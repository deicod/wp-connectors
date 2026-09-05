<?php
/**
 * Task 1.1 — z.ai plugin scaffold tests.
 *
 * Covers activation on WP 7.0 (SDK present), the 6.9-compatible header, the
 * missing-SDK no-op, and duplicate `init` execution.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;
use Deicod\WpConnectors\Zai\Plugin;

final class ZaiPluginScaffoldTest extends WpConnectorsTestCase
{
    private const PLUGIN_FILE = __DIR__ . '/../../connectors/zai/zai.php';

    private const BOOT = '\Deicod\WpConnectors\Zai\boot';

    /**
     * Loads the plugin and fires init once.
     *
     * @return void
     */
    private function bootPlugin()
    {
        $this->loadPlugin(self::PLUGIN_FILE, self::BOOT);
        $this->runInit();
    }

    /*
     * Activation on WP 7.0 (SDK ships in core).
     */

    public function testRegistersProviderBeforeCoreConnectorDiscovery()
    {
        $this->bootPlugin();

        $registeredAtPriority15 = null;
        add_action('init', static function () use (&$registeredAtPriority15) {
            $registeredAtPriority15 = AiClient::defaultRegistry()->hasProvider('zai');
        }, 15, 0);

        $this->runInit();

        $this->assertTrue($registeredAtPriority15, 'Provider was NOT registered when core discovery (init 15) ran.');
        $this->assertTrue(AiClient::defaultRegistry()->hasProvider('zai'));
    }

    public function testRegistersBothProvidersInMilestone2()
    {
        $this->bootPlugin();

        $this->assertTrue(AiClient::defaultRegistry()->hasProvider('zai'));
        $this->assertTrue(AiClient::defaultRegistry()->hasProvider('zai_anthropic'));
    }

    public function testRegistersWithAFreshRegistryWithoutDuplicating()
    {
        $this->bootPlugin();

        $fresh = new ProviderRegistry();
        Plugin::register($fresh);
        Plugin::register($fresh);

        $this->assertTrue($fresh->hasProvider('zai'));
        $this->assertTrue($fresh->hasProvider('zai_anthropic'));
        $this->assertSame(array('zai', 'zai_anthropic'), $fresh->getRegisteredProviderIds());
    }

    /*
     * The 6.9-compatible header (standalone-SDK sites must not be blocked).
     */

    public function testPluginHeaderAcceptsStandaloneSdkSites()
    {
        $source = (string) file_get_contents(self::PLUGIN_FILE);

        $this->assertSame(1, preg_match('/^\s*\*?\s*Requires at least:\s*(.+)$/mi', $source, $requires));
        $this->assertSame('6.9', trim($requires[1]));

        $this->assertSame(1, preg_match('/^\s*\*?\s*Text Domain:\s*(.+)$/mi', $source, $domain));
        $this->assertSame('zai', trim($domain[1]));

        // The version constant must match the header Version (docs/CONVENTIONS.md).
        $this->assertSame(1, preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $source, $version));
        $this->assertSame(1, preg_match("/define\(\s*'ZAI_VERSION',\s*'([^']+)'/", $source, $constant));
        $this->assertSame(trim($version[1]), $constant[1]);
        $this->assertSame($constant[1], ZAI_VERSION);
    }

    /*
     * Missing SDK: the guarded bootstrap must no-op without fatals.
     */

    public function testAForeignVersionConstantDoesNotEmitARedefinitionNotice()
    {
        /*
         * GLM5 #15: ZAI_VERSION was defined without a defined() guard, so
         * any other plugin/theme defining the same generic constant first
         * emitted an E_NOTICE on every request and this plugin silently
         * reported the foreign version. Verified in a subprocess: the
         * plugin file is already loaded in this process, and the guard's
         * effect is only observable at load time.
         */
        $script = ''
            . 'define("ABSPATH", "/tmp/");'
            . 'define("ZAI_VERSION", "9.9-foreign");'
            . 'function add_action(...$args) {}'
            . 'function add_filter(...$args) {}'
            . 'function plugin_basename($file) { return $file; }'
            . 'require ' . var_export(self::PLUGIN_FILE, true) . ';'
            . 'echo "ZAI_VERSION=" . ZAI_VERSION;'
            . '';

        $command = escapeshellarg(PHP_BINARY)
            . ' -d error_reporting=-1 -d display_errors=1 -r '
            . escapeshellarg($script)
            . ' 2>&1';

        exec($command, $output_lines, $exit_code);
        $output = implode("\n", $output_lines);

        $this->assertSame(0, $exit_code, "Loading with a foreign ZAI_VERSION must not fatal: {$output}");
        $this->assertStringNotContainsString('already defined', $output, 'No constant-redefinition notice may be emitted.');
        $this->assertStringNotContainsString('Notice', $output, 'No notice may be emitted at load time.');
        $this->assertStringContainsString('ZAI_VERSION=9.9-foreign', $output, 'The foreign value stands (the guarded define is skipped): the guard exists to stop the per-request notice, not to fight the collision.');
    }

    public function testMissingSdkBootstrapIsASafeNoOp()
    {
        // Runs the plugin bootstrap in a fresh PHP process WITHOUT the SDK
        // (the test bootstrap always loads vendor/, so this can only be
        // proven out-of-process).
        $script = <<<'PHP'
define('ABSPATH', '/tmp/');
$GLOBALS['__hooks'] = array();
function add_action($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['__hooks'][$tag][] = $cb; return true; }
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['__hooks'][$tag][] = $cb; return true; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function esc_url($url) { return $url; }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8', true); }
function __($t, $d = 'default') { return $t; }
function esc_html__($t, $d = 'default') { return esc_html($t); }
require %s;
if (class_exists('WordPress\AiClient\AiClient')) { fwrite(STDERR, "SDK unexpectedly present\n"); exit(1); }
call_user_func('\Deicod\WpConnectors\Zai\register_provider');
ob_start();
Deicod\WpConnectors\Zai\Plugin::render_dependency_notice();
$notice = (string) ob_get_clean();
if (strpos($notice, 'PHP AI Client SDK') === false) { fwrite(STDERR, "dependency notice missing\n"); exit(1); }
echo "MISSING_SDK_OK\n";
PHP;
        $script = sprintf($script, var_export(self::PLUGIN_FILE, true));

        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1';
        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        $this->assertSame(0, $exitCode, "Missing-SDK bootstrap failed: {$output}");
        $this->assertStringContainsString('MISSING_SDK_OK', $output);
    }

    public function testDependencyNoticeIsSilentWhenSdkPresent()
    {
        $this->assertTrue(Plugin::sdk_available());

        ob_start();
        Plugin::render_dependency_notice();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    /*
     * Duplicate init execution.
     */

    public function testDuplicateInitExecutionIsIdempotent()
    {
        $this->bootPlugin();

        $this->runInit();
        $this->runInit();

        $this->assertTrue(AiClient::defaultRegistry()->hasProvider('zai'));
        $this->assertSame(3, did_action('init'));
        $this->assertNoDoingItWrong();
    }

    public function testBootWiresEverySurfaceThroughTheOneSurfaceList()
    {
        /*
         * glm15-13 (source pin): boot() iterates one SDK-free surface
         * list — the per-surface hook block was copy-pasted (~7
         * update/add-option hooks per surface plus the page/section
         * asymmetry), and one missed copied line was exactly the
         * stranded-invalidation bug class the file's own GLM5 #14
         * comments document. Each surface class may appear in the list
         * exactly once; every per-surface hook rides the foreach (the
         * behavioral coverage above pins the wired hooks themselves).
         */
        $source = (string) file_get_contents(self::PLUGIN_FILE);

        $this->assertStringContainsString('$surface_settings = array(', $source, 'boot() declares the one surface list.');
        foreach (array('PlanRegionSettings::class', 'ZaiAnthropicPlanRegionSettings::class') as $surface) {
            $this->assertSame(
                1,
                preg_match_all('/(?<![A-Za-z])' . preg_quote($surface, '/') . ',/', $source),
                "{$surface} is a list entry exactly once, never a copy-pasted hook block."
            );
        }
        $this->assertStringContainsString("foreach ( \$surface_settings as \$settings_class )", $source, 'The per-surface hooks ride the list iteration.');
        $this->assertStringContainsString("foreach ( \$surface_settings as \$index => \$settings_class )", $source, 'The page-owner asymmetry rides the list order.');
    }
}
