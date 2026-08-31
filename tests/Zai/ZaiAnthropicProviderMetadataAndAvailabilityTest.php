<?php
/**
 * Task 2.1 — zai_anthropic provider metadata and availability tests.
 *
 * Covers the second provider's metadata shapes, the invalid-key →
 * not-connected criterion validated INDEPENDENTLY of the zai provider (its
 * validated state can never establish this provider's status), and the
 * persisted state binding/scoping rules.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

final class ZaiAnthropicProviderMetadataAndAvailabilityTest extends WpConnectorsTestCase
{
    /**
     * Builds a standalone availability instance wired to the harness
     * transporter with the given key.
     *
     * @param string $key API key (fixture value).
     * @return ZaiAnthropicProviderAvailability
     */
    private function availability(string $key): ZaiAnthropicProviderAvailability
    {
        $instance = new ZaiAnthropicProviderAvailability();
        $instance->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $instance->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        return $instance;
    }

    /*
     * Provider metadata across SDK versions.
     */

    public function testMetadataShapeOnMinimumSdk()
    {
        $args = ZaiAnthropicProvider::provider_metadata_args('1.1.0');

        $this->assertCount(5, $args);
        $this->assertSame('zai_anthropic', $args[0]);
        $this->assertSame('z.ai (Anthropic API)', $args[1]);
        $this->assertSame('https://z.ai/manage/apikey/apikey', $args[3]);
    }

    public function testMetadataShapeOnSdk120AddsDescription()
    {
        $args = ZaiAnthropicProvider::provider_metadata_args('1.2.0');

        $this->assertCount(6, $args);
        $this->assertSame('GLM text generation via the z.ai Anthropic-compatible API.', $args[5]);
    }

    public function testMetadataShapeOnSdk130AddsLogo()
    {
        $args = ZaiAnthropicProvider::provider_metadata_args('1.3.0');

        $this->assertCount(7, $args);
        $this->assertStringEndsWith('/assets/zai.svg', $args[6]);
        $this->assertFileExists($args[6], 'Logo file must ship inside the plugin.');
    }

    public function testCredentialsUrlFollowsTheAnthropicRegionSelectionOnly()
    {
        // Only the zai_anthropic region drives this provider's link.
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');
        update_option(PlanRegionSettings::OPTION_REGION, 'intl');

        $this->assertSame(
            'https://open.bigmodel.cn/usercenter/apikeys',
            ZaiAnthropicProvider::provider_metadata_args('1.1.0')[3],
            'The anthropic cn region must advertise the open.bigmodel.cn key portal.'
        );

        // Flipping the ZAI provider's region changes nothing for this one.
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame(ZaiAnthropicProvider::CN_CREDENTIALS_URL, ZaiAnthropicProvider::provider_metadata_args('1.1.0')[3]);

        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl');
        $this->assertSame(
            'https://z.ai/manage/apikey/apikey',
            ZaiAnthropicProvider::provider_metadata_args('1.1.0')[3],
            'The anthropic intl region keeps the z.ai key portal.'
        );
    }

    public function testDetectedSdkProducesTheNewerShape()
    {
        $metadata = ZaiAnthropicProvider::metadata();

        $this->assertSame('zai_anthropic', $metadata->getId());
        $this->assertNotNull($metadata->getAuthenticationMethod());
        $this->assertTrue($metadata->getAuthenticationMethod()->isApiKey());
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $this->assertSame('GLM text generation via the z.ai Anthropic-compatible API.', $metadata->getDescription());
        }
    }

    /*
     * Availability: independent validation (Task 2.1's core requirement).
     */

    public function testNonemptyButInvalidKeyReportsNotConnected()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(401, array(), '{"type":"error","error":{"type":"authentication_error","message":"token expired or incorrect"}}');

        $this->assertFalse($instance->isConfigured(), 'An invalid key must report not-connected for THIS provider (M2 exit criterion).');

        $state = get_option(ZaiAnthropicProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state);
        $this->assertSame('invalid', $state['valid']);
        $this->assertFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'No zai state may be written by this provider.');
    }

    public function testValidKeyProbesOnceAndPersistsStateWithoutTheKey()
    {
        $key = FakeSecrets::apiKey();
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, $key);
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), '{"data":[{"id":"glm-5.3","display_name":"GLM 5.3","type":"model"}]}');
        $this->assertTrue($instance->isConfigured());

        // Within the TTL the persisted verdict answers; no second probe.
        $this->assertTrue($instance->isConfigured());
        $this->assertCount(1, $this->sdkHttpAttempts());

        $state = get_option(ZaiAnthropicProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state);
        $this->assertSame('valid', $state['valid']);
        $this->assertOptionNotPlaintext(
            ZaiAnthropicProviderAvailability::STATE_OPTION,
            $key,
            'The persisted state must contain a binding hash, never the key.'
        );
    }

    public function testTheProbeTargetsTheAnthropicSurfaceModelsRoute()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), '{"data":[{"id":"glm-5.3","type":"model"}]}');
        $this->assertTrue($instance->isConfigured());

        $attempts = $this->sdkHttpAttempts();
        $this->assertSame('https://api.z.ai/api/anthropic/v1/models', $attempts[0]['url']);
    }

    public function testAZaiValidatedStateCanNeverEstablishAnthropicStatus()
    {
        $key = FakeSecrets::apiKey();

        // The zai provider validated this exact key (state present, valid,
        // same region/plan selections) — zai_anthropic must still probe on
        // its own: its state option is separate and its binding embeds the
        // provider-scoped endpoint identity.
        update_option(ZaiProviderAvailability::STATE_OPTION, array(
            'binding'    => hash('sha256', 'database|zai|coding|intl|' . $key),
            'valid'      => 'valid',
            'checked_at' => time() + 60,
            'clock'      => ZaiProviderAvailability::STATE_CLOCK_UTC,
        ));
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        // The anthropic endpoint rejects the credential.
        $instance = $this->availability($key);
        $this->queueSdkResponse(401, array(), '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}');

        $this->assertFalse(
            $instance->isConfigured(),
            'Task 1.4\'s validated state for zai must not establish zai_anthropic\'s status.'
        );
        $this->assertCount(1, $this->sdkHttpAttempts(), 'The verdict must come from this provider\'s own probe.');
    }

    public function testAnAnthropicValidatedStateCanNeverEstablishZaiStatus()
    {
        $key = FakeSecrets::apiKey();

        // Mirror image: a valid anthropic verdict, then the zai endpoint
        // rejects — zai reports not-connected on the strength of its own
        // (missing) state and its own probe.
        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array(
            'binding'    => hash('sha256', 'runtime|zai_anthropic|coding|intl|' . $key),
            'valid'      => 'valid',
            'checked_at' => time() + 60,
            'clock'      => ZaiAnthropicProviderAvailability::STATE_CLOCK_UTC,
        ));

        $zai = new ZaiProviderAvailability();
        $zai->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $zai->setRequestAuthentication(new ApiKeyRequestAuthentication($key));
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token expired or incorrect'));

        $this->assertFalse($zai->isConfigured());
    }

    public function testNoKeyMeansNotConfiguredAndStateCleared()
    {
        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'stale', 'valid' => 'valid'));

        $instance = new ZaiAnthropicProviderAvailability();

        $this->assertFalse($instance->isConfigured());
        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::STATE_OPTION, false), 'Stale state must be removed when no key exists.');
        $this->assertNoHttpRequests();
    }

    public function testRateLimitResponseDoesNotInvalidateAValidKey()
    {
        $key = FakeSecrets::apiKey();
        $instance = $this->availability($key);

        // z.ai 429 is also code 1113 (plan/balance mismatch, record 0006):
        // inconclusive for the credential.
        $this->queueSdkResponse(429, array(), '{"type":"error","error":{"type":"rate_limit_error","message":"Insufficient balance"}}');
        $this->assertTrue($instance->isConfigured(), 'An inconclusive probe must not report not-connected.');
        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::STATE_OPTION, false), 'A 429 must not persist a verdict.');
    }

    public function testRegionSwitchForcesRevalidationAgainstTheNewAnthropicEndpoint()
    {
        $key = FakeSecrets::apiKey();
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, $key);
        $instance = $this->availability($key);

        $this->queueSdkResponse(200, array(), '{"data":[{"id":"glm-5.3","type":"model"}]}');
        $this->assertTrue($instance->isConfigured());
        $this->assertSame('https://api.z.ai/api/anthropic/v1/models', $this->sdkHttpAttempts()[0]['url']);

        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        $this->queueSdkResponse(401, array(), '{"type":"error","error":{"type":"authentication_error","message":"wrong region"}}');
        $this->assertFalse($instance->isConfigured(), 'An international key must not count as connected on the China endpoint.');
        $this->assertSame(
            'https://open.bigmodel.cn/api/anthropic/v1/models',
            $this->sdkHttpAttempts()[1]['url'],
            'The revalidation probe must target the new region\'s ANTHROPIC endpoint.'
        );
    }

    public function testEffectiveKeyResolutionUsesTheAnthropicNames()
    {
        putenv('ZAI_ANTHROPIC_API_KEY');

        $dbKey = FakeSecrets::apiKey();
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, $dbKey);
        $plain = new ZaiAnthropicProviderAvailability();

        $this->assertSame(array('key' => $dbKey, 'source' => 'database'), $plain->effective_key());

        delete_option(ZaiAnthropicProviderAvailability::KEY_OPTION);
        $this->assertSame(array('key' => '', 'source' => 'none'), $plain->effective_key());

        // The zai provider's key option is NOT a source for this provider.
        update_option(ZaiProviderAvailability::KEY_OPTION, 'zai-only-key');
        $this->assertSame(array('key' => '', 'source' => 'none'), $plain->effective_key());
        delete_option(ZaiProviderAvailability::KEY_OPTION);
    }

    public function testAnthropicEnvNameDrivesTheEnvSource()
    {
        $envKey = FakeSecrets::apiKey();

        try {
            putenv('ZAI_ANTHROPIC_API_KEY=' . $envKey);

            $instance = $this->availability($envKey);
            $this->assertSame(array('key' => $envKey, 'source' => 'env'), $instance->effective_key());
        } finally {
            putenv('ZAI_ANTHROPIC_API_KEY');
        }
    }
}
