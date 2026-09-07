<?php
/**
 * The protocol-independent response-mapping members, shared by both zai
 * surfaces (glm22-6).
 *
 * The transport-failure mapping test and the three data providers were
 * byte-identical copies across the two response-mapping suites while
 * pinning the SHARED ErrorMapper and the core-code surface — ~1.5k lines
 * of mirrored tests at 90-100% identity meant every ErrorMapper rule
 * change had to be re-encoded twice, and a one-side edit left the
 * suites pinning different expectations of the same code while both
 * stayed green. One copy executes per surface now (each concrete
 * subclass inherits the test and serves the providers to its
 * surface-specific data consumers); the surface-specific tests keep
 * riding the inherited providers through @dataProvider.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Support\ErrorMapper;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;

abstract class AbstractZaiSurfaceResponseMappingTestCase extends WpConnectorsTestCase
{
    /**
     * The wired surface model (provided by each concrete suite).
     *
     * @return object The wired model.
     */
    abstract protected function model();

    /**
     * The wired surface model authenticated with THIS exact key (glm29-7).
     *
     * FakeSecrets::apiKey() draws a FRESH random key per call, so a
     * redaction pin that wires with one draw and asserts against
     * another compares the message against a secret that was never on
     * the wire — vacuous. Each concrete suite delegates to its wiring
     * helper with the caller's key.
     *
     * @param string $key The exact fixture key to wire.
     * @return object The wired model.
     */
    abstract protected function model_with_key( string $key );

    /**
     * The one-message prompt (provided by each concrete suite).
     *
     * @return list<\WordPress\AiClient\Messages\DTO\Message>
     */
    abstract protected function prompt();

    /**
     * @return array<string, list<mixed>>
     */
    public function provideBoundaryErrorCodes()
    {
        return array(
            '401' => array(401, ErrorMapper::CODE_UNAUTHORIZED),
            '403' => array(403, ErrorMapper::CODE_FORBIDDEN),
            '429' => array(429, ErrorMapper::CODE_RATE_LIMITED),
            '418' => array(418, ErrorMapper::CODE_CLIENT_ERROR),
            '500' => array(500, ErrorMapper::CODE_UPSTREAM_ERROR),
            '503' => array(503, ErrorMapper::CODE_UPSTREAM_ERROR),
            '307' => array(307, ErrorMapper::CODE_REDIRECT_ERROR),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideErrorStatuses()
    {
        return array(
            '401' => array(401, ClientException::class),
            '403' => array(403, ClientException::class),
            '429' => array(429, ClientException::class),
            '418' => array(418, ClientException::class),
            '500' => array(500, ServerException::class),
            '503' => array(503, ServerException::class),
            '307' => array(307, WordPress\AiClient\Providers\Http\Exception\RedirectException::class),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideCoreBuilderErrorCases()
    {
        return array(
            '401' => array(401, 'prompt_client_error'),
            '429' => array(429, 'prompt_client_error'),
            '503' => array(503, 'prompt_upstream_server_error'),
        );
    }

    public function testGenerateTextMapsTransportFailuresToTypedWpErrors()
    {
        /*
         * glm29-7: the pin used to assert against a FRESH
         * FakeSecrets::apiKey() draw while the model was wired with a
         * different random key — the leak-detection assertion never saw
         * the secret actually on the wire, so an ErrorMapper that
         * embedded it passed green (empirically confirmed by the round's
         * verifier with a hex-fragment leak). ONE draw wires AND
         * asserts now, and the canary below proves the assertion
         * detects exactly this key instance when it IS present.
         */
        $key = FakeSecrets::apiKey();

        try {
            $this->assertRedacted('canary prefix ' . $key . ' suffix', $key);
            $this->fail('assertRedacted must flag a message carrying the wired key — the pin would be vacuous.');
        } catch ( \PHPUnit\Framework\AssertionFailedError $e ) {
            // The canary: the redaction assertion really sees THIS key.
        }

        $this->allowUnmockedHttp = true;

        $error = $this->model_with_key( $key )->generate_text($this->prompt());

        $this->assertWPError($error, ErrorMapper::CODE_TRANSPORT_ERROR);
        $this->assertRedacted($error->get_error_message(), $key);
    }
}
