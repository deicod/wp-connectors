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

    public function testPlanSwitchInvalidatesPersistedKeyStateLikeRegionSwitch()
    {
        $this->bootPlugin();

        update_option('zai_connector_zai_key_state', array('binding' => 'stale'));

        do_action('update_option_' . PlanRegionSettings::OPTION_PLAN, 'coding', 'general');

        $this->assertFalse(get_option('zai_connector_zai_key_state', false), 'Validated key state must be cleared on a plan switch too.');
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

    public function testRegionSwitchInvalidatesPersistedKeyStateAndCaches()
    {
        $this->bootPlugin();

        // Plugin-owned credential-derived state (Task 1.4) and a discovery
        // cache entry (Task 1.5) for the OLD region must not survive a switch.
        update_option('zai_connector_zai_key_state', array('binding' => 'stale'));
        update_option(PlanRegionSettings::OPTION_REGION, 'intl');
        set_transient('zai_connector_zai_models_' . md5('zai|coding|intl'), array('glm-5.3'), 3600);

        do_action('update_option_' . PlanRegionSettings::OPTION_REGION, 'intl', 'cn');

        $this->assertFalse(get_option('zai_connector_zai_key_state', false), 'Validated key state must be cleared.');
        $this->assertFalse(get_transient('zai_connector_zai_models_' . md5('zai|coding|intl')), 'Old-region discovery cache must be cleared.');
    }

    public function testRegionRewriteWithSameValueDoesNotInvalidate()
    {
        update_option('zai_connector_zai_key_state', array('binding' => 'keepme'));

        do_action('update_option_' . PlanRegionSettings::OPTION_REGION, 'intl', 'intl');

        $this->assertNotFalse(get_option('zai_connector_zai_key_state', false));
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
