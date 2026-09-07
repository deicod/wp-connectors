<?php
/**
 * Task 2.7 — OPT-IN live smoke test for the zai_anthropic surface.
 *
 * Skipped unless WP_CONNECTORS_TEST_ZAI_API_KEY is set (docs/TESTING.md);
 * optional WP_CONNECTORS_TEST_ZAI_PLAN / WP_CONNECTORS_TEST_ZAI_REGION
 * select the endpoint (the SAME account key works on both surfaces per
 * SPEC §3.2). Never runs under `composer check`, never prints the key, and
 * asserts only safe facts. The round-trip skeleton rides the one shared
 * base (glm28-11, the Abstract*MappingTestCase pattern).
 *
 * Default GENERAL: record 0007 proved the coding-surface Messages routes
 * cannot generate, so the provider's own default is general.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

final class ZaiAnthropicLiveSmokeTest extends AbstractZaiSurfaceLiveSmokeTestCase
{
    protected function settings_class(): string
    {
        return ZaiAnthropicPlanRegionSettings::class;
    }

    protected function availability_class(): string
    {
        return ZaiAnthropicProviderAvailability::class;
    }

    protected function provider_class(): string
    {
        return ZaiAnthropicProvider::class;
    }

    public function testLiveAnthropicRoundTrip()
    {
        $this->assert_live_round_trip();
    }
}
