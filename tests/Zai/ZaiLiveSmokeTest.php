<?php
/**
 * Task 1.9 — OPT-IN live smoke test (coding + international by default).
 *
 * Skipped unless WP_CONNECTORS_TEST_ZAI_API_KEY is set (docs/TESTING.md);
 * optional WP_CONNECTORS_TEST_ZAI_PLAN / WP_CONNECTORS_TEST_ZAI_REGION
 * select the endpoint. Never runs under `composer check`, never prints the
 * key, and asserts only safe facts. The round-trip skeleton rides the one
 * shared base (glm28-11, the Abstract*MappingTestCase pattern).
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

final class ZaiLiveSmokeTest extends AbstractZaiSurfaceLiveSmokeTestCase
{
    protected function settings_class(): string
    {
        return PlanRegionSettings::class;
    }

    protected function availability_class(): string
    {
        return ZaiProviderAvailability::class;
    }

    protected function provider_class(): string
    {
        return ZaiProvider::class;
    }

    public function testLiveCodingInternationalRoundTrip()
    {
        $this->assert_live_round_trip();
    }
}
