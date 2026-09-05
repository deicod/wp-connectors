<?php
/**
 * Task 2.4 — zai_anthropic model metadata directory tests.
 *
 * Covers the plan-partitioned static fallback, discovery against the
 * /v1/models route (both list shapes), transient caching and its expiry,
 * cache scoping across pre-expiry plan/region switches (the new endpoint's
 * catalog is re-fetched, never served stale), malformed/401/404 fallback
 * behavior, and the capability/option declarations.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;
use Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache;

final class ZaiAnthropicModelDirectoryTest extends WpConnectorsTestCase
{
    /**
     * Fresh directory wired to the harness transporter with a fixture key.
     *
     * @param string|null $key API key; a fresh fixture key when omitted.
     * @return ZaiAnthropicModelMetadataDirectory
     */
    private function directory(?string $key = null): ZaiAnthropicModelMetadataDirectory
    {
        $directory = new ZaiAnthropicModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication($key ?? FakeSecrets::apiKey()));

        return $directory;
    }

    /*
     * glm15-19: selectEndpoint()/idList() ride the shared harness — the
     * private twins differed only in their settings class (and their
     * docblocks had already drifted from their assertions).
     */

    /*
     * Static catalog data (shared, plan-partitioned).
     */

    public function testFallbackCatalogsArePlanPartitionedAndSharedWithTheZaiData()
    {
        $coding = ZaiModelCatalog::ids_for_plan('coding');
        $general = ZaiModelCatalog::ids_for_plan('general');

        // Coding exposes only the GLM 5.x family (SPEC 3.3 restricted set).
        foreach ($coding as $modelId) {
            $this->assertStringStartsWith('glm-5', $modelId);
        }
        $this->assertCount(10, $general);
        $this->assertNotSame($coding, $general);

        // The neutral DATA is shared with the zai provider's catalog (not
        // the adapter): same fallback lists.
        $this->assertSame(Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog::CODING_MODELS, $coding);
        $this->assertSame(Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog::GENERAL_MODELS, $general);
    }

    public function testCodingFallbackServesWhenNoCacheAndDiscoveryFails()
    {
        // No cached transient: discovery is attempted; its failure (here a
        // 404 — the unprobed route shape) must leave the plan fallback.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(404, array(), '{"type":"error","error":{"type":"not_found_error","message":"no route"}}');

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
        $this->assertCount(1, $this->sdkHttpAttempts(), 'Exactly one discovery attempt was made.');
    }

    /*
     * Discovery.
     */

    public function testDiscoveryHitsTheSelectedEndpointWithProtocolHeadersAndCaches()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.2', 'glm-5.3', 'glm-5.3-flash')));

        $first = $this->directory($key);
        $ids = $this->idList($first->listModelMetadata());

        $this->assertSame(array('glm-5.3', 'glm-5.3-flash', 'glm-5.2'), $ids, 'Discovered models sort newest-first.');
        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/models', $attempts[0]['url']);
        $this->assertSame(array('Bearer ' . $key), $attempts[0]['headers']['Authorization'] ?? null);
        $this->assertSame(array('2023-06-01'), $attempts[0]['headers']['anthropic-version'] ?? null);

        // A FRESH instance (new request) serves the cached discovery without
        // another HTTP attempt.
        $second = $this->directory($key);
        $this->assertSame($ids, $this->idList($second->listModelMetadata()));
        $this->assertCount(1, $this->sdkHttpAttempts());
    }

    public function testCodingPlanDiscoveryIsIntersectedWithThePlanCatalog()
    {
        // Codex R3 #4: the live route returns the FULL list on the coding
        // plan (record 0007), but the coding subscription exposes only its
        // restricted model set — general-only GLM 4.x entries must not be
        // advertised OR cached.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array(
            'glm-5.3', 'glm-5.3-flash', 'glm-4.7', 'glm-4.5', 'glm-4.5-air',
        )));

        $models = $this->directory()->listModelMetadata();

        // The advertised list is the DISCOVERED list intersected with the
        // coding catalog (newest-first): the two coding models present in
        // the response, with the general-only entries dropped.
        $this->assertSame(array('glm-5.3', 'glm-5.3-flash'), $this->idList($models), 'Only in-plan discovered models may be advertised.');

        // The cached transient must already be the intersected list.
        $cached = get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|coding|intl'));
        $this->assertSame(array('glm-5.3', 'glm-5.3-flash'), array_values($cached), 'The cached discovery must be plan-intersected.');

        // hasModelMetadata must reject the dropped general-only IDs.
        $this->assertFalse($this->directory()->hasModelMetadata('glm-4.5'));
        $this->assertFalse($this->directory()->hasModelMetadata('glm-4.5-air'));
    }

    public function testGeneralPlanDiscoveryKeepsTheFullList()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array(
            'glm-5.3', 'glm-4.7', 'glm-4.5',
        )));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(array('glm-5.3', 'glm-4.7', 'glm-4.5'), $this->idList($models), 'The general plan keeps the full discovered list.');

        $cached = get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|general|intl'));
        $this->assertSame(array('glm-5.3', 'glm-4.7', 'glm-4.5'), array_values($cached));
    }

    public function testDiscoveryWithOnlyOutOfPlanModelsFallsBack()
    {
        // Nothing survives the plan intersection: the plan fallback applies
        // and nothing is cached.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-4.5', 'glm-4.5-air')));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'The plan fallback applies.');
        $this->assertFalse(get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|coding|intl')), 'The fallback must not be cached.');
    }

    public function testDiscoveryAcceptsTheOpenAiListShapeToo()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3', 'glm-4.5')));

        $ids = $this->idList($this->directory()->listModelMetadata());

        $this->assertSame(array('glm-5.3', 'glm-4.5'), $ids);
    }

    public function testDiscoveryCacheExpires()
    {
        $this->freezeTime(1700000000);
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));

        $directory = $this->directory();
        $this->assertCount(1, $this->idList($directory->listModelMetadata()));
        $this->assertCount(1, $this->sdkHttpAttempts());

        $this->advanceTime(ZaiDiscoveryCache::DISCOVERY_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3', 'glm-5.2')));
        $models = $directory->listModelMetadata();

        $this->assertCount(2, $this->idList($models));
        $this->assertCount(2, $this->sdkHttpAttempts(), 'Past the TTL the discovery must run again.');
    }

    /*
     * Cache scoping across plan/region switches (BEFORE expiry).
     */

    public function testPlanSwitchBeforeExpiryRefetchesTheOtherEndpoint()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));

        $this->directory()->listModelMetadata(); // Warms the coding|intl cache.
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Switch plan well inside the TTL: the general endpoint must be
        // re-fetched, never served the coding cache.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3', 'glm-4.5')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $models);
        $this->assertCount(2, $this->sdkHttpAttempts());
        $this->assertSame('https://api.z.ai/api/anthropic/v1/models', $this->sdkHttpAttempts()[1]['url']);
    }

    public function testRegionSwitchBeforeExpiryRefetchesTheOtherEndpoint()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));
        $this->directory()->listModelMetadata();

        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'cn');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3', 'glm-5.2')));

        $models = $this->directory()->listModelMetadata();

        $this->assertCount(2, $this->idList($models));
        $this->assertSame('https://open.bigmodel.cn/api/coding/anthropic/v1/models', $this->sdkHttpAttempts()[1]['url']);
    }

    public function testTheZaiProvidersCacheNeverServesTheAnthropicDirectoryAndViceVersa()
    {
        // Prime the ZAI provider's warm cache for the same plan/region
        // selection; the anthropic directory must still fetch its own.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        set_transient(
            Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl'),
            array('glm-4.5'),
            3600
        );

        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));
        $ids = $this->idList($this->directory()->listModelMetadata());

        $this->assertSame(array('glm-5.3'), $ids, 'The OpenAI-surface cache must never serve this directory.');
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/models', $this->sdkHttpAttempts()[0]['url']);
    }

    /*
     * Fallback behavior.
     */

    public function testUnauthorizedDiscoveryFallsBackToThePlanCatalog()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(401, array(), HttpResponseFactory::anthropicErrorBody('token expired or incorrect', 'authentication_error'));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/models', $this->sdkHttpAttempts()[0]['url']);
    }

    public function testRepeatedLookupsOnOneInstanceReuseTheBuiltMap()
    {
        /*
         * GLM7 #13: the map (metadata objects, capability/option sets,
         * sort) is a pure function of the resolved IDs, but every
         * list/has/get call rebuilt it — core resolution makes two or
         * more per request. The rebuild is memoized per transient
         * CONTENT: repeated lookups return the SAME metadata instances,
         * and a transient-content change (the read stays authoritative)
         * swaps the memo key and rebuilds.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->primeZaiAnthropicDiscoveryTransient(array('glm-5.3', 'glm-5.2'));

        $directory = $this->directory();

        $first = $directory->getModelMetadata('glm-5.3');
        $this->assertSame($first, $directory->getModelMetadata('glm-5.3'), 'A repeated get must reuse the built metadata object.');
        $this->assertSame($first, $directory->listModelMetadata()[0], 'listModelMetadata() must reuse the built map.');
        $this->assertTrue($directory->hasModelMetadata('glm-5.2'), 'The memoized map answers has-lookups.');

        // A transient-content change rebuilds: the memo follows the IDs,
        // not the instance.
        $this->primeZaiAnthropicDiscoveryTransient(array('glm-5.2'));
        $rebuilt = $directory->getModelMetadata('glm-5.2');
        $this->assertNotSame($first, $rebuilt, 'New content must rebuild the map.');
        $this->assertFalse($directory->hasModelMetadata('glm-5.3'), 'The rebuilt map reflects the new ID list.');
    }

    /**
     * @dataProvider provideCredentialRejectionStatuses
     */
    public function testCredentialRejectingDiscoveryPersistsTheInvalidVerdict($status, $label)
    {
        /*
         * GLM7 #12: a definitive 401/403 on /v1/models is the same
         * evidence the availability probe persists an invalid verdict for
         * — previously it surfaced as the misattributed 'Missing the
         * "data" key' error and converted into the silent 60s '_miss'
         * marker plus fallback, so isConfigured() kept reporting
         * configured-pending for a definitively rejected key. The verdict
         * is recorded through the probe's own persist path: a subsequent
         * availability consult answers from state with NO new request.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION, $key);
        $this->queueSdkResponse($status, array(), HttpResponseFactory::anthropicErrorBody('token expired or incorrect', 'authentication_error'));

        $models = $this->directory($key)->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), "{$label}: the fallback still serves.");
        $this->assertCount(1, $this->sdkHttpAttempts(), "{$label}: exactly one discovery attempt.");

        $state = get_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state, "{$label}: the invalid verdict must be persisted.");
        $this->assertSame('invalid', $state['valid']);

        $availability = new Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability();
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

    public function testEnvelopeRejectingDiscoveryPersistsTheInvalidVerdict()
    {
        /*
         * glm18-4: this route's live-attested rejection shape is HTTP 200
         * CARRYING the failure envelope — definitive INVALID for the
         * probe since glm12-1, but discovery recorded verdicts only for
         * the 401/403 STATUS set, so a server-side revocation arriving
         * as the envelope persisted nothing and a stale VALID verdict
         * kept isConfigured() answering true while every window's first
         * generation learned of it only through its own doomed 401. The
         * envelope now records through the same persist path the status
         * branch rides: a subsequent availability consult answers from
         * state with NO new request.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION, $key);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::zaiStatusEnvelopeBody(401, 'token expired or incorrect'));

        $models = $this->directory($key)->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'The fallback still serves.');
        $this->assertCount(1, $this->sdkHttpAttempts(), 'Exactly one discovery attempt.');

        $state = get_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state, 'The invalid verdict must be persisted.');
        $this->assertSame('invalid', $state['valid']);

        $availability = new Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability();
        $this->assertFalse($availability->isConfigured(), 'An envelope-rejected key reports not-connected.');
        $this->assertCount(1, $this->sdkHttpAttempts(), 'The fresh state answers without another request.');
    }

    public function testANonCredentialEnvelopeBodyRecordsNothing()
    {
        /*
         * glm18-4 (glm12-1 parity): a success:false envelope whose code
         * is NOT in the definitive set (1113 balance/plan standing)
         * rejects the account's standing, not the key — the predicate
         * does not match, nothing records, and discovery degrades to
         * the miss marker exactly like its 429 status twin.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION, $key);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::zaiStatusEnvelopeBody(1113, 'insufficient balance'));

        $models = $this->directory($key)->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'The fallback still serves.');
        $this->assertFalse(
            get_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::STATE_OPTION),
            'A non-credential envelope code must not persist a verdict.'
        );
    }

    public function testAHybridModelsListCarryingTheEnvelopeIsNotARejection()
    {
        /*
         * glm18-15 (verifier round on glm18-4): the probe's one verdict
         * rule checks the models-list shape FIRST, so a HYBRID body — a
         * well-formed list that also carries success:false + a
         * definitive code — is VALID evidence there. The glm18-4 form
         * of the body recorder consulted the envelope alone and
         * answered the same body INVALID: one 2xx answer persisted
         * opposite verdicts at the two sites, the fresh invalid state
         * answering isConfigured() false with no new request while core
         * clears keys on false. The recorder applies the probe's
         * precedence now: the hybrid is a models list, discovery
         * parses it, and no verdict is recorded by the directory.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION, $key);
        $this->queueSdkResponse(
            200,
            array(),
            '{"data":[{"id":"glm-5.3"},{"id":"glm-5.2"}],"success":false,"code":401,"msg":"token expired or incorrect"}'
        );

        $models = $this->directory($key)->listModelMetadata();

        $this->assertSame(array('glm-5.3', 'glm-5.2'), $this->idList($models), 'The hybrid body parses as the models list it also is.');
        $this->assertFalse(
            get_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::STATE_OPTION),
            'A body the probe judges VALID must not persist invalid here.'
        );

        /*
         * The probe on the SAME body: the models-list shape wins there
         * too — one precedence at both verdict sites, no divergence.
         */
        $availability = new Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability();
        $availability->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication($key));
        $this->queueSdkResponse(
            200,
            array(),
            '{"data":[{"id":"glm-5.3"},{"id":"glm-5.2"}],"success":false,"code":401,"msg":"token expired or incorrect"}'
        );

        $this->assertTrue($availability->isConfigured(), 'The probe still judges the hybrid body by its models-list half.');
    }

    public function testDiscoveryToleratesAGatewayBomPrefix()
    {
        /*
         * GLM10 #3, zai_anthropic twin: a gateway/CDN prepending a UTF-8
         * BOM to the /v1/models JSON body made both decodes fail, so
         * discovery silently degraded to the 60s '_miss' marker plus
         * static fallback on every request. The parser owns one BOM-safe
         * decode now.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(
            200,
            array(),
            "\xEF\xBB\xBF" . HttpResponseFactory::anthropicModelsBody(array('glm-5.3', 'glm-5.2', 'glm-4.5'))
        );

        $ids = $this->idList($this->directory()->listModelMetadata());

        $this->assertSame(array('glm-5.3', 'glm-5.2'), $ids, 'A BOM-prefixed body still discovers (coding-plan intersection as always).');
        $this->assertCount(1, $this->sdkHttpAttempts(), 'One discovery attempt, no retry storm.');
    }

    public function testCredentialRejectingDiscoveryRecordsAgainstTheRequestTimeEndpoint()
    {
        /*
         * GLM10 #1: the recorder previously re-resolved the endpoint from
         * the settings at RESPONSE time, so an admin saving the region
         * while an intl discovery was in flight got the intl rejection
         * persisted under the CN binding — isConfigured() on cn then
         * answered not-connected from state for a key never tested
         * against cn, while intl kept no verdict at all. The verdict must
         * bind the endpoint the rejecting request actually HIT (the
         * $endpoint captured at request time).
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION, $key);

        // Flip the region mid-flight: the request is already built against
        // intl; the flip lands between send and response processing — the
        // exact race the request-time capture exists for.
        AiClient::defaultRegistry()->setHttpTransporter(new HttpTransporter(new MidFlightOptionFlipClient(
            new SdkHttpClient(),
            ZaiAnthropicPlanRegionSettings::OPTION_REGION,
            'cn'
        )));

        $this->queueSdkResponse(401, array(), HttpResponseFactory::anthropicErrorBody('token expired or incorrect', 'authentication_error'));

        $models = $this->directory($key)->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'The fallback still serves.');
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/models', $this->sdkHttpAttempts()[0]['url'], 'The discovery request itself hit intl.');

        $state = get_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::STATE_OPTION);
        $this->assertIsArray($state, 'The invalid verdict must be persisted.');
        $this->assertSame(
            ZaiAnthropicPlanRegionSettings::credential_binding(
                'database',
                Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::for('coding', 'intl')->cache_key(),
                $key
            ),
            $state['binding'],
            'The verdict binds the request-time (intl) endpoint, not the response-time cn one.'
        );

        // The cn consult must NOT inherit the intl rejection: it probes cn
        // on its own (a 200 answers valid — connected). The flip client's
        // one-shot arming is spent, so the wired transporter just delegates.
        $this->queueSdkResponse(200, array(), '{"data":[]}');
        $availability = new Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability();
        $availability->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $this->assertTrue($availability->isConfigured(), 'The cn consult answers its own probe, not the intl rejection.');
        $this->assertCount(2, $this->sdkHttpAttempts(), 'The cn consult probed rather than answering from the intl-bound state.');
        $this->assertSame('https://open.bigmodel.cn/api/coding/anthropic/v1/models', $this->sdkHttpAttempts()[1]['url'], 'The follow-up probe hit the (now current) cn endpoint.');
    }

    public function testNonAuthDiscoveryFailurePersistsNoVerdict()
    {
        /*
         * GLM7 #12 guard: only the definitive credential rejections
         * persist — a 404 (the unprobed route shape) stays an
         * inconclusive failure whose verdict store stays untouched.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION, $key);
        $this->queueSdkResponse(404, array(), '{"type":"error","error":{"type":"not_found_error","message":"no route"}}');

        $models = $this->directory($key)->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
        $this->assertFalse(
            get_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::STATE_OPTION, false),
            'A non-auth failure must not persist a verdict.'
        );
    }

    public function testGeneralPlanFallbackContainsTheFullCatalog()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'general', 'cn');

        $this->queueSdkResponse(404, array(), '{"type":"error","error":{"type":"not_found_error","message":"not found"}}');

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::GENERAL_MODELS, $this->idList($models));
    }

    public function testAPaginatedDiscoveryPageFallsBackAndIsNotCached()
    {
        /*
         * Codex R15 #3 (option a): a partial page with has_more: true is
         * NOT a catalog — caching it for 12 hours would freeze the
         * directory to one page and drop known in-plan models.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"data":[{"id":"glm-5.3"}],"has_more":true,"first_id":"glm-5.3","last_id":"glm-5.3"}');

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'An incomplete page falls back to the static plan catalog.');
        $this->assertFalse(get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|coding|intl')), 'The partial page must not be cached.');
    }

    /**
     * @dataProvider provideHasMoreShapes
     */
    public function testNonBooleanHasMoreValuesFailDiscoveryToo($hasMoreJson)
    {
        // STRICT shape: "true"/1/null are not the documented bool.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"data":[{"id":"glm-5.3"}],' . $hasMoreJson . '}');

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'A non-boolean has_more falls back like an incomplete page.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideHasMoreShapes()
    {
        return array(
            'string true' => array('"has_more":"true"'),
            'integer one' => array('"has_more":1'),
            'explicit null' => array('"has_more":null'),
        );
    }

    public function testACompletePageWithHasMoreFalseOrAbsentStillDiscovers()
    {
        // Guards: has_more:false is a complete page; an absent member is
        // the pre-existing shape.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"data":[{"id":"glm-5.3"}],"has_more":false}');
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"data":[{"id":"glm-5.3"}]}');

        $first = $this->idList($this->directory()->listModelMetadata());
        $this->assertSame(array('glm-5.3'), $first, 'has_more:false is a complete page.');

        $second = $this->idList($this->directory()->listModelMetadata());
        $this->assertSame(array('glm-5.3'), $second, 'An absent has_more member keeps the current behavior.');
    }

    public function testDiscoveryIsSkippedWhileTheCredentialIsRegionPending()
    {
        /*
         * R20 (inline 3907008518): an env credential that survives a
         * region switch must not be disclosed to the newly selected
         * endpoint by model ENUMERATION — the static fallback serves and
         * no authenticated request leaves.
         */
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        $directory = $this->directory($key);

        $region = \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::for_current_settings()->region();
        update_option(ZaiAnthropicPlanRegionSettings::REGION_PENDING_OPTION, array(
            'region' => $region,
            'fingerprint' => hash('sha256', $key),
        ));

        $models = $directory->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'The static plan catalog serves while the credential is region-pending.');
        $this->assertFalse(get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|coding|intl')), 'The fallback is not cached.');
        $this->assertSame(array(), WpHarness::$sdk_http_attempts, 'No authenticated discovery request may leave.');
    }

    public function testDiscoveryIsSkippedWhileAMatchingInvalidVerdictExists()
    {
        // R20 (inline 3907008518): a definitive invalid verdict for the
        // exact key+endpoint binding also gates enumeration.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $key = FakeSecrets::apiKey();
        $directory = $this->directory($key);

        // GLM5 #11: the runtime (save-time candidate) source normalizes to the
        // database identity at binding construction.
        $binding = hash('sha256', 'database|zai_anthropic|coding|intl|' . $key);
        update_option(ZaiAnthropicPlanRegionSettings::STATE_OPTION, array(
            'binding' => $binding,
            'valid' => 'invalid',
            'checked_at' => time(),
            'clock' => 'utc',
        ));

        $models = $directory->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'The static plan catalog serves while the credential verdict is invalid.');
        $this->assertSame(array(), WpHarness::$sdk_http_attempts, 'No authenticated discovery request may leave.');
    }

    public function testDiscoveryProceedsUnderValidCredentialState()
    {
        // Guard: no pending flag and no invalid verdict — discovery
        // authenticates and discovers as before.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(array('glm-5.3'), $this->idList($models), 'Valid state still discovers live.');
        $this->assertCount(1, WpHarness::$sdk_http_attempts, 'The authenticated discovery request left exactly once.');
    }

    public function testMalformedDiscoveryResponsesFallBack()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');

        $bodies = array(
            'not json at all',
            '{"data":{"id":"not-a-list"}}',
            '{"data":{"only":{"id":"glm-5.3"}}}',
            '{"data":{}}',
            '{"data":[{"no_id":true}]}',
            '{"data":[]}',
            '{"data":[{"id":"embedding-3"},{"id":"cogview-4"}]}',
        );

        foreach ($bodies as $body) {
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
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(404, array(), HttpResponseFactory::anthropicErrorBody('no route'));

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(1, $this->sdkHttpAttempts());

        // Within the TTL the fallback serves again with NO remote attempt.
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(1, $this->sdkHttpAttempts(), 'The short negative cache must suppress the doomed repeat request.');

        // The positive cache stays unset: only the miss marker exists.
        $this->assertFalse(get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|coding|intl')));

        // After the TTL, discovery is retryable and a valid endpoint wins.
        $this->advanceTime(ZaiDiscoveryCache::NEGATIVE_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3', 'glm-5.2')));
        $this->assertCount(2, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testFallbackIsNotCachedSoAValidKeyCanDiscoverLater()
    {
        $this->freezeTime(1700000000);
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');

        $this->queueSdkResponse(401, array(), HttpResponseFactory::anthropicErrorBody('bad key'));
        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($this->directory()->listModelMetadata()));

        $cacheId = ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|coding|intl');
        $this->assertFalse(get_transient($cacheId), 'No transient may exist after a failure.');

        /*
         * ...so the next request attempts discovery again and succeeds
         * (GLM1 #6: the short negative cache only spans NEGATIVE_TTL
         * seconds — a later request past the TTL rediscovers).
         */
        $this->advanceTime(ZaiDiscoveryCache::NEGATIVE_TTL + 1);
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3', 'glm-5.2')));
        $this->assertCount(2, $this->idList($this->directory()->listModelMetadata()));
        $this->assertCount(2, $this->sdkHttpAttempts());
    }

    public function testTransportFailureFallsBackWithoutFatal()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->allowUnmockedHttp = true;

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models));
    }

    public function testDiscoveryDropsModelIdsWithoutKnownChatSupport()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'general', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array(
            'embedding-3',
            'cogview-4',
            'glm-future-9',
            'glm-5.3',
            'glm-6',
            'glm-6-image',
        )));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(array('glm-5.3'), $this->idList($models), 'Only IDs with known chat support may be advertised.');

        // The persisted transient must already be the filtered list.
        $cached = get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|general|intl'));
        $this->assertSame(array('glm-5.3'), array_values($cached));

        $this->assertFalse($this->directory()->hasModelMetadata('glm-6-image'));
    }

    public function testDiscoveryWithOnlyNonChatIdsFallsBackToThePlanCatalog()
    {
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('embedding-3', 'cogview-4', 'glm-6-image')));

        $models = $this->directory()->listModelMetadata();

        $this->assertSame(ZaiModelCatalog::CODING_MODELS, $this->idList($models), 'Nothing usable discovered: the plan fallback applies.');
        $this->assertFalse(get_transient(ZaiAnthropicPlanRegionSettings::CACHE_PREFIX . md5('zai_anthropic|coding|intl')), 'The fallback must not be cached.');
    }

    /*
     * Capability and option declarations.
     */

    public function testModelsCarryCapabilitiesAndOptions()
    {
        $this->primeZaiAnthropicDiscoveryTransient();
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

        foreach (array('systemInstruction', 'maxTokens', 'temperature', 'topP', 'stopSequences', 'outputMimeType', 'outputSchema', 'functionDeclarations', 'inputModalities', 'outputModalities') as $name) {
            $this->assertArrayHasKey($name, $options, "Option {$name} must be advertised.");
        }

        $this->assertTrue($options['outputMimeType']->isSupportedValue('application/json'));
        $this->assertTrue($options['outputMimeType']->isSupportedValue('text/plain'));
        $this->assertFalse($options['outputMimeType']->isSupportedValue('image/png'));

        foreach (array('inputModalities', 'outputModalities') as $name) {
            $declared = array();
            foreach ($options[$name]->getSupportedValues() as $modalitySet) {
                $declared[] = array_map('strval', $modalitySet);
            }
            $this->assertSame(array(array('text')), $declared, "{$name} must declare text only.");
        }
    }

    public function testNoModelClaimsImageSupport()
    {
        foreach (array('coding', 'general') as $plan) {
            foreach (ZaiModelCatalog::ids_for_plan($plan) as $modelId) {
                foreach (ZaiModelCatalog::metadata_for($modelId)->getSupportedOptions() as $option) {
                    $name = (string) $option->getName();
                    if ('inputModalities' === $name || 'outputModalities' === $name) {
                        foreach ($option->getSupportedValues() as $modalitySet) {
                            $this->assertSame(
                                array('text'),
                                array_map('strval', $modalitySet),
                                "{$modelId} must not claim image {$name} without evidence."
                            );
                        }
                    }
                }
            }
        }
    }

    public function testUnknownModelIdIsRejected()
    {
        $this->primeZaiAnthropicDiscoveryTransient();

        /*
         * GLM4 #12: the assertion must pin the SDK-typed exception the
         * directory actually throws — the file previously never imported
         * it, so expectException() bound the GLOBAL
         * \InvalidArgumentException, which the SDK exception merely
         * subclasses: a regression to an untyped throw would have kept
         * passing.
         */
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('totally-unknown');
        $this->directory()->getModelMetadata('totally-unknown');
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
        $this->assertTrue($registry->hasProvider('zai_anthropic'));

        $registry->setProviderRequestAuthentication(
            'zai_anthropic',
            new ApiKeyRequestAuthentication(FakeSecrets::apiKey())
        );

        // The provider's directory instance is cached per-class for the
        // whole process — exactly like a long-running request or WP-CLI.
        $directory = $registry->getProviderClassName('zai_anthropic')::modelMetadataDirectory();

        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'coding', 'intl');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));
        $this->assertCount(1, $this->idList($directory->listModelMetadata()));
        $this->assertSame('https://api.z.ai/api/coding/anthropic/v1/models', $this->sdkHttpAttempts()[0]['url']);

        // Same provider, same directory instance, same registry: switch the
        // endpoint and list again — the next request must hit the NEW
        // endpoint, never the warm cache from the previous one.
        $this->selectEndpoint(ZaiAnthropicPlanRegionSettings::class, 'general', 'cn');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3', 'glm-4.5')));
        $models = $directory->listModelMetadata();

        $this->assertCount(2, $models, 'The new endpoint catalog must be fetched, not the stale one.');
        $this->assertCount(2, $this->sdkHttpAttempts());
        $this->assertSame('https://open.bigmodel.cn/api/anthropic/v1/models', end(WpHarness::$sdk_http_attempts)['url']);
        $this->assertTrue($registry->hasProvider('zai_anthropic'), 'Registry state must be untouched.');
    }
}
