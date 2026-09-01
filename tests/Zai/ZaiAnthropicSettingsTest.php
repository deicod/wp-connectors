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

    public function testDefaultsAreGeneralPlanAndInternationalRegion()
    {
        // Live-evidence amendment (record 0007): the coding-surface Messages
        // routes cannot generate, so this provider defaults to the general
        // surface — the production-proven path for Coding-Plan keys too.
        $this->bootPlugin();

        $this->assertSame('general', ZaiAnthropicPlanRegionSettings::get_plan());
        $this->assertSame('intl', ZaiAnthropicPlanRegionSettings::get_region());
        $this->assertSame('general', ZaiAnthropicPlanRegionSettings::DEFAULT_PLAN);
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
        $this->assertSame('general', $plan['default']);

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
        $this->assertSame('general', ZaiAnthropicPlanRegionSettings::get_plan());
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

        $this->assertSame('general', ZaiAnthropicPlanRegionSettings::get_plan(), 'The zai_anthropic plan must be untouched.');
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

    public function testFirstPersistedAnthropicPlanChangeOnAFreshInstallInvalidates()
    {
        $this->bootPlugin();

        // No plan row yet: the first save travels through add_option(),
        // firing add_option_{plan} instead of the update hook — the
        // companion hook must still run the state invalidation.
        $this->assertFalse(get_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, false), 'Fresh install must start without a plan row.');
        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'stale'));
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'plan-shared-key');
        set_transient(Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory::CACHE_PREFIX . md5('zai_anthropic|general|intl'), array('glm-5.3'), 3600);

        add_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'coding');

        $this->assertSame('coding', ZaiAnthropicPlanRegionSettings::get_plan());
        $this->assertFalse(get_option(ZaiAnthropicProviderAvailability::STATE_OPTION, false), 'The first persisted plan change must clear the validated state.');
        $this->assertFalse(
            get_transient(Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory::CACHE_PREFIX . md5('zai_anthropic|general|intl')),
            'The first persisted plan change must clear the old endpoint\'s discovery cache.'
        );
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
     * Effective-region comparison (Codex R11 #2).
     */

    /**
     * @dataProvider provideCorruptStoredRegionsSavingTheDefault
     */
    public function testSavingTheDefaultOverACorruptStoredRegionKeepsTheKey($corrupt)
    {
        // Codex R11 #2: a corrupt stored value already routes to the
        // default — saving the displayed default is NOT a region switch,
        // and must not delete a valid credential whose effective endpoint
        // never changed.
        $this->bootPlugin();
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, $corrupt);
        update_option(ZaiAnthropicProviderAvailability::STATE_OPTION, array('binding' => 'x'));
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'valid-intl-key');

        // The save path: Settings API sanitized value becomes the new
        // stored value; core fires the update hook with (raw old, new).
        $new = sanitize_text_field('intl');
        do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, $corrupt, $new);

        $this->assertSame(
            'valid-intl-key',
            get_option(ZaiAnthropicProviderAvailability::KEY_OPTION),
            'The effective endpoint never changed: the key must survive.'
        );
        // Nothing is invalidated when the effective regions match — the
        // early return skips the whole switch handling (key AND state).
        $this->assertSame(array('binding' => 'x'), get_option(ZaiAnthropicProviderAvailability::STATE_OPTION));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideCorruptStoredRegionsSavingTheDefault()
    {
        return array(
            'garbage string' => array('bogus'),
            'empty string' => array(''),
            'whitespace' => array('  '),
            'upper case' => array('INTL'),
        );
    }

    public function testAGenuineRegionSwitchStillDeletesTheKey()
    {
        $this->bootPlugin();
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl');
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'intl-key');

        do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'intl', 'cn');

        $this->assertFalse(
            get_option(ZaiAnthropicProviderAvailability::KEY_OPTION, false),
            'Genuinely different effective regions: the key is deleted as before.'
        );
    }

    public function testSavingTheSameRegionKeepsTheKey()
    {
        $this->bootPlugin();
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');
        update_option(ZaiAnthropicProviderAvailability::KEY_OPTION, 'cn-key');

        do_action('update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn', 'cn');

        $this->assertSame('cn-key', get_option(ZaiAnthropicProviderAvailability::KEY_OPTION), 'No change: the key survives.');
    }

    /*
     * SDK-absent safety (Codex R2 #3) and identifier consistency.
     */

    public function testSettingsInvalidationNeverLoadsSdkDependentClasses()
    {
        // On WP 6.9 without the optional PHP AI Client plugin the settings
        // UI still boots (dependency notice), and a plan/region save must
        // invalidate credential-derived state WITHOUT autoloading the
        // availability/directory classes — they implement missing SDK
        // types and would fatal after the option write. The suite always
        // loads the SDK from vendor/, so this runs in a fresh PHP process
        // with only the SDK-free plugin files available (same pattern as
        // the M1 missing-SDK scaffold test).
        $script = <<<'PHP'
define('ABSPATH', '/tmp/');
$GLOBALS['__opts'] = array();
$GLOBALS['__trans'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function add_option($k, $v = '', $x = null, $a = null) { if (!array_key_exists($k, $GLOBALS['__opts'])) { $GLOBALS['__opts'][$k] = $v; } return true; }
function get_transient($k) { return array_key_exists($k, $GLOBALS['__trans']) ? $GLOBALS['__trans'][$k] : false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__trans'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__trans'][$k]); return true; }
function add_action(...$x) {} function add_filter(...$x) {}
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function wp_json_encode($d) { return json_encode($d); }
function __($t, $d = null) { return $t; } function esc_html($t) { return $t; }
function esc_html__($t, $d = null) { return $t; } function esc_attr($t) { return $t; }

