<?php
/**
 * Artifact-builder acceptance tests (Task 0.5).
 *
 * Builds the fixture plugin, verifies determinism, inspects the zip, and
 * proves the inspector rejects broken artifacts (repo-relative includes,
 * missing headers, dev files).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/build.php';
require_once __DIR__ . '/../bin/inspect-artifact.php';

final class BuildArtifactsTest extends WpConnectorsTestCase
{
    private const FIXTURE = 'example-connector';

    public static function setUpBeforeClass(): void
    {
        $dist = self::distDir();
        if (! is_dir($dist)) {
            mkdir($dist, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Keep dist/ clean for the next test.
        foreach (glob(self::distDir() . '/connectors-*demo*') ?: array() as $file) {
            @unlink($file);
        }
        foreach (glob(self::distDir() . '/connectors-' . self::FIXTURE . '-*') ?: array() as $file) {
            @unlink($file);
        }
        @unlink(self::distDir() . '/checksums.txt');

        parent::tearDown();
    }

    private static function distDir(): string
    {
        return __DIR__ . '/../dist';
    }

    private function buildFixture(): string
    {
        $zipPath = WpConnectorsBuild::buildPlugin(
            __DIR__ . '/fixtures/plugins/' . self::FIXTURE,
            self::distDir()
        );
        $this->assertFileExists($zipPath);

        return $zipPath;
    }

    public function testFixturePluginZipsWithChecksum()
    {
        $zipPath = $this->buildFixture();

        $this->assertSame(
            'connectors-example-connector-0.1.0.zip',
            basename($zipPath),
            'Zip name must be connectors-<slug>-<version>.zip'
        );
        $this->assertFileExists($zipPath . '.sha256');

        $recorded = (string) file_get_contents($zipPath . '.sha256');
        $this->assertSame(hash_file('sha256', $zipPath) . '  ' . basename($zipPath) . "\n", $recorded);
        $this->assertStringContainsString(basename($zipPath), (string) file_get_contents(self::distDir() . '/checksums.txt'));
    }

    public function testBuildIsDeterministic()
    {
        $first = $this->buildFixture();
        $firstHash = hash_file('sha256', $first);

        $second = $this->buildFixture();
        $secondHash = hash_file('sha256', $second);

        $this->assertSame($firstHash, $secondHash, 'Two builds of the same plugin must be byte-identical.');
    }

    public function testBuiltArtifactIsAcceptedByInspector()
    {
        $zipPath = $this->buildFixture();

        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-test');
        $this->assertSame(array(), $violations);
        $this->assertDirectoryDoesNotExist(
            self::distDir() . '/.inspect-test',
            'The inspector must remove its temp extraction tree via try/finally (accepting path).'
        );
    }

    public function testExtractedArtifactIsSelfContainedAndSyntaxClean()
    {
        $zipPath = $this->buildFixture();

        $extractDir = self::distDir() . '/.extract-test';
        if (is_dir($extractDir)) {
            WpHarness::rrmdir($extractDir);
        }
        mkdir($extractDir, 0755, true);
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($extractDir);
        $zip->close();

        // Independent extraction contains exactly the plugin dir, no dev files.
        $this->assertFileExists($extractDir . '/' . self::FIXTURE . '/example-connector.php');
        $this->assertFileDoesNotExist($extractDir . '/' . self::FIXTURE . '/vendor');
        $this->assertFileDoesNotExist($extractDir . '/' . self::FIXTURE . '/composer.json');

        // LICENSE from the repo root is embedded.
        $this->assertFileExists($extractDir . '/' . self::FIXTURE . '/LICENSE');

        // All shipped PHP parses after extraction elsewhere.
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            ++$count;
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, 'php -l failed: ' . implode("\n", $output));
        }
        $this->assertGreaterThan(4, $count, 'Fixture zip should contain the main file, autoloader, and source classes.');
        WpHarness::rrmdir($extractDir);
    }

    public function testInspectorRejectsRepoRelativeInclude()
    {
        $zipPath = $this->buildBadZip('escape-demo', "require_once dirname(__DIR__) . '/other-plugin/plugin.php';");
        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-bad');
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));
        // This path extracts a real tree, so it pins the try/finally cleanup:
        // a deleted finally block would leak .inspect-bad and fail here.
        $this->assertDirectoryDoesNotExist(
            self::distDir() . '/.inspect-bad',
            'The inspector must remove its temp extraction tree via try/finally (rejecting path).'
        );
    }

    public function testInspectorRejectsMissingHeader()
    {
        $zipPath = $this->buildBadZip('header-demo', '', true);
        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-bad');
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('header is missing', implode("\n", $violations));
    }

    public function testInspectorRejectsDevelopmentFilesInZip()
    {
        $zipPath = $this->buildBadZip('devfiles-demo', '');
        $extra = self::distDir() . '/connectors-devfiles-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $zip->addFromString('devfiles-demo/vendor/autoload.php', "<?php\n");
        $zip->addFromString('devfiles-demo/composer.json', '{}');
        $zip->close();

        $violations = wp_connectors_inspect_artifact($extra, self::distDir() . '/.inspect-bad');
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('development entry', implode("\n", $violations));
    }

    /*
     * Root-file archives (finding: the sole top-level entry is a FILE).
     */

    public function testInspectorRejectsARootFileArchiveWithoutTraversingIt()
    {
        // A zip whose single top-level entry is a regular file (plugin.php)
        // used to make the directory iterator throw and leak the temp tree;
        // it must be a normal violation with the work dir cleaned up.
        $zipPath = self::distDir() . '/connectors-rootfile-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $main = "<?php\n/**\n * Plugin Name:       rootfile-demo\n * Version:           1.0.0\n * Requires at least: 6.9\n * Requires PHP:      7.4\n * License:           GPL-2.0-or-later\n * Text Domain:       rootfile-demo\n * Author:            x\n */\ndefine( 'ROOTFILE_DEMO_VERSION', '1.0.0' );\n";
        $zip->addFromString('plugin.php', $main);
        $zip->close();

        $workDir = self::distDir() . '/.inspect-rootfile';
        $violations = wp_connectors_inspect_artifact($zipPath, $workDir);

        $this->assertNotSame(array(), $violations, 'A root-file archive must be rejected.');
        $this->assertStringContainsString('sole entry "plugin.php" is a file', implode("\n", $violations));
        $this->assertDirectoryDoesNotExist($workDir, 'The temp extraction tree must be cleaned up on every path.');

        unlink($zipPath);
    }

    /*
     * Exactly one main plugin file (finding: two Plugin Name headers accepted).
     */

    public function testMultipleMainPluginFilesAreRejectedByTheSharedRule()
    {
        $tempPlugin = self::distDir() . '/.twomain-test/twomain-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        $head = "Plugin Name:       twomain-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      7.4\nLicense:           GPL-2.0-or-later\nText Domain:       twomain-demo\nAuthor:            x\n";
        file_put_contents($tempPlugin . '/twomain-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'TWOMAIN_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n");
        file_put_contents($tempPlugin . '/second-entry.php', "<?php\n/**\n * Plugin Name:       twomain-demo again\n */\necho 'also a plugin';\n");
        file_put_contents($tempPlugin . '/not-a-plugin.php', "<?php\necho 'no header here';\n");
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\TwomainDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);

        // The helper sees BOTH header-bearing files, deterministically ordered.
        $mainFiles = wp_connectors_find_main_plugin_files($tempPlugin);
        $this->assertCount(2, $mainFiles);
        $this->assertSame('second-entry.php', basename($mainFiles[0]));
        $this->assertSame('twomain-demo.php', basename($mainFiles[1]));

        $violations = wp_connectors_main_file_violations($tempPlugin, $mainFiles);
        $this->assertNotSame(array(), $violations, 'Two Plugin Name headers must be a violation.');
        $this->assertStringContainsString('multiple main plugin files', $violations[0]);
        $this->assertStringContainsString('second-entry.php', $violations[0]);
        $this->assertStringContainsString('twomain-demo.php', $violations[0]);

        // The builder refuses to package it.
        try {
            WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            $this->fail('The build must refuse a plugin with two main files.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('multiple main plugin files', $e->getMessage());
        }

        // And the inspector rejects the archive shape it would produce.
        $zipPath = self::distDir() . '/connectors-twomain-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (array('twomain-demo.php', 'second-entry.php', 'src/autoload.php') as $relative) {
            $zip->addFile($tempPlugin . '/' . $relative, 'twomain-demo/' . $relative);
        }
        $zip->close();

        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-twomain');
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('multiple main plugin files', implode("\n", $violations));

        unlink($zipPath);
        @unlink($zipPath . '.sha256');
        @unlink(self::distDir() . '/checksums.txt');
        WpHarness::rrmdir(dirname($tempPlugin));
    }

    /*
     * Traversal root names (finding: '../payload.php' as the sole entry made
     * every check run against HOST paths outside the extraction dir).
     */

    public function testInspectorRejectsATraversalRootNameWithoutTouchingTheHost()
    {
        $zipPath = self::distDir() . '/connectors-traversal-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../payload.php', "<?php\n/**\n * Plugin Name:       traversal-demo\n * Version:           1.0.0\n */\n");
        $zip->close();

        $workDir = self::distDir() . '/.inspect-traversal';
        $violations = wp_connectors_inspect_artifact($zipPath, $workDir);

        $this->assertNotSame(array(), $violations, 'A traversal root name must be rejected.');
        $this->assertStringContainsString('invalid top-level plugin directory name', implode("\n", $violations));
        $this->assertDirectoryDoesNotExist($workDir, 'The temp extraction tree must be cleaned up on every path.');
        $this->assertFileDoesNotExist(dirname($workDir) . '/payload.php', 'Extraction must never write outside the work dir.');

        unlink($zipPath);
    }

    public function testInspectorRejectsMidPathTraversalEntriesBeforeExtracting()
    {
        $zipPath = self::distDir() . '/connectors-midpath-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('midpath-demo/midpath-demo.php', "<?php\n/**\n * Plugin Name:       midpath-demo\n * Version:           1.0.0\n */\n");
        $zip->addFromString('midpath-demo/src/../../escape.php', "<?php\necho 'outside';\n");
        $zip->close();

        $workDir = self::distDir() . '/.inspect-midpath';
        $violations = wp_connectors_inspect_artifact($zipPath, $workDir);

        $this->assertNotSame(array(), $violations, "A '..' path segment in any entry must be rejected.");
        $this->assertStringContainsString('escapes the extraction directory', implode("\n", $violations));
        $this->assertDirectoryDoesNotExist($workDir, 'The temp extraction tree must be cleaned up on every path.');
        $this->assertFileDoesNotExist(dirname($workDir) . '/escape.php', 'Extraction must never write outside the work dir.');

        unlink($zipPath);
    }

    public function testInspectorRejectsBackslashSeparatedPathEntries()
    {
        // A backslash is a harmless literal on Linux but a path separator
        // under PHP on Windows, where '..\..\x.php' behind a valid root
        // would extract outside the work dir.
        $zipPath = self::distDir() . '/connectors-backslash-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('backslash-demo/backslash-demo.php', "<?php\n/**\n * Plugin Name:       backslash-demo\n * Version:           1.0.0\n */\n");
        $zip->addFromString("backslash-demo/src\\..\\..\\escape.php", "<?php\necho 'outside';\n");
        $zip->close();

        $workDir = self::distDir() . '/.inspect-backslash';
        $violations = wp_connectors_inspect_artifact($zipPath, $workDir);

        $this->assertNotSame(array(), $violations, 'Backslash-separated path entries must be rejected.');
        $this->assertStringContainsString('escapes the extraction directory', implode("\n", $violations));
        $this->assertDirectoryDoesNotExist($workDir, 'The temp extraction tree must be cleaned up on every path.');

        unlink($zipPath);
    }

    /*
     * Anchored includes that walk out of the plugin dir (finding:
     * `require __DIR__ . '/../../other/bootstrap.php';` passed the check).
     */

    public function testAnchoredIncludesThatEscapeThePluginDirAreRejected()
    {
        $tempPlugin = self::distDir() . '/.anchored-test/anchored-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src/Settings', 0755, true);
        $head = "Plugin Name:       anchored-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      7.4\nLicense:           GPL-2.0-or-later\nText Domain:       anchored-demo\nAuthor:            x\n";
        file_put_contents($tempPlugin . '/anchored-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'ANCHORED_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n");
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\AnchoredDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);
        // Nested-but-inside: from src/Settings, '..' resolves to src/ — still
        // beneath the plugin root, so it must stay allowed.
        file_put_contents($tempPlugin . '/src/support.php', "<?php\n// loaded by src/Settings/bootstrap.php\n");
        file_put_contents($tempPlugin . '/src/Settings/bootstrap.php', "<?php\nrequire_once __DIR__ . '/../support.php';\n");
        // Anchored-but-escaping: two '..' segments leave the plugin dir.
        file_put_contents($tempPlugin . '/src/escape.php', "<?php\nrequire __DIR__ . '/../../outside/bootstrap.php';\n");

        $violations = wp_connectors_self_containment_violations($tempPlugin);

        $byFile = array();
        foreach ($violations as $violation) {
            if (strpos($violation, 'src/escape.php') !== false) {
                $byFile['escape'] = ($byFile['escape'] ?? 0) + 1;
            }
            if (strpos($violation, 'src/Settings/bootstrap.php') !== false) {
                $byFile['bootstrap'] = ($byFile['bootstrap'] ?? 0) + 1;
            }
            if (strpos($violation, 'src/autoload.php') !== false) {
                $byFile['autoload'] = ($byFile['autoload'] ?? 0) + 1;
            }
        }
        $this->assertSame(1, $byFile['escape'] ?? 0, 'The anchored-but-escaping include must be flagged exactly once: ' . implode("\n", $violations));
        $this->assertSame(0, $byFile['bootstrap'] ?? 0, 'A nested-but-inside include must still be allowed.');
        $this->assertSame(0, $byFile['autoload'] ?? 0, 'The ordinary downward autoloader include must stay clean.');

        // The inspector enforces the same shared rule on built artifacts.
        $zipPath = self::distDir() . '/connectors-anchored-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (array('anchored-demo.php', 'src/autoload.php', 'src/support.php', 'src/Settings/bootstrap.php', 'src/escape.php') as $relative) {
            $zip->addFile($tempPlugin . '/' . $relative, 'anchored-demo/' . $relative);
        }
        $zip->close();
        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-anchored');
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));

        unlink($zipPath);
        WpHarness::rrmdir(dirname($tempPlugin));
    }

    /*
     * Duplicate plugin headers (finding: the parser kept the LAST value while
     * WordPress's get_file_data() keeps the FIRST).
     */

    public function testDuplicateHeadersKeepTheFirstValueAndAreFlagged()
    {
        $tempPlugin = self::distDir() . '/.dupheader-test/dupheader-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        // Two Version lines: WordPress installs and reports the FIRST; the
        // constant below matches the first, so the pair only stays clean when
        // the parser is keep-first.
        $head = "Plugin Name:       dupheader-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      7.4\nLicense:           GPL-2.0-or-later\nText Domain:       dupheader-demo\nAuthor:            x\n * Version:           9.9.9\n";
        file_put_contents($tempPlugin . '/dupheader-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'DUPHEADER_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n");
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\DupheaderDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);

        $mainPath = $tempPlugin . '/dupheader-demo.php';
        $headers = wp_connectors_parse_plugin_headers($mainPath);

        $this->assertSame('1.0.0', $headers['version'], 'The parser must keep the FIRST header value, like WordPress.');
        $this->assertSame(
            array(),
            wp_connectors_version_constant_violations($tempPlugin, $headers),
            'The constant check must agree with what WordPress would actually install (first value).'
        );

        $duplicates = wp_connectors_duplicate_header_violations($mainPath, 'dupheader-demo');
        $this->assertNotSame(array(), $duplicates, 'A repeated recognized header must be flagged.');
        $this->assertStringContainsString('duplicate "Version"', $duplicates[0]);
        $this->assertStringContainsString('1.0.0', $duplicates[0]);

        // The builder refuses to package duplicate headers at all.
        try {
            WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            $this->fail('The build must refuse a plugin with duplicate headers.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('duplicate', $e->getMessage());
        }

        WpHarness::rrmdir(dirname($tempPlugin));
    }

    /*
     * Version-constant gate (finding: stale {SLUG}_VERSION built mislabeled zips).
     */

    public function testBuildRefusesAStaleVersionConstant()
    {
        // Copy the fixture, bump the header Version without touching the
        // EXAMPLE_CONNECTOR_VERSION constant: the build must refuse.
        $tempPlugin = self::distDir() . '/.version-test/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $fixtureRoot = __DIR__ . '/fixtures/plugins/example-connector';
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $tempPlugin . '/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        $mainPath = $tempPlugin . '/example-connector.php';
        $main = (string) file_get_contents($mainPath);
        $main = str_replace('Version:           0.1.0', 'Version:           0.2.0', $main);
        file_put_contents($mainPath, $main);

        try {
            WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            $this->fail('The build must refuse a header/constant version mismatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not match header Version', $e->getMessage());
            $this->assertStringContainsString('EXAMPLE_CONNECTOR_VERSION', $e->getMessage());
        }

        WpHarness::rrmdir(dirname($tempPlugin));
    }

    public function testSharedNamespaceRewrite()
    {
        $source = "<?php\ndeclare(strict_types=1);\n\nnamespace Deicod\\WpConnectors\\Shared\\Storage;\n\nuse Deicod\\WpConnectors\\Shared\\Clock;\n\nclass TokenStore\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/Storage/TokenStore.php');

        $this->assertStringContainsString('namespace Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Storage;', $rewritten);
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock;', $rewritten);
        $this->assertStringNotContainsString('namespace Deicod\\WpConnectors\\Shared', $rewritten);
        $this->assertStringContainsString('Generated copy of shared/src/Storage/TokenStore.php', $rewritten);

        // The rewritten file must be valid PHP (provenance placement must not
        // precede the open tag / strict_types) and must load without output.
        $temp = self::distDir() . '/.rewrite-test-' . getmypid() . '.php';
        file_put_contents($temp, $rewritten);
        $output = array();
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temp) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Rewritten shared source must pass php -l: ' . implode("\n", $output));

        ob_start();
        require $temp;
        $emitted = ob_get_clean();
        unlink($temp);
        $this->assertSame('', $emitted, 'Loading a rewritten shared file must not emit output.');
        $this->assertTrue(class_exists('Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Storage\\TokenStore'));
    }

    public function testNamespaceDerivationPreservesTheOpenAiAcronym()
    {
        // The documented namespace for the planned connectors/openai-oauth
        // plugin is Deicod\WpConnectors\OpenAiOauth (docs/CONVENTIONS.md).
        // The ONE shared derivation — used by bin/build.php,
        // bin/check-conventions.php, and the test bootstrap — must preserve
        // the acronym casing (review finding: it previously returned
        // OpenaiOauth everywhere).
        $this->assertSame('OpenAiOauth', wp_connectors_namespace_suffix_from_slug('openai-oauth'));
        $this->assertSame('Zai', wp_connectors_namespace_suffix_from_slug('zai'));
        $this->assertSame('ExampleConnector', wp_connectors_namespace_suffix_from_slug('example-connector'));

        // bin/build.php delegates to the same derivation (one source of truth).
        $this->assertSame('OpenAiOauth', WpConnectorsBuild::namespaceSuffixFromSlug('openai-oauth'));

        // A correctly named future openai-oauth plugin must therefore pass
        // the conventions autoloader check (it previously would have been
        // rejected for not matching the lowercased derivation), while a
        // wrongly cased OpenaiOautH prefix must still fail.
        $tempPlugin = self::distDir() . '/.ns-test/openai-oauth';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\OpenAiOauth\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);

        $this->assertSame(array(), wp_connectors_autoloader_violations($tempPlugin), 'The documented OpenAiOauth prefix must be accepted.');

        file_put_contents($tempPlugin . '/src/autoload.php', str_replace('OpenAiOauth', 'OpenaiOauth', $autoload));
        $this->assertNotSame(array(), wp_connectors_autoloader_violations($tempPlugin), 'A lowercased OpenaiOauth prefix must be rejected.');

        WpHarness::rrmdir(dirname($tempPlugin));
    }

    public function testBuildNeverFollowsSymlinks()
    {
        // Copy the fixture plugin, add a symlink pointing outside the tree,
        // and prove the built zip does not contain the linked file's entry.
        $tempPlugin = self::distDir() . '/.symlink-test/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $fixtureRoot = __DIR__ . '/fixtures/plugins/example-connector';
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $tempPlugin . '/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        $secretOutside = dirname($tempPlugin) . '/outside-secret.txt';
        file_put_contents($secretOutside, 'not-packaged');
        symlink($secretOutside, $tempPlugin . '/leaked-config.txt');

        $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());

        $zip = new ZipArchive();
        $zip->open($zipPath);
        $names = array();
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        unlink($zipPath);
        unlink($zipPath . '.sha256');
        @unlink(self::distDir() . '/checksums.txt');
        WpHarness::rrmdir(dirname($tempPlugin));

        $this->assertNotContains('example-connector/leaked-config.txt', $names, 'Symlinked files must never be packaged.');
        $this->assertContains('example-connector/example-connector.php', $names);
    }

    /**
     * Creates a zip of a deliberately flawed plugin in dist/.
     *
     * @param string $slug          Plugin slug.
     * @param string $extraPhp      PHP appended to the main file.
     * @param bool   $stripHeaders  Remove required headers from the main file.
     * @return string Zip path.
     */
    private function buildBadZip($slug, $extraPhp, $stripHeaders = false)
    {
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      7.4\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        if ($stripHeaders) {
            $head = "Plugin Name:       {$slug}\nAuthor:            x\n";
        }
        $main = "<?php\n/**\n * {$head} */\ndefine( '" . strtoupper(str_replace('-', '_', $slug)) . "_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n{$extraPhp}\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";

        $tmp = self::distDir() . '/.badzip-' . $slug;
        if (is_dir($tmp)) {
            WpHarness::rrmdir($tmp);
        }
        mkdir($tmp . '/' . $slug . '/src', 0755, true);
        file_put_contents($tmp . '/' . $slug . '/' . $slug . '.php', $main);
        file_put_contents($tmp . '/' . $slug . '/src/autoload.php', $autoload);

        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($tmp . '/' . $slug . '/' . $slug . '.php', "{$slug}/{$slug}.php");
        $zip->addFile($tmp . '/' . $slug . '/src/autoload.php', "{$slug}/src/autoload.php");
        $zip->close();
        WpHarness::rrmdir($tmp);

        return $zipPath;
    }
}
