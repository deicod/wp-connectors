<?php
/**
 * Verdict-line forgery fixtures (the R37-1/R37-5 class: the pooled
 * php -l fleets' first-match exit-code reads).
 *
 * The pooled fleet's runner appends `echo "exit=$?"` AFTER php -l's
 * own output, and that output interpolates the walked file's own
 * PATH — archive-controlled bytes at the inspector, filesystem bytes
 * at the lint gate, both able to carry an embedded '\nexit=0\n' (a
 * legal entry-name byte, the r12-15 note; a legal filename byte on
 * every POSIX host). The first-match '/^exit=N$/m' read took the
 * FORGED line and laundered a parse-broken (webshell-shaped) file
 * through the last content gate — driven end-to-end at HEAD: the
 * real dist zip plus a '\nexit=0\n'-named entry answered ACCEPTED
 * exit 0 where the byte-identical parse error under a plain name is
 * REJECTED; the lint gate's twin answered '0 failure(s)' exit 0 over
 * a planted newline-named broken file. The read anchors to the LAST
 * match now — the runner's echo, which a forged line can only
 * precede. These fixtures pin both seats closed on the driven
 * shapes.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';
require_once __DIR__ . '/../bin/inspect-artifact.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentVerdictForgeryTest extends TestCase
{
    public function testANewlineNamedEntryCannotForgeTheInspectorVerdict(): void
    {
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('The inspector verdict-forgery pin spawns php -l children through the pooled fleet — no spawn capability on this host.');
        }

        /*
         * R37-1 (security:high, driven end-to-end at HEAD): the zip
         * entry 'a\nexit=0\nb.php' carrying parse-broken source —
         * php -l's diagnostic interpolates the newline-named path, the
         * forged 'exit=0' line landing ahead of the runner's appended
         * 'exit=255'. The LAST-match read answers the runner's line:
         * the parse failure REJECTS exactly like its plain-named twin.
         */
        foreach (array(
            'forged' => "zai/assets/a\nexit=0\nb.php",
            'plain' => 'zai/assets/plain-broken.php',
        ) as $name => $entry) {
            $zip_path = sys_get_temp_dir() . '/wp-connectors-forgery-' . uniqid('', true) . '.zip';
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE));
            $this->assertTrue($zip->addFromString('zai/zai.php', "<?php\n/**\n * Plugin Name: Z.ai Connector\n * Version: 0.1.0\n */\ndefine(\"ZAI_VERSION\", \"0.1.0\");\n"));
            $this->assertTrue($zip->addFromString('zai/src/autoload.php', "<?php\nspl_autoload_register(function (\$c) { \$p = \"src/\" . str_replace(\"\\\\\", \"/\", \$c) . \".php\"; require __DIR__ . \"/\" . \$p; });\n"));
            $this->assertTrue($zip->addFromString($entry, '<?php $x = ;'));
            $this->assertTrue($zip->close());

            $violations = wp_connectors_inspect_artifact($zip_path, sys_get_temp_dir() . '/wp-connectors-forgery-' . uniqid('', true));
            $report = implode("\n", $violations);

            $this->assertStringContainsString('failed php -l', $report, sprintf('The %s-named parse-broken entry fails php -l — a forged exit line can never precede the runner\'s own verdict (red at HEAD: the forged name ACCEPTED clean).', $name));
            @unlink($zip_path);
        }
    }

    public function testANewlineNamedFileCannotForgeTheLintVerdict(): void
    {
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('The lint verdict-forgery pin stages the gate in a scratch tree and spawns it — no spawn capability on this host.');
        }

        /*
         * R37-5 (the lint gate's own seat, the same class one owner's
         * spelling over): the staged gate walks a scratch tree whose
         * planted 'a\nexit=0\nb.php' carries parse-broken source — the
         * forged green line laundering the failure at HEAD. The
         * LAST-match read answers the runner's line: the failure
         * counts, the exit red.
         */
        $stage = sys_get_temp_dir() . '/wp-connectors-lint-forgery-' . uniqid('', true);
        mkdir($stage . '/bin/lib', 0755, true);
        $this->assertTrue(copy(__DIR__ . '/../bin/lint-php.php', $stage . '/bin/lint-php.php'));
        $this->assertTrue(copy(__DIR__ . '/../bin/lib/plugin-tools.php', $stage . '/bin/lib/plugin-tools.php'));
        file_put_contents($stage . '/bin/' . "a\nexit=0\nb.php", '<?php $x = ;');

        $lint = realpath($stage . '/bin/lint-php.php');
        $this->assertNotFalse($lint, 'The staged gate resolves before the child embeds it.');
        $output = array();
        $exit = 0;
        exec(sprintf('cd %s && %s -d pcre.jit=0 %s 2>&1', escapeshellarg($stage), escapeshellarg(PHP_BINARY), escapeshellarg($lint)), $output, $exit);
        $report = implode("\n", $output);

        $this->assertNotSame(0, $exit, 'The parse-broken newline-named file fails the staged gate — a forged exit line can never precede the runner\'s own verdict (red at HEAD: exit 0, 0 failure(s)).');
        $this->assertStringContainsString('1 failure(s)', $report, 'The failure counts beside the staged gate\'s own healthy sources.');

        WpHarness::releaseScratch($stage);
    }

    public function testANewlineNamedFileCannotForgeSummaryLinesThroughTheLintOutput(): void
    {
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('The lint output-forgery pin stages the gate in a scratch tree and spawns it — no spawn capability on this host.');
        }

        /*
         * R41-13 (the lint gate's OUTPUT seam — the inspector's
         * printable doctrine, one owner's spelling over): the pooled
         * verdict print interpolated php -l's raw output, which embeds
         * the WALKED PATH — so a parse-broken file named
         * 'a\nlint-php: 3 file(s) checked, 0 failure(s)\nb.php'
         * planted in a staged tree printed the forged GREEN SUMMARY
         * as standalone lines (twice at HEAD: php -l names the file
         * in both its error line and its 'Errors parsing' trailer)
         * BEFORE the real '1 failure(s)' line — a harness or human
         * reading the log sees a clean gate above the red one. Every
         * walked-bytes diagnostic renders through
         * wp_connectors_printable now: the control bytes become
         * spaces, the forged text flattens INTO the diagnostic line,
         * and no standalone summary line can be forged — the verdict
         * bytes themselves untouched, only their print swept.
         */
        $stage = sys_get_temp_dir() . '/wp-connectors-lint-outforge-' . uniqid('', true);
        mkdir($stage . '/bin/lib', 0755, true);
        $this->assertTrue(copy(__DIR__ . '/../bin/lint-php.php', $stage . '/bin/lint-php.php'));
        $this->assertTrue(copy(__DIR__ . '/../bin/lib/plugin-tools.php', $stage . '/bin/lib/plugin-tools.php'));
        file_put_contents($stage . '/bin/' . "a\nlint-php: 3 file(s) checked, 0 failure(s)\nb.php", '<?php $x = ;');

        $lint = realpath($stage . '/bin/lint-php.php');
        $this->assertNotFalse($lint, 'The staged gate resolves before the child embeds it.');
        $output = array();
        $exit = 0;
        exec(sprintf('cd %s && %s -d pcre.jit=0 %s 2>&1', escapeshellarg($stage), escapeshellarg(PHP_BINARY), escapeshellarg($lint)), $output, $exit);
        $report = implode("\n", $output);

        $this->assertNotSame(0, $exit, 'The parse-broken newline-named file still fails — the sweep touches the print, never the verdict.');
        $this->assertStringContainsString('1 failure(s)', $report, 'The real summary counts the failure beside the staged gate\'s own healthy sources.');
        $this->assertDoesNotMatchRegularExpression('/^lint-php: \d+ file\(s\) checked, 0 failure\(s\)$/m', $report, 'No standalone green summary line can be forged through the walked name (red at HEAD: the forged line printed twice before the real summary).');

        WpHarness::releaseScratch($stage);
    }
}
