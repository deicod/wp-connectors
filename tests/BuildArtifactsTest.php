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

    /**
     * Asserts the plugin's staging tree is gone — ANY pid spelling
     * (t31-r10-4 renamed the stage `.stage-<slug>-<pid>`; assertions
     * pinned to the old pid-less name would pass vacuously forever).
     *
     * @param string $distDir Absolute dist directory.
     * @param string $slug    Plugin slug.
     * @param string $message Failure message.
     * @return void
     */
    private static function assertNoStageTree( string $distDir, string $slug, string $message = '' ): void {
        self::assertSame( array(), glob( $distDir . '/.stage-' . $slug . '*' ) ?: array(), $message );
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

                $names = $this->zipEntryNames($zipPath);

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

    /**
     * Review-round pin (t31-r12-6): the CLI success echo interpolated
     * hash_file() unchecked, so a zip unreadable in the window between
     * buildPlugin() returning and the echo printed 'sha256=' BLANK at
     * exit 0 — the conflation the sidecar seam refuses at its own
     * checksum step. The guarded helper owns the digest now: the
     * refusal names the artifact, and the happy path is byte-identical.
     */
    public function testTheSuccessLineDigestRefusesWhenThePublishedZipCannotBeRead()
    {
        $zipPath = $this->buildFixture();
        $this->assertSame(hash_file('sha256', $zipPath), WpConnectorsBuild::publishedChecksum($zipPath), 'The happy path keeps the exact digest the line printed before.');

        // The deleted-zip window.
        unlink($zipPath);
        try {
            WpConnectorsBuild::publishedChecksum($zipPath);
            $this->fail('A vanished published zip must refuse the success-line digest, never print it blank.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot checksum the published', $e->getMessage());
            $this->assertStringContainsString(basename($zipPath), $e->getMessage(), 'The refusal names the artifact.');
        }

        // The chmod-000 window (non-root spelling, restored in finally).
        $rebuilt = $this->buildFixture();
        chmod($rebuilt, 0000);
        try {
            try {
                WpConnectorsBuild::publishedChecksum($rebuilt);
                $this->fail('An unreadable published zip must refuse the success-line digest.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot checksum the published', $e->getMessage());
            }
        } finally {
            chmod($rebuilt, 0644);
        }
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
            // The extension judgment rides the ONE owner (t31-r5-9): the
            // exact-case getExtension() check skipped '.PHP' entries while
            // every gate had migrated to the shared judgment — a false
            // green for exactly the parse-broken-.PHP class (the sibling
            // spellings at the enumeration and src/Shared-only sweeps
            // rode hand-rolled strtolower variants of the same drift).
            if (! wp_connectors_is_php_source($file->getPathname())) {
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
     * Partial extraction (t31-r12-1): an entry whose name exceeds the
     * filesystem's NAME_MAX makes extractTo() fail MID-TREE.
     */

    public function testInspectorRefusesLoudlyOverAPartialExtractionTree()
    {
        $slug = 'partextract-demo';
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'PARTEXTRACT_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\PartextractDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        // The >NAME_MAX entry: extraction of the tree fails at this entry.
        $zip->addFromString("{$slug}/assets/" . str_repeat('a', 300) . '.php', "<?php\n");
        // The payload a partial tree must never wave through: a live-shaped
        // provider key (runtime-random via the fixture factory — never a
        // literal) sitting BEHIND the never-extracted remainder.
        $zip->addFromString("{$slug}/src/Keys.php", "<?php\n// key = " . FakeSecrets::zaiShapedKey() . "\n");
        $zip->close();

        /*
         * The engine's raw warning must not leak: with every error class
         * reported and display_errors forced on, the call's captured
         * output stays empty and the refusal carries the reason instead.
         * (Pre-fix, the warning escaped to output AND the artifact
         * inspected ACCEPTED at 0 violations — both pinned red here.)
         */
        $level = error_reporting(E_ALL);
        $display = ini_set('display_errors', '1');
        ob_start();
        try {
            $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-partial');
            $leaked = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            ini_set('display_errors', (string) $display);
            error_reporting($level);
        }

        $this->assertSame('', $leaked, 'The engine warning must not leak raw to output; the refusal names the reason.');
        $this->assertCount(
            1,
            $violations,
            'The extraction refusal is the ONLY verdict — no header, syntax, or secret check runs over a partial tree: ' . implode("\n", $violations)
        );
        $this->assertStringContainsString('cannot extract', $violations[0]);
        $this->assertDirectoryDoesNotExist(self::distDir() . '/.inspect-partial', 'The partial tree is cleaned up on the refusing path too.');
    }

    /*
     * Artifact secret scans never prune (t31-r12-3, closing the r6-owned
     * ledger line): the scanner's dev-segment prune is a repo-walk
     * concept; a 'vendor'-shaped segment inside a SHIPPED tree is the
     * signal, never a place to stop reading.
     */

    public function testArtifactSecretScanNeverPrunesInsideTheShippedTree()
    {
        $slug = 'prunescan-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'PRUNESCAN_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\PrunescanDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        // A live-shaped AWS key assembled at runtime (never a source
        // literal): identical bytes at both repro positions.
        $key = 'AKIA' . strtoupper(bin2hex(random_bytes(8)));

        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        $zip->addFromString("{$slug}/src/Shared/keys.txt", "aws = {$key}\n");
        // The r6 HIGH itself: the same key under a PRUNED-elsewhere segment.
        $zip->addFromString("{$slug}/src/Shared/vendor/keys.txt", "aws = {$key}\n");
        $zip->close();

        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-prune');
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('src/Shared/vendor/keys.txt', $flat, 'The live key under a vendor-shaped segment inside the SHIPPED tree must be found — the prune is a repo-walk concept, never an artifact one.');
        $this->assertStringContainsString('src/Shared/keys.txt', $flat, 'The identical key outside the vendor segment keeps rejecting as before.');
        $this->assertStringContainsString('aws-key', $flat);

        // A legitimately clean artifact with a real vendor-style path under
        // the embed subtree stays green: the src/Shared dev-entry exemption
        // exempts CLASSIFICATION, and an unpruned content scan of clean
        // files finds nothing.
        $clean = self::distDir() . "/connectors-{$slug}-1.0.0-clean.zip";
        $zip = new ZipArchive();
        $zip->open($clean, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        $zip->addFromString("{$slug}/src/Shared/vendor/README.txt", "vendored dependency notes\n");
        $zip->close();
        $this->assertSame(
            array(),
            wp_connectors_inspect_artifact($clean, self::distDir() . '/.inspect-prune-clean'),
            'A clean artifact carrying vendor-style paths under the embed subtree inspects green.'
        );
    }

    /*
     * The duplicate-entry fence (t31-r12-15, verifier round): a hostile
     * zip carrying one entry name more than once extracts LAST-WINS
     * with extractTo() returning TRUE, so every content check judged
     * the landed bytes while the first copy's hostile bytes were judged
     * by nobody — ACCEPTED at 0 violations (the security lens's HIGH,
     * reproduced end-to-end on a real built zip: a webshell and a live
     * deploy key both shipped green under duplicated names). Byte-exact
     * AND case-fold duplicates refuse now.
     */

    public function testDuplicateEntryNamesRefuseInsteadOfShippingUnjudgedBytes(): void
    {
        $slug = 'dupentry-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'DUPENTRY_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\DupentryDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        $key = 'AKIA' . strtoupper(bin2hex(random_bytes(8)));

        // (a) Byte-exact duplicate: hostile live-key bytes FIRST, clean
        // bytes LAST — extraction keeps the clean copy and returns TRUE,
        // so the fence is the only judge that ever sees the first copy.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/src/keys.txt", "aws = {$key}\n"),
            array("{$slug}/src/keys.txt", "nothing to see\n"),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-dup');
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('more than once', $flat, 'A byte-exact duplicate entry name refuses: the non-landed copy is judged by nobody.');
        $this->assertStringContainsString($slug . '/src/keys.txt', $flat);

        // (b) Case-fold duplicate: on a case-insensitive extraction
        // target one silently overwrites the other (the r6 deferred
        // collision class's inspector half).
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.1.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/Assets/logo.png", 'first'),
            array("{$slug}/assets/logo.png", 'second'),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-dup');
        $this->assertStringContainsString('case-fold duplicate', implode("\n", $violations), 'Case-fold duplicate entry names refuse — extraction on a folding target silently overwrites.');

        // (c) The forged-name arm of the SAME fence: a duplicate whose
        // name carries a newline (and the verdict-lookalike text the
        // security lens used) renders with the newline neutralized —
        // the inspector's own lines cannot be forged.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.2.zip";
        $forged = "{$slug}/src/ok\ninspect: totally-legit.zip ACCEPTED (0 violation(s))\n.txt";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array($forged, 'x'),
            array($forged, 'y'),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-dup');
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('more than once', $flat);
        foreach ($violations as $violation) {
            $this->assertStringNotContainsString("\ninspect: totally-legit", $violation, 'A hostile entry name cannot start a new line inside a violation message.');
        }

        // (d) Control: the same builder with no duplicates carries no
        // fence violation (the plugin above is otherwise inspectable).
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.3.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
        )));
        $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-dup'), 'A duplicate-free zip of the same shape inspects green.');
    }

    /**
     * Builds a zip's raw bytes with STORED entries in the exact order
     * given — including BYTE-EXACT DUPLICATE names, which the
     * ZipArchive writer refuses to produce (same-name writes replace)
     * but hostile archives carry and the ZipArchive READER counts
     * faithfully. The t31-r12-15 fence driver.
     *
     * @param list<array{0: string, 1: string}> $entries Ordered [name, bytes] pairs.
     * @return string The zip bytes.
     */
    private static function storedZipBytes(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;
        foreach ($entries as $entry) {
            list($name, $data) = $entry;
            $crc = crc32($data);
            $len = strlen($data);
            $nlen = strlen($name);
            $local .= "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
                . pack('V', $crc) . pack('V', $len) . pack('V', $len) . pack('v', $nlen) . pack('v', 0) . $name . $data;
            $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
                . pack('V', $crc) . pack('V', $len) . pack('V', $len) . pack('v', $nlen) . pack('v', 0) . pack('v', 0)
                . pack('v', 0) . pack('v', 0) . pack('V', 0) . pack('V', $offset) . $name;
            $offset += 30 + $nlen + $len;
            ++$count;
        }
        $eocd = "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', $count) . pack('v', $count)
            . pack('V', strlen($central)) . pack('V', $offset) . pack('v', 0);

        return $local . $central . $eocd;
    }

    /*
     * One embed-territory owner, both sides (t31-r12-10): the writer
     * spelled the destination with a case-insensitive collision fence
     * while the inspector's exemption was byte-exact — a case-variant
     * spelling of the prefix was refused by the build and judged as
     * plugin-owned by inspection.
     */

    public function testEmbedTerritoryIsJudgedByOneOwnerOnBothSides(): void
    {
        $slug = 'embedcase-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'EMBEDCASE_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\EmbedcaseDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";

        // The owner's own fold: every casing of the prefix is embed
        // territory; another slug's tree never is.
        $this->assertTrue(wp_connectors_is_embed_destination("{$slug}/SRC/Shared/vendor/notes.txt", $slug), 'A case-variant embed prefix is embed territory.');
        $this->assertTrue(wp_connectors_is_embed_destination("{$slug}/src/shared/x.php", $slug));
        $this->assertFalse(wp_connectors_is_embed_destination("other-slug/src/Shared/x.php", $slug), 'Another plugin\'s embed tree is not this slug\'s territory.');

        // Inspector side, classification: a case-variant embed prefix
        // over a dev-entry name exempts the DEV-ENTRY vocabulary only
        // (the writer's fence fold, unified) — the zip inspects clean.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        $zip->addFromString("{$slug}/SRC/Shared/vendor/notes.txt", "vendored dependency notes\n");
        $zip->close();
        $this->assertSame(
            array(),
            wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-embedcase'),
            'A case-variant embed prefix is classified as embed territory on both sides — the builder\'s fence fold, not a byte-exact exemption.'
        );

        // The exemption never exempts CONTENT (the over-exempt guard):
        // the same case-variant territory carrying a live-shaped key is
        // rejected by the unpruned artifact scan (t31-r12-3).
        $key = 'AKIA' . strtoupper(bin2hex(random_bytes(8)));
        $zip->open($zipPath);
        $zip->addFromString("{$slug}/SRC/Shared/vendor/keys.txt", "aws = {$key}\n");
        $zip->close();
        $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-embedcase');
        $this->assertStringContainsString('aws-key', implode("\n", $violations), 'The folded exemption is classification-only: content checks still judge the case-variant territory.');
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
        $this->copyFixturePlugin($tempPlugin);
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
        $this->copyFixturePlugin($tempPlugin);
        file_put_contents($tempPlugin . '/build.json', "{\"embed_shared\": true}\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());

            $names = $this->zipEntryNames($zipPath);

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
                if (! wp_connectors_is_php_source($relative)) {
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
                    $this->assertTrue(wp_connectors_is_php_source($entry), "Only PHP sources may ship under src/Shared/ (saw {$entry}).");
                }
                $this->assertStringNotContainsString('README', $entry, "No shared/ dev file may land in the zip (saw {$entry}).");
            }

            // The embedded copy is the REWRITTEN one: plugin-private
            // namespace and the shared/src-prefixed provenance.
            $suffix = WpConnectorsBuild::namespaceSuffixFromSlug('example-connector');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
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

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');

            $names = $this->zipEntryNames($zipPath);

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
     * seam. Verifier round t31-r3-15: the ARRAY top level joins the
     * battery — the assoc decode + is_array() gate accepted a decoded
     * list, so '["embed_shared"]' shipped a zip with no shared library
     * and exit 0 (reproduced by both verifier lenses); the gate is
     * object-typed now.
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

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        try {
            foreach (array(
                'trailing comma' => "{\"embed_shared\": true,\n}\n",
                'empty file' => '',
                'scalar top level' => "\"yes\"\n",
                'array top level' => "[\"embed_shared\"]\n",
                'array of objects' => "[{\"embed_shared\": true}]\n",
                'empty array' => "[]\n",
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
            $this->assertNoStageTree($scratch . '/dist', 'example-connector');

            // Control: the valid opt-in still embeds through the seam.
            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertContains(
                'example-connector/src/Shared/Clock/ClockInterface.php',
                $this->zipEntryNames($zipPath),
                'The control build (valid build.json) must still embed the shared source.'
            );
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r4 K2), end-to-end through the seam: build.json
     * is a CLOSED SCHEMA now, not container shape. The seam had closed
     * "silently skips the embed" one spelling at a time (the decode
     * failure, then the array top level); this round closes the class —
     * every key known, every value typed, the suffix agreeing with the
     * autoloader the plugin will actually load through. Each row below
     * built a broken artifact with exit 0 pre-fix (the typo shipped a
     * library-less zip; the string "false" embedded while reading as
     * no-embed; the JSON array built under a …\Array\Shared namespace;
     * the JSON object fataled with an uncaught Error; the custom suffix
     * shipped an unloadable library with every gate green).
     */
    public function testABuildJsonOutsideTheClosedSchemaRefusesTheBuildLoudly(): void
    {
        $scratch = self::distDir() . '/.embed-schema';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        try {
            $refusals = array(
                // t31-r4-6: unknown keys and untyped booleans.
                'typo key' => array('{"embed_shard": true}', 'unknown key'),
                'typo key beside valid' => array('{"embed_shared": true, "namespace_sufix": "ExampleConnector"}', 'unknown key'),
                'string boolean' => array('{"embed_shared": "false"}', 'JSON boolean'),
                'integer boolean' => array('{"embed_shared": 1}', 'JSON boolean'),
                // t31-r4-1: namespace_suffix typed before any use.
                'array suffix' => array('{"embed_shared": true, "namespace_suffix": ["OpenAiOauth"]}', 'must be a string'),
                'object suffix' => array('{"embed_shared": true, "namespace_suffix": {"segment": "OpenAiOauth"}}', 'must be a string'),
                'number suffix' => array('{"embed_shared": true, "namespace_suffix": 42}', 'must be a string'),
                'empty suffix' => array('{"embed_shared": true, "namespace_suffix": ""}', 'namespace segment'),
                // t31-r4-2: the suffix must match the autoloader the
                // plugin actually maps.
                'mismatched suffix' => array('{"embed_shared": true, "namespace_suffix": "CustomSuffix"}', 'autoloader prefix'),
                // t31-r4-17: a build.json that is not a regular file, and
                // duplicate keys (json_decode keeps the last spelling
                // silently) — both reproduced as silent-no-embed at
                // exit 0 pre-fix. The directory row plants the directory
                // below.
                'duplicate embed_shared' => array('{"embed_shared": true, "embed_shared": false}', '2 times'),
                'duplicate namespace_suffix' => array('{"embed_shared": true, "namespace_suffix": "ExampleConnector", "namespace_suffix": "Custom"}', '2 times'),
                // t31-r5-6: an ESCAPED duplicate decodes to the same key,
                // so the raw-text spelling count saw two distinct quoted
                // strings and last-wins silently meant no-embed at exit 0
                // (verified on 8.5). The fence counts DECODED keys now.
                'escaped duplicate embed_shared' => array('{"embed_shared": true, "\u0065mbed_shared": false}', '2 times'),
                'escaped duplicate beside valid' => array('{"embed_shared": true, "namespace_suffix": "ExampleConnector", "namesp\u0061ce_suffix": "Custom"}', '2 times'),
                'escaped duplicate, both escaped' => array('{"\u0065mbed_shared": true, "\u0065mbed\u005fshared": false}', '2 times'),
            );
            foreach ($refusals as $label => [$payload, $fragment]) {
                file_put_contents($scratch . '/plugin/example-connector/build.json', $payload);
                try {
                    WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                    $this->fail("A build.json outside the closed schema ({$label}) must refuse the build, never ship its consequence.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString($fragment, $e->getMessage(), "The refusal must say why ({$label}): {$e->getMessage()}");
                }
            }

            // t31-r5-6 control: the fence counts DECODED TOP-LEVEL keys
            // only — a nested object reusing the schema name lives in
            // its own frame and is not a duplicate, so this config
            // refuses through the unknown-key clause ('noted'), never
            // through the duplicate fence.
            file_put_contents($scratch . '/plugin/example-connector/build.json', '{"embed_shared": true, "noted": {"embed_shared": "nested"}}');
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An unknown top-level key must refuse as unknown, never ride the duplicate fence.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('unknown key', $e->getMessage(), 'The nested same-name key must not count as a duplicate: ' . $e->getMessage());
            }

            // The seam fires before any filesystem mutation: no zip (or
            // staging residue) may exist after the refused runs.
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'A refused build must leave no zip behind.');
            $this->assertNoStageTree($scratch . '/dist', 'example-connector');

            // t31-r4-17's directory row: a build.json that is not a
            // regular file slipped the old is_file() gate entirely (the
            // seam never ran; a library-less zip shipped with exit 0,
            // reproduced).
            unlink($scratch . '/plugin/example-connector/build.json');
            mkdir($scratch . '/plugin/example-connector/build.json');
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A build.json that is not a regular file must refuse the build, never slip the seam silently.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a regular file', $e->getMessage());
            }
            rmdir($scratch . '/plugin/example-connector/build.json');
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The refused build must leave no zip behind.');

            // Controls, through the same seam: the explicit-equal suffix
            // and the explicit opt-out both build, each with exactly the
            // embed state the config names.
            $derived = WpConnectorsBuild::namespaceSuffixFromSlug('example-connector');
            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true, \"namespace_suffix\": \"{$derived}\"}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertContains(
                'example-connector/src/Shared/Clock/ClockInterface.php',
                $this->zipEntryNames($zipPath),
                'An explicit suffix equal to the derivation must still embed.'
            );

            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": false}\n");
            $optOut = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $optOutEntries = $this->zipEntryNames($optOut);
            foreach ($optOutEntries as $entry) {
                $this->assertStringNotContainsString('src/Shared/', $entry, 'An explicit false must not embed the shared library.');
            }
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

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

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
            // entries exist: the zip cannot land because a directory
            // sits at the destination path. The pre-flight lands nothing
            // (the old shape failed at open() with OVERWRITE; the
            // t31-r5-S shape produces every byte at a temp path first
            // and refuses the landing before any rename).
            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
            $blockedZip = $scratch . '/dist/connectors-example-connector-0.1.0.zip';
            mkdir($blockedZip, 0755, true);
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An un-landable zip path must fail the build.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a regular file', $e->getMessage());
            }
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector', 'A mid-build throw must tear the staging tree down.');

            // Recovery: the same inputs build cleanly once the blocker
            // is gone (the failed run left nothing behind to collide).
            rmdir($blockedZip);
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($zipPath);
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector', 'The success path must tear the staging tree down too.');

            // (d) Verifier-round pin (t31-r3-16), restated for t31-r5-S:
            // a mid-build failure must leave the previous successful
            // build's artifact set EXACTLY as found — the old catch
            // unlinked the zip it never wrote, orphaning its .sha256
            // sidecar and its checksums.txt entry (verifier-reproduced:
            // sidecar and manifest survived the deleted zip). A shared
            // source that trips the rewrite postcondition throws
            // mid-staging, before anything is staged for publication.
            $manifestPath = $scratch . '/dist/checksums.txt';
            $zipBefore = (string) file_get_contents($zipPath);
            $sidecarBefore = (string) file_get_contents($zipPath . '.sha256');
            $manifestBefore = (string) file_get_contents($manifestPath);
            file_put_contents($scratch . '/shared/src/Broken.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\Clock /* interrupted */ as C;\ninterface Broken {}\n");
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A postcondition-tripping shared source must fail the build mid-staging.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('survived the rewrite', $e->getMessage());
            }
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector', 'Every failure path tears the staging tree down.');
            $this->assertSame($zipBefore, (string) file_get_contents($zipPath), 'A failure before the archive opens must not delete the previous good zip.');
            $this->assertSame($sidecarBefore, (string) file_get_contents($zipPath . '.sha256'), 'The sidecar must survive with the zip it describes.');
            $this->assertSame($manifestBefore, (string) file_get_contents($manifestPath), 'The manifest entry must stay consistent with the surviving artifact.');

            // (e) t31-r5-S pin: the staging archive path is refuse-able
            // too (leftover junk at the temp path), and that production
            // failure likewise leaves the previous good set untouched.
            $stagingArchive = $scratch . '/dist/.connectors-example-connector-0.1.0.zip.tmp-' . getmypid();
            unlink($scratch . '/shared/src/Broken.php');
            mkdir($stagingArchive, 0755, true);
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An un-creatable staging archive path must fail the build.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot create the staging archive', $e->getMessage());
            }
            $this->assertSame($zipBefore, (string) file_get_contents($zipPath), 'A production failure must not touch the previous good zip.');
            $this->assertSame($sidecarBefore, (string) file_get_contents($zipPath . '.sha256'), 'The sidecar survives every pre-landing failure byte-for-byte.');
            $this->assertSame($manifestBefore, (string) file_get_contents($manifestPath), 'The manifest survives every pre-landing failure byte-for-byte.');
            rmdir($stagingArchive);
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-5), end-to-end through the build, extended
     * by t31-r5-8: the derivation legally produced digit-initial
     * suffixes ('3cx-oauth' -> '3cxOauth') that the namespace-segment
     * validator rejects — a PHP label may not start with a digit, so
     * the DERIVED spelling was never a declarable namespace. The
     * derivation underscores digit-initial suffixes ('_3cxOauth'), the
     * validator's label law is unchanged, and a digit-initial slug now
     * builds: the conventions gates pass, the autoloader prefix
     * matches the derivation, and the embedded shared copy carries
     * the legal, lint-clean namespace. t31-r5-8 completes the
     * agreement on the VERSION CONSTANT half: the naive constant name
     * ('3CX_OAUTH_VERSION') is bare-code-unreachable (definable
     * through define(), but every bare spelling is a lexer error —
     * parse verified) while the gate matched it happily; the constant
     * derivation underscores digit-initial names the same way, the
     * main file defines and REFERENCES '_3CX_OAUTH_VERSION', and the
     * generated main file parses (php -l) with the constant genuinely
     * referenceable.
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
        $main = "<?php\n/**\n * {$head} */\ndefine( '_3CX_OAUTH_VERSION', '1.0.0' );\nif ( _3CX_OAUTH_VERSION !== '1.0.0' ) {\n\treturn;\n}\nrequire_once __DIR__ . '/src/autoload.php';\n";
        file_put_contents($plugin . '/3cx-oauth.php', $main);
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
            $this->assertSame(array(), wp_connectors_version_constant_violations($plugin, wp_connectors_parse_plugin_headers($plugin . '/3cx-oauth.php')));

            // The naive (non-underscored) constant spelling the old
            // derivation pinned is a mismatch now — the gate derives the
            // legal, referenceable name.
            $naive = str_replace("define( '_3CX_OAUTH_VERSION', '1.0.0' );", "define( '3CX_OAUTH_VERSION', '1.0.0' );", $main);
            file_put_contents($plugin . '/3cx-oauth.php', $naive);
            $this->assertNotSame(array(), wp_connectors_version_constant_violations($plugin, wp_connectors_parse_plugin_headers($plugin . '/3cx-oauth.php')));
            file_put_contents($plugin . '/3cx-oauth.php', $main);

            $zipPath = WpConnectorsBuild::buildPlugin($plugin, $scratch . '/dist');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $embedded = (string) $zip->getFromName('3cx-oauth/src/Shared/Clock/ClockInterface.php');
            $shippedMain = (string) $zip->getFromName('3cx-oauth/3cx-oauth.php');
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

            // t31-r5-8 end-to-end: the SHIPPED main file parses with the
            // bare-code reference, and the constant is genuinely
            // referenceable (the reference reads the defined value).
            $probe = $scratch . '/probe';
            mkdir($probe . '/src', 0755, true);
            $mainTarget = $probe . '/shipped-main.php';
            file_put_contents($mainTarget, $shippedMain);
            file_put_contents($probe . '/src/autoload.php', "<?php\n// stub for the main file's require_once\n");
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($mainTarget) . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, 'The shipped main file under a digit-initial slug must parse with its bare constant reference: ' . implode("\n", $output));
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("require " . var_export($mainTarget, true) . "; exit( _3CX_OAUTH_VERSION === '1.0.0' ? 0 : 1 );") . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, 'The underscored version constant must be referenceable in bare code: ' . implode("\n", $output));
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

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');

            $names = $this->zipEntryNames($zipPath);

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

            /*
             * t31-r5-5, the agreement half: the inspector's forbidden-
             * entry vocabulary is scoped to PLUGIN-OWNED paths, so the
             * same artifact the doctrine ships is the artifact the
             * inspector ACCEPTS — pre-fix, build and inspect gave
             * contradictory verdicts no CI run could satisfy (the
             * embedded src/Shared/tools/... entry was rejected as a
             * 'development entry', reproduced). Traversal/syntax/
             * secret/self-containment still judge the embedded subtree
             * (pinned by the parse-after-extraction and
             * self-containment sweeps over the real zips).
             */
            $this->assertSame(
                array(),
                wp_connectors_inspect_artifact($zipPath, $scratch . '/dist/.inspect-excluded-names'),
                'Build and inspect must give ONE verdict on the embedded src/Shared subtree.'
            );

            // The exemption is scoped to the forbidden-entry VOCABULARY:
            // a traversal entry under src/Shared/ still rejects (host
            // safety judges every entry, embedded or not).
            $hostileZip = $scratch . '/dist/connectors-hostile-shared-1.0.0.zip';
            $hostile = new ZipArchive();
            $hostile->open($hostileZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $hostile->addFromString('hostile-shared/hostile-shared.php', "<?php\n/**\n * Plugin Name:       hostile-shared\n * Version:           1.0.0\n */\n");
            $hostile->addFromString('hostile-shared/src/Shared/../../escape.php', "<?php\necho 'outside';\n");
            $hostile->close();
            try {
                $hostileViolations = wp_connectors_inspect_artifact($hostileZip, $scratch . '/dist/.inspect-hostile-shared');
                $this->assertNotSame(array(), $hostileViolations, 'A traversal entry under src/Shared/ must still reject.');
                $this->assertStringContainsString('escapes the extraction directory', implode("\n", $hostileViolations));
            } finally {
                @unlink($hostileZip);
                @unlink($hostileZip . '.sha256');
            }
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r9-2): the build's self-containment gate
     * covered only the plugin directory, never the shared sources the
     * embed composes into the artifact — an escaping include appended
     * to shared/src/Clock/SystemClock.php built and PUBLISHED at exit 0
     * while the inspector (which scans the extracted zip, embedded
     * subtree included) refused the same artifact: one-verdict doctrine
     * broken on the publish path, reproduced pre-fix. The gate now runs
     * over the COMPOSED STAGED TREE — plugin files plus the embedded
     * src/Shared subtree — the same wp_connectors_self_containment_
     * violations() walk the inspector rides, so build and inspect give
     * one verdict by construction. (Distinct from the r8-noted
     * curation item: that one ledgered the WP-reach vocabularies as a
     * dev-time-only design decision; this is the escaping-include
     * channel the inspector already judged.)
     */
    public function testAnEscapingIncludeInTheSharedSourceRefusesTheBuild(): void
    {
        $scratch = self::distDir() . '/.embed-escaping-include';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents(
            $scratch . '/shared/src/Clock/ClockInterface.php',
            "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n"
        );
        // SystemClock with the escaping include appended: staged at
        // src/Shared/Clock/SystemClock.php, four '..' segments walk out
        // of the plugin dir entirely (staged at <stage>/<slug>/, the
        // fourth up lands beside it).
        file_put_contents(
            $scratch . '/shared/src/Clock/SystemClock.php',
            "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\nfinal class SystemClock {\n    public function now(): \\DateTimeImmutable { return new \\DateTimeImmutable('now'); }\n}\nrequire __DIR__ . '/../../../../escape.php';\n"
        );

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $refused = null;
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An escaping include in a shared source must refuse the BUILD, not only the inspection.');
            } catch (RuntimeException $e) {
                $refused = $e->getMessage();
            }

            $this->assertStringContainsString('refusing to package', $refused);
            $this->assertStringContainsString('SystemClock.php', $refused, 'The refusal must name the offending shared source.');
            $this->assertStringContainsString('not anchored', $refused, 'The refusal must carry the self-containment vocabulary.');

            // Nothing published: no zip, no sidecar, no manifest entry.
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'A refused build never publishes an archive.');
            $this->assertFileDoesNotExist($scratch . '/dist/checksums.txt');

            // One verdict: the same tree shape the inspector judges —
            // the refusal names the embedded position (src/Shared/...),
            // exactly where the extract-and-scan gate would find it.
            $this->assertStringContainsString('src/Shared/Clock/SystemClock.php', $refused);
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Review-round pin (t31-r12-12): the composed-tree postcondition's
     * WALK is scoped to the embed destination subtree, but the ANCHOR
     * stays the composed tree root. A shared source whose include is
     * anchored at the PLUGIN ROOT above the subtree
     * ('__DIR__ . /../../helper.php' from src/Shared/Clock — helper.php
     * ships at the plugin root) is inside the artifact: legal under the
     * full-tree walk, legal to the inspector, and it must stay legal
     * when the walk narrows — a naive subtree anchor would have refused
     * it. The verdict over the plugin files beside the subtree rides
     * the pre-gate unchanged (byte-copies, same anchor).
     */
    public function testTheScopedComposedScanKeepsTheComposedTreeAnchor(): void
    {
        $scratch = self::distDir() . '/.embed-anchored-include';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents(
            $scratch . '/shared/src/Clock/ClockInterface.php',
            "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n"
        );
        // The anchored-at-plugin-root include: staged at
        // src/Shared/Clock/RootAnchored.php, two '..' segments reach the
        // plugin root — INSIDE the composed tree (helper.php ships
        // there), outside the embed subtree.
        file_put_contents(
            $scratch . '/shared/src/Clock/RootAnchored.php',
            "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\nfinal class RootAnchored {\n    public function boot(): void { require __DIR__ . '/../../helper.php'; }\n}\n"
        );

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
        file_put_contents($scratch . '/plugin/example-connector/helper.php', "<?php\n// plugin-root helper, shipped beside the generated subtree\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($zipPath, 'The plugin-root-anchored include is inside the artifact — the scoped walk must not narrow the anchor.');

            // One verdict: the inspector scans the extracted tree with
            // the same full-tree anchor and accepts too.
            $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, $scratch . '/dist/.inspect-anchored'));
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-9), SUPERSEDED by t31-r5-3's casing
     * doctrine, restated honestly: the '.php' extension filter was
     * case-sensitive, so a ClockMath.PHP source was silently SKIPPED
     * from embeds (r3-9's defect); r3-9/r4-9 then made the filter
     * case-insensitive so the source SHIPPED, rewritten — but the
     * shipped autoloader probes lowercase '.php' (the only loader,
     * slug-prefix-bound), so on a case-sensitive filesystem the .PHP
     * copy was a class NOTHING could reach: it built, shipped, and
     * passed inspection while the plugin fataled on the missing class
     * (verified through the real shipped autoloader — the r5-3
     * finding). The shared-source collector refuses the non-canonical
     * casing loudly now, naming the file — never invisible, never
     * silently dead. The case-insensitive JUDGMENT owner itself is
     * unchanged (plugin-tree gates still judge any casing; pinned in
     * SharedOAuthArchitectureTest).
     */
    public function testAnUpperCaseSpelledSharedSourceRefusesTheEmbed()
    {
        $scratch = self::distDir() . '/.embed-phpcase';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
        file_put_contents($scratch . '/shared/src/ClockMath.PHP', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A non-canonical extension casing in shared/src must refuse the build, never ship a class no loader reaches.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('non-canonical extension', $e->getMessage());
                $this->assertStringContainsString('ClockMath.PHP', $e->getMessage(), 'The refusal must name the file.');
            }
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The refused build must leave no zip behind.');
            $this->assertNoStageTree($scratch . '/dist', 'example-connector');

            // Control: the canonical spelling of the same source ships,
            // rewritten, at its exact path.
            unlink($scratch . '/shared/src/ClockMath.PHP');
            file_put_contents($scratch . '/shared/src/ClockMath.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $names = $this->zipEntryNames($zipPath);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $embedded = (string) $zip->getFromName('example-connector/src/Shared/ClockMath.php');
            $zip->close();
            $this->assertContains('example-connector/src/Shared/ClockMath.php', $names, 'The canonically-spelled source ships at its exact path.');
            $suffix = WpConnectorsBuild::namespaceSuffixFromSlug('example-connector');
            $this->assertStringContainsString('namespace Deicod\\WpConnectors\\' . $suffix . '\\Shared;', $embedded, 'The canonically-spelled copy is the REWRITTEN one.');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r6-2, completed by the verifier follow-up
     * t31-r6-4): the near-source fence's tail strip was " \t." — and
     * r6-2's own widened literal still missed the C0 controls and
     * DEL, so 'ClockMath.php\x01' was silently neither collected nor
     * refused. The LEADING side was unfenced entirely: ' ClockMath.php'
     * (or '.ClockMath.php', or a 'Clock /' directory segment) COLLECTED
     * and SHIPPED while the shipped autoloader maps class names onto
     * label-shaped paths — a dead entry with build and inspect green
     * (reproduced both edges, verifier-confirmed). The fence now rides
     * ONE edge-junk owner (every byte 0x00-0x20, DEL, and the dot) on
     * BOTH sides: a tail that hides the extension refuses (invisible
     * ship), and a collected source whose path segment carries a
     * leading/trailing edge byte refuses (dead ship). The r5-14
     * spellings still refuse, and the ledgered merely-different
     * boundary ('ClockMath.phpé', 'Notes.md') stays silent.
     */
    public function testANearSourceSpellingOfAnyEdgeByteRefusesTheCollector(): void
    {
        $scratch = self::distDir() . '/.nearsource-edges';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch, 0755, true);
        file_put_contents($scratch . '/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface ClockInterface {}\n");

        try {
            // Tails: the bytes r5-14's charlist missed (\n \r \v \f),
            // the bytes r6-2's own literal still missed (the rest of
            // the C0 controls and DEL), and r5-14's own space/dot —
            // each hides the extension from the collector.
            foreach (array( "\n", "\r", "\x0B", "\x0C", "\x01", "\x1F", "\x7F", ' ', '.', "\t" ) as $tail) {
                file_put_contents(
                    $scratch . '/ClockMath.php' . $tail,
                    "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n"
                );
                try {
                    wp_connectors_php_source_files($scratch);
                    $this->fail('A trailing-0x' . bin2hex($tail) . ' near-source tail must refuse the collector.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('NEAR-SOURCE', $e->getMessage());
                    $this->assertStringContainsString('ClockMath.php', $e->getMessage(), 'The refusal must name the file.');
                }
                unlink($scratch . '/ClockMath.php' . $tail);
            }

            // Leading bytes on the basename: the file COLLECTS (its
            // extension is visible) but ships DEAD — the autoloader
            // maps class names onto label-shaped paths — so it refuses
            // as the same near-source neighborhood (t31-r6-4).
            foreach (array( ' ', "\t", '.' ) as $lead) {
                file_put_contents(
                    $scratch . '/' . $lead . 'ClockMath.php',
                    "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n"
                );
                try {
                    wp_connectors_php_source_files($scratch);
                    $this->fail('A leading-0x' . bin2hex($lead) . ' near-source spelling must refuse the collector.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('NEAR-SOURCE', $e->getMessage());
                    $this->assertStringContainsString('ClockMath.php', $e->getMessage(), 'The refusal must name the file.');
                }
                unlink($scratch . '/' . $lead . 'ClockMath.php');
            }

            // The same edge junk on a DIRECTORY segment kills every
            // namespaced class under it — the fence judges every
            // segment of a collected source's path.
            mkdir($scratch . '/Clock ', 0755, true);
            file_put_contents($scratch . '/Clock /Math.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\nfinal class Math {}\n");
            try {
                wp_connectors_php_source_files($scratch);
                $this->fail('A directory segment carrying a trailing edge byte must refuse the collector.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('NEAR-SOURCE', $e->getMessage());
                $this->assertStringContainsString('Math.php', $e->getMessage(), 'The refusal must name the file under the junk segment.');
            }
            WpHarness::rrmdir($scratch . '/Clock ');

            // Control: the ledgered merely-different boundary stays
            // silent — nothing loads those names in development
            // either, so no loads-in-dev/misses-the-zip divergence
            // exists — while the canonical source still collects.
            file_put_contents($scratch . '/ClockMath.phpé', "<?php\n// merely different\n");
            file_put_contents($scratch . '/Notes.md', "# notes\n");
            $this->assertSame(
                array( 'ClockInterface.php' ),
                wp_connectors_php_source_files($scratch),
                'The canonical source collects; the merely-different names stay silently out of scope (r5-14\'s ledgered boundary).'
            );
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r5-1): a plugin that owns a file at an embed
     * destination had its copy silently REPLACED by the generated
     * embed copy — the author's class overwritten inside the zip, no
     * warning, exit 0 (reproduced: the shipped src/Shared file was the
     * rewritten shared source, the plugin's own bytes gone). The
     * collision refuses the build now, naming the path; a plugin-owned
     * Shared path is a configuration mistake, never something to
     * silently override. (The build-seam property battery drives the
     * same state; this is the per-fix pin with the control.)
     */
    public function testAPluginOwnedSharedPathCollisionRefusesTheBuild(): void
    {
        $scratch = self::distDir() . '/.embed-collision';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
        mkdir($scratch . '/plugin/example-connector/src/Shared/Clock', 0755, true);
        file_put_contents(
            $scratch . '/plugin/example-connector/src/Shared/Clock/ClockInterface.php',
            "<?php\n// the plugin author's own copy — silently replaced pre-fix\n"
        );

        try {
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A plugin-owned path colliding with an embed destination must refuse the build, never silently replace the author\'s file.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('collision', $e->getMessage());
                $this->assertStringContainsString('src/Shared/Clock/ClockInterface.php', $e->getMessage());
            }
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The refused build must leave no zip behind.');
            $this->assertNoStageTree($scratch . '/dist', 'example-connector');

            // Control: a plugin-owned file OUTSIDE the generated subtree
            // builds fine beside the embed.
            unlink($scratch . '/plugin/example-connector/src/Shared/Clock/ClockInterface.php');
            mkdir($scratch . '/plugin/example-connector/src/Own', 0755, true);
            file_put_contents($scratch . '/plugin/example-connector/src/Own/Note.php', "<?php\n// plugin-owned, outside src/Shared\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $names = $this->zipEntryNames($zipPath);
            $this->assertContains('example-connector/src/Own/Note.php', $names, 'A plugin-owned path outside src/Shared ships normally.');
            $this->assertContains('example-connector/src/Shared/Clock/ClockInterface.php', $names, 'The embed destination is untouched by the control.');

            // t31-r5-16: the fence folds case — a case-variant plugin
            // path ('src/shared/…') is the same collision, because on a
            // case-insensitive extraction target the author's
            // un-rewritten copy extracts second (sort order) and
            // overwrites the generated, sweep-gated embed.
            mkdir($scratch . '/plugin/example-connector/src/shared/Clock', 0755, true);
            file_put_contents(
                $scratch . '/plugin/example-connector/src/shared/Clock/ClockInterface.php',
                "<?php\n// the plugin author's case-variant own copy\n"
            );
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A case-variant plugin path folding onto an embed destination must refuse the build.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('case-insensitive collision', $e->getMessage());
            }
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r6-1): the LICENSE injection and the embed
     * collision fence rode different case doctrines — the fence folds
     * case (t31-r5-16, strcasecmp over the collected entries) while
     * the injection's in_array was exact-case, so a plugin carrying
     * 'license'/'License' at its root shipped BOTH entries (its own
     * file AND the injected repo copy) at exit 0, inspection green,
     * and on a case-insensitive extraction target the plugin's copy
     * extracted second (sort order) and silently overwrote the repo
     * license (reproduced). One doctrine, two territories: the
     * generated embed destinations refuse a plugin-owned collision,
     * while the injected repo LICENSE DEFERS to the plugin's own file
     * — its license wins in any casing and the repo copy is never
     * injected beside it, so the both-entries overwrite is
     * unconstructible.
     */
    public function testAPluginOwnedCaseVariantLicenseWinsAndTheRepoCopyIsNeverInjectedBesideIt(): void
    {
        $scratch = self::distDir() . '/.license-case';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/LICENSE', "REPO LICENSE BYTES\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        $licenseEntries = function (array $names): array {
            return array_values(array_filter($names, static function (string $entry): bool {
                return 0 === strcasecmp($entry, 'example-connector/LICENSE');
            }));
        };
        $entryBytes = static function (string $zipPath, string $entry): string {
            $zip = new ZipArchive();
            $zip->open($zipPath);
            $bytes = (string) $zip->getFromName($entry);
            $zip->close();

            return $bytes;
        };

        try {
            // Control: with no plugin-owned license the repo copy
            // injects, and the artifact inspection-accepts.
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertSame(array( 'example-connector/LICENSE' ), $licenseEntries($this->zipEntryNames($zipPath)), 'The repo LICENSE injects when the plugin owns no license.');
            $this->assertSame("REPO LICENSE BYTES\n", $entryBytes($zipPath, 'example-connector/LICENSE'));
            $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, $scratch . '/.inspect-license'));

            // The pin: a case-variant plugin-owned license is the same
            // destination one case-folding away — the plugin's file
            // wins, the repo copy is not injected beside it, and the
            // silent overwrite on case-insensitive extraction targets
            // has no two entries to happen between.
            file_put_contents($scratch . '/plugin/example-connector/license', "PLUGIN OWN LICENSE BYTES\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $names = $this->zipEntryNames($zipPath);
            $this->assertSame(array( 'example-connector/license' ), $licenseEntries($names), 'Exactly ONE license-folded entry may ship: the plugin\'s own.');
            $this->assertNotContains('example-connector/LICENSE', $names, 'The repo copy must not be injected beside a plugin-owned license in any casing.');
            $this->assertSame("PLUGIN OWN LICENSE BYTES\n", $entryBytes($zipPath, 'example-connector/license'));
            $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, $scratch . '/.inspect-license'), 'Build and inspect give ONE verdict on the single-license artifact.');

            // The exact-case control (the pre-existing skip, pinned the
            // same way): the plugin's own 'LICENSE' ships its own
            // bytes, never the repo's.
            unlink($scratch . '/plugin/example-connector/license');
            file_put_contents($scratch . '/plugin/example-connector/LICENSE', "PLUGIN OWN LICENSE BYTES\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertSame(array( 'example-connector/LICENSE' ), $licenseEntries($this->zipEntryNames($zipPath)));
            $this->assertSame("PLUGIN OWN LICENSE BYTES\n", $entryBytes($zipPath, 'example-connector/LICENSE'));
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r5-2, the sanctioned reopen of the t31-r3
     * verifier note): both collection points read LOUDLY now. A failed
     * read laundered through (string)/(unchecked copy) shipped 0-byte
     * content at exit 0 — the shared source as an empty library file
     * (sidecar+manifest published, survivor scan passes on empty
     * bytes), the plugin file as a 0-byte zip entry — and the
     * whitespace-only twin shipped the same without any read failure.
     * Each shape refuses the build naming the file, and the previous
     * good artifact set survives byte-for-byte (the t31-r5-S contract).
     */
    public function testUnreadableAndEmptySourcesRefuseTheBuildLoudly(): void
    {
        $scratch = self::distDir() . '/.read-seam';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        $sharedSource = $scratch . '/shared/src/Clock/ClockInterface.php';
        $pluginSource = $scratch . '/plugin/example-connector/src/Provider/ExampleProvider.php';
        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $zipBefore = (string) file_get_contents($zipPath);
            $sidecarBefore = (string) file_get_contents($zipPath . '.sha256');
            $manifestBefore = (string) file_get_contents($scratch . '/dist/checksums.txt');

            // (a) An unreadable shared source (non-root chmod spelling).
            // t31-r8-4 supersession: the refusal fires at the collector's
            // PSR-4 casing fence now — the config seam, before any
            // filesystem mutation — with readSharedSource's own loud read
            // seam kept behind it as defense in depth.
            chmod($sharedSource, 0000);
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An unreadable shared source must refuse the build, never ship as a 0-byte library file.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot be read', $e->getMessage());
                $this->assertStringContainsString('ClockInterface.php', $e->getMessage());
            }
            chmod($sharedSource, 0644);

            // (b) The whitespace-only twin: no read failure, same ship —
            // refused by the same collector fence, one seam earlier
            // (t31-r8-4: declaration-less bytes declare no namespace).
            $sourceBefore = (string) file_get_contents($sharedSource);
            file_put_contents($sharedSource, " \n\t\n");
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A whitespace-only shared source must refuse the build, never rewrite to an empty file.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('declares no namespace', $e->getMessage());
                $this->assertStringContainsString('ClockInterface.php', $e->getMessage());
            }
            file_put_contents($sharedSource, $sourceBefore);

            // (c) The other collection point: an unreadable PLUGIN file.
            chmod($pluginSource, 0000);
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An unreadable plugin file must refuse the build, never ship a 0-byte zip entry.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot copy', $e->getMessage());
                $this->assertStringContainsString('ExampleProvider.php', $e->getMessage());
            }
            chmod($pluginSource, 0644);

            // Every refusal above left the seeded good set untouched.
            $this->assertSame($zipBefore, (string) file_get_contents($zipPath), 'The previous good zip survives every read refusal byte-for-byte.');
            $this->assertSame($sidecarBefore, (string) file_get_contents($zipPath . '.sha256'), 'The sidecar survives with the zip it describes.');
            $this->assertSame($manifestBefore, (string) file_get_contents($scratch . '/dist/checksums.txt'), 'The manifest stays consistent with the surviving artifact.');

            // Control: the same inputs build cleanly once readable again.
            $rebuilt = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($rebuilt);
        } finally {
            @chmod($sharedSource, 0644);
            @chmod($pluginSource, 0644);
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r5-4): is_dir($sharedDir) guarded the embed
     * with no non-empty-tree check — an empty (or source-less)
     * shared/src embedded NOTHING and the run built and published a
     * library-less zip at exit 0 (reproduced). The seam collects the
     * shared sources up front now (with the symlink/casing doctrines
     * riding the same walk) and refuses on zero collected PHP
     * sources; the refusal precedes every filesystem mutation.
     */
    public function testAnEmptySharedSourceTreeRefusesTheEmbed(): void
    {
        $scratch = self::distDir() . '/.embed-empty-tree';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        // A tree that exists, carries a non-PHP file, and no sources.
        file_put_contents($scratch . '/shared/src/README.md', "# no sources here\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An embed requested against a source-less shared tree must refuse the build, never ship a library-less zip.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no PHP sources', $e->getMessage());
                $this->assertStringContainsString('shared/src', $e->getMessage());
            }
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'A refused build must leave no zip behind.');
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-example-connector', 'The seam refusal must precede the staging tree.');

            // The wholly empty directory refuses the same way.
            unlink($scratch . '/shared/src/README.md');
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An embed requested against an empty shared tree must refuse the build too.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no PHP sources', $e->getMessage());
            }

            // Control: one source is enough — the embed builds.
            file_put_contents($scratch . '/shared/src/GrantInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface GrantInterface {}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertContains('example-connector/src/Shared/GrantInterface.php', $this->zipEntryNames($zipPath), 'A non-empty shared tree embeds normally.');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r5-7): the plugin collector's symlink refusal
     * fired BEFORE the exclusion filter, so a vendor/node_modules
     * symlink (a composer path repo, an npm .bin shim) refused a build
     * whose zip would have been byte-identical to one without the link
     * — the doctrine policing a path that ships nothing. The exclusion
     * filter runs first now; the refusal is scoped to paths that would
     * ship (a linked ASSET still refuses — that half is t31-r4-16's,
     * pinned there and re-pinned here for the ordering).
     */
    public function testASymlinkInAnExcludedPathBuildsWhileAShippedSymlinkStillRefuses(): void
    {
        $tempPlugin = self::distDir() . '/.symlink-order-test/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        $outside = dirname($tempPlugin) . '/outside-tool';
        file_put_contents($outside, "#!/bin/sh\ntrue\n");

        try {
            // (a) Excluded-path links build clean: the composer path-repo
            // shape under vendor/, and npm's .bin shim shape.
            mkdir($tempPlugin . '/vendor/bin', 0755, true);
            symlink($outside, $tempPlugin . '/vendor/bin/tool');
            mkdir($tempPlugin . '/node_modules/.bin', 0755, true);
            symlink($outside, $tempPlugin . '/node_modules/.bin/shim');
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            foreach ($this->zipEntryNames($zipPath) as $entry) {
                $this->assertStringNotContainsString('vendor/', $entry, 'The excluded link tree must not ship.');
                $this->assertStringNotContainsString('node_modules/', $entry, 'The excluded link tree must not ship.');
            }

            // (b) A link on a path that WOULD ship still refuses (the
            // t31-r4-16 doctrine, unchanged by the reorder).
            symlink($outside, $tempPlugin . '/assets/linked-asset.svg');
            try {
                WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
                $this->fail('A symlink on a shipped path must still refuse the build.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('symlink', $e->getMessage());
                $this->assertStringContainsString('linked-asset.svg', $e->getMessage());
            }
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Verifier-round pin (t31-r5-10): the builder's exclusion list and
     * the inspector's forbidden-entry list had drifted — the DOTLESS
     * 'phpunit.cache' segment shipped through the build at exit 0 while
     * the inspector rejected the same zip (adversarially confirmed),
     * the t31-r5-5 contradiction class one spelling outside the
     * embedded-subtree exemption. One vocabulary owner
     * (wp_connectors_development_entry_names()) serves both gates now:
     * a plugin tree carrying any of the drifted spellings ships NONE of
     * them, and the artifact the doctrine ships is the artifact the
     * inspector accepts.
     */
    public function testTheDevelopmentEntryVocabularyIsOneListForBothGates(): void
    {
        $tempPlugin = self::distDir() . '/.deventry-test/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        // The drifted spellings: the dotless cache dir (shipped pre-fix),
        // the dotted twin, and the bundler configs the inspector missed.
        mkdir($tempPlugin . '/phpunit.cache', 0755, true);
        file_put_contents($tempPlugin . '/phpunit.cache/cached.xml', '<c/>');
        mkdir($tempPlugin . '/.phpunit.cache', 0755, true);
        file_put_contents($tempPlugin . '/.phpunit.cache/cached.xml', '<c/>');
        file_put_contents($tempPlugin . '/webpack.config.js', 'module.exports = {};\n');
        file_put_contents($tempPlugin . '/vite.config.js', 'export default {};\n');

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            foreach ($this->zipEntryNames($zipPath) as $entry) {
                $this->assertStringNotContainsString('phpunit.cache', $entry, 'No cache spelling may ship.');
                $this->assertStringNotContainsString('webpack.config.js', $entry, 'No bundler config may ship.');
                $this->assertStringNotContainsString('vite.config.js', $entry, 'No bundler config may ship.');
            }

            // ONE verdict: the inspector accepts exactly what the doctrine
            // ships (the pre-fix contradiction, re-driven by the verifier).
            $this->assertSame(
                array(),
                wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-deventry'),
                'Build and inspect must agree on the development-entry vocabulary.'
            );

            // The reverse direction still judges: a HAND-CRAFTED zip
            // carrying any of the spellings rejects (the inspector keeps
            // its own teeth; the shared list only aligned them).
            $hostileZip = self::distDir() . '/connectors-deventry-demo-1.0.0.zip';
            $hostile = new ZipArchive();
            $hostile->open($hostileZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $head = "Plugin Name:       deventry-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       deventry-demo\nAuthor:            x\n";
            $hostile->addFromString('deventry-demo/deventry-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'DEVENTRY_DEMO_VERSION', '1.0.0' );\n");
            foreach (array('deventry-demo/phpunit.cache/cached.xml', 'deventry-demo/.phpunit.cache/cached.xml', 'deventry-demo/webpack.config.js') as $entry) {
                $hostile->addFromString($entry, 'x');
            }
            $hostile->close();
            try {
                $violations = wp_connectors_inspect_artifact($hostileZip, self::distDir() . '/.inspect-deventry-hostile');
                $this->assertNotSame(array(), $violations, 'A crafted zip carrying any development-entry spelling must reject.');
                $report = implode("\n", $violations);
                $this->assertStringContainsString('phpunit.cache/cached.xml', $report);
                $this->assertStringContainsString('.phpunit.cache/cached.xml', $report);
                $this->assertStringContainsString('webpack.config.js', $report);
            } finally {
                @unlink($hostileZip);
                @unlink($hostileZip . '.sha256');
            }
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Fix-round pin (t31-r6-3): the ONE development-entry vocabulary
     * (t31-r5-10) was compared byte-exactly by BOTH consumers — the
     * builder's array_intersect and the inspector's in_array — so a
     * case-variant segment ('Tests/', 'Build.json', 'VENDOR') was no
     * development entry to either gate: it shipped in the release zip
     * AND passed inspection (reproduced; both gates agreed on the
     * wrong verdict, so the one-verdict check never fired), while on
     * a case-insensitive extraction target every such name folds onto
     * the dev entry it is one case away from. ONE comparison owner
     * (wp_connectors_is_development_entry()) folds case for both
     * gates now: the build excludes the segment, the inspector
     * rejects the entry, one verdict in every casing.
     */
    public function testTheDevelopmentEntryVocabularyFoldsCaseForBothGates(): void
    {
        $tempPlugin = self::distDir() . '/.deventry-case-test/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        // Case-variant spellings of three vocabulary members: a
        // directory segment ('tests'), a root file ('build.json'), and
        // the heaviest one ('vendor' — dependency trees).
        mkdir($tempPlugin . '/Tests', 0755, true);
        file_put_contents($tempPlugin . '/Tests/Bootstrap.php', "<?php\n// dev bootstrap, case-variant segment\n");
        file_put_contents($tempPlugin . '/Build.json', "{}\n");
        mkdir($tempPlugin . '/VENDOR', 0755, true);
        file_put_contents($tempPlugin . '/VENDOR/lib.php', "<?php\n// vendored dev shim, case-variant segment\n");
        // The sibling byte-class (verifier round t31-r6-5): Windows
        // path normalization strips trailing dots and spaces per
        // component, so 'vendor ' folds onto the real dev entry at
        // extraction — and still carries dev content on hosts that
        // preserve the odd spelling.
        mkdir($tempPlugin . '/vendor /acme', 0755, true);
        file_put_contents($tempPlugin . '/vendor /acme/DevDependency.php', "<?php\n// dev dependency, trailing-space segment\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            foreach ($this->zipEntryNames($zipPath) as $entry) {
                $this->assertStringNotContainsString('Tests/', $entry, 'A case-variant tests segment must not ship.');
                $this->assertStringNotContainsString('Build.json', $entry, 'A case-variant build.json must not ship.');
                $this->assertStringNotContainsString('VENDOR/', $entry, 'A case-variant vendor segment must not ship.');
                $this->assertStringNotContainsString('vendor /', $entry, 'A trailing-junk vendor segment must not ship.');
            }

            // ONE verdict, this direction: the artifact the doctrine
            // ships is the artifact the inspector accepts.
            $this->assertSame(
                array(),
                wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-deventry-case'),
                'Build and inspect must agree on the case-folded vocabulary.'
            );

            // ONE verdict, the reverse: a HAND-CRAFTED zip carrying the
            // same case-variant spellings rejects, naming them.
            $hostileZip = self::distDir() . '/connectors-deventrycase-demo-1.0.0.zip';
            $hostile = new ZipArchive();
            $hostile->open($hostileZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $head = "Plugin Name:       deventrycase-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       deventrycase-demo\nAuthor:            x\n";
            $hostile->addFromString('deventrycase-demo/deventrycase-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'DEVENTRYCASE_DEMO_VERSION', '1.0.0' );\n");
            foreach (array( 'deventrycase-demo/Tests/Bootstrap.php', 'deventrycase-demo/Build.json', 'deventrycase-demo/VENDOR/lib.php', 'deventrycase-demo/vendor /acme/DevDependency.php' ) as $entry) {
                $hostile->addFromString($entry, 'x');
            }
            $hostile->close();
            try {
                $violations = wp_connectors_inspect_artifact($hostileZip, self::distDir() . '/.inspect-deventry-case-hostile');
                $this->assertNotSame(array(), $violations, 'A crafted zip carrying a case-variant development entry must reject.');
                $report = implode("\n", $violations);
                $this->assertStringContainsString('Tests/Bootstrap.php', $report);
                $this->assertStringContainsString('Build.json', $report);
                $this->assertStringContainsString('VENDOR/lib.php', $report);
                $this->assertStringContainsString('vendor /acme/DevDependency.php', $report, 'A trailing-junk vendor entry must reject too.');
            } finally {
                @unlink($hostileZip);
                @unlink($hostileZip . '.sha256');
            }
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Verifier-round pin (t31-r5-11): the manifest merge's read-modify-
     * write raced across processes — two concurrent builds of DIFFERENT
     * plugins both exited 0 while the landed checksums.txt described
     * only one of them (adversarially confirmed, 30/72 synchronized
     * trials pre-fix; t31-r5-S's unique temp names had closed the write
     * interleave, not the lost update). The read→land span holds an
     * exclusive flock on dist/.checksums.lock now: concurrent runs
     * serialize the merge and every entry survives.
     */
    public function testConcurrentBuildsOfDifferentPluginsKeepEveryManifestEntry(): void
    {
        $connectors = array(
            'race-a-demo' => true,
            'race-b-demo' => true,
            'race-c-demo' => true,
            'race-d-demo' => true,
            'race-e-demo' => true,
            'race-f-demo' => true,
        );
        $repo = $this->makeBuildCliRepo($connectors);

        try {
            // Fire every build simultaneously; each is a full CLI run
            // against the same dist/ and the same manifest.
            $handles = array();
            foreach (array_keys($connectors) as $slug) {
                $handles[ $slug ] = proc_open(
                    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=' . escapeshellarg($slug),
                    array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
                    $pipes
                );
                fclose($pipes[0]);
            }

            foreach ($handles as $slug => $handle) {
                $exit = proc_close($handle);
                $this->assertSame(0, $exit, "The concurrent build of {$slug} must succeed.");
            }

            $manifest = (string) file_get_contents($repo . '/dist/checksums.txt');
            foreach (array_keys($connectors) as $slug) {
                $this->assertStringContainsString(
                    "connectors-{$slug}-1.0.0.zip  ",
                    $manifest,
                    "Every concurrent run's manifest entry must survive the raced landings (lost {$slug})."
                );
            }
        } finally {
            WpHarness::rrmdir($repo);
        }
    }

    /**
     * Fix-round pin (t31-r10-4): the stage tree was named
     * `.stage-<slug>` — SHARED between concurrent builds of the same
     * plugin, so run B's startup/finally rrmdir deleted run A's
     * in-flight stage tree and A refused loudly on a spurious
     * "cannot add … to" (build survival, not artifact correctness —
     * t31-r5-11 adjudicated the manifest race and named this fix: "then
     * the stage dir wants the PID too"). The stage is PID-named now
     * (`.stage-<slug>-<pid>`), the finally releases exactly the run's
     * own tree, and the startup sweep reclaims only DEAD-process
     * orphans of the SAME plugin — a live run's tree is never touched.
     */
    public function testConcurrentSamePluginBuildsKeepTheirStageTreesAndDeadOnesAreSwept(): void
    {
        // Part 1, end-to-end through the CLI entry: two synchronized
        // builds of the SAME plugin both exit 0 and no stage tree of
        // any pid survives the pair (pre-fix, the shared name made the
        // pair racy — B's startup rrmdir of A's in-flight tree).
        $repo = $this->makeBuildCliRepo(array('race-same-demo' => true));

        try {
            $handles = array();
            for ($i = 0; $i < 2; ++$i) {
                $handles[] = proc_open(
                    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=race-same-demo',
                    array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
                    $pipes
                );
            }

            foreach ($handles as $handle) {
                $this->assertSame(0, proc_close($handle), 'A concurrent same-plugin build must survive its sibling: the stage trees are pid-disjoint.');
            }
            $this->assertSame(array(), glob($repo . '/dist/.stage-race-same-demo*') ?: array(), 'No stage tree of any pid may survive the pair.');
            $this->assertStringContainsString('connectors-race-same-demo-1.0.0.zip  ', (string) file_get_contents($repo . '/dist/checksums.txt'));
        } finally {
            WpHarness::rrmdir($repo);
        }

        // Part 2, the sweep, deterministic: a LIVE foreign run's stage
        // tree is never touched by a sibling build; a DEAD-pid orphan of
        // the same plugin is reclaimed by the next build; foreign-slug
        // and pid-less spellings are left to their owners.
        $scratch = tempnam(sys_get_temp_dir(), 'wpct-stage-sweep-');
        unlink($scratch);
        mkdir($scratch . '/dist', 0755, true);
        mkdir($scratch . '/plugin/stage-demo/src', 0755, true);
        $head = "Plugin Name:       stage-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       stage-demo\nAuthor:            x\n";
        file_put_contents($scratch . '/plugin/stage-demo/stage-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'STAGE_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n");
        file_put_contents($scratch . '/plugin/stage-demo/src/autoload.php', "<?php\nspl_autoload_register( static function ( string \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\StageDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n");

        $pid_file = $scratch . '/live-pid.txt';
        $live = proc_open(
            'exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('file_put_contents(' . var_export($pid_file, true) . ', (string) getmypid()); sleep(60);'),
            array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
            $live_pipes
        );

        try {
            $deadline = microtime(true) + 10.0;
            while (! is_file($pid_file) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($pid_file, 'The spawned live run must publish its pid.');
            $live_pid = (int) file_get_contents($pid_file);

            // The live sibling's in-flight tree, a dead-pid orphan of the
            // same plugin (999999999 exceeds every Linux pid_max), a
            // pid-less foreign spelling, another plugin's dead-pid
            // orphan, and the crashed-run TEMP spellings (verifier round
            // t31-r10-13: the zip temp, its sidecar twin, and libzip's
            // in-window .part spelling — dead pid swept, live pid kept).
            mkdir($scratch . '/dist/.stage-stage-demo-' . $live_pid . '/stage-demo', 0755, true);
            file_put_contents($scratch . '/dist/.stage-stage-demo-' . $live_pid . '/stage-demo/inflight.txt', 'run A mid-flight');
            mkdir($scratch . '/dist/.stage-stage-demo-999999999', 0755, true);
            mkdir($scratch . '/dist/.stage-stage-demo', 0755, true);
            mkdir($scratch . '/dist/.stage-other-demo-999999999', 0755, true);
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997', 'half a zip');
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.sha256', 'half a sidecar');
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.acce0w.part', 'libzip window');
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-' . $live_pid, 'live run temp');
            file_put_contents($scratch . '/dist/.checksums-orphan', 'pid-less manifest staging temp');

            // Run "B": builds green BESIDE the live sibling.
            WpConnectorsBuild::buildPlugin($scratch . '/plugin/stage-demo', $scratch . '/dist');

            $this->assertFileExists($scratch . '/dist/.stage-stage-demo-' . $live_pid . '/stage-demo/inflight.txt', 'A live run\'s stage tree is never touched by a sibling build.');
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-stage-demo-999999999', 'A dead-pid orphan of the plugin is swept by the next build — never orphaned forever.');
            $this->assertDirectoryExists($scratch . '/dist/.stage-stage-demo', 'A pid-less foreign spelling is left alone (nothing running this code creates it).');
            $this->assertDirectoryExists($scratch . '/dist/.stage-other-demo-999999999', 'Another plugin\'s stage dirs are that plugin\'s sweep\'s to reclaim.');
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-stage-demo-' . getmypid(), 'The run\'s own stage tree tears down on success.');
            $this->assertFileDoesNotExist($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997', 'A dead-pid zip temp is reclaimed — the crashed-run charter covers the temps too.');
            $this->assertFileDoesNotExist($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.sha256', 'A dead-pid sidecar temp is reclaimed.');
            $this->assertFileDoesNotExist($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.acce0w.part', 'A dead-pid libzip .part temp is reclaimed.');
            $this->assertFileExists($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-' . $live_pid, 'A LIVE run\'s temp is never touched by a sibling build.');
            $this->assertFileExists($scratch . '/dist/.checksums-orphan', 'A pid-less manifest staging temp is unattributable — left alone, never raced.');

            // Part 3: once the sibling's process is dead (terminated and
            // reaped), its leftover tree is reclaimed by the next build.
            proc_terminate($live);
            proc_close($live);
            $live = null;
            if (is_dir('/proc')) {
                $deadline = microtime(true) + 10.0;
                while (is_dir('/proc/' . $live_pid) && microtime(true) < $deadline) {
                    usleep(10000);
                }
            }

            WpConnectorsBuild::buildPlugin($scratch . '/plugin/stage-demo', $scratch . '/dist');

            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-stage-demo-' . $live_pid, 'A stage tree whose owning process died is reclaimed by the next build of the plugin.');

            /*
             * Part 4, the symlink legs (verifier round t31-r10-10): a
             * matching-named SYMLINK at a dead pid is never deleted
             * THROUGH (is_dir follows links; the sweep would have
             * emptied the target tree, reproduced end-to-end by the
             * verifier), and a link at the run's OWN stage name refuses
             * the build loudly.
             */
            $victim = $scratch . '/victim';
            mkdir($victim . '/inner', 0755, true);
            file_put_contents($victim . '/inner/keep.txt', 'survivor');
            file_put_contents($victim . '/keep2.txt', 'survivor');
            symlink($victim, $scratch . '/dist/.stage-stage-demo-999999998');

            WpConnectorsBuild::buildPlugin($scratch . '/plugin/stage-demo', $scratch . '/dist');

            $this->assertFileExists($victim . '/inner/keep.txt', 'The sweep never deletes through a symlink — the target tree must survive intact.');
            $this->assertFileExists($victim . '/keep2.txt', 'The sweep never deletes through a symlink — the target tree must survive intact.');
            $this->assertTrue(is_link($scratch . '/dist/.stage-stage-demo-999999998'), 'The sweep leaves a symlinked stage-shaped entry standing (it is never this code\'s product).');

            // The own-name leg: a link at THIS run's stage name refuses.
            symlink($victim, $scratch . '/dist/.stage-stage-demo-' . getmypid());
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/stage-demo', $scratch . '/dist');
                $this->fail('A symlink at the run\'s own stage name must refuse the build, never stage through the link.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('symlink', $e->getMessage());
            }
            unlink($scratch . '/dist/.stage-stage-demo-999999998');
            unlink($scratch . '/dist/.stage-stage-demo-' . getmypid());
            $this->assertFileExists($victim . '/keep2.txt', 'The refused build never touched the link target either.');
        } finally {
            if (null !== $live && is_resource($live)) {
                proc_terminate($live);
                proc_close($live);
            }
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Verifier-round pin (t31-r11-2): an INVISIBLE /proc entry is not a
     * death verdict. Under hidepid=2 another user's live build is
     * invisible in /proc while it runs, and the old liveness shortcut —
     * `is_dir('/proc') ? is_dir('/proc/<pid>') : <signal probe>` — read
     * that invisibility as DEAD, making the posix fallback (whose EPERM
     * answer means ALIVE) unreachable on every Linux: the sweep would
     * have rrmdired a live sibling build's in-flight stage tree, the
     * exact deletion the sweep's own docblock forbids ("a sweep that
     * cannot tell never deletes"). Invisibility falls through to the
     * signal-0 probe now; this pin drives the private verdict with the
     * /proc entry probe INJECTED as the hidepid view (every entry
     * invisible), so the deterministic legs run without needing a
     * second user on the host.
     */
    public function testAnInvisibleProcEntryIsNotADeathVerdict(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('The deterministic invisible-path verdicts need the posix signal-0 probe.');
        }
        $alive = new ReflectionMethod('WpConnectorsBuild', 'processIsAlive');
        $hidepid_view = static function (int $pid): bool {
            return false; // every /proc entry invisible to this process
        };

        /*
         * EPERM through the invisible path: a LIVE process that is not
         * ours to signal — pid 1 from an unprivileged runner (a root
         * runner's probe simply succeeds, also alive). The pre-fix
         * shortcut returned DEAD here.
         */
        $this->assertTrue($alive->invoke(null, 1, $hidepid_view), 'An invisible-but-live process (EPERM on signal 0) must read ALIVE — invisibility is not a death verdict.');

        // ESRCH through the invisible path: the one deterministic
        // invisible-and-dead verdict (a pid beyond every Linux pid_max).
        $this->assertFalse($alive->invoke(null, 999999999, $hidepid_view), 'An invisible entry whose signal probe returns ESRCH reads dead — the only invisible death verdict.');

        // The visible path is unchanged: a visible entry reads alive,
        // and a non-positive pid stays dead under every probe.
        $this->assertTrue($alive->invoke(null, getmypid()), 'A visible /proc entry (this process) reads alive.');
        $this->assertFalse($alive->invoke(null, 0, $hidepid_view), 'A non-positive pid is dead under every probe.');
    }

    /**
     * Fix-round pin (t31-r10-8): the version-constant stem rode a second
     * hand-spelled slug→identifier derivation beside
     * wp_connectors_namespace_suffix_from_slug() — twins this branch had
     * already synchronized by hand twice (t31-r5-8's digit rule,
     * t31-r5-12's dot separator). Both spellings derive from the ONE
     * core (wp_connectors_identifier_from_slug()) now; this pin holds
     * BYTE PARITY with each former hand spelling at the cutover (the
     * t31-r9-9 discipline), so the core provably changes nothing but
     * the drift risk.
     */
    public function testTheSlugToIdentifierDerivationHasOneCore(): void
    {
        $slugs = array('my-plugin', 'my.plugin', '3cx-oauth', 'openai-oauth', '42', 'x3-dev', 'zai', 'My-Plugin', '');

        foreach ($slugs as $slug) {
            // The namespace spelling: the former derivation, verbatim.
            $former_suffix = '';
            foreach (preg_split('/[-.]/', strtolower($slug)) ?: array() as $segment) {
                $former_suffix .= 'openai' === $segment ? 'OpenAi' : ucfirst($segment);
            }
            if ('' !== $former_suffix && ctype_digit($former_suffix[0])) {
                $former_suffix = '_' . $former_suffix;
            }
            $this->assertSame($former_suffix, wp_connectors_namespace_suffix_from_slug($slug), "Namespace suffix byte parity: {$slug}");

            // The constant spelling: the former derivation, verbatim (its
            // digit check rode the FULL name, '_VERSION' included).
            $former_constant = strtoupper(str_replace(array('-', '.'), '_', $slug)) . '_VERSION';
            if (ctype_digit($former_constant[0])) {
                $former_constant = '_' . $former_constant;
            }
            $this->assertSame($former_constant, wp_connectors_identifier_from_slug($slug, '_') . '_VERSION', "Constant stem byte parity: {$slug}");
        }

        // The shared rules, both spellings at once: same segments, same
        // digit-initial underscore.
        $this->assertSame('_3cxOauth', wp_connectors_namespace_suffix_from_slug('3cx-oauth'));
        $this->assertSame('_3CX_OAUTH_VERSION', wp_connectors_identifier_from_slug('3cx-oauth', '_') . '_VERSION');
    }

    /**
     * Verifier-round pin (t31-r11-6): the slug→identifier core folds
     * through the LOCALE-INDEPENDENT ASCII tables, never
     * strtolower()/ucfirst()/strtoupper(). The C-library folds consult
     * LC_CTYPE, and under a Turkish tr_* locale the dotted-I rule makes
     * 'zai' derive 'ZAİ_VERSION' (two-byte İ, U+0130) and 'zai-oauth'
     * derive 'İnkOauth'-shaped spellings — derived IDENTIFIERS, the
     * keyed vocabulary every plugin file and hand-written autoloader
     * prefix must match bare, so they must be identical in every
     * process (the r2-14 BY-SCOPE doctrine covers comparison keys,
     * which stay consistent under any locale; these do not). The pin
     * sets the locale when the host carries it; this development host
     * does not (locale -a: C, C.utf8, en_US.utf8, POSIX — setlocale
     * fails), so on it the divergence is argued from the fold tables
     * like AsciiFold's own docblock argues it — the spelling pins hold
     * everywhere, the locale pressure rides wherever the locale exists.
     * setlocale is process-global: attempted and restored in a finally
     * so no other test sees it.
     */
    public function testTheSlugToIdentifierFoldIsLocaleIndependent(): void
    {
        $previous = setlocale(LC_CTYPE, null);
        try {
            setlocale(LC_CTYPE, 'tr_TR.UTF-8');

            $this->assertSame('ZAI', wp_connectors_identifier_from_slug('zai', '_'), "The constant stem folds 'zai' to all-caps ASCII — never the dotted-I 'ZAİ' a tr_* locale's strtoupper() derives.");
            $this->assertSame('ZAI_VERSION', wp_connectors_identifier_from_slug('zai', '_') . '_VERSION');
            $this->assertSame('ZaiOauth', wp_connectors_namespace_suffix_from_slug('zai-oauth'), "The namespace segment capitalizes per segment in ASCII — never an 'İnk'-shaped spelling.");
            $this->assertSame('MyPlugin', wp_connectors_namespace_suffix_from_slug('MY.PLUGIN'), 'The lower fold that splits the segments is ASCII too: dot and dash separate the same segments under every locale.');
            $this->assertSame('MY_PLUGIN_VERSION', wp_connectors_identifier_from_slug('My.Plugin', '_') . '_VERSION');
            $this->assertSame('_3CX_OAUTH_VERSION', wp_connectors_identifier_from_slug('3cx-oauth', '_') . '_VERSION', 'The digit rule rides the same ASCII fold.');
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    }

    /**
     * Verifier-round pin (t31-r5-12): a dotted slug ('my.plugin' — legal
     * to the slug regex and the text-domain gate) derived the constant
     * 'MY.PLUGIN_VERSION' and the namespace suffix 'My.plugin' — both
     * bare-code-unreachable (every bare spelling of either is a lexer
     * error) while every gate passed and the artifact shipped green
     * (adversarially confirmed, parse error driven). Both derivations
     * treat '.' as a separator now: the constant maps it to '_' like
     * '-' ('MY_PLUGIN_VERSION') and the suffix capitalizes per segment
     * ('MyPlugin'), so a dotted slug builds with labels code can
     * actually spell.
     *
     * Verifier round t31-r11-4: the end-to-end build ran against the
     * REAL dist/ with no preservation — the dotted-slug zip and sidecar
     * ('connectors-my.plugin-1.0.0.zip' — matched by neither of
     * tearDown's glob patterns) leaked permanently, a zip whose checksum
     * the restored manifest records nowhere (verified by execution on
     * the leak the round found in dist/). The build rides
     * withArtifactStatePreserved() now — zip, sidecar, and manifest
     * snapshotted and restored byte-for-byte on every exit path, the
     * introduced zip removed — so the real dist/ is identical after the
     * run.
     */
    public function testADottedSlugDerivesLegalLabelsForConstantAndNamespace(): void
    {
        $this->assertSame('MyPlugin', wp_connectors_namespace_suffix_from_slug('my.plugin'));
        $this->assertSame('MyPlugin', wp_connectors_namespace_suffix_from_slug('my-plugin'), "Dot and dash separate the same segments.");

        $tempPlugin = self::distDir() . '/.dot-slug/my.plugin';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        $head = "Plugin Name:       my.plugin\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       my.plugin\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'MY_PLUGIN_VERSION', '1.0.0' );\nif ( MY_PLUGIN_VERSION !== '1.0.0' ) {\n\treturn;\n}\nrequire_once __DIR__ . '/src/autoload.php';\n";
        file_put_contents($tempPlugin . '/my.plugin.php', $main);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\MyPlugin\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);

        try {
            // The gates the build rides agree with the derivations.
            $this->assertSame(array(), wp_connectors_autoloader_violations($tempPlugin));
            $this->assertSame(array(), wp_connectors_version_constant_violations($tempPlugin, wp_connectors_parse_plugin_headers($tempPlugin . '/my.plugin.php')));

            // The naive dotted spellings the pre-fix derivation produced
            // are mismatches now.
            $naive = str_replace("define( 'MY_PLUGIN_VERSION', '1.0.0' );", "define( 'MY.PLUGIN_VERSION', '1.0.0' );", $main);
            file_put_contents($tempPlugin . '/my.plugin.php', $naive);
            file_put_contents($tempPlugin . '/src/autoload.php', str_replace('MyPlugin', 'My.plugin', $autoload));
            $this->assertNotSame(array(), wp_connectors_version_constant_violations($tempPlugin, wp_connectors_parse_plugin_headers($tempPlugin . '/my.plugin.php')));
            $this->assertNotSame(array(), wp_connectors_autoloader_violations($tempPlugin));
            file_put_contents($tempPlugin . '/my.plugin.php', $main);
            file_put_contents($tempPlugin . '/src/autoload.php', $autoload);

            /*
             * End-to-end through the artifact-state machinery: the
             * artifact builds and the shipped main file parses with its
             * bare constant reference, and the real dist/ state around
             * it is preserved byte-for-byte.
             */
            $this->withArtifactStatePreserved(
                'connectors-my.plugin-1.0.0.zip',
                function (string $zipPath) use ($tempPlugin): void {
                    $built = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
                    $this->assertSame($zipPath, $built);
                    $zip = new ZipArchive();
                    $this->assertTrue($zip->open($zipPath));
                    $shippedMain = (string) $zip->getFromName('my.plugin/my.plugin.php');
                    $zip->close();
                    $probe = self::distDir() . '/.dot-slug/probe-main.php';
                    file_put_contents($probe, $shippedMain);
                    try {
                        $output = array();
                        $exit = 0;
                        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($probe) . ' 2>&1', $output, $exit);
                        $this->assertSame(0, $exit, 'The shipped main file under a dotted slug must parse with its bare constant reference: ' . implode("\n", $output));
                    } finally {
                        @unlink($probe);
                    }
                },
                function (string $sidecarPrevious, string $manifestPrevious, string $sidecarPath, string $manifestPath): void {
                    $this->assertSame($sidecarPrevious, (string) file_get_contents($sidecarPath), 'The real checksum sidecar must survive the dotted-slug build byte-for-byte.');
                    $this->assertSame($manifestPrevious, (string) file_get_contents($manifestPath), 'The real checksum manifest must survive the dotted-slug build byte-for-byte — a leaked zip whose checksum is recorded nowhere is a corruption, not a build.');
                }
            );
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Verifier-round pin (t31-r5-13): the manifest merge's unchecked
     * read laundered a chmod-000 checksums.txt into an EMPTY line set —
     * the next build landed a manifest carrying only its own entry,
     * silently destroying every other plugin's checksum at exit 0
     * (adversarially confirmed). The read is owned now: an unreadable
     * manifest refuses the build before any landing, the surviving
     * zip/sidecar stay byte-identical, and the unreadable file is left
     * exactly as found.
     */
    public function testAnUnreadableManifestRefusesTheBuildAndKeepsEveryEntry(): void
    {
        $scratch = self::distDir() . '/.manifest-read';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        $manifestPath = $scratch . '/dist/checksums.txt';
        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            // A sibling plugin's entry rides the manifest, and its
            // artifact rides the dist beside it — under the t31-r12-7
            // regeneration prune an entry survives exactly while its
            // artifact exists, so the sibling's zip must be real for
            // the survival half of this pin to mean anything.
            file_put_contents($scratch . '/dist/connectors-other-demo-1.0.0.zip', 'sibling artifact bytes');
            file_put_contents($manifestPath, "connectors-other-demo-1.0.0.zip  " . str_repeat('a', 64) . "\n" . (string) file_get_contents($manifestPath));
            $manifestBefore = (string) file_get_contents($manifestPath);
            $zipBefore = (string) file_get_contents($zipPath);
            $sidecarBefore = (string) file_get_contents($zipPath . '.sha256');

            chmod($manifestPath, 0000);
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('An unreadable manifest must refuse the build, never land a one-entry replacement.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot read the checksum manifest', $e->getMessage());
            }
            $this->assertSame($zipBefore, (string) file_get_contents($zipPath), 'The previous good zip survives the refused merge byte-for-byte.');
            $this->assertSame($sidecarBefore, (string) file_get_contents($zipPath . '.sha256'), 'The sidecar survives the refused merge byte-for-byte.');
            $this->assertFileExists($manifestPath, 'The unreadable manifest is left exactly as found.');

            // Recovery: readable again, the merge keeps every LIVE
            // entry — the sibling's artifact exists beside the manifest
            // (the t31-r12-7 prune drops only entries whose artifact
            // is gone).
            chmod($manifestPath, 0644);
            WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $rebuilt = (string) file_get_contents($manifestPath);
            $this->assertStringContainsString('connectors-other-demo-1.0.0.zip', $rebuilt, "The sibling plugin's entry survives the recovered merge.");
            $this->assertStringContainsString('connectors-example-connector-0.1.0.zip', $rebuilt);
        } finally {
            @chmod($manifestPath, 0644);
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Verifier-round pin (t31-r5-15): the Version header's bytes flowed
     * unchecked into the artifact filename and the staging paths — a
     * traversal spelling ('0.1/../../../vsec-precious', with the version
     * constant matching so both gates passed) staged the archive and
     * sidecar OUTSIDE dist/ on runtimes whose write paths lexically
     * collapse '..', stranding valid-artifact bytes past the cleanup's
     * raw-spelling unlinks (adversarially confirmed; the pre-round code
     * had published the whole set at the escaped path at exit 0). The
     * header gate requires a version TOKEN now — no separators — at the
     * ONE gate conventions, build, and inspect all ride.
     */
    public function testATraversalSpelledVersionHeaderRefusesTheBuild(): void
    {
        $tempPlugin = self::distDir() . '/.version-token/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        $mainPath = $tempPlugin . '/example-connector.php';
        $main = (string) file_get_contents($mainPath);

        try {
            // Both the header and the constant carry the spelling (the
            // version-constant gate requires them to match).
            $traversal = '0.1/../../../vsec-precious';
            file_put_contents($mainPath, str_replace(array('Version:           0.1.0', "'0.1.0'"), array("Version:           {$traversal}", "'{$traversal}'"), $main));
            try {
                WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
                $this->fail('A traversal-spelled Version header must refuse the build at the header gate.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('version token', $e->getMessage());
                $this->assertStringContainsString($traversal, $e->getMessage());
            }

            // Control: the ordinary version shape still builds, and the
            // token charset's legal specials (dot, plus) pass the gate.
            file_put_contents($mainPath, str_replace(array("Version:           {$traversal}", "'{$traversal}'"), array('Version:           0.1.0', "'0.1.0'"), $main));
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            $this->assertFileExists($zipPath);

            foreach (array('1.0.0-beta.1', '1.0+build.2') as $legal) {
                file_put_contents($mainPath, str_replace(array('Version:           0.1.0', "'0.1.0'"), array("Version:           {$legal}", "'{$legal}'"), $main));
                $legalZip = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
                @unlink($legalZip);
                @unlink($legalZip . '.sha256');
            }
            @unlink(self::distDir() . '/checksums.txt');
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Fix-round pin (t31-r9-6): the provenance-banner insertion was
     * case- and BOM-sensitive with no zero-match guard — '<?PHP' (a
     * legal PHP open tag) and a BOM-prefixed source matched nothing,
     * rewrote clean, and shipped BANNER-LESS silently (reproduced
     * pre-fix on both spellings), and the "Do not edit here" marker is
     * load-bearing provenance. The doctrine, chosen and documented at
     * the seam: BANNER-IN-PLACE — the pattern matches an optional BOM
     * then the open tag case-insensitively, PRESERVES the matched
     * opener bytes verbatim (the source's own spelling is not the
     * rewriter's to rewrite), and a ZERO-MATCH (no open tag at the
     * head) refuses loudly at the banner seam itself — the token-walk
     * postcondition only ever caught such a file incidentally, when
     * its bytes happened to spell the family.
     */
    public function testEveryLegalOpenerCarriesTheProvenanceBannerAndATaglessSourceRefuses(): void
    {
        $declaration = 'namespace Deicod\\WpConnectors\\Shared\\Clock;';

        // The canonical opener keeps its exact head shape (regression
        // guard for the insertion itself).
        $canonical = WpConnectorsBuild::rewriteSharedNamespace("<?php\n" . $declaration . "\nclass A {}\n", 'OpenAiOauth', 'shared/src/Clock/A.php');
        $this->assertStringStartsWith("<?php\n\n/**\n * Generated copy of shared/src/Clock/A.php", $canonical);

        // '<?PHP' is a legal opener: bannered IN PLACE, the original
        // spelling preserved byte-for-byte.
        $upper = WpConnectorsBuild::rewriteSharedNamespace("<?PHP\n" . $declaration . "\nclass A {}\n", 'OpenAiOauth', 'shared/src/Clock/A.php');
        $this->assertStringContainsString('Do not edit here', $upper, 'An uppercase opener must carry the provenance banner.');
        $this->assertStringStartsWith("<?PHP\n\n/**", $upper, 'The banner follows the opener verbatim — the spelling is the source\'s own.');

        // A BOM-prefixed opener: bannered after the tag, the BOM stays
        // exactly where the source carried it.
        $bom = WpConnectorsBuild::rewriteSharedNamespace("\xEF\xBB\xBF<?php\n" . $declaration . "\nclass A {}\n", 'OpenAiOauth', 'shared/src/Clock/A.php');
        $this->assertStringContainsString('Do not edit here', $bom, 'A BOM-prefixed opener must carry the provenance banner.');
        $this->assertStringStartsWith("\xEF\xBB\xBF<?php\n\n/**", $bom, 'The BOM stays at the head, the banner follows the tag.');

        // A source with no open tag at the head REFUSES at the banner
        // seam, loudly, naming the file.
        $refused = null;
        try {
            WpConnectorsBuild::rewriteSharedNamespace("no tag here\n" . $declaration . "\nclass A {}\n", 'OpenAiOauth', 'shared/src/Clock/A.php');
            $this->fail('A source with no PHP open tag must refuse — its generated copy would ship without the load-bearing provenance marker.');
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }
        $this->assertStringContainsString('provenance banner', $refused);
        $this->assertStringContainsString('shared/src/Clock/A.php', $refused);
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

        /*
         * Fix-round pin (t31-r4 K1 / t31-r4-4), restructured by t31-r7:
         * group-use MEMBER spellings — the prefix before '{' is
         * Deicod\WpConnectors itself and the members carry the Shared
         * segment — are rewritten at the member's LEADING Shared segment
         * (mixed-kind members included). The round-7 sibling doctrine
         * SUPERSEDES the old battery's third row ('{Other\Shared as O,
         * SharedStorage\Widget}' was pinned untouched-legal; those are
         * SIBLINGS under the vendor prefix now and refuse in the
         * survivors battery below): the rewriter owns no sibling
         * spelling, so one ships pointing at a namespace that does not
         * exist inside the plugin — the terminal-fix decision, ledgered
         * with the spelling history that motivated it.
         */
        $groupUse = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Shared\\Clock, Shared\\Storage\\Widget as W};\nuse Deicod\\WpConnectors\\{function Shared\\Clock\\now, const Shared\\TTL as T};\nclass GroupUseStore\n{\n}\n";
        $groupRewritten = WpConnectorsBuild::rewriteSharedNamespace($groupUse, 'OpenAiOauth', 'shared/src/GroupUseStore.php');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\{OpenAiOauth\\Shared\\Clock, OpenAiOauth\\Shared\\Storage\\Widget as W};', $groupRewritten, 'A group-use member carrying the Shared segment is rewritten at its leading segment.');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\{function OpenAiOauth\\Shared\\Clock\\now, const OpenAiOauth\\Shared\\TTL as T};', $groupRewritten, 'Mixed-kind group members rewrite too (the kind prefix rides along).');
        $this->assertStringNotContainsString('Deicod\\WpConnectors\\{Shared', $groupRewritten, 'No unrewritten group member may survive.');

        /*
         * The total postcondition (the token detector, t31-r7): a family
         * reference the rewrite does not own REFUSES the build loudly
         * with the file, byte offset, resolved name, and position kind.
         * The t31-r4 rows stay (the nested brace group, the case-variant
         * spelling, the multiline string reference, the docblock
         * @throws, the group member ALIASED as 'Shared' — caught now as
         * the sibling its member composes to); the round-7 rows close
         * the shapes three regex rounds were each one spelling away
         * from: the comment-interrupted use (a comment can only
         * INTERRUPT the token run, never hide the name — the bytes
         * defeat every contiguous probe, including the r4-K1 survivor
         * regex), the SIBLING imports (the rewrite owns nothing under
         * the vendor prefix but Shared, and a plugin's private tree
         * loads nothing else — the SharedStorage spelling the old
         * battery pinned untouched-legal flips with the doctrine), the
         * double-backslash class-string (judged by its unescaped VALUE,
         * not its bytes), and the bare vendor-prefix import.
         */
        $survivors = array(
            'nested brace group' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Shared\\{Clock}};\nclass NestedGroupStore\n{\n}\n",
            'case-variant use' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse deicod\\wpconnectors\\shared\\Clock;\nclass CaseVariantStore\n{\n}\n",
            'multiline string reference' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass MultilineStore\n{\n    public function name(): string\n    {\n        return 'Deicod\\WpConnectors\\\nShared\\Clock';\n    }\n}\n",
            'docblock @throws reference' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n/**\n * @throws \\Deicod\\WpConnectors\\Shared\\Exception\\OAuthRuntimeException\n */\nclass DocblockStore\n{\n}\n",
            'group member aliased as Shared' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Clock as Shared};\nclass AliasedMemberStore\n{\n}\n",
            'comment-interrupted use, between the segments (t31-r7-1)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod/* pick one */\\WpConnectors\\Shared\\Clock;\nclass InterruptedStore\n{\n}\n",
            'whitespace-interrupted use, after the separator (t31-r7-6)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\\nShared\\Clock;\nclass WhitespaceStore\n{\n}\n",
            'whitespace-interrupted declaration, after the separator (t31-r7-6)' => "<?php\nnamespace Deicod\\WpConnectors\\\nShared;\nclass WhitespaceDeclStore\n{\n}\n",
            'whitespace-interrupted code reference, after the separator (t31-r7-6)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface WhitespaceCodeFixture\n{\n    public function name(): string;\n}\nfinal class WhitespaceCodeCarrier\n{\n    public function name(): string\n    {\n        return \\Deicod\\WpConnectors\\\nShared\\Clock::class;\n    }\n}\n",
            'sibling import under the vendor prefix (t31-r7-2)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Zai\\ApiClient;\nclass SiblingStore\n{\n}\n",
            'SharedStorage-prefixed sibling (the r4 pin, flipped by the r7 doctrine)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\SharedStorage\\Widget;\nclass SharedStorageStore\n{\n}\n",
            'group-use member carrying a sibling (t31-r7-2)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Shared\\Clock, Zai\\Api};\nclass GroupSiblingStore\n{\n}\n",
            'double-backslash class-string, judged by value (t31-r7-4)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass ClassStringStore\n{\n    public function name(): string\n    {\n        return 'Deicod\\\\WpConnectors\\\\Shared\\\\Clock';\n    }\n}\n",
            'bare vendor-prefix import' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors;\nclass BarePrefixStore\n{\n}\n",
            'dangling as eats the next reference (t31-r7-7)' => "<?php\nnamespace A;\nuse Foo\\Bar as;\n\$x = \\Deicod\\WpConnectors\\Shared\\Clock::class;\n",
            'source-spelled TARGET-rooted code reference (t31-r7-8)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface TargetCodeFixture\n{\n    public function name(): string;\n}\nfinal class TargetCodeCarrier\n{\n    public function name(): string\n    {\n        return \\class_exists(\\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\Ghost::class);\n    }\n}\n",
            'source-spelled TARGET-rooted class-string (t31-r7-8)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface TargetStringFixture\n{\n    public function name(): string;\n}\nfinal class TargetStringCarrier\n{\n    public function name(): string\n    {\n        return 'Deicod\\\\WpConnectors\\\\OpenAiOauth\\\\Shared\\\\Clock\\\\Ghost';\n    }\n}\n",
            'docblock naming the TARGET (t31-r7-8)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n/**\n * @throws \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\Ghost\n */\ninterface TargetDocblockFixture\n{\n}\n",
            'trait-adaptation block carrying a family reference (t31-r10-1)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait AdaptStoreTrait\n{\n}\nfinal class AdaptStore\n{\n    use AdaptStoreTrait {\n        \\Deicod\\WpConnectors\\Shared\\Clock::now insteadof AdaptStoreTrait;\n    }\n}\n",
            'trait-adaptation clause naming the family itself (t31-r10-1)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClauseStore\n{\n    use Deicod\\WpConnectors\\Shared\\ClockFamily { tick as tock; }\n}\n",
            'multi-trait adaptation carrying a family member (t31-r10-1)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait MultiStoreA { public function s(): void {} }\ntrait MultiStoreB { public function s(): void {} }\nfinal class MultiStore\n{\n    use MultiStoreA, MultiStoreB {\n        MultiStoreA::s insteadof MultiStoreB;\n        \\Deicod\\WpConnectors\\Shared\\Ghost::s insteadof MultiStoreA;\n    }\n}\n",
            'b-prefixed class-string, double-quoted, judged by value (t31-r10-2)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass BPrefixStore\n{\n    public function name(): string\n    {\n        return b\"Deicod\\\\WpConnectors\\\\Shared\\\\Clock\";\n    }\n}\n",
            'B-prefixed class-string, single-quoted, judged by value (t31-r10-2)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass BPrefixStore\n{\n    public function name(): string\n    {\n        return B'Deicod\\\\WpConnectors\\\\Shared\\\\Clock';\n    }\n}\n",
            'b-prefixed hex-escaped class-string, judged by value (t31-r10-2)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass BPrefixStore\n{\n    public function name(): string\n    {\n        return b\"\\x44eicod\\\\WpConnectors\\\\Shared\\\\Clock\";\n    }\n}\n",
            'fully-qualified group member against a non-family prefix (t31-r10-9)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse OtherVendor\\Stuff\\{ \\Deicod\\WpConnectors\\Shared\\Clock };\nclass FqMemberStore\n{\n}\n",
            'qualified name after as, plain use (t31-r10-9)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse OtherVendor\\X as \\Deicod\\WpConnectors\\Shared\\Clock;\nclass FqAliasStore\n{\n}\n",
            'qualified name after as, group body (t31-r10-9)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse OtherVendor\\Stuff\\{ Y as \\Deicod\\WpConnectors\\Shared\\Clock };\nclass FqGroupAliasStore\n{\n}\n",
            'empty group body naming the vendor prefix (t31-r10-9)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{};\nclass EmptyGroupStore\n{\n}\n",
            'multi-trait adaptation CLAUSE naming the family (t31-r10-11)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait ClauseListStoreA { public function s(): void {} }\nfinal class ClauseListStore\n{\n    use ClauseListStoreA, Deicod\\WpConnectors\\Shared\\Clock {\n        ClauseListStoreA::s insteadof Clock;\n    }\n}\n",
        );
        foreach ($survivors as $label => $hostile) {
            try {
                WpConnectorsBuild::rewriteSharedNamespace($hostile, 'OpenAiOauth', 'shared/src/Hostile.php');
                $this->fail("A shared-namespace spelling the rewriter does not know ({$label}) must refuse the rewrite, never survive it.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('survived the rewrite', $e->getMessage());
                $this->assertStringContainsString('Hostile.php', $e->getMessage(), "The refusal must name the file ({$label}).");
                $this->assertStringContainsString('byte offset', $e->getMessage(), "The refusal must locate the survivor ({$label}).");
            }
        }

        /*
         * Soundness of the total scan (the round's design mandate,
         * verified empirically then pinned): scanning the REAL tree's
         * rewrites must stay clean for every legal suffix shape. A false
         * positive here would make every embed build refuse; a silent
         * survivor would ship a broken import. The r7 belt rides the
         * token walk over the REWRITTEN output (the mandate's
         * "verifiable via the token walk on the rewritten output"):
         * rewriteSharedNamespace()'s own postcondition already refuses
         * any survivor, and this loop additionally asserts every family
         * reference in the output is the rewritten TARGET — kinds
         * included — so the detector and the rewriter provably agree on
         * the whole legal tree, suffix shape by suffix shape.
         */
        foreach ($this->fixtureSuffixes() as $suffix) {
            $rewritten_count = 0;
            $root = realpath(__DIR__ . '/../shared/src');
            $target_lower = strtolower('Deicod\\WpConnectors\\' . $suffix . '\\Shared');
            foreach (wp_connectors_php_source_files($root) as $relative) {
                $rewritten_output = WpConnectorsBuild::rewriteSharedNamespace(
                    (string) file_get_contents($root . '/' . $relative),
                    $suffix,
                    'shared/src/' . $relative
                );
                foreach (wp_connectors_shared_family_references($rewritten_output, 'Deicod\\WpConnectors\\' . $suffix . '\\Shared') as $reference) {
                    $this->assertContains($reference['kind'], array('declaration', 'use'), "The rewrite produces the target spelling ONLY in declarations and use statements ({$relative}; verifier t31-r7-8's measured allow-set).");
                    $this->assertTrue(
                        $reference['lower'] === $target_lower || 0 === strpos($reference['lower'], $target_lower . '\\'),
                        "Every family reference in a rewritten output must be the target prefix ({$relative}, suffix {$suffix}): {$reference['name']}"
                    );
                }
                ++$rewritten_count;
            }
            $this->assertGreaterThanOrEqual(20, $rewritten_count, "The soundness sweep must see the real tree (suffix {$suffix}).");
        }

        /*
         * A DIFFERENT namespace that merely starts with 'Shared'
         * ('SharedStorage') REFUSES now (t31-r7's sibling doctrine,
         * superseding the r4-era pin that kept it untouched): it is a
         * sibling under the vendor prefix, the rewriter owns no sibling
         * spelling, and a plugin's private tree loads nothing under
         * Deicod\WpConnectors but its own <Suffix>\Shared target. The
         * same flip applies to the old group battery's '{Other\Shared
         * as O}' member — both now ride the survivors battery above.
         */
        $foreign = "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Storage;\nuse Deicod\\WpConnectors\\SharedStorage\\Widget;\nclass WidgetStore\n{\n}\n";
        try {
            WpConnectorsBuild::rewriteSharedNamespace($foreign, 'OpenAiOauth', 'shared/src/Storage/WidgetStore.php');
            $this->fail('A Shared-prefixed SIBLING namespace must refuse the rewrite (the r7 sibling doctrine), never stay untouched.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('survived the rewrite', $e->getMessage());
            $this->assertStringContainsString('WidgetStore.php', $e->getMessage());
        }

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

    /**
     * Fix-round pin (t31-r8-1): the use-statement boundary SET. r7-7
     * taught the walk's use-tracking state to die at ';' — but a CLOSE
     * TAG is a statement terminator exactly like ';' (the engine implies
     * the semicolon at '?>'), and it was not in the set: a hostile
     * `use Foo\Bar as ?>` left the alias skip (and the unclosed group's
     * prefix) armed across the mode boundary and into the re-entered
     * code, where the armed skip silently ATE the next name run — the
     * family reference after the tag became invisible to both gates and
     * shipped at exit 0 (reproduced red under the pre-fix detector:
     * zero family references). The matrix walks every boundary
     * spelling over every state that can survive one: the alias skip
     * behind a dangling `as`, and an unclosed group prefix behind '{'.
     * The clean direction: an own-namespace file that legitimately ends
     * statements with '?>' stays clean through the same walk.
     */
    public function testAUseStatementDiesAtEveryStatementBoundarySpelling(): void
    {
        $boundaries = array(
            'semicolon (the r7-7 spelling)' => "use Foo\\Bar as;\n\$x = \\Deicod\\WpConnectors\\Zai\\ApiClient::class;\n",
            'close tag with inline HTML between (the r8-1 repro)' => "use Foo\\Bar as ?>\ninline HTML\n<?php\n\$x = \\Deicod\\WpConnectors\\Zai\\ApiClient::class;\n",
            'close tag re-entered by <?= (T_OPEN_TAG_WITH_ECHO)' => "use Foo\\Bar as ?>\n<?= 'x' ?>\n<?php\n\$x = \\Deicod\\WpConnectors\\Zai\\ApiClient::class;\n",
            'close tag with no gap before the open tag' => "use Foo\\Bar as ?><?php\n\$x = \\Deicod\\WpConnectors\\Zai\\ApiClient::class;\n",
            'unclosed group prefix survives the close tag (the composition half)' => "use Foo\\{Bar, ?>\n<?php\nuse Deicod\\WpConnectors\\Zai\\Api;\nclass TagBoundStore\n{\n}\n",
        );
        foreach ($boundaries as $label => $tail) {
            $source = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n" . $tail;
            try {
                WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/TagBound.php');
                $this->fail("A use statement's tracking state must die at every statement-boundary spelling ({$label}) — never eat the name run after the boundary.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('survived the rewrite', $e->getMessage(), "The refusal is the postcondition's ({$label}).");
                $this->assertStringContainsString('TagBound.php', $e->getMessage(), "The refusal must name the file ({$label}).");
                $this->assertStringContainsString('Deicod\\WpConnectors\\Zai\\Api', $e->getMessage(), "The refusal must name the laundered reference ({$label}).");
            }
        }

        // The unclosed-group row launders through the PREFIX COMPOSITION
        // (the post-tag member composes with 'Foo\' and stops looking
        // family) — pin that the bare member is what the walk must
        // report: the same member WITHOUT the tag boundary is composed
        // and refused today, so the boundary is the only variable.
        $detector = wp_connectors_shared_family_references(
            "<?php\nuse Foo\\{Bar, ?>\n<?php\nuse Deicod\\WpConnectors\\Zai\\Api;\n"
        );
        $this->assertContains(
            array('name' => 'Deicod\\WpConnectors\\Zai\\Api', 'lower' => 'deicod\\wpconnectors\\zai\\api', 'kind' => 'use', 'offset' => 33, 'line' => 4),
            $detector,
            'Past a tag boundary the post-tag name run is judged bare, never composed with a prefix from before the boundary.'
        );

        /*
         * Clean direction: a shared source that legitimately CARRIES a
         * close tag (template-flavored tail, still lintable PHP) keeps
         * rewriting clean — the boundary resets nothing that was not
         * already dead. The family declaration and import stay ';'
         * -terminated: the REWRITER owns only that spelling of the
         * statement end (its patterns anchor on ';'), so a tag-
         * terminated family declaration is a postcondition refusal —
         * loud, and out of this round's detector scope. (The close tag
         * is spelled out in words in this comment: the two-byte
         * spelling would close PHP mode INSIDE a line comment.)
         */
        $clean = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\Clock;\nfinal class TagBoundClean\n{\n    public function stamp(): string\n    {\n        return Clock::class;\n    }\n}\n?>\n<p>rendered</p>\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($clean, 'OpenAiOauth', 'shared/src/TagBoundClean.php');
        $this->assertStringContainsString('namespace Deicod\\WpConnectors\\OpenAiOauth\\Shared;', $rewritten, 'A source carrying a close-tag tail rewrites its declaration normally.');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock;', $rewritten, 'A source carrying a close-tag tail rewrites its import normally.');
        $this->assertStringContainsString('<p>rendered</p>', $rewritten, 'The inline-HTML tail rides verbatim.');
    }

    /**
     * Fix-round pin (t31-r8-2): the relative operator resolves against
     * the file's declared namespace BEFORE the family predicates judge
     * it. T_NAME_RELATIVE carries its literal `namespace\` prefix
     * through the walk, so `namespace\WpConnectors\Shared\Clock` in a
     * file declaring `namespace Deicod;` — which PHP resolves to the
     * family name — never matched the vendor predicate: the reference
     * shipped un-rewritten, class-not-found at runtime, both gates
     * green (reproduced red: zero family references under the pre-fix
     * detector). The ADAPTATION carve-out is the pinned other half —
     * CODE positions only since t31-r11-1 (a relative under a
     * rewrite-owned tree adapts through the rewrite and reports
     * nothing); the use position lost the carve-out there (see
     * testARelativeUseImportIsRewrittenLikeAnyOtherFamilySpelling).
     */
    public function testARelativeOperatorResolvesAgainstTheDeclaredNamespaceBeforeTheFamilyPredicates(): void
    {
        // THE REPRO, both directions at the detector: matches when the
        // resolution lands in the family…
        $escape = "<?php\nnamespace Deicod;\n\$x = namespace\\WpConnectors\\Shared\\Clock::class;\n";
        $this->assertSame(
            array( array( 'name' => 'Deicod\\WpConnectors\\Shared\\Clock', 'lower' => 'deicod\\wpconnectors\\shared\\clock', 'kind' => 'relative', 'offset' => 29, 'line' => 3 ) ),
            wp_connectors_shared_family_references($escape),
            'A relative operator resolving into the family under a non-owned declaration is a family reference — resolved, not literal.'
        );
        // …and passes when it resolves outside it.
        $outside = "<?php\nnamespace Other\\Tree;\n\$x = namespace\\Foo\\Bar::class;\n";
        $this->assertSame(array(), wp_connectors_shared_family_references($outside), 'A relative resolving outside the family is no family reference.');

        // The escape refuses the build's postcondition loudly — the
        // rewriter owns no relative spelling, and the declaration it
        // resolves against (outside the rewrite's trees) is never
        // rewritten, so the reference dangles inside the plugin.
        try {
            WpConnectorsBuild::rewriteSharedNamespace($escape, 'OpenAiOauth', 'shared/src/Relative.php');
            $this->fail('A family-resolving relative under a non-owned declaration must refuse the rewrite, never ship un-rewritten.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('survived the rewrite', $e->getMessage());
            $this->assertStringContainsString('Relative.php', $e->getMessage());
            $this->assertStringContainsString('Deicod\\WpConnectors\\Shared\\Clock', $e->getMessage(), 'The refusal names the RESOLVED reference.');
            $this->assertStringContainsString('relative position', $e->getMessage(), 'The refusal names the relative kind — never "use", whose rewritable-position reading would wave it through the sweep.');
        }

        // The keyword's case and interrupted spellings resolve too
        // (the walk reassembles; the resolution judges the assembly).
        $interrupted = "<?php\nnamespace Deicod;\n\$x = NAMESPACE\\WpConnectors \\ Shared \\ Clock::class;\n";
        $found = wp_connectors_shared_family_references($interrupted);
        $this->assertCount(1, $found, 'A case-variant, separator-interrupted relative resolves like its contiguous twin.');
        $this->assertSame('Deicod\\WpConnectors\\Shared\\Clock', $found[0]['name']);
        $this->assertSame('relative', $found[0]['kind']);

        // Multi-block files: the SECOND declaration is the base a later
        // relative resolves against.
        $multi = "<?php\nnamespace Other;\n\$a = namespace\\Foo;\nnamespace Deicod;\n\$b = namespace\\WpConnectors\\Shared\\Clock::class;\n";
        $found = wp_connectors_shared_family_references($multi);
        $this->assertCount(1, $found, 'Only the relative under the second block\'s declaration resolves into the family.');
        $this->assertSame('Deicod\\WpConnectors\\Shared\\Clock', $found[0]['name']);

        // A relative USE spelling reports as 'relative', never 'use':
        // the kind's rewritable-position reading would wave it through
        // the sweep — and since t31-r11-1 the rewriter OWNS the use
        // position spelling (a survivor is a rewriter miss), so it can
        // never ride the whitelist either.
        $relative_use = "<?php\nnamespace Deicod;\nuse namespace\\WpConnectors\\Shared\\Clock;\n";
        $found = wp_connectors_shared_family_references($relative_use);
        $this->assertSame('relative', $found[0]['kind'], 'A relative use spelling must not wear the use kind.');

        /*
         * Verifier round t31-r8-10: a fully-qualified (parse-error)
         * namespace spelling is NOT a declaration — the walk first
         * classified `namespace \Junk;` as one, letting the invalid
         * spelling CORRUPT the file's in-effect namespace: the relative
         * after it resolved against the junk base, stopped being
         * family, and laundered past both gates (reproduced: the
         * hostile file rewrote clean where its control refused). Only
         * the two legal declaration shapes open one now; the invalid
         * spelling's name falls to a code position, where a FAMILY
         * spelling still refuses everywhere.
         */
        $corrupted = "<?php\nnamespace Deicod;\nnamespace \\Junk;\n\$x = namespace\\WpConnectors\\Shared\\Clock::class;\n";
        $found = wp_connectors_shared_family_references($corrupted);
        $this->assertCount(1, $found, 'The junk spelling corrupts nothing: the family-resolving relative still reports.');
        $this->assertSame(array( 'Deicod\\WpConnectors\\Shared\\Clock', 'relative' ), array( $found[0]['name'], $found[0]['kind'] ));
        try {
            WpConnectorsBuild::rewriteSharedNamespace($corrupted, 'ExampleConnector', 'shared/src/Corrupt.php');
            $this->fail('A relative laundering behind an invalid fully-qualified declaration must refuse the rewrite, exactly like its control.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('survived the rewrite', $e->getMessage());
        }

        // A fully-qualified FAMILY declaration (`namespace \Deicod\…`)
        // is a code-position name now — refused by both consumers, one
        // verdict, never a declaration that overwrites the base.
        $fq_family = "<?php\nnamespace Deicod;\nnamespace \\Deicod\\WpConnectors\\Shared;\ninterface FqFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($fq_family);
        $this->assertContains(array( 'name' => 'Deicod\\WpConnectors\\Shared', 'lower' => 'deicod\\wpconnectors\\shared', 'kind' => 'code', 'offset' => 34, 'line' => 3 ), $found, 'The invalid fully-qualified family spelling reports as a code-position name, never a declaration.');
        try {
            WpConnectorsBuild::rewriteSharedNamespace($fq_family, 'ExampleConnector', 'shared/src/Fq.php');
            $this->fail('A fully-qualified family declaration must refuse the rewrite.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('code position', $e->getMessage());
        }

        /*
         * The adaptation carve-out, CODE positions only (t31-r11-1):
         * under the SOURCE root the code-position relative reports
         * nothing and the rewrite passes its own postcondition — on the
         * rewritten bytes the relative resolves under the TARGET root,
         * which the consumer hands the detector, so it reports nothing
         * there either: the spelling rides verbatim and adapts by
         * construction. The use position keeps no carve-out — the
         * sibling test below pins its rewrite.
         */
        $adapting = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface FormsFixture\n{\n}\nfinal class Carrier\n{\n    public function self(): namespace\\FormsFixture\n    {\n        return new FormsFixture();\n    }\n}\n";
        $found = wp_connectors_shared_family_references($adapting);
        $this->assertCount(1, $found, 'Only the declaration reports; the adapting relative is legal in a code position.');
        $this->assertSame('declaration', $found[0]['kind']);
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($adapting, 'OpenAiOauth', 'shared/src/Carrier.php');
        $this->assertStringContainsString('namespace Deicod\\WpConnectors\\OpenAiOauth\\Shared;', $rewritten, 'The declaration is rewritten.');
        $this->assertStringContainsString('namespace\\FormsFixture', $rewritten, 'The relative spelling rides verbatim — it adapts, it is never rewritten.');
        $this->assertSame(
            array( array( 'name' => 'Deicod\\WpConnectors\\OpenAiOauth\\Shared', 'lower' => 'deicod\\wpconnectors\\openaioauth\\shared', 'kind' => 'declaration' ) ),
            array_map(static function (array $reference): array {
                return array( 'name' => $reference['name'], 'lower' => $reference['lower'], 'kind' => $reference['kind'] );
            }, wp_connectors_shared_family_references($rewritten, 'Deicod\\WpConnectors\\OpenAiOauth\\Shared')),
            'On the rewritten bytes the relative resolves under the target root and reports nothing — the declaration is the only family reference (the ownership half of the carve-out).'
        );
    }

    /**
     * Verifier-round pin (t31-r11-1): the rewriter owns the
     * `namespace\`-relative USE spelling. A relative use statement is a
     * parse error the engine never accepts (verified on 8.5.10), and
     * the rewriter's patterns did not match the spelling — it rode
     * verbatim through the rewrite, the postcondition (whose r8-2
     * carve-out waived relatives under a rewrite-owned declaration),
     * and the sweep, and the zip shipped the parse-error line at exit 0
     * (the round's finding). The rewriter resolves the operator exactly
     * as PHP does — the file's declared namespace plus the relative
     * tail — applies the family rewrite to the RESOLVED name, and emits
     * the fully-qualified rewritten import; relatives that cannot
     * resolve within the family refuse loudly; the detector owns the
     * spelling in use positions too (a survivor is a rewriter miss).
     */
    public function testARelativeUseImportIsRewrittenLikeAnyOtherFamilySpelling(): void
    {
        // THE REPRO, planted exactly as the round's finding planted it:
        // a shared-root file importing through the relative operator.
        // Pre-fix, the detector reported NOTHING for this file (the
        // rewrite-ownership carve-out) and the rewriter shipped the
        // parse-error line verbatim at exit 0.
        $planted = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors\\Shared\\Clock\\SystemClock;\ninterface RelUseFixture\n{\n}\n";
        $this->assertSame(
            array(
                array( 'name' => 'Deicod\\WpConnectors\\Shared', 'lower' => 'deicod\\wpconnectors\\shared', 'kind' => 'declaration', 'offset' => 16, 'line' => 2 ),
                array( 'name' => 'Deicod\\WpConnectors\\Shared\\WpConnectors\\Shared\\Clock\\SystemClock', 'lower' => 'deicod\\wpconnectors\\shared\\wpconnectors\\shared\\clock\\systemclock', 'kind' => 'relative', 'offset' => 48, 'line' => 3 ),
            ),
            wp_connectors_shared_family_references($planted),
            'A family-resolving relative USE statement reports under a rewrite-owned declaration too — the detector owns the spelling in use positions.'
        );

        // The rewrite direction: the file builds and ships REWRITTEN
        // WORKING code — the resolved name (declared namespace plus the
        // tail, the resolution PHP itself performs) mapped through the
        // family rewrite, spelled as a fully-qualified import.
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($planted, 'OpenAiOauth', 'shared/src/RelUseFixture.php');
        $this->assertStringContainsString('namespace Deicod\\WpConnectors\\OpenAiOauth\\Shared;', $rewritten, 'The declaration rewrites normally.');
        $this->assertStringContainsString(
            'use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\WpConnectors\\Shared\\Clock\\SystemClock;',
            $rewritten,
            'The relative import ships as the fully-qualified REWRITTEN resolution — the spelling is rewritten, never ridden.'
        );
        $this->assertStringNotContainsString('namespace\\', $rewritten, 'No relative spelling survives the rewrite.');

        // "Working": the rewritten file parses — the pre-fix output was
        // a parse error on this very line.
        $probe = self::distDir() . '/.rel-use-probe.php';
        file_put_contents($probe, $rewritten);
        try {
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($probe) . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, 'The rewritten import must parse: ' . implode("\n", $output));
        } finally {
            @unlink($probe);
        }

        // The aliased and function/const forms rewrite through the same
        // splice (only the name run's bytes are replaced).
        $aliased = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock\\SystemClock as Clock;\nuse function namespace\\Clock\\now;\nuse const namespace\\Clock\\TICK;\ninterface AliasFixture\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($aliased, 'OpenAiOauth', 'shared/src/AliasFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\SystemClock as Clock;', $rewritten);
        $this->assertStringContainsString('use function \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\now;', $rewritten);
        $this->assertStringContainsString('use const \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\TICK;', $rewritten);

        // A separator-INTERRUPTED relative (the walk reassembles; the
        // splice replaces the whole run) resolves like its contiguous
        // twin.
        $interrupted = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors \\\n Shared \\ Clock;\ninterface InterruptedRelFixture\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($interrupted, 'OpenAiOauth', 'shared/src/InterruptedRelFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\WpConnectors\\Shared\\Clock;', $rewritten, 'An interrupted relative spelling is replaced whole, reassembled like its contiguous twin.');

        // The other direction: a relative that ESCAPES the family —
        // under a foreign declaration, where the resolution lands
        // outside the vendor prefix — refuses loudly, never rides (in
        // the output it would silently re-resolve against the
        // REWRITTEN declaration, changing its meaning).
        $escaping = "<?php\nnamespace Other\\Tree;\nuse namespace\\Foo\\Bar;\ninterface EscapeFixture\n{\n}\n";
        try {
            WpConnectorsBuild::rewriteSharedNamespace($escaping, 'OpenAiOauth', 'shared/src/EscapeFixture.php');
            $this->fail('An escaping relative use import must refuse the rewrite, never ride verbatim.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside the shared-namespace family', $e->getMessage());
            $this->assertStringContainsString('Other\\Tree\\Foo\\Bar', $e->getMessage(), 'The refusal names the RESOLVED spelling.');
        }

        // A SIBLING resolution (family, but not under the rewrite's own
        // tree) refuses too — the rewriter owns no sibling spelling.
        $sibling = "<?php\nnamespace Deicod\\WpConnectors;\nuse namespace\\Zai\\Api;\ninterface SiblingFixture\n{\n}\n";
        try {
            WpConnectorsBuild::rewriteSharedNamespace($sibling, 'OpenAiOauth', 'shared/src/SiblingFixture.php');
            $this->fail('A sibling-resolving relative use import must refuse the rewrite.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SIBLING', $e->getMessage());
        }

        // UNRESOLVABLE: no declaration in effect where the relative
        // stands (and the multi-block resolution rule — the relative
        // resolves against the declaration IN EFFECT, not the file's
        // first).
        $unresolvable = "<?php\nuse namespace\\Foo\\Bar;\n";
        try {
            WpConnectorsBuild::rewriteSharedNamespace($unresolvable, 'OpenAiOauth', 'shared/src/NoDecl.php');
            $this->fail('A relative with no declaration in effect must refuse the rewrite.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot resolve', $e->getMessage());
        }
        $multi_block = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors\\Shared\\Clock;\nnamespace Other;\nuse namespace\\Baz;\n";
        try {
            WpConnectorsBuild::rewriteSharedNamespace($multi_block, 'OpenAiOauth', 'shared/src/MultiBlock.php');
            $this->fail('A relative resolving against a LATER block\'s foreign declaration must refuse, never resolve against the first block.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside the shared-namespace family', $e->getMessage(), 'The second block\'s relative resolves against the declaration in effect (Other), not the first block.');
        }

        // The group-use PREFIX shape — a parse-error spelling whose
        // members the rewrite owns no map for — refuses by name.
        $group_prefix = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors\\{Shared\\Clock};\ninterface GroupRelFixture\n{\n}\n";
        try {
            WpConnectorsBuild::rewriteSharedNamespace($group_prefix, 'OpenAiOauth', 'shared/src/GroupRelFixture.php');
            $this->fail('A relative group-use PREFIX must refuse the rewrite.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('group-use PREFIX', $e->getMessage());
        }

        /*
         * Verifier round t31-r11-9 (raised by both lenses): a relative
         * standing INSIDE a group body once spliced a LEADING-BACKSLASH
         * name into the member list — a spelling the grammar forbids —
         * and the postcondition waved it through (the absolute member
         * reports as a target-rooted 'use' reference), so the build
         * shipped one parse error in place of another at exit 0
         * (reproduced end-to-end through the real builder). Every such
         * member refuses loudly now.
         */
        $group_members = array(
            'plain member' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Other\\{namespace\\Clock};\ninterface GroupMemberFixture\n{\n}\n",
            'aliased member' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Other\\{namespace\\Clock as C};\ninterface GroupMemberFixture\n{\n}\n",
            'function member' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse function Other\\{namespace\\Clock\\now};\ninterface GroupMemberFixture\n{\n}\n",
            'family-prefixed group' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{namespace\\Shared\\Clock};\ninterface GroupMemberFixture\n{\n}\n",
            'second member of a list' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Other\\{Foo, namespace\\Clock};\ninterface GroupMemberFixture\n{\n}\n",
        );
        foreach ($group_members as $label => $source) {
            try {
                WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/GroupMemberFixture.php');
                $this->fail("A relative group-use MEMBER must refuse the rewrite, never splice an illegal fully-qualified member ({$label}).");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('group-use MEMBER', $e->getMessage(), "The refusal names the member shape ({$label}).");
            }
        }

        // CODE positions are untouched — they adapt by construction
        // (the r8-2 doctrine holds where its premise is true); the
        // sibling test above pins the full both-sides shape.
        $code_position = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class CodeRelCarrier\n{\n    public function self(): namespace\\FormsFixture\n    {\n        return new namespace\\FormsFixture();\n    }\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($code_position, 'OpenAiOauth', 'shared/src/CodeRelCarrier.php');
        $this->assertStringContainsString('namespace\\FormsFixture', $rewritten, 'A code-position relative rides verbatim — it adapts through the rewritten declaration.');

        /*
         * Verifier round t31-r11-10 (raised by both lenses): the
         * INTERRUPTED spellings — trivia between the keyword and the
         * name makes the lexer drop the fused T_NAME_RELATIVE token —
         * rode the step untouched and shipped parse-error bytes at
         * exit 0. The step owns the keyword now: every interrupted
         * spelling resolves across the trivia and rewrites to the same
         * fully-qualified import (and the shipped bytes parse).
         */
        $interrupted_spellings = array(
            'space after the keyword' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace \\Clock\\SystemClock;\ninterface InterruptedKeywordFixture\n{\n}\n",
            'block comment between' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace/* c */\\Http\\Url;\ninterface InterruptedKeywordFixture\n{\n}\n",
            'line comment between' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\n// c\n\\\\Clock;\ninterface InterruptedKeywordFixture\n{\n}\n",
            'doc comment between' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace /** d */ \\Clock;\ninterface InterruptedKeywordFixture\n{\n}\n",
        );
        foreach ($interrupted_spellings as $label => $source) {
            $rewritten = WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/InterruptedKeywordFixture.php');
            $this->assertStringContainsString(
                'use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\SystemClock;',
                str_replace(array('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Http\\Url;', 'use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock;'), 'use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\SystemClock;', $rewritten),
                "An interrupted relative spelling is owned: resolved across the trivia, rewritten whole ({$label})."
            );
            $this->assertStringNotContainsString('namespace \\', $rewritten, "No interrupted spelling survives ({$label}).");
        }
        // The shipped bytes parse — the pre-fix output was a parse
        // error on this very line.
        $probe = self::distDir() . '/.interrupted-rel-probe.php';
        file_put_contents($probe, WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace \\Clock\\SystemClock as Clock;\ninterface InterruptedKeywordFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/InterruptedKeywordFixture.php'));
        try {
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($probe) . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, 'The rewritten interrupted spelling must parse: ' . implode("\n", $output));
        } finally {
            @unlink($probe);
        }

        // The bare keyword with no name to resolve, and the interrupted
        // group shapes, refuse loudly — the step owns the keyword in
        // every position it can appear.
        $refusals = array(
            'bare keyword' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace;\ninterface BareKeywordFixture\n{\n}\n",
                'not a spelling PHP accepts',
            ),
            'interrupted prefix' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace \\WpConnectors\\{Shared\\Clock};\ninterface BareKeywordFixture\n{\n}\n",
                'group-use PREFIX',
            ),
            'interrupted member' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Other\\{namespace \\Clock};\ninterface BareKeywordFixture\n{\n}\n",
                'group-use MEMBER',
            ),
            'interrupted escape' => array(
                "<?php\nnamespace Other;\nuse namespace \\Foo;\ninterface BareKeywordFixture\n{\n}\n",
                'outside the shared-namespace family',
            ),
        );
        foreach ($refusals as $label => $case) {
            try {
                WpConnectorsBuild::rewriteSharedNamespace($case[0], 'OpenAiOauth', 'shared/src/BareKeywordFixture.php');
                $this->fail("An un-ownable keyword spelling must refuse the rewrite ({$label}).");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($case[1], $e->getMessage(), "The refusal names the shape ({$label}).");
            }
        }
    }

    /**
     * Verifier-round pin (t31-r11-8): octal escapes past \377 unescape
     * DEPRECATION-FREE. The engine wraps such escapes to the low byte
     * ("\400" is chr(0), "\777" is chr(255) — verified against the
     * runtime), and the unescaper mirrors it — but chr() with a
     * codepoint over 255 deprecates on PHP 8.5, and this unescaper runs
     * mid-gate (the string lens of both namespace gates): the notice
     * polluted the gate's output while every verdict stayed correct.
     * The explicit & 0xFF applies the engine's own wrap, silently.
     */
    public function testOctalEscapesPast377UnescapeDeprecationFree(): void
    {
        $deprecations = array();
        set_error_handler(static function (int $errno, string $message) use (&$deprecations): bool {
            if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
                $deprecations[] = $message;
            }

            return true;
        });
        try {
            $wrapped = wp_connectors_unescape_php_string_literal('"', '\\777\\400\\101');
            $in_range = wp_connectors_unescape_php_string_literal('"', '\\101\\102');
        } finally {
            restore_error_handler();
        }

        $this->assertSame("\xFF\x00\x41", $wrapped, 'The wrap is the engine\'s own: octal \777\400\101 mask to the low byte, semantics unchanged.');
        $this->assertSame('AB', $in_range, 'In-range octal is untouched by the mask.');
        $this->assertSame(array(), $deprecations, 'An octal escape past \377 must not raise the chr() deprecation mid-gate (chr() over 255 deprecates on the 8.5 runtime).');
    }

    /**
     * Fix-round pin (t31-r8-3): the text lens applies the FULL family
     * vocabulary. It tried only the source spelling and the consumer's
     * target pattern, so a docblock `@throws` naming a SIBLING under
     * the vendor prefix in a shared source launders exactly where the
     * same sibling in a code or string position refuses — and the dev
     * sweep rides the same detector, so nothing caught it anywhere
     * (reproduced red: the docblock-sibling file produced only its
     * declaration under the pre-fix detector). One vocabulary at every
     * lens: the bare vendor prefix and every sibling continuation under
     * it are text findings now, while the source and target spellings
     * keep their dedicated, fuller reports (not preempted by a bare-
     * stem match on the raw view of a double-backslash spelling).
     */
    public function testTheTextLensJudgesTheFullSiblingFamilyVocabulary(): void
    {
        $declaration = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n";
        $text_finding = static function (string $body) use ($declaration): array {
            $found = array();
            foreach (wp_connectors_shared_family_references($declaration . $body) as $reference) {
                if ('declaration' !== $reference['kind']) {
                    $found[] = array( $reference['name'], $reference['kind'] );
                }
            }

            return $found;
        };

        // THE REPRO: a docblock naming a sibling refuses.
        $this->assertSame(
            array( array( 'Deicod\\WpConnectors\\Zai', 'comment' ) ),
            $text_finding("/**\n * @throws \\Deicod\\WpConnectors\\Zai\\ApiClient\n */\ninterface DocSiblingFixture\n{\n}\n"),
            'A docblock naming a sibling under the vendor prefix is a finding — the same vocabulary the code and string positions judge.'
        );

        // The rest of the family shapes in text: the bare vendor prefix,
        // a SharedStorage-prefixed sibling, inline HTML, and a group-use
        // brace head naming a sibling member.
        $this->assertSame(array( array( 'Deicod\\WpConnectors', 'comment' ) ), $text_finding("/** @package Deicod\\WpConnectors */\ninterface BareFixture\n{\n}\n"));
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\SharedStorage', 'comment' ) ), $text_finding("/** @see Deicod\\WpConnectors\\SharedStorage\\Widget */\ninterface SharedStorageFixture\n{\n}\n"));
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\Zai', 'inline-html' ) ), $text_finding("?>\n<b>Deicod\\WpConnectors\\Zai\\Api</b>\n<?php\ninterface HtmlFixture\n{\n}\n"));
        $this->assertSame(array( array( 'Deicod\\WpConnectors', 'comment' ) ), $text_finding("/** Example: use Deicod\\WpConnectors\\{Zai\\Api}; */\ninterface BraceHeadFixture\n{\n}\n"));

        // Non-family noise stays quiet: a sibling-local name without the
        // vendor prefix, the prefix with a name character glued on, and
        // a longer name merely CONTAINING the stem.
        $this->assertSame(array(), $text_finding("/** SharedStorage notes; WpConnectors alone; MyDeicod\\WpConnectors\\Zai */\ninterface NoiseFixture\n{\n}\n"));

        // The dedicated reports are not preempted: a source spelling
        // (raw or double-backslash) reports the SOURCE namespace (the
        // generator's match ends at its leaf, as it always has), and a
        // target spelling the target namespace — never a bare stem.
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\Shared', 'comment' ) ), $text_finding("/** @see Deicod\\WpConnectors\\Shared\\Clock */\ninterface SourceReportFixture\n{\n}\n"));
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\Shared', 'comment' ) ), $text_finding("/** @see Deicod\\\\WpConnectors\\\\Shared\\\\Clock */\ninterface SourceRawReportFixture\n{\n}\n"));

        /*
         * Verifier round t31-r8-9: the sibling exclusion first covered
         * the target's whole SUFFIX segment, but the dedicated target
         * pattern owns only …\<Suffix>\Shared — so a docblock naming
         * anything ELSE under the building plugin's own segment
         * (`…\ExampleConnector\OAuth`) reported ZERO findings under the
         * build's postcondition (target given) while the sweep refused
         * the same file, and the dangling docblock shipped verbatim at
         * exit 0 (end-to-end reproduced) — the r7-8 verdict-drift
         * class, one segment inside the target tree. The exclusion is
         * the FULL below-vendor tails now: both gates give ONE verdict
         * on the target-SEGMENT sibling, the refusal direction.
         */
        $drift = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n/** @see \\Deicod\\WpConnectors\\ExampleConnector\\OAuth::start() */\ninterface DriftFixture\n{\n}\n";
        $target_given = array();
        foreach (wp_connectors_shared_family_references($drift, 'Deicod\\WpConnectors\\ExampleConnector\\Shared') as $reference) {
            if ('declaration' !== $reference['kind']) {
                $target_given[] = array( $reference['name'], $reference['kind'] );
            }
        }
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\ExampleConnector', 'comment' ) ), $target_given, 'With the target given, a target-SEGMENT sibling reports exactly what the sweep (no target) reports on the same file.');
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\ExampleConnector', 'comment' ) ), $text_finding("/** @see \\Deicod\\WpConnectors\\ExampleConnector\\OAuth::start() */\ninterface DriftSweepFixture\n{\n}\n"), 'The sweep twin (no target) gives the same verdict.');
        try {
            WpConnectorsBuild::rewriteSharedNamespace($drift, 'ExampleConnector', 'shared/src/Drift.php');
            $this->fail('A docblock naming a sibling under the building plugin\'s own segment must refuse the rewrite — never ship the dangling spelling the sweep refuses.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Drift.php', $e->getMessage());
            $this->assertStringContainsString('Deicod\\WpConnectors\\ExampleConnector', $e->getMessage());
        }
        $target_found = array();
        foreach (wp_connectors_shared_family_references($declaration . "/** @throws Deicod\\\\WpConnectors\\\\OpenAiOauth\\\\Shared\\\\Clock */\ninterface TargetReportFixture\n{\n}\n", 'Deicod\\WpConnectors\\OpenAiOauth\\Shared') as $reference) {
            if ('declaration' !== $reference['kind']) {
                $target_found[] = array( $reference['name'], $reference['kind'] );
            }
        }
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\OpenAiOauth\\Shared', 'comment' ) ), $target_found, 'A double-backslash target spelling keeps its dedicated target report, not a sibling stem.');

        // End to end through the build's postcondition: the docblock
        // sibling REFUSES the rewrite, naming the sibling.
        try {
            WpConnectorsBuild::rewriteSharedNamespace($declaration . "/**\n * @throws \\Deicod\\WpConnectors\\Zai\\ApiClient\n */\ninterface DocSiblingStore\n{\n}\n", 'OpenAiOauth', 'shared/src/DocSibling.php');
            $this->fail('A docblock naming a sibling must refuse the rewrite — the embedded copy would ship a reference to a namespace that does not exist inside the plugin.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('survived the rewrite', $e->getMessage());
            $this->assertStringContainsString('DocSibling.php', $e->getMessage());
            $this->assertStringContainsString('Deicod\\WpConnectors\\Zai', $e->getMessage(), 'The refusal names the sibling.');
            $this->assertStringContainsString('comment position', $e->getMessage(), 'The refusal names the text position.');
        }
    }

    /**
     * Fix-round pin (t31-r4-3): ZipArchive::close()'s false return was
     * ignored — a failed finalization took no catch path while OVERWRITE
     * had already destroyed the previous good zip, so the run continued
     * to hash_file() on a zip that was never written, wrote a
     * blank-checksum sidecar, and exited 0 (the t31-r3-16 invariant
     * failing at the seam its re-open clause anticipated). Finalization
     * is its own checked seam now, driven here with a REAL failed
     * close(): on this runtime an addFile()'d source that is unreadable
     * at read time makes close() return false (libzip defers the read —
     * empirically confirmed), which is the deterministic external
     * spelling of the failure. The clean direction finalizes a real
     * archive through the same seam. (No external input reaches a
     * failed close() through buildPlugin() itself — every staged file is
     * written and chmod 0644 by the same synchronous call — so the seam
     * is pinned directly, the reflection-driven-seam idiom.)
     */
    public function testAFailedZipFinalizationRefusesTheBuild(): void
    {
        $finalize = new ReflectionMethod(WpConnectorsBuild::class, 'closeArchiveOrThrow');

        // Clean direction: a real archive with one entry finalizes.
        $good = tempnam(sys_get_temp_dir(), 'wpct-zip-good-');
        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($good, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $zip->addFromString('entry.txt', 'data');
            $finalize->invoke(null, $zip, 'good.zip');
            $this->assertFileExists($good, 'A finalized archive lands on disk.');
        } finally {
            @unlink($good);
        }

        // Failing direction: the staged source is unreadable at close()
        // time, so finalization fails for real. libzip warns through the
        // engine on the failed read; the pin collects the warning (it is
        // the evidence the failure is a real close() failure, not a stub)
        // instead of letting PHPUnit's handler turn it into a test error.
        $staged = tempnam(sys_get_temp_dir(), 'wpct-zip-staged-');
        $bad = tempnam(sys_get_temp_dir(), 'wpct-zip-bad-');
        try {
            file_put_contents($staged, 'staged content');
            chmod($staged, 0000);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($bad, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $this->assertTrue($zip->addFile($staged, 'staged.txt'));

            $warnings = array();
            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                $warnings[] = $errstr;

                return true;
            });
            $refused = null;
            try {
                $finalize->invoke(null, $zip, 'bad.zip');
            } catch (RuntimeException $e) {
                $refused = $e->getMessage();
            } finally {
                restore_error_handler();
            }

            $this->assertNotNull($refused, 'A failed finalization must refuse the build, never fall through to a blank-checksum sidecar and exit 0.');
            $this->assertStringContainsString('cannot finalize', $refused);
            $this->assertStringContainsString('bad.zip', $refused);
            $this->assertNotSame(array(), $warnings, 'The pin must drive a REAL close() failure (libzip\'s own read warning is the evidence), not a stubbed one.');
        } finally {
            @chmod($staged, 0644);
            @unlink($staged);
            @unlink($bad);
        }
    }

    /**
     * Fix-round pin (t31-r7-3): writeNormalized()'s file_put_contents()
     * return was ignored — a short write (an ENOSPC-style prefix write:
     * the write layer accepts part of the buffer, then reports failure)
     * staged a truncated PHP file that zip close() happily packed and
     * published at exit 0, and the t31-r5-S "verified whole at its
     * staging path" claim was FALSE for generated members.
     *
     * No external input reaches a failed generated write through
     * buildPlugin() itself — every staged path is created fresh inside
     * the run, before any input can block it — so the seam is pinned
     * directly (the reflection-driven-seam idiom, the t31-r4-3
     * closeArchiveOrThrow precedent), with the artifact-set contract
     * asserted around it. Both runtime spellings drive a REAL
     * file_put_contents failure, not a stub: a stream wrapper whose
     * stream_write accepts half the buffer makes PHP's own write loop
     * fall short (its "Only X of Y bytes written" diagnostic is the
     * evidence, collected the way the close() pin collects libzip's
     * warning), and a directory at the target is the plain false.
     */
    public function testAShortOrFailedGeneratedWriteRefusesTheBuildAndKeepsTheArtifactSet(): void
    {
        $write = new ReflectionMethod(WpConnectorsBuild::class, 'writeNormalized');

        /*
         * Seed a previous GOOD artifact set: the refusal must leave it
         * byte-untouched (the t31-r5-S publication contract — every
         * failure this seam can construct happens at the staging path,
         * before the archive opens).
         */
        $scratch = self::distDir() . '/.write-normalized-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $seedZip = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($seedZip);
            $zipBefore = (string) file_get_contents($seedZip);
            $sidecarBefore = (string) file_get_contents($seedZip . '.sha256');
            $manifestBefore = (string) file_get_contents($scratch . '/dist/checksums.txt');

            // (a) The SHORT-WRITE spelling, through the real write layer:
            // the wrapper's stream_write accepts half of every chunk, so
            // PHP's write loop falls short and file_put_contents fails.
            // Fix-round pin (t31-r9-8): the scratch URL is two segments
            // deep ('…://staged/short.php') so the seam's @mkdir(
            // dirname($to)) stays INSIDE the URL scheme — the old
            // one-segment '…://short' spelled a scheme-only dirname
            // ('wpctshortwrite:') that PHP treats as a plain relative
            // path, and the mkdir created a literal directory of that
            // name in the process CWD (the repo root; two leaked dirs
            // verified present, one stale from an earlier spelling).
            $payload = str_repeat('x', 1000);
            $this->assertTrue(stream_wrapper_register('wpctshortwrite', WpctShortWriteStream::class), 'The short-write wrapper must register.');
            try {
                $warnings = array();
                set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                    $warnings[] = $errstr;

                    return true;
                });
                $refused = null;
                try {
                    $write->invoke(null, $payload, 'wpctshortwrite://staged/short.php');
                } catch (RuntimeException $e) {
                    $refused = $e->getMessage();
                } finally {
                    restore_error_handler();
                }

                $this->assertNotNull($refused, 'A short write must refuse the build, never stage a truncated file the zip would happily pack.');
                $this->assertStringContainsString('wpctshortwrite://staged/short.php', $refused, 'The refusal must name the file.');
                $this->assertStringContainsString('1000 bytes expected', $refused, 'The refusal must name the expected byte count.');
                $this->assertNotSame(array(), array_filter($warnings, static function (string $w): bool {
                    return false !== strpos($w, 'bytes written');
                }), 'The pin must drive a REAL short write (PHP\'s own "Only X of Y bytes written" diagnostic is the evidence), not a stubbed one.');
                $this->assertFileDoesNotExist(
                    getcwd() . '/wpctshortwrite:',
                    'The stream-URL scratch must never leak a literal scheme-named directory into the working directory.'
                );
            } finally {
                stream_wrapper_unregister('wpctshortwrite');
            }

            // (b) The FALSE spelling: a directory at the target refuses
            // the same way, naming the file.
            $blocked = $scratch . '/dist/blocked-target';
            mkdir($blocked, 0755, true);
            $refused = null;
            try {
                $write->invoke(null, 'generated content', $blocked);
            } catch (RuntimeException $e) {
                $refused = $e->getMessage();
            }
            $this->assertNotNull($refused, 'A refused write must refuse the build loudly.');
            $this->assertStringContainsString($blocked, $refused);
            $this->assertStringContainsString('17 bytes expected', $refused);

            // Clean direction through the same seam: a real write to a
            // real staging path lands whole (and its staged size is
            // verified by the seam itself).
            $good = $scratch . '/dist/good-target.php';
            $write->invoke(null, $payload, $good);
            $this->assertSame($payload, (string) file_get_contents($good));
            $this->assertSame(1000, filesize($good));

            // The artifact-set contract: both refusals happened at the
            // seam (staging-path-shaped), and the previous good set is
            // byte-untouched.
            $this->assertSame($zipBefore, (string) file_get_contents($seedZip));
            $this->assertSame($sidecarBefore, (string) file_get_contents($seedZip . '.sha256'));
            $this->assertSame($manifestBefore, (string) file_get_contents($scratch . '/dist/checksums.txt'));
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r4-7), the build channel: a symlinked
     * directory in shared/src REFUSES the embed build loudly — the old
     * silent skip shipped a library whose linked class loads in
     * development (the dev autoloader resolves link paths) and fatals
     * in the shipped plugin, with the sweep equally blind (same
     * collector). The refusal rides the staging try: no zip, no staging
     * residue, the previous good artifact set untouched.
     */
    public function testASymlinkInTheSharedSourceTreeRefusesTheEmbedBuild(): void
    {
        $scratch = self::distDir() . '/.embed-symlink';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/shared/src/Linked', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
        file_put_contents($scratch . '/shared/src/Linked/LinkedSource.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Linked;\ninterface LinkedSource {}\n");
        symlink($scratch . '/shared/src/Linked', $scratch . '/shared/src/LinkedDir');

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A symlinked directory inside shared/src must refuse the embed build, never ship a library that silently drops it.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('symlink', $e->getMessage());
                $this->assertStringContainsString('LinkedDir', $e->getMessage());
            }
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The refused build must leave no zip behind.');
            $this->assertNoStageTree($scratch . '/dist', 'example-connector');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r4-8), the partial-rebuild shape: the CLI wiped
     * dist/checksums.txt before building, so a --slug rebuild dropped
     * every OTHER plugin's manifest entry (reproduced) — the manifest
     * must be per-run-atomic: a run updates only the entries of the
     * plugin(s) it built, and every other entry survives byte-for-byte.
     */
    public function testASlugRebuildKeepsEveryOtherPluginsManifestEntry(): void
    {
        $repo = $this->makeBuildCliRepo(array( 'alpha-demo' => true, 'beta-demo' => true ));

        try {
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, "The full run must build cleanly:\n" . implode("\n", $output));

            $manifestPath = $repo . '/dist/checksums.txt';
            $fullManifest = (string) file_get_contents($manifestPath);
            $this->assertStringContainsString('connectors-alpha-demo-1.0.0.zip', $fullManifest);
            $this->assertStringContainsString('connectors-beta-demo-1.0.0.zip', $fullManifest);

            // The partial rebuild: only alpha's entry may change; beta's
            // entry (and sidecar, and zip) must survive untouched.
            $betaZip = $repo . '/dist/connectors-beta-demo-1.0.0.zip';
            $betaSidecarBefore = (string) file_get_contents($betaZip . '.sha256');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=alpha-demo 2>&1', $output, $exit);
            $this->assertSame(0, $exit);

            $rebuiltManifest = (string) file_get_contents($manifestPath);
            $this->assertStringContainsString('connectors-alpha-demo-1.0.0.zip', $rebuiltManifest, 'The rebuilt plugin keeps its own entry.');
            $this->assertStringContainsString('connectors-beta-demo-1.0.0.zip', $rebuiltManifest, 'The unbuilt plugin keeps its entry (the old pre-run wipe dropped it).');
            $this->assertSame($betaSidecarBefore, (string) file_get_contents($betaZip . '.sha256'), 'The unbuilt plugin\'s sidecar survives byte-for-byte.');
            $this->assertFileExists($betaZip, 'The unbuilt plugin\'s zip survives.');
        } finally {
            WpHarness::rrmdir($repo);
        }
    }

    /**
     * Fix-round pin (t31-r4-8), the failing-rebuild shape: the CLI wipe
     * ran BEFORE the loop, so a failing rebuild left the manifest gone
     * with every sidecar orphaned (reproduced) — t31-r3-16's
     * last-good-artifact invariant was false at the CLI seam. A failed
     * run must leave the previous manifest and sidecars exactly as the
     * last successful run wrote them.
     */
    public function testAFailingRebuildLeavesTheManifestAndSidecarsIntact(): void
    {
        $repo = $this->makeBuildCliRepo(array( 'alpha-demo' => true, 'beta-demo' => true ));

        try {
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' 2>&1', $output, $exit);
            $this->assertSame(0, $exit, "The full run must build cleanly:\n" . implode("\n", $output));

            $manifestPath = $repo . '/dist/checksums.txt';
            $manifestBefore = (string) file_get_contents($manifestPath);
            $alphaZip = $repo . '/dist/connectors-alpha-demo-1.0.0.zip';
            $betaZip = $repo . '/dist/connectors-beta-demo-1.0.0.zip';
            $alphaSidecarBefore = (string) file_get_contents($alphaZip . '.sha256');
            $betaSidecarBefore = (string) file_get_contents($betaZip . '.sha256');

            // Break beta AFTER the successful run: its main file loses
            // the Plugin Name header, so the rebuild refuses it.
            file_put_contents($repo . '/connectors/beta-demo/beta-demo.php', "<?php\necho 'header lost';\n");

            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' 2>&1', $output, $exit);
            $this->assertSame(1, $exit, 'The failing rebuild must exit non-zero.');
            $this->assertStringContainsString('no main plugin file', implode("\n", $output));

            // The last good release survives the failed rebuild whole:
            // manifest byte-identical (alpha rebuilds deterministically to
            // the same checksum before beta refuses), zips and sidecars
            // all present and unchanged.
            $this->assertSame($manifestBefore, (string) file_get_contents($manifestPath), 'A failed rebuild must leave the manifest exactly as the last successful run wrote it.');
            $this->assertFileExists($alphaZip);
            $this->assertFileExists($betaZip);
            $this->assertSame($alphaSidecarBefore, (string) file_get_contents($alphaZip . '.sha256'));
            $this->assertSame($betaSidecarBefore, (string) file_get_contents($betaZip . '.sha256'));
        } finally {
            WpHarness::rrmdir($repo);
        }
    }

    /**
     * Fix-round pin (t31-r4-9), the classify half: the self-containment
     * walker's exact-case extension check skipped a '.PHP'-spelled file
     * in a plugin tree — while the zip ships it (collectFiles has no
     * extension filter), so an escaping include inside one escaped every
     * self-containment gate (build and inspect alike). The walker rides
     * the ONE case-insensitive owner now: the violation fires.
     */
    public function testTheSelfContainmentWalkerJudgesUpperCaseSpelledPhpSources(): void
    {
        $tempPlugin = self::distDir() . '/.phpcase-containment/upper-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        $head = "Plugin Name:       upper-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       upper-demo\nAuthor:            x\n";
        file_put_contents($tempPlugin . '/upper-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'UPPER_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n");
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\UpperDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);
        // The escaping include rides the '.PHP' spelling the exact-case
        // check used to skip.
        file_put_contents($tempPlugin . '/escape.PHP', "<?php\nrequire __DIR__ . '/../../outside/bootstrap.php';\n");

        try {
            $violations = wp_connectors_self_containment_violations($tempPlugin);
            $this->assertNotSame(array(), $violations, 'A .PHP-spelled source must be scanned, never skipped by the extension judgment.');
            $this->assertStringContainsString('escape.PHP', implode("\n", $violations));
            $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Fix-round pin (t31-r4-14): the rewrite seams' (string) casts turned
     * a PCRE abort's null return into '' — an EMPTY file written into
     * the zip, fail-open against the glm36-8 abort-as-reject doctrine
     * (trigger unproven on these linear patterns; the shape was wrong).
     * Every rewrite seam rides replaceOrThrow() now: a null result
     * refuses the build loudly, named with the step and the file. The
     * helper is driven directly (the deterministic trigger does not
     * exist — a forced abort needs inputs these anchored, linear
     * patterns do not accept); the seams' shape is the fix.
     */
    public function testAnAbortingReplacementRefusesTheRewriteNeverCasts(): void
    {
        $guard = new ReflectionMethod(WpConnectorsBuild::class, 'replaceOrThrow');

        // The abort spelling: preg_replace()'s null.
        try {
            $guard->invoke(null, null, 'namespace declaration rewrite', 'shared/src/Http/Url.php');
            $this->fail('A null preg_replace result must refuse the rewrite, never cast to an empty file.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('aborted (PCRE)', $e->getMessage());
            $this->assertStringContainsString('namespace declaration rewrite', $e->getMessage());
            $this->assertStringContainsString('shared/src/Http/Url.php', $e->getMessage());
        }

        // The healthy spelling passes through byte-identical.
        $this->assertSame(
            'rewritten bytes',
            $guard->invoke(null, 'rewritten bytes', 'use-statement rewrite', 'shared/src/Http/Url.php')
        );
    }

    /**
     * Verifier-round pin (t31-r4-15), restated for the t31-r5-S
     * publication seam: a blocked artifact path must never exit 0 with a
     * half-described artifact set — and under the staged-then-landed
     * shape the stronger contract holds by construction: the refusal
     * lands NOTHING and leaves any previous good set byte-untouched.
     * (The r4-15 shape had already overwritten the zip when the sidecar
     * write failed, so its cleanup REMOVED the artifact set; producing
     * every byte at a temp path first makes that whole compensation
     * class unconstructible — the landing pre-flight refuses a
     * non-file destination before the first rename.)
     */
    public function testAFailingPublicationLandsNothingAndKeepsThePreviousGoodSet(): void
    {
        $scratch = self::distDir() . '/.publish-check';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        try {
            // (a) The sidecar landing path blocked: the pre-flight
            // refuses before anything lands — no zip, no manifest, the
            // blocking directory untouched.
            mkdir($scratch . '/dist/connectors-example-connector-0.1.0.zip.sha256');
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A blocked sidecar landing must refuse the build, never exit 0 with a half-described artifact set.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a regular file', $e->getMessage());
                $this->assertStringContainsString('connectors-example-connector-0.1.0.zip.sha256', $e->getMessage());
            }
            $this->assertFileDoesNotExist($scratch . '/dist/connectors-example-connector-0.1.0.zip', 'Nothing lands when the pre-flight refuses.');
            $this->assertFileDoesNotExist($scratch . '/dist/checksums.txt', 'No manifest may land beside a refused landing.');
            rmdir($scratch . '/dist/connectors-example-connector-0.1.0.zip.sha256');

            // Control: the same inputs build cleanly once the blocker is
            // gone (nothing the failed run left behind collides).
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($zipPath);
            $this->assertFileExists($zipPath . '.sha256');

            // (b) The previous good set survives a refused rebuild
            // byte-for-byte: block the manifest LANDING (a directory at
            // checksums.txt cannot coexist with a prior manifest file,
            // so the prior set is snapshotted first and restored after
            // the blocker is planted).
            $manifestPath = $scratch . '/dist/checksums.txt';
            $zipBefore = (string) file_get_contents($zipPath);
            $sidecarBefore = (string) file_get_contents($zipPath . '.sha256');
            $manifestBefore = (string) file_get_contents($manifestPath);
            unlink($manifestPath);
            mkdir($manifestPath, 0755, true);
            try {
                WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $this->fail('A blocked manifest landing must refuse the build too.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a regular file', $e->getMessage());
                $this->assertStringContainsString('checksums.txt', $e->getMessage());
            }
            $this->assertSame($zipBefore, (string) file_get_contents($zipPath), 'The previous good zip survives a refused landing byte-for-byte.');
            $this->assertSame($sidecarBefore, (string) file_get_contents($zipPath . '.sha256'), 'The previous good sidecar survives a refused landing byte-for-byte.');
            rmdir($manifestPath);
            file_put_contents($manifestPath, $manifestBefore);

            // Recovery: the same inputs rebuild cleanly once the blocker
            // is gone (the refused landing left nothing behind).
            $rebuilt = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($rebuilt);
            $this->assertFileExists($rebuilt . '.sha256');
            $this->assertStringContainsString(basename($rebuilt), (string) file_get_contents($manifestPath));
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Verifier-round pin (t31-r4-18): the artifact inspector's
     * post-extraction syntax loop and the repo's lint gate both used the
     * exact-case extension check — a parse-broken '.PHP' entry shipped
     * into a zip at exit 0 and the inspector ACCEPTED it with zero
     * violations (reproduced), the release gate weaker than the build's
     * own classify gate for the spelling. Both consumers ride the ONE
     * case-insensitive owner now.
     */
    public function testTheInspectorSyntaxChecksUpperCaseSpelledPhpEntries(): void
    {
        $zipPath = self::distDir() . '/connectors-phplint-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $head = "Plugin Name:       phplint-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       phplint-demo\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'PHPLINT_DEMO_VERSION', '1.0.0' );\n";
        $zip->addFromString('phplint-demo/phplint-demo.php', $main);
        // The parse error rides the '.PHP' spelling the exact-case check skipped.
        $zip->addFromString('phplint-demo/src/Broken.PHP', "<?php\nnamespace Deicod\\WpConnectors\\PhplintDemo\\;\nclass Broken {\n");
        $zip->close();

        try {
            $violations = wp_connectors_inspect_artifact($zipPath, self::distDir() . '/.inspect-phplint');
            $this->assertNotSame(array(), $violations, 'A parse-broken .PHP entry must fail inspection, never ride the extension case past the syntax loop.');
            $this->assertStringContainsString('failed php -l', implode("\n", $violations));
            $this->assertStringContainsString('Broken.PHP', implode("\n", $violations));
        } finally {
            @unlink($zipPath);
            @unlink($zipPath . '.sha256');
        }
    }

    /**
     * The legal namespace-suffix shapes the rewrite soundness sweep rides:
     * the ordinary derivation, the digit-initial underscored derivation
     * (t31-r3-5), and an explicit all-caps segment — each a validated,
     * non-empty namespace segment, so the survivor scan's soundness
     * argument (the target never contains the source spelling) holds for
     * each by construction.
     *
     * @return list<string>
     */
    private function fixtureSuffixes(): array
    {
        return array(
            WpConnectorsBuild::namespaceSuffixFromSlug('example-connector'),
            WpConnectorsBuild::namespaceSuffixFromSlug('3cx-oauth'),
            'OPENAIOAUTH',
        );
    }

    /**
     * Fix-round pin (t31-r4 K1), end-to-end through the build: a shared
     * source carrying a namespace spelling the rewriter does not know
     * must REFUSE the build loudly with the file named — never package a
     * zip whose embedded copy imports a namespace that no longer exists
     * inside the plugin. Both round-4 shapes are planted in a scratch
     * shared/src (the group-use member, t31-r4-4; the multiline string
     * reference, t31-r4-5): pre-fix, each built to exit 0 with a broken
     * import inside the zip (reproduced through this exact scaffold).
     */
    public function testASharedSourceWithAnUnrewritableSpellingRefusesTheBuild(): void
    {
        $scratch = self::distDir() . '/.rewrite-refuse';
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        $hostile_sources = array(
            'nested group' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Shared\\{Clock}};\nclass NestedGroupHostile\n{\n}\n",
            'multiline reference' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass MultilineHostile\n{\n    public function name(): string\n    {\n        return 'Deicod\\WpConnectors\\\nShared\\Clock';\n    }\n}\n",
            'sibling import (t31-r7-2)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Zai\\ApiClient;\nclass SiblingHostile\n{\n}\n",
            'comment-interrupted use (t31-r7-1)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod/* pick one */\\WpConnectors\\Shared\\Clock;\nclass InterruptedHostile\n{\n}\n",
            'double-backslash class-string (t31-r7-4)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass ClassStringHostile\n{\n    public function name(): string\n    {\n        return 'Deicod\\\\WpConnectors\\\\Shared\\\\Clock';\n    }\n}\n",
            'target-spelled references — the drift shape (t31-r7-8)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n/**\n * @throws \\Deicod\\WpConnectors\\ExampleConnector\\Shared\\Clock\\Ghost\n */\ninterface TargetHostile\n{\n    public function name(): string;\n}\nfinal class TargetHostileCarrier\n{\n    public function name(): string\n    {\n        return \\class_exists(\\Deicod\\WpConnectors\\ExampleConnector\\Shared\\Clock\\Ghost::class)\n            ? 'Deicod\\\\WpConnectors\\\\ExampleConnector\\\\Shared\\\\Clock\\\\Ghost'\n            : '';\n    }\n}\n",
        );

        try {
            // First, the shape the rewriter now OWNS (t31-r4-4): a
            // group-use member builds, and the embedded copy carries the
            // REWRITTEN member — the round's defect was this exact source
            // building to exit 0 with the import still pointing at the
            // source namespace.
            $suffix = WpConnectorsBuild::namespaceSuffixFromSlug('example-connector');
            file_put_contents($scratch . '/shared/src/GroupUse.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Shared\\Clock\\ClockInterface};\nclass GroupUseHostile\n{\n}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $embedded = (string) $zip->getFromName('example-connector/src/Shared/GroupUse.php');
            $zip->close();
            $this->assertStringContainsString('use Deicod\\WpConnectors\\{' . $suffix . '\\Shared\\Clock\\ClockInterface};', $embedded, 'A group-use member must ship REWRITTEN, never pointing at the source namespace.');
            unlink($scratch . '/shared/src/GroupUse.php');

            foreach ($hostile_sources as $label => $source) {
                file_put_contents($scratch . '/shared/src/Hostile.php', $source);
                try {
                    WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                    $this->fail("A shared source carrying an unrewritable namespace spelling ({$label}) must refuse the build, never package it.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('survived the rewrite', $e->getMessage(), "The refusal must say what happened ({$label}).");
                    $this->assertStringContainsString('shared/src/Hostile.php', $e->getMessage(), "The refusal must name the offending file ({$label}).");
                }
                // The refusal fires during staging, BEFORE the archive
                // opens — the previous good zip survives it byte-for-byte
                // (the t31-r3-16 artifact-preservation contract).
                $this->assertFileExists($zipPath, "A pre-open refusal must leave the previous good zip ({$label}).");
                $this->assertNoStageTree($scratch . '/dist', 'example-connector', "The staging tree must tear down on every refusal ({$label}).");
            }

            // Control: remove the hostile source and the same inputs
            // build through the total scan cleanly.
            unlink($scratch . '/shared/src/Hostile.php');
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($zipPath);
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /*
     * Manifest regeneration prunes (t31-r12-7): the header contract
     * says "dist/checksums.txt is regenerated" — a connector whose zip
     * is deleted out-of-band must not leave a stale line behind.
     */

    public function testManifestRegenerationDropsEntriesWhoseArtifactVanished()
    {
        $scratch = self::distDir() . '/.prune-manifest-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        try {
            $plugins = array();
            foreach (array('alpha-demo', 'beta-demo') as $slug) {
                $plugins[$slug] = $this->makeMinimalPlugin($scratch . '/plugins', $slug);
            }

            $zipAlpha = WpConnectorsBuild::buildPlugin($plugins['alpha-demo'], $scratch . '/dist');
            $zipBeta = WpConnectorsBuild::buildPlugin($plugins['beta-demo'], $scratch . '/dist');
            $manifestPath = $scratch . '/dist/checksums.txt';
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($manifestPath)), static function ($line): bool {
                return '' !== $line;
            }));
            $this->assertCount(2, $lines, 'Both builds record their entries: ' . implode(' | ', $lines));

            // The remove-a-connector scenario: its zip vanishes out of
            // band, then ANY later build regenerates the manifest.
            unlink($zipBeta);
            WpConnectorsBuild::buildPlugin($plugins['alpha-demo'], $scratch . '/dist');

            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($manifestPath)), static function ($line): bool {
                return '' !== $line;
            }));
            $this->assertCount(1, $lines, 'The vanished connector\'s entry is dropped by regeneration: ' . implode(' | ', $lines));
            $this->assertStringContainsString(basename($zipAlpha), $lines[0]);

            // Every surviving line verifies: the artifact it names
            // exists beside the manifest and hashes to the recorded
            // digest (the contract the stale line broke forever before).
            foreach ($lines as $line) {
                $parts = explode('  ', $line, 2);
                $this->assertFileExists($scratch . '/dist/' . $parts[0], 'Every manifest line names an existing artifact.');
                $this->assertSame($parts[1], hash_file('sha256', $scratch . '/dist/' . $parts[0]), 'Every manifest line carries the artifact\'s real digest.');
            }

            // A FAILED rebuild never touches the manifest (t31-r5-S,
            // unchanged): the prune rides the successful merge only.
            $manifestBefore = (string) file_get_contents($manifestPath);
            $damaged = $plugins['beta-demo'] . '/beta-demo.php';
            $source = (string) file_get_contents($damaged);
            file_put_contents($damaged, str_replace('Plugin Name:', 'Plugin Void:', $source));
            try {
                WpConnectorsBuild::buildPlugin($plugins['beta-demo'], $scratch . '/dist');
                $this->fail('A headerless plugin must refuse the build.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no main plugin file', $e->getMessage());
            } finally {
                file_put_contents($damaged, $source);
            }
            $this->assertSame($manifestBefore, (string) file_get_contents($manifestPath), 'A failed run lands no manifest change, prune included.');
        } finally {
            WpHarness::rrmdir($scratch);
        }
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

    /**
     * Verifier-round pin (t31-r4-16, superseding the master-era
     * leak-only pin): a symlink inside the PLUGIN tree refuses the
     * build loudly. The old silent skip pinned only the leak half
     * (out-of-tree content never packaged) while leaving the divergence
     * half open — a symlinked plugin source loaded in development, was
     * scanned through by the self-containment walker, and silently
     * missed the zip: an unloadable artifact at exit 0 (reproduced).
     * The refusal closes both halves at once (nothing linked is ever
     * packaged, because nothing linked is ever built past).
     */
    public function testASymlinkInThePluginTreeRefusesTheBuild()
    {
        $tempPlugin = self::distDir() . '/.symlink-test/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        $secretOutside = dirname($tempPlugin) . '/outside-secret.txt';
        file_put_contents($secretOutside, 'not-packaged');
        symlink($secretOutside, $tempPlugin . '/leaked-config.txt');

        try {
            try {
                WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
                $this->fail('A symlink inside the plugin tree must refuse the build, never skip it silently.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('symlink', $e->getMessage());
                $this->assertStringContainsString('leaked-config.txt', $e->getMessage());
                $this->assertStringContainsString('outside-secret.txt', $e->getMessage(), 'The refusal names the link target — the leak half stays visible in the diagnostic.');
            }
            $this->assertSame(array(), glob(self::distDir() . '/connectors-example-connector-0.1.0.zip*') ?: array(), 'The refused build must leave no artifact behind.');
        } finally {
            WpHarness::rrmdir(dirname($tempPlugin));
        }
    }

    /**
     * Copies the example-connector fixture plugin into a target directory.
     *
     * t31-r3-12: the 11-line fixture-copy loop was already a verbatim
     * quadruple (the stale-version and symlink tests predate the branch;
     * the embed pair copied it again) and this round accrued four more —
     * one helper now, so a fixture-layout change (a new source file, a
     * renamed asset) rides one site.
     *
     * @param string $targetDir Absolute target directory (the plugin root
     *                          inside it is created as needed).
     * @return string The target directory, for call-site chaining.
     */
    private function copyFixturePlugin(string $targetDir): string
    {
        $fixtureRoot = __DIR__ . '/fixtures/plugins/' . self::FIXTURE;
        // The plugin root is created BEFORE the copy loop (verifier
        // round t31-r6-7): the loop relied on a subdirectory
        // (assets/src) being yielded before the first root-level FILE
        // — its mkdir(..., true) was what created the root — so on a
        // filesystem whose readdir order yields readme.txt first (a
        // tmpfs clone demonstrated it), copy() failed against a root
        // that did not exist yet and every fixture-copy test errored
        // in setup. Directory-entry order is not a contract.
        mkdir($targetDir, 0755, true);
        $fixture = new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($fixture, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $relative = str_replace($fixtureRoot . '/', '', $item->getPathname());
            $target = $targetDir . '/' . $relative;
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }

        return $targetDir;
    }

    /**
     * The entry names of a zip, in zip order.
     *
     * t31-r3-12: the numFiles loop had one verbatim copy per
     * zip-inspecting test; one helper owns the open/read/close shape
     * (and the loud open failure) now.
     *
     * @param string $zipPath Absolute zip path.
     * @return list<string> Entry names.
     */
    private function zipEntryNames(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath), "The built zip must open: {$zipPath}");
        $names = array();
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
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
     * Creates one minimal VALID plugin directory (header, version
     * constant, slug-derived PSR-4 autoloader) for in-process build
     * tests — the same plugin shape makeBuildCliRepo writes for CLI
     * runs, without the copied bin/ tree.
     *
     * @param string $root Parent directory (created implicitly).
     * @param string $slug Plugin slug.
     * @return string Absolute plugin directory.
     */
    private function makeMinimalPlugin(string $root, string $slug): string
    {
        $pluginDir = $root . '/' . $slug;
        mkdir($pluginDir . '/src', 0755, true);
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( '" . strtoupper(str_replace('-', '_', $slug)) . "_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        file_put_contents($pluginDir . '/' . $slug . '.php', $main);
        $suffix = wp_connectors_namespace_suffix_from_slug($slug);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\{$suffix}\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($pluginDir . '/src/autoload.php', $autoload);

        return $pluginDir;
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

/**
 * The short-write stream wrapper for the t31-r7-3 seam pin.
 *
 * stream_write() accepts half of every chunk it is handed, so PHP's own
 * file_put_contents() write loop falls short of the buffer and reports
 * failure with its "Only X of Y bytes written" diagnostic — a REAL
 * short write through the real write API, the deterministic driver for
 * the checked-write pin (no ENOSPC filesystem required). Registered and
 * unregistered by the test that drives it; never exposed on a path any
 * other code touches.
 *
 * The wrapper owns its URL namespace's DIRECTORY operations too
 * (t31-r9-8): writeNormalized() probes @mkdir(dirname($to)) before
 * every write, and the wrapper's mkdir() no-ops it inside the scheme —
 * the scratch URL never resolves onto the host filesystem, so the pin
 * cannot leak a literal scheme-named directory into the working
 * directory the way the one-segment URL spelling did.
 */
final class WpctShortWriteStream
{
    /** @var resource|null The stream context (required by the wrapper protocol). */
    public $context;

    /**
     * Accepts the path unconditionally.
     *
     * @param string     $path         The opened path.
     * @param string     $mode         The open mode.
     * @param int        $options      Stream options.
     * @param string|null $opened_path Receives the opened path.
     * @return bool Always true.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    /**
     * No-op directory creation inside the URL scheme (t31-r9-8): the
     * seam's mkdir probe succeeds without touching the host filesystem.
     *
     * @param string $path    The directory path inside the scheme.
     * @param int    $mode    The requested mode.
     * @param int    $options Stream options.
     * @return bool Always true.
     */
    public function mkdir(string $path, int $mode, int $options): bool
    {
        return true;
    }

    /**
     * Accepts half of the chunk — the short count that makes the real
     * write loop fall short.
     *
     * @param string $data The chunk to (partly) accept.
     * @return int The accepted byte count.
     */
    public function stream_write(string $data): int
    {
        return (int) floor(strlen($data) / 2);
    }

    /**
     * @return bool Always true (nothing to flush).
     */
    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * @return void
     */
    public function stream_close(): void
    {
    }

    /**
     * @return bool Always at EOF.
     */
    public function stream_eof(): bool
    {
        return true;
    }

    /**
     * @param string $path The stat target.
     * @param int    $flags Stat flags.
     * @return array<int|string, int|string> An empty stat.
     */
    public function url_stat(string $path, int $flags): array
    {
        return array();
    }
}
