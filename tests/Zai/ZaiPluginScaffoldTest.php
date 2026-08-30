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

    public function testRegistersOnlyTheZaiProviderInMilestone1()
    {
        $this->bootPlugin();

        $this->assertTrue(AiClient::defaultRegistry()->hasProvider('zai'));
        $this->assertFalse(
            AiClient::defaultRegistry()->hasProvider('zai_anthropic'),
            'zai_anthropic belongs to Milestone 2 and must not be registered yet.'
        );
    }

    public function testRegistersWithAFreshRegistryWithoutDuplicating()
    {
        $this->bootPlugin();

        $fresh = new ProviderRegistry();
        Plugin::register($fresh);
        Plugin::register($fresh);

        $this->assertTrue($fresh->hasProvider('zai'));
        $this->assertSame(array('zai'), $fresh->getRegisteredProviderIds());
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

    public function testMissingSdkBootstrapIsASafeNoOp()
    {
        // Runs the plugin bootstrap in a fresh PHP process WITHOUT the SDK
        // (the test bootstrap always loads vendor/, so this can only be
        // proven out-of-process).
        $script = <<<'PHP'
define('ABSPATH', '/tmp/');
$GLOBALS['__hooks'] = array();
function add_action($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['__hooks'][$tag][] = $cb; return true; }
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
}
