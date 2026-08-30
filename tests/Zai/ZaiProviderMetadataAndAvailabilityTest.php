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
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
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

    public function testMetadataShapeOnSdk120AddsDescription()
    {
        $args = ZaiProvider::provider_metadata_args('1.2.0');

        $this->assertCount(6, $args);
        $this->assertSame('GLM text generation via the z.ai OpenAI-compatible API.', $args[5]);
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
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame(1, did_action('update_option_' . PlanRegionSettings::OPTION_REGION));

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token invalid in this region'));
        $this->assertFalse($instance->isConfigured(), 'An international key must not count as connected on the China endpoint.');
        $this->assertSame(
            'https://open.bigmodel.cn/api/coding/paas/v4/models',
            $this->sdkHttpAttempts()[1]['url'],
            'The revalidation probe must target the new region.'
        );
    }

    public function testRateLimitResponseDoesNotInvalidateAValidKey()
    {
        // z.ai returns 429 for plan mismatches on an otherwise VALID key
        // (error 1113, record 0006): the verdict must stay unpersisted.
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(429, array(), HttpResponseFactory::openAiErrorBody('Insufficient balance or no resource package'));
        $this->assertFalse($instance->isConfigured(), 'Inconclusive probe reports unavailable for this call.');

        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A 429 must not persist an invalid verdict.');

        // And the next check probes again (no cached false verdict).
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($instance->isConfigured());
    }

    public function testNotFoundResponseDoesNotInvalidateTheKey()
    {
        // The cn /models path is unprobed (record 0006): a 404 there says
        // nothing about the credential.
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');
        $this->assertFalse($instance->isConfigured());
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A 404 must not persist an invalid verdict.');
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

        // No queued mock: the harness transport throws a network error.
        $this->allowUnmockedHttp = true;
        $this->assertFalse($instance->isConfigured());
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'A transport failure must not persist a verdict.');
    }

    public function testServerErrorIsTransientAndNotPersisted()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(503, array(), 'upstream overloaded');
        $this->assertFalse($instance->isConfigured());
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
        // Core sets a candidate key on the registry during REST validation
        // before it is stored; its verdict binds to 'runtime'.
        $candidate = FakeSecrets::apiKey();
        $instance = $this->availability($candidate);

        $this->assertSame('runtime', $instance->effective_key()['source']);
    }
}
