<?php
/**
 * Task 1.4 — provider metadata, auth, and availability tests.
 *
 * Covers the minimum/newer SDK metadata shapes, Bearer header injection, the
 * persisted validated state bound to the complete key hash + source +
 * endpoint, invalidation on every key-source change, and redaction of the key
 * from persisted state and failures.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Provider\AbstractZaiProvider;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

final class ZaiProviderMetadataAndAvailabilityTest extends WpConnectorsTestCase
{
    /**
     * Builds a standalone availability instance wired to the harness
     * transporter with the given key.
     *
     * @param string $key API key (fixture value).
     * @return ZaiProviderAvailability
     */
    private function availability(string $key): ZaiProviderAvailability
    {
        $instance = new ZaiProviderAvailability();
        $instance->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $instance->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        return $instance;
    }

    /**
     * Availability instance whose wired key equals the env var value, so
     * the effective credential resolves to the ENVIRONMENT source (the
     * registry-wired credential is authoritative and key_source() maps it
     * back to 'env') — the shape production uses after a region switch.
     *
     * @param string $envKey Value of ZAI_API_KEY.
     * @return ZaiProviderAvailability
     */
    private function envBackedAvailability(string $envKey): ZaiProviderAvailability
    {
        return $this->availability($envKey);
    }

    /*
     * Provider metadata across SDK versions.
     */

    public function testMetadataShapeOnMinimumSdk()
    {
        $args = ZaiProvider::provider_metadata_args('1.1.0');

        // id, name, type, credentials URL, auth method — no description/logo.
        $this->assertCount(5, $args);
        $this->assertSame('zai', $args[0]);
        $this->assertSame('z.ai', $args[1]);
        $this->assertSame('https://z.ai/manage/apikey/apikey', $args[3]);
    }

    public function testCredentialsUrlFollowsTheSelectedRegion()
    {
        // r5: regions use separate accounts and keys (SPEC 3.3) — a
        // China-region admin must be linked to the open.bigmodel.cn portal,
        // never to the international z.ai key page.
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame(
            'https://open.bigmodel.cn/usercenter/apikeys',
            ZaiProvider::provider_metadata_args('1.1.0')[3],
            'The cn region must advertise the open.bigmodel.cn key portal.'
        );
        $this->assertSame(ZaiProvider::CN_CREDENTIALS_URL, ZaiProvider::provider_metadata_args('1.3.0')[3], 'The link is region-aware in every metadata shape.');

        update_option(PlanRegionSettings::OPTION_REGION, 'intl');
        $this->assertSame(
            'https://z.ai/manage/apikey/apikey',
            ZaiProvider::provider_metadata_args('1.1.0')[3],
            'The intl region keeps the z.ai key portal.'
        );
        $this->assertSame(ZaiProvider::INTL_CREDENTIALS_URL, ZaiProvider::provider_metadata_args('1.2.0')[3]);
    }

    public function testMetadataShapeOnSdk120AddsDescription()
    {
        $args = ZaiProvider::provider_metadata_args('1.2.0');

        $this->assertCount(6, $args);
        $this->assertSame('GLM text generation via the z.ai OpenAI-compatible API.', $args[5]);
    }

    public function testTheDescriptionLiteralSitsInsideATranslationCallForExtraction()
    {
        /*
         * GLM6 #10: i18n extractors (wp i18n make-pot and friends) scan
         * for LITERAL arguments inside translation calls — the shared-base
         * indirection (__( static::provider_description() )) was invisible
         * to them, so POT regeneration silently dropped the provider-card
         * msgid. The runtime value is covered above; this pins the
         * extractable SOURCE shape.
         */
        $source = (string) file_get_contents(
            __DIR__ . '/../../connectors/zai/src/Provider/ZaiProvider.php'
        );

        $this->assertSame(
            1,
            preg_match('/__\(\s*\'GLM text generation via the z\.ai OpenAI-compatible API\.\',\s*\'zai\'\s*\)/', $source),
            'The description literal must appear inside a __() call for POT extraction.'
        );
    }

    public function testMetadataShapeOnSdk130AddsLogo()
    {
        $args = ZaiProvider::provider_metadata_args('1.3.0');

        $this->assertCount(7, $args);
        $this->assertStringEndsWith('/assets/zai.svg', $args[6]);
        $this->assertFileExists($args[6], 'Logo file must ship inside the plugin.');
    }

    public function testDetectedSdkProducesTheNewerShape()
    {
        $this->bootProvider();

        $metadata = ZaiProvider::metadata();

        $this->assertSame('zai', $metadata->getId());
        $this->assertNotNull($metadata->getAuthenticationMethod());
        $this->assertTrue($metadata->getAuthenticationMethod()->isApiKey());
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $this->assertSame('GLM text generation via the z.ai OpenAI-compatible API.', $metadata->getDescription());
        }
    }

    /**
     * Loads the plugin and registers the provider with the default registry.
     *
     * @return void
     */
    private function bootProvider()
    {
        $this->loadPlugin(__DIR__ . '/../../connectors/zai/zai.php', '\Deicod\WpConnectors\Zai\boot');
        $this->runInit();
    }

    /*
     * Auth: Bearer header injection via the registry-wired credential.
     */

    public function testProbeInjectsBearerHeaderAgainstTheSelectedEndpoint()
    {
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        $this->bootProvider();
        AiClient::defaultRegistry()->setProviderRequestAuthentication(
            'zai',
            new ApiKeyRequestAuthentication($key)
        );

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        $this->assertTrue(ZaiProvider::availability()->isConfigured());

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);
        $this->assertSame('GET', $attempts[0]['method']);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $attempts[0]['url']);
        $this->assertSame(
            array('Bearer ' . $key),
            $attempts[0]['headers']['Authorization'] ?? null,
            'Authorization header must be exactly "Bearer <key>".'
        );
    }

    /*
     * Availability: validated state and its invalidation rules.
     */

    public function testNoKeyMeansNotConfiguredAndStateCleared()
    {
        update_option(ZaiProviderAvailability::STATE_OPTION, array('binding' => 'stale', 'valid' => 'valid'));

        $instance = new ZaiProviderAvailability();

        $this->assertFalse($instance->isConfigured());
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'Stale state must be removed when no key exists.');
        $this->assertNoHttpRequests();
    }

    public function testADatabaseOnlyKeyValidatesThroughAnUnwiredProbe()
    {
        /*
         * GLM5 #10: the SDK registry wires provider auth from env/constant
         * ONLY, so a DATABASE-only key rode an UNWIRED probe — the
         * binding RuntimeException counted as inconclusive and
         * isConfigured() reported connected (configured-pending) FOREVER
         * without a single validation request, defeating the class's own
         * 'nonempty-but-invalid key must report not-connected' contract.
         * The unwired probe now authenticates with the EFFECTIVE (stored)
         * key through the surface's fallback authentication.
         */
        putenv('ZAI_API_KEY');
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        $instance = new ZaiProviderAvailability();
        $instance->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        // Deliberately NO request authentication wired: the registry's
        // database-only shape.

        $this->queueSdkResponse(401, array(), '{"error":{"message":"bad key"}}');

        $this->assertFalse($instance->isConfigured(), 'A database-only invalid key must report not-connected.');

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts, 'The probe must actually validate the stored key.');
        $this->assertSame(
            array('Bearer ' . $key),
            $attempts[0]['headers']['Authorization'] ?? null,
            'The fallback probe must authenticate with the stored key.'
        );

        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $this->assertSame('invalid', $state['valid'], 'The definitive rejection persists.');
    }

    public function testAnEmptyWiredCredentialProbesWithTheEffectiveKeyItBinds()
    {
        /*
         * glm13-1: the SDK registry wires ZAI_API_KEY='' verbatim
         * (getenv() returns the empty string, not false, so the wired
         * ApiKeyRequestAuthentication carries an empty key) while a valid
         * key sits in the database option. The probe must authenticate
         * with the EFFECTIVE (database) credential — the exact one the
         * verdict binding names — so the credential that flies and the
         * credential that binds are one. The discriminating assertion is
         * the Bearer header: the pre-fix probe flew the EMPTY credential
         * and persisted the 401 it earned under the VALID key's binding.
         */
        putenv('ZAI_API_KEY');
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        $instance = new ZaiProviderAvailability();
        $instance->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $instance->setRequestAuthentication(new ApiKeyRequestAuthentication(''));

        $this->queueSdkResponse(401, array(), '{"error":{"message":"bad key"}}');

        $this->assertFalse($instance->isConfigured());

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts, 'The probe must run: the effective key is non-empty.');
        $this->assertSame(
            array('Bearer ' . $key),
            $attempts[0]['headers']['Authorization'] ?? null,
            'The probe must fly the effective database key, never the empty wired credential.'
        );

        // The 401 WAS earned by the database key (it flew), so the
        // invalid verdict under its binding is coherent — not poisoning.
        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $this->assertSame('invalid', $state['valid'], 'The rejection the flown credential earned persists.');
    }

    public function testADefinitiveVerdictForAnEmptyWiredCredentialRecordsNothing()
    {
        /*
         * glm13-1 recorder half: a rejecting answer earned by a request
         * the EMPTY wired credential flew (generation, discovery) says
         * nothing about the effective credential — recording it under the
         * effective binding is the cross-credential poisoning the probe
         * half refuses above.
         */
        putenv('ZAI_API_KEY');
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        (new ZaiProviderAvailability())->record_definitive_verdict(
            false,
            new ApiKeyRequestAuthentication('')
        );

        $this->assertFalse(
            get_option(ZaiProviderAvailability::STATE_OPTION, false),
            'No verdict may be recorded for a credential that never flew.'
        );
        $this->assertNoHttpRequests();
    }

    public function testADatabaseOnlyValidKeyConnectsThroughAnUnwiredProbe()
    {
        // GLM5 #10 (positive half): a valid database-only key must report
        // connected on the strength of a REAL probe, not a silent default.
        putenv('ZAI_API_KEY');
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        $instance = new ZaiProviderAvailability();
        $instance->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        $this->assertTrue($instance->isConfigured());
        $this->assertCount(1, $this->sdkHttpAttempts());
    }

    public function testKeylessIsConfiguredDoesNotDeleteStateOnEveryCall()
    {
        /*
         * Code-review GLM1 #6: the keyless path called delete_option()
         * unconditionally on EVERY isConfigured() call, even when no state
         * row exists (ProviderRegistry consults availability on every
         * request) — a needless database DELETE per call. The delete now
         * runs only when a row is actually present.
         */
        $instance = new ZaiProviderAvailability();

        $this->assertFalse($instance->isConfigured());
        $this->assertFalse($instance->isConfigured());
        $this->assertNotContains(
            ZaiProviderAvailability::STATE_OPTION,
            WpHarness::$delete_option_attempts,
            'No delete_option() call may happen while no state row exists.'
        );

        // With a stale row present, the delete still runs (once is enough).
        update_option(ZaiProviderAvailability::STATE_OPTION, array('binding' => 'stale', 'valid' => 'valid'));
        $this->assertFalse($instance->isConfigured());
        $this->assertContains(ZaiProviderAvailability::STATE_OPTION, WpHarness::$delete_option_attempts);
    }

    public function testValidKeyProbesOnceAndPersistsStateWithoutTheKey()
    {
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());

        // Within the TTL the persisted verdict answers; no second probe.
        $this->assertTrue($instance->isConfigured());
        $this->assertCount(1, $this->sdkHttpAttempts());

        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state);
        $this->assertSame('valid', $state['valid']);
        $this->assertOptionNotPlaintext(
            ZaiProviderAvailability::STATE_OPTION,
            $key,
            'The persisted state must contain a binding hash, never the key.'
        );
    }

    public function testNonemptyButInvalidKeyReportsNotConnected()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token expired or incorrect'));

        $this->assertFalse($instance->isConfigured(), 'An invalid key must report not-connected (M1 exit criterion).');

        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state);
        $this->assertSame('invalid', $state['valid']);
    }

    public function testARejectionEnvelopeUnderHttp200IsDefinitiveOnTheZaiSurfaceToo()
    {
        /*
         * GLM12 #1: the body-aware verdict is ONE rule on both surfaces.
         * The live zai (OpenAI-compat) /models route answers 401 for a
         * garbage credential, but if a gateway ever frames the rejection
         * in band ({"code":401,"msg":"...","success":false} under HTTP
         * 200, the Anthropic route's live shape), the verdict must be the
         * same definitive invalid — and a 200 whose body is neither an
         * authenticated model list nor that envelope stays inconclusive.
         */
        $key = FakeSecrets::apiKey();

        $rejected = $this->availability($key);
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::zaiStatusEnvelopeBody(401, 'token expired or incorrect'));
        $this->assertFalse($rejected->isConfigured(), 'The in-band rejection envelope is definitive on this surface too.');
        $this->assertSame('invalid', get_option(ZaiProviderAvailability::STATE_OPTION)['valid']);

        delete_option(ZaiProviderAvailability::STATE_OPTION);

        $unrecognized = $this->availability(FakeSecrets::apiKey());
        $this->queueSdkResponse(200, array(), 'not json at all');
        $this->assertTrue($unrecognized->isConfigured(), 'An unrecognized 2xx body keeps the configured-pending default.');
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'An unrecognized 2xx body must not persist a verdict.');
    }

    public function testAValidProbeSeedsTheDiscoveryTransient()
    {
        /*
         * GLM12 #2: the availability probe and the metadata directory's
         * discovery each issued their own authenticated GET to the
         * IDENTICAL models_url — a cold window (no verdict state, no
         * discovery transient) paid two sequential blocking round trips
         * before the first generation. The probe's own response body now
         * seeds the discovery transient (the shared parser, the shared
         * endpoint-scoped id and TTL), and the directory's next lookup
         * consumes the seed without a second request.
         */
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.3-flash')));
        $this->assertTrue($instance->isConfigured());

        $endpoint = ZaiEndpoint::for_current_settings();
        $this->assertSame(
            array('glm-5.3', 'glm-5.3-flash'),
            get_transient(ZaiEndpoint::discovery_cache_id($endpoint->plan(), $endpoint->region())),
            'The probe response must seed the discovery transient with the plan-intersected chat IDs.'
        );

        $directory = new ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        $models = $directory->listModelMetadata();
        $this->assertSame(
            array('glm-5.3', 'glm-5.3-flash'),
            array_map(static function ($m) {
                return $m->getId();
            }, $models),
            'Discovery serves the seeded catalog.'
        );
        $this->assertCount(1, $this->sdkHttpAttempts(), 'Discovery must consume the seeded transient, not re-fetch the same URL.');
    }

    public function testAValidProbeDoesNotSeedDiscoveryFromACatalogUnusableBody()
    {
        /*
         * GLM12 #2 (the seed's edge): a body that fails the CATALOG read
         * seeds nothing — discovery keeps its own flow (live attempt,
         * negative marker, plan fallback) with its own failure caching.
         *
         * glm13-2 supersedes the earlier VERDICT-valid half of this pin:
         * an incomplete page (has_more) is not the models-list shape the
         * shared parser's ENTRY rule accepts, so the verdict predicate —
         * which now rides that one rule — treats it as an UNRECOGNIZED
         * 2xx body: still configured-pending, but no verdict persists
         * (the glm12-1 narrowing: only the models-list shape is VALID).
         */
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"data":[{"id":"glm-5.3"}],"has_more":true}');
        $this->assertTrue($instance->isConfigured(), 'An unrecognized 2xx body keeps the configured-pending default.');
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'An incomplete page must not persist a verdict.');

        $endpoint = ZaiEndpoint::for_current_settings();
        $this->assertFalse(
            get_transient(ZaiEndpoint::discovery_cache_id($endpoint->plan(), $endpoint->region())),
            'A catalog-unusable body must not seed the discovery transient.'
        );
    }

    public function testTheProbeDecodesTheResponseBodyOnce()
    {
        /*
         * glm13-3: the verdict and the discovery seed share ONE decode of
         * the probe body (the shared ZaiModelListParser decode) — pin the
         * source shape so a second decode or a re-read of the response
         * body cannot silently return on the blocking pre-generation path
         * (the same source-pinning idiom the mapping suites use).
         */
        $source = (string) file_get_contents(
            __DIR__ . '/../../connectors/zai/src/Availability/AbstractZaiProviderAvailability.php'
        );

        $this->assertSame(
            1,
            preg_match_all('/ZaiModelListParser::decode_models_body\(/', $source),
            'Exactly one decode of the probe body must exist.'
        );
        $this->assertSame(
            1,
            preg_match_all('/ZaiModelListParser::parse_decoded_chat_ids\(/', $source),
            'The discovery seed must ride the pre-decoded tree.'
        );
        $this->assertSame(
            0,
            preg_match_all('/parse_chat_ids\(/', $source),
            'The probe must not re-enter the parser through the response-decoding entry.'
        );
    }

    public function testAnEmptyModelsListUnderHttp200IsNotAValidVerdict()
    {        /*
         * glm13-2: {"data":[]} passed the verdict predicate's private
         * shape-copy vacuously (a foreach over zero entries) and
         * persisted VERDICT_VALID for the 300s STATE_TTL — while the
         * shared parser rejects the body (no usable chat ID) and the
         * discovery seed refuses to cache it. The predicate rides the
         * parser's ONE entry rule now: an empty list is an UNRECOGNIZED
         * 2xx body and stays INCONCLUSIVE (glm12-1) — never connected,
         * never an unproven invalid verdict.
         */
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"data":[]}');

        $this->assertTrue($instance->isConfigured(), 'An unrecognized 2xx body keeps the configured-pending default.');
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'An empty models list must not persist a valid verdict.');

        $endpoint = ZaiEndpoint::for_current_settings();
        $this->assertFalse(
            get_transient(ZaiEndpoint::discovery_cache_id($endpoint->plan(), $endpoint->region())),
            'An empty models list must not seed the discovery transient.'
        );
    }

    public function testInvalidVerdictIsReprobedAfterTtl()
    {
        $this->freezeTime(1700000000);
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('nope'));
        $this->assertFalse($instance->isConfigured());
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Still inside the TTL: persisted verdict, no new attempt.
        $this->assertFalse($instance->isConfigured());
        $this->assertCount(1, $this->sdkHttpAttempts());

        // After the TTL the probe runs again and may succeed.
        $this->advanceTime(ZaiProviderAvailability::STATE_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testValidToInvalidKeyReplacementFlipsToNotConnected()
    {
        $first = FakeSecrets::apiKey();
        $second = FakeSecrets::apiKey();

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($this->availability($first)->isConfigured());

        // The replacement key is rejected: a newly invalid key must NOT
        // appear connected on the strength of the old verdict.
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertFalse($this->availability($second)->isConfigured());
    }

    public function testInvalidToValidKeyReplacementFlipsToConnected()
    {
        $first = FakeSecrets::apiKey();
        $second = FakeSecrets::apiKey();

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertFalse($this->availability($first)->isConfigured());

        // The corrected key must not stay unavailable.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($this->availability($second)->isConfigured());
    }

    public function testReplacementKeySharingALongPrefixGetsItsOwnBinding()
    {
        // Common API-key formats share a provider prefix; the binding must
        // hash the COMPLETE key so a replacement can never inherit a verdict.
        $shared = bin2hex(random_bytes(20));
        $first = $shared . 'aaaaaaaaaaaa';
        $second = $shared . 'bbbbbbbbbbbb';

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($this->availability($first)->isConfigured());
        $firstBinding = get_option(ZaiProviderAvailability::STATE_OPTION)['binding'];

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertFalse($this->availability($second)->isConfigured());
        $secondBinding = get_option(ZaiProviderAvailability::STATE_OPTION)['binding'];

        $this->assertNotSame($firstBinding, $secondBinding);
    }

    public function testEnvSourceKeyGetsADistinctBindingFromTheSameDatabaseValue()
    {
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        try {
            putenv('ZAI_API_KEY=' . $key);

            $envAvailability = $this->availability($key);
            $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
            $this->assertTrue($envAvailability->isConfigured());
            $envBinding = get_option(ZaiProviderAvailability::STATE_OPTION)['binding'];
            $this->assertSame('env', $envAvailability->effective_key()['source']);

            // Same key VALUE, different source (plain DB, no env override):
            // the binding must differ so the source change re-validates.
            putenv('ZAI_API_KEY');
            $dbAvailability = $this->availability($key);
            $this->assertSame('database', $dbAvailability->effective_key()['source']);

            $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
            $this->assertTrue($dbAvailability->isConfigured());
            $dbBinding = get_option(ZaiProviderAvailability::STATE_OPTION)['binding'];

            $this->assertNotSame($envBinding, $dbBinding);
        } finally {
            putenv('ZAI_API_KEY');
        }
    }

    public function testRegionSwitchForcesRevalidationAgainstTheNewEndpoint()
    {
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);

        // Switch region: same key, same instance — the endpoint-bound binding
        // is now stale, so the very next check must probe the NEW endpoint.
        // (No region row exists yet, so the first save travels core's
        // add_option() delegation — GLM1 #7 — and fires the ADD hook.)
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame(1, did_action('add_option_' . PlanRegionSettings::OPTION_REGION));

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token invalid in this region'));
        $this->assertFalse($instance->isConfigured(), 'An international key must not count as connected on the China endpoint.');
        $this->assertSame(
            'https://open.bigmodel.cn/api/coding/paas/v4/models',
            $this->sdkHttpAttempts()[1]['url'],
            'The revalidation probe must target the new region.'
        );
    }

    public function testRegionSwitchClearsTheStoredKeySoAnInconclusiveProbeCannotRideIt()
    {
        $this->freezeTime(1700000000);
        // With the plugin's hooks active (the settings page save path), a
        // region switch must clear the STORED key too: clearing only the
        // verdict is not enough, because the cn /models probe 404s
        // (inconclusive → configured-pending) and the connector would send
        // the OLD international key against the China endpoint indefinitely.
        $intlKey = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $intlKey);
        $this->bootProvider();

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($this->availability($intlKey)->isConfigured());

        // Switch intl → cn through the real option write (fires the hook).
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertFalse(
            get_option(ZaiProviderAvailability::KEY_OPTION, false),
            'The region switch must delete the stored key (separate accounts, SPEC §3.3).'
        );
        $this->assertFalse(
            (new ZaiProviderAvailability())->isConfigured(),
            'No key after the switch means not connected — no probe may ride the old key.'
        );
        $this->assertCount(1, $this->sdkHttpAttempts(), 'The post-switch check must not send the old key anywhere.');

        // The admin supplies a key for the NEW region: core's key-save
        // validation wires a candidate (runtime source) and the cn /models
        // 404 stays acceptable (pending) — the R1 semantics, key-save path
        // only.
        $cnKey = FakeSecrets::apiKey();
        $candidate = $this->availability($cnKey);
        $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
        $this->assertTrue(
            $candidate->isConfigured(),
            'Saving a NEW key for the new region must be accepted despite the unprobed cn /models route.'
        );
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A 404 must not persist a verdict.');

        // A later definitive answer about the cn key is honored (past the
        // 60s binding miss marker — GLM1 #6; a fresh key save is a new
        // binding and probes immediately regardless).
        $this->advanceTime(ZaiProviderAvailability::PROBE_MISS_TTL + 1);
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad cn key'));
        $this->assertFalse($candidate->isConfigured());
    }

    public function testRegionSwitchWithEnvKeyStaysDisconnectedUntilDefinitivelyValidated()
    {
        // Codex r3: after a region switch the env credential (immutable —
        // the plugin cannot clear it like the database key) must not ride
        // configured-pending semantics onto the new endpoint. Only a
        // DEFINITIVE probe result may report it connected again.
        $envKey = FakeSecrets::apiKey();

        try {
            putenv('ZAI_API_KEY=' . $envKey);
            $this->bootProvider();
            $this->freezeTime(1700000000);

            // Connected on the OLD region before the switch.
            $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
            $this->assertTrue($this->availability($envKey)->isConfigured());
            $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);

            // Switch intl → cn through the real option write (fires the hook).
            update_option(PlanRegionSettings::OPTION_REGION, 'cn');

            $env = $this->envBackedAvailability($envKey); // Wired key == env value: source resolves to 'env'.

            // Inconclusive probe (the unprobed cn /models 404): the env key
            // must NOT appear connected on the new region.
            $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
            $this->assertFalse(
                $env->isConfigured(),
                'The old-region env key must not count as connected after the switch.'
            );
            $this->assertSame(
                'https://open.bigmodel.cn/api/coding/paas/v4/models',
                $this->sdkHttpAttempts()[1]['url'],
                'The pending check still probes the NEW endpoint with the env key.'
            );
            $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'An inconclusive probe must not persist a verdict.');
            $this->assertNotFalse(
                get_option(ZaiProviderAvailability::REGION_PENDING_OPTION, false),
                'The region-pending flag must stay while the probe is inconclusive.'
            );

            // Still inconclusive: still disconnected — and GLM7 #3 bounds the
            // doomed retries: within PROBE_MISS_TTL the binding-scoped miss
            // marker answers the repeat consult with NO live request (the
            // distrust no longer bypasses the negative cache).
            $this->assertFalse($env->isConfigured());
            $this->assertCount(2, $this->sdkHttpAttempts(), 'A repeat distrust consult inside the miss TTL must not probe again.');

            // A definitive 2xx ends the distrust: connected, flag cleared,
            // verdict persisted (answerable from state within the TTL). The
            // marker expired, so this consult probes live again.
            $this->advanceTime(ZaiProviderAvailability::PROBE_MISS_TTL + 1);
            $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
            $this->assertTrue(
                $env->isConfigured(),
                'A definitive success must report the env key connected for the new region.'
            );
            $this->assertFalse(get_option(ZaiProviderAvailability::REGION_PENDING_OPTION, false), 'The flag must clear on the definitive verdict.');
            $this->assertSame('valid', get_option(ZaiProviderAvailability::STATE_OPTION)['valid']);

            $this->assertTrue($env->isConfigured());
            $this->assertCount(3, $this->sdkHttpAttempts(), 'Within the TTL the persisted verdict answers without a new probe.');
        } finally {
            putenv('ZAI_API_KEY');
        }
    }

    public function testRegionSwitchWithEnvKeyDefinitiveRejectionIsActionable()
    {
        $envKey = FakeSecrets::apiKey();

        try {
            putenv('ZAI_API_KEY=' . $envKey);
            $this->bootProvider();

            update_option(PlanRegionSettings::OPTION_REGION, 'cn');

            $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token invalid in this region'));
            $env = $this->envBackedAvailability($envKey);
            $this->assertFalse($env->isConfigured(), 'A rejected credential must report not-connected.');

            // Actionable: the definitive rejection is persisted (the admin
            // sees WHY — the key is invalid for this endpoint), and the
            // distrust flag is resolved.
            $state = get_option(ZaiProviderAvailability::STATE_OPTION);
            $this->assertIsArray($state);
            $this->assertSame('invalid', $state['valid']);
            $this->assertFalse(
                get_option(ZaiProviderAvailability::REGION_PENDING_OPTION, false),
                'A definitive rejection resolves the region-pending flag.'
            );
        } finally {
            putenv('ZAI_API_KEY');
        }
    }

    public function testRegionSwitchDistrustProbingIsBoundedByTheNegativeCache()
    {
        /*
         * GLM7 #3: the distrust exemption defeated the 60s probe-miss
         * negative cache entirely — while an env key stayed region-pending
         * against a permanently inconclusive endpoint (the cn /models
         * 404), EVERY availability consult paid a live blocking
         * authenticated HTTPS probe and re-transmitted the old-region
         * credential to it, with no cap. Distrust now consults the same
         * marker: at most one doomed probe per PROBE_MISS_TTL window, one
         * retry after expiry.
         */
        $envKey = FakeSecrets::apiKey();

        try {
            putenv('ZAI_API_KEY=' . $envKey);
            $this->bootProvider();
            $this->freezeTime(1700000000);

            update_option(PlanRegionSettings::OPTION_REGION, 'cn');

            $env = $this->envBackedAvailability($envKey);

            // First consult: one live inconclusive probe; the marker is set
            // and the distrust keeps the connector disconnected.
            $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
            $this->assertFalse($env->isConfigured());

            // Repeat consults inside the window: the marker answers — no
            // live request may leave (nothing is queued, so an attempt
            // would surface as an unmocked HTTP leak).
            $this->assertFalse($env->isConfigured());
            $this->assertFalse($env->isConfigured());
            $this->assertCount(1, $this->sdkHttpAttempts(), 'Distrust must not bypass the probe-miss negative cache.');

            // After the marker expires exactly one retry happens, and the
            // definitive rejection resolves the distrust.
            $this->advanceTime(ZaiProviderAvailability::PROBE_MISS_TTL + 1);
            $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad in this region'));
            $this->assertFalse($env->isConfigured());
            $this->assertCount(2, $this->sdkHttpAttempts(), 'Exactly one retry per miss-TTL window.');
            $this->assertSame('invalid', get_option(ZaiProviderAvailability::STATE_OPTION)['valid']);
            $this->assertFalse(
                get_option(ZaiProviderAvailability::REGION_PENDING_OPTION, false),
                'The definitive rejection resolves the distrust.'
            );
        } finally {
            putenv('ZAI_API_KEY');
        }
    }

    public function testRegionSwitchDistrustDoesNotBlockSavingANewKeyForTheNewRegion()
    {
        // Core's key-save path must keep working: the candidate core wires
        // during validation is a DIFFERENT credential than the distrusted
        // env key, so the cn /models 404 stays accepted-pending for it —
        // and once the new key is stored (and the env override gone) the
        // stored key itself keeps normal pending semantics.
        $envKey = FakeSecrets::apiKey();
        $cnKey = FakeSecrets::apiKey();

        try {
            putenv('ZAI_API_KEY=' . $envKey);
            $this->bootProvider();

            update_option(PlanRegionSettings::OPTION_REGION, 'cn');

            $candidate = $this->availability($cnKey);
            $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
            $this->assertTrue(
                $candidate->isConfigured(),
                'Saving a new key for the new region must stay accepted-pending.'
            );
            $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A 404 must not persist a verdict.');

            // The env credential itself remains distrusted while effective.
            $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
            $this->assertFalse(
                $this->envBackedAvailability($envKey)->isConfigured(),
                'The env key must stay disconnected despite the saved db key (env wins resolution).'
            );
            $this->assertNotFalse(
                get_option(ZaiProviderAvailability::REGION_PENDING_OPTION, false),
                'The flag must persist for the env credential until definitively validated.'
            );

            // Env override removed: the stored key is now effective and is
            // NOT the distrusted credential — normal pending semantics.
            update_option(ZaiProviderAvailability::KEY_OPTION, $cnKey);
            putenv('ZAI_API_KEY');
            $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
            $this->assertTrue(
                $this->availability($cnKey)->isConfigured(),
                'The saved key for the new region keeps normal pending semantics.'
            );
        } finally {
            putenv('ZAI_API_KEY');
        }
    }

    public function testInconclusiveProbesAreNegativelyCachedForAShortTtl()
    {
        /*
         * Code-review GLM1 #6 (verifier must-fix): the finding's symptom
         * covers the availability consult too — ProviderRegistry calls
         * isConfigured() on every request, and a persistently inconclusive
         * /models route (the cn 404 shape) paid one doomed blocking GET
         * per consult. A 60s BINDING-scoped miss marker now suppresses the
         * repeat remote calls; the returned value is exactly what a live
         * inconclusive probe yields (configured-pending / stale-verdict
         * fallback), a DIFFERENT key is a different binding and probes
         * immediately, and the original binding is retryable after the
         * TTL with definitive answers honored.
         */
        $this->freezeTime(1700000000);
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
        $this->assertTrue($instance->isConfigured());
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Within the TTL: the same answer, NO new remote attempt.
        $this->assertTrue($instance->isConfigured(), 'The cached inconclusive outcome must equal the live one (configured-pending).');
        $this->assertCount(1, $this->sdkHttpAttempts(), 'The short miss marker must suppress the doomed repeat probe.');

        // A different key is a different binding: it probes immediately.
        $other = $this->availability(FakeSecrets::apiKey());
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($other->isConfigured());
        $this->assertCount(2, $this->sdkHttpAttempts());

        // After the TTL the original binding is retryable and a definitive
        // answer settles the state (never a fake connected state).
        $this->advanceTime(ZaiProviderAvailability::PROBE_MISS_TTL + 1);
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertFalse($instance->isConfigured());
        $this->assertCount(3, $this->sdkHttpAttempts());
    }

    public function testClearingTheProbeMissMarkerForcesALiveProbe()
    {
        /*
         * GLM2 #6: the live probe clears its positive caches before the
         * acceptance steps, but the binding-scoped probe-MISS marker
         * survived that clearing — a run within PROBE_MISS_TTL of a
         * transient failure served the cached inconclusive verdict with
         * ZERO live requests. clear_probe_miss_marker() deletes the
         * marker the NEXT consult would read for the CURRENT effective
         * binding, so the consult after it probes the live route again.
         */
        $this->freezeTime(1700000000);
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        // One inconclusive probe plants the miss marker.
        $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
        $this->assertTrue($instance->isConfigured());
        $this->assertCount(1, $this->sdkHttpAttempts());

        $this->assertTrue($instance->isConfigured(), 'Within the TTL the marker suppresses the repeat probe.');
        $this->assertCount(1, $this->sdkHttpAttempts());

        // The live-probe clearer removes exactly that marker...
        $instance->clear_probe_miss_marker();

        // ...so the next consult probes live again (a definitive answer
        // this time, persisting the verdict).
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());
        $this->assertCount(2, $this->sdkHttpAttempts(), 'After clearing the marker the consult must probe the live route.');
        $this->assertNotFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'The live verdict persists as usual.');
    }

    public function testClearingTheProbeMissMarkerIsANoOpWithoutACredential()
    {
        // No effective key: no binding, so no marker name to derive — the
        // clearer must not error or invent one.
        (new ZaiProviderAvailability())->clear_probe_miss_marker();

        $this->addToAssertionCount(1);
    }

    public function testRateLimitResponseDoesNotInvalidateAValidKey()
    {
        $this->freezeTime(1700000000);
        // z.ai returns 429 for plan mismatches on an otherwise VALID key
        // (error 1113, record 0006): the verdict must stay unpersisted and
        // the inconclusive probe must report configured-pending (true), not
        // block the key (review finding: core clears keys on false).
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(429, array(), HttpResponseFactory::openAiErrorBody('Insufficient balance or no resource package'));
        $this->assertTrue($instance->isConfigured(), 'An inconclusive probe must not report not-connected.');

        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A 429 must not persist an invalid verdict.');

        /*
         * And the next check probes again (no cached false verdict; the
         * 60s miss marker only spans PROBE_MISS_TTL seconds — GLM1 #6).
         */
        $this->advanceTime(ZaiProviderAvailability::PROBE_MISS_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());
    }

    public function testCnRegionModels404DoesNotBlockKeySaving()
    {
        $this->freezeTime(1700000000);
        // The cn /models path is unprobed and expected to 404 (record 0006):
        // a newly submitted key (core REST validation sets it as a runtime
        // candidate) must still be accepted — an unavailable probe ROUTE
        // says nothing about the credential, and core clears keys when
        // isConfigured() returns false (review finding).
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
        $this->assertTrue(
            $instance->isConfigured(),
            'An inconclusive probe on the cn endpoint must not block the key save.'
        );
        $this->assertSame('https://open.bigmodel.cn/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A 404 must not persist a verdict.');

        /*
         * It also never settles into a fake connected state: the next check
         * probes again and a definitive answer (if the route ever answers)
         * is honored (past the 60s miss marker — GLM1 #6).
         */
        $this->advanceTime(ZaiProviderAvailability::PROBE_MISS_TTL + 1);
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertFalse($instance->isConfigured());
    }

    public function testForbiddenResponsePersistsInvalidVerdict()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(403, array(), HttpResponseFactory::openAiErrorBody('no access'));
        $this->assertFalse($instance->isConfigured());

        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state);
        $this->assertSame('invalid', $state['valid'], '403 (credential lacks access) must persist.');
    }

    public function testTransportFailureIsTransientAndNotPersisted()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        // No queued mock: the harness transport throws a network error. The
        // probe is inconclusive — configured-pending, never a persisted
        // verdict, never a blocked key save.
        $this->allowUnmockedHttp = true;
        $this->assertTrue($instance->isConfigured());
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A transport failure must not persist a verdict.');
    }

    public function testServerErrorIsTransientAndNotPersisted()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(503, array(), 'upstream overloaded');
        $this->assertTrue($instance->isConfigured(), 'A 5xx is inconclusive for the credential: configured-pending.');
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A 5xx must not persist a verdict.');
    }

    public function testStaleMatchingVerdictSurvivesATransientFailure()
    {
        $this->freezeTime(1700000000);
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());

        // Past the TTL, the next probe hits a transport failure: the stale
        // (matching) verdict is the better answer than flipping to false.
        $this->advanceTime(ZaiProviderAvailability::STATE_TTL + 60);
        $this->allowUnmockedHttp = true;
        $this->assertTrue($instance->isConfigured());
    }

    /*
     * UTC clock for TTL math (review finding: local-time timestamps break
     * the elapsed-time calculation when the site timezone changes).
     */

    public function testTimezoneChangeAfterStoringDoesNotDistortTheTtl()
    {
        $this->freezeTime(1700000000);
        WpHarness::$utc_offset = 2 * HOUR_IN_SECONDS;
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());

        // checked_at must be UTC (the frozen clock), NOT local time.
        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $this->assertSame(1700000000, $state['checked_at'], 'checked_at must be stored as UTC.');
        $this->assertSame(ZaiProviderAvailability::STATE_CLOCK_UTC, $state['clock']);

        // Site timezone moves from +2 to -5: the stored verdict must still
        // expire after the TTL (with local timestamps the delta goes
        // negative and the verdict stays "fresh" for hours).
        WpHarness::$utc_offset = -5 * HOUR_IN_SECONDS;
        $this->advanceTime(ZaiProviderAvailability::STATE_TTL + 60);

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('now rejected'));
        $this->assertFalse($instance->isConfigured(), 'The verdict must have expired despite the timezone change.');
        $this->assertCount(2, $this->sdkHttpAttempts(), 'The expired verdict must trigger a fresh probe.');
    }

    public function testLegacyStateWithoutUtcMarkerIsReprobed()
    {
        $this->freezeTime(1700000000);
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());

        // Strip the clock marker: the stored checked_at basis is unknown, so
        // the state must be treated as stale and re-probed, never trusted.
        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        unset($state['clock']);
        update_option(ZaiProviderAvailability::STATE_OPTION, $state, false);

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());
        $this->assertCount(2, $this->sdkHttpAttempts(), 'A marker-less (legacy) state must be re-probed.');

        // The rewrite restores the marker.
        $this->assertSame(ZaiProviderAvailability::STATE_CLOCK_UTC, get_option(ZaiProviderAvailability::STATE_OPTION)['clock']);
    }

    public function testRejectionRecordingHasOneSharedStatusSource()
    {
        /*
         * GLM10 #8: what counts as a definitive credential rejection is
         * stated ONCE (the availability base) and consumed by the probe
         * and both directories' discovery recording — the set was
         * hand-copied per site and the lockstep already failed once
         * (GLM7 #12 landed one side only; glm9-5 re-landed the twin).
         * The pin fixes the documented set and the shared helper's
         * both-edges behavior: definitive statuses record the invalid
         * verdict (bound to the request-time endpoint when given),
         * everything else records nothing.
         */
        $this->assertSame(array(401, 403), AbstractZaiProviderAvailability::DEFINITIVE_REJECTION_STATUSES, 'The documented definitive-rejection set.');
        $this->assertTrue(ZaiProviderAvailability::is_definitive_rejection(401));
        $this->assertTrue(ZaiProviderAvailability::is_definitive_rejection(403));
        $this->assertFalse(ZaiProviderAvailability::is_definitive_rejection(404));
        $this->assertFalse(ZaiProviderAvailability::is_definitive_rejection(429));

        $this->freezeTime(1700000000);
        $key = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        $availability = new ZaiProviderAvailability();
        $availability->record_rejection_for_status(
            404,
            function () {
                throw new WordPress\AiClient\Common\Exception\RuntimeException('unwired');
            }
        );
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A non-definitive status records nothing.');

        $availability->record_rejection_for_status(
            403,
            function () use ($key) {
                return new ApiKeyRequestAuthentication($key);
            },
            ZaiEndpoint::for('coding', 'intl')->cache_key()
        );

        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state, 'The definitive status records the invalid verdict.');
        $this->assertSame('invalid', $state['valid']);
        $this->assertSame(
            PlanRegionSettings::credential_binding('database', ZaiEndpoint::for('coding', 'intl')->cache_key(), $key),
            $state['binding'],
            'The helper records with the wired credential and the given request-time endpoint.'
        );
    }

    public function testAFutureCheckedAtIsNotFreshAndIsReprobed()
    {
        /*
         * GLM10 #2: elapsed = now - checked_at had no lower bound, so a
         * checked_at in the FUTURE (clock skew between web nodes, a
         * state restored from an ahead-clocked server) yielded a
         * negative elapsed that always passed the < STATE_TTL test —
         * the verdict read fresh for as long as the skew lasted, and a
         * server-side-revoked key kept reporting connected far past the
         * advertised TTL. A future checked_at must distrust the state
         * and re-probe; the probe rewrites checked_at on this node's
         * clock, healing the skew.
         */
        $this->freezeTime(1700000000);
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token expired or incorrect'));
        $this->assertFalse($instance->isConfigured(), 'The definitive rejection persists.');
        $this->assertSame(
            'invalid_verdict',
            $instance->generation_refusal_reason(),
            'Sanity pin: the FRESH invalid verdict refuses generation.'
        );

        // checked_at 1h in the future — an ahead-clocked writer.
        $state = get_option(ZaiProviderAvailability::STATE_OPTION);
        $state['checked_at'] = 1700000000 + HOUR_IN_SECONDS;
        update_option(ZaiProviderAvailability::STATE_OPTION, $state, false);

        $this->assertNull($instance->generation_refusal_reason(), 'An unageable (future checked_at) invalid verdict must refuse nothing.');

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured(), 'The unageable state must be re-probed, not answered from state.');
        $this->assertCount(2, $this->sdkHttpAttempts(), 'The future checked_at must trigger a fresh probe.');

        // The rewrite heals the skew: checked_at is back on this clock.
        $this->assertSame(1700000000, get_option(ZaiProviderAvailability::STATE_OPTION)['checked_at']);
    }

    public function testEffectiveKeyResolutionMirrorsCoreOrder()
    {
        putenv('ZAI_API_KEY');
        $plain = new ZaiProviderAvailability();

        $dbKey = FakeSecrets::apiKey();
        update_option(ZaiProviderAvailability::KEY_OPTION, $dbKey);
        $this->assertSame(array('key' => $dbKey, 'source' => 'database'), $plain->effective_key());

        delete_option(ZaiProviderAvailability::KEY_OPTION);
        $this->assertSame(array('key' => '', 'source' => 'none'), $plain->effective_key());
    }

    public function testRuntimeSourceForUnstoredRegistryKey()
    {
        /*
         * Core sets a candidate key on the registry during REST validation
         * before it is stored. The SOURCE label stays 'runtime' (an
         * unstored candidate), but its binding normalizes to the
         * 'database' identity (GLM5 #11): the same credential, one
         * binding, across the save→store transition.
         */
        $candidate = FakeSecrets::apiKey();
        $instance = $this->availability($candidate);

        $this->assertSame('runtime', $instance->effective_key()['source']);
    }

    public function testAnInvalidVerdictPersistsAcrossTheSaveStoreTransition()
    {
        /*
         * GLM5 #11: the refusal gate matched stored verdicts by
         * binding(source,key), but key_source() labels the same
         * credential 'runtime' at save time and 'database' once stored —
         * a fresh invalid verdict persisted under the 'runtime' binding
         * did not refuse the identical credential later read from the
         * stored option. 'runtime' normalizes to 'database' at binding
         * construction now: one credential identity across the
         * transition.
         */
        $this->freezeTime(1700000000);
        putenv('ZAI_API_KEY');
        $key = FakeSecrets::apiKey();

        // Save-time shape: core wires the candidate key (runtime source)
        // and the endpoint definitively rejects it.
        $candidate = $this->availability($key);
        $this->queueSdkResponse(401, array(), '{"error":{"message":"bad key"}}');

        $this->assertFalse($candidate->isConfigured(), 'The candidate key is definitively rejected.');
        $this->assertSame('invalid', get_option(ZaiProviderAvailability::STATE_OPTION)['valid'], 'The rejection persists.');

        // Stored shape: the same key value read back from the option (no
        // wired auth) must inherit the definitive verdict — the gate
        // refuses it and no fresh probe rides the stored binding.
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        $stored = new ZaiProviderAvailability();
        $stored->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());

        $this->assertSame('invalid_verdict', $stored->generation_refusal_reason(), 'The invalid verdict must refuse the identical stored credential.');
        $this->assertFalse($stored->isConfigured(), 'The stored verdict must hold across the transition.');
        $this->assertCount(1, $this->sdkHttpAttempts(), 'No fresh probe may ride the stored verdict.');
    }

    public function testTheGateAndTheRecorderResolveTheWiredCredentialAlike()
    {
        /*
         * GLM9 #14: generation_refusal_reason() and
         * record_definitive_verdict() decided through copy-pasted
         * optional-authentication ternaries — divergent copies would make
         * the refusal gate and the verdict recorder disagree about WHICH
         * credential a verdict binds (the GLM5 #11 divergence class: one
         * label split a credential into two bindings and let a
         * definitively rejected key through). One
         * effective_for_authentication() resolves both now; the pin: a
         * verdict recorded through a wired credential (the probe path)
         * is refused through the SAME wired credential, from state, with
         * no fresh request.
         */
        $this->freezeTime(1700000000);
        putenv('ZAI_API_KEY');
        $key = FakeSecrets::apiKey();

        $wired = $this->availability($key);
        $this->queueSdkResponse(403, array(), '{"error":{"message":"forbidden"}}');
        $this->assertFalse($wired->isConfigured(), 'The wired credential is definitively rejected.');

        $attempts = count($this->sdkHttpAttempts());
        $this->assertSame(
            'invalid_verdict',
            $wired->generation_refusal_reason(new ApiKeyRequestAuthentication($key)),
            'The recorded verdict must refuse the same wired credential.'
        );
        $this->assertSame($attempts, count($this->sdkHttpAttempts()), 'The refusal answers from state, no fresh probe.');
    }
    public function testIdentifierConstantsAreChildOwnedNotInheritedDefaults()
    {
        /*
         * GLM6 #12: the shared provider and availability bases ship NO
         * provider's identifier constants — a future child forgetting a
         * declaration must fail LOUD (undefined constant), never
         * silently read and write the zai provider's options under
         * runtime-dead base defaults.
         */
        $this->assertSame(
            array(),
            self::declared_constants_intersect(AbstractZaiProvider::class, array(
                'PROVIDER_ID',
            )),
            'The provider base must not carry a provider ID default.'
        );
        $this->assertSame(
            array(),
            self::declared_constants_intersect(AbstractZaiProviderAvailability::class, array(
                'STATE_OPTION',
                'REGION_PENDING_OPTION',
                'KEY_OPTION',
                'KEY_ENV_NAME',
                'REFUSAL_LABEL',
            )),
            'The availability base must not carry the zai provider identifiers.'
        );

        foreach (array(ZaiProvider::class, ZaiAnthropicProvider::class) as $provider) {
            $this->assertContains('PROVIDER_ID', self::declared_constants($provider), "{$provider} must declare its connector ID.");
        }

        $identifiers = array('STATE_OPTION', 'REGION_PENDING_OPTION', 'KEY_OPTION', 'KEY_ENV_NAME', 'REFUSAL_LABEL');
        foreach (array(ZaiProviderAvailability::class, ZaiAnthropicProviderAvailability::class) as $availability) {
            $missing = array_diff($identifiers, self::declared_constants($availability));
            $this->assertSame(array(), $missing, "{$availability} must declare every identifier constant.");
        }
    }

    /**
     * The constants a class declares ITSELF (inherited ones excluded).
     *
     * @param string $class Class name.
     * @return list<string> Declared constant names.
     */
    private static function declared_constants(string $class): array
    {
        $names = array();
        foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $class) {
                $names[] = $constant->getName();
            }
        }

        return $names;
    }

    /**
     * Which of the given names a class declares itself.
     *
     * @param string        $class Class name.
     * @param list<string>  $names Constant names to probe.
     * @return list<string> The intersecting declared names.
     */
    private static function declared_constants_intersect(string $class, array $names): array
    {
        return array_values(array_intersect($names, self::declared_constants($class)));
    }

    public function testBothProvidersRideTheSharedModelScaffold()
    {
        /*
         * GLM8 #12 (extraction pin, the GLM4 #10 pattern): the capability
         * walk and the unsupported-capabilities rejection, and the
         * ProviderMetadata construction, were line-for-line identical in
         * both providers except the instantiated class — hoisted to the
         * shared AbstractZaiProvider. Neither provider may re-roll the
         * scaffolding; each declares only its model_class() hook.
         */
        $base = (string) file_get_contents(__DIR__ . '/../../connectors/zai/src/Provider/AbstractZaiProvider.php');
        $this->assertStringContainsString('Unsupported model capabilities', $base, 'The base owns the capability-walk rejection.');
        $this->assertStringContainsString('new ProviderMetadata( ...static::provider_metadata_args()', $base, 'The base owns the metadata construction.');

        foreach (array(
            'zai' => __DIR__ . '/../../connectors/zai/src/Provider/ZaiProvider.php',
            'zai_anthropic' => __DIR__ . '/../../connectors/zai/src/Provider/ZaiAnthropicProvider.php',
        ) as $label => $path) {
            $source = (string) file_get_contents($path);

            $this->assertSame(
                0,
                preg_match('/Unsupported model capabilities/', $source),
                "[{$label}] The provider must not re-roll the capability-walk rejection."
            );
            $this->assertSame(
                0,
                preg_match('/new ProviderMetadata\(/', $source),
                "[{$label}] The provider must not construct ProviderMetadata itself."
            );
            $this->assertSame(
                1,
                preg_match('/function model_class\(\): string/', $source),
                "[{$label}] The provider declares the model_class() hook."
            );
        }
    }
}
