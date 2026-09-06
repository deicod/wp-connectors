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
        $this->allowUnmockedHttp = true;

        $error = $this->model()->generate_text($this->prompt());

        $this->assertWPError($error, ErrorMapper::CODE_TRANSPORT_ERROR);
        $this->assertRedacted($error->get_error_message(), FakeSecrets::apiKey());
    }
}
