<?php
/**
 * Shared skeleton for the OPT-IN live smoke tests (glm28-11, glm29-9).
 *
 * The two live smoke tests (ZaiLiveSmokeTest, ZaiAnthropicLiveSmokeTest)
 * rode the same ~40-line scaffold line-for-line until glm28-11 moved it
 * to this parameterized base (each surface names its owner classes and
 * keeps its own test name). glm29-9 moved the round trip ITSELF one
 * layer down: the base and bin/zai-live-probe.php had each
 * hand-maintained the acceptance sequence and drifted in both
 * directions (the CLI carried the state-option delete, the probe-miss
 * clear, the definitive-verdict check, the discovery-transient
 * clearing and live-vs-fallback evidence, and the preferred-model
 * fallback this skeleton lacked, while the skeleton alone checked the
 * state option for plaintext). tests/harness/ZaiLiveRoundTrip.php owns
 * the ordered steps now, reconciled to the stricter side; this base
 * judges its structured outcome through assertions and the CLI through
 * exit codes.
 *
 * Skipped unless WP_CONNECTORS_TEST_ZAI_API_KEY is set (docs/TESTING.md);
 * optional WP_CONNECTORS_TEST_ZAI_PLAN / WP_CONNECTORS_TEST_ZAI_REGION
 * select the endpoint. Never runs under `composer check`, never prints
 * the key, and asserts only safe facts.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

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
     * One live round trip through the shared owner, judged by assertion
     * (glm28-11 moved the twins onto one base; glm29-9 moved the base onto
     * the one runner the CLI probe also rides).
     *
     * @return void
     */
    protected function assert_live_round_trip(): void
    {
        $settings = $this->settings_class();
        $provider = $this->provider_class();

        $key = (string) getenv('WP_CONNECTORS_TEST_ZAI_API_KEY');
        /*
         * glm25-6: the plan/region defaults ride their owner constants —
         * after a rename this test selects the endpoint the plugin would.
         */
        $plan   = (string) (getenv('WP_CONNECTORS_TEST_ZAI_PLAN') ?: $settings::DEFAULT_PLAN);
        $region = (string) (getenv('WP_CONNECTORS_TEST_ZAI_REGION') ?: AbstractPlanRegionSettings::DEFAULT_REGION);

        /*
         * glm21-10's derivation: the endpoint pairing comes from the one
         * cross-file owner registry — never restated per surface (the
         * same scan the CLI probe's facts map performs over its rows).
         */
        $endpoint_class = null;
        foreach ( Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES as $row ) {
            if ( $row['settings'] === $settings ) {
                $endpoint_class = $row['endpoint'];
                break;
            }
        }
        if ( null === $endpoint_class ) {
            throw new RuntimeException( "No zai surface registered for settings class {$settings}." );
        }

        $outcome = ZaiLiveRoundTrip::run(
            $settings,
            $provider,
            $endpoint_class,
            $key,
            $plan,
            $region,
            static function ( string $label, $value ): void {}
        );

        /*
         * R17b: connected alone is not acceptance — an inconclusive probe
         * (no definitive verdict persisted) must fail the test, not
         * masquerade as a pass.
         */
        $this->assertTrue($outcome['availability']['definitive'], "Live availability probe was inconclusive for {$plan}+{$region} — no definitive verdict persisted.");
        $this->assertTrue($outcome['availability']['connected'], "Live availability probe failed for {$plan}+{$region} — check the key matches the selected plan/region.");

        /*
         * Codex R8 #6: a non-empty model list alone is not acceptance —
         * listModelMetadata() silently serves the static fallback on
         * discovery failure, and a fallback list is non-empty too.
         */
        $this->assertNotEmpty($outcome['discovery']['models'], 'Live model discovery returned no models.');
        $this->assertTrue($outcome['discovery']['live'], 'Discovery served the static fallback — live discovery failed or was malformed.');

        // Codex R17: blank output is not an acceptance pass.
        $this->assertTrue($outcome['generation']['ok'], 'The live generation returned empty output for the sentinel prompt.');

        // The key must never appear in any state the plugin persisted.
        $this->assertFalse($outcome['state_option_plaintext'], 'The validation state option carries the live key in plaintext.');
    }
}
