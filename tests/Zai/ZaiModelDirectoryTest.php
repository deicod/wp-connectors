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
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog;
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

    /**
     * @param string $plan Plan.
     * @param string $region Region.
     * @return void
     */
    private function selectEndpoint(string $plan, string $region)
    {
        update_option(PlanRegionSettings::OPTION_PLAN, $plan);
        update_option(PlanRegionSettings::OPTION_REGION, $region);
    }

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
        $this->selectEndpoint('general', 'intl');
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
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(self::OBSERVED_MODELS));

        $first = $this->directory();
        $ids = array_map(static function (ModelMetadata $m) {
            return $m->getId();
        }, $first->listModelMetadata());

        $this->assertCount(10, $ids);
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
        $this->selectEndpoint('coding', 'intl');
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
        $this->selectEndpoint('coding', 'intl');
        $key = FakeSecrets::apiKey();
        $binding = hash('sha256', 'runtime|' . \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for_current_settings()->cache_key() . '|' . $key);
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

    public function testDiscoveryCacheExpires()
    {
        $this->freezeTime(1700000000);
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        // ONE directory instance across the expiry — the production shape
        // (the provider's per-process singleton). The SDK's own cache layer
        // (in-memory local or PSR-16, 24h TTL) must not be able to serve the
        // discovery past the plugin's 12h transient TTL (review finding: it
        // previously did, so the advertised TTL never triggered a refresh).
        $directory = $this->directory();
        $directory->listModelMetadata();
        $this->assertCount(1, $this->sdkHttpAttempts());

        $this->advanceTime(ZaiModelMetadataDirectory::DISCOVERY_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
        $models = $directory->listModelMetadata();
        $this->assertCount(2, $models);
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testTransientInvalidationBypassesTheSdkCacheLayerToo()
    {
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        // Warm both layers the way a successful request does.
        $directory = $this->directory();
        $this->assertCount(1, $this->idList($directory->listModelMetadata()));

        // A settings change / uninstall deletes the plugin transient...
        delete_transient(ZaiModelMetadataDirectory::CACHE_PREFIX . md5('zai|coding|intl'));

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
            $this->selectEndpoint('coding', 'intl');

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
            $this->advanceTime(ZaiModelMetadataDirectory::DISCOVERY_TTL + 1);
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
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        $this->directory()->listModelMetadata(); // Warms the coding|intl cache.
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Switch plan well inside the TTL: the general endpoint must be
        // re-fetched, never served the coding cache.
        $this->selectEndpoint('general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-4.5')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $models);
        $this->assertCount(2, $this->sdkHttpAttempts());
        $this->assertSame('https://api.z.ai/api/paas/v4/models', $this->sdkHttpAttempts()[1]['url']);
    }

    public function testRegionSwitchBeforeExpiryRefetchesTheOtherEndpoint()
    {
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->directory()->listModelMetadata();

        $this->selectEndpoint('coding', 'cn');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $models);
        $this->assertSame('https://open.bigmodel.cn/api/coding/paas/v4/models', $this->sdkHttpAttempts()[1]['url']);
    }

    /*
     * Fallback behavior.
     */

    public function testUnauthorizedDiscoveryFallsBackToThePlanCatalog()
    {
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('token expired or incorrect'));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);
    }

    public function testGeneralPlanFallbackContainsTheFullCatalog()
    {
        $this->selectEndpoint('general', 'cn');

        // cn is unprobed; any discovery failure falls back per plan.
        $this->queueSdkResponse(404, array(), '{"error":{"message":"not found"}}');

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::GENERAL_MODELS, $this->idList($models));
    }

    public function testMalformedDiscoveryResponseFallsBack()
    {
        $this->selectEndpoint('coding', 'intl');

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

    public function testFallbackIsNotCachedSoAValidKeyCanDiscoverLater()
    {
        $this->selectEndpoint('coding', 'intl');

        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()));

        // No transient may exist after a failure...
        $this->assertFalse(get_transient(ZaiModelMetadataDirectory::CACHE_PREFIX . md5('zai|coding|intl')));

        // ...so the next request attempts discovery again and succeeds.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
        $this->assertCount(2, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testTransportFailureFallsBackWithoutFatal()
    {
        $this->selectEndpoint('coding', 'intl');
        $this->allowUnmockedHttp = true;

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
    }

    /*
     * Capability and option declarations.
     */

    public function testDiscoveredModelsCarryCapabilitiesAndOptions()
    {
        $this->selectEndpoint('general', 'intl');
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
        $this->selectEndpoint('general', 'intl');
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
        $this->selectEndpoint('general', 'intl');
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
        $this->selectEndpoint('general', 'intl');
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
        $cached = get_transient(ZaiModelMetadataDirectory::CACHE_PREFIX . md5('zai|general|intl'));
        $this->assertSame(array('glm-5.3'), array_values($cached));

        // hasModelMetadata() must reject the dropped IDs.
        $this->assertFalse($this->directory()->hasModelMetadata('embedding-3'));
        $this->assertFalse($this->directory()->hasModelMetadata('glm-6-image'));
    }

    public function testDiscoveryWithOnlyNonChatIdsFallsBackToThePlanCatalog()
    {
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('embedding-3', 'cogview-4', 'glm-6-image')));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'Nothing usable discovered: the plan fallback applies.');
        $this->assertFalse(get_transient(ZaiModelMetadataDirectory::CACHE_PREFIX . md5('zai|coding|intl')), 'The fallback must not be cached.');
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

        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $models = $directory->listModelMetadata();
        $this->assertCount(1, $models);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);

        // Same provider, same directory instance, same registry: switch the
        // endpoint and list again — the next request must hit the NEW
        // endpoint, never the warm cache from the previous one.
        $this->selectEndpoint('general', 'cn');
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

        $this->selectEndpoint('coding', 'intl');
        $coding_intl = $base_key();
        $this->selectEndpoint('general', 'intl');
        $general_intl = $base_key();
        $this->selectEndpoint('coding', 'cn');
        $coding_cn = $base_key();

        $this->assertNotSame($coding_intl, $general_intl);
        $this->assertNotSame($coding_intl, $coding_cn);
        $this->assertNotSame($general_intl, $coding_cn);
    }

    public function testFallbackIsNotCachedAtTheSdkLayerEither()
    {
        $this->selectEndpoint('coding', 'intl');

        $directory = $this->directory();
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('bad key'));
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($directory->listModelMetadata()));

        // The SAME instance must re-probe (no SDK localCache entry for the
        // fallback), so a later valid key can still discover.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.2')));
        $this->assertCount(1, $this->idList($directory->listModelMetadata()));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }


    /**
     * @param list<ModelMetadata> $models
     * @return list<string>
     */
    private function idList(array $models): array
    {
        return array_map(static function (ModelMetadata $m) {
            return $m->getId();
        }, $models);
    }
}
