<?php
/**
 * Task 1.3 — endpoint resolver tests.
 *
 * Table-driven verification of the exact SPEC §3.1 URLs, canonical baseUrl(),
 * immutability, and request-time option resolution.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Endpoints\AbstractZaiEndpoint;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

final class ZaiEndpointResolverTest extends WpConnectorsTestCase
{
    /**
     * Data provider: the four SPEC §3.1 OpenAI-surface combinations.
     *
     * @return array<string, list<string>>
     */
    public function provideEndpointMatrix()
    {
        return array(
            'coding+intl (default)' => array('coding', 'intl', 'https://api.z.ai/api/coding/paas/v4'),
            'coding+cn' => array('coding', 'cn', 'https://open.bigmodel.cn/api/coding/paas/v4'),
            'general+intl' => array('general', 'intl', 'https://api.z.ai/api/paas/v4'),
            'general+cn' => array('general', 'cn', 'https://open.bigmodel.cn/api/paas/v4'),
        );
    }

    /**
     * @dataProvider provideEndpointMatrix
     */
    public function testResolvesExactUrlsForEveryCombination($plan, $region, $baseUrl)
    {
        $endpoint = ZaiEndpoint::for($plan, $region);

        $this->assertSame($plan, $endpoint->plan());
        $this->assertSame($region, $endpoint->region());
        $this->assertSame($baseUrl, $endpoint->base_url());
        $this->assertSame($baseUrl, $endpoint->api_url());
        $this->assertSame($baseUrl . '/models', $endpoint->api_url('models'));
        $this->assertSame($baseUrl . '/models', $endpoint->api_url('/models'));
        $this->assertSame($baseUrl . '/chat/completions', $endpoint->api_url('chat/completions'));
        $this->assertSame('zai|' . $plan . '|' . $region, $endpoint->cache_key());
    }

    public function testCurrentSettingsDefaultToCodingInternational()
    {
        $endpoint = ZaiEndpoint::for_current_settings();

        $this->assertSame('coding', $endpoint->plan());
        $this->assertSame('intl', $endpoint->region());
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $endpoint->base_url());
    }

    /**
     * @dataProvider provideEndpointMatrix
     */
    public function testCurrentSettingsHonorStoredOptions($plan, $region)
    {
        update_option(PlanRegionSettings::OPTION_PLAN, $plan);
        update_option(PlanRegionSettings::OPTION_REGION, $region);

        $endpoint = ZaiEndpoint::for_current_settings();

        $this->assertSame($plan, $endpoint->plan());
        $this->assertSame($region, $endpoint->region());
        $this->assertSame(ZaiEndpoint::MATRIX[$plan][$region], $endpoint->base_url());
    }

    public function testCorruptSettingsFallBackToTheDefaultEndpoint()
    {
        update_option(PlanRegionSettings::OPTION_PLAN, array('garbage'));
        update_option(PlanRegionSettings::OPTION_REGION, null);

        $endpoint = ZaiEndpoint::for_current_settings();

        $this->assertSame('coding', $endpoint->plan());
        $this->assertSame('intl', $endpoint->region());
    }

    /**
     * The core Task 1.3 requirement: an option change retargets the NEXT
     * request, resolved at request time, without rebuilding the registry.
     */
    public function testOptionChangeRetargetsSubsequentResolutionWithoutRegistryRebuild()
    {
        $provider_class = ZaiProvider::class;

        $first = ZaiEndpoint::for_current_settings();
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $first->api_url('models'));

        // The registry/provider state does not change; only the option does.
        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame($provider_class, ZaiProvider::class);

        $second = ZaiEndpoint::for_current_settings();
        $this->assertSame('https://open.bigmodel.cn/api/paas/v4/models', $second->api_url('models'));
        $this->assertNotSame($first->base_url(), $second->base_url());
    }

    public function testProviderBaseUrlStaysCanonicalRegardlessOfSettings()
    {
        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        // AbstractApiProvider::url() builds on the fixed canonical base URL.
        $this->assertSame(
            'https://api.z.ai/api/paas/v4/chat/completions',
            ZaiProvider::url('chat/completions')
        );
        $this->assertSame(ZaiEndpoint::CANONICAL_BASE_URL, ZaiProvider::url());
    }

    public function testEndpointIsImmutable()
    {
        $endpoint = ZaiEndpoint::for('coding', 'intl');

        // The value object exposes no mutators; a changed setting produces a
        // NEW instance and leaves existing ones untouched.
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('intl', $endpoint->region());
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $endpoint->base_url());
        $this->assertNotSame($endpoint, ZaiEndpoint::for_current_settings());
    }

    public function testTheMemoizedValueInstanceIsSharedPerCombinationAndFreshPerInput()
    {
        /*
         * glm15-6: for() memoizes the immutable value instance per
         * concrete surface and plan × region — one AI request resolves
         * the endpoint two to three times (the gate's binding, the
         * request build, a rejection recording). The memo is keyed by
         * the full input tuple: the same combination shares ONE
         * instance, a different combination gets its own, and
         * for_current_settings() still re-reads the options every call
         * (the retarget-the-next-request contract, pinned above).
         */
        $first = ZaiEndpoint::for('coding', 'intl');
        $this->assertSame($first, ZaiEndpoint::for('coding', 'intl'), 'The same plan × region shares one memoized instance.');
        $this->assertNotSame($first, ZaiEndpoint::for('general', 'intl'), 'A different combination is a different instance.');
        $this->assertNotSame($first, ZaiAnthropicEndpoint::for('coding', 'intl'), 'A different surface is a different instance.');

        update_option(PlanRegionSettings::OPTION_REGION, 'cn');
        $this->assertSame(ZaiEndpoint::for('coding', 'cn'), ZaiEndpoint::for_current_settings(), 'for_current_settings() still resolves the CURRENT options through the per-combination memo.');
    }

    public function testUnknownCombinationIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        ZaiEndpoint::for('hobby', 'intl');
    }

    public function testUnknownRegionIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        ZaiEndpoint::for('coding', 'eu');
    }

    public function testABaseUrlAlreadyCarryingAnEndpointSuffixLosesItExactlyOnce()
    {
        /*
         * GLM8 #10: the double-append guard exists on BOTH surfaces now —
         * it lived only in the Anthropic copy while this surface ran a
         * bare rtrim, the live drift the shared AbstractZaiEndpoint base
         * exists to stop. A base URL that already carries one of this
         * surface's suffixes (a matrix edit, a hand-built value) loses it
         * exactly once, so api_url() can never produce
         * {base}/models/models or {base}/chat/completions/chat/completions.
         */
        $this->assertSame(
            'https://api.z.ai/api/paas/v4',
            ZaiEndpoint::normalize_base_url('https://api.z.ai/api/paas/v4/models'),
            'A /models suffix strips.'
        );
        $this->assertSame(
            'https://api.z.ai/api/paas/v4',
            ZaiEndpoint::normalize_base_url('https://api.z.ai/api/paas/v4/chat/completions'),
            'A /chat/completions suffix strips.'
        );
        $this->assertSame(
            'https://api.z.ai/api/paas/v4',
            ZaiEndpoint::normalize_base_url('https://api.z.ai/api/paas/v4/'),
            'A trailing slash strips (the historical bare-rtrim behavior).'
        );
        $this->assertSame(
            'https://api.z.ai/api/paas/v4',
            ZaiEndpoint::normalize_base_url('https://api.z.ai/api/paas/v4'),
            'A clean base URL is untouched.'
        );

        $models = ZaiEndpoint::for('general', 'intl')->models_url();
        $this->assertSame('https://api.z.ai/api/paas/v4/models', $models);
        $this->assertSame($models, ZaiEndpoint::normalize_base_url($models) . '/models', 'The models URL never accumulates suffixes.');
    }

    public function testDiscoveryCacheIdsComposeTheHistoricalFormulaForEveryCombination()
    {
        /*
         * GLM8 #11: the endpoint layer owns the ONE discovery cache-id
         * composition. This pin freezes it to the historical formula the
         * pre-extraction mirrors produced (and the settings/uninstall
         * tests still seed their transients by), so any recipe change —
         * scope order, prefix, md5 — is a conscious, loudly-pinned edit.
         */
        foreach (array(
            array(ZaiEndpoint::class, 'zai_connector_zai_models_', 'zai'),
            array(ZaiAnthropicEndpoint::class, 'zai_connector_zai_anthropic_models_', 'zai_anthropic'),
        ) as list($endpoint_class, $prefix, $scope)) {
            foreach (array('coding', 'general') as $plan) {
                foreach (array('intl', 'cn') as $region) {
                    $expected = $prefix . md5("{$scope}|{$plan}|{$region}");

                    $this->assertSame($expected, $endpoint_class::discovery_cache_id($plan, $region), "{$endpoint_class} [{$plan}+{$region}] keeps the historical composition.");
                    $this->assertSame(
                        array($expected, $expected . '_miss'),
                        $endpoint_class::discovery_transient_ids($plan, $region),
                        "{$endpoint_class} [{$plan}+{$region}] pairs the id with the miss marker."
                    );

                    // The composition matches what an endpoint INSTANCE
                    // of the same combination would key by.
                    $this->assertSame(
                        $expected,
                        $endpoint_class::CACHE_PREFIX . md5($endpoint_class::for($plan, $region)->cache_key()),
                        "{$endpoint_class} [{$plan}+{$region}] equals the instance-composed id the directories used to build."
                    );
                }
            }
        }
    }

    public function testTheCacheIdOwnerLoadsAndComposesWithoutTheSdk()
    {
        /*
         * GLM8 #11: the settings invalidation and uninstall.php consult
         * the endpoint layer WITHOUT the SDK plugin available — the
         * owner (base + children + ZaiDiscoveryCache) must load and
         * compose in a subprocess that never includes vendor/ (the
         * test bootstrap always loads it, so this can only be proven
         * out-of-process, the missing-SDK scaffold-test pattern).
         */
        $script = <<<'PHP'
define('HOUR_IN_SECONDS', 3600);
require %s;
require %s;
require %s;
require %s;
require %s;
require %s;
require %s;
$ids = Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::discovery_transient_ids('coding', 'intl');
if ($ids !== array('zai_connector_zai_models_' . md5('zai|coding|intl'), 'zai_connector_zai_models_' . md5('zai|coding|intl') . '_miss')) {
    fwrite(STDERR, "unexpected ids: " . implode(',', $ids) . "\n");
    exit(1);
}
if (class_exists('WordPress\AiClient\AiClient')) {
    fwrite(STDERR, "SDK unexpectedly present\n");
    exit(1);
}
echo "SDK_FREE_OWNER_OK\n";
PHP;
        $src = dirname(__DIR__, 2) . '/connectors/zai/src';
        $script = sprintf(
            $script,
            var_export($src . '/Settings/AbstractPlanRegionSettings.php', true),
            var_export($src . '/Settings/PlanRegionSettings.php', true),
            var_export($src . '/Settings/ZaiAnthropicPlanRegionSettings.php', true),
            var_export($src . '/Endpoints/AbstractZaiEndpoint.php', true),
            var_export($src . '/Endpoints/ZaiEndpoint.php', true),
            var_export($src . '/Endpoints/ZaiAnthropicEndpoint.php', true),
            var_export($src . '/Metadata/ZaiDiscoveryCache.php', true)
        );

        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1';
        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        $this->assertSame(0, $exitCode, "The owner must compose without the SDK: {$output}");
        $this->assertStringContainsString('SDK_FREE_OWNER_OK', $output);
    }

    public function testEveryConsumerComposesThroughTheEndpointLayerOwner()
    {
        /*
         * GLM8 #11 (source pin): the five former mirrors must carry no
         * private composition — no hand-rolled md5 over the cache key,
         * no literal '_miss' suffix, and (where an endpoint class is
         * available) an actual call into discovery_transient_ids() /
         * discovery_cache_id().
         */
        $consumers = array(
            'settings invalidation' => __DIR__ . '/../../connectors/zai/src/Settings/AbstractPlanRegionSettings.php',
            'openai directory' => __DIR__ . '/../../connectors/zai/src/Metadata/ZaiModelMetadataDirectory.php',
            'anthropic directory' => __DIR__ . '/../../connectors/zai/src/Metadata/ZaiAnthropicModelMetadataDirectory.php',
            'uninstall' => __DIR__ . '/../../connectors/zai/uninstall.php',
            'live probe' => __DIR__ . '/../../bin/zai-live-probe.php',
        );

        foreach ($consumers as $label => $path) {
            $source = (string) file_get_contents($path);

            $this->assertSame(
                0,
                preg_match("/\.\s*'_miss'|'_miss'\s*\./", $source),
                "[{$label}] The negative-cache suffix must never be concatenated in code."
            );
            $this->assertSame(
                0,
                preg_match('/md5\(\s*\$endpoint->cache_key\(\)\s*\)/', $source),
                "[{$label}] No private md5-over-cache-key composition."
            );
            $this->assertSame(
                1,
                preg_match('/discovery_transient_ids\(|discovery_cache_id\(/', $source),
                "[{$label}] The consumer must call the endpoint layer's owner."
            );
        }

        /*
         * glm15-12: the test harness's two priming helpers ride the
         * owner too — the hand-composed CACHE_PREFIX . md5(cache_key())
         * mirror was the last private composition; a composition change
         * would have silently stranded ~31 priming call sites against
         * ids the directories no longer read. Both methods call the
         * owner; no private md5-over-cache-key composition remains.
         */
        $harness = (string) file_get_contents(__DIR__ . '/../harness/WpConnectorsTestCase.php');

        $this->assertSame(
            2,
            preg_match_all('/discovery_cache_id\(\s*\$endpoint->plan\(\),\s*\$endpoint->region\(\)\s*\)/', $harness),
            'Both priming helpers compose the transient id through the endpoint layer owner.'
        );
        $this->assertSame(
            0,
            preg_match('/md5\(\s*[^)]*cache_key\(\)\s*\)/', $harness),
            'No private md5-over-cache-key composition may ride the harness.'
        );

        /*
         * Verifier round on GLM8 #11: the no-literal rule is swept over
         * the WHOLE plugin and probe surface (not just the five
         * historical mirrors), so a new consumer file cannot reintroduce
         * a private '_miss' composition — ZaiDiscoveryCache (the
         * constant's own definition) is the only exempt file.
         */
        $sweep = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/connectors/zai/src'));
        $swept = array();
        foreach ($sweep as $file_info) {
            if ($file_info->isFile() && 'php' === $file_info->getExtension()) {
                $swept[] = $file_info->getPathname();
            }
        }
        $swept[] = dirname(__DIR__, 2) . '/bin/zai-live-probe.php';
        $swept[] = dirname(__DIR__, 2) . '/connectors/zai/uninstall.php';

        foreach ($swept as $path) {
            if (substr($path, -strlen('ZaiDiscoveryCache.php')) === 'ZaiDiscoveryCache.php') {
                continue;
            }

            $source = (string) file_get_contents($path);
            $this->assertSame(
                0,
                preg_match("/\.\s*'_miss'|'_miss'\s*\./", $source),
                "{$path} must not compose the negative-cache suffix literally."
            );
        }
    }

    public function testBothEndpointSurfacesDeclareTheSharedBaseIdentifiers()
    {
        /*
         * GLM8 #10 (reflection pin, the GLM6 #12 pattern): the shared
         * AbstractZaiEndpoint reads the child-owned identifier constants
         * through static:: — a child that forgets one fails loudly at
         * its first use, and this pin names the contract. The base
         * itself must carry no surface's defaults.
         */
        $identifiers = array(
            'MATRIX',
            'MODELS_ROUTE',
            'ENDPOINT_SUFFIXES',
            'CACHE_SCOPE',
            'SETTINGS_CLASS',
            'UNKNOWN_ENDPOINT_LABEL',
        );

        $base = array();
        foreach ((new \ReflectionClass(AbstractZaiEndpoint::class))->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === AbstractZaiEndpoint::class) {
                $base[] = $constant->getName();
            }
        }

        $this->assertSame(array(), array_values(array_intersect($identifiers, $base)), 'The endpoint base must not carry surface identifiers.');

        foreach (array(ZaiEndpoint::class, ZaiAnthropicEndpoint::class) as $endpoint_class) {
            $declared = array();
            foreach ((new \ReflectionClass($endpoint_class))->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $endpoint_class) {
                    $declared[] = $constant->getName();
                }
            }

            $this->assertSame(array(), array_values(array_diff($identifiers, $declared)), "{$endpoint_class} must declare every identifier constant.");
            $this->assertTrue(is_subclass_of($endpoint_class, AbstractZaiEndpoint::class), "{$endpoint_class} rides the shared base.");

            // The cache scope each endpoint builds keys from is the
            // settings layer's own string — aliased, never re-declared.
            $settings = constant($endpoint_class . '::SETTINGS_CLASS');
            $this->assertSame(constant($settings . '::CACHE_SCOPE'), constant($endpoint_class . '::CACHE_SCOPE'), "{$endpoint_class} aliases its settings layer's cache scope.");
        }
    }
}
