<?php
/**
 * Shared skeleton for the OPT-IN live smoke tests (glm28-11).
 *
 * The two live smoke tests (ZaiLiveSmokeTest, ZaiAnthropicLiveSmokeTest)
 * rode the same ~40-line scaffold line-for-line — the skip guard, the
 * env-with-owner-constant plan/region defaults, the three option
 * writes, the transporter/registry/provider wiring, the
 * availability/discovery/generation acceptance order, and the
 * no-plaintext assertion — one surface label apart. Neither runs in CI
 * (both skip without the opt-in key), so the copies could drift
 * silently: an acceptance-step or env-name change had to land twice or
 * one probe shipped stale evidence. One parameterized base owns the
 * round trip (the Abstract*MappingTestCase pattern); each surface
 * names its owner classes and keeps its own test name.
 *
 * Skipped unless WP_CONNECTORS_TEST_ZAI_API_KEY is set (docs/TESTING.md);
 * optional WP_CONNECTORS_TEST_ZAI_PLAN / WP_CONNECTORS_TEST_ZAI_REGION
 * select the endpoint. Never runs under `composer check`, never prints
 * the key, and asserts only safe facts.
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
use Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings;

abstract class AbstractZaiSurfaceLiveSmokeTestCase extends WpConnectorsTestCase
{
    /**
     * Live requests go through a real curl client, not the recording harness
     * client, so the unmocked-attempt audit must be relaxed for this test.
     *
     * @var bool
     */
    protected $allowUnmockedHttp = true;

    /**
     * The surface's settings class (the option names and plan default's
     * owner).
     *
     * @return string
     */
    abstract protected function settings_class(): string;

    /**
     * The surface's availability class (the key-state options' owner).
     *
     * @return string
     */
    abstract protected function availability_class(): string;

    /**
     * The surface's provider class (the provider id's owner).
     *
     * @return string
     */
    abstract protected function provider_class(): string;

    protected function setUp(): void
    {
        parent::setUp();

        if ('' === (string) getenv('WP_CONNECTORS_TEST_ZAI_API_KEY')) {
            $this->markTestSkipped('Live z.ai test requires WP_CONNECTORS_TEST_ZAI_API_KEY (opt-in only; see docs/TESTING.md).');
        }
    }

    /**
     * One live round trip: option writes, wiring, the availability and
     * discovery acceptance steps, one real generation, and the
     * no-plaintext assertion (glm28-11).
     *
     * @return void
     */
    protected function assert_live_round_trip(): void
    {
        $settings     = $this->settings_class();
        $availability = $this->availability_class();
        $provider     = $this->provider_class();

        $key = (string) getenv('WP_CONNECTORS_TEST_ZAI_API_KEY');
        /*
         * glm25-6: the option names, defaults, and provider id ride
         * their owner constants (the GLM10 #15 class, the anthropic
         * twin's glm21-15 idiom) — after a rename this test writes
         * options the plugin reads and probes the surface it reports
         * as evidence.
         */
        $plan   = (string) (getenv('WP_CONNECTORS_TEST_ZAI_PLAN') ?: $settings::DEFAULT_PLAN);
        $region = (string) (getenv('WP_CONNECTORS_TEST_ZAI_REGION') ?: AbstractPlanRegionSettings::DEFAULT_REGION);

        update_option($settings::OPTION_PLAN, $plan);
        update_option($settings::OPTION_REGION, $region);
        update_option($availability::KEY_OPTION, $key);

        $registry = AiClient::defaultRegistry();
        $registry->setHttpTransporter(new HttpTransporter(new CurlPsr18Client()));

        \Deicod\WpConnectors\Zai\Plugin::register($registry);
        $registry->setProviderRequestAuthentication($provider::PROVIDER_ID, new ApiKeyRequestAuthentication($key));

        // Availability: the authenticated /models probe against the live endpoint.
        $this->assertTrue(
            $provider::availability()->isConfigured(),
            "Live availability probe failed for {$plan}+{$region} — check the key matches the selected plan/region."
        );

        // Discovery: the live model list.
        $models = $provider::modelMetadataDirectory()->listModelMetadata();
        $this->assertNotEmpty($models);

        // Inference: one real generation through the plugin model class.
        $model  = $registry->getProviderModel($provider::PROVIDER_ID, $models[0]->getId());
        $result = $model->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Reply with the single word: ok'))),
        ));

        $this->assertNotSame('', trim($result->toText()));

        // The key must never appear in any state the plugin persisted.
        $this->assertOptionNotPlaintext($availability::STATE_OPTION, $key);
    }
}
