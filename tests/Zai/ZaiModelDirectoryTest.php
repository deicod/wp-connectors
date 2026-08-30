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

    public function testDiscoveryCacheExpires()
    {
        $this->freezeTime(1700000000);
        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));

        $this->directory()->listModelMetadata();
        $this->assertCount(1, $this->sdkHttpAttempts());

        $this->advanceTime(ZaiModelMetadataDirectory::DISCOVERY_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-5.2')));
        $models = $this->directory()->listModelMetadata();
        $this->assertCount(2, $models);
        $this->assertCount(2, $this->sdkHttpAttempts());
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
                    $this->assertNotSame('outputModalities', (string) $option->getName(), "{$modelId} must not advertise output modalities.");
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

    public function testUnknownDiscoveredIdGetsConservativeTextCapabilities()
    {
        $this->selectEndpoint('general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-future-9')));

        $metadata = $this->directory()->getModelMetadata('glm-future-9');

        $this->assertSame(array('text_generation', 'chat_history'), array_map('strval', $metadata->getSupportedCapabilities()));
        $this->assertSame('GLM-FUTURE-9', $metadata->getName(), 'Unknown IDs keep a conservative uppercase name.');
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

        $directory = $registry->getProviderClassName('zai')::modelMetadataDirectory();
        $directory->invalidateCaches(); // The instance is cached per-class process-wide.

        $this->selectEndpoint('coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $directory->listModelMetadata();
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/models', $this->sdkHttpAttempts()[0]['url']);

        // Same provider, same registry instance: change the setting and
        // invalidate the in-memory per-instance cache only.
        $this->selectEndpoint('general', 'cn');
        $directory->invalidateCaches();
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $directory->listModelMetadata();

        $this->assertSame('https://open.bigmodel.cn/api/paas/v4/models', end(WpHarness::$sdk_http_attempts)['url']);
        $this->assertTrue($registry->hasProvider('zai'), 'Registry state must be untouched.');
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
