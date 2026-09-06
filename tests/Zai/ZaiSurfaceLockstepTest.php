<?php
/**
 * Cross-file surface-set lockstep pins (glm20-4).
 *
 * The (zai, zai_anthropic) surface set was hand-enumerated in four
 * independent lists — Plugin::PROVIDER_CLASSES, zai.php's surface
 * settings list, uninstall.php's surface registry, and
 * bin/zai-live-probe.php's probe surface map — with the repo's own
 * comments recording the silent-strand drift class that already
 * happened twice when one listing missed an edit. ZaiSurfaces is the
 * ONE owner of the SDK-free facts now: zai.php and uninstall.php
 * DERIVE their lists from it; the two SDK-dependent sites (the
 * provider registrations, the probe's per-surface wiring — columns a
 * SDK-free owner may not hold, glm15-23's fixed alias direction) stay
 * owned where they are and are PINNED to the registry here instead:
 * a third surface added anywhere without its lockstep edit fails one
 * of these tests, never silently.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

final class ZaiSurfaceLockstepTest extends WpConnectorsTestCase
{
    public function testTheOwnerRegistryStatesTheFullSurfaceSetInRegistrationOrder()
    {
        $surfaces = \Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES;

        $this->assertSame(
            array(
                array(
                    'settings' => \Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::class,
                    'endpoint' => \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::class,
                ),
                array(
                    'settings' => \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::class,
                    'endpoint' => \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::class,
                ),
            ),
            $surfaces,
            'The owner registry states the full surface set in registration order.'
        );

        $this->assertSame(
            array(
                \Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::class,
                \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::class,
            ),
            \Deicod\WpConnectors\Zai\Support\ZaiSurfaces::settings_classes(),
            'The settings-class list boot() wires its hooks through is the registry row order.'
        );

        /*
         * glm15-23: each surface's slug is its settings class's
         * CACHE_SCOPE — the registry restates no slug strings, so the
         * derivation is pinned and the scopes must stay unique (the
         * first-registration-wins skip would silently hide a
         * duplicate-ID surface).
         */
        $scopes = array();
        foreach ($surfaces as $surface) {
            $scopes[] = $surface['settings']::CACHE_SCOPE;
        }

        $this->assertSame($scopes, array_values(array_unique($scopes)), 'Every surface slug (CACHE_SCOPE) is unique.');
    }

    public function testEveryProviderRegistrationMatchesAnOwnerSurfaceRowInOrder()
    {
        /*
         * PROVIDER_CLASSES cannot derive from the SDK-free owner (its
         * entries are SDK-dependent classes), so it is pinned: same
         * count, and provider[i]'s registry ID must be surface[i]'s
         * slug — a third surface without its provider registration (or
         * the reverse, or an order divergence) fails here.
         */
        $provider_classes = \Deicod\WpConnectors\Zai\Plugin::PROVIDER_CLASSES;
        $surfaces = \Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES;

        $this->assertSame(
            count($surfaces),
            count($provider_classes),
            'Every owner surface row has exactly one provider registration.'
        );

        foreach ($provider_classes as $index => $provider_class) {
            $this->assertSame(
                $surfaces[$index]['settings']::CACHE_SCOPE,
                $provider_class::PROVIDER_ID,
                "Provider registration [{$index}] must be surface row [{$index}]: the provider ID aliases the surface slug (glm15-23)."
            );
        }
    }

    public function testTheBootstrapDerivesItsSurfaceListFromTheOwner()
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/connectors/zai/zai.php');

        $this->assertSame(
            1,
            substr_count($source, 'ZaiSurfaces::settings_classes()'),
            'boot() derives its surface list from the one cross-file owner.'
        );
        $this->assertSame(
            0,
            substr_count($source, '$surface_settings = array('),
            'No second hand-enumerated surface list in the bootstrap file — the drift vector glm20-4 removed.'
        );
    }

    public function testProviderCardNamesAliasTheSettingsLayerLabels()
    {
        /*
         * glm24-2: the Connectors-card display names were hand-mirrored
         * literals (ZaiAnthropicProvider::PROVIDER_NAME, the zai
         * provider_display_name() return) with no tie to the settings
         * layer's PROVIDER_LABEL the settings section header renders —
         * each side's tests pinned only their own copy, so the card and
         * the settings page could name one connector two ways after a
         * one-sided rename (the per-side-pin vacuity glm21-18
         * documented). Both surfaces alias the settings label now, the
         * PROVIDER_ID/CACHE_SCOPE pattern; the pin reads the card name
         * through the public metadata args (index 1 is
         * provider_display_name()), so a re-inlined divergent literal
         * fails on either surface.
         */
        $surfaces = \Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES;
        $provider_classes = \Deicod\WpConnectors\Zai\Plugin::PROVIDER_CLASSES;

        foreach ($surfaces as $index => $surface) {
            $this->assertSame(
                $surface['settings']::PROVIDER_LABEL,
                $provider_classes[$index]::provider_metadata_args()[1],
                "Surface [{$index}]: the Connectors card name aliases the settings layer's PROVIDER_LABEL."
            );
        }
    }

    public function testTheLiveProbeDerivesItsSurfacePairingFromTheOwner()
    {
        /*
         * glm21-10: the probe's settings/endpoint pairing is DERIVED
         * from ZaiSurfaces::SURFACES now, so a pairing swap between
         * registry rows reaches the probe with the same edit. What
         * stays in the probe is the SDK-dependent facts table the
         * SDK-free owner may not hold (glm15-23's fixed alias
         * direction), keyed by the registry row's settings class — the
         * full-pairing pin: the derivation statements present, every
         * registry settings class keyed exactly once, NO endpoint class
         * restated, the CLI whitelist derived from the built map's keys,
         * and one facts row per surface. The old form only
         * substring-pinned the class names, so a pairing swap between
         * rows kept every assertion green (round 21 finding 10).
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/zai-live-probe.php');

        $this->assertSame(
            1,
            substr_count($source, 'ZaiSurfaces::SURFACES'),
            'The probe derives its surface rows from the one cross-file owner.'
        );
        $this->assertSame(
            1,
            substr_count($source, "\$zai_probe_sdk_facts[ \$zai_probe_row['settings'] ]"),
            'The probe facts table is keyed by the registry row\'s settings class.'
        );
        $this->assertSame(
            1,
            substr_count($source, "\$zai_probe_row['endpoint']"),
            'The probe map rows take their endpoint class from the registry row.'
        );
        $this->assertSame(
            0,
            substr_count($source, "array( 'openai', 'anthropic' )"),
            'The CLI whitelist derives from the built map, not a literal.'
        );

        /*
         * glm24-1: the probe's availability-class column is GONE. The
         * option names ride the registry row's settings class (the owner
         * the availability layer's constants alias, glm15-23's fixed
         * direction), so the probe states NO availability class at all —
         * the hand pairing was the one probe fact no pin covered, and a
         * swap between rows wrote one surface's key option while
         * deleting the other's validation state.
         */
        $this->assertSame(
            1,
            substr_count($source, "\$surface_facts['settings']::KEY_OPTION"),
            'The probe reads the key option through the registry row\'s settings class.'
        );
        $this->assertSame(
            1,
            substr_count($source, "\$surface_facts['settings']::STATE_OPTION"),
            'The probe reads the state option through the registry row\'s settings class.'
        );

        foreach (\Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES as $index => $surface) {
            $short_settings = substr($surface['settings'], (int) strrpos($surface['settings'], '\\') + 1);
            $short_endpoint = substr($surface['endpoint'], (int) strrpos($surface['endpoint'], '\\') + 1);

            $this->assertSame(
                1,
                preg_match_all('/\b' . preg_quote($short_settings, '/') . '::class/', $source),
                "Surface row [{$index}]: the probe facts table keys its SDK columns by the registry settings class."
            );
            $this->assertStringNotContainsString(
                $short_endpoint,
                $source,
                "Surface row [{$index}]: the endpoint pairing must not be restated in the probe (the drift vector glm21-10 removed)."
            );
        }

        foreach (\Deicod\WpConnectors\Zai\Plugin::PROVIDER_CLASSES as $provider_class) {
            $this->assertStringContainsString(
                $provider_class,
                $source,
                'The probe must wire every provider registration.'
            );
        }

        foreach (\Deicod\WpConnectors\Zai\Plugin::PROVIDER_CLASSES as $provider_class) {
            /*
             * Word-bounded, so the AbstractZaiProviderAvailability
             * instanceof the probe keeps is not a restatement.
             */
            $availability_class = get_class($provider_class::availability());
            $short_availability = substr($availability_class, (int) strrpos($availability_class, '\\') + 1);

            $this->assertSame(
                0,
                preg_match_all('/\b' . preg_quote($short_availability, '/') . '\b/', $source),
                "The probe must not restate {$short_availability}: the provider's own availability() factory owns that pairing, and its option constants alias the settings class (glm24-1)."
            );
        }

        $this->assertSame(
            count(\Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES),
            preg_match_all("/'cli'\s*=>/", $source),
            'Exactly one probe facts row per owner surface.'
        );
    }

    public function testTheLiveSmokeTestsRideTheOwnerConstants()
    {
        /*
         * glm21-15/glm21-18 (source pin, the GLM10 #15 class the live
         * probe was fixed in): the opt-in zai_anthropic live smoke test
         * hand-stringed the surface's plan/region option names and
         * provider id where owner constants exist — after a rename the
         * test would write options nothing reads and probe the default
         * plan/region while reporting the env-selected ones as
         * acceptance evidence (and billing the wrong surface). The pin
         * lives HERE, not in the smoke suite: its class setUp() skips
         * every test without the opt-in key, so an in-suite pin would
         * be dead in every offline composer check run (the
         * verifier-round catch).
         *
         * glm25-6: the scan covers BOTH live-smoke twins now — the zai
         * twin's hand-stringed literals were the identical uncovered
         * shape on the other surface (composer check green offline,
         * then the next live run writing options nothing reads).
         */
        foreach (array(
            'zai' => array(
                'file' => dirname(__DIR__, 2) . '/tests/Zai/ZaiLiveSmokeTest.php',
                'settings' => 'PlanRegionSettings',
                'provider' => 'ZaiProvider',
            ),
            'zai_anthropic' => array(
                'file' => dirname(__DIR__, 2) . '/tests/Zai/ZaiAnthropicLiveSmokeTest.php',
                'settings' => 'ZaiAnthropicPlanRegionSettings',
                'provider' => 'ZaiAnthropicProvider',
            ),
        ) as $surface => $owner) {
            $source = (string) file_get_contents($owner['file']);

            $this->assertSame(0, preg_match('/[\'"]zai_connector_/', $source), "Every plugin option name in the {$surface} smoke test rides an owner constant.");
            $this->assertSame(0, preg_match('/[\'"]' . preg_quote($surface, '/') . '[\'"]/', $source), "The {$surface} provider id rides its owner constant.");
            $this->assertStringContainsString($owner['settings'] . '::OPTION_PLAN', $source, "The {$surface} plan option rides its owner constant.");
            $this->assertStringContainsString($owner['provider'] . '::PROVIDER_ID', $source, "The {$surface} provider id is wired through the owner constant.");
        }
    }
    public function testBothDirectoriesRideTheOneDiscoveryConsultOrchestrator()
    {
        /*
         * glm26-6 (source pin): the metadata-consult skeleton (endpoint
         * resolve -> discovery cache id -> cached_ids -> memoized_map) is
         * stated ONCE, on ZaiDiscoveryCache::resolved_map(); each
         * directory composes it with its own discovery closure. A
         * directory re-spelling the skeleton inline is the
         * composition-layer drift GLM4 #10's shared cache left standing
         * (round 26 finding 9: a caching-rule change could land on one
         * surface only and the two silently diverge).
         */
        foreach (array(
            'the zai directory' => 'src/Metadata/ZaiModelMetadataDirectory.php',
            'the zai_anthropic directory' => 'src/Metadata/ZaiAnthropicModelMetadataDirectory.php',
        ) as $label => $relative) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/connectors/zai/' . $relative);

            $this->assertStringContainsString('ZaiDiscoveryCache::resolved_map(', $source, "{$label} rides the shared orchestrator.");
            $this->assertSame(0, preg_match('/ZaiDiscoveryCache::(cached_ids|memoized_map)\(\s*\$/', $source), "{$label} spells no consult skeleton inline (statement shape; docblock mentions excluded).");
        }
    }
}
