<?php
/**
 * Task 1.5 — model metadata directory tests.
 *
 * Covers sorting, discovery, cache expiry, cache scoping across plan/region
 * switches, fallback contents for both plans, malformed/401/404 discovery
 * responses, and capability/option declarations.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelListParser;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

final class ZaiModelDirectoryTest extends WpConnectorsTestCase
{
    /**
     * The full 10-model list observed live (record 0006).
     *
     * @var list<string>
     */
    private const OBSERVED_MODELS = array(
        'glm-4.5', 'glm-4.5-air', 'glm-4.6', 'glm-4.7', 'glm-5',
        'glm-5-turbo', 'glm-5.1', 'glm-5.2', 'glm-5.3', 'glm-5.3-flash',
    );

    /**
     * Fresh directory wired to the harness transporter with a fixture key.
     *
     * @return ZaiModelMetadataDirectory
     */
    private function directory(): ZaiModelMetadataDirectory
    {
        $directory = new ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        return $directory;
    }

    /*
     * glm15-19: selectEndpoint()/idList() ride the shared harness — the
     * private twins differed only in their settings class (and their
     * docblocks had already drifted from their assertions).
     */

    /*
     * Static catalog data.
     */

    public function testFallbackCatalogsArePlanPartitioned()
    {
        $coding = ZaiModelCatalog::ids_for_plan('coding');
        $general = ZaiModelCatalog::ids_for_plan('general');

        // Coding exposes only the GLM 5.x family (SPEC 3.3 restricted set).
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $coding);
        foreach ($coding as $modelId) {
            $this->assertStringStartsWith('glm-5', $modelId);
        }

        // General exposes the full observed list.
        $this->assertCount(10, $general);
        $this->assertSame(array('glm-5.3', 'glm-5.3-flash'), array_slice($general, 0, 2));
        $this->assertContains('glm-4.5', $general);
        $this->assertNotSame($coding, $general);
    }

    /*
     * Sorting.
     */

    public function testDiscoveredModelsSortNewestFirst()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array_reverse(self::OBSERVED_MODELS)));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(
            array('glm-5.3', 'glm-5.3-flash', 'glm-5.2', 'glm-5.1', 'glm-5', 'glm-5-turbo', 'glm-4.7', 'glm-4.6', 'glm-4.5', 'glm-4.5-air'),
            array_map(static function (ModelMetadata $m) {
                return $m->getId();
            }, $models)
        );
    }

    public function testSortComparatorHandlesVersionsAndVariants()
    {
        $make = static function (string $id): ModelMetadata {
            return ZaiModelCatalog::metadata_for($id);
        };

        $this->assertSame(-1, ZaiModelCatalog::sort_callback($make('glm-5.3'), $make('glm-5.2')));
        $this->assertSame(1, ZaiModelCatalog::sort_callback($make('glm-4.7'), $make('glm-5')));
        // Numeric, not lexicographic: 5.10 > 5.9.
        $this->assertSame(-1, ZaiModelCatalog::sort_callback($make('glm-5.10'), $make('glm-5.9')));
        // Base model before variant at the same version.
        $this->assertSame(-1, ZaiModelCatalog::sort_callback($make('glm-5.3'), $make('glm-5.3-flash')));
        $this->assertSame(1, ZaiModelCatalog::sort_callback($make('glm-5.3-flash'), $make('glm-5.3')));
        // Non-GLM after GLM.
        $this->assertSame(1, ZaiModelCatalog::sort_callback($make('other-model'), $make('glm-4.5')));
    }

    /*
     * Discovery.
     */

    /**
     * Fresh directory wired to the harness transporter with an EXACT key.
     *
     * For credential-state tests that bind flags/verdicts to a specific key
     * (FakeSecrets::apiKey() is random per call, so the directory must carry
     * the captured value).
     *
     * @param string $key The exact API key the directory authenticates with.
     * @return ZaiModelMetadataDirectory
     */
    private function directoryWithKey(string $key): ZaiModelMetadataDirectory
    {
        $directory = new ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        return $directory;
    }

    public function testDiscoveryHitsTheSelectedEndpointAndCaches()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(self::OBSERVED_MODELS));

        $first = $this->directory();
        $ids = array_map(static function (ModelMetadata $m) {
            return $m->getId();
        }, $first->listModelMetadata());

        /*
         * GLM1 #11 drift closure: discovery on the coding plan is
         * intersected with the plan catalog (previously zai-surface-only),
         * so the full 10-model observed list keeps exactly the coding
         * plan's GLM 5.x set, sorted newest-first.
         */
        $this->assertSame(array('glm-5.3', 'glm-5.3-flash', 'glm-5.2', 'glm-5.1', 'glm-5', 'glm-5-turbo'), $ids);
        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $attempts[0]['url']);

        // A FRESH instance (new request) serves the cached discovery without
        // another HTTP attempt.
        $second = $this->directory();
        $cachedIds = array_map(static function (ModelMetadata $m) {
            return $m->getId();
        }, $second->listModelMetadata());
        $this->assertSame($ids, $cachedIds);
        $this->assertCount(1, $this->sdkHttpAttempts());
    }

    public function testDiscoveryIsSkippedWhileTheCredentialIsRegionPending()
    {
        /*
         * Code-review GLM1 #1: the R19/R20 credential-refusal gate was
         * consulted only by the zai_anthropic surface — this (OpenAI)
         * surface's discovery still authenticated a region-pending env/
         * constant key against the newly selected endpoint. The SAME
         * availability gate must refuse enumeration here, degrading to the
         * static plan fallback WITHOUT any authenticated request.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(PlanRegionSettings::REGION_PENDING_OPTION, array(
            'region' => 'intl',
            'fingerprint' => hash('sha256', $key),
        ));

        $models = $this->directoryWithKey($key)->listModelMetadata();

        $this->assertSame(
            ZaiModelCatalog::ids_for_plan('coding'),
            $this->idList($models),
            'Discovery must degrade to the plan fallback while the credential is region-pending.'
        );
        $this->assertNoHttpRequests();
    }

    public function testDiscoveryIsSkippedWhileAMatchingInvalidVerdictExists()
    {
        // Code-review GLM1 #1: a definitive invalid verdict for the exact
        // key+endpoint binding must refuse enumeration on this surface too.
        // (GLM5 #11: the runtime save-time source normalizes to the
        // 'database' identity at binding construction.)
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        $binding = hash('sha256', 'database|' . \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for_current_settings()->cache_key() . '|' . $key);
        update_option(PlanRegionSettings::STATE_OPTION, array(
            'binding' => $binding,
            'valid' => 'invalid',
            'checked_at' => time(),
            'clock' => 'utc',
        ));

        $models = $this->directoryWithKey($key)->listModelMetadata();

        $this->assertSame(
            ZaiModelCatalog::ids_for_plan('coding'),
            $this->idList($models),
            'Discovery must degrade to the plan fallback while the credential carries an invalid verdict.'
        );
        $this->assertNoHttpRequests();
    }

    public function testAPaginatedDiscoveryPageFallsBackAndIsNotCached()
    {
        /*
         * Code-review GLM1 #11 (drift closure): the R15 has_more rejection
         * existed only on the zai_anthropic side's parser — the shared
         * parsing now applies it to BOTH surfaces: an incomplete page is
         * not a catalog, so it must fall back and never reach the
         * positive cache.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"object":"list","data":[{"id":"glm-5.3"}],"has_more":true,"first_id":"glm-5.3","last_id":"glm-5.3"}');

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()), 'A paginated page must fall back to the plan catalog.');
        $this->assertFalse(get_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl')), 'A paginated page must not be positively cached.');
    }

    public function testCodingPlanDiscoveryIsIntersectedWithThePlanCatalog()
    {
        /*
         * Code-review GLM1 #11 (drift closure): the R3 #4 plan
         * intersection existed only on the zai_anthropic side — the OpenAI
         * surface advertised general-only models on the coding plan. The
         * shared parsing intersects BOTH surfaces with the ACTIVE plan's
         * catalog before anything is cached.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-4.7', 'glm-4.5')));

        $ids = $this->idList($this->directory()->listModelMetadata());

        $this->assertSame(array('glm-5.3'), $ids, 'General-only GLM 4.x entries must not be advertised on the coding plan.');
        $this->assertSame(array('glm-5.3'), array_values((array) get_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl'))), 'The cache must hold the intersected list.');
    }

    public function testAConcurrentPlanSaveDuringTheRoundTripDoesNotReTargetTheParse()
    {
        /*
         * GLM3 #10: parseResponseToModelMetadataList() re-resolved the
         * CURRENT settings at parse time, so a plan save landing during
         * the HTTP round-trip filtered the coding endpoint's response
         * with the GENERAL catalog and cached the wrong (general-only)
         * list under the coding endpoint's key for the 12h TTL. The
         * endpoint is now captured at request time — the Anthropic
         * twin's semantics for this surface's SDK-mediated flow. The
         * plan save is injected mid-round-trip by a wrapping transporter.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');

        $inner = AiClient::defaultRegistry()->getHttpTransporter();
        $directory = new ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(new class($inner) implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
            private $inner;

            public function __construct($inner)
            {
                $this->inner = $inner;
            }

            public function send(\WordPress\AiClient\Providers\Http\DTO\Request $request, ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null): \WordPress\AiClient\Providers\Http\DTO\Response
            {
                // The concurrent save lands after the request was built
                // against the coding endpoint, before the response parses.
                update_option(PlanRegionSettings::OPTION_PLAN, 'general');

                return $this->inner->send($request, $options);
            }
        });
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-4.7', 'glm-4.5')));

        $ids = $this->idList($directory->listModelMetadata());

        $this->assertSame(array('glm-5.3'), $ids, 'The coding endpoint must keep its plan intersection despite the mid-flight plan save.');
        $this->assertSame(
            array('glm-5.3'),
            array_values((array) get_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl'))),
            'The coding endpoint cache must hold the coding-intersected list.'
        );
    }

    public function testDiscoveryCacheExpires()
    {
        $this->freezeTime(1700000000);
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        // ONE directory instance across the expiry — the production shape
        // (the provider's per-process singleton). The SDK's own cache layer
        // (in-memory local or PSR-16, 24h TTL) must not be able to serve the
        // discovery past the plugin's 12h transient TTL (review finding: it
        // previously did, so the advertised TTL never triggered a refresh).
        $directory = $this->directory();
        $directory->listModelMetadata();
        $this->assertCount(1, $this->sdkHttpAttempts());

        $this->advanceTime(ZaiDiscoveryCache::DISCOVERY_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
        $models = $directory->listModelMetadata();
        $this->assertCount(2, $models);
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testTransientInvalidationBypassesTheSdkCacheLayerToo()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        // Warm both layers the way a successful request does.
        $directory = $this->directory();
        $this->assertCount(1, $this->idList($directory->listModelMetadata()));

        // A settings change / uninstall deletes the plugin transient...
        delete_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl'));

        // ...the very next listing on the SAME instance must re-discover,
        // never serve a warmed SDK/local/PSR-16 entry.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
        $models = $directory->listModelMetadata();

        $this->assertCount(2, $this->idList($models));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testConfiguredPsr16CacheNeverServesOrStoresDiscovery()
    {
        // End-to-end against a REAL configured PSR-16 cache (the SDK's
        // outermost cache layer when core wires one via AiClient::setCache()):
        // a poisoned pre-existing entry must never be served, and successful
        // discoveries must never be written — the plugin transient is the
        // sole discovery cache (review finding).
        $cache = new SimpleArrayCache();
        AiClient::setCache($cache);

        try {
            $this->freezeTime(1700000000);
            $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');

            $directory = $this->directory();
            $base_key = Closure::bind(
                function () {
                    return $this->getBaseCacheKey();
                },
                $directory,
                ZaiModelMetadataDirectory::class
            );
            $cache->set($base_key() . '_models', array('poisoned-model' => ZaiModelCatalog::metadata_for('poisoned-model')));

            $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
            $models = $directory->listModelMetadata();

            $this->assertSame(array('glm-5.3'), $this->idList($models), 'A warmed PSR-16 entry must never be served.');
            $this->assertCount(1, $this->sdkHttpAttempts());

            // Expiry still governs: past the transient TTL the same instance
            // re-discovers even though a PSR-16 cache is configured.
            $this->advanceTime(ZaiDiscoveryCache::DISCOVERY_TTL + 1);
            $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
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

    /*
     * Cache scoping across plan/region switches (before expiry).
     */

    public function testPlanSwitchBeforeExpiryRefetchesTheOtherEndpoint()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        $this->directory()->listModelMetadata(); // Warms the coding|intl cache.
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Switch plan well inside the TTL: the general endpoint must be
        // re-fetched, never served the coding cache.
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-4.5')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $models);
        $this->assertCount(2, $this->sdkHttpAttempts());
        $this->assertSame('https://api.z.ai/api/paas/v4/models', $this->sdkHttpAttempts()[1]['url']);
    }

    public function testRegionSwitchBeforeExpiryRefetchesTheOtherEndpoint()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->directory()->listModelMetadata();

        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'cn');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $models);
        $this->assertSame('https://open.bigmodel.cn/api/coding/paas/v4/models', $this->sdkHttpAttempts()[1]['url']);
    }

    /*
     * Fallback behavior.
     */

    public function testRepeatedLookupsOnOneInstanceReuseTheBuiltMap()
    {
        /*
         * GLM8 #9: the twin directory's GLM7 #13 memo, which this
         * surface never got — hasCache() is hard-wired false, so every
         * list/has/get call (core resolution makes two or more per AI
         * request) re-ran the full map_from_ids() rebuild of constant
         * data. The rebuild is memoized per transient CONTENT: repeated
         * lookups return the SAME metadata instances, and a
         * transient-content change (the read stays authoritative) swaps
         * the memo key and rebuilds.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->primeZaiDiscoveryTransient(array('glm-5.3', 'glm-5.2'));

        $directory = $this->directory();

        $first = $directory->getModelMetadata('glm-5.3');
        $this->assertSame($first, $directory->getModelMetadata('glm-5.3'), 'A repeated get must reuse the built metadata object.');
        $this->assertSame($first, $directory->listModelMetadata()[0], 'listModelMetadata() must reuse the built map.');
        $this->assertTrue($directory->hasModelMetadata('glm-5.2'), 'The memoized map answers has-lookups.');

        // A transient-content change rebuilds: the memo follows the IDs,
        // not the instance.
        $this->primeZaiDiscoveryTransient(array('glm-5.2'));
        $rebuilt = $directory->getModelMetadata('glm-5.2');
        $this->assertNotSame($first, $rebuilt, 'New content must rebuild the map.');
        $this->assertFalse($directory->hasModelMetadata('glm-5.3'), 'The rebuilt map reflects the new ID list.');
    }

    public function testColdDiscoveryHandsItsBuiltMetadataToTheMapMemo()
    {
        /*
         * glm15-22 (source pin — the one-build efficiency contract): the
         * vendor parent's discovery flow FORCES a full metadata build in
         * parseResponseToModelMetadataList() (metadata construction plus
         * sort per discovered ID), which this directory used to reduce
         * to array_keys() while ZaiDiscoveryCache::map_from_ids()
         * rebuilt the identical metadata for the same IDs — two full
         * builds per cold-window discovery at sites that had already
         * diverged (the rebuild re-applies the chat filter, the parse
         * build did not). The parse stashes its built list and the
         * cold-path memo call seeds from it through the SAME
         * chat-filter rule; the newest-first and caching pins above
         * hold the served map byte-identically.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/connectors/zai/src/Metadata/ZaiModelMetadataDirectory.php');

        $this->assertStringContainsString(
            '$this->discovery_built_metadata = $models;',
            $source,
            'The parse-side build is stashed for the cold-path memo seed.'
        );
        $this->assertStringContainsString(
            'memoized_map( $cache_id, $ids, $this->take_discovery_built_map( $ids ) )',
            $source,
            'The cold path seeds the map memo with the parse-built metadata.'
        );
        $this->assertStringContainsString(
            'ZaiModelCatalog::is_chat_model( $metadata->getId() )',
            $source,
            'The seed applies the one chat-filter build rule the cache rebuild applies.'
        );
    }

    public function testBothDirectoriesMemoizeThroughTheOneSharedOwner()
    {
        /*
         * GLM9 #10: the two directories' per-content map memos were
         * verbatim twins (GLM7 #13 and GLM8 #9 — the fields plus the
         * cache-id-and-digest key block, copy-pasted), so a memo-rule
         * change could land on one surface only. One
         * ZaiDiscoveryCache::memoized_map() owns the state and the key
         * formula now: the same resolved IDs through either surface's
         * cache id produce the identical map, content changes rebuild
         * through the digest rule, and distinct cache ids memoize
         * independently.
         */
        $ids = array('glm-5.3', 'glm-5.2');

        $zai_map = ZaiDiscoveryCache::memoized_map('test_cache_zai', $ids);
        $this->assertSame(array('glm-5.3', 'glm-5.2'), array_keys($zai_map), 'The shared owner builds the chat-filtered newest-first map.');

        // Same content through the other surface's cache id: the pure
        // map, independently memoized.
        $this->assertSame(array_keys($zai_map), array_keys(ZaiDiscoveryCache::memoized_map('test_cache_anthropic', $ids)));

        // A content change rebuilds through the digest rule...
        $this->assertSame(array('glm-5.2'), array_keys(ZaiDiscoveryCache::memoized_map('test_cache_zai', array('glm-5.2'))));
        // ...and the original content still memoizes per cache id.
        $this->assertSame(array('glm-5.3', 'glm-5.2'), array_keys(ZaiDiscoveryCache::memoized_map('test_cache_zai', $ids)));
    }

    public function testTheMapMemoDigestsOnlyTheStringViewOfTheIdList()
    {
        /*
         * glm18-9: the memo digest md5()-imploded the RAW id list, so a
         * transient row carrying a non-string entry (a foreign or
         * corrupt write; no in-repo writer produces one) raised an
         * Array-to-string warning on every directory lookup for the
         * transient's 12h TTL — and an ErrorException out of this
         * documented never-throw path on hosts whose error handler
         * throws. The digest now rides the same string-only view
         * map_from_ids() keeps; the corrupt entry cannot change the
         * built map (it is dropped from it too), so the memo stays
         * faithful. failOnWarning makes the unguarded call fail here.
         */
        $map = ZaiDiscoveryCache::memoized_map('test_cache_corrupt', array(array('x'), 'glm-5.3', null, ''));

        $this->assertSame(array('glm-5.3'), array_keys($map), 'The corrupt entries drop exactly as map_from_ids() drops them.');
    }

    public function testUnauthorizedDiscoveryFallsBackToThePlanCatalog()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token expired or incorrect'));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);
    }

    /**
     * @dataProvider provideCredentialRejectionStatuses
     */
    public function testCredentialRejectingDiscoveryPersistsTheInvalidVerdict($status, $label)
    {
        /*
         * GLM9 #5: the zai twin of the Anthropic directory's GLM7 #12
         * contract. The models route ANSWERED and rejected the
         * credential itself — the same definitive evidence the
         * availability probe persists an invalid verdict for — but this
         * surface delegates to the SDK parent's sendListModelsRequest()
         * and never overrode its overridable throwIfNotSuccessful()
         * hook, so the rejection landed in the shared cache's catch as
         * a plain failure (silent 60s '_miss' marker plus plan
         * fallback) with no persisted verdict: a key revoked server
         * side kept passing isConfigured() on zai for up to the 300s
         * STATE_TTL with raw 401s instead of the typed refusal. The
         * verdict is recorded through the probe's own persist path now;
         * a subsequent availability consult answers from state with NO
         * new request.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, $key);
        $this->queueSdkResponse($status, array(), HttpResponseFactory::openAiErrorBody('token expired or incorrect'));

        // Wired with the SAME key the option stores, so the recorded
        // verdict's binding (source 'database') matches the one the
        // later isConfigured() consult computes.
        $directory = new ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication($key));
        $models = $directory->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), "{$label}: the fallback still serves.");
        $this->assertCount(1, $this->sdkHttpAttempts(), "{$label}: exactly one discovery attempt.");

        $state = get_option(Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state, "{$label}: the invalid verdict must be persisted.");
        $this->assertSame('invalid', $state['valid']);

        $availability = new Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability();
        $this->assertFalse($availability->isConfigured(), "{$label}: a definitively rejected key reports not-connected.");
        $this->assertCount(1, $this->sdkHttpAttempts(), "{$label}: the fresh state answers without another request.");
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideCredentialRejectionStatuses()
    {
        return array(
            'unauthorized' => array(401, 'unauthorized'),
            'forbidden' => array(403, 'forbidden'),
        );
    }

    public function testDiscoveryToleratesAGatewayBomPrefix()
    {
        /*
         * GLM10 #3: a gateway/CDN prepending a UTF-8 BOM to the JSON body
         * made BOTH decodes fail (the SDK getData() and the parser's raw
         * oracle), so discovery silently degraded to the 60s '_miss'
         * marker plus static fallback on every request — the same threat
         * class GLM8-2/3 and GLM9-3 hardened on the SSE and
         * Messages/completions paths, missed on this route. The parser
         * owns one BOM-safe decode now.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(
            200,
            array(),
            "\xEF\xBB\xBF" . HttpResponseFactory::openAiModelsBody(self::OBSERVED_MODELS)
        );

        $ids = $this->idList($this->directory()->listModelMetadata());

        $this->assertSame(
            array('glm-5.3', 'glm-5.3-flash', 'glm-5.2', 'glm-5.1', 'glm-5', 'glm-5-turbo'),
            $ids,
            'A BOM-prefixed body still discovers (the coding-plan intersection applies as always).'
        );
        $this->assertCount(1, $this->sdkHttpAttempts(), 'One discovery attempt, no retry storm.');
    }

    public function testCredentialRejectingDiscoveryRecordsAgainstTheRequestTimeEndpoint()
    {
        /*
         * GLM10 #1, zai surface twin: throwIfNotSuccessful() runs inside
         * the GLM3 #10 capture window, so the 401/403 verdict must be
         * recorded against $this->discovery_endpoint — the endpoint the
         * rejecting request hit — not the endpoint the settings resolve
         * to by response time. An admin saving the region mid-flight
         * previously got the intl rejection persisted under the cn
         * binding.
         */
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, $key);

        AiClient::defaultRegistry()->setHttpTransporter(new HttpTransporter(new MidFlightOptionFlipClient(
            new SdkHttpClient(),
            PlanRegionSettings::OPTION_REGION,
            'cn'
        )));

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token expired or incorrect'));

        $directory = new ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication($key));
        $models = $directory->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'The fallback still serves.');
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url'], 'The discovery request itself hit intl.');

        $state = get_option(Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state, 'The invalid verdict must be persisted.');
        $this->assertSame(
            PlanRegionSettings::credential_binding(
                'database',
                Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for('coding', 'intl')->cache_key(),
                $key
            ),
            $state['binding'],
            'The verdict binds the request-time (intl) endpoint, not the response-time cn one.'
        );

        // The cn consult must NOT inherit the intl rejection: it probes cn
        // on its own (a 200 answers valid — connected). The flip client's
        // one-shot arming is spent, so the wired transporter just delegates.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(self::OBSERVED_MODELS));
        $availability = new Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability();
        $availability->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $this->assertTrue($availability->isConfigured(), 'The cn consult answers its own probe, not the intl rejection.');
        $this->assertCount(2, $this->sdkHttpAttempts(), 'The cn consult probed rather than answering from the intl-bound state.');
        $this->assertSame('https://open.bigmodel.cn/api/coding/paas/v4/models', $this->sdkHttpAttempts()[1]['url'], 'The follow-up probe hit the (now current) cn endpoint.');
    }

    public function testNonAuthDiscoveryFailurePersistsNoVerdict()
    {
        // GLM9 #5 guard: only the definitive credential rejections
        // persist — a 404 (the unprobed route shape) stays an
        // inconclusive failure whose verdict store stays untouched.
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, $key);
        $this->queueSdkResponse(404, array(), '{"error":{"message":"no route"}}');

        $directory = new ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication($key));
        $models = $directory->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
        $this->assertFalse(
            get_option(Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::STATE_OPTION, false),
            'A non-auth failure must not persist a verdict.'
        );
    }

    public function testGeneralPlanFallbackContainsTheFullCatalog()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'cn');

        // cn is unprobed; any discovery failure falls back per plan.
        $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::GENERAL_MODELS, $this->idList($models));
    }

    public function testMalformedDiscoveryResponseFallsBack()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');

        $bodies = array(
            'not json at all',
            '{"object":"list"}',
            '{"data":{"id":"not-a-list"}}',
            '{"data":{"only":{"id":"glm-5.3"}}}',
            '{"data":{}}',
            '{"data":[{"no_id":true}]}',
            '{"data":[]}',
        );

        foreach ($bodies as $body) {
            $this->sdkHttpAttempts(); // drain recording for readability
            WpHarness::$sdk_http_attempts = array();
            $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), $body);

            $models = $this->directory()->listModelMetadata();
            $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), "Body {$body} must fall back.");
        }
    }

    public function testFailedDiscoveryIsNegativelyCachedForAShortTtl()
    {
        /*
         * Code-review GLM1 #6: failed discovery was never negatively
         * cached, so every metadata lookup re-issued a blocking doomed
         * remote GET (the cn-region 404 shape pays this on every request).
         * A SHORT bounded negative cache (60s) collapses the repeat remote
         * calls while staying retryable: after the TTL the endpoint is
         * probed again — never fatal, and the fallback still serves
         * meanwhile.
         */
        $this->freezeTime(1700000000);
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Within the TTL the fallback serves again with NO remote attempt.
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(1, $this->sdkHttpAttempts(), 'The short negative cache must suppress the doomed repeat request.');

        // The positive cache stays unset: only the miss marker exists.
        $this->assertFalse(get_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl')));

        // After the TTL, discovery is retryable and a valid key wins.
        $this->advanceTime(ZaiDiscoveryCache::NEGATIVE_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
        $this->assertCount(2, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testFallbackIsNotCachedSoAValidKeyCanDiscoverLater()
    {
        $this->freezeTime(1700000000);
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()));

        // No transient may exist after a failure...
        $this->assertFalse(get_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl')));

        /*
         * ...so the next request attempts discovery again and succeeds
         * (GLM1 #6: the short negative cache only spans NEGATIVE_TTL
         * seconds — a later request past the TTL rediscovers).
         */
        $this->advanceTime(ZaiDiscoveryCache::NEGATIVE_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
        $this->assertCount(2, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testTransportFailureFallsBackWithoutFatal()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->allowUnmockedHttp = true;

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
    }

    /*
     * Capability and option declarations.
     */

    public function testDiscoveredModelsCarryCapabilitiesAndOptions()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        $metadata = $this->directory()->getModelMetadata('glm-5.3');

        $this->assertSame('glm-5.3', $metadata->getId());
        $this->assertSame('GLM 5.3', $metadata->getName());

        $capabilities = $metadata->getSupportedCapabilities();
        $this->assertCount(2, $capabilities);
        $this->assertTrue($capabilities[0]->isTextGeneration());
        $this->assertTrue($capabilities[1]->isChatHistory());

        $options = array();
        foreach ($metadata->getSupportedOptions() as $option) {
            $options[(string) $option->getName()] = $option;
        }

        foreach (array('systemInstruction', 'maxTokens', 'temperature', 'topP', 'stopSequences', 'outputMimeType', 'outputSchema', 'functionDeclarations', 'inputModalities') as $name) {
            $this->assertArrayHasKey($name, $options, "Option {$name} must be advertised.");
        }

        // JSON output is constrained to the two supported MIME types.
        $this->assertTrue($options['outputMimeType']->isSupportedValue('application/json'));
        $this->assertTrue($options['outputMimeType']->isSupportedValue('text/plain'));
        $this->assertFalse($options['outputMimeType']->isSupportedValue('image/png'));

        // Text input only: the DECLARED allowed values are exactly [[text]].
        // (Assert the declaration directly; the SDK's isSupportedValue()
        // cannot match nested AbstractEnum arrays by instance identity.)
        $declaredModalities = array();
        foreach ($options['inputModalities']->getSupportedValues() as $modalitySet) {
            $declaredModalities[] = array_map('strval', $modalitySet);
        }
        $this->assertSame(array(array('text')), $declaredModalities);
    }

    public function testNoModelClaimsImageSupport()
    {
        foreach (array('coding', 'general') as $plan) {
            foreach (ZaiModelCatalog::ids_for_plan($plan) as $modelId) {
                $metadata = ZaiModelCatalog::metadata_for($modelId);

                foreach ($metadata->getSupportedOptions() as $option) {
                    if ('outputModalities' === (string) $option->getName()) {
                        // outputModalities MUST be advertised (SDK model
                        // resolution requires it, incl. core's prompt path)
                        // — but text only: no image output is claimed.
                        foreach ($option->getSupportedValues() as $modalitySet) {
                            $this->assertSame(
                                array('text'),
                                array_map('strval', $modalitySet),
                                "{$modelId} must not claim image output without evidence."
                            );
                        }
                    }
                    if ('inputModalities' === (string) $option->getName()) {
                        foreach ($option->getSupportedValues() as $modalitySet) {
                            $this->assertSame(
                                array('text'),
                                array_map('strval', $modalitySet),
                                "{$modelId} must not claim image input without evidence."
                            );
                        }
                    }
                }
            }
        }
    }

    public function testUnknownFamilyShapedDiscoveredIdIsNotAdvertised()
    {
        // r5: family shape is not chat evidence. 'glm-6-image' matches the
        // GLM naming grammar, but nothing VERIFIED says it chats — the plan
        // forbids advertising capabilities from family names alone. Unknown
        // IDs from /models are excluded from advertised chat metadata.
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array(
            'glm-6-image',
            'glm-6-preview',
            'glm-5.3',
        )));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(array('glm-5.3'), $this->idList($models), 'Only verified chat IDs may be advertised.');
        $this->assertFalse($this->directory()->hasModelMetadata('glm-6-image'));

        // Direct catalog proof: the grammar matches, the verdict must not.
        $this->assertFalse(ZaiModelCatalog::is_chat_model('glm-6-image'));
        $this->assertFalse(ZaiModelCatalog::is_chat_model('glm-6'));
        $this->assertFalse(ZaiModelCatalog::is_chat_model('glm-6-preview'));
    }

    public function testKnownCatalogIdsAreAdvertisedFromDiscovery()
    {
        // Every fallback-catalog ID counts as verified chat support: mixed
        // into a discovery response (alongside non-chat noise), all of them
        // survive filtering, per plan and region data alike.
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array_merge(
            array('glm-6-image', 'cogview-4', 'embedding-3'),
            ZaiModelCatalog::GENERAL_MODELS
        )));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::GENERAL_MODELS, $this->idList($models));
        $this->assertTrue(ZaiModelCatalog::is_chat_model('glm-4.5-air'), 'Coding-family and general-family catalog IDs are all verified.');
        $this->assertTrue(ZaiModelCatalog::is_chat_model('glm-5.3-flash'));
    }

    public function testDiscoveryDropsModelIdsWithoutKnownChatSupport()
    {
        // A future /models list exposing non-chat models (embedding, image,
        // word-form IDs, unverified family-shaped releases) must not
        // advertise them: they would get full chat metadata and then fail
        // at /chat/completions (review finding).
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array(
            'embedding-3',
            'cogview-4',
            'glm-future-9',
            'glm-5.3',
            'glm-6',
            'glm-6-image',
        )));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(array('glm-5.3'), $this->idList($models), 'Only IDs with known chat support may be advertised.');

        // The persisted transient must already be the filtered list, so a
        // non-chat ID can never resurface from the cache either.
        $cached = get_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|general|intl'));
        $this->assertSame(array('glm-5.3'), array_values($cached));

        // hasModelMetadata() must reject the dropped IDs.
        $this->assertFalse($this->directory()->hasModelMetadata('embedding-3'));
        $this->assertFalse($this->directory()->hasModelMetadata('glm-6-image'));
    }

    public function testDiscoveryWithOnlyNonChatIdsFallsBackToThePlanCatalog()
    {
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('embedding-3', 'cogview-4', 'glm-6-image')));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'Nothing usable discovered: the plan fallback applies.');
        $this->assertFalse(get_transient(PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl')), 'The fallback must not be cached.');
    }

    /*
     * Registry-level integration: the provider's directory resolves the
     * endpoint at request time.
     */

    public function testProviderDirectoryRetargetsWithoutRegistryRebuild()
    {
        $this->loadPlugin(__DIR__ . '/../../connectors/zai/zai.php', '\Deicod\WpConnectors\Zai\boot');
        $this->runInit();

        $registry = AiClient::defaultRegistry();
        $this->assertTrue($registry->hasProvider('zai'));

        // Simulate core pushing the stored DB key into the SDK at init 20.
        $registry->setProviderRequestAuthentication(
            'zai',
            new ApiKeyRequestAuthentication(FakeSecrets::apiKey())
        );

        // The provider's directory instance is cached per-class for the whole
        // process — exactly like a long-running request or WP-CLI. NO manual
        // invalidation is performed: the SDK-level cache key must itself be
        // endpoint-scoped (review finding: it previously was not).
        $directory = $registry->getProviderClassName('zai')::modelMetadataDirectory();
        $directory->invalidateCaches(); // Reset cross-test process state only.

        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $models = $directory->listModelMetadata();
        $this->assertCount(1, $models);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);

        // Same provider, same directory instance, same registry: switch the
        // endpoint and list again — the next request must hit the NEW
        // endpoint, never the warm cache from the previous one.
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'cn');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-4.5')));
        $models = $directory->listModelMetadata();

        $this->assertCount(2, $models, 'The new endpoint catalog must be fetched, not the stale one.');
        $this->assertCount(2, $this->sdkHttpAttempts());
        $this->assertSame('https://open.bigmodel.cn/api/paas/v4/models', end(WpHarness::$sdk_http_attempts)['url']);
        $this->assertTrue($registry->hasProvider('zai'), 'Registry state must be untouched.');
    }

    public function testSdkCacheKeyIsEndpointScoped()
    {
        // Direct proof that the SDK-level cache key (including any PSR-16
        // persistent cache configured via AiClient::setCache()) differs per
        // plan and per region.
        $directory = new ZaiModelMetadataDirectory();
        $base_key = Closure::bind(
            function () {
                return $this->getBaseCacheKey();
            },
            $directory,
            ZaiModelMetadataDirectory::class
        );

        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');
        $coding_intl = $base_key();
        $this->selectEndpoint(PlanRegionSettings::class, 'general', 'intl');
        $general_intl = $base_key();
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'cn');
        $coding_cn = $base_key();

        $this->assertNotSame($coding_intl, $general_intl);
        $this->assertNotSame($coding_intl, $coding_cn);
        $this->assertNotSame($general_intl, $coding_cn);
    }

    public function testFallbackIsNotCachedAtTheSdkLayerEither()
    {
        $this->freezeTime(1700000000);
        $this->selectEndpoint(PlanRegionSettings::class, 'coding', 'intl');

        $directory = $this->directory();
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($directory->listModelMetadata()));

        /*
         * The SAME instance must re-probe once past the plugin's 60s
         * negative marker (GLM1 #6): no SDK localCache entry exists for
         * the fallback, so a later valid key can still discover.
         *
         * GLM9 #5: the 401 additionally persists a definitive invalid
         * verdict for the wired key, whose refusal gate blocks
         * re-discovery with that SAME key until the verdict's own
         * STATE_TTL expires (the verdict IS the feature — a rejected
         * key must not re-authenticate every 60s); the re-probe below
         * advances past both windows.
         */
        $this->advanceTime(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::STATE_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.2')));
        $this->assertCount(1, $this->idList($directory->listModelMetadata()));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testBothDirectoriesShareTheDiscoveryCacheConstants()
    {
        /*
         * GLM4 #10: the discovery orchestration was duplicated
         * line-for-line between the two directories, so every caching
         * change had to land twice. Both run the shared ZaiDiscoveryCache's
         * cached_ids() flow, so its constants are the single source.
         *
         * glm19-10 supersedes the old alias-agreement pins: the
         * directories' own alias constants (DISCOVERY_TTL, NEGATIVE_TTL,
         * NEGATIVE_CACHE_SUFFIX, CACHE_PREFIX) were production-dead —
         * every composition goes through ZaiDiscoveryCache and the
         * endpoint classes — and the docblocked mirrors suggested the
         * directories owned TTL/prefix behavior they do not have. The
         * aliases are deleted; this pin now fails if one comes back.
         * (The SDK-free settings invalidation and uninstall.php mirror
         * the literal suffix value — those mirrors are pinned
         * separately.)
         */
        foreach (array(ZaiModelMetadataDirectory::class, ZaiAnthropicModelMetadataDirectory::class) as $directory) {
            $constants = (new \ReflectionClass($directory))->getConstants();
            foreach (array('DISCOVERY_TTL', 'NEGATIVE_TTL', 'NEGATIVE_CACHE_SUFFIX', 'CACHE_PREFIX') as $alias) {
                $this->assertArrayNotHasKey($alias, $constants, "{$directory}::{$alias} is a deleted dead alias (glm19-10); the real owners are ZaiDiscoveryCache and the settings/endpoint classes.");
            }
        }

        // The external mirrors keep pinning the literal suffix value.
        $this->assertSame('_miss', ZaiDiscoveryCache::NEGATIVE_CACHE_SUFFIX);
    }

    public function testEveryEntryFailureReasonIsTypedAndMappedToItsRejection()
    {
        /*
         * glm14-7: the rejection switch's default arm silently mapped any
         * future or renamed entry-failure reason to the missing-data
         * rejection. The reasons are typed ENTRY_* constants now, and the
         * rejection rides ONE reason→message map: every reason string
         * entry_failure_reason() can return over the malformed-shape
         * battery must (a) be one of the declared constants and (b)
         * carry a rejection in that map, so a future reason without its
         * mapping fails LOUDLY (the lockstep RuntimeException) instead
         * of degrading silently.
         */
        $reflection = new \ReflectionClass(ZaiModelListParser::class);
        $reason_constants = array_filter(
            array_keys($reflection->getConstants()),
            static function (string $name): bool {
                return 'ENTRY_' === substr($name, 0, 6) && 'ENTRY_REJECTION_MESSAGES' !== $name;
            }
        );
        $messages = $reflection->getConstant('ENTRY_REJECTION_MESSAGES');

        // Every declared reason constant carries its rejection mapping.
        foreach ($reason_constants as $constant) {
            $reason = $reflection->getConstant($constant);
            $this->assertArrayHasKey(
                $reason,
                $messages,
                "The {$constant} reason must carry a rejection in the one map."
            );
        }

        $shapes = array(
            'null body' => null,
            'missing data member' => (object) array('other' => 1),
            'object-shaped data' => (object) array('data' => (object) array('only' => (object) array('id' => 'glm-5.3'))),
            'additional pages' => (object) array('data' => array((object) array('id' => 'glm-5.3')), 'has_more' => true),
            'empty list' => (object) array('data' => array()),
            'bad entry id' => (object) array('data' => array((object) array('id' => 0))),
        );

        foreach ($shapes as $label => $raw) {
            $reason = ZaiModelListParser::entry_failure_reason($raw);

            $this->assertNotNull($reason, "[{$label}] The malformed shape must produce a failure reason.");
            $this->assertContains(
                $reason,
                array_map(static function (string $name) use ($reflection) {
                    return $reflection->getConstant($name);
                }, $reason_constants),
                "[{$label}] Every returnable reason must be a declared ENTRY_* constant."
            );
            $this->assertArrayHasKey($reason, $messages, "[{$label}] The reason must carry a rejection mapping.");
        }

        // The one map builds every reason's rejection (the old default
        // arm's silently-missing cases now throw instead).
        $build = new \ReflectionMethod(ZaiModelListParser::class, 'entry_rejection');

        foreach ($reason_constants as $constant) {
            $reason = $reflection->getConstant($constant);

            try {
                $build->invoke(null, $reason, 'z.ai');
                $this->fail("The {$constant} reason must throw its typed rejection.");
            } catch (ResponseException $e) {
                // The mapped rejection — the pin.
            }
        }

        // An unmapped reason fails LOUDLY (the lockstep invariant), never
        // the silent missing-data degradation the old default arm gave.
        try {
            $build->invoke(null, 'future_reason', 'z.ai');
            $this->fail('An unmapped reason must throw the lockstep invariant.');
        } catch (\WordPress\AiClient\Common\Exception\RuntimeException $e) {
            $this->assertStringContainsString('Unmapped model-list entry reason', $e->getMessage());
        }

        // The valid shape still carries no reason.
        $this->assertNull(ZaiModelListParser::entry_failure_reason(
            (object) array('data' => array((object) array('id' => 'glm-5.3')))
        ));
    }

    /**
     * @param list<ModelMetadata> $models
     * @return list<string>
     */
}