// Boot the plugin file itself (register_provider's SDK guard makes init a
// no-op; booting must stay SDK-free) and load nothing else beyond it.
require %s;

// Seed live-site state for BOTH providers.
update_option('zai_connector_zai_plan', 'coding');
update_option('zai_connector_zai_region', 'intl');
update_option('zai_connector_zai_key_state', array('binding' => 'x'));
update_option('connectors_ai_zai_api_key', 'zai-key');
set_transient('zai_connector_zai_models_' . md5('zai|coding|intl'), array('glm-5.3'), 3600);
update_option('zai_connector_zai_anthropic_plan', 'general');
update_option('zai_connector_zai_anthropic_region', 'intl');
update_option('zai_connector_zai_anthropic_key_state', array('binding' => 'x'));
update_option('connectors_ai_zai_anthropic_api_key', 'anthropic-key');
set_transient('zai_connector_zai_anthropic_models_' . md5('zai_anthropic|general|intl'), array('glm-5.3'), 3600);

// The invalidation a settings save fires. If either handler autoloaded an
// SDK-dependent class, PHP would fatal with "Interface not found" here.
Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::handle_settings_change('coding', 'general');
Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::handle_region_change('intl', 'cn');

if (class_exists('WordPress\AiClient\AiClient')) { fwrite(STDERR, "SDK unexpectedly present\n"); exit(1); }

$failures = array();
if (get_option('zai_connector_zai_key_state', false) !== false) { $failures[] = 'zai state'; }
if (get_option('connectors_ai_zai_api_key', false) === false) { $failures[] = 'zai key wrongly deleted by plan change'; }
if (get_transient('zai_connector_zai_models_' . md5('zai|coding|intl')) !== false) { $failures[] = 'zai transient'; }
if (get_option('zai_connector_zai_anthropic_key_state', false) !== false) { $failures[] = 'anthropic state'; }
if (get_option('connectors_ai_zai_anthropic_api_key', false) !== false) { $failures[] = 'anthropic key'; }
if (get_transient('zai_connector_zai_anthropic_models_' . md5('zai_anthropic|general|intl')) !== false) { $failures[] = 'anthropic transient'; }
if ($failures !== array()) { fwrite(STDERR, 'not invalidated: ' . implode('; ', $failures) . "\n"); exit(1); }
echo "SDK_ABSENT_INVALIDATION_OK\n";
PHP;
        $script = sprintf($script, var_export(dirname(__DIR__, 2) . '/connectors/zai/zai.php', true));

        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1';
        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        $this->assertSame(0, $exitCode, "SDK-absent invalidation must not fatal or mis-invalidate:\n{$output}");
        $this->assertStringContainsString('SDK_ABSENT_INVALIDATION_OK', $output);
    }

    public function testSettingsInvalidationIdentifiersMatchTheRuntimeClasses()
    {
        // The settings layer carries SDK-free copies of the invalidation
        // identifiers (Codex R2 #3). The availability/directory constants
        // mirror them; the endpoint cache-key format is composed inline in
        // the settings handlers. This pins every relationship so the layers
        // can never drift.
        $cases = array(
            array(
                Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::class,
                Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::class,
                Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory::class,
                Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::class,
            ),
            array(
                Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::class,
                Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::class,
                Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory::class,
                Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::class,
            ),
        );

        foreach ($cases as $case) {
            list($settings, $availability, $directory, $endpoint) = $case;

            $this->assertSame($settings::STATE_OPTION, $availability::STATE_OPTION);
            $this->assertSame($settings::REGION_PENDING_OPTION, $availability::REGION_PENDING_OPTION);
            $this->assertSame($settings::KEY_OPTION, $availability::KEY_OPTION);
            $this->assertSame($settings::KEY_ENV_NAME, $availability::KEY_ENV_NAME);
            $this->assertSame($settings::CACHE_PREFIX, $directory::CACHE_PREFIX);

            // The inline cache-key composition must equal the endpoint
            // resolver's own identity for every plan x region.
            foreach (Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings::PLANS as $plan) {
                foreach (Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings::REGIONS as $region) {
                    $this->assertSame(
                        $settings::CACHE_SCOPE . '|' . $plan . '|' . $region,
                        $endpoint::for($plan, $region)->cache_key(),
                        'The settings-layer cache-key composition must match the endpoint identity.'
                    );
                }
            }
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
