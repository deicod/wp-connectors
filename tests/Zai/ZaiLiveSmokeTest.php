<?php
/**
 * Task 1.9 — OPT-IN live smoke test (coding + international by default).
 *
 * Skipped unless WP_CONNECTORS_TEST_ZAI_API_KEY is set (docs/TESTING.md);
 * optional WP_CONNECTORS_TEST_ZAI_PLAN / WP_CONNECTORS_TEST_ZAI_REGION
 * select the endpoint. Never runs under `composer check`, never prints the
 * key, and asserts only safe facts.
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
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;

final class ZaiLiveSmokeTest extends WpConnectorsTestCase
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

    public function testLiveCodingInternationalRoundTrip()
    {
        $key = (string) getenv('WP_CONNECTORS_TEST_ZAI_API_KEY');
        $plan = (string) (getenv('WP_CONNECTORS_TEST_ZAI_PLAN') ?: 'coding');
        $region = (string) (getenv('WP_CONNECTORS_TEST_ZAI_REGION') ?: 'intl');

        update_option('zai_connector_zai_plan', $plan);
        update_option('zai_connector_zai_region', $region);
        update_option(ZaiProviderAvailability::KEY_OPTION, $key);

        $registry = AiClient::defaultRegistry();
        $registry->setHttpTransporter(new HttpTransporter(new CurlPsr18Client()));

        \Deicod\WpConnectors\Zai\Plugin::register($registry);
        $registry->setProviderRequestAuthentication('zai', new ApiKeyRequestAuthentication($key));

        // Availability: authenticated /models probe against the live endpoint.
        $this->assertTrue(
            ZaiProvider::availability()->isConfigured(),
            "Live availability probe failed for {$plan}+{$region} — check the key matches the selected plan/region."
        );

        // Discovery: live /models list.
        $models = ZaiProvider::modelMetadataDirectory()->listModelMetadata();
        $this->assertNotEmpty($models);

        // Inference: one real generation through the plugin model class.
        $model = $registry->getProviderModel('zai', $models[0]->getId());
        $result = $model->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Reply with the single word: ok'))),
        ));

        $this->assertNotSame('', trim($result->toText()));

        // The key must never appear in any state the plugin persisted.
        $this->assertOptionNotPlaintext(ZaiProviderAvailability::STATE_OPTION, $key);
    }
}
