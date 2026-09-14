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

    /**
     * Whether dist/checksums.txt existed (and its content) before this
     * class ran — a prepared dist/ directory's release manifest must
     * survive every build test (Codex R1 finding 3), so tearDown restores
     * it instead of deleting it when it pre-existed.
     *
     * @var string|null
     */
    private static $checksumsBefore = null;

    public static function setUpBeforeClass(): void
    {
        $dist = self::distDir();
        if (! is_dir($dist)) {
            mkdir($dist, 0755, true);
        }

        $manifest = $dist . '/checksums.txt';
        self::$checksumsBefore = is_file($manifest) ? (string) file_get_contents($manifest) : null;
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

        // The shared manifest: restore a prepared one byte-for-byte (builds
        // merge their own entries into it); delete it only when the class
        // itself introduced it.
        $manifest = self::distDir() . '/checksums.txt';
        if (null !== self::$checksumsBefore) {
            file_put_contents($manifest, self::$checksumsBefore);
        } else {
            @unlink($manifest);
        }

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

    /**
     * Task 2.7: the REAL zai plugin artifact ships BOTH providers.
     *
     * The one plugin registers zai and zai_anthropic; the standalone zip
     * must carry both providers' source trees, pass the inspector, and stay
     * self-contained. The dist/ release-verification state around the
     * build is preserved by withArtifactStatePreserved() (Codex R1 #3 /
     * R2 #2) and asserted byte-identical after the restore.
     */
    public function testRealZaiArtifactShipsBothProvidersAndStaysStandalone()
    {
        $this->withArtifactStatePreserved(
            'connectors-zai-0.1.0.zip',
            function (string $zipPath): void {
                $built = WpConnectorsBuild::buildPlugin(__DIR__ . '/../connectors/zai', self::distDir());
                $this->assertSame($zipPath, $built);

                $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-zai'));

                $zip = new ZipArchive();
                $this->assertTrue($zip->open($zipPath));
                $names = array();
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $names[] = $zip->getNameIndex($i);
                }
                $zip->close();

                foreach (array(
                    'zai/assets/zai.svg',
                    'zai/uninstall.php',
                    'zai/LICENSE',
                ) as $required) {
                    $this->assertContains($required, $names, "The artifact must ship {$required}.");
                }

                /*
                 * glm31-8: EVERY connectors/zai/src file ships — the
                 * former hand-picked per-surface class list passed a
                 * third surface's silently-missing classes (and any
                 * renamed/dropped source file outside the eight named
                 * ones), the checklist-in-code drift class
                 * ZaiSurfaceLockstepTest eliminated for the runtime
                 * listings. The sweep covers the providers, models,
                 * metadata directories, authenticator, and aggregator
                 * the old list named by construction, and a packaging
                 * regression on ANY source file fails here.
                 */
                $sourceRoot = realpath(__DIR__ . '/../connectors/zai/src');
                $sourceIterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
                );
                $sourceCount = 0;
                foreach ($sourceIterator as $sourceFile) {
                    $zipEntry = 'zai/src/' . str_replace(
                        DIRECTORY_SEPARATOR,
                        '/',
                        substr($sourceFile->getPathname(), strlen($sourceRoot) + 1)
                    );
                    ++$sourceCount;
                    $this->assertContains($zipEntry, $names, "The artifact must ship every source file (missing {$zipEntry}).");
                }
                $this->assertGreaterThan(0, $sourceCount, 'The source sweep must see the real tree, not an empty root.');

                // Exactly the two expected root PHP files: the one plugin
                // header file (the second provider is a registered class inside
                // the same plugin, not a second plugin) and uninstall.php.
                $mains = array_filter($names, static function (string $name): bool {
                    return 1 === preg_match('/^zai\/[^\/]+\.php$/', $name);
                });
                $this->assertSame(array('zai/uninstall.php', 'zai/zai.php'), array_values($mains));
            },
            function (string $sidecarPrevious, string $manifestPrevious, string $sidecarPath, string $manifestPath): void {
                $this->assertSame($sidecarPrevious, (string) file_get_contents($sidecarPath), 'The checksum sidecar must survive the test byte-for-byte.');
                $this->assertSame($manifestPrevious, (string) file_get_contents($manifestPath), 'The checksum manifest must survive the test byte-for-byte.');
            }
        );
    }

    /**
     * Codex R2 #2: a FAILING build/artifact assertion must not leave the
     * seeded verification files behind — the removal of test-introduced
     * files runs in the OUTER finally of withArtifactStatePreserved(), on
     * every exit path (tearDown only removes example-connector/demo
     * artifacts, so a lingering connectors-zai sidecar would contaminate
     * later runs).
     */
    public function testAFailingArtifactAssertionCleansUpIntroducedVerificationState()
    {
        /*
         * Codex R3 #3: the regression must run against ANY dist/ state,
         * including a prepared release directory — so it drives the guard
         * with a throwaway artifact name that can never collide with a
         * real release sidecar (no unconditional absence precondition on
         * connectors-zai-0.1.0.zip.sha256).
         */
        $zipName = 'connectors-zai-cleanup-probe.zip';
        $sidecarPath = self::distDir() . '/' . $zipName . '.sha256';
        $manifestPath = self::distDir() . '/checksums.txt';
        $manifestExisted = is_file($manifestPath);

        // Defensive: clear any leftover probe sidecar from a previous
        // broken run so the postcondition below stays meaningful.
        @unlink($sidecarPath);

        try {
            $this->withArtifactStatePreserved(
                $zipName,
                function (): void {
                    // Any failing build/artifact assertion — no build needed.
                    $this->fail('simulated artifact assertion failure');
                },
                static function (): void {
                }
            );
            $this->fail('The simulated failure must propagate.');
        } catch (PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('simulated artifact assertion failure', $e->getMessage());
        }

        $this->assertFileDoesNotExist($sidecarPath, 'The introduced probe sidecar must be removed even on a failing path.');
        if (! $manifestExisted) {
            // On a prepared dist/ the helper must (and does) keep the real
            // manifest — the removal postcondition only holds when the test
            // environment had none.
            $this->assertFileDoesNotExist($manifestPath, 'The introduced manifest must be removed even on a failing path.');
        }
    }

    /**
     * Runs $body against a real build's dist/ release-verification state
     * with the state preserved on every exit path.
     *
     * Codex R1 #3: a build run rewrites the zip, its .sha256 sidecar, and
     * the zip's entry in dist/checksums.txt, so all three pieces are
     * snapshotted (seeded with sentinels when absent, so the restore path
     * always runs) and restored byte-for-byte afterwards.
     *
     * Codex R2 #2: the structure is two NESTED finally blocks — the inner
     * one restores state, the OUTER one removes whatever the test
     * introduced — so a failing assertion inside $body cannot skip the
     * cleanup and leave seeded files behind. $verifyRestored runs between
     * the two (success path only) to assert the restoration.
     *
     * @param string   $zipName         Artifact zip basename, e.g. 'connectors-zai-0.1.0.zip'.
     * @param callable $body            Build + artifact assertions; receives the zip path.
     * @param callable $verifyRestored  Post-restore assertions; receives
     *                                  ($sidecarPrevious, $manifestPrevious, $sidecarPath, $manifestPath).
     * @return void
     */
    private function withArtifactStatePreserved(string $zipName, callable $body, callable $verifyRestored): void
    {
        $zipPath = self::distDir() . '/' . $zipName;
        $sidecarPath = $zipPath . '.sha256';
        $manifestPath = self::distDir() . '/checksums.txt';

        $sidecarSeed = '0000000000000000000000000000000000000000000000000000000000000000  ' . $zipName . "\n";
        $manifestSeed = '0000000000000000000000000000000000000000000000000000000000000000  ' . $zipName . "\n";

        $zipExisted = is_file($zipPath);
        $zipPrevious = $zipExisted ? (string) file_get_contents($zipPath) : '';
        $sidecarExisted = is_file($sidecarPath);
        $sidecarPrevious = $sidecarExisted ? (string) file_get_contents($sidecarPath) : $sidecarSeed;
        $manifestExisted = is_file($manifestPath);
        $manifestPrevious = $manifestExisted ? (string) file_get_contents($manifestPath) : $manifestSeed;

        if (! $sidecarExisted) {
            file_put_contents($sidecarPath, $sidecarSeed);
        }
        if (! $manifestExisted) {
            file_put_contents($manifestPath, $manifestSeed);
        }

        try {
            try {
                $body($zipPath);
            } finally {
                // Restore-or-remove every file the build touched.
                if ($zipExisted) {
                    if ($zipPrevious !== (string) file_get_contents($zipPath)) {
                        file_put_contents($zipPath, $zipPrevious);
                    }
                } elseif (is_file($zipPath)) {
                    @unlink($zipPath);
                }

                file_put_contents($sidecarPath, $sidecarPrevious);
                file_put_contents($manifestPath, $manifestPrevious);
            }

            $verifyRestored($sidecarPrevious, $manifestPrevious, $sidecarPath, $manifestPath);
        } finally {
            // Remove ONLY what this test introduced — on every exit path
            // (an assertion failure above must not leave seeded files
            // behind). A prepared dist/ keeps its real state; tearDown's
            // guarded manifest cleanup is a no-op then.
            if (! $sidecarExisted) {
                @unlink($sidecarPath);
            }
            if (! $manifestExisted) {
                @unlink($manifestPath);
            }
        }
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
        $main = "<?php\n/**\n * Plugin Name:       rootfile-demo\n * Version:           1.0.0\n * Requires at least: 6.9\n * Requires PHP:      8.2\n * License:           GPL-2.0-or-later\n * Text Domain:       rootfile-demo\n * Author:            x\n */\ndefine( 'ROOTFILE_DEMO_VERSION', '1.0.0' );\n";
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
        $head = "Plugin Name:       twomain-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       twomain-demo\nAuthor:            x\n";
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
        $head = "Plugin Name:       anchored-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       anchored-demo\nAuthor:            x\n";
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
     * Includes hidden behind variables (finding: `require $dependency;`
     * carries no quoted literal, so an indirectly assigned escaping path
     * reported nothing and shipped in artifacts).
     */

    public function testLiteralFreeIncludesAreResolvedStrictly()
    {
        $tempPlugin = self::distDir() . '/.hidden-include-test/hidden-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src/Settings', 0755, true);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\HiddenDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);
        file_put_contents($tempPlugin . '/src/support.php', "<?php\n// loaded via variable includes\n");
        // (a) Escaping literal assigned to a variable: the anchored-traversal
        // analysis must see through the assignment.
        file_put_contents($tempPlugin . '/escape-via-var.php', "<?php\n\$dependency = dirname(__DIR__, 2) . '/other-plugin/bootstrap.php';\nrequire \$dependency;\n");
        // (b) In-root literal behind a variable: resolves inside the root.
        file_put_contents($tempPlugin . '/src/ok-via-var.php', "<?php\n\$support = __DIR__ . '/support.php';\nrequire \$support;\n");
        // (b2) Anchored-but-inside '..' behind a variable (R4-protected shape).
        file_put_contents($tempPlugin . '/src/Settings/ok-nested-via-var.php', "<?php\n\$up = __DIR__ . '/../support.php';\nrequire \$up;\n");
        // (c1) Variable with no resolvable same-file assignment.
        file_put_contents($tempPlugin . '/unresolved.php', "<?php\nrequire \$unknown;\n");
        // (c2) Runtime-built, unanchored assignment.
        file_put_contents($tempPlugin . '/runtime.php', "<?php\n\$path = get_template_directory() . '/x.php';\nrequire \$path;\n");
        // (c3) Non-variable expression the scanner cannot resolve.
        file_put_contents($tempPlugin . '/indirect.php', "<?php\nrequire \$config['path'];\n");

        $violations = wp_connectors_self_containment_violations($tempPlugin);

        $byFile = array();
        foreach ($violations as $violation) {
            foreach (array( 'escape-via-var.php', 'src/ok-via-var.php', 'src/Settings/ok-nested-via-var.php', 'unresolved.php', 'runtime.php', 'indirect.php', 'src/autoload.php' ) as $relative) {
                if (strpos($violation, $relative) !== false) {
                    $byFile[$relative] = ($byFile[$relative] ?? 0) + 1;
                }
            }
        }
        $this->assertSame(1, $byFile['escape-via-var.php'] ?? 0, 'An escaping literal assigned to a variable must be flagged: ' . implode("\n", $violations));
        $this->assertSame(1, $byFile['unresolved.php'] ?? 0, 'An unresolvable variable include must be flagged.');
        $this->assertSame(1, $byFile['runtime.php'] ?? 0, 'A runtime-built unanchored include must be flagged.');
        $this->assertSame(1, $byFile['indirect.php'] ?? 0, 'A non-variable indirect include must be flagged.');
        $this->assertSame(0, $byFile['src/ok-via-var.php'] ?? 0, 'An in-root literal behind a variable must still be allowed.');
        $this->assertSame(0, $byFile['src/Settings/ok-nested-via-var.php'] ?? 0, 'A nested-but-inside variable include must still be allowed.');
        $this->assertSame(0, $byFile['src/autoload.php'] ?? 0, 'The mandated PSR-4 autoloader include must stay clean.');

        $report = implode("\n", $violations);
        $this->assertStringContainsString('not provably inside the plugin dir', $report);
        $this->assertStringContainsString('escapes upward through dirname()', $report);
        $this->assertStringContainsString('no resolvable same-file assignment', $report);
        $this->assertStringContainsString('is not anchored to __DIR__ or ABSPATH', $report);

        WpHarness::rrmdir(dirname($tempPlugin));
    }

    /*
     * Anchored includes mixing literals with variable segments (finding:
     * `require __DIR__ . '/' . $dependency;` carries a quoted literal, which
     * selected the literal-only analysis and skipped the variable part — an
     * escaping assignment behind it shipped unnoticed).
     */

    public function testMixedLiteralAndVariableIncludesAreAnalyzedPerSegment()
    {
        $tempPlugin = self::distDir() . '/.mixed-include-test/mixed-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        // (c) The mandated PSR-4 autoloader in its DIRECT require form: the
        // str_replace class-name mapping must stay exempt — but ONLY here.
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\MixedDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    require __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);
        file_put_contents($tempPlugin . '/src/support.php', "<?php\n// reached by the mixed in-root include below\n");
        // (a) Escaping literal behind a mixed variable segment.
        file_put_contents($tempPlugin . '/escape-mixed.php', "<?php\n\$dependency = '../../other-plugin/bootstrap.php';\nrequire __DIR__ . '/' . \$dependency;\n");
        // (b) In-root literal behind a mixed variable segment: must pass.
        file_put_contents($tempPlugin . '/ok-mixed.php', "<?php\n\$support = 'src/support.php';\nrequire __DIR__ . '/' . \$support;\n");
        // (d) Mixed literal + variable whose composed path escapes: the
        // statement's own literals stay downward-only, so only the resolved
        // value can reveal the escape.
        file_put_contents($tempPlugin . '/trailing-mixed.php', "<?php\n\$sub = '../../outside';\nrequire __DIR__ . '/' . \$sub . '/partial.php';\n");
        // Unresolvable variable segment.
        file_put_contents($tempPlugin . '/unresolved-mixed.php', "<?php\nrequire __DIR__ . '/' . \$unknown;\n");
        // The autoloader SHAPE outside src/autoload.php is NOT exempt.
        file_put_contents($tempPlugin . '/shape-only.php', "<?php\nrequire __DIR__ . '/' . str_replace( '\\\\', '/', \$class ) . '.php';\n");

        $violations = wp_connectors_self_containment_violations($tempPlugin);

        $byFile = array();
        foreach ($violations as $violation) {
            foreach (array( 'escape-mixed.php', 'ok-mixed.php', 'trailing-mixed.php', 'unresolved-mixed.php', 'shape-only.php', 'src/autoload.php' ) as $relative) {
                if (strpos($violation, $relative) !== false) {
                    $byFile[$relative] = ($byFile[$relative] ?? 0) + 1;
                }
            }
        }
        $this->assertSame(1, $byFile['escape-mixed.php'] ?? 0, 'A mixed include whose variable resolves outside the plugin dir must be flagged exactly once: ' . implode("\n", $violations));
        $this->assertSame(1, $byFile['trailing-mixed.php'] ?? 0, 'A mixed include escaping only through the resolved value must be flagged.');
        $this->assertSame(1, $byFile['unresolved-mixed.php'] ?? 0, 'A mixed include with an unresolvable variable segment must be flagged.');
        $this->assertSame(1, $byFile['shape-only.php'] ?? 0, 'The autoloader shape outside src/autoload.php must stay flagged.');
        $this->assertSame(0, $byFile['ok-mixed.php'] ?? 0, 'A mixed include whose variable resolves in-root must still be allowed.');
        $this->assertSame(0, $byFile['src/autoload.php'] ?? 0, 'The direct-form PSR-4 autoloader include must stay clean.');

        $report = implode("\n", $violations);
        $this->assertStringContainsString('not provably inside the plugin dir', $report);
        $this->assertStringContainsString('resolves outside the plugin dir through $dependency', $report);
        $this->assertStringContainsString('depends on $unknown with no resolvable same-file assignment', $report);
        $this->assertStringContainsString('combines the anchor with unresolvable runtime segments', $report);

        WpHarness::rrmdir(dirname($tempPlugin));
    }

    /*
     * The map + foreach include idiom the uninstall owner chain uses
     * (GLM10 #14): the synthetic binding and the array-literal proof it
     * unlocked must not LAUNDER shapes a direct include is flagged for.
     * Verifier round on that change — three empirically-demonstrated
     * escapes of the first cut, each old-flagged/new-passing at runtime:
     * an anchored map VALUE mixing in a runtime segment, an element
     * append the assignment collector cannot see, and a function
     * parameter default the caller's argument overrides.
     */

    public function testMapAndForeachIncludeLaunderingIsRejected()
    {
        $tempPlugin = self::distDir() . '/.map-launder-test/map-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\MapDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);
        file_put_contents($tempPlugin . '/src/support.php', "<?php\n// the sanctioned in-root map target\n");
        // (a) Anchored map value with a runtime tail: the per-segment
        // proof the direct form applies must judge the map VALUE too.
        file_put_contents($tempPlugin . '/runtime-value.php', "<?php\n\$page = isset(\$_GET['page']) ? \$_GET['page'] : 'home';\n\$map = array( __DIR__ . '/' . \$page . '.php' );\nforeach ( \$map as \$file ) {\n    require \$file;\n}\n");
        // (a2) Same-file-resolvable escaping tail through a map value.
        file_put_contents($tempPlugin . '/trailing-value.php', "<?php\n\$sub = '../../outside';\n\$map = array( __DIR__ . '/' . \$sub . '.php' );\nforeach ( \$map as \$file ) {\n    require \$file;\n}\n");
        // (b) Element append after the literal: an unmodeled write form.
        file_put_contents($tempPlugin . '/append.php', "<?php\n\$map = array( __DIR__ . '/src/support.php' );\n\$map[] = '/tmp/abs-target-test.php';\nforeach ( \$map as \$file ) {\n    require \$file;\n}\n");
        // (c) Function parameter default the caller overrides: the
        // assignment regex sees the default, the runtime sees the caller.
        file_put_contents($tempPlugin . '/param-default.php', "<?php\nfunction map_demo_load( \$map = array( __DIR__ . '/src/support.php' ) ) {\n    foreach ( \$map as \$file ) {\n        require \$file;\n    }\n}\nmap_demo_load( array( '/tmp/abs-target-test.php' ) );\n");
        // (d) The sanctioned shape: literal-only map values, whole-array
        // writes only — exactly the uninstall owner chain's idiom.
        file_put_contents($tempPlugin . '/sanctioned.php', "<?php\n\$map = array( __DIR__ . '/src/support.php' );\nforeach ( \$map as \$class => \$file ) {\n    if ( ! class_exists( \$class, false ) ) {\n        require_once \$file;\n    }\n}\n");

        $violations = wp_connectors_self_containment_violations($tempPlugin);

        $byFile = array();
        foreach ($violations as $violation) {
            foreach (array( 'runtime-value.php', 'trailing-value.php', 'append.php', 'param-default.php', 'sanctioned.php', 'src/autoload.php' ) as $relative) {
                if (strpos($violation, $relative) !== false) {
                    $byFile[$relative] = ($byFile[$relative] ?? 0) + 1;
                }
            }
        }
        $this->assertSame(1, $byFile['runtime-value.php'] ?? 0, 'A map value mixing the anchor with an unresolvable runtime segment must be flagged: ' . implode("\n", $violations));
        $this->assertSame(1, $byFile['trailing-value.php'] ?? 0, 'A map value escaping only through its resolved tail must be flagged.');
        $this->assertSame(1, $byFile['append.php'] ?? 0, 'An element append after the literal must refuse the map proof.');
        $this->assertSame(1, $byFile['param-default.php'] ?? 0, 'A parameter default the caller overrides must refuse the map proof.');
        $this->assertSame(0, $byFile['sanctioned.php'] ?? 0, 'The sanctioned literal-only map shape must stay clean.');
        $this->assertSame(0, $byFile['src/autoload.php'] ?? 0, 'The mandated PSR-4 autoloader include must stay clean.');

        WpHarness::rrmdir(dirname($tempPlugin));
    }

    /*
     * glm15-2: string and heredoc CONTENTS are never analyzed as code.
     * The analyzer's scans used to run over regex comment-stripped
     * source, so an assignment, signature, or include keyword written
     * inside a quoted string or heredoc body counted as a statement:
     * a phantom in-string assignment could SATISFY a variable include
     * the runtime never resolves that way (a false accept), and phantom
     * includes/writes could refuse legitimate files (false flags).
     */

    public function testStringAndHeredocContentsAreNeverAnalyzedAsCode()
    {
        $tempPlugin = self::distDir() . '/.string-contents-test/string-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\StringDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);
        file_put_contents($tempPlugin . '/src/support.php', "<?php\n// the in-root target every clean fixture below reaches\n");

        // (a) Decoys in single-quoted, interpolated, and nowdoc strings
        // around a REAL in-root variable include: nothing may be flagged.
        $stringsClean = <<<'FIXTURE'
<?php
$decoy = '$path = "/tmp/abs-target-test.php";';
$banner = "welcome {$map['k']} require $path;";
$note = <<<'TXT'
// a comment-looking heredoc line
$path = '/tmp/heredoc-decoy-test.php';
function not_a_real_signature( $map = array() ) {}
require $path;
TXT;
$path = __DIR__ . '/src/support.php';
require $path;
FIXTURE;
        file_put_contents($tempPlugin . '/strings-clean.php', $stringsClean);

        // (b) The ONLY "assignment" to $dep lives inside a string: at
        // runtime $dep is whatever the caller passed, so the include
        // must stay flagged — the phantom assignment must not satisfy
        // it (the false-accept direction).
        $stringOnly = <<<'FIXTURE'
<?php
$decoy = '$dep = __DIR__ . "/src/support.php";';
require $dep;
FIXTURE;
        file_put_contents($tempPlugin . '/string-only-assignment.php', $stringOnly);

        // (c) An escaping include written inside a nowdoc: text the
        // plugin merely prints, never a statement (the false-flag
        // direction).
        $heredocInclude = <<<'FIXTURE'
<?php
$note = <<<'TXT'
require __DIR__ . '/../../outside/bootstrap.php';
TXT;
require __DIR__ . '/src/support.php';
FIXTURE;
        file_put_contents($tempPlugin . '/heredoc-include.php', $heredocInclude);

        // (d) Real comments carrying include/assignment text stay
        // ignored (pins the token-based comment strip).
        $commentDecoy = <<<'FIXTURE'
<?php
// require '/outside.php';
/* $x = '/tmp/x.php'; */
require __DIR__ . '/src/support.php';
FIXTURE;
        file_put_contents($tempPlugin . '/comment-decoy.php', $commentDecoy);

        // (e) The sanctioned map idiom in a file that ALSO carries
        // map-writings text inside strings: the phantom element write
        // and signature must not refuse the literal proof.
        $mapDecoy = <<<'FIXTURE'
<?php
$doc = '$map[] = "/tmp/append.php"; function f( $map = array() ) {}';
$map = array( __DIR__ . '/src/support.php' );
foreach ( $map as $class => $file ) {
    if ( ! class_exists( $class, false ) ) {
        require_once $file;
    }
}
FIXTURE;
        file_put_contents($tempPlugin . '/map-decoy.php', $mapDecoy);

        $violations = wp_connectors_self_containment_violations($tempPlugin);

        $byFile = array();
        foreach ($violations as $violation) {
            foreach (array( 'strings-clean.php', 'string-only-assignment.php', 'heredoc-include.php', 'comment-decoy.php', 'map-decoy.php', 'src/autoload.php' ) as $relative) {
                if (strpos($violation, $relative) !== false) {
                    $byFile[$relative] = ($byFile[$relative] ?? 0) + 1;
                }
            }
        }
        $this->assertSame(0, $byFile['strings-clean.php'] ?? 0, 'Decoy code text inside strings/heredocs must never be flagged: ' . implode("\n", $violations));
        $this->assertSame(1, $byFile['string-only-assignment.php'] ?? 0, 'A variable include whose only assignment is string text must stay flagged.');
        $this->assertSame(0, $byFile['heredoc-include.php'] ?? 0, 'An include keyword inside a nowdoc body is not a statement.');
        $this->assertSame(0, $byFile['comment-decoy.php'] ?? 0, 'Include text inside real comments must stay ignored.');
        $this->assertSame(0, $byFile['map-decoy.php'] ?? 0, 'Phantom map writes inside strings must not refuse the sanctioned map proof.');
        $this->assertSame(0, $byFile['src/autoload.php'] ?? 0, 'The mandated PSR-4 autoloader include must stay clean.');

        $this->assertStringContainsString('no resolvable same-file assignment', implode("\n", $violations));

        WpHarness::rrmdir(dirname($tempPlugin));
    }

    /*
     * All-plugin build mode (finding: a connector directory without a valid
     * main-file header was silently omitted from the no-argument build — a
     * damaged or new connector vanished from a release with exit 0).
     */

    public function testAllPluginBuildRejectsAMalformedConnectorDirectory()
    {
        $repo = $this->makeBuildCliRepo(array( 'good-demo' => true, 'broken-demo' => false ));

        $output = array();
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' 2>&1', $output, $exit);

        $report = implode("\n", $output);
        $this->assertSame(1, $exit, "A malformed connector directory must fail the all-plugin build:\n{$report}");
        $this->assertStringContainsString('no main plugin file', $report);
        $this->assertStringContainsString('broken-demo', $report, 'The failing run must name the malformed directory.');
        // The healthy connector is still built and reported in the same run.
        $this->assertStringContainsString('connectors-good-demo-1.0.0.zip', $report);
        $this->assertFileExists($repo . '/dist/connectors-good-demo-1.0.0.zip');

        WpHarness::rrmdir($repo);
    }

    public function testAllPluginBuildPackagesEveryValidConnectorDirectory()
    {
        $repo = $this->makeBuildCliRepo(array( 'alpha-demo' => true, 'beta-demo' => true ));

        $output = array();
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' 2>&1', $output, $exit);

        $report = implode("\n", $output);
        $this->assertSame(0, $exit, "An all-valid connectors/ tree must build cleanly:\n{$report}");
        foreach (array( 'alpha-demo', 'beta-demo' ) as $slug) {
            $this->assertFileExists($repo . '/dist/connectors-' . $slug . '-1.0.0.zip', "Every valid connector dir must produce its zip: {$slug}");
        }
        $checksums = (string) file_get_contents($repo . '/dist/checksums.txt');
        $this->assertStringContainsString('connectors-alpha-demo-1.0.0.zip', $checksums);
        $this->assertStringContainsString('connectors-beta-demo-1.0.0.zip', $checksums);

        WpHarness::rrmdir($repo);
    }

    public function testExplicitSlugBuildStillRejectsAMalformedConnector()
    {
        $repo = $this->makeBuildCliRepo(array( 'broken-demo' => false ));

        $output = array();
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=broken-demo 2>&1', $output, $exit);

        $this->assertSame(1, $exit, 'Explicit-slug mode must keep rejecting a malformed connector.');
        $this->assertStringContainsString('no main plugin file', implode("\n", $output));

        WpHarness::rrmdir($repo);
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
        $head = "Plugin Name:       dupheader-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       dupheader-demo\nAuthor:            x\n * Version:           9.9.9\n";
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

    /**
     * Fix-round pin (t31-r2-12): embed_shared collected from shared/ —
     * the PARENT of the source directory — so a plugin opting in would
     * ship shared/'s dev files (README.md et al.) inside its zip, and
     * the global str_replace('src/', '', ...) would mangle any nested
     * 'src/' path segment. Fixed before the first consumer turns the
     * flag on (the bug predates this branch — master code; fixed here
     * because this branch populates shared/).
     *
     * End-to-end with a fixture plugin that opts in, under the ONE
     * assertion the pair carries (t31-r3-7, per t31-r2-18's design):
     * shared/src's PHP SOURCES ship — each at its exact relative path
     * under src/Shared/, rewritten — and nothing else does (every
     * src/Shared/ entry is a PHP source; no dev file lands anywhere).
     * The old 'everything under shared/src ships' sweep re-encoded the
     * invariant t31-r2-18 removed and contradicted the sibling
     * PHP-sources-only pin; the pair states one contract now.
     */
    public function testEmbedSharedShipsOnlyTheSourceTreeUnderSrcShared()
    {
        $tempPlugin = self::distDir() . '/.embed-test/example-connector';
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
        file_put_contents($tempPlugin . '/build.json', "{\"embed_shared\": true}\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $names = array();
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = $zip->getNameIndex($i);
            }

            // Every shared/src PHP SOURCE ships, at its exact relative
            // path under src/Shared/ (the glm31-8 sweep shape — a
            // dropped or mis-staged source file fails here, and the
            // mapping is prefix-exact: no global segment stripping).
            // The enumeration is independent of the build's collector
            // (a raw tree walk) so the two cannot agree by construction.
            $sourceRoot = realpath(__DIR__ . '/../shared/src');
            $sourceIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
            );
            $sourceCount = 0;
            foreach ($sourceIterator as $sourceFile) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($sourceFile->getPathname(), strlen($sourceRoot) + 1));
                if ('.php' !== strtolower(substr($relative, -4))) {
                    continue;
                }
                ++$sourceCount;
                $this->assertContains('example-connector/src/Shared/' . $relative, $names, "The embedded copy of {$relative} must ship at its exact shared/src-relative path.");
            }
            $this->assertGreaterThanOrEqual(20, $sourceCount, 'The embed sweep must see the real shared source tree.');

            // PHP sources ONLY: every src/Shared/ entry is one — a
            // non-PHP file inside shared/src (or anywhere else) never
            // ships (t31-r2-18's rule, stated against the real tree;
            // the shared README is the shape the old parent-directory
            // collection shipped).
            foreach ($names as $entry) {
                if (false !== strpos($entry, 'src/Shared/')) {
                    $this->assertSame('.php', strtolower(substr($entry, -4)), "Only PHP sources may ship under src/Shared/ (saw {$entry}).");
                }
                $this->assertStringNotContainsString('README', $entry, "No shared/ dev file may land in the zip (saw {$entry}).");
            }

            // The embedded copy is the REWRITTEN one: plugin-private
            // namespace and the shared/src-prefixed provenance.
            $suffix = WpConnectorsBuild::namespaceSuffixFromSlug('example-connector');
            $embedded = (string) $zip->getFromName('example-connector/src/Shared/Http/HeaderMap.php');
            $zip->close();
            $this->assertStringContainsString('namespace Deicod\\WpConnectors\\' . $suffix . '\\Shared\\Http;', $embedded);
            $this->assertStringContainsString('Generated copy of shared/src/Http/HeaderMap.php', $embedded);
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Verifier-round pin (t31-r2-18): collectFiles() filters by
     * excluded path NAMES only, so the embed collection would ship any
     * non-PHP file committed inside shared/src (notes, READMEs)
     * byte-identical into plugin zips — the t31-r2-12 pin proved the
     * shared/ parent case, which the new collection root cannot see;
     * this pin drives the INSIDE-the-source-directory case. The build
     * root is scratch (buildPlugin resolves shared/ from
     * dirname($distDir)), with both a dev file and a PHP source
     * planted in its shared/src.
     */
    public function testEmbedSharedShipsOnlyPhpSourcesEvenFromInsideTheSourceDirectory()
    {
        $scratch = self::distDir() . '/.embed-scratch';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        mkdir($scratch . '/plugin', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
        file_put_contents($scratch . '/shared/src/Notes.md', "# Developer scratch notes\n");
        file_put_contents($scratch . '/shared/src/README.md', "# shared\n");

        $fixtureRoot = __DIR__ . '/fixtures/plugins/example-connector';
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $scratch . '/plugin/example-connector/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $names = array();
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();

            $this->assertContains('example-connector/src/Shared/Clock/ClockInterface.php', $names, 'The PHP source inside shared/src must ship.');
            foreach ($names as $entry) {
                $this->assertStringNotContainsString('Notes.md', $entry, 'A dev file inside shared/src must not ship.');
                $this->assertStringNotContainsString('README.md', $entry, 'A README inside shared/src must not ship.');
            }
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-1): a malformed build.json silently disabled
     * embed_shared — json_decode() null → is_array() false → the block
     * skipped → the zip shipped WITHOUT the shared library while the run
     * exited 0 with a checksum (reproduced at HEAD with a trailing
     * comma). build.json is resolved and validated ONCE at the config
     * seam now, before any filesystem mutation: a trailing comma (the
     * repro), an empty file, and a non-object top level each refuse the
     * build loudly, and the valid opt-in still builds through the same
     * seam.
     */
    public function testAMalformedBuildJsonRefusesTheBuildLoudly()
    {
        $scratch = self::distDir() . '/.embed-malformed';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");

        $fixtureRoot = __DIR__ . '/fixtures/plugins/example-connector';
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $scratch . '/plugin/example-connector/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }

        try {
            foreach (array(
                'trailing comma' => "{\"embed_shared\": true,\n}\n",
                'empty file' => '',
                'scalar top level' => "\"yes\"\n",
            ) as $label => $payload) {
                file_put_contents($scratch . '/plugin/example-connector/build.json', $payload);
                try {
                    WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                    $this->fail("A malformed build.json ({$label}) must refuse the build, never silently skip embed_shared.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('malformed', $e->getMessage());
                    $this->assertStringContainsString('build.json', $e->getMessage());
                }
            }

            // The seam fires before any filesystem mutation: no zip (or
            // staging residue) may exist after the refused runs.
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'A refused build must leave no zip behind.');
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector');

            // Control: the valid opt-in still embeds through the seam.
            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $found = false;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $found = $found || 'example-connector/src/Shared/Clock/ClockInterface.php' === $zip->getNameIndex($i);
            }
            $zip->close();
            $this->assertTrue($found, 'The control build (valid build.json) must still embed the shared source.');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-6): the staging lifecycle leaked on failure —
     * the per-file namespace-suffix validation threw AFTER the
     * dist/.stage-<slug> tree existed (rrmdir only ran on the success
     * path), and a zip open/addFile failure left a partial zip plus the
     * staging tree (reproduced at HEAD with an 'Evil$1' suffix). The
     * suffix is validated ONCE at the config seam — before any
     * filesystem mutation — and the whole staging lifecycle runs inside
     * one try/catch/finally that tears the stage down and removes a
     * partial zip on every throw.
     */
    public function testAFailingBuildLeavesNoStagingResidueBehind()
    {
        $scratch = self::distDir() . '/.embed-residue';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");

        $fixtureRoot = __DIR__ . '/fixtures/plugins/example-connector';
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $scratch . '/plugin/example-connector/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }

        try {
            // (a) An illegal namespace_suffix refuses at the seam: the
            // throw precedes every filesystem mutation, so no stage tree
            // and no zip ever exist.
            file_put_contents($scratch . '/plugin/example-connector/build.json', '{"embed_shared": true, "namespace_suffix": "Evil$1"}');
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An illegal namespace_suffix must refuse the build.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('namespace segment', $e->getMessage());
            }
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector', 'The seam refusal must precede the staging tree.');
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The seam refusal must leave no zip.');

            // (b) A throw that lands MID-BUILD, after the stage tree and
            // entries exist: the zip cannot be created because a
            // directory sits at the zip path. The catch/finally must
            // remove the staging tree (the old code leaked it).
            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
            $blockedZip = $scratch . '/dist/connectors-example-connector-0.1.0.zip';
            mkdir($blockedZip, 0755, true);
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An un-creatable zip path must fail the build.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot create', $e->getMessage());
            }
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector', 'A mid-build throw must tear the staging tree down.');

            // Recovery: the same inputs build cleanly once the blocker
            // is gone (the failed run left nothing behind to collide).
            rmdir($blockedZip);
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($zipPath);
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector', 'The success path must tear the staging tree down too.');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-5), end-to-end through the build: the
     * derivation legally produced digit-initial suffixes
     * ('3cx-oauth' -> '3cxOauth') that the namespace-segment validator
     * rejects — a PHP label may not start with a digit, so the DERIVED
     * spelling was never a declarable namespace. The derivation
     * underscores digit-initial suffixes ('_3cxOauth'), the validator's
     * label law is unchanged, and a digit-initial slug now builds: the
     * conventions gates pass, the autoloader prefix matches the
     * derivation, and the embedded shared copy carries the legal,
     * lint-clean namespace.
     */
    public function testADigitInitialSlugDerivesAndBuildsALegalNamespace()
    {
        $scratch = self::distDir() . '/.digit-slug';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");

        $plugin = $scratch . '/plugin/3cx-oauth';
        mkdir($plugin . '/src', 0755, true);
        $head = "Plugin Name:       3cx-oauth\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       3cx-oauth\nAuthor:            x\n";
        file_put_contents($plugin . '/3cx-oauth.php', "<?php\n/**\n * {$head} */\ndefine( '3CX_OAUTH_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n");
        $suffix = wp_connectors_namespace_suffix_from_slug('3cx-oauth');
        $this->assertSame('_3cxOauth', $suffix);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\{$suffix}\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($plugin . '/src/autoload.php', $autoload);
        file_put_contents($plugin . '/build.json', "{\"embed_shared\": true}\n");

        try {
            // The conventions gates the build rides accept the digit-initial
            // slug (headers, version constant, the derivation-matched
            // autoloader prefix) — the derivation and the validator agree.
            $this->assertSame(array(), wp_connectors_autoloader_violations($plugin));

            $zipPath = WpConnectorsBuild::buildPlugin($plugin, $scratch . '/dist');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $embedded = (string) $zip->getFromName('3cx-oauth/src/Shared/Clock/ClockInterface.php');
            $zip->close();
            $this->assertStringContainsString('namespace Deicod\\WpConnectors\\_3cxOauth\\Shared\\Clock;', $embedded);

            // The embedded copy is declarable PHP, not just a string the
            // zip accepted: the underscored namespace lints clean.
            $lintTarget = $scratch . '/embedded-copy.php';
            file_put_contents($lintTarget, $embedded);
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lintTarget) . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, 'The embedded copy under a digit-initial slug must lint: ' . implode("\n", $output));
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-4): the embed collection reused
     * collectFiles(), whose EXCLUDED_PATHS dropped any shared/src
     * subdirectory named tests/tools/dist/vendor — a shared source
     * living there loaded in development (the dev autoloader walks the
     * whole tree), passed the architecture sweep (same walk), and then
     * silently missed the zip, so the shipped plugin fataled on the
     * missing class (the inverse of t31-r2-12's dev-files-shipping
     * class). The shared-source collector applies NO exclusion
     * segments: every PHP source under shared/src ships, wherever it
     * lives; non-PHP files still do not.
     */
    public function testEverySharedPhpSourceShipsEvenFromExcludedNamedSubdirectories()
    {
        $scratch = self::distDir() . '/.embed-excluded-names';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
        // One PHP source in EVERY excluded-name subdirectory: each must ship.
        foreach (array('tools', 'tests', 'dist', 'vendor') as $excludedName) {
            $type = ucfirst($excludedName) . 'Source';
            mkdir($scratch . '/shared/src/' . $excludedName, 0755, true);
            file_put_contents(
                $scratch . '/shared/src/' . $excludedName . '/' . $type . '.php',
                "<?php\nnamespace Deicod\\WpConnectors\\Shared\\{$excludedName};\ninterface {$type} {}\n"
            );
        }
        // The PHP-source filter itself is unchanged: non-PHP dev files
        // inside shared/src still never ship (the t31-r2-18 rule).
        file_put_contents($scratch . '/shared/src/Notes.md', "# Developer scratch notes\n");

        $fixtureRoot = __DIR__ . '/fixtures/plugins/example-connector';
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $scratch . '/plugin/example-connector/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $names = array();
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();

            $this->assertContains('example-connector/src/Shared/Clock/ClockInterface.php', $names, 'An ordinary shared source must ship.');
            foreach (array('Tools', 'Tests', 'Dist', 'Vendor') as $excludedName) {
                $this->assertContains(
                    'example-connector/src/Shared/' . strtolower($excludedName) . '/' . $excludedName . 'Source.php',
                    $names,
                    "A PHP source inside shared/src/{$excludedName}/ must ship — exclusions are a dist-tree concept, not a shared-source concept."
                );
            }
            foreach ($names as $entry) {
                $this->assertStringNotContainsString('Notes.md', $entry, 'A non-PHP file inside shared/src must still not ship.');
            }
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-9), the build half: the '.php' extension
     * filter was case-sensitive, so a ClockMath.PHP source was silently
     * skipped from embeds — a class development loads (any extension
     * case resolves) that the shipped plugin fatals on. The extension
     * match is case-insensitive in the ONE shared collector the embed
     * rides: the .PHP-spelled source ships like any other, rewritten.
     */
    public function testAnUpperCaseSpelledPhpSourceShipsInTheEmbed()
    {
        $scratch = self::distDir() . '/.embed-phpcase';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
        file_put_contents($scratch . '/shared/src/ClockMath.PHP', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");

        $fixtureRoot = __DIR__ . '/fixtures/plugins/example-connector';
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $scratch . '/plugin/example-connector/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $names = array();
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $names[] = $zip->getNameIndex($i);
            }
            $embedded = (string) $zip->getFromName('example-connector/src/Shared/ClockMath.PHP');
            $zip->close();

            $this->assertContains('example-connector/src/Shared/ClockMath.PHP', $names, 'A .PHP-spelled source must ship at its exact path.');
            $this->assertContains('example-connector/src/Shared/Clock/ClockInterface.php', $names, 'The ordinary .php spelling keeps shipping.');
            $suffix = WpConnectorsBuild::namespaceSuffixFromSlug('example-connector');
            $this->assertStringContainsString('namespace Deicod\\WpConnectors\\' . $suffix . '\\Shared;', $embedded, 'The .PHP-spelled copy must be the REWRITTEN one.');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    public function testSharedNamespaceRewrite()
    {
        $source = "<?php\ndeclare(strict_types=1);\n\nnamespace Deicod\\WpConnectors\\Shared\\Storage;\n\nuse Deicod\\WpConnectors\\Shared\\Clock;\n\nclass TokenStore\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/Storage/TokenStore.php');

        $this->assertStringContainsString('namespace Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Storage;', $rewritten);
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock;', $rewritten);
        $this->assertStringNotContainsString('namespace Deicod\\WpConnectors\\Shared', $rewritten);
        $this->assertStringContainsString('Generated copy of shared/src/Storage/TokenStore.php', $rewritten);

        // Verifier-round pin (t31-r2-19): both interpolated values land
        // in preg_replace REPLACEMENT strings, where '$1' is
        // backreference material — a suffix 'Evil$1' shipped a parse
        // error into the zip, and a '$1' in the provenance path was
        // silently consumed. The suffix must be a namespace segment
        // (refused loudly otherwise) and the provenance string escapes
        // its replacement metacharacters.
        foreach (array('Evil$1', 'X${1}Y', 'Z\\1W', '123Starts', '', 'Has-Dash') as $hostile) {
            try {
                WpConnectorsBuild::rewriteSharedNamespace($source, $hostile, 'shared/src/Storage/TokenStore.php');
                $this->fail('A namespace_suffix that is not a namespace segment must be refused: ' . var_export($hostile, true));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('namespace segment', $e->getMessage());
            }
        }

        $dollarPath = WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/Evil$1Name.php');
        $this->assertStringContainsString('Generated copy of shared/src/Evil$1Name.php', $dollarPath, 'A dollar in the provenance path must render literally, never be consumed as a backreference.');

        // Fix-round pin (t31-r3-2): the use-rewrite required a trailing
        // separator after Shared, so the exact-namespace import, its
        // aliased form, and every 'use function/const' spelling survived
        // byte-identical (verified at HEAD) — the embedded copy imported
        // the source namespace, which no longer exists inside the plugin:
        // a class-not-found fatal on load. One pattern rewrites every
        // legal spelling now (plain, aliased, function, const, fully
        // qualified, exact, brace-group), and the rewrite's postcondition
        // refuses anything the pattern does not know — silent survival is
        // the defect this closes.
        $spellings = "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Storage;\nuse Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared as SharedNs;\nuse Deicod\\WpConnectors\\Shared\\Clock;\nuse Deicod\\WpConnectors\\Shared\\Clock as C;\nuse \\Deicod\\WpConnectors\\Shared\\Token;\nuse function Deicod\\WpConnectors\\Shared\\Clock\\now;\nuse function Deicod\\WpConnectors\\Shared\\Clock\\now as nowish;\nuse const Deicod\\WpConnectors\\Shared\\TTL;\nuse Deicod\\WpConnectors\\Shared\\Http\\{HeaderMap, Url as U};\nclass TokenStore\n{\n}\n";
        $battery = WpConnectorsBuild::rewriteSharedNamespace($spellings, 'OpenAiOauth', 'shared/src/Storage/TokenStore.php');
        $this->assertStringNotContainsString('Deicod\\WpConnectors\\Shared\\', $battery, 'No sub-segmented spelling may survive.');
        $this->assertStringNotContainsString('Deicod\\WpConnectors\\Shared;', $battery, 'No exact-namespace spelling may survive.');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared;', $battery, 'The exact-namespace import is rewritten.');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared as SharedNs;', $battery, 'The aliased exact-namespace import is rewritten.');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock as C;', $battery, 'The aliased sub-segment import is rewritten.');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Token;', $battery, 'A fully qualified import keeps its leading backslash, rewritten.');
        $this->assertStringContainsString('use function Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\now;', $battery, "A 'use function' spelling is rewritten.");
        $this->assertStringContainsString('use function Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\now as nowish;', $battery, "An aliased 'use function' spelling is rewritten.");
        $this->assertStringContainsString('use const Deicod\\WpConnectors\\OpenAiOauth\\Shared\\TTL;', $battery, "A 'use const' spelling is rewritten.");
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Http\\{HeaderMap, Url as U};', $battery, 'The brace-group form is rewritten (members are relative — the prefix carries them).');

        // A DIFFERENT namespace that merely starts with 'Shared'
        // ('SharedStorage') is neither rewritten nor refused: the
        // postcondition's lookahead keeps longer names out of scope.
        $foreign = "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Storage;\nuse Deicod\\WpConnectors\\SharedStorage\\Widget;\nclass WidgetStore\n{\n}\n";
        $foreignRewritten = WpConnectorsBuild::rewriteSharedNamespace($foreign, 'OpenAiOauth', 'shared/src/Storage/WidgetStore.php');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\SharedStorage\\Widget;', $foreignRewritten, 'A Shared-prefixed FOREIGN namespace stays untouched.');

        // The postcondition: a spelling the pattern does not know (a
        // comment-interrupted use line) refuses the rewrite loudly —
        // never ships a broken import silently.
        $interrupted = "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Storage;\nuse Deicod\\WpConnectors\\Shared\\Clock /* timing */ as C;\nclass ClockStore\n{\n}\n";
        try {
            WpConnectorsBuild::rewriteSharedNamespace($interrupted, 'OpenAiOauth', 'shared/src/Storage/ClockStore.php');
            $this->fail('An unhandled shared-namespace spelling must refuse the rewrite, never survive it.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('survived the rewrite', $e->getMessage());
            $this->assertStringContainsString('ClockStore.php', $e->getMessage());
        }

        // The rewritten file must be valid PHP (provenance placement must not
        // precede the open tag / strict_types) and must load without output.
        // Scratch hygiene (t31-r3-11): the lint/load scratch matches no
        // tearDown glob, so its lifecycle rides try/finally — an assertion
        // failure between write and unlink must not leak it into dist/.
        $temp = self::distDir() . '/.rewrite-test-' . getmypid() . '.php';
        try {
            file_put_contents($temp, $rewritten);
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temp) . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, 'Rewritten shared source must pass php -l: ' . implode("\n", $output));

            ob_start();
            require $temp;
            $emitted = ob_get_clean();
        } finally {
            @unlink($temp);
        }
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

        // Fix-round pin (t31-r3-5): a digit-initial slug is a legal plugin
        // slug whose naive derivation ('3cx-oauth' -> '3cxOauth') is NOT a
        // legal PHP label — the validator rejected what the derivation
        // produced. The derivation underscores it: a legal segment, the
        // same one for the conventions checker, the builder, and the dev
        // autoloader.
        $this->assertSame('_3cxOauth', wp_connectors_namespace_suffix_from_slug('3cx-oauth'));
        $this->assertSame('_42', wp_connectors_namespace_suffix_from_slug('42'));
        $this->assertSame('X3Dev', wp_connectors_namespace_suffix_from_slug('x3-dev'), 'Only a digit-INITIAL derivation is underscored.');

        // bin/build.php delegates to the same derivation (one source of truth).
        $this->assertSame('OpenAiOauth', WpConnectorsBuild::namespaceSuffixFromSlug('openai-oauth'));
        $this->assertSame('_3cxOauth', WpConnectorsBuild::namespaceSuffixFromSlug('3cx-oauth'));

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
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
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

    /**
     * Assembles a throwaway repository for CLI-mode build runs.
     *
     * Copies bin/build.php and its lib into a temp root and creates the
     * requested connectors/ subdirectories (valid plugins, or malformed
     * directories whose main file lost its Plugin Name header), so the
     * guarded CLI entry point can be exercised as a subprocess.
     *
     * @param array<string,bool> $connectors Slug => whether the dir is valid.
     * @return string Absolute temp repo root.
     */
    private function makeBuildCliRepo(array $connectors)
    {
        $repo = self::distDir() . '/.build-cli-' . getmypid() . '-' . substr(md5((string) json_encode($connectors)), 0, 6);
        if (is_dir($repo)) {
            WpHarness::rrmdir($repo);
        }
        mkdir($repo . '/bin/lib', 0755, true);
        copy(__DIR__ . '/../bin/build.php', $repo . '/bin/build.php');
        copy(__DIR__ . '/../bin/lib/plugin-tools.php', $repo . '/bin/lib/plugin-tools.php');

        foreach ($connectors as $slug => $valid) {
            $pluginDir = $repo . '/connectors/' . $slug;
            mkdir($pluginDir . '/src', 0755, true);
            if (! $valid) {
                // Damaged/new connector: PHP present, header lost.
                file_put_contents($pluginDir . '/' . $slug . '.php', "<?php\necho 'header lost';\n");
                continue;
            }
            $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
            $main = "<?php\n/**\n * {$head} */\ndefine( '" . strtoupper(str_replace('-', '_', $slug)) . "_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
            file_put_contents($pluginDir . '/' . $slug . '.php', $main);
            $suffix = wp_connectors_namespace_suffix_from_slug($slug);
            $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\{$suffix}\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
            file_put_contents($pluginDir . '/src/autoload.php', $autoload);
        }

        return $repo;
    }
}
