<?php
/**
 * Task 2.7 — OPT-IN live smoke test for the zai_anthropic surface.
 *
 * Skipped unless WP_CONNECTORS_TEST_ZAI_API_KEY is set (docs/TESTING.md);
 * optional WP_CONNECTORS_TEST_ZAI_PLAN / WP_CONNECTORS_TEST_ZAI_REGION
 * select the endpoint (the SAME account key works on both surfaces per
 * SPEC §3.2). Never runs under `composer check`, never prints the key, and
 * asserts only safe facts.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;

final class ZaiAnthropicLiveSmokeTest extends WpConnectorsTestCase
{
    /**
     * Live requests go through a real curl client, not the recording harness
     * client, so the unmocked-attempt audit must be relaxed for this test.
     */
    protected $allowUnmockedHttp = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ('' === (string) getenv('WP_CONNECTORS_TEST_ZAI_API_KEY')) {
            $this->markTestSkipped('Live z.ai test requires WP_CONNECTORS_TEST_ZAI_API_KEY (opt-in only; see docs/TESTING.md).');
        }
    }

    public function testLiveAnthropicRoundTrip()
    {
        $key = (string) getenv('WP_CONNECTORS_TEST_ZAI_API_KEY');
        // Default GENERAL: record 0007 proved the coding-surface Messages
        // routes cannot generate, so the provider's own default is general.
        $plan = (string) (getenv('WP_CONNECTORS_TEST_ZAI_PLAN') ?: 'general');
        $region = (string) (getenv('WP_CONNECTORS_TEST_ZAI_REGION') ?: 'intl');

        update_option('zai_connector_zai_anthropic_plan', $plan);
        update_option('zai_connector_zai_anthropic_region', $region);
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, $key);

        $registry = AiClient::defaultRegistry();
        $registry->setHttpTransporter(new HttpTransporter(new CurlPsr18Client()));

        \Deicod\WpConnectors\Zai\Plugin::register($registry);
        $registry->setProviderRequestAuthentication('zai_anthropic', new ApiKeyRequestAuthentication($key));

        // Availability: authenticated /v1/models probe against the live
        // Anthropic-surface endpoint (this also settles the O1 question for
        // the selected endpoint whenever it returns a definitive answer).
        $this->assertTrue(
            ZaiAnthropicProvider::availability()->isConfigured(),
            "Live availability probe failed for {$plan}+{$region} — check the key matches the selected plan/region."
        );

        // Discovery: live /v1/models list (falls back to the static catalog
        // on any failure, so a non-empty list is guaranteed either way).
        $models = ZaiAnthropicProvider::modelMetadataDirectory()->listModelMetadata();
        $this->assertNotEmpty($models);

        // Inference: one real Messages generation through the plugin model.
        $model = $registry->getProviderModel('zai_anthropic', $models[0]->getId());
        $result = $model->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Reply with the single word: ok'))),
        ));

        $this->assertNotSame('', trim($result->toText()));

        // The key must never appear in any state the plugin persisted.
        $this->assertOptionNotPlaintext(ZaiAnthropicProviderAvailability::STATE_OPTION, $key);
    }
}
