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
}
