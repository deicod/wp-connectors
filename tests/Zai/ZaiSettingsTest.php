<?php
/**
 * Task 1.2 — plan/region settings tests.
 *
 * Covers defaults, all four valid combinations, invalid input, unauthorized
 * submission, region-switch key invalidation, and escaped/translatable
 * rendering.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

final class ZaiSettingsTest extends WpConnectorsTestCase
{
    private const PLUGIN_FILE = __DIR__ . '/../../connectors/zai/zai.php';

    private const BOOT = '\Deicod\WpConnectors\Zai\boot';

    /**
     * Boots the plugin (installs hooks) without firing init.
     *
     * @return void
     */
    private function bootPlugin()
    {
        $this->loadPlugin(self::PLUGIN_FILE, self::BOOT);
    }

    /*
     * Defaults.
     */

    public function testDefaultsAreCodingPlanAndInternationalRegion()
    {
        $this->bootPlugin();

        $this->assertSame('coding', PlanRegionSettings::get_plan());
        $this->assertSame('intl', PlanRegionSettings::get_region());
    }

    /*
     * All four valid combinations.
     */

    public function testAllFourPlanRegionCombinationsRoundTrip()
    {
        $this->bootPlugin();

        foreach (PlanRegionSettings::PLANS as $plan) {
            foreach (PlanRegionSettings::REGIONS as $region) {
                $this->assertSame(
                    $plan,
                    PlanRegionSettings::sanitize_plan($plan),
                    "Plan {$plan} must sanitize to itself."
                );
                $this->assertSame(
                    $region,
                    PlanRegionSettings::sanitize_region($region),
                    "Region {$region} must sanitize to itself."
                );

                update_option(PlanRegionSettings::OPTION_PLAN, $plan);
                update_option(PlanRegionSettings::OPTION_REGION, $region);
                $this->assertSame($plan, PlanRegionSettings::get_plan());
                $this->assertSame($region, PlanRegionSettings::get_region());
            }
        }
    }

    /*
     * Invalid input and corrupt stored values.
     */

    public function testInvalidSubmittedValuesKeepTheStoredSelection()
    {
        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('general', PlanRegionSettings::sanitize_plan('bogus'));
        $this->assertSame('general', PlanRegionSettings::sanitize_plan(array('coding')));
        $this->assertSame('general', PlanRegionSettings::sanitize_plan(null));

        $this->assertSame('cn', PlanRegionSettings::sanitize_region('europe'));
        $this->assertSame('cn', PlanRegionSettings::sanitize_region(1337));
    }

    public function testInvalidSubmittedValuesFallBackToDefaultsWhenNothingValidIsStored()
    {
        $this->assertSame('coding', PlanRegionSettings::sanitize_plan('bogus'));
        $this->assertSame('intl', PlanRegionSettings::sanitize_region('bogus'));
    }

    public function testCorruptStoredValuesFallBackToDefaultsOnRead()
    {
        update_option(PlanRegionSettings::OPTION_PLAN, array('nested'));
        update_option(PlanRegionSettings::OPTION_REGION, 42);

        $this->assertSame('coding', PlanRegionSettings::get_plan());
        $this->assertSame('intl', PlanRegionSettings::get_region());
    }

    /*
     * Settings API registration.
     */

    public function testSettingsAreRegisteredWithTheSettingsApi()
    {
        $this->bootPlugin();
        do_action('admin_init');

        $plan = get_registered_settings()[PlanRegionSettings::OPTION_PLAN] ?? null;
        $this->assertNotNull($plan, 'Plan option must be registered.');
        $this->assertSame('zai_connector', $plan['group']);
        $this->assertFalse($plan['show_in_rest']);
        $this->assertSame('coding', $plan['default']);

        $region = get_registered_settings()[PlanRegionSettings::OPTION_REGION] ?? null;
        $this->assertNotNull($region, 'Region option must be registered.');
        $this->assertSame('zai_connector', $region['group']);
        $this->assertFalse($region['show_in_rest']);
        $this->assertSame('intl', $region['default']);
    }

    /*
     * Authorization: capability + nonce guard.
     */

    public function testUnauthorizedSubmissionIsStrippedByTheGuard()
    {
        $this->bootPlugin();
        update_option(PlanRegionSettings::OPTION_PLAN, 'coding');

        $this->asAnonymous();
        $_POST = array(
            'option_page' => 'zai_connector',
            PlanRegionSettings::OPTION_PLAN => 'general',
            PlanRegionSettings::OPTION_REGION => 'cn',
        );

        do_action('admin_init');

        $this->assertArrayNotHasKey(PlanRegionSettings::OPTION_PLAN, $_POST);
        $this->assertArrayNotHasKey(PlanRegionSettings::OPTION_REGION, $_POST);
        $this->assertSame('coding', PlanRegionSettings::get_plan(), 'Option must be unchanged.');
        $this->assertNotEmpty(WpHarness::$settings_errors);
    }

    public function testAnArrayOptionPageValueIsIgnoredByTheGuard()
    {
        /*
         * GLM3 #8: a mangled 'option_page[]=x' POST from any logged-in
         * user must never reach sanitize_key() — the guard's is_string
         * check ignores the request as not-our-form (no strip, no
         * notice), and the sanitize_key stub now mirrors core's
         * is_scalar() semantics, so it would answer '' for the array
         * even if it did.
         */
        $this->bootPlugin();
        $this->asAnonymous();
        $_POST = array(
            'option_page' => array('zai_connector'),
            PlanRegionSettings::OPTION_PLAN => 'general',
        );

        do_action('admin_init');

        $this->assertSame(array('zai_connector'), $_POST['option_page'], 'Not our form: the group identifier is untouched.');
        $this->assertArrayHasKey(PlanRegionSettings::OPTION_PLAN, $_POST, 'Not our form: the guard must not strip anything.');
        $this->assertSame(array(), WpHarness::$settings_errors, 'No unauthorized notice for a request that is not our form.');
    }

    public function testTheUnauthorizedNoticeIsEmittedOncePerSave()
    {
        /*
         * GLM2 #8: both provider settings classes hook their guard on the
         * shared admin_init priority for the SHARED option group, so one
         * unauthorized save ran the emission once per provider — two
         * byte-identical 'zai_connector_unauthorized' settings errors
         * (latent while core's options.php wp_dies before render, but any
         * path that renders settings errors would print the notice twice).
         * The emission is idempotent now: the strip still runs under every
         * guard, the notice is added exactly once.
         */
        $this->bootPlugin();
        $this->asAnonymous();
        $_POST = array(
            'option_page' => 'zai_connector',
            PlanRegionSettings::OPTION_PLAN => 'general',
            \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::OPTION_PLAN => 'general',
        );

        do_action('admin_init');

        $this->assertSame(
            1,
            count(array_keys(array_column(WpHarness::$settings_errors, 'code'), 'zai_connector_unauthorized')),
            'One unauthorized save must record exactly one unauthorized settings error, not one per provider guard.'
        );
    }

    public function testAForeignSettingsErrorWithTheSameCodeDoesNotSuppressTheNotice()
    {
        /*
         * Verifier round on GLM2 #8: the dedup read is get_settings_errors()
         * scoped to THIS group's setting slug (the core getter — its
         * display-function sibling settings_errors() echoes HTML and
         * returns void in real WordPress). A same-CODE error registered
         * under a different setting (another plugin's coincidental reuse)
         * is outside the scope and must not suppress this group's notice.
         */
        add_settings_error('some_other_plugin_group', 'zai_connector_unauthorized', 'unrelated notice');

        $this->bootPlugin();
        $this->asAnonymous();
        $_POST = array(
            'option_page' => 'zai_connector',
            PlanRegionSettings::OPTION_PLAN => 'general',
        );

        do_action('admin_init');

        $ours = array_filter(
            get_settings_errors('zai_connector'),
            static function ($error) {
                return is_array($error) && 'zai_connector_unauthorized' === ($error['code'] ?? null);
            }
        );

        $this->assertCount(1, $ours, 'The dedup scope is this group alone: exactly one notice of ours is recorded despite the foreign same-code error.');
    }

    public function testUnauthorizedSubmissionStripsEveryGroupOption()
    {
        /*
         * Code-review GLM1 #10: the capability-failure path stripped only
         * the plan/region pair, leaving the group's third key — the
         * debug-logging flag — in $_POST, contradicting the guard's
         * documented 'nothing of ours can be persisted by any other write
         * path in the same request' contract. The guard now strips EVERY
         * option registered under the plugin's option group, enumerated
         * from the group registration.
         */
        $this->bootPlugin();
        $this->asAnonymous();
        $_POST = array(
            'option_page' => 'zai_connector',
            PlanRegionSettings::OPTION_PLAN => 'general',
            PlanRegionSettings::OPTION_REGION => 'cn',
            \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::OPTION_PLAN => 'general',
            \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::OPTION_REGION => 'cn',
            \Deicod\WpConnectors\Zai\Support\DebugLogger::OPTION_ENABLED => '1',
        );

        do_action('admin_init');

        foreach (array(
            PlanRegionSettings::OPTION_PLAN,
            PlanRegionSettings::OPTION_REGION,
            \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::OPTION_PLAN,
            \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::OPTION_REGION,
            \Deicod\WpConnectors\Zai\Support\DebugLogger::OPTION_ENABLED,
        ) as $stripped) {
            $this->assertArrayNotHasKey($stripped, $_POST, "The guard must strip {$stripped} from the request.");
        }

        // Only OUR keys go: the group identifier itself (and anything
        // unrelated) must survive, or the enumeration would over-strip.
        $this->assertSame('zai_connector', $_POST['option_page']);

        $this->assertNotEmpty(WpHarness::$settings_errors);
    }

    public function testSettingsInvalidationClearsTheNegativeDiscoveryMarkers()
    {
        /*
         * GLM1 #6 verifier nit: the directories' NEGATIVE_CACHE_SUFFIX
         * ('_miss') is mirrored LITERALLY by this SDK-free invalidation —
         * this pin keeps the mirror honest (deleting the sweep would fail
         * the suite).
         */
        $miss = PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl') . '_miss';
        set_transient($miss, true, 60);

        PlanRegionSettings::handle_settings_change('coding', 'general');

        $this->assertFalse(get_transient($miss), 'A plan change must clear the negative discovery marker with the positive cache.');
    }

    public function testDistinctCorruptArrayPayloadsStillInvalidate()
    {
        /*
         * GLM5 #9: the (string)-cast comparison raised an Array-to-string
         * warning per side and equated two DIFFERENT arrays
         * ('Array' === 'Array'), silently skipping the state and
         * discovery-cache invalidation a plan change must perform. The
         * comparison is type-aware now: distinct arrays are CHANGED (no
         * coercion, no warning).
         */
        update_option(PlanRegionSettings::STATE_OPTION, array('binding' => 'stale'), false);
        $cache = PlanRegionSettings::CACHE_PREFIX . md5('zai|coding|intl');
        set_transient($cache, array('glm-5.3'), 3600);

        PlanRegionSettings::handle_settings_change(array('corrupt' => 'x'), array('corrupt' => 'y'));

        $this->assertNull(get_option(PlanRegionSettings::STATE_OPTION, null), 'A change between corrupt arrays must still clear the validated state.');
        $this->assertFalse(get_transient($cache), 'A change between corrupt arrays must still clear the discovery cache.');
    }

    public function testIdenticalArrayPayloadsSkipInvalidation()
    {
        // GLM5 #9: strict identity — an unchanged (if corrupt) array value
        // is NOT a change; the invalidation stays skipped without warnings.
        update_option(PlanRegionSettings::STATE_OPTION, array('binding' => 'keep'), false);

        PlanRegionSettings::handle_settings_change(array('same' => 1), array('same' => 1));

        $this->assertSame(array('binding' => 'keep'), get_option(PlanRegionSettings::STATE_OPTION), 'Identical payloads must skip the invalidation.');
    }

    public function testAuthorizedUserWithoutValidNonceIsLeftToCoreEnforcement()
    {
        $this->bootPlugin();
        $this->asAdministrator();
        $_POST = array(
            'option_page' => 'zai_connector',
            '_wpnonce' => 'definitely-not-a-nonce',
            PlanRegionSettings::OPTION_PLAN => 'general',
        );

        do_action('admin_init');

        // Nonce enforcement is terminal in real WordPress (check_admin_referer
        // wp_dies); the guard records the failure and leaves the rest to core.
        $this->assertNotEmpty(WpHarness::$doing_it_wrong);
        $this->assertArrayNotHasKey('zai_connector_unauthorized', array_column(WpHarness::$settings_errors, 'code'));
    }

    public function testPlanSwitchInvalidatesStateButKeepsTheStoredKey()
    {
        $this->bootPlugin();

        update_option('zai_connector_zai_key_state', array('binding' => 'stale'));
        update_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, 'plan-shared-key');

        do_action('update_option_' . PlanRegionSettings::OPTION_PLAN, 'coding', 'general');

        $this->assertFalse(get_option('zai_connector_zai_key_state', false), 'Validated key state must be cleared on a plan switch too.');
        $this->assertSame(
            'plan-shared-key',
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION),
            'A plan change stays on the same account: the stored key must be kept.'
        );
    }

    public function testAuthorizedUserWithValidNoncePassesTheGuard()
    {
        $this->bootPlugin();
        $this->asAdministrator();
        $this->withValidNonce('zai_connector-options');
        $_POST['option_page'] = 'zai_connector';
        $_POST[PlanRegionSettings::OPTION_PLAN] = 'general';

        do_action('admin_init');

        $this->assertSame('general', $_POST[PlanRegionSettings::OPTION_PLAN] ?? null);
        $this->assertSame(array(), WpHarness::$settings_errors);
    }

    public function testGuardIgnoresForeignOptionGroups()
    {
        $this->bootPlugin();
        $this->asAnonymous();
        $_POST = array('option_page' => 'some_other_group');

        do_action('admin_init');

        $this->assertSame('some_other_group', $_POST['option_page']);
        $this->assertSame(array(), WpHarness::$settings_errors);
    }

    /*
     * Region-switch key invalidation.
     */

    public function testRegionSwitchClearsStoredKeyStateCachesAndTheStoredKey()
    {
        $this->bootPlugin();

        // Plugin-owned credential-derived state (Task 1.4), a discovery
        // cache entry (Task 1.5), and the STORED key itself: the regions use
        // separate accounts (SPEC §3.3), so none of the old region's
        // credential material may survive a switch.
        update_option('zai_connector_zai_key_state', array('binding' => 'stale'));
        update_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, 'intl-key');
        update_option(PlanRegionSettings::OPTION_REGION, 'intl');
        set_transient('zai_connector_zai_models_' . md5('zai|coding|intl'), array('glm-5.3'), 3600);

        do_action('update_option_' . PlanRegionSettings::OPTION_REGION, 'intl', 'cn');

        $this->assertFalse(get_option('zai_connector_zai_key_state', false), 'Validated key state must be cleared.');
        $this->assertFalse(
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, false),
            'The stored key belongs to the OLD region\'s account and must be cleared (Task 1.2).'
        );
        $this->assertFalse(get_transient('zai_connector_zai_models_' . md5('zai|coding|intl')), 'Old-region discovery cache must be cleared.');
        $this->assertFalse(
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::REGION_PENDING_OPTION, false),
            'Without an env/constant credential nothing can ride the switch: no pending flag.'
        );
    }

    public function testFirstPersistedRegionChangeOnAFreshInstallRunsTheFullInvalidation()
    {
        $this->bootPlugin();

        // Fresh install: no region row exists (the default is served from the
        // registration), so the first save travels through core's
        // add_option() — update_option() delegates to it — which fires
        // add_option_{$option}, NOT update_option_{$option}.
        $this->assertFalse(get_option(PlanRegionSettings::OPTION_REGION, false), 'Fresh install must start without a region row.');
        update_option('zai_connector_zai_key_state', array('binding' => 'stale'));
        update_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, 'intl-key');
        set_transient('zai_connector_zai_models_' . md5('zai|coding|intl'), array('glm-5.3'), 3600);

        add_option(PlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('cn', PlanRegionSettings::get_region());
        $this->assertFalse(get_option('zai_connector_zai_key_state', false), 'The initial persisted region change must clear the validated key state.');
        $this->assertFalse(
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, false),
            'The initial persisted region change must clear the old region\'s stored key.'
        );
        $this->assertFalse(get_transient('zai_connector_zai_models_' . md5('zai|coding|intl')), 'The initial persisted region change must clear the old region\'s discovery cache.');
        $this->assertFalse(
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::REGION_PENDING_OPTION, false),
            'Without an env/constant credential nothing can ride the initial switch: no pending flag.'
        );
    }

    public function testFirstPersistedDebugFlagSaveClearsTheLogOnAFreshRow()
    {
        /*
         * GLM5 #14: the add_option fresh-install companions covered
         * plan/region only — with the debug option row missing (deleted
         * out-of-band while log entries persist), the first persisted
         * save of the unchecked flag fired add_option_{option} with no
         * handler and the promised 'disable and save to clear' never
         * ran.
         */
        $this->bootPlugin();

        delete_option(\Deicod\WpConnectors\Zai\Support\DebugLogger::OPTION_ENABLED);
        update_option(\Deicod\WpConnectors\Zai\Support\DebugLogger::OPTION_LOG, array(array(
            'method' => 'GET', 'url' => 'https://api.z.ai/x', 'status' => 200, 'duration_ms' => 1.0, 'at' => 1,
        )), false);

        add_option(\Deicod\WpConnectors\Zai\Support\DebugLogger::OPTION_ENABLED, '0');

        $this->assertSame(
            array(),
            \Deicod\WpConnectors\Zai\Support\DebugLogger::entries(),
            'The first persisted save of a disabled flag must clear the log.'
        );
    }

    public function testSavingTheDefaultRegionOnAFreshInstallIsNotASwitch()
    {
        $this->bootPlugin();

        update_option('zai_connector_zai_key_state', array('binding' => 'keepme'));
        update_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, 'keepme-too');

        // Persisting the default while no row exists behaves like a
        // same-value update: no account switch happened, nothing to clear.
        add_option(PlanRegionSettings::OPTION_REGION, 'intl');

        $this->assertNotFalse(get_option('zai_connector_zai_key_state', false));
        $this->assertNotFalse(
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, false),
            'Saving the default region is not a switch: the key must be kept.'
        );
    }

    public function testRegionSwitchWithAPreExistingOptionRowStillInvalidates()
    {
        $this->bootPlugin();

        // Regression: once the row exists, updates keep firing the classic
        // update_option_{$option} hook and must invalidate exactly as before.
        update_option(PlanRegionSettings::OPTION_REGION, 'intl');
        update_option('zai_connector_zai_key_state', array('binding' => 'stale'));
        update_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, 'intl-key');

        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('cn', PlanRegionSettings::get_region());
        $this->assertFalse(get_option('zai_connector_zai_key_state', false), 'Validated key state must be cleared.');
        $this->assertFalse(
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, false),
            'The stored key must be cleared on the update path too.'
        );
    }

    public function testRegionSwitchMarksAnEnvCredentialPendingValidationForTheNewRegion()
    {
        $this->bootPlugin();
        $envKey = FakeSecrets::apiKey();

        try {
            putenv('ZAI_API_KEY=' . $envKey);
            update_option(PlanRegionSettings::OPTION_REGION, 'intl');

            do_action('update_option_' . PlanRegionSettings::OPTION_REGION, 'intl', 'cn');

            // The riding env credential is immutable, so it is marked
            // pending a DEFINITIVE validation, bound to the new region and
            // a fingerprint of the key — never the key itself.
            $flag = get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::REGION_PENDING_OPTION);
            $this->assertIsArray($flag, 'The env credential effective across the switch must be marked pending.');
            $this->assertSame('cn', $flag['region']);
            $this->assertSame(hash('sha256', $envKey), $flag['fingerprint']);
            $this->assertOptionNotPlaintext(
                \Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::REGION_PENDING_OPTION,
                $envKey,
                'The pending flag must store a fingerprint, never the key.'
            );
        } finally {
            putenv('ZAI_API_KEY');
        }
    }

    public function testTheCredentialLadderIsTheOneSharedImplementation()
    {
        /*
         * GLM7 #17: the env→constant resolution sequence was hand-rolled
         * three times (this class's mark_region_switch_pending() and the
         * availability base's effective_key()/key_source()). One shared
         * env_constant_ladder() in the SDK-free settings layer serves
         * every consumer; this pins its contract: ordered non-empty
         * rungs, empty-string sources excluded, empty when none exists.
         */
        try {
            putenv('ZAI_API_KEY=');
            $this->assertSame(array(), PlanRegionSettings::env_constant_ladder(), 'No env value and no constant: the ladder is empty.');

            $envKey = FakeSecrets::apiKey();
            putenv('ZAI_API_KEY=' . $envKey);
            $this->assertSame(array('env' => $envKey), PlanRegionSettings::env_constant_ladder(), 'The env rung carries its source label.');

            $otherKey = FakeSecrets::apiKey();
            putenv('ZAI_ANTHROPIC_API_KEY=' . $otherKey);
            $this->assertSame(
                array('env' => $otherKey),
                ZaiAnthropicPlanRegionSettings::env_constant_ladder(),
                'Each provider reads its OWN env name through the same shared implementation.'
            );
            $this->assertSame(
                array('env' => $envKey),
                PlanRegionSettings::env_constant_ladder(),
                'The sibling provider\'s env value must not leak into this provider\'s ladder.'
            );
        } finally {
            putenv('ZAI_API_KEY');
            putenv('ZAI_ANTHROPIC_API_KEY');
        }
    }

    public function testRegionRewriteWithSameValueDoesNotInvalidate()
    {
        $this->bootPlugin();

        update_option('zai_connector_zai_key_state', array('binding' => 'keepme'));
        update_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, 'keepme-too');

        do_action('update_option_' . PlanRegionSettings::OPTION_REGION, 'intl', 'intl');

        $this->assertNotFalse(get_option('zai_connector_zai_key_state', false));
        $this->assertNotFalse(
            get_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, false),
            'A same-value rewrite is not a region switch: the key must be kept.'
        );
    }

    /*
     * Escaped, translatable rendering.
     */

    public function testFieldRenderingIsEscapedAndReflectsCurrentSelection()
    {
        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        ob_start();
        PlanRegionSettings::render_plan_field();
        $planField = (string) ob_get_clean();

        ob_start();
        PlanRegionSettings::render_region_field();
        $regionField = (string) ob_get_clean();

        $this->assertStringContainsString('<select', $planField);
        $this->assertStringContainsString('value="coding"', $planField);
        $this->assertStringContainsString('value="general"', $planField);
        $this->assertStringContainsString('value="general" selected=\'selected\'', $planField);
        $this->assertStringNotContainsString('<script', $planField);

        $this->assertStringContainsString('value="intl"', $regionField);
        $this->assertStringContainsString('value="cn"', $regionField);
        $this->assertStringContainsString('value="cn" selected=\'selected\'', $regionField);
    }

    public function testSectionDescriptionExplainsAccountAndBillingDistinction()
    {
        ob_start();
        PlanRegionSettings::render_section_description();
        $description = (string) ob_get_clean();

        $this->assertStringContainsString('pay-as-you-go', $description);
        $this->assertStringContainsString('separate accounts and separate API keys', $description);
        $this->assertStringNotContainsString('<script', $description);
    }

    public function testSettingsPageIsRegisteredForAdministratorsOnly()
    {
        $this->bootPlugin();

        $this->asAnonymous();
        do_action('admin_menu');
        $this->assertArrayNotHasKey('zai-connector', WpHarness::$admin_pages, 'Page must not register for unprivileged users.');

        $this->asAdministrator();
        do_action('admin_menu');
        $page = WpHarness::$admin_pages['zai-connector'] ?? null;
        $this->assertNotNull($page);
        $this->assertSame('manage_options', $page['capability']);
        $this->assertIsCallable($page['callback']);
    }

    public function testPageRenderRequiresManageOptions()
    {
        $this->bootPlugin();
        $this->asAnonymous();

        ob_start();
        PlanRegionSettings::render_page();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output, 'Unprivileged users must get no settings markup.');
    }
    public function testIdentifierConstantsAreChildOwnedNotInheritedDefaults()
    {
        /*
         * GLM6 #12: the shared settings base ships NO provider's
         * identifier constants (option names, section id, label, the
         * SDK-free invalidation identifiers, the cache prefix/scope) —
         * a future child forgetting a declaration must fail LOUD
         * (undefined constant), never silently read and write the zai
         * provider's options under runtime-dead base defaults. Only
         * genuinely shared structure stays in the base.
         */
        $identifiers = array(
            'OPTION_PLAN',
            'OPTION_REGION',
            'SECTION_ID',
            'PROVIDER_LABEL',
            'STATE_OPTION',
            'REGION_PENDING_OPTION',
            'KEY_OPTION',
            'KEY_ENV_NAME',
            'CACHE_PREFIX',
            'CACHE_SCOPE',
            'ENDPOINT_CLASS',
        );

        $base = array();
        foreach ((new \ReflectionClass(AbstractPlanRegionSettings::class))->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === AbstractPlanRegionSettings::class) {
                $base[] = $constant->getName();
            }
        }

        $this->assertSame(array(), array_values(array_intersect($identifiers, $base)), 'The settings base must not carry provider identifiers.');

        foreach (array(PlanRegionSettings::class, ZaiAnthropicPlanRegionSettings::class) as $settings) {
            $declared = array();
            foreach ((new \ReflectionClass($settings))->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $settings) {
                    $declared[] = $constant->getName();
                }
            }

            $this->assertSame(array(), array_values(array_diff($identifiers, $declared)), "{$settings} must declare every identifier constant.");
        }
    }
}
