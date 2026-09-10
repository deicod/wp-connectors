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
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use Deicod\WpConnectors\Zai\Authentication\SpeaksAnthropicMessagesProtocol;
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
        return $this->wiredZaiAnthropicModel($key);
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

    public function testCredentialMaterialTheHeaderCannotCarryIsRefusedPreTransport()
    {
        /*
         * glm16-13: the Authorization value is raw concatenation of the
         * key, the vendor HeadersCollection SPLITS comma-joined values
         * (explode(',')), and nothing before PSR-7 rejects CR/LF — a
         * key with a comma silently became several header values and a
         * control character rode toward header injection, both failing
         * auth at the endpoint with no hint the credential material is
         * the cause. Typed rejection now, in the RuntimeException
         * binding-failure family, message fixed and key-free.
         */
        foreach (array("good-key,bad-half", "good-key\r\nX-Injected: 1", "with\ttab", "with\x00nul", "with\x7Fdel") as $uncarriable) {
            try {
                (new ZaiAnthropicRequestAuthentication($uncarriable))->authenticateRequest(
                    new SdkRequest(HttpMethodEnum::POST(), 'https://api.z.ai/api/anthropic/v1/messages')
                );
                $this->fail('Uncarriable credential material must be refused before the header is built.');
            } catch (RuntimeException $e) {
                $this->assertSame(
                    'The ' . ZaiAnthropicProviderAvailability::REFUSAL_LABEL . ' provider refuses credential material containing control characters or commas: the Authorization header cannot carry it.',
                    $e->getMessage()
                );
                $this->assertStringNotContainsString($uncarriable, $e->getMessage(), 'The key never appears in the rejection.');
            }
        }

        // glm13-1 tolerance: an EMPTY key authenticates nothing and its
        // own rules own that shape — the header build proceeds exactly
        // as before (the vendor collection trims the trailing space,
        // which is itself an instance of the silent-mutation class the
        // rejection above exists to surface).
        $request = (new ZaiAnthropicRequestAuthentication(''))->authenticateRequest(
            new SdkRequest(HttpMethodEnum::POST(), 'https://api.z.ai/api/anthropic/v1/messages')
        );
        $this->assertSame(array('Bearer'), $request->getHeader('Authorization'), 'The empty-key shape keeps its flying semantics.');

        // The generation path: a comma key wired on the model rejects
        // before any transport attempt, in the binding-failure family.
        try {
            $this->model('key-part-one,key-part-two')->generateTextResult($this->prompt());
            $this->fail('Generation with uncarriable credential material must fail pre-transport.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot carry it', $e->getMessage());
        }
        $this->assertNoHttpRequests();

        // The probe's fallback path rejects the same way (glm26-1,
        // superseding this block's former inconclusive pin): a comma DB
        // key is DEFINITIVELY invalid — nothing flown, and the invalid
        // verdict persists bound to exactly that key, so the card stops
        // reporting connected for a credential whose every generation
        // would 500. The full-chain regression (refusal gate, region
        // distrust) lives in the availability suite.
        putenv('ZAI_ANTHROPIC_API_KEY');
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'db-part-one,db-part-two');

        $instance = new ZaiAnthropicProviderAvailability();
        $instance->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());

        $this->assertFalse(
            $instance->isConfigured(),
            'Uncarriable credential material is a definitive invalid verdict — never configured-pending.'
        );
        $this->assertNoHttpRequests();
        $this->assertSame(
            'invalid',
            get_option(ZaiAnthropicProviderAvailability::STATE_OPTION)['valid'],
            'The verdict persists for exactly the material that cannot fly.'
        );
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
        // glm22-10: the harness-owned identity-passthrough double (was
        // an inline anonymous class spelling the same contract).
        $foreign = new OpaqueAuthentication();

        /*
         * GLM3 #9: the refusal is the binding-failure RuntimeException
         * family (ErrorMapper: 500 zai_error) — the previous
         * InvalidArgumentException made a wiring failure surface as a 400
         * zai_invalid_request, the caller-input channel.
         */
        $this->expectException(RuntimeException::class);
        /*
         * glm24-10: the FULL message rides the owner chain — the old
         * substring pin ('API-key authentication') held under any
         * phrasing; this one ties the wrap() refusal text to the
         * REFUSAL_LABEL the folded message interpolates.
         */
        $this->expectExceptionMessage('The ' . ZaiAnthropicProviderAvailability::REFUSAL_LABEL . ' provider requires an API-key authentication instance.');
        ZaiAnthropicRequestAuthentication::wrap($foreign);
    }

    public function testTheWrapFunnelRefusesApiKeySubclassesTyped()
    {
        /*
         * glm38-3: the wrap's plain-class requirement went EXACT. An
         * ApiKeyRequestAuthentication subclass — the registry's
         * instanceof gate accepts one; the SDK's own paths never
         * produce one — used to be silently rebuilt from getApiKey()
         * alone, stripping whatever authenticateRequest() behavior the
         * override added: requests flew bare Bearer auth and failed
         * upstream with no local diagnostic, the fail-OPEN sibling of
         * the foreign shape's typed refusal above. The subclass shape
         * refuses typed in the same family now; the message stays fixed
         * and value-free (the wiring class name is caller-controlled
         * and never interpolated).
         */
        $subclass = new class (FakeSecrets::apiKey()) extends ApiKeyRequestAuthentication {
            /**
             * An override the bare rebuild could never carry.
             *
             * @param SdkRequest $request The request.
             * @return SdkRequest
             */
            public function authenticateRequest( SdkRequest $request ): SdkRequest
            {
                return $request->withHeader( 'X-Org', 'acme' );
            }
        };

        try {
            ZaiAnthropicRequestAuthentication::wrap( $subclass );
            $this->fail( 'An API-key authentication subclass must be refused typed, never silently rebuilt.' );
        } catch ( RuntimeException $e ) {
            $this->assertSame(
                'The ' . ZaiAnthropicProviderAvailability::REFUSAL_LABEL . ' provider refuses an API-key authentication subclass: its overridden behavior cannot ride this surface.',
                $e->getMessage()
            );
        }

        // The plain instance keeps its byte-identical rebuild (the
        // registry's own shape — the pass-through pin sits above).
        $plain = new ApiKeyRequestAuthentication( FakeSecrets::apiKey() );
        $this->assertInstanceOf(
            ZaiAnthropicRequestAuthentication::class,
            ZaiAnthropicRequestAuthentication::wrap( $plain )
        );
    }

    public function testEveryAnthropicSdkClassSpeaksTheProtocolThroughTheOneTrait()
    {
        /*
         * glm15-8: the protocol wrap was re-declared as a bespoke
         * getRequestAuthentication() override in each SDK-interfaced
         * class — a fourth class speaking the surface that forgets the
         * override silently sends plain ApiKey auth (requests still
         * succeed against z.ai while violating the never-x-api-key
         * contract, so the omission fails open and undetected). The
         * SpeaksAnthropicMessagesProtocol trait owns the wrap now; it
         * also overrides fallback_authentication()'s plain ApiKey
         * default, so the unwired probe flies the same headers.
         *
         * glm35-3: the class set is DERIVED, never hand-listed (the
         * glm31-8 checklist-in-code class — a three-file literal
         * cannot see the fourth class the pin exists for). The sweep
         * walks every plugin source file and takes the classes the SDK
         * itself authenticates through: implementors of
         * WithRequestAuthenticationInterface (directly or through any
         * parent — the vendor bases AbstractApiBasedModel and
         * AbstractApiBasedModelMetadataDirectory and this plugin's
         * AbstractZaiProviderAvailability all carry it) on this
         * surface (the ZaiAnthropic naming convention every class of
         * the surface follows). Each must compose the trait, supply
         * the raw hook the trait demands, and carry no bespoke wrap
         * override. The lower bound of three proves the sweep sees the
         * real tree; a fourth class enters the set automatically.
         */
        $candidates = array();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/connectors/zai/src', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!preg_match('/^namespace\s+(.+);/m', $source, $ns)
                || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(ZaiAnthropic\w+)/m', $source, $cls)
            ) {
                continue;
            }
            $fqcn = $ns[1] . '\\' . $cls[1];
            if (!class_exists($fqcn)
                || !in_array(WithRequestAuthenticationInterface::class, class_implements($fqcn), true)
            ) {
                continue;
            }

            $candidates[$cls[1]] = array($fqcn, $source);
        }

        $this->assertGreaterThanOrEqual(3, count($candidates), 'The sweep must see the model, the metadata directory, and the availability — a smaller set means the derivation broke.');

        foreach ($candidates as $label => $candidate) {
            list($fqcn, $source) = $candidate;

            $traits = array();
            $walk = $fqcn;
            do {
                $uses = class_uses($walk);
                if (false !== $uses) {
                    $traits += $uses;
                }
            } while ($walk = get_parent_class($walk));
            $this->assertArrayHasKey(SpeaksAnthropicMessagesProtocol::class, $traits, "{$label} composes the protocol trait.");

            $this->assertTrue(method_exists($fqcn, 'raw_request_authentication'), "{$label} supplies the raw-authentication hook.");
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

        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));
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
        // glm29-11: the error envelope rides the factory builder.
        $body = HttpResponseFactory::anthropicErrorBody('invalid api key ' . $key, 'authentication_error');
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

        $this->queueSdkResponse(401, array(), HttpResponseFactory::anthropicErrorBody('nope', 'authentication_error'));
        $this->assertFalse($availability->isConfigured());

        $this->assertOptionNotPlaintext(
            ZaiAnthropicProviderAvailability::STATE_OPTION,
            $key,
            'The persisted state must contain a binding hash, never the key.'
        );
    }

    public function testNoRejectionMessageHardcodesTheSurfaceSlug()
    {
        /*
         * glm24-3 (closing the glm19-5/6 deferral): the two rejection
         * messages in ZaiAnthropicRequestAuthentication were the last
         * literals hardcoding the surface slug while every other
         * rejection rode the REFUSAL_LABEL chain — a CACHE_SCOPE rename
         * would have left ErrorMapper's admin-facing 500 text naming a
         * provider id that exists nowhere else. The messages
         * interpolate PROVIDER_LABEL now (byte-identical output, pinned
         * above).
         *
         * glm24-10 (verifier round): the first form of this pin matched
         * only a STANDALONE quoted slug — a verbatim revert of the fold
         * keeps the slug mid-string inside the quoted sentence and
         * scored 0 matches (empirically confirmed against the
         * pre-round source). The pin now names BOTH shapes: the
         * positive half requires the label-interpolating sprintf idiom
         * at every rejection site, and the negative halves forbid the
         * standalone slug literal AND the reverted sentence prefix.
         *
         * glm38-3: the count is 3 now — the wrap's Api-key SUBCLASS
         * refusal is the third rejection site, riding the same idiom
         * (superseding glm24-10's 2, the GLM10 #4 lesson: the pin
         * counts the sites, and a site was added).
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/connectors/zai/src/Authentication/ZaiAnthropicRequestAuthentication.php');

        $this->assertSame(
            3,
            substr_count($source, "sprintf( 'The %s provider"),
            'Every rejection message interpolates the surface label — the verbatim revert fails here (it scores 0).'
        );
        $this->assertSame(0, preg_match('/The zai_anthropic provider/', $source), 'No rejection message embeds the surface slug mid-string.');
        $this->assertSame(0, preg_match('/[\'"]zai_anthropic[\'"]/', $source), 'No standalone quoted slug literal either.');
    }
}
