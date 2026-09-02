<?php
/**
 * Uninstall cleanup tests (uninstall.php).
 *
 * Covers single-site removal of plugin-owned options/transients, the
 * core-owned key option being left in place, and network uninstall
 * cleaning EVERY site's data (options/transients are per-site) with the
 * blog context restored — including networks larger than one site batch.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

final class ZaiUninstallTest extends WpConnectorsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        // The file runs its single-site cleanup once at load (against the
        // empty, freshly reset harness state — a no-op); the functions it
        // defines are what the tests drive.
        require_once dirname( __DIR__, 2 ) . '/connectors/zai/uninstall.php';
    }

    /**
     * Seeds every plugin-owned option plus a discovery transient on the
     * CURRENT site, plus decoy core-owned key options that must survive.
     *
     * @param string $decoy_value Value for the core-owned key options.
     * @return void
     */
    private function seedCurrentSite( $decoy_value = 'core-owned-key' )
    {
        update_option( 'zai_connector_zai_plan', 'general' );
        update_option( 'zai_connector_zai_region', 'cn' );
        update_option( 'zai_connector_zai_debug', '1' );
        update_option( 'zai_connector_zai_debug_log', 'line' );
        update_option( 'zai_connector_zai_key_state', array( 'binding' => 'x' ) );
        update_option( 'zai_connector_zai_region_pending', array( 'region' => 'cn', 'fingerprint' => 'x' ) );
        set_transient( 'zai_connector_zai_models_' . md5( 'zai|coding|intl' ), array( 'glm-5.3' ), 3600 );
        set_transient( 'zai_connector_zai_models_' . md5( 'zai|coding|intl' ) . '_miss', true, 60 );
        // GLM2 #7: probe-miss markers embed md5(sha256(binding)) — seeded
        // with arbitrary hash tails, exactly like production rows whose
        // keys the uninstaller never sees.
        set_transient( 'zai_connector_zai_key_state_probe_' . md5( 'any-binding-hash' ), true, 60 );

        update_option( 'zai_connector_zai_anthropic_plan', 'general' );
        update_option( 'zai_connector_zai_anthropic_region', 'cn' );
        update_option( 'zai_connector_zai_anthropic_key_state', array( 'binding' => 'x' ) );
        update_option( 'zai_connector_zai_anthropic_region_pending', array( 'region' => 'cn', 'fingerprint' => 'x' ) );
        set_transient( 'zai_connector_zai_anthropic_models_' . md5( 'zai_anthropic|coding|intl' ), array( 'glm-5.3' ), 3600 );
        set_transient( 'zai_connector_zai_anthropic_models_' . md5( 'zai_anthropic|coding|intl' ) . '_miss', true, 60 );
        set_transient( 'zai_connector_zai_anthropic_key_state_probe_' . md5( 'another-binding-hash' ), true, 60 );

        update_option( 'connectors_ai_zai_api_key', $decoy_value );
        update_option( 'connectors_ai_zai_anthropic_api_key', $decoy_value );
    }

    /**
     * Asserts the current site holds no plugin-owned data, and that the
     * core-owned key options survived.
     *
     * @return void
     */
    private function assertCurrentSiteClean()
    {
        foreach ( array(
            'zai_connector_zai_plan',
            'zai_connector_zai_region',
            'zai_connector_zai_debug',
            'zai_connector_zai_debug_log',
            'zai_connector_zai_key_state',
            'zai_connector_zai_region_pending',
            'zai_connector_zai_anthropic_plan',
            'zai_connector_zai_anthropic_region',
            'zai_connector_zai_anthropic_key_state',
            'zai_connector_zai_anthropic_region_pending',
        ) as $option ) {
            $this->assertFalse( get_option( $option, false ), "{$option} must be removed on uninstall." );
        }
        $this->assertFalse(
            get_transient( 'zai_connector_zai_models_' . md5( 'zai|coding|intl' ) ),
            'Discovery transients must be removed on uninstall.'
        );
        $this->assertFalse(
            get_transient( 'zai_connector_zai_models_' . md5( 'zai|coding|intl' ) . '_miss' ),
            'The negative discovery markers must be removed too (GLM1 #6).'
        );
        $this->assertFalse(
            get_transient( 'zai_connector_zai_anthropic_models_' . md5( 'zai_anthropic|coding|intl' ) ),
            'The second provider\'s discovery transients must be removed on uninstall.'
        );
        $this->assertFalse(
            get_transient( 'zai_connector_zai_anthropic_models_' . md5( 'zai_anthropic|coding|intl' ) . '_miss' ),
            'The second provider\'s negative discovery markers must be removed too (GLM1 #6).'
        );
        $this->assertFalse(
            get_transient( 'zai_connector_zai_key_state_probe_' . md5( 'any-binding-hash' ) ),
            'The availability probe-miss markers must be removed on uninstall (GLM2 #7).'
        );
        $this->assertFalse(
            get_transient( 'zai_connector_zai_anthropic_key_state_probe_' . md5( 'another-binding-hash' ) ),
            'The second provider\'s probe-miss markers must be removed too (GLM2 #7).'
        );
        $this->assertNotFalse(
            get_option( 'connectors_ai_zai_api_key', false ),
            'The core-owned key option is left to core/the user (record 0004, rule 4).'
        );
        $this->assertNotFalse(
            get_option( 'connectors_ai_zai_anthropic_api_key', false ),
            'The core-owned zai_anthropic key option is left to core/the user too.'
        );
    }

    public function testSingleSiteUninstallRemovesPluginDataOnly()
    {
        $this->seedCurrentSite();

        // Verifier round on GLM2 #7: a decoy row differing by ONE
        // character where the real marker has an underscore must SURVIVE —
        // pinning that the enumeration honors esc_like()'s literal
        // underscores instead of matching them as single-char wildcards.
        $decoy = 'zai_connector_zaiXkey_state_probe_' . md5( 'decoy' );
        set_transient( $decoy, true, 60 );

        zai_connector_zai_uninstall_site();
        zai_connector_zai_uninstall_network(); // Single site: no-op.

        $this->assertCurrentSiteClean();
        $this->assertSame( 1, get_current_blog_id() );
        $this->assertTrue(
            get_transient( $decoy ),
            'The prefix sweep must match literal underscores only — a one-character-off decoy row is not plugin-owned and must survive.'
        );
    }

    public function testNetworkUninstallCleansEverySiteAndRestoresContext()
    {
        WpHarness::$is_multisite = true;
        WpHarness::$sites = array( 2, 3 );

        $this->seedCurrentSite( 'key-site-1' );
        switch_to_blog( 2 );
        $this->seedCurrentSite( 'key-site-2' );
        restore_current_blog();
        switch_to_blog( 3 );
        $this->seedCurrentSite( 'key-site-3' );
        restore_current_blog();

        zai_connector_zai_uninstall_site();
        zai_connector_zai_uninstall_network();

        $this->assertCurrentSiteClean();
        $this->assertSame( 1, get_current_blog_id(), 'The blog context must be restored after the network sweep.' );
        $this->assertSame( array(), WpHarness::$blog_stack, 'The switch_to_blog stack must be balanced.');

        switch_to_blog( 2 );
        $this->assertCurrentSiteClean();
        $this->assertSame( 'key-site-2', get_option( 'connectors_ai_zai_api_key' ), 'Other sites keep their core-owned key option.' );
        restore_current_blog();

        switch_to_blog( 3 );
        $this->assertCurrentSiteClean();
        restore_current_blog();
    }

    public function testNetworkUninstallHandlesMoreSitesThanOneBatch()
    {
        // 205 extra sites force three batches of 100 — the batching loop must
        // reach past the first batch (a naive single get_sites() call is
        // capped by core's default number and would miss the tail). The
        // harness get_sites() ignores 'paged' exactly like core's
        // WP_Site_Query, so passing here PROVES the loop advances through
        // the supported 'offset' argument: a 'paged' regression would
        // re-return the first 100 sites forever (the harness fails that
        // non-advancing loop fast) and the tail blogs would never be cleaned.
        WpHarness::$is_multisite = true;
        WpHarness::$sites = range( 2, 206 );

        foreach ( array( 2, 101, 102, 206 ) as $blog_id ) {
            switch_to_blog( $blog_id );
            update_option( 'zai_connector_zai_plan', 'coding' );
            update_option( 'zai_connector_zai_key_state', array( 'binding' => 'x' ) );
            restore_current_blog();
        }

        zai_connector_zai_uninstall_site();
        zai_connector_zai_uninstall_network();

        // 206 sites (blog 1 + 205) in batches of 100: offsets 0, 100, 200,
        // then the 6-row short batch ends the sweep — no fifth query.
        $this->assertSame(
            array( 0, 100, 200 ),
            array_map( 'intval', array_column( WpHarness::$get_sites_queries, 'offset' ) ),
            'The batch loop must advance the offset by the batch size.'
        );
        $this->assertSame(
            array( 100, 100, 100 ),
            array_map( 'intval', array_column( WpHarness::$get_sites_queries, 'number' ) ),
            'Every batch must be bounded by the batch size.'
        );

        foreach ( array( 1, 2, 101, 102, 206 ) as $blog_id ) {
            switch_to_blog( $blog_id );
            $this->assertFalse( get_option( 'zai_connector_zai_plan', false ), "Blog {$blog_id} plan option must be removed." );
            $this->assertFalse( get_option( 'zai_connector_zai_key_state', false ), "Blog {$blog_id} key state must be removed." );
            restore_current_blog();
        }

        $this->assertSame( 1, get_current_blog_id() );
    }

    public function testNetworkUninstallTerminatesWhenTheSiteCountDividesEvenly()
    {
        // 199 extra sites = 200 total: two FULL batches, then one empty
        // batch must end the sweep (a short batch terminates the loop) —
        // an offset past the end simply returns no rows.
        WpHarness::$is_multisite = true;
        WpHarness::$sites = range( 2, 200 );

        switch_to_blog( 200 );
        update_option( 'zai_connector_zai_plan', 'coding' );
        restore_current_blog();

        zai_connector_zai_uninstall_site();
        zai_connector_zai_uninstall_network();

        $this->assertSame(
            array( 0, 100, 200 ),
            array_map( 'intval', array_column( WpHarness::$get_sites_queries, 'offset' ) ),
            'A full final batch must be followed by exactly one terminating empty query.'
        );

        switch_to_blog( 200 );
        $this->assertFalse( get_option( 'zai_connector_zai_plan', false ), 'The last site of the final full batch must be cleaned.' );
        restore_current_blog();

        $this->assertSame( 1, get_current_blog_id() );
    }

    public function testNetworkCleanupIsAnIdempotentNoOpOnCleanSites()
    {
        WpHarness::$is_multisite = true;
        WpHarness::$sites = array( 7 );

        zai_connector_zai_uninstall_site();
        zai_connector_zai_uninstall_network();

        $this->assertSame( 1, get_current_blog_id() );
        $this->assertFalse( restore_current_blog(), 'No dangling switch may remain.' );
    }
}
