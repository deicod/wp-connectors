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

use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

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
}
