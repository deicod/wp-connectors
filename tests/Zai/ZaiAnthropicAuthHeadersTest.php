<?php
/**
 * Task 2.3 — Bearer authentication and protocol header tests.
 *
 * Proves the exact header set on every request surface (generation, probe),
 * that x-api-key is never sent, that no duplicate/conflicting Authorization
 * header can result, and that the full key stays redacted in every log and
 * error surface.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request as SdkRequest;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use Deicod\WpConnectors\Zai\Authentication\ZaiAnthropicRequestAuthentication;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Support\DebugLogger;

final class ZaiAnthropicAuthHeadersTest extends WpConnectorsTestCase
{
    /**
     * Model instance wired to the harness transport with a fixture key.
     *
     * The discovery transient is primed first so the directory resolves
     * 'glm-5.3' with NO discovery HTTP and generation produces exactly ONE
     * request. Without the priming, model resolution depends on the
     * process-wide SDK state the vendor parent caches (glm15-1): the
     * provider's directory instance is cached statically per class, so a
     * test that wired authentication onto it earlier in the process leaves
     * discovery able to authenticate — and to consume this test's single
     * queued response — while an unwired cached instance throws before any
     * transport and falls back to the static catalog. Default file order
     * happened to keep the directory unwired here; randomized order does
     * not, which is exactly the order dependency the suite's
     * --order-by=random run exists to catch.
     *
     * @param string $key API key.
     * @return \Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel
     */
    private function model(string $key)
    {
        $this->primeZaiAnthropicDiscoveryTransient();

        $model = ZaiAnthropicProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        return $model;
    }

    /**
     * @return list<Message>
     */
    private function prompt()
    {
        return array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))));
    }

    /*
     * glm15-20: the success body rides HttpResponseFactory::
     * anthropicMessagesBody() — the hand-rolled minimal Messages shape
     * duplicated the canonical fixture (only usage numbers apart), so a
     * Messages-payload contract change updated the factory for the
     * mapping suites while this suite kept posting a stale shape.
     */

    /*
     * The authentication class in isolation.
     */

    public function testAuthenticateRequestSetsExactlyBearerAndVersion()
    {
        $key = FakeSecrets::apiKey();
        $authentication = new ZaiAnthropicRequestAuthentication($key);

        $request = $authentication->authenticateRequest(
            new SdkRequest(HttpMethodEnum::POST(), 'https://api.z.ai/api/anthropic/v1/messages')
        );

        $this->assertSame(array('Bearer ' . $key), $request->getHeader('Authorization'));
        $this->assertSame(array(ZaiAnthropicRequestAuthentication::ANTHROPIC_VERSION), $request->getHeader('anthropic-version'));
        $this->assertSame('2023-06-01', ZaiAnthropicRequestAuthentication::ANTHROPIC_VERSION);
        $this->assertNull($request->getHeader('x-api-key'), 'x-api-key must never be sent (unverified on z.ai).');
    }

    public function testAPreExistingAuthorizationHeaderIsReplacedNotDuplicated()
    {
        $key = FakeSecrets::apiKey();
        $authentication = new ZaiAnthropicRequestAuthentication($key);

        // A stale/conflicting credential header on the wire target must be
        // replaced by exactly ONE Bearer value, never appended.
        $request = new SdkRequest(
            HttpMethodEnum::POST(),
            'https://api.z.ai/api/anthropic/v1/messages',
            array(
                'Authorization' => 'Token stale-value',
                'anthropic-version' => '1999-01-01',
            )
        );

        $authenticated = $authentication->authenticateRequest($request);

        $this->assertSame(array('Bearer ' . $key), $authenticated->getHeader('Authorization'));
        $this->assertCount(1, $authenticated->getHeader('Authorization'), 'Exactly one Authorization value.');
        $this->assertSame(array('2023-06-01'), $authenticated->getHeader('anthropic-version'));
        $this->assertCount(1, $authenticated->getHeader('anthropic-version'), 'Exactly one anthropic-version value.');
    }

    public function testTheWrapFunnelPassesWrappedInstancesThrough()
    {
        $plain = new ApiKeyRequestAuthentication(FakeSecrets::apiKey());
        $wrapped = ZaiAnthropicRequestAuthentication::wrap($plain);
        $this->assertInstanceOf(ZaiAnthropicRequestAuthentication::class, $wrapped);
        $this->assertSame($plain->getApiKey(), $wrapped->getApiKey());

        $this->assertSame($wrapped, ZaiAnthropicRequestAuthentication::wrap($wrapped), 'Already-wrapped instances pass through.');
    }

    public function testAPreExistingXApiKeyHeaderIsRemoved()
    {
        // Codex R4 #5: a reused/decorated request already carrying an
        // x-api-key header must lose it — the stale second credential may
        // never travel alongside the Bearer key.
        $key = FakeSecrets::apiKey();
        $authentication = new ZaiAnthropicRequestAuthentication($key);

        foreach (array('x-api-key', 'X-Api-Key', 'X-API-KEY') as $casing) {
            $request = new SdkRequest(
                HttpMethodEnum::POST(),
                'https://api.z.ai/api/anthropic/v1/messages',
                array(
                    $casing => 'stale-credential-value',
                    'Authorization' => 'Token stale',
                    'Content-Type' => 'application/json',
                ),
                array('model' => 'glm-5.3', 'max_tokens' => 8)
            );

            $authenticated = $authentication->authenticateRequest($request);

            $this->assertNull($authenticated->getHeader('x-api-key'), "A pre-existing {$casing} header must be removed (lookup is case-insensitive).");
            $this->assertNotContains(strtolower($casing), array_map('strtolower', array_keys($authenticated->getHeaders())), 'No casing variant may survive.');
            $this->assertSame(array('Bearer ' . $key), $authenticated->getHeader('Authorization'));
            $this->assertSame(array('2023-06-01'), $authenticated->getHeader('anthropic-version'));

            // The rebuild carries unrelated headers and the payload verbatim.
            $this->assertSame(array('application/json'), $authenticated->getHeader('Content-Type'), 'Unrelated headers must survive the strip.');
            $this->assertSame('{"model":"glm-5.3","max_tokens":8}', (string) $authenticated->getBody(), 'The request body must survive the header strip unchanged.');
        }
    }

    public function testAGetWithDataAndXApiKeyIsStrippedWithoutDoublingTheQuery()
    {
        /*
         * GLM4 #7: the strip rebuilt the Request from getUri() — which
         * already folds GET array data into the query string — WHILE
         * carrying the data component over, so the rebuilt request's own
         * getUri() appended every query parameter a second time
         * ('...?page=2&limit=50&page=2&limit=50'). Unreachable from the
         * current plugin callers (their GETs carry no data), but this is
         * the defense-in-depth reuse/decoration case the strip exists
         * for, so the rebuild must be wire-identical.
         */
        $authentication = new ZaiAnthropicRequestAuthentication(FakeSecrets::apiKey());

        $request = new SdkRequest(
            HttpMethodEnum::GET(),
            'https://api.z.ai/api/anthropic/v1/models',
            array('x-api-key' => 'stale-credential-value'),
            array('page' => 2, 'limit' => 50)
        );

        $authenticated = $authentication->authenticateRequest($request);

        $this->assertNull($authenticated->getHeader('x-api-key'));
        $this->assertSame(
            'https://api.z.ai/api/anthropic/v1/models?page=2&limit=50',
            $authenticated->getUri(),
            'The query parameters must appear exactly once after the header strip.'
        );
        $this->assertSame(
            'https://api.z.ai/api/anthropic/v1/models?page=2&limit=50',
            $authenticated->toArray()['uri'],
            'The wire form (toArray) must carry the query exactly once too.'
        );
    }

    public function testAGetWithoutDataAndXApiKeyKeepsItsUriVerbatim()
    {
        // Control: a data-less GET (the shape the plugin's probe sends)
        // strips the header with the URI untouched.
        $authentication = new ZaiAnthropicRequestAuthentication(FakeSecrets::apiKey());

        $request = new SdkRequest(
            HttpMethodEnum::GET(),
            'https://api.z.ai/api/anthropic/v1/models',
            array('x-api-key' => 'stale-credential-value')
        );

        $authenticated = $authentication->authenticateRequest($request);

        $this->assertNull($authenticated->getHeader('x-api-key'));
        $this->assertSame('https://api.z.ai/api/anthropic/v1/models', $authenticated->getUri());
    }

    public function testTheWrapFunnelFailsClosedOnForeignAuthTypes()
    {
        $foreign = new class implements WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface {
            public function authenticateRequest(SdkRequest $request): SdkRequest
            {
                return $request;
            }

            public static function getJsonSchema(): array
            {
                return array();
            }
        };

        /*
         * GLM3 #9: the refusal is the binding-failure RuntimeException
         * family (ErrorMapper: 500 zai_error) — the previous
         * InvalidArgumentException made a wiring failure surface as a 400
         * zai_invalid_request, the caller-input channel.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API-key authentication');
        ZaiAnthropicRequestAuthentication::wrap($foreign);
    }

    public function testEveryAnthropicSdkClassSpeaksTheProtocolThroughTheOneTrait()
    {
        /*
         * glm15-8: the protocol wrap was re-declared as a bespoke
         * getRequestAuthentication() override in each of the three
         * SDK-interfaced classes — a fourth class speaking the surface
         * that forgets the override silently sends plain ApiKey auth
         * (requests still succeed against z.ai while violating the
         * never-x-api-key contract, so the omission fails open and
         * undetected). The SpeaksAnthropicMessagesProtocol trait owns
         * the wrap now; the pins hold every SDK-interfaced class on
         * the trait and forbid the bespoke-override shape.
         */
        $classes = array(
            'the model' => 'src/Models/ZaiAnthropicTextGenerationModel.php',
            'the metadata directory' => 'src/Metadata/ZaiAnthropicModelMetadataDirectory.php',
            'the availability' => 'src/Availability/ZaiAnthropicProviderAvailability.php',
        );

        foreach ($classes as $label => $relative) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/connectors/zai/' . $relative);

            $this->assertStringContainsString('use SpeaksAnthropicMessagesProtocol', $source, "{$label} composes the protocol trait.");
            $this->assertStringContainsString('raw_request_authentication()', $source, "{$label} supplies the raw-authentication hook.");
            $this->assertSame(0, preg_match('/ZaiAnthropicRequestAuthentication::wrap\(\s*parent::/', $source), "{$label} carries no bespoke parent-wrap override.");
            $this->assertSame(0, preg_match('/ZaiAnthropicRequestAuthentication::wrap\(\s*\$this->trait_/', $source), "{$label} carries no bespoke trait-wrap override.");
        }
    }

    /*
     * Exact header sets on the real request surfaces.
     */

    public function testGenerationSendsTheExactHeaderSet()
    {
        $key = FakeSecrets::apiKey();
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('ok'));

        $this->model($key)->generateTextResult($this->prompt());

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts, 'The v1 static directory performs no discovery HTTP.');
        $headers = $attempts[0]['headers'];

        $this->assertSame('POST', $attempts[0]['method']);
        $this->assertSame('https://api.z.ai/api/anthropic/v1/messages', $attempts[0]['url']);
        $this->assertSame(array('Bearer ' . $key), $headers['Authorization'] ?? null, 'Exactly one Bearer Authorization header.');
        $this->assertSame(array('2023-06-01'), $headers['anthropic-version'] ?? null, 'The protocol version header is always sent.');
        $this->assertSame(array('application/json'), $headers['Content-Type'] ?? null, 'JSON content type.');
        $this->assertArrayNotHasKey('x-api-key', $headers, 'x-api-key is unverified on z.ai and must never be sent.');
        $this->assertCount(1, $headers['Authorization'], 'No duplicate credential header.');
    }

    public function testTheProbeSendsTheExactHeaderSet()
    {
        $key = FakeSecrets::apiKey();
        $availability = new ZaiAnthropicProviderAvailability();
        $availability->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        $this->queueSdkResponse(200, array(), '{"data":[{"id":"glm-5.3","type":"model"}]}');
        $this->assertTrue($availability->isConfigured());

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);
        $headers = $attempts[0]['headers'];

        $this->assertSame('https://api.z.ai/api/anthropic/v1/models', $attempts[0]['url']);
        $this->assertSame(array('Bearer ' . $key), $headers['Authorization'] ?? null);
        $this->assertSame(array('2023-06-01'), $headers['anthropic-version'] ?? null, 'The probe carries the protocol header too.');
        $this->assertArrayNotHasKey('x-api-key', $headers);
    }

    /*
     * Redaction across every surface that could expose the key.
     */

    public function testTheKeyAppearsOnlyInsideTheSingleAuthorizationHeader()
    {
        $key = FakeSecrets::apiKey();
        $authentication = new ZaiAnthropicRequestAuthentication($key);

        $request = $authentication->authenticateRequest(
            new SdkRequest(HttpMethodEnum::POST(), 'https://api.z.ai/api/anthropic/v1/messages')
        );

        // Authorization carries the credential exactly once (asserted
        // elsewhere); every OTHER header must be key-free.
        foreach ($request->getHeaders() as $name => $values) {
            if ('authorization' === strtolower((string) $name)) {
                continue;
            }

            foreach ($values as $value) {
                $this->assertRedacted(
                    $name . ': ' . $value,
                    $key,
                    "Header {$name} must not carry the key."
                );
            }
        }
    }

    public function testUpstreamErrorBodiesEchoingTheKeyStayRedacted()
    {
        $key = FakeSecrets::apiKey();
        $body = (string) wp_json_encode(array(
            'type' => 'error',
            'error' => array('type' => 'authentication_error', 'message' => 'invalid api key ' . $key),
        ));
        $this->queueSdkResponse(401, array('Content-Type' => 'application/json'), $body);

        $error = $this->model($key)->generate_text($this->prompt());

        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_UNAUTHORIZED);
        $this->assertRedacted($error->get_error_message(), $key, 'The typed WP_Error message must never echo the key.');
        $this->assertStringNotContainsString('invalid api key', $error->get_error_message(), 'Upstream body text must not be copied.');
    }

    public function testDebugLoggingRecordsNoCredentialOrHeaders()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');
        $key = FakeSecrets::apiKey();
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('ok'));

        $this->model($key)->generateTextResult($this->prompt());

        $serialized = wp_json_encode(DebugLogger::entries());
        $this->assertRedacted($serialized, $key);
        $this->assertStringNotContainsString('Bearer', $serialized);
        $this->assertStringNotContainsString('Authorization', $serialized);
        $this->assertStringNotContainsString('anthropic-version', $serialized, 'Header names/values are never logged.');

        $entry = DebugLogger::entries()[0];
        $this->assertSame('https://api.z.ai/api/anthropic/v1/messages', $entry['url'], 'Path only, no query string.');
    }

    public function testThePersistedAvailabilityStateCarriesNoKey()
    {
        $key = FakeSecrets::apiKey();
        $availability = new ZaiAnthropicProviderAvailability();
        $availability->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        $this->queueSdkResponse(401, array(), '{"type":"error","error":{"type":"authentication_error","message":"nope"}}');
        $this->assertFalse($availability->isConfigured());

        $this->assertOptionNotPlaintext(
            ZaiAnthropicProviderAvailability::STATE_OPTION,
            $key,
            'The persisted state must contain a binding hash, never the key.'
        );
    }
}
