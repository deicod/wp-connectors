<?php
/**
 * Task 2.1 — zai_anthropic plan/region settings tests.
 *
 * Covers the second provider's own options (defaults, enum round-trip,
 * Settings API registration), the no-bleed guarantee between the two
 * providers' selections, region-switch key invalidation scoped to THIS
 * provider's key/state/caches, and escaped rendering of the second section.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

final class ZaiAnthropicSettingsTest extends WpConnectorsTestCase
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
     * Defaults and option identity.
     */

    public function testDefaultsAreCodingPlanAndInternationalRegion()
    {
        $this->bootPlugin();

        $this->assertSame('coding', ZaiAnthropicPlanRegionSettings::get_plan());
        $this->assertSame('intl', ZaiAnthropicPlanRegionSettings::get_region());
    }

    public function testTheTwoProvidersUseDistinctOptions()
    {
        $this->assertNotSame(PlanRegionSettings::OPTION_PLAN, ZaiAnthropicPlanRegionSettings::OPTION_PLAN);
        $this->assertNotSame(PlanRegionSettings::OPTION_REGION, ZaiAnthropicPlanRegionSettings::OPTION_REGION);
        $this->assertSame('zai_connector_zai_anthropic_plan', ZaiAnthropicPlanRegionSettings::OPTION_PLAN);
        $this->assertSame('zai_connector_zai_anthropic_region', ZaiAnthropicPlanRegionSettings::OPTION_REGION);
    }

    public function testSettingsAreRegisteredWithTheSettingsApi()
    {
        $this->bootPlugin();
        do_action('admin_init');

        $plan = get_registered_settings()[ZaiAnthropicPlanRegionSettings::OPTION_PLAN] ?? null;
        $this->assertNotNull($plan, 'zai_anthropic plan option must be registered.');
        $this->assertSame('zai_connector', $plan['group']);
        $this->assertFalse($plan['show_in_rest']);
        $this->assertSame('coding', $plan['default']);

        $region = get_registered_settings()[ZaiAnthropicPlanRegionSettings::OPTION_REGION] ?? null;
        $this->assertNotNull($region, 'zai_anthropic region option must be registered.');
        $this->assertSame('zai_connector', $region['group']);
        $this->assertSame('intl', $region['default']);
    }

    /*
     * Enum handling (same contract as the zai provider's settings).
     */

    public function testAllFourPlanRegionCombinationsRoundTrip()
    {
        foreach (ZaiAnthropicPlanRegionSettings::PLANS as $plan) {
            foreach (ZaiAnthropicPlanRegionSettings::REGIONS as $region) {
                $this->assertSame($plan, ZaiAnthropicPlanRegionSettings::sanitize_plan($plan));
                $this->assertSame($region, ZaiAnthropicPlanRegionSettings::sanitize_region($region));

                update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, $plan);
                update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, $region);
                $this->assertSame($plan, ZaiAnthropicPlanRegionSettings::get_plan());
                $this->assertSame($region, ZaiAnthropicPlanRegionSettings::get_region());
            }
        }
    }

    public function testInvalidAndCorruptValuesFallBackSafely()
    {
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'general');
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('general', ZaiAnthropicPlanRegionSettings::sanitize_plan('bogus'));
        $this->assertSame('cn', ZaiAnthropicPlanRegionSettings::sanitize_region(array('intl')));

        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, array('nested'));
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 42);
        $this->assertSame('coding', ZaiAnthropicPlanRegionSettings::get_plan());
        $this->assertSame('intl', ZaiAnthropicPlanRegionSettings::get_region());
    }

    /*
     * No bleed between the two providers' selections.
     */

    public function testChangingTheAnthropicSelectionNeverTouchesTheZaiSelection()
    {
        $this->bootPlugin();

        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'general');
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('general', ZaiAnthropicPlanRegionSettings::get_plan());
        $this->assertSame('cn', ZaiAnthropicPlanRegionSettings::get_region());
        $this->assertSame('coding', PlanRegionSettings::get_plan(), 'The zai plan must be untouched.');
        $this->assertSame('intl', PlanRegionSettings::get_region(), 'The zai region must be untouched.');
    }

    public function testChangingTheZaiSelectionNeverTouchesTheAnthropicSelection()
    {
        $this->bootPlugin();

        update_option(PlanRegionSettings::OPTION_PLAN, 'general');
        update_option(PlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('coding', ZaiAnthropicPlanRegionSettings::get_plan(), 'The zai_anthropic plan must be untouched.');
        $this->assertSame('intl', ZaiAnthropicPlanRegionSettings::get_region(), 'The zai_anthropic region must be untouched.');
    }

    /*
     * Authorization guard.
     */

    public function testUnauthorizedSubmissionStripsTheAnthropicKeysToo()
    {
        $this->bootPlugin();
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'coding');

        $this->asAnonymous();
        $_POST = array(
            'option_page' => 'zai_connector',
            PlanRegionSettings::OPTION_PLAN => 'general',
            ZaiAnthropicPlanRegionSettings::OPTION_PLAN => 'general',
            ZaiAnthropicPlanRegionSettings::OPTION_REGION => 'cn',
        );

        do_action('admin_init');

        $this->assertArrayNotHasKey(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, $_POST);
        $this->assertArrayNotHasKey(ZaiAnthropicPlanRegionSettings::OPTION_REGION, $_POST);
        $this->assertSame('coding', ZaiAnthropicPlanRegionSettings::get_plan(), 'Option must be unchanged.');
        $this->assertNotEmpty(WpHarness::$settings_errors);
    }

    public function testAuthorizedUserWithValidNoncePassesTheGuard()
    {
        $this->bootPlugin();
        $this->asAdministrator();
        $this->withValidNonce('zai_connector-options');
        $_POST['option_page'] = 'zai_connector';
        $_POST[ZaiAnthropicPlanRegionSettings::OPTION_PLAN] = 'general';

        do_action('admin_init');

        $this->assertSame('general', $_POST[ZaiAnthropicPlanRegionSettings::OPTION_PLAN] ?? null);
    }

    /*
     * Region-switch invalidation — scoped to THIS provider.
     */

    public function testRegionSwitchClearsTheAnthropicKeyStateCachesAndKey()
    {
        $this->bootPlugin();

        // Create the region row FIRST (its creation fires the hooks in the
        // harness); seed the credential-derived state afterwards.
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl');
        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'stale'));
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'intl-key');
        set_transient(ZaiAnthropicModelMetadataDirectory::CACHE_PREFIX . md5('zai_anthropic|coding|intl'), array('glm-5.3'), 3600);

        do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl', 'cn');

        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::STATE_OPTION, false), 'The anthropic validated key state must be cleared.');
        $this->assertFalse(
            get_option(ZaiAnthropicProviderAvailability::KEY_OPTION, false),
            'The anthropic stored key belongs to the OLD region\'s account and must be cleared.'
        );
        $this->assertFalse(get_transient(ZaiAnthropicModelMetadataDirectory::CACHE_PREFIX . md5('zai_anthropic|coding|intl')), 'The anthropic discovery cache must be cleared.');
    }

    public function testRegionSwitchOnTheAnthropicProviderNeverTouchesZaiData()
    {
        $this->bootPlugin();

        // Both providers fully configured; only the ANTHROPIC region changes.
        // Region rows first (their creation fires hooks in the harness).
        update_option(PlanRegionSettings::OPTION_REGION, 'intl');
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl');
        update_option(ZaiProviderAvailability::STATE_OPTION, array('binding' => 'zai-binding'));
        update_option(ZaiProviderAvailability::KEY_OPTION, 'zai-key');
        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'anthropic-binding'));
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'anthropic-key');

        // Switch through the real option write (fires the hook and stores).
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::KEY_OPTION, false), 'The anthropic key must be cleared by ITS region switch.');
        $this->assertSame(
            'zai-key',
            get_option(ZaiProviderAvailability::KEY_OPTION),
            'A zai_anthropic region switch must never delete the zai provider\'s key.'
        );
        $this->assertNotFalse(get_option(ZaiProviderAvailability::STATE_OPTION, false), 'The zai validated state must survive the anthropic region switch.');
        $this->assertSame('intl', PlanRegionSettings::get_region(), 'The zai region selection must be unchanged.');
        $this->assertSame('cn', ZaiAnthropicPlanRegionSettings::get_region());
    }

    public function testFirstPersistedAnthropicRegionChangeOnAFreshInstallRunsTheFullInvalidation()
    {
        $this->bootPlugin();

        $this->assertFalse(get_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, false), 'Fresh install must start without a region row.');
        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'stale'));
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'intl-key');

        add_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        $this->assertSame('cn', ZaiAnthropicPlanRegionSettings::get_region());
        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::STATE_OPTION, false));
        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::KEY_OPTION, false));
    }

    public function testPlanSwitchInvalidatesStateButKeepsTheStoredKey()
    {
        $this->bootPlugin();

        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'stale'));
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'plan-shared-key');

        do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'coding', 'general');

        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::STATE_OPTION, false), 'Validated key state must be cleared on a plan switch too.');
        $this->assertSame(
            'plan-shared-key',
            get_option(ZaiAnthropicProviderAvailability::KEY_OPTION),
            'A plan change stays on the same account: the stored key must be kept.'
        );
    }

    public function testSameValueRewritesInvalidateNothing()
    {
        $this->bootPlugin();

        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'keepme'));
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'keepme-too');

        do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl', 'intl');
        do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'coding', 'coding');

        $this->assertNotFalse(get_option(ZaiAnthropicProviderAvailability::STATE_OPTION, false));
        $this->assertNotFalse(get_option(ZaiAnthropicProviderAvailability::KEY_OPTION, false));
    }

    public function testRegionSwitchMarksAnEnvCredentialPendingValidationForTheNewRegion()
    {
        $this->bootPlugin();
        $envKey = FakeSecrets::apiKey();

        try {
            putenv('ZAI_ANTHROPIC_API_KEY=' . $envKey);
            update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl');

            do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl', 'cn');

            $flag = get_option(ZaiAnthropicProviderAvailability::REGION_PENDING_OPTION);
            $this->assertIsArray($flag, 'The env credential effective across the switch must be marked pending.');
            $this->assertSame('cn', $flag['region']);
            $this->assertSame(hash('sha256', $envKey), $flag['fingerprint']);
            $this->assertOptionNotPlaintext(
                ZaiAnthropicProviderAvailability::REGION_PENDING_OPTION,
                $envKey,
                'The pending flag must store a fingerprint, never the key.'
            );

            // The zai provider's flag stays untouched.
            $this->assertFalse(get_option(ZaiProviderAvailability::REGION_PENDING_OPTION, false), 'The zai provider has its own flag lifecycle.');
        } finally {
            putenv('ZAI_ANTHROPIC_API_KEY');
        }
    }

    /*
     * Rendering: the second section on the shared page.
     */

    public function testBothSectionsRenderOnTheSharedPageWithDistinctLabels()
    {
        $this->bootPlugin();
        $this->asAdministrator();
        do_action('admin_menu');

        ob_start();
        ZaiAnthropicPlanRegionSettings::render_section_description();
        $description = (string) ob_get_clean();
        $this->assertStringContainsString('separate accounts and separate API keys', $description);
        $this->assertStringNotContainsString('<script', $description);

        ob_start();
        ZaiAnthropicPlanRegionSettings::render_plan_field();
        $planField = (string) ob_get_clean();
        $this->assertStringContainsString('name="zai_connector_zai_anthropic_plan"', $planField);
        $this->assertStringNotContainsString('name="zai_connector_zai_plan"', $planField, 'The field must post to the anthropic option, not the zai one.');

        ob_start();
        ZaiAnthropicPlanRegionSettings::render_region_field();
        $regionField = (string) ob_get_clean();
        $this->assertStringContainsString('name="zai_connector_zai_anthropic_region"', $regionField);
    }

    public function testFieldRenderingReflectsTheCurrentSelection()
    {
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'general');
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        ob_start();
        ZaiAnthropicPlanRegionSettings::render_plan_field();
        $planField = (string) ob_get_clean();

        $this->assertStringContainsString('value="general" selected=\'selected\'', $planField);

        ob_start();
        ZaiAnthropicPlanRegionSettings::render_region_field();
        $regionField = (string) ob_get_clean();

        $this->assertStringContainsString('value="cn" selected=\'selected\'', $regionField);
    }

    public function testPageRenderRequiresManageOptions()
    {
        $this->bootPlugin();
        $this->asAnonymous();

        ob_start();
        ZaiAnthropicPlanRegionSettings::render_page();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output, 'Unprivileged users must get no settings markup.');
    }
}
