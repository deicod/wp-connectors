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

    public function testProbeMissMarkersAreDeletedDirectlyUnderAnObjectCache()
    {
        /*
         * GLM5 #12: the probe-miss cleanup enumerated wp_options rows via
         * a raw LIKE sweep, which finds NOTHING when a persistent object
         * cache (Redis/Memcached) backs transients — the deterministic
         * markers survived uninstall with no path that deleted them. The
         * derivable names (the current credential under every source
         * label and endpoint combination) are deleted directly through
         * the transients API now, so they go even when the option rows
         * are invisible to the sweep. The database marker below is
         * planted through the REAL availability flow, so its name is the
         * layer's own — the pin holds the mirror honest.
         */
        WpHarness::$external_object_cache = true;

        try {
            // Database-source marker via the real availability flow: an
            // inconclusive probe plants the negative-cache marker.
            $db_key = FakeSecrets::apiKey();
            update_option( 'connectors_ai_zai_api_key', $db_key );

            $availability = new \Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability();
            $availability->setHttpTransporter( \WordPress\AiClient\AiClient::defaultRegistry()->getHttpTransporter() );

            $this->queueSdkResponse( 500, array(), 'boom' );
            $this->assertTrue( $availability->isConfigured(), 'The inconclusive probe reports configured-pending.' );

            // Env-source marker planted by the same formula.
            putenv( 'ZAI_API_KEY=' . ( $env_key = FakeSecrets::apiKey() ) );
            $env_marker = 'zai_connector_zai_key_state_probe_' . md5( hash( 'sha256', 'env|zai|general|cn|' . $env_key ) );
            set_transient( $env_marker, true, 60 );

            zai_connector_zai_uninstall_site();

            $remaining = array();
            foreach ( array_keys( WpHarness::$transients ) as $transient ) {
                if ( false !== strpos( $transient, '_key_state_probe_' ) ) {
                    $remaining[] = $transient;
                }
            }

            $this->assertSame( array(), $remaining, 'Every derivable probe-miss marker must be deleted directly under an object cache: ' . wp_json_encode( $remaining ) );
        } finally {
            putenv( 'ZAI_API_KEY' );
        }
    }

    public function testDerivableProbeMissMarkersTrackTheSingleOwnerComposition()
    {
        /*
         * GLM9 #8: the deterministic sweep composes through the settings
         * owner (probe_miss_transient_ids()) — the SAME formula the
         * availability writer's binding() delegates to. Markers planted
         * by the REAL availability flows of BOTH surfaces, plus a
         * pre-GLM5 #11 'runtime'-labeled row composed by the literal
         * historical formula, must all go; if the writer and the sweeper
         * ever disagree about the composition again, this pin fails
         * where the old hand-rolled mirror would have drifted silently.
         */
        WpHarness::$external_object_cache = true;

        // zai surface: a db-source marker via the real availability flow.
        $db_key = FakeSecrets::apiKey();
        update_option( 'connectors_ai_zai_api_key', $db_key );

        $availability = new \Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability();
        $availability->setHttpTransporter( \WordPress\AiClient\AiClient::defaultRegistry()->getHttpTransporter() );

        $this->queueSdkResponse( 503, array(), 'boom' );
        $this->assertTrue( $availability->isConfigured(), 'The inconclusive probe reports configured-pending (zai).' );

        // zai_anthropic surface: its own marker via its real flow.
        $anthropic_db_key = FakeSecrets::apiKey();
        update_option( 'connectors_ai_zai_anthropic_api_key', $anthropic_db_key );

        $anthropic = new \Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability();
        $anthropic->setHttpTransporter( \WordPress\AiClient\AiClient::defaultRegistry()->getHttpTransporter() );

        $this->queueSdkResponse( 503, array(), 'boom' );
        $this->assertTrue( $anthropic->isConfigured(), 'The inconclusive probe reports configured-pending (zai_anthropic).' );

        // A pre-GLM5 #11 'runtime'-labeled row: composed by the literal
        // historical formula, which the sweep's label set must keep
        // covering (the writer normalizes the label now; the historical
        // rows were written without the normalization).
        $runtime_marker = 'zai_connector_zai_key_state_probe_' . md5( hash( 'sha256', 'runtime|zai|general|cn|' . $db_key ) );
        set_transient( $runtime_marker, true, 60 );

        zai_connector_zai_uninstall_site();

        $remaining = array();
        foreach ( array_keys( WpHarness::$transients ) as $transient ) {
            if ( false !== strpos( $transient, '_key_state_probe_' ) ) {
                $remaining[] = $transient;
            }
        }

        $this->assertSame( array(), $remaining, 'Every derivable probe-miss marker of both surfaces must be deleted through the single owner: ' . wp_json_encode( $remaining ) );
    }

    public function testADatabaseErrorDuringTheProbeSweepDoesNotWarnOrSkipTheOtherCleanups()
    {
        /*
         * GLM5 #13: real wpdb::get_col() returns NULL on a database
         * error (deletion tooling racing the uninstall, say) — the
         * foreach over null emitted a warning and aborted the sweep
         * while uninstall reported success. The null coalesce keeps the
         * surrounding deletions running; only the (failed) enumeration
         * itself is lost.
         */
        $this->seedCurrentSite();

        $real_wpdb = $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class {
            /** @var string Options table name (matches core's property). */
            public $options = 'wp_options';

            /**
             * Escapes LIKE wildcards like the harness wpdb.
             *
             * @param string $text Literal text.
             * @return string Escaped text.
             */
            public function esc_like( $text )
            {
                return addcslashes( (string) $text, '_%\\' );
            }

            /**
             * Passes the query through.
             *
             * @param string   $query Query.
             * @param mixed ...$args Values (unused).
             * @return string The query.
             */
            public function prepare( $query, ...$args )
            {
                return $query;
            }

            /**
             * Simulates the database error: null.
             *
             * @param string $query Query (unused).
             * @return null
             */
            public function get_col( $query )
            {
                return null;
            }
        };

        try {
            zai_connector_zai_uninstall_site();
        } finally {
            $GLOBALS['wpdb'] = $real_wpdb;
        }

        // No warning aborted the run: every other plugin-owned deletion
        // still happened.
        $this->assertFalse( get_option( 'zai_connector_zai_plan', false ), 'The options deletions must survive the sweep failure.' );
        $this->assertFalse( get_option( 'zai_connector_zai_anthropic_key_state', false ), 'The options deletions must survive the sweep failure.' );
        $this->assertFalse(
            get_transient( 'zai_connector_zai_models_' . md5( 'zai|coding|intl' ) ),
			'The derivable discovery-cache deletions must survive the sweep failure.'
        );
    }

    public function testUninstallCleansTheClassFreeStateEvenWhenTheOwnerChainCannotLoad()
    {
        /*
         * GLM8 #15 (verifier round on glm8-11, subprocess — the harness
         * loads the real classes, so a broken install can only be
         * proven out-of-process): the discovery-owner require chain used
         * to run BEFORE any cleanup, so a quarantined or missing src
         * file fataled with ZERO delete calls and every Delete retry
         * aborted identically. The option deletions are class-free and
         * always run now; only the class-derived discovery sweep is
         * skipped when the chain cannot load.
         */
        $repo = dirname(__DIR__, 2);
        $plugin = sys_get_temp_dir() . '/zai-uninstall-broken-' . getmypid();
        $log = $plugin . '-calls.log';

        foreach (array('', '/src', '/src/Settings', '/src/Endpoints', '/src/Metadata') as $dir) {
            @mkdir($plugin . $dir, 0777, true);
        }
        copy($repo . '/connectors/zai/uninstall.php', $plugin . '/uninstall.php');
        // The full owner chain EXCEPT one quarantined endpoint file.
        foreach (array(
            '/src/Settings/AbstractPlanRegionSettings.php',
            '/src/Settings/PlanRegionSettings.php',
            '/src/Settings/ZaiAnthropicPlanRegionSettings.php',
            '/src/Endpoints/AbstractZaiEndpoint.php',
            '/src/Endpoints/ZaiEndpoint.php',
            '/src/Metadata/ZaiDiscoveryCache.php',
        ) as $file) {
            copy($repo . '/connectors/zai' . $file, $plugin . $file);
        }
        // ZaiAnthropicEndpoint.php deliberately absent (quarantined).

        $script = <<<'PHP'
<?php
define('WP_UNINSTALL_PLUGIN', 'zai/zai.php');
$GLOBALS['__log'] = fopen(__DIR__ . '-calls.log', 'w');
function __zai_log($name) { fwrite($GLOBALS['__log'], $name . "\n"); }
function delete_option($option) { __zai_log('delete_option:' . $option); return true; }
function delete_transient($transient) { __zai_log('delete_transient:' . $transient); return true; }
function get_option($option, $default = false) { return $default; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function is_multisite() { return false; }
class wpdb_stub {
    public $options = 'wp_options';
    public function prepare($query, ...$args) { return $query; }
    public function esc_like($text) { return $text; }
    public function get_col($query) { return array(); }
}
$GLOBALS['wpdb'] = new wpdb_stub();
require __DIR__ . '/uninstall.php';
echo "UNINSTALL_COMPLETED\n";
PHP;
        file_put_contents($plugin . '/runner.php', $script);
        @unlink($log);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/runner.php') . ' 2>&1', $outputLines, $exitCode);
        $output = implode("\n", $outputLines);
        $calls = is_file($log) ? (string) file_get_contents($log) : '';

        // Cleanup runs to completion even with the chain broken.
        $this->assertSame(0, $exitCode, "The uninstall must not fatal on a partially-present install: {$output}");
        $this->assertStringContainsString('UNINSTALL_COMPLETED', $output);
        $this->assertStringContainsString('delete_option:zai_connector_zai_plan', $calls, 'The class-free option deletions always run.');
        $this->assertStringContainsString('delete_option:zai_connector_zai_anthropic_region_pending', $calls, "Both surfaces' options are deleted.");
        // The class-derived discovery sweep is skipped, not fataled.
        $this->assertStringNotContainsString('delete_transient:zai_connector_zai_models_', $calls, 'The owner-based discovery sweep is skipped when the chain cannot load.');

        // And with the full chain present, the sweep runs (the in-process
        // tests above already pin its deletions; this asserts the guard
        // does not over-suppress when files exist).
        copy($repo . '/connectors/zai/src/Endpoints/ZaiAnthropicEndpoint.php', $plugin . '/src/Endpoints/ZaiAnthropicEndpoint.php');
        @unlink($log);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/runner.php') . ' 2>&1', $outputLines2, $exitCode2);
        $calls2 = is_file($log) ? (string) file_get_contents($log) : '';

        $this->assertSame(0, $exitCode2, 'The uninstall must complete with the full chain present: ' . implode("\n", $outputLines2));
        $this->assertStringContainsString('delete_transient:zai_connector_zai_models_', $calls2, 'The discovery sweep runs when the owner chain loads.');

        // Housekeeping for repeated runs.
        foreach (array('/src/Settings', '/src/Endpoints', '/src/Metadata', '/src') as $dir) {
            @array_map('unlink', glob($plugin . $dir . '/*.php') ?: array());
            @rmdir($plugin . $dir);
        }
        @array_map('unlink', glob($plugin . '/*.php') ?: array());
        @rmdir($plugin);
        @unlink($log);
    }
}
