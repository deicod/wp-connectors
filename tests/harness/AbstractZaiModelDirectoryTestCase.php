<?php
/**
 * The surface-constant discovery/cache rules, shared by both zai
 * surfaces' model-directory suites (glm35-9).
 *
 * The glm22-6 drift class at the directory layer: these tests pin rules
 * that live on the SHARED discovery machinery (ZaiDiscoveryCache's
 * cache identity, plan/region retargeting, the fallback ladder), and
 * their bodies were byte-identical except for each surface's constants
 * — the settings class, the /models wire shape, and the endpoint URLs.
 * A rule change had to be re-encoded in both hand-written suites, and a
 * one-suite edit left the other surface pinning the superseded contract
 * while both stayed green. One copy executes per surface now: each
 * concrete suite supplies its constants through the hooks and inherits
 * the tests.
 *
 * Deliberately NOT consolidated here: the twins whose bodies embed
 * PER-SURFACE decision history (the verdict-recording, memo, and
 * parse-shape tests narrate GLM7 #12 vs GLM9 #5, glm18-4's status-only
 * recording divergence, and their own port orders) — merging those
 * comments would orphan the ledger's citations (the glm30
 * comment-density refutation), and their assertion bodies genuinely
 * differ. Those stay hand-mirrored; see the round-35 ledger entry.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;

abstract class AbstractZaiModelDirectoryTestCase extends WpConnectorsTestCase
{
    /**
     * The surface's settings class (owns the plan/region options).
     *
     * @return string
     */
    abstract protected function settings_class();

    /**
     * The surface's canonical /models success body for a discovered id list.
     *
     * @param list<string> $ids Discovered model ids.
     * @return string Body bytes.
     */
    abstract protected function models_body( array $ids );

    /**
     * The surface's canonical error body.
     *
     * @param string $message Fixture error message.
     * @param string $type Fixture error type.
     * @return string Body bytes.
     */
    abstract protected function error_body( $message, $type );

    /**
     * The surface's /models URL for one plan|region pair — a LITERAL map,
     * never derived: the URLs are the pin (the endpoint classes' own
     * construction is what these assertions hold them to).
     *
     * @param string $plan Plan slug.
     * @param string $region Region slug.
     * @return string URL.
     */
    abstract protected function models_url( $plan, $region );

    /**
     * The surface's wired directory instance.
     *
     * @param string|null $key Optional fixture key.
     * @return object The wired directory.
     */
    abstract protected function directory( ?string $key = null );

    public function testPlanSwitchBeforeExpiryRefetchesTheOtherEndpoint()
    {
        $this->selectEndpoint($this->settings_class(), 'coding', 'intl');
        $this->queueSdkResponse(200, array(), $this->models_body(array('glm-5.3')));

        $this->directory()->listModelMetadata(); // Warms the coding|intl cache.
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Switch plan well inside the TTL: the general endpoint must be
        // re-fetched, never served the coding cache.
        $this->selectEndpoint($this->settings_class(), 'general', 'intl');
        $this->queueSdkResponse(200, array(), $this->models_body(array('glm-5.3', 'glm-4.5')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $this->idList($models));
        $this->assertCount(2, $this->sdkHttpAttempts());
        $this->assertSame($this->models_url('general', 'intl'), $this->sdkHttpAttempts()[1]['url']);
    }

    public function testRegionSwitchBeforeExpiryRefetchesTheOtherEndpoint()
    {
        $this->selectEndpoint($this->settings_class(), 'coding', 'intl');
        $this->queueSdkResponse(200, array(), $this->models_body(array('glm-5.3')));
        $this->directory()->listModelMetadata();

        $this->selectEndpoint($this->settings_class(), 'coding', 'cn');
        $this->queueSdkResponse(200, array(), $this->models_body(array('glm-5.3', 'glm-5.2')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $this->idList($models));
        $this->assertSame($this->models_url('coding', 'cn'), $this->sdkHttpAttempts()[1]['url']);
    }

    public function testUnauthorizedDiscoveryFallsBackToThePlanCatalog()
    {
        $this->selectEndpoint($this->settings_class(), 'coding', 'intl');
        $this->queueSdkResponse(401, array(), $this->error_body('token expired or incorrect', 'authentication_error'));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(\Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog::CODING_MODELS, $this->idList($models));
        $this->assertSame($this->models_url('coding', 'intl'), $this->sdkHttpAttempts()[0]['url']);
    }

    public function testGeneralPlanFallbackContainsTheFullCatalog()
    {
        $this->selectEndpoint($this->settings_class(), 'general', 'cn');

        // cn is unprobed; any discovery failure falls back per plan.
        $this->queueSdkResponse(404, array(), $this->error_body('not found', 'not_found_error'));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(\Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog::GENERAL_MODELS, $this->idList($models));
    }

    public function testTheSdkCacheNeutralizationRidesTheSharedTrait()
    {
        /*
         * glm37-6: the neutralization trio (getBaseCacheKey/hasCache/
         * setCache) was a near-verbatim twin on the two directories with
         * behavioral pins on the zai side only — glm36-6 verified the
         * anthropic port empirically and pinned nothing, so a one-surface
         * neutralization change (say, a hasCache() that starts serving
         * SDK-layer entries, defeating the 12h transient TTL and
         * surviving plan/region invalidation) drifted silently. The trio
         * composes Support\NeutralizesSdkModelCache now, parameterized by
         * the per-surface endpoint hook; this pin runs once per concrete
         * suite and holds both the composition and the registry pairing
         * of the hook.
         */
        $class = get_class($this->directory());

        $this->assertContains(
            \Deicod\WpConnectors\Zai\Support\NeutralizesSdkModelCache::class,
            class_uses($class, true),
            'The SDK cache neutralization composes the shared trait (glm37-6).'
        );

        $expected_endpoint = null;
        foreach (\Deicod\WpConnectors\Zai\Support\ZaiSurfaces::SURFACES as $row) {
            if ($row['settings'] === $this->settings_class()) {
                $expected_endpoint = $row['endpoint'];
            }
        }

        $hook = new \ReflectionMethod($class, 'discovery_endpoint_class');
        if (PHP_VERSION_ID < 80100) {
            // Required on PHP <= 8.0; a silent no-op since 8.1 (deprecated
            // only since 8.5) — openPrivateProperty()'s stated guard.
            $hook->setAccessible(true);
        }

        $this->assertSame(
            $expected_endpoint,
            $hook->invoke(null),
            'The base-key endpoint hook pairs with the registry row\'s endpoint class (glm37-6).'
        );
    }

    public function testSdkCacheKeyIsEndpointScoped()
    {
        // glm37-6: moved from ZaiModelDirectoryTest (the zai-only pin of a
        // BOTH-surface rule) — one copy executes per surface now.
        //
        // Direct proof that the SDK-level cache key (including any PSR-16
        // persistent cache configured via AiClient::setCache()) differs per
        // plan and per region.
        $directory = $this->directory();
        $base_key = \Closure::bind(
            function () {
                return $this->getBaseCacheKey();
            },
            $directory,
            get_class($directory)
        );

        $this->selectEndpoint($this->settings_class(), 'coding', 'intl');
        $coding_intl = $base_key();
        $this->selectEndpoint($this->settings_class(), 'general', 'intl');
        $general_intl = $base_key();
        $this->selectEndpoint($this->settings_class(), 'coding', 'cn');
        $coding_cn = $base_key();

        $this->assertNotSame($coding_intl, $general_intl);
        $this->assertNotSame($coding_intl, $coding_cn);
        $this->assertNotSame($general_intl, $coding_cn);
    }

    public function testConfiguredPsr16CacheNeverServesOrStoresDiscovery()
    {
        // glm37-6: moved from ZaiModelDirectoryTest (the zai-only pin of a
        // BOTH-surface rule) — one copy executes per surface now.
        //
        // End-to-end against a REAL configured PSR-16 cache (the SDK's
        // outermost cache layer when core wires one via AiClient::setCache()):
        // a poisoned pre-existing entry must never be served, and successful
        // discoveries must never be written — the plugin transient is the
        // sole discovery cache (review finding).
        $cache = new \SimpleArrayCache();
        AiClient::setCache($cache);

        try {
            $this->freezeTime(1700000000);
            $this->selectEndpoint($this->settings_class(), 'coding', 'intl');

            $directory = $this->directory();
            $base_key = \Closure::bind(
                function () {
                    return $this->getBaseCacheKey();
                },
                $directory,
                get_class($directory)
            );
            $cache->set(
                $base_key() . '_models',
                array('poisoned-model' => \Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog::metadata_for('poisoned-model'))
            );

            $this->queueSdkResponse(200, array(), $this->models_body(array('glm-5.3')));
            $models = $directory->listModelMetadata();

            $this->assertSame(array('glm-5.3'), $this->idList($models), 'A warmed PSR-16 entry must never be served.');
            $this->assertCount(1, $this->sdkHttpAttempts());

            // Expiry still governs: past the transient TTL the same instance
            // re-discovers even though a PSR-16 cache is configured.
            $this->advanceTime(\Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache::DISCOVERY_TTL + 1);
            $this->queueSdkResponse(200, array(), $this->models_body(array('glm-5.3', 'glm-5.2')));
            $models = $directory->listModelMetadata();

            $this->assertCount(2, $this->idList($models));
            $this->assertCount(2, $this->sdkHttpAttempts());

            $this->assertSame(
                array( $base_key() . '_models' ),
                array_keys($cache->entries),
                'No discovery value may be written to the PSR-16 cache (only the poisoned test entry may remain).'
            );
        } finally {
            AiClient::setCache(null);
        }
    }
}
