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

    public function testTheLiveProbeCoversEveryOwnerSurfaceAndProviderRegistration()
    {
        /*
         * The probe's per-surface wiring map carries SDK-dependent
         * columns (provider, availability, provider_id, default_plan)
         * the SDK-free owner may not hold, so its map stays — pinned:
         * every settings/endpoint class the registry names and every
         * provider registration must appear in the probe source, and
         * the map must carry exactly one row per surface.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/zai-live-probe.php');

        foreach (\Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES as $index => $surface) {
            $this->assertStringContainsString(
                $surface['settings'],
                $source,
                "Surface row [{$index}]: the probe must wire its settings class."
            );
            $this->assertStringContainsString(
                $surface['endpoint'],
                $source,
                "Surface row [{$index}]: the probe must wire its endpoint class."
            );
        }

        foreach (\Deicod\WpConnectors\Zai\Plugin::PROVIDER_CLASSES as $provider_class) {
            $this->assertStringContainsString(
                $provider_class,
                $source,
                'The probe must wire every provider registration.'
            );
        }

        $this->assertSame(
            count(\Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES),
            preg_match_all("/'provider_id'\s*=>/", $source),
            'Exactly one probe surface row per owner surface.'
        );
    }
}
