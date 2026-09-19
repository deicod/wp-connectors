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
     * One unique-per-run scratch/work path under the shared dist/ (OCR
     * round 25, t31-ocr25-5 — the t31-ocr11-16 stage-dir doctrine over
     * this file's own trees): the FIXED spellings the file carried
     * ('.embed-test', '.teardown-masking', '.inspect-bad', …) made two
     * CONCURRENT suite runs collide on one tree — run B's pre-clean or
     * teardown eating run A's in-flight battery — exactly the
     * concurrent-run collision class the unique-stage doctrine closed
     * for the build. The random suffix makes the tree one run's own;
     * the if(is_dir()) pre-cleans the fixed names carried rode along
     * (dead on a unique name — the crashed-run residue they ate can no
     * longer collide with a live run).
     */
    private static function scratchPath(string $label): string
    {
        return self::distDir() . '/.' . $label . '-' . bin2hex(random_bytes(4));
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
            'connectors-zai-' . self::headerVersion(__DIR__ . '/../connectors/zai/zai.php') . '.zip',
            function (string $zipPath): void {
                $built = WpConnectorsBuild::buildPlugin(__DIR__ . '/../connectors/zai', self::distDir());
                $this->assertSame($zipPath, $built);

                /*
                 * The exec-capability guard (t31-ocr26-9, the ocr20-5
                 * doctrine): the inspector's green verdict rides its
                 * internal php -l spawn over the extracted tree — on a
                 * disable_functions host the sweep was an
                 * undefined-function \Error, never a visible skip.
                 */
                if (! self::canSpawnChildren()) {
                    $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict (its internal php -l spawn) cannot run, and the entry-name legs below it do not run either; the build above already landed and matched its name.');
                }

                $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-zai')));

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
            self::fixtureZipName(),
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
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::publishedChecksum($zipPath),
            'A vanished published zip must refuse the success-line digest, never print it blank.', \RuntimeException::class
        );
        $this->assertStringContainsString('cannot checksum the published', $refusal->getMessage());
        $this->assertStringContainsString(basename($zipPath), $refusal->getMessage(), 'The refusal names the artifact.');

        /*
         * The deleted-zip leg's mutation is HEALED before the skip gate
         * (OCR round 25, t31-ocr25-7): the uid-0 skip used to fire one
         * leg later, AFTER the unlink — a root runner's skip throw left
         * dist/ holding the deleted zip's stale sidecar and manifest
         * entry until tearDown or the next build. The rebuild lands the
         * whole set again first, so the skip (and any failure) fires
         * over a consistent dist/, the gate's own doctrine: skip BEFORE
         * the mutation your leg premises, or after its healing.
         */
        $zipPath = $this->buildFixture();

        // The chmod-000 window (non-root spelling, restored in finally).
        // Root-runner skip (t31-ocr4-1): uid 0 reads through mode 0000,
        // so the window never opens there.
        $this->skipChmod0000LegOnRootRunner('the chmod-000 published-zip window of the success-line digest pin');
        chmod($zipPath, 0000);
        try {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::publishedChecksum($zipPath),
                'An unreadable published zip must refuse the success-line digest.', \RuntimeException::class
            );
            $this->assertStringContainsString('cannot checksum the published', $refusal->getMessage());
        } finally {
            chmod($zipPath, 0644);
        }
    }

    public function testBuiltArtifactIsAcceptedByInspector()
    {
        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * the inspector's green verdict rides its internal php -l spawn
         * over the extracted tree — on a disable_functions host the
         * sweep was an undefined-function \Error, never a skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict (its internal php -l spawn) cannot run.');
        }

        $zipPath = $this->buildFixture();

        $work = self::scratchPath('inspect-test');
        $violations = wp_connectors_inspect_artifact($zipPath, $work);
        $this->assertSame(array(), $violations);
        $this->assertDirectoryDoesNotExist(
            $work,
            'The inspector must remove its temp extraction tree via try/finally (accepting path).'
        );
    }

    public function testExtractedArtifactIsSelfContainedAndSyntaxClean()
    {
        $zipPath = $this->buildFixture();

        $extractDir = self::scratchPath('extract-test');
        if (is_dir($extractDir)) {
            WpHarness::releaseScratch($extractDir);
        }
        mkdir($extractDir, 0755, true);
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath)),
            sprintf(
                'The zip under test must open: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->extractTo($extractDir);
        $zip->close();

        /*
         * Creation-to-cleanup under ONE finally (the verifier pass over
         * t31-ocr20-5, the t31-ocr16-14 scratch-staging class): the
         * body below ends in a capability skip whose throw once
         * stranded the extraction tree — the trailing rrmdir was a
         * STATEMENT, not a finally, and tearDown unlinks only the
         * connectors-* artifacts. The tree is removed on every exit
         * path now (an assertion failure, the skip, and the happy
         * path alike).
         */
        try {
            // Independent extraction contains exactly the plugin dir, no dev files.
            $this->assertFileExists($extractDir . '/' . self::FIXTURE . '/example-connector.php');
            $this->assertFileDoesNotExist($extractDir . '/' . self::FIXTURE . '/vendor');
            $this->assertFileDoesNotExist($extractDir . '/' . self::FIXTURE . '/composer.json');

            // LICENSE from the repo root is embedded.
            $this->assertFileExists($extractDir . '/' . self::FIXTURE . '/LICENSE');

            // All shipped PHP parses after extraction elsewhere.
            /*
             * The exec-capability guard (t31-ocr20-5, the ocr18-2/ocr16-12
             * doctrine over this consumer): the parse sweep below lints
             * every shipped source through a spawned engine, and on a
             * disable_functions host the first loop iteration was an
             * undefined-function \Error mid-test — the extraction and
             * entry-set assertions above already passed.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the post-extraction php -l sweep cannot run; the extraction, entry-set, and LICENSE assertions above already passed.');
            }
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
        } finally {
            WpHarness::releaseScratch($extractDir);
        }
    }

    public function testInspectorRejectsRepoRelativeInclude()
    {
        /*
         * The exec-capability guard (t31-ocr27-7, the ocr20-5/ocr26-9
         * doctrine): the anchoring violation ACCUMULATES — extraction
         * and the internal php -l sweep run anyway — so the verdict
         * leg rides the spawn; on a disable_functions host it was an
         * undefined-function \Error, never a skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict (its internal php -l spawn over the extracted tree) cannot run; the anchoring violation accumulates beside it, extraction still happens.');
        }
        $zipPath = $this->buildBadZip('escape-demo', "require_once dirname(__DIR__) . '/other-plugin/plugin.php';");
        $work = self::scratchPath('inspect-bad');
        $violations = wp_connectors_inspect_artifact($zipPath, $work);
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));
        // This path extracts a real tree, so it pins the try/finally cleanup:
        // a deleted finally block would leak the work tree and fail here.
        $this->assertDirectoryDoesNotExist(
            $work,
            'The inspector must remove its temp extraction tree via try/finally (rejecting path).'
        );
    }

    public function testInspectorRejectsMissingHeader()
    {
        /*
         * The exec-capability guard (t31-ocr27-7, the ocr20-5/ocr26-9
         * doctrine): the header verdict is a POST-EXTRACTION check —
         * the internal php -l sweep runs behind it, so the leg rides
         * the spawn; on a disable_functions host it was an
         * undefined-function \Error, never a skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict (its internal php -l spawn over the extracted tree) cannot run; the header verdict it precedes is a post-extraction check.');
        }
        $zipPath = $this->buildBadZip('header-demo', '', true);
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-bad'));
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('header is missing', implode("\n", $violations));
    }

    public function testInspectorRejectsDevelopmentFilesInZip()
    {
        /*
         * The exec-capability guard (t31-ocr27-7, the ocr20-5/ocr26-9
         * doctrine): the dev-entry verdicts ACCUMULATE — extraction
         * and the internal php -l sweep run anyway — so the leg rides
         * the spawn; on a disable_functions host it was an
         * undefined-function \Error, never a skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict (its internal php -l spawn over the extracted tree) cannot run; the dev-entry classification accumulates, extraction still happens.');
        }
        $zipPath = $this->buildBadZip('devfiles-demo', '');
        $extra = self::distDir() . '/connectors-devfiles-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath)),
            sprintf(
                'The zip under test must open: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString('devfiles-demo/vendor/autoload.php', "<?php\n");
        $zip->addFromString('devfiles-demo/composer.json', '{}');
        $zip->close();

        $violations = wp_connectors_inspect_artifact($extra, self::scratchPath('inspect-bad'));
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('development entry', implode("\n", $violations));
    }

    /**
     * OCR-round-10 pin (t31-ocr10-8): the embed-territory exemption
     * keys on the ARCHIVE-CONTROLLED top-level name — a hostile zip
     * whose single top-level dir IS a development entry ('vendor',
     * the t31-r5-5 exemption territory 'vendor/src/Shared/…' spelled
     * from a dev-entry root, driven) exempted everything under it
     * from dev-entry classification: composer.json and vendor/ under
     * the hostile 'src/Shared' rode the exemption un-flagged. The
     * exemption requires the top-level name to NOT be a development
     * entry, judged through the ONE vocabulary owner — the embed
     * territory only exists under a REAL plugin slug.
     */
    public function testADevEntryTopLevelDirectoryIsNeverAnEmbedTerritory(): void
    {
        /*
         * The exec-capability guard (t31-ocr27-7, the ocr20-5/ocr26-9
         * doctrine): the dev-entry classification under a dev-entry
         * root ACCUMULATES (the finding's own named arm) — extraction
         * and the internal php -l sweep run anyway, so both verdict
         * legs ride the spawn; on a disable_functions host it was an
         * undefined-function \Error, never a skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdicts (their internal php -l spawn over the extracted tree) cannot run; the dev-entry classification accumulates, extraction still happens.');
        }
        $head = "Plugin Name:       vendor\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       vendor\nAuthor:            x\n";
        $zipPath = self::distDir() . '/connectors-vendor-1.0.0.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString('vendor/vendor.php', "<?php\n/**\n * {$head} */\ndefine( 'VENDOR_VERSION', '1.0.0' );\n");
        $zip->addFromString('vendor/src/Shared/composer.json', '{}');
        $zip->addFromString('vendor/src/Shared/vendor/x.php', "<?php\n");
        $zip->close();

        try {
            $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-devroot'));
            $report = implode("\n", $violations);
            $this->assertStringContainsString('development entry "vendor/src/Shared/composer.json"', $report, 'The hostile embed-territory spelling under a dev-entry root is NOT exempted (red at HEAD: the top-level name was never judged).');
            $this->assertStringContainsString('development entry "vendor/src/Shared/vendor/x.php"', $report, 'The vendor segment under the hostile territory flags too.');
        } finally {
            @unlink($zipPath);
            @unlink($zipPath . '.sha256');
        }
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
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
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
        $work = self::scratchPath('inspect-partial');
        ob_start();
        try {
            $violations = wp_connectors_inspect_artifact($zipPath, $work);
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
        $this->assertDirectoryDoesNotExist($work, 'The partial tree is cleaned up on the refusing path too.');
    }

    /**
     * OCR-round-10 verifier-pass pin (t31-ocr10-17): the unique-dir
     * retry loop's own failure premise is CAPTURED, never leaked — on
     * an unwritable parent each of the 16 retries once raised a RAW
     * 'mkdir(): Permission denied' warning to output (driven pre-fix,
     * 16 lines of it) before the polite refusal printed. The r12-19
     * capture doctrine (one screen below, for extractTo()) now rides
     * the loop that shares its screen: zero leaked bytes with
     * display_errors forced on, and the refusal names the captured
     * reason. Root-runner skip: uid 0 writes through 0555, the
     * refusal cannot fire (the t31-ocr4-1 guard).
     */
    public function testTheUniqueDirRetryLoopCapturesItsOwnFailure(): void
    {
        if (self::runningAsRootRunner()) {
            $this->markTestSkipped('chmod-0555 does not block writes for uid 0 — the unwritable-parent refusal cannot fire in a root container (t31-ocr4-1).');
        }
        $scratch = self::distDir() . '/.inspect-mkdir-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/parent', 0755, true);
        chmod($scratch . '/parent', 0555);
        $slug = 'mkdirexhaust-demo';
        $zipPath = $scratch . "/connectors-{$slug}-1.0.0.zip";
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString("{$slug}/{$slug}.php", "<?php\n");
        $zip->close();

        try {
            $level = error_reporting(E_ALL);
            $display = ini_set('display_errors', '1');
            ob_start();
            try {
                $violations = wp_connectors_inspect_artifact($zipPath, $scratch . '/parent/sub');
                $leaked = (string) ob_get_contents();
            } finally {
                ob_end_clean();
                ini_set('display_errors', (string) $display);
                error_reporting($level);
            }

            $this->assertSame('', $leaked, 'The retry loop\'s raw mkdir() warnings must not leak to output — the refusal names the captured reason instead.');
            $this->assertCount(1, $violations, 'The creation refusal is the one verdict: ' . implode("\n", $violations));
            $this->assertStringContainsString('cannot create a unique extraction directory', $violations[0]);
            $this->assertStringNotContainsString("\n", $violations[0], 'The captured reason renders through the printable seam — no raw newlines.');
        } finally {
            chmod($scratch . '/parent', 0755);
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-10 pin (t31-ocr10-2, security): the extraction dir is
     * UNIQUE-OWNED — the WRITE half of the planted-link threat
     * t31-ocr9-10 closed for deletion. The workDir spellings are fixed
     * and predictable (the CLI's '/wp-connectors-inspect-<pid>', the
     * tests' dist/.inspect-* literals), so a symlink pre-planted at
     * the name made is_dir() follow it, mkdir() fail, and extractTo()
     * WRITE through the link into the attacker's chosen tree (driven
     * pre-fix: the extracted plugin dir landed inside the victim
     * tree). A random unique suffix cannot be pre-planted.
     */
    public function testTheExtractionDirectoryIsUniqueOwnedNeverAPlantedName(): void
    {
        $scratch = self::distDir() . '/.inspect-unique-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch, 0755, true);
        try {
            /*
             * (a) Uniqueness, observed through the captured engine
             * diagnostic: the NAME_MAX zip's extraction refusal (the
             * t31-r12-1 shape) names the FULL extraction path, so two
             * runs under the same base expose their dir names —
             * different unique suffixes, both under the base. (Red at
             * HEAD: pre-fix both runs extracted into the base itself,
             * one shared name.)
             */
            $slug = 'partextract-demo';
            $longNameZip = $scratch . "/connectors-{$slug}-1.0.0.zip";
            $zip = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $zip->open($longNameZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $longNameZip,
                    var_export($opened, true)
                )
            );
            $zip->addFromString("{$slug}/{$slug}.php", "<?php\n");
            $zip->addFromString("{$slug}/assets/" . str_repeat('a', 300) . '.php', "<?php\n");
            $zip->close();
            $extractionDirs = array();
            for ($run = 0; $run < 2; ++$run) {
                $violations = wp_connectors_inspect_artifact($longNameZip, $scratch . '/.inspect-uniq');
                $this->assertCount(1, $violations, 'The NAME_MAX refusal is the one verdict: ' . implode("\n", $violations));
                $this->assertMatchesRegularExpression(
                    '#' . preg_quote($scratch . '/.inspect-uniq', '#') . '-[0-9a-f]{16}/#',
                    $violations[0],
                    'The extraction refusal names the run\'s extraction dir — a unique suffix under the requested base.'
                );
                preg_match('#(' . preg_quote($scratch . '/.inspect-uniq', '#') . '-[0-9a-f]{16})/#', $violations[0], $hit);
                $extractionDirs[] = $hit[1];
            }
            $this->assertNotSame($extractionDirs[0], $extractionDirs[1], 'Two runs extract into two DIFFERENT unique dirs — the name is never reused, so it cannot be pre-planted.');
            $this->assertSame(array(), array_filter(glob($scratch . '/.inspect-uniq-*') ?: array(), 'is_dir'), 'Every unique extraction dir is cleaned up by the try/finally.');

            /*
             * (b) The pre-planted link at the OLD predictable name: the
             * name is never followed, extraction lands in the unique
             * dir (the zip inspects green), and the victim tree stands.
             * (Red at HEAD: driven pre-fix, the extracted plugin dir
             * landed INSIDE the victim tree through the link.) The
             * leg's gate rides the ONE capability owner
             * (t31-ocr11-23, the round's verifier sweep): this inline
             * @symlink probe twin was the last ungated one in the
             * file, and under disable_functions(symlink) @ cannot
             * suppress the missing-function Error — the probe FATALED
             * the test the skip exists to protect.
             */
            if (! self::canSymlink()) {
                $this->markTestSkipped('This host cannot create symlinks — the planted-link leg did not run (the uniqueness legs above already passed).');
            }
            /*
             * The exec-capability guard (t31-ocr26-9, the ocr20-5
             * doctrine): this leg's GREEN inspector verdict rides the
             * internal php -l spawn over the extracted tree — on a
             * disable_functions host the sweep was an
             * undefined-function \Error mid-leg, never a skip.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the planted-link leg\'s green inspector verdict (its internal php -l spawn) cannot run (the uniqueness legs above already passed).');
            }
            $victim = $scratch . '/victim';
            mkdir($victim, 0755, true);
            file_put_contents($victim . '/survivor.txt', 'survivor');
            $workDir = $scratch . '/wp-connectors-inspect-' . getmypid();
            symlink($victim, $workDir);
            $head = "Plugin Name:       linkdemo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       linkdemo\nAuthor:            x\n";
            $main = "<?php\n/**\n * {$head} */\ndefine( 'LINKDEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
            $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\Linkdemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
            $greenZip = $scratch . '/connectors-linkdemo-1.0.0.zip';
            $zip = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $zip->open($greenZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $greenZip,
                    var_export($opened, true)
                )
            );
            $zip->addFromString('linkdemo/linkdemo.php', $main);
            $zip->addFromString('linkdemo/src/autoload.php', $autoload);
            $zip->close();

            $this->assertSame(array(), wp_connectors_inspect_artifact($greenZip, $workDir), 'Extraction lands in the unique dir — the planted link never sabotages the inspection.');
            $this->assertSame(array('survivor.txt'), array_values(array_diff(scandir($victim), array('.', '..'))), 'The victim tree behind the planted link is untouched — nothing was written through it.');
            $this->assertTrue(is_link($workDir), 'The planted link stands exactly where it is.');
        } finally {
            if (is_link($scratch . '/wp-connectors-inspect-' . getmypid())) {
                unlink($scratch . '/wp-connectors-inspect-' . getmypid());
            }
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * Verifier-round pin (t31-r12-19, the security lens): the
     * extraction refusal interpolates the CAPTURED ENGINE WARNING,
     * which itself interpolates archive-controlled text. The r12 round
     * read this as "this libzip build sanitizes control bytes in entry
     * names on BOTH the write and the read side" — t31-r13-1's repro
     * falsified that premise (an entry name survives getNameIndex()
     * BYTE-EXACT, and addFromString keeps it too; both sides probed),
     * but THIS arm's spelling still does not reproduce here for its
     * own reason: the ENGINE DIAGNOSTIC the capture reads is
     * libzip-rendered text, and this build's renderer SUBSTITUTES the
     * control bytes with visible glyphs (U+25D9 for a newline, U+2190
     * for ESC — probed byte-level), so no raw C0 byte reaches the
     * capture — the seam is load-bearing for the verdict lines
     * that interpolate the names themselves (t31-r13-1, reproduced)
     * and hardening for captured engine text on builds whose renderer
     * passes raw bytes. The reason renders through the ONE printable
     * seam — every C0 control and DEL becomes a space — pinned at the
     * seam itself (drivable with real control bytes) and end to end
     * (the refusal line carries none, whatever the runtime hands the
     * capture).
     */
    public function testTheExtractionRefusalReasonCannotForgeLines(): void
    {
        // The seam, driven with real control bytes (the only spelling
        // that cannot drift behind a sanitizing runtime).
        $hostile = "before\ninspect: totally-legit.zip ACCEPTED\r\x1b[2J\x1b[H\x00\x7Fafter";
        $printed = wp_connectors_printable($hostile);
        $this->assertSame('before inspect: totally-legit.zip ACCEPTED  [2J [H  after', $printed, 'Every C0 control and DEL becomes a space; the printable body rides verbatim.');
        $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $printed), 'The rendered reason carries no line-forging or terminal-rewriting byte.');
        $this->assertSame('plain text rides untouched', wp_connectors_printable('plain text rides untouched'));

        // End to end: a NAME_MAX-breaking entry whose component carries
        // the forged verdict text — the refusal line that interpolates
        // the captured reason carries no raw control byte on THIS
        // runtime, and the seam keeps that true on any other.
        $slug = 'forge-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'FORGE_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\ForgeDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        $forgedEntry = "{$slug}/assets/" . str_repeat('a', 200) . "\ninspect: totally-legit.zip ACCEPTED (0 violation(s))\n\x1b[2J\x1b[H" . str_repeat('b', 100) . '.php';

        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array($forgedEntry, "<?php\n"),
        )));

        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-forge'));
        $this->assertCount(1, $violations, 'The extraction refusal is the only verdict: ' . implode("\n", $violations));
        $this->assertStringContainsString('cannot extract', $violations[0]);
        $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violations[0]), 'The refusal line carries no raw control byte — no forged line, no ANSI ride.');
        $this->assertStringContainsString(str_repeat('a', 40), $violations[0], 'The printable body of the reason still names the offending entry.');
    }

    /**
     * Round-13 pin (t31-r13-1, the security lens, REPRODUCED): every
     * verdict line that interpolates archive-controlled text renders
     * through the ONE printable seam. The r12 ledger's boundary claim
     * ("this libzip build sanitizes control bytes in entry names on
     * BOTH the write and the read side") is FALSE on this runtime — a
     * raw stored zip's entry name survives getNameIndex() BYTE-EXACT
     * with its newline (addFromString keeps it too; both sides probed
     * again this round), so the six unguarded verdict lines printed a
     * FORGED verdict line beside the real REJECTED one (the driver's
     * exact repro: entry name
     * '…/vendor/x\ninspect: FORGED-LINE-ACCEPTED (0 violations)\n.php'
     * put "inspect: FORGED-LINE-ACCEPTED (0 violations)" on STDERR as
     * its own line while the verdict was REJECTED). Every arm drives a
     * real raw-stored zip through the inspector and pins three things:
     * the real verdict stands, no line carries a control byte, and the
     * printable body still names the offending entry.
     */
    public function testVerdictLinesInterpolateEntryTextThroughThePrintableSeam(): void
    {
        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * arms (a) and (e) extract and assert verdicts the inspector's
         * internal php -l spawn produces — on a disable_functions host
         * the spawn was an undefined-function \Error mid-test, never a
         * visible skip (arms (b)-(d) refuse before extraction and skip
         * with the test, named here).
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the extracting arms ((a) and (e), the inspector\'s internal php -l spawn) cannot run, and the early-return arms (b)-(d) skip with the test.');
        }

        $slug = 'forgeline-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'FORGELINE_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\ForgelineDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        $forged = "x\ninspect: FORGED-LINE-ACCEPTED (0 violations)\n";
        $key = 'AKIA' . strtoupper(bin2hex(random_bytes(8)));

        // (a) The driver's exact repro: the dev-entry verdict interpolates
        // the hostile entry name; the verdict is REJECTED, the forged
        // ACCEPTED line renders nowhere, and the NEUTRALIZED body still
        // names the entry (space for the newline — the seam's render).
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/vendor/{$forged}.php", "<?php\n"),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-forgeline'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('development entry', $flat, 'The real verdict stands: the vendor-segment entry rejects.');
        $this->assertStringContainsString("vendor/x inspect: FORGED-LINE-ACCEPTED (0 violations) .php", $flat, 'The neutralized body still names the offending entry.');
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "No verdict line carries a control byte — no forged line, no ANSI ride: {$violation}");
        }

        // (b) The top-dir list: two top-level directories, one hostile.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.1.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array('a/x.txt', 'x'),
            array("{$forged}dir/y.txt", 'y'),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-forgeline'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('exactly one top-level plugin directory', $flat);
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "The top-dir list cannot forge a line: {$violation}");
        }

        // (c) The invalid-slug refusal: the hostile name IS the sole
        // top-level directory, and it fails the slug grammar — the
        // refusal prints the bytes that failed, neutralized.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.2.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$forged}dir/y.txt", 'y'),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-forgeline'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('invalid top-level plugin directory name', $flat);
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "The invalid-slug refusal cannot forge a line: {$violation}");
        }

        // (d) The traversal refusal: the escaping entry's name carries
        // the forged text; the refusal names it neutralized, and no
        // extraction ever runs (the refusal returns first).
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.3.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/sub/../evil{$forged}.txt", 'x'),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-forgeline'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('escapes the extraction directory', $flat);
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "The traversal refusal cannot forge a line: {$violation}");
        }

        // (e) The post-extraction lines: a newline-bearing LANDED
        // filename (Linux filesystems keep the entry's bytes byte-exact)
        // rides both the php -l failure line — whose engine output
        // interpolates the same path — and the secret-finding line.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.4.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/assets/broken{$forged}.php", "<?php this is not php\n"),
            array("{$slug}/assets/keys{$forged}.txt", "aws = {$key}\n"),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-forgeline'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('failed php -l', $flat, 'The parse-broken landed file still rejects.');
        $this->assertStringContainsString('aws-key', $flat, 'The live key under a newline-bearing landed name still rejects.');
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "Neither post-extraction line can forge a line — path or engine output: {$violation}");
        }
    }

    /**
     * Verifier-round pin (t31-r13-4, BOTH lenses independently, both
     * repros re-driven by the implementer): the inspector MERGES
     * violation lines from the shared helpers — main-file basenames,
     * header values, the version-constant value, the
     * self-containment walk's landed paths and include statements —
     * and every one of those is archive-controlled text (landed file
     * names survive extraction byte-exact; header and code values are
     * the artifact's own content). The r13-1 seam fixed the lines the
     * inspector spells itself; the merged lines still carried the
     * bytes raw, and the round's own "every verdict line" claim was
     * false until this fix — the helpers' output renders through the
     * ONE seam at the merge now (the helpers stay pure producers: the
     * conventions gate and the builder render them over the repo's
     * own trusted bytes; the inspector is the hostile-input surface).
     */
    public function testMergedHelperViolationsRenderThroughThePrintableSeam(): void
    {
        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * every arm's verdict rides the inspector's internal php -l
         * spawn over the extracted tree (the merged-helper violations
         * accumulate — extraction runs), and on a disable_functions
         * host the spawn was an undefined-function \Error, never a
         * visible skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the merged-helper verdict arms (the inspector\'s internal php -l spawn) cannot run.');
        }

        $slug = 'mergeforge-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'MERGEFORGE_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\MergeforgeDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        $forged = "x\ninspect: FORGED-LINE-ACCEPTED (0 violations)\n";

        // (a) The self-containment walk: an unanchored include inside a
        // newline-bearing LANDED directory — both the relative path and
        // the include statement ride the merged line.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/sub{$forged}dir/evil.php", "<?php\nrequire 'not-anchored.php';\n"),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-mergeforge'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('includes a path not anchored to the plugin dir', $flat, 'The real verdict stands: the unanchored include rejects.');
        $this->assertStringContainsString('subx inspect: FORGED-LINE-ACCEPTED (0 violations) dir/evil.php', $flat, 'The neutralized body still names the offending landed path (space for the newline — the seam\'s render).');
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "A merged self-containment line cannot forge: {$violation}");
        }

        // (b) The main-file list: a second header-bearing root file
        // whose NAME carries the forged text.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.1.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/second{$forged}main.php", $main),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-mergeforge'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('multiple main plugin files', $flat, 'The real verdict stands: the second main file rejects.');
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "A merged main-file line cannot forge: {$violation}");
        }

        // (c) Header values: the line-based header capture keeps a
        // carriage return (a line OVERWRITE in a terminal) and an ANSI
        // erase inside the value; the version-constant arm rides the
        // define() value the same way.
        $head2 = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 7.0\rinspect: FORGED-CLEARED\x1b[2K\r (0 violations)\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main2 = "<?php\n/**\n * {$head2} */\ndefine( 'MERGEFORGE_DEMO_VERSION', \"1.0.0\ninspect: FORGED-CONSTANT (0 violations)\n\" );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.2.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main2),
            array("{$slug}/src/autoload.php", $autoload),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-mergeforge'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('must be 6.9', $flat, 'The real verdict stands: the wrong Requires-at-least rejects.');
        $this->assertStringContainsString('does not match header Version', $flat, 'The real verdict stands: the constant mismatch rejects.');
        foreach ($violations as $violation) {
            $this->assertSame(1, preg_match('/\A[^\x00-\x1F\x7F]*\z/', $violation), "A merged header/constant line cannot forge — no CR overwrite, no ANSI ride: {$violation}");
        }
    }

    /**
     * Verifier-round pin (t31-r12-20, the security lens): the capture
     * handler's restore rides a FINALLY around the extractTo() call —
     * the pre-fix pairing (set_error_handler … call … restore on the
     * happy path only) left the swallow-all capture handler installed
     * for the REST of the process on any throw between the two calls,
     * silently suppressing every later warning, notice, and
     * deprecation. The throw spelling does not fire on this runtime
     * (probed: extractTo() with an unusable destination WARNS and
     * returns false — captured — rather than raising), so the pin is
     * the structural one: the restore lives inside the finally that
     * wraps the call, mutation-sensitive to the happy-path-only
     * pairing coming back.
     *
     * OCR round 39 (t31-ocr39-1): the r12-20 finally restored the
     * handler but let the throw itself ESCAPE — uncaught past the
     * restore, past $zip->close(), and past every verdict surface:
     * the CLI died at exit 255 with an engine stack trace and no
     * verdict, and a test call site aborted its whole battery. The
     * catch now rides INSIDE the try statement whose finally restores
     * the handler — the catch's return runs that finally exactly
     * once, and a nested finally of its own would restore twice (the
     * reviewer's own correction, one finding over the first cut). The
     * pin's shape grows with it: extractTo() wrapped in a try whose
     * CATCH clause precedes — never a sibling try, never a nested
     * finally — the finally that restores the handler. Still
     * structural: the throw spelling stays unconstructible on this
     * engine (re-probed this round: warn and false, both the
     * file-destination and empty-destination spellings), so the red
     * at HEAD is the missing catch itself.
     */
    public function testTheCaptureHandlerRestoreRidesAFinally(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../bin/inspect-artifact.php');
        /*
         * Whitespace-normalized (t31-ocr10-12): the pin asserted a
         * byte-exact, indentation-sensitive substring — the t31-ocr10-2
         * rename of one variable inside the try block reddened it live
         * (the finding's own class demonstrated), and any
         * formatting-only edit would too. The STRUCTURE is what is
         * pinned: extractTo() wrapped in a try whose finally restores
         * the handler, token order preserved, formatting-free.
         */
        /*
         * The pin matches STRUCTURE, not variable tokens (OCR round
         * 16, t31-ocr16-15d, the ocr10-12 doctrine the comment above
         * already claimed): the whitespace-collapsed exact string
         * still spelled '$extracted', '$zip', '$extractDir' byte-
         * exactly, so a rename of any of the three reddened the pin
         * live — the t31-ocr10-2 finding's own class, one variable
         * at a time. The shape is a pattern now: assignment of an
         * extractTo() call on any variable pair, wrapped in a try
         * whose catch-and-finally own the throw and the restore. The
         * catch body is pinned BRACE-FLAT ([^{}] in the pattern): a
         * nested try/finally inside the catch is the double-restore
         * shape the reviewer's correction names, and the pin reds on
         * its braces alone.
         */
        $this->assertSame(
            1,
            preg_match(
                '/try\s*\{\s*\$\w+\s*=\s*\$\w+->extractTo\(\s*\$\w+\s*\)\s*;\s*\}\s*catch\s*\([^)]+\)\s*\{\s*[^{}]*\}\s*finally\s*\{\s*restore_error_handler\(\)\s*;\s*\}/',
                $source
            ),
            'The capture handler\'s restore must ride the finally of the SAME try whose catch owns the extractTo() throw — the catch\'s return runs that finally exactly once, a happy-path-only restore leaks the swallow-all handler on any throw, and an uncaught throw dies at exit 255 with no verdict (t31-ocr39-1). (The pin matches the try/catch/finally STRUCTURE: variable names are any names, reformatting is any formatting — only the shape is pinned, the catch brace-flat so a nested finally\'s double restore reds on braces alone.)'
        );
    }

    /*
     * Artifact secret scans never prune (t31-r12-3, closing the r6-owned
     * ledger line): the scanner's dev-segment prune is a repo-walk
     * concept; a 'vendor'-shaped segment inside a SHIPPED tree is the
     * signal, never a place to stop reading.
     */

    public function testArtifactSecretScanNeverPrunesInsideTheShippedTree()
    {
        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * both verdicts (the red scan arms AND the clean-control green)
         * ride the inspector's internal php -l spawn over the extracted
         * tree — on a disable_functions host the spawn was an
         * undefined-function \Error, never a visible skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the secret-scan verdicts and the clean control (the inspector\'s internal php -l spawn) cannot run.');
        }

        $slug = 'prunescan-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'PRUNESCAN_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\PrunescanDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        // A live-shaped AWS key assembled at runtime (never a source
        // literal): identical bytes at both repro positions.
        $key = 'AKIA' . strtoupper(bin2hex(random_bytes(8)));

        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        $zip->addFromString("{$slug}/src/Shared/keys.txt", "aws = {$key}\n");
        // The r6 HIGH itself: the same key under a PRUNED-elsewhere segment.
        $zip->addFromString("{$slug}/src/Shared/vendor/keys.txt", "aws = {$key}\n");
        $zip->close();

        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-prune'));
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
        $this->assertTrue(
            true === ($opened = $zip->open($clean, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $clean,
                var_export($opened, true)
            )
        );
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        $zip->addFromString("{$slug}/src/Shared/vendor/README.txt", "vendored dependency notes\n");
        $zip->close();
        $this->assertSame(
            array(),
            wp_connectors_inspect_artifact($clean, self::scratchPath('inspect-prune-clean')),
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
        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * the duplicate violations ACCUMULATE — extraction and the
         * internal php -l spawn run anyway over every arm, and the
         * clean control (d) needs the full sweep green — so on a
         * disable_functions host the spawn was an undefined-function
         * \Error at arm (a), never a visible skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the duplicate-fence arms extract and ride the inspector\'s internal php -l spawn; none can run.');
        }

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
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('more than once', $flat, 'A byte-exact duplicate entry name refuses: the non-landed copy is judged by nobody.');
        $this->assertStringContainsString($slug . '/src/keys.txt', $flat);
        /*
         * The verdict is ONE line per duplicate copy (OCR round 26,
         * t31-ocr26-5): identical bytes fold identically, so the
         * second copy once tripped BOTH fences — the byte-exact line
         * AND the case-fold line beside it (a triple copy answered
         * four lines). The first fence wins; the fold fence judges
         * only the copies the byte fence did not name.
         */
        $this->assertSame(1, substr_count($flat, 'more than once'), 'A byte-exact duplicate answers exactly ONE verdict line — never one per fence.');
        $this->assertStringNotContainsString('case-fold duplicate', $flat, 'The byte-exact twin does not also wear the case-fold verdict — one offense, one line.');

        /*
         * (a-triple) A THIRD copy of the same bytes: exactly ONE line
         * (OCR round 27, t31-ocr27-4): the r26-5 close still answered
         * N−1 lines for N copies — a pair one, a triple two — while
         * its own comment claimed "deduped per name". One offense,
         * one line: the third and every later copy of the same bytes
         * answers nothing the second copy did not.
         */
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.6.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/src/keys.txt", "aws = {$key}\n"),
            array("{$slug}/src/keys.txt", "nothing to see\n"),
            array("{$slug}/src/keys.txt", "still nothing\n"),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup'));
        $flat = implode("\n", $violations);
        $this->assertSame(1, substr_count($flat, 'more than once'), 'A triple copy answers exactly ONE byte-duplicate line — the name\'s verdict is deduped per NAME, never one line per extra copy.');
        $this->assertStringNotContainsString('case-fold duplicate', $flat);

        /*
         * (a-devdup) A byte-duplicated DEV-SEGMENT name: exactly ONE
         * dev-entry line (OCR round 42, t31-ocr42-4 — the t31-ocr27-4
         * doctrine swept to the dev-entry collector): the duplicate
         * fence does not `continue`, so at HEAD every copy of the name
         * pushed its own identical 'development entry' line beside the
         * fence's ONE duplicate line — one offense, one line.
         */
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.7.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/vendor/x.php", "<?php\n"),
            array("{$slug}/vendor/x.php", "<?php\n"),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup'));
        $flat = implode("\n", $violations);
        $this->assertSame(1, substr_count($flat, 'development entry'), 'A byte-duplicated dev-segment entry answers exactly ONE dev-entry line — the name\'s verdict is deduped per NAME (red at HEAD: one line per copy), never one per extra copy.');
        $this->assertStringContainsString("development entry \"{$slug}/vendor/x.php\"", $flat, 'The dev-entry line names the offending entry through the printable seam.');
        $this->assertSame(1, substr_count($flat, 'more than once'), 'The duplicate fence answers its own ONE line beside it — two fences, two verdicts, no multiplication.');

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
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup'));
        $this->assertStringContainsString('case-fold duplicate', implode("\n", $violations), 'Case-fold duplicate entry names refuse — extraction on a folding target silently overwrites.');

        // (b-edge) The trailing EDGE-JUNK twins of the same fold (OCR
        // round 11, t31-ocr11-3): Windows strips trailing dots, spaces,
        // and controls per path component at extraction, so
        // 'logo.png.' and 'logo.png ' beside 'logo.png' collide exactly
        // like the case variants above — one silently overwrites the
        // other on a normalizing host while both pass byte-exact AND
        // case-fold comparison (driven red at HEAD: the twins passed
        // the fence untouched). The fence's normalization composes the
        // codebase's own owners: the ASCII case fold AND the
        // edge-junk class, per segment, the same fold the
        // development-entry vocabulary rides (t31-r6-5).
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.4.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/assets/logo.png", 'first'),
            array("{$slug}/assets/logo.png.", 'dot twin'),
            array("{$slug}/assets/logo.png ", 'space twin'),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('case-fold duplicate', $flat, 'A trailing-DOT twin is a fold duplicate — the fence strips the edge-junk class the extraction target itself strips.');
        $this->assertStringContainsString('logo.png.', $flat, 'The dot twin is named in the refusal.');
        $this->assertStringContainsString('logo.png ', $flat, 'The space twin is named in the refusal.');

        // (b-collapse) The SEGMENT-COLLAPSE twins (t31-ocr11-24, the
        // round's verifier lens): '.' and empty segments name the SAME
        // file at extraction on EVERY host — driven red at HEAD on
        // this one, extractTo() returned true with one file landed
        // and the fence silent, the first copy's bytes judged by
        // nobody. The fold drops the segments; '..' stays outside
        // (the traversal refusal owns it).
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.5.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/assets/logo.png", 'first'),
            array("{$slug}/assets/./logo.png", 'dot-segment twin'),
            array("{$slug}/assets//logo.png", 'empty-segment twin'),
        )));
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('case-fold duplicate', $flat, 'A dot-segment twin folds onto the plain name — the fence collapses what extraction collapses.');
        $this->assertStringContainsString('assets/./logo.png', $flat, 'The dot-segment twin is named in the refusal.');
        $this->assertStringContainsString('assets//logo.png', $flat, 'The empty-segment twin is named in the refusal.');

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
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup'));
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
        $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-dup')), 'A duplicate-free zip of the same shape inspects green.');
    }

    /**
     * OCR-round-32 pin (t31-ocr32-5): first-verdict-wins per name at
     * the traversal/near-source collectors (the t31-ocr27-4 doctrine,
     * this collector). The byte-duplicate fence does not `continue`
     * past its own emission, so every COPY of a hostile name once
     * pushed its own identical entry — N copies answered N identical
     * violation lines (red at HEAD: driven below, three copies
     * answered three traversal lines, two near-source copies answered
     * two). One offense, one line: the collectors are keyed per name
     * and the emission walks the keys. Pre-extraction refusals both —
     * no spawn gate (the ocr20-5 doctrine's ungated arm class).
     */
    public function testNCopyHostileEntriesAnswerExactlyOneLinePerName(): void
    {
        $slug = 'ncopy-demo';
        $main = "<?php\n/**\n * Plugin Name:       {$slug}\n * Version:           1.0.0\n */\n";

        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/../../escape.php", 'a'),
            array("{$slug}/src/../../escape.php", 'b'),
            array("{$slug}/src/../../escape.php", 'c'),
        )));
        $flat = implode("\n", wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-ncopy')));
        $this->assertSame(1, substr_count($flat, 'escapes the extraction directory'), 'A traversal name carried three times answers exactly ONE traversal line — the duplicate-fence line is its own offense, the traversal line is one.');
        $this->assertSame(1, substr_count($flat, 'more than once'), 'The duplicate fence keeps its own single line (the t31-ocr27-4 pin, unchanged).');
        unlink($zipPath);

        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.1.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/shell.php.", 'a'),
            array("{$slug}/shell.php.", 'b'),
        )));
        $flat = implode("\n", wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-ncopy')));
        $this->assertSame(1, substr_count($flat, 'NEAR-SOURCE PHP spelling'), 'A near-source name carried twice answers exactly ONE near-source line.');
        unlink($zipPath);
    }

    /*
     * The near-source PHP fence at the EXTRACTION fence (OCR round 20,
     * t31-ocr20-1, the security lens's HIGH — the r16 edge-junk class,
     * alive at a NEW seam): wp_connectors_is_php_source() judges only
     * the last four bytes, so an entry whose basename hides the
     * extension behind trailing edge junk ('shell.php ', 'shell.php.',
     * 'shell.php\x01') is a PHP source to every path-normalizing
     * extraction target (Windows strips trailing dots, spaces, and
     * controls per component — the exact fold the duplicate-entry
     * fence at t31-ocr11-3 already rides) while EVERY gate judged it
     * as not one: the entry extracted, the syntax loop skipped it (the
     * raw lens at :450), and the artifact ACCEPTED carrying a file
     * that lands as a live .php source on the folding host (driven red
     * at HEAD: zero violations). The collector has refused the same
     * spelling in shared/src since t31-r5-14; the extraction fence —
     * where the names are ARCHIVE-CONTROLLED — refused nothing. Every
     * segment whose raw spelling is not a PHP source but whose
     * trailing-folded spelling is one refuses the artifact loudly,
     * before extraction runs (the r16 lesson: a fold the host applies
     * is a fold the fence must judge). The control-byte leg carries
     * the UTF-8 flag bit — the spelling under which the raw byte
     * survives the reader's own name decode (driven: un-flagged, this
     * engine's libzip remaps \x01 through CP437 to the U+263A bytes).
     */
    public function testNearSourcePhpSpellingsRefuseExtraction(): void
    {
        $slug = 'nearsources-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'NEARSOURCES_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\NearsourcesDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";

        foreach (array(
            'trailing space' => array('shell.php ', '1.0.0', 0),
            'trailing dot' => array('shell.php.', '1.0.1', 0),
            'trailing control byte' => array("shell.php\x01", '1.0.2', 0x0800),
        ) as $label => list($entryName, $version, $flags)) {
            $zipPath = self::distDir() . "/connectors-{$slug}-{$version}.zip";
            file_put_contents($zipPath, self::storedZipBytes(array(
                array("{$slug}/{$slug}.php", $main),
                array("{$slug}/src/autoload.php", $autoload),
                array("{$slug}/src/{$entryName}", "<?php\n// near-source spelling\n"),
            ), $flags));
            $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-nearsource'));
            $flat = implode("\n", $violations);
            $this->assertStringContainsString('NEAR-SOURCE', $flat, "A near-source PHP spelling refuses extraction ({$label}; red at HEAD: the entry extracted and every gate judged it as not a PHP source).");
            // The refusal names the entry through the printable seam — the
            // control byte renders as its printable twin, never raw.
            $this->assertStringContainsString(wp_connectors_printable("{$slug}/src/{$entryName}"), $flat, "The refusal names the offending entry ({$label}).");
        }

        // Controls: the PLAIN spelling of the same entry is an ordinary PHP
        // source — it extracts, lints, and inspects green (the fence judges
        // the fold, never the source); a near-source NON-PHP tail
        // ('notes.md ') folds to no PHP source and refuses nothing here.
        /*
         * The exec-capability guard (t31-ocr27-7, the ocr20-5/ocr26-9
         * doctrine): the GREEN control leg extracts real PHP sources
         * and rides the internal php -l sweep; the refusal legs above
         * refuse PRE-extraction and already passed on any host.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the green control leg (the inspector\'s internal php -l spawn over the extracted PHP sources) cannot run; the near-source refusal legs above already passed.');
        }
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.3.zip";
        file_put_contents($zipPath, self::storedZipBytes(array(
            array("{$slug}/{$slug}.php", $main),
            array("{$slug}/src/autoload.php", $autoload),
            array("{$slug}/src/shell.php", "<?php\n// an ordinary source\n"),
            array("{$slug}/src/notes.md ", "prose\n"),
        )));
        $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-nearsource')), 'A plain .php entry and a non-PHP near-source tail inspects green — the fence owns exactly the fold-to-PHP class.');
    }

    /**
     * OCR-round-23 pin (t31-ocr23-8): the BUILDER side of the
     * near-source fence — the residual the r20 round itself named and
     * carried (the ledger's builder-side name fence): collectFiles()
     * packaged plugin-tree files whose names fold to .php only after
     * edge-junk stripping ('notes.php.', 'x.PHP ') while the inspector
     * refused every such entry through the ONE near-source predicate —
     * build shipped what inspect rejected, the fence pair INCONSISTENT
     * (a plugin tree carrying one built its zip at exit 0 and the same
     * zip failed inspection, no CI run satisfiable). The collector
     * skips the class now through the SAME judgment (the ONE
     * near-source owner both fences ride): what never ships never
     * judges the build — the exclusion filter's own doctrine — and the
     * pair answers ONE verdict, builder-excludes/inspector-rejects,
     * like the development-entry vocabulary before it (t31-r5-10).
     */
    public function testThePluginTreeCollectorSkipsNearSourceNamesSoBothFencesAnswerOneVerdict(): void
    {
        $scratch = self::scratchPath('nearsource-collect');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/notes.php.', 'a tail the fold strips');
        file_put_contents($scratch . '/plugin/example-connector/x.PHP ', 'a tail the fold strips');
        file_put_contents($scratch . '/plugin/example-connector/plain.php', "<?php\n// an ordinary source\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');

            $names = $this->zipEntryNames($zipPath);
            $this->assertNotContains('example-connector/notes.php.', $names, 'A near-source name never ships — the collector skips the fold-to-PHP class (red at HEAD: the entry was packaged).');
            $this->assertNotContains('example-connector/x.PHP ', $names, 'The case-folded near-source twin never ships either — the judgment rides the ONE predicate, casing and edge junk both.');
            $this->assertContains('example-connector/plain.php', $names, 'The plain control still ships — the fence owns exactly the fold-to-PHP class.');

            // The fence pair answers ONE verdict now: the built zip —
            // the very artifact the builder produces — inspects green
            // (red at HEAD: the same build's zip REJECTED, the
            // NEAR-SOURCE violation naming the entry the collector
            // shipped; both sides of the inconsistent pair driven).
            /*
             * The exec-capability guard (t31-ocr26-9, the ocr20-5
             * doctrine): the green inspector verdict rides the
             * internal php -l spawn over the extracted tree — the
             * build and entry-name legs above already passed.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the one-verdict inspect leg (the inspector\'s internal php -l spawn) cannot run; the build and entry-name legs above already passed.');
            }
            $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, $scratch . '/.inspect-nearsource-pair'), 'Build and inspect answer one verdict over the plugin tree\'s near-source names — never build-ships-what-inspect-rejects.');
        } finally {
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * Builds a zip's raw bytes with STORED entries in the exact order
     * given — including BYTE-EXACT DUPLICATE names, which the
     * ZipArchive writer refuses to produce (same-name writes replace)
     * but hostile archives carry and the ZipArchive READER counts
     * faithfully. The t31-r12-15 fence driver.
     *
     * The general-purpose flag word is caller-set (t31-ocr20-1): an
     * entry name byte the reader must hand back BYTE-EXACT — a raw
     * control byte in a near-source tail — needs the UTF-8 flag bit
     * (0x0800); without it this engine's libzip decodes the name as
     * CP437 and remaps the byte (\x01 arrives as the U+263A bytes,
     * driven), a conversion the hostile zip cannot opt out of.
     *
     * @param list<array{0: string, 1: string}> $entries Ordered [name, bytes] pairs.
     * @param int                                $flags  General-purpose flag word for every entry.
     * @return string The zip bytes.
     */
    private static function storedZipBytes(array $entries, int $flags = 0): string
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
            $local .= "PK\x03\x04" . pack('v', 20) . pack('v', $flags) . pack('v', 0) . pack('v', 0) . pack('v', 0)
                . pack('V', $crc) . pack('V', $len) . pack('V', $len) . pack('v', $nlen) . pack('v', 0) . $name . $data;
            $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', $flags) . pack('v', 0) . pack('v', 0) . pack('v', 0)
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
     * One embed-territory owner, two fold roles (t31-r12-10, corrected
     * by its verifier round t31-r12-16): the writer's collision fence
     * folds case (any case-variant of a generated destination refuses
     * the build — pinned by the r5-16 battery), while the inspector's
     * exemption matches the CANONICAL prefix only — a case-variant
     * spelling is foreign (no builder-produced zip carries one), and
     * its segments judge by the development-entry vocabulary. The
     * first cut folded the exemption too, and both verifier lenses
     * reproduced the regression: a hostile zip's 'SRC/SHARED/
     * composer.json' went REJECTED → ACCEPTED at exit 0.
     */

    public function testEmbedTerritoryIsJudgedByOneOwnerOnBothSides(): void
    {
        $slug = 'embedcase-demo';
        $head = "Plugin Name:       {$slug}\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       {$slug}\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'EMBEDCASE_DEMO_VERSION', '1.0.0' );\nrequire_once __DIR__ . '/src/autoload.php';\n";
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\EmbedcaseDemo\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";

        // The owner's own judgment: the canonical spelling is embed
        // territory; case variants and other slugs' trees are not.
        $this->assertTrue(wp_connectors_is_embed_destination("{$slug}/src/Shared/vendor/notes.txt", $slug), 'The canonical embed prefix is embed territory.');
        $this->assertFalse(wp_connectors_is_embed_destination("{$slug}/SRC/Shared/vendor/notes.txt", $slug), 'A case-variant prefix is FOREIGN territory — the builder\'s fence refuses the tree that carries one.');
        $this->assertFalse(wp_connectors_is_embed_destination("other-slug/src/Shared/x.php", $slug), 'Another plugin\'s embed tree is not this slug\'s territory.');

        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * every inspector verdict below rides the internal php -l
         * spawn over the extracted tree (the dev-entry and key
         * violations accumulate — extraction runs anyway) — on a
         * disable_functions host the spawn was an
         * undefined-function \Error, never a visible skip. The pure
         * predicate assertions above already passed.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict arms (the internal php -l spawn) cannot run; the territory-predicate assertions above already passed.');
        }

        // The verifier lenses' regression, pinned: a hostile zip's
        // case-variant embed territory carrying dev artifacts REFUSES —
        // the pre-round byte-exact verdict, restored.
        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        $zip->addFromString("{$slug}/SRC/SHARED/composer.json", "{}\n");
        $zip->addFromString("{$slug}/SRC/SHARED/phpunit.xml", "<phpunit/>\n");
        $zip->close();
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-embedcase'));
        $flat = implode("\n", $violations);
        $this->assertStringContainsString('development entry', $flat, 'A case-variant embed prefix is foreign: the dev artifacts under it are visible to the vocabulary again.');
        $this->assertStringContainsString('composer.json', $flat);

        // The CANONICAL territory stays exempt from classification
        // (shared/src has no exclusion concepts, t31-r5-5) — and the
        // exemption never exempts content (t31-r12-3): a live key
        // under the canonical prefix rejects through the unpruned scan.
        $key = 'AKIA' . strtoupper(bin2hex(random_bytes(8)));
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString("{$slug}/{$slug}.php", $main);
        $zip->addFromString("{$slug}/src/autoload.php", $autoload);
        $zip->addFromString("{$slug}/src/Shared/vendor/notes.txt", "vendored dependency notes\n");
        $zip->close();
        $this->assertSame(
            array(),
            wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-embedcase')),
            'The canonical embed territory stays exempt from classification — the t31-r5-5 doctrine unchanged.'
        );
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath)),
            sprintf(
                'The zip under test must open: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString("{$slug}/src/Shared/vendor/keys.txt", "aws = {$key}\n");
        $zip->close();
        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-embedcase'));
        $this->assertStringContainsString('aws-key', implode("\n", $violations), 'The exemption is classification-only: content checks still judge the canonical territory.');
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
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $main = "<?php\n/**\n * Plugin Name:       rootfile-demo\n * Version:           1.0.0\n * Requires at least: 6.9\n * Requires PHP:      8.2\n * License:           GPL-2.0-or-later\n * Text Domain:       rootfile-demo\n * Author:            x\n */\ndefine( 'ROOTFILE_DEMO_VERSION', '1.0.0' );\n";
        $zip->addFromString('plugin.php', $main);
        $zip->close();

        $workDir = self::scratchPath('inspect-rootfile');
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
        $tempPlugin = self::scratchPath('twomain-test') . '/twomain-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir()),
            'The build must refuse a plugin with two main files.', \RuntimeException::class
        );
        $this->assertStringContainsString('multiple main plugin files', $refusal->getMessage());

        // And the inspector rejects the archive shape it would produce.
        $zipPath = self::distDir() . '/connectors-twomain-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        foreach (array('twomain-demo.php', 'second-entry.php', 'src/autoload.php') as $relative) {
            $zip->addFile($tempPlugin . '/' . $relative, 'twomain-demo/' . $relative);
        }
        $zip->close();

        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * the inspector verdict rides the internal php -l spawn over
         * the extracted tree (the multiple-main violation accumulates —
         * extraction runs anyway); the in-process shared-rule legs
         * above already passed.
         */
        if (! self::canSpawnChildren()) {
            @unlink($zipPath);
            @unlink($zipPath . '.sha256');
            @unlink(self::distDir() . '/checksums.txt');
            WpHarness::releaseScratch(dirname($tempPlugin));
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict (its internal php -l spawn) cannot run; the in-process shared-rule legs above already passed.');
        }

        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-twomain'));
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('multiple main plugin files', implode("\n", $violations));

        unlink($zipPath);
        @unlink($zipPath . '.sha256');
        @unlink(self::distDir() . '/checksums.txt');
        WpHarness::releaseScratch(dirname($tempPlugin));
    }

    /*
     * Traversal root names (finding: '../payload.php' as the sole entry made
     * every check run against HOST paths outside the extraction dir).
     */

    public function testInspectorRejectsATraversalRootNameWithoutTouchingTheHost()
    {
        $zipPath = self::distDir() . '/connectors-traversal-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString('../payload.php', "<?php\n/**\n * Plugin Name:       traversal-demo\n * Version:           1.0.0\n */\n");
        $zip->close();

        $workDir = self::scratchPath('inspect-traversal');
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
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString('midpath-demo/midpath-demo.php', "<?php\n/**\n * Plugin Name:       midpath-demo\n * Version:           1.0.0\n */\n");
        $zip->addFromString('midpath-demo/src/../../escape.php', "<?php\necho 'outside';\n");
        $zip->close();

        $workDir = self::scratchPath('inspect-midpath');
        $violations = wp_connectors_inspect_artifact($zipPath, $workDir);

        $this->assertNotSame(array(), $violations, "A '..' path segment in any entry must be rejected.");
        $this->assertStringContainsString('escapes the extraction directory', implode("\n", $violations));
        $this->assertDirectoryDoesNotExist($workDir, 'The temp extraction tree must be cleaned up on every path.');
        $this->assertFileDoesNotExist(dirname($workDir) . '/escape.php', 'Extraction must never write outside the work dir.');

        unlink($zipPath);
    }

    /*
     * OCR-round-16 pin (t31-ocr16-1, the security lens): the '..'
     * traversal refusal owns every spelling that RESOLVES to '..'.
     * The byte-exact in_array judged only the plain segment, while
     * the duplicate fence's own edge-junk fold (the trailing
     * dot/space strip per segment) collapses '.. ' and '...' onto
     * '..' at extraction on every path-normalizing host (Windows
     * strips the trailing edge junk) — driven at HEAD: such a zip
     * carried ZERO traversal violations and the entries extracted
     * past the work dir's grammar. The refusal rides the same fold
     * now: one vocabulary, both gates.
     */
    public function testInspectorRejectsEdgeJunkSpellingsOfTheTraversalSegment()
    {
        $zipPath = self::distDir() . '/connectors-edgejunk-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString('edgejunk-demo/edgejunk-demo.php', "<?php\n/**\n * Plugin Name:       edgejunk-demo\n * Version:           1.0.0\n */\n");
        // Both spellings fold to '..' through the trailing edge-junk
        // strip: a trailing space, and a trailing dot.
        $zip->addFromString('edgejunk-demo/src/.. /escape.php', "<?php\necho 'space-tailed';\n");
        $zip->addFromString('edgejunk-demo/doc/.../escape2.php', "<?php\necho 'dot-tailed';\n");
        $zip->close();

        $workDir = self::scratchPath('inspect-edgejunk');
        $violations = wp_connectors_inspect_artifact($zipPath, $workDir);

        $this->assertNotSame(array(), $violations, "A '..'-resolving segment ('.. ', '...') is a traversal the fold class already knows collapses — it must be rejected as one.");
        $this->assertStringContainsString('escapes the extraction directory', implode("\n", $violations));
        $this->assertDirectoryDoesNotExist($workDir, 'The temp extraction tree must be cleaned up on every path.');
        $this->assertFileDoesNotExist(dirname($workDir) . '/escape.php', 'Extraction must never write outside the work dir.');

        unlink($zipPath);

        /*
         * The INTERLEAVED spellings (OCR round 27, t31-ocr27-1): junk
         * BETWEEN the dots survived the round-16 trailing-only strip
         * ('. .' folded to nothing the fence judged, red at HEAD: this
         * zip carried ZERO traversal violations) while every
         * path-normalizing host strips its own side of the class and
         * lands the parent token. The predicate judges the RESOLVED
         * segment now — junk folds out ANYWHERE it sits, then the
         * dots-only remainder of two or more dots refuses. The
         * control bytes need the raw-stored writer (the helper's own
         * flag doctrine: libzip remaps a control byte in a name
         * without the UTF-8 flag bit).
         */
        $interleaved = self::distDir() . '/connectors-interleave-demo-1.0.0.zip';
        file_put_contents($interleaved, self::storedZipBytes(array(
            array('interleave-demo/interleave-demo.php', "<?php\n/**\n * Plugin Name:       interleave-demo\n * Version:           1.0.0\n */\n"),
            array('interleave-demo/a/. ./escape3.php', "<?php\necho 'space-between';\n"),
            array("interleave-demo/b/..\t../escape4.php", "<?php\necho 'tab-between';\n"),
            array("interleave-demo/c/..\x01./escape5.php", "<?php\necho 'control-between';\n"),
        ), 0x0800));
        $workDir = self::scratchPath('inspect-interleave');
        $violations = wp_connectors_inspect_artifact($interleaved, $workDir);
        $flat = implode("\n", $violations);

        $this->assertStringContainsString('escapes the extraction directory', $flat, "Every junk-interleaved spelling of the parent token ('. .', '..<tab>..', '..<0x01>.') refuses — the resolved segment is the parent token after the host strips its own side of the junk class.");
        $this->assertDirectoryDoesNotExist($workDir, 'The temp extraction tree must be cleaned up on every path.');
        $this->assertFileDoesNotExist(dirname($workDir) . '/escape3.php', 'Extraction must never write outside the work dir.');

        unlink($interleaved);
    }

    public function testInspectorRejectsBackslashSeparatedPathEntries()
    {
        // A backslash is a harmless literal on Linux but a path separator
        // under PHP on Windows, where '..\..\x.php' behind a valid root
        // would extract outside the work dir.
        $zipPath = self::distDir() . '/connectors-backslash-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString('backslash-demo/backslash-demo.php', "<?php\n/**\n * Plugin Name:       backslash-demo\n * Version:           1.0.0\n */\n");
        $zip->addFromString("backslash-demo/src\\..\\..\\escape.php", "<?php\necho 'outside';\n");
        $zip->close();

        $workDir = self::scratchPath('inspect-backslash');
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
        $tempPlugin = self::scratchPath('anchored-test') . '/anchored-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        foreach (array('anchored-demo.php', 'src/autoload.php', 'src/support.php', 'src/Settings/bootstrap.php', 'src/escape.php') as $relative) {
            $zip->addFile($tempPlugin . '/' . $relative, 'anchored-demo/' . $relative);
        }
        $zip->close();

        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * the inspector verdict rides the internal php -l spawn over
         * the extracted tree (the anchoring violation accumulates —
         * extraction runs anyway); the in-process shared-rule legs
         * above already passed.
         */
        if (! self::canSpawnChildren()) {
            @unlink($zipPath);
            WpHarness::releaseScratch(dirname($tempPlugin));
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector verdict (its internal php -l spawn) cannot run; the in-process self-containment legs above already passed.');
        }

        $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-anchored'));
        $this->assertNotSame(array(), $violations);
        $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));

        unlink($zipPath);
        WpHarness::releaseScratch(dirname($tempPlugin));
    }

    /*
     * Includes hidden behind variables (finding: `require $dependency;`
     * carries no quoted literal, so an indirectly assigned escaping path
     * reported nothing and shipped in artifacts).
     */

    public function testLiteralFreeIncludesAreResolvedStrictly()
    {
        $tempPlugin = self::scratchPath('hidden-include-test') . '/hidden-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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

        WpHarness::releaseScratch(dirname($tempPlugin));
    }

    /*
     * Anchored includes mixing literals with variable segments (finding:
     * `require __DIR__ . '/' . $dependency;` carries a quoted literal, which
     * selected the literal-only analysis and skipped the variable part — an
     * escaping assignment behind it shipped unnoticed).
     */

    public function testMixedLiteralAndVariableIncludesAreAnalyzedPerSegment()
    {
        $tempPlugin = self::scratchPath('mixed-include-test') . '/mixed-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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

        WpHarness::releaseScratch(dirname($tempPlugin));
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
        $tempPlugin = self::scratchPath('map-launder-test') . '/map-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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

        WpHarness::releaseScratch(dirname($tempPlugin));
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
        $tempPlugin = self::scratchPath('string-contents-test') . '/string-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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

        WpHarness::releaseScratch(dirname($tempPlugin));
    }

    /*
     * All-plugin build mode (finding: a connector directory without a valid
     * main-file header was silently omitted from the no-argument build — a
     * damaged or new connector vanished from a release with exit 0).
     */

    public function testAllPluginBuildRejectsAMalformedConnectorDirectory()
    {
        /*
         * The exec-capability guard (t31-ocr21-2, the ocr18-2/ocr16-12
         * doctrine over this CLI consumer): the whole verdict rides a
         * spawned build.php, and on a disable_functions host the first
         * escaped argument was an undefined-function \Error mid-test.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the all-plugin rejection leg cannot run (the verdict rides a spawned CLI).');
        }
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

        WpHarness::releaseScratch($repo);
    }

    public function testAllPluginBuildPackagesEveryValidConnectorDirectory()
    {
        /*
         * The exec-capability guard (t31-ocr21-2, the ocr18-2/ocr16-12
         * doctrine): the whole verdict rides a spawned build.php, and on
         * a disable_functions host the first escaped argument was an
         * undefined-function \Error mid-test.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the all-plugin packaging leg cannot run (the verdict rides a spawned CLI).');
        }
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

        WpHarness::releaseScratch($repo);
    }

    public function testExplicitSlugBuildStillRejectsAMalformedConnector()
    {
        /*
         * The exec-capability guard (t31-ocr21-2, the ocr18-2/ocr16-12
         * doctrine): the whole verdict rides a spawned build.php, and on
         * a disable_functions host the first escaped argument was an
         * undefined-function \Error mid-test.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the explicit-slug rejection leg cannot run (the verdict rides a spawned CLI).');
        }
        $repo = $this->makeBuildCliRepo(array( 'broken-demo' => false ));

        $output = array();
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=broken-demo 2>&1', $output, $exit);

        $this->assertSame(1, $exit, 'Explicit-slug mode must keep rejecting a malformed connector.');
        $this->assertStringContainsString('no main plugin file', implode("\n", $output));

        WpHarness::releaseScratch($repo);
    }

    /*
     * Duplicate plugin headers (finding: the parser kept the LAST value while
     * WordPress's get_file_data() keeps the FIRST).
     */

    public function testDuplicateHeadersKeepTheFirstValueAndAreFlagged()
    {
        $tempPlugin = self::scratchPath('dupheader-test') . '/dupheader-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir()),
            'The build must refuse a plugin with duplicate headers.', \RuntimeException::class
        );
        $this->assertStringContainsString('duplicate', $refusal->getMessage());

        WpHarness::releaseScratch(dirname($tempPlugin));
    }

    /*
     * Version-constant gate (finding: stale {SLUG}_VERSION built mislabeled zips).
     */

    public function testBuildRefusesAStaleVersionConstant()
    {
        // Copy the fixture, bump the header Version without touching the
        // EXAMPLE_CONNECTOR_VERSION constant: the build must refuse.
        $tempPlugin = self::scratchPath('version-test') . '/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        $mainPath = $tempPlugin . '/example-connector.php';
        $main = (string) file_get_contents($mainPath);
        $main = str_replace('Version:           ' . self::fixtureVersion(), 'Version:           0.2.0', $main);
        file_put_contents($mainPath, $main);

        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir()),
            'The build must refuse a header/constant version mismatch.', \RuntimeException::class
        );
        $this->assertStringContainsString('does not match header Version', $refusal->getMessage());
        $this->assertStringContainsString('EXAMPLE_CONNECTOR_VERSION', $refusal->getMessage());

        WpHarness::releaseScratch(dirname($tempPlugin));
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
        $tempPlugin = self::scratchPath('embed-test') . '/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
            $this->assertTrue(
                true === ($opened = $zip->open($zipPath)),
                sprintf(
                    'The zip must open for the entry read: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $zipPath,
                    var_export($opened, true)
                )
            );
            $embedded = (string) $zip->getFromName('example-connector/src/Shared/Http/HeaderMap.php');
            $zip->close();
            $this->assertStringContainsString('namespace Deicod\\WpConnectors\\' . $suffix . '\\Shared\\Http;', $embedded);
            $this->assertStringContainsString('Generated copy of shared/src/Http/HeaderMap.php', $embedded);
        } finally {
            WpHarness::releaseScratch(dirname($tempPlugin));
        }
    }

    /**
     * OCR-round-36 pin (t31-ocr36-2): BOTH tree collectors fence their
     * recursion boundary. hasChildren() passes on stat alone, so an
     * unreadable SUBDIRECTORY mid-tree (a chmod-000 child) aborted each
     * descent in the SPL iterator's own UnexpectedValueException —
     * another library's vocabulary answering a build refusal (red at
     * HEAD: both walks threw the SPL exception class, the t31-ocr33-6
     * class at the last two unfenced collectors). The twins are fenced
     * in lockstep: the abort answers each walk's own named refusal,
     * the SPL message riding parenthetically (it is what names the
     * path); the staged source is a LEGAL shared root so the fence's
     * verdict is the only refusal the walk can reach, and the
     * readable-tree control below keeps the green walk's collection
     * unchanged. Gated by the opendir probe (the t31-ocr4-1 doctrine):
     * a host whose process opens chmod-0000 directories cannot
     * construct the shape at all.
     */
    public function testBothTreeCollectorsFenceTheirRecursionBoundary(): void
    {
        $scratch = self::scratchPath('collectors-unlistable');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        // Staging asserts its own landing (the t31-ocr35-7 doctrine):
        // a staging failure fails as staging, never as a collector
        // verdict.
        $this->assertTrue(@mkdir($scratch . '/locked/inner', 0755, true), "staging: the collectors' hostile leg must land at {$scratch}/locked/inner.");
        // Gated (the t31-ocr16-13 doctrine): an absent or empty source
        // makes the shared collector refuse as 'declares no namespace'
        // (or collect a phantom empty set) instead of the fence's
        // verdict the leg pins — and the source sits at the tree ROOT
        // so the PSR-4 fence keeps it green whatever readdir order the
        // descent takes to the locked child.
        $this->assertNotFalse(
            file_put_contents($scratch . '/Root.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface Root\n{\n}\n"),
            "staging: the legal shared source must land at {$scratch}/Root.php — every other verdict the walk owns must stay green so the fence's is the only one it can reach."
        );
        chmod($scratch . '/locked', 0000);
        // The unlistable-shape probe (the t31-ocr4-1 root doctrine): a
        // host whose process opens chmod-0000 directories cannot
        // construct the shape — skip visibly, never a vacuous green.
        $probe = @opendir($scratch . '/locked');
        if (false !== $probe) {
            closedir($probe);
            chmod($scratch . '/locked', 0755);
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host opens chmod-0000 directories (uid 0 — t31-ocr4-1); the mid-tree unlistable shape is unconstructible here.');
        }

        try {
            $plugin_refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::collectFiles($scratch),
                'An unlistable SUBDIRECTORY mid-tree must answer the plugin collector\'s own refusal, never the SPL iterator\'s vocabulary.', \RuntimeException::class
            );
            $this->assertStringContainsString('cannot be listed', $plugin_refusal->getMessage(), 'The refusal names the class the fence owns.');
            $this->assertStringContainsString('locked', $plugin_refusal->getMessage(), 'The refusal names the path — the SPL message parenthetical carries it.');

            $shared_refusal = $this->refusalOf(
                fn() => wp_connectors_php_source_files($scratch),
                'An unlistable SUBDIRECTORY mid-tree must answer the shared collector\'s own refusal, never the SPL iterator\'s vocabulary.', \RuntimeException::class
            );
            $this->assertStringContainsString('cannot be listed', $shared_refusal->getMessage(), 'The refusal names the class the fence owns.');
            $this->assertStringContainsString('locked', $shared_refusal->getMessage(), 'The refusal names the path — the SPL message parenthetical carries it.');
        } finally {
            chmod($scratch . '/locked', 0755);
            WpHarness::releaseScratch($scratch);
        }

        // The readable-tree control: the same walk with the mode
        // restored collects the legal source unchanged — the fence
        // changed nothing about the green walk.
        $restored = self::scratchPath('collectors-unlistable');
        $this->assertTrue(@mkdir($restored, 0755, true), "staging: the control tree must land at {$restored}.");
        $this->assertNotFalse(
            file_put_contents($restored . '/Root.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface Root\n{\n}\n"),
            "staging: the control's source must land — the collect expectation names it."
        );
        $this->assertSame(array('Root.php'), wp_connectors_php_source_files($restored));
        $this->assertSame(array('Root.php'), WpConnectorsBuild::collectFiles($restored));
        WpHarness::releaseScratch($restored);
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
        $scratch = self::scratchPath('embed-scratch');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-malformed');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
                $refusal = $this->refusalOf(
                    fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                    "A malformed build.json ({$label}) must refuse the build, never silently skip embed_shared.", \RuntimeException::class
                );
                $this->assertStringContainsString('malformed', $refusal->getMessage());
                $this->assertStringContainsString('build.json', $refusal->getMessage());
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
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-23 pin (t31-ocr23-1): buildPlugin()'s FINALLY teardown
     * walks the stage through rrmdir(), whose RecursiveDirectoryIterator
     * had no guard against the UnexpectedValueException a subdirectory
     * it cannot OPEN raises mid-recursion (glm31-4's class, on the walk
     * the teardown owns) — and an exception thrown in a finally REPLACES
     * the primary failure in flight, so the build answered the teardown's
     * SPL vocabulary instead of its own refusal. The teardown rides the
     * silent contract now (the walk wrapped; an iteration refusal is
     * swallowed — the partial removal stands, the primary verdict
     * surfaces). Driven red at HEAD through BOTH unguarded arms of the
     * same owner: the hostile tree sits at THIS RUN'S OWN stage name, so
     * at HEAD the startup reclaim (the same rrmdir, one screen above the
     * try) throws first — the drive answers the SPL message either way;
     * post-fix both arms swallow and the run reaches the try, where a
     * landing-preflight PRIMARY failure (a directory at the manifest
     * landing target) answers in the build's own vocabulary.
     */
    public function testTheFinallyTeardownNeverMasksThePrimaryFailureOverAHostileStageTree(): void
    {
        $scratch = self::scratchPath('teardown-masking');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        // The teardown-hostile tree at a LIVE-pid stage spelling: a
        // subdirectory this process cannot open. Since t31-ocr42-2 the
        // run's own stage name is unpredictable (the random suffix), so
        // a plant can no longer sit at THIS run's own name — the
        // planted tree is a lookalike the sweep's liveness gate keeps
        // (own pid, alive), and the teardown-masking drive itself rides
        // the reflection seam below (the rd-1 close). The opendir probe
        // is the capability signal (glm17-16: uid 0 reads through mode
        // 0000, the t31-ocr4-1 doctrine) — a host that opens it cannot
        // construct the hostile shape at all.
        $stage = $scratch . '/dist/.stage-example-connector-' . getmypid();
        mkdir($stage . '/locked/inner', 0755, true);
        file_put_contents($stage . '/locked/inner/orphan.txt', 'a crashed run\'s scratch');
        chmod($stage . '/locked', 0000);
        $probe = @opendir($stage . '/locked');
        if (false !== $probe) {
            closedir($probe);
            chmod($stage . '/locked', 0755);
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host opens chmod-0000 directories (uid 0 — t31-ocr4-1); the teardown-hostile stage tree is unconstructible here.');
        }

        // The PRIMARY failure: a directory at the manifest landing
        // target. The preflight refuses it with the stage fully
        // populated — the exact try-body state whose in-flight verdict
        // the finally must carry, never replace.
        mkdir($scratch . '/dist/checksums.txt');

        $lockedRoot = $scratch . '/locked-root';
        try {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'The landing preflight must refuse the run — a silent success here means the drive never reached the try.', \RuntimeException::class
            );
            $this->assertStringContainsString('is not a regular file', $refusal->getMessage(), 'The PRIMARY refusal surfaces — the teardown no longer answers in the SPL iterator\'s vocabulary over it.');
            $this->assertStringContainsString('checksums.txt', $refusal->getMessage(), 'The primary names the landing target the preflight judged.');
            $this->assertSame(array(), glob($scratch . '/dist/connectors-example-connector-*') ?: array(), 'The preflight refusal precedes every landing — nothing published.');

            /*
             * The verifier close (rd-1, the CONSTRUCTION shape): the
             * first cut wrapped only the walk, and the iterator is
             * built LAZILY on the removal root itself — a ROOT this
             * process cannot open threw from the constructor, one
             * shape over the walk's refusal, the same SPL vocabulary
             * through the same channel (driven at the round's HEAD
             * through this reflection seam). The silent contract owns
             * the whole iteration seam now.
             */
            mkdir($lockedRoot . '/inner', 0755, true);
            file_put_contents($lockedRoot . '/inner/x.txt', 'bytes');
            chmod($lockedRoot, 0000);
            $remove = new ReflectionMethod(WpConnectorsBuild::class, 'rrmdir');
            $remove->invoke(null, $lockedRoot);
            $this->assertDirectoryExists($lockedRoot, 'A locked removal ROOT answers the silent contract too — the guard owns the construction, never the walk alone.');
        } finally {
            chmod($stage . '/locked', 0755);
            @chmod($lockedRoot, 0755);
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-43 pin (t31-ocr43-4): the BUILD removal walk owns its
     * IO returns — the exact two-way escape ocr30-4 closed for the
     * copy twin and ocr32-7 for the harness twin, one owner over, at
     * THIS owner's own contract. WpConnectorsBuild::rrmdir()'s
     * per-entry unlink()/rmdir() calls (and the emptied root's final
     * rmdir) once ran bare, and this owner runs from buildPlugin()'s
     * FINALLY and the startup sweep — so a refused removal (a
     * stranded 0555 bit, a removal race) answered with a RAW E_WARNING
     * interpolating staging paths, against the docblock's own
     * silent-degrade promise: under the runner's warning conversion
     * an exception wearing another vocabulary inside the finally
     * (driven red at HEAD), outside it raw bytes. The policy is the
     * SILENT degrade, never the harness twin's loud throw — a rethrow
     * here would REPLACE the primary verdict in flight (the
     * t31-ocr23-1 class this owner exists to keep): the refused entry
     * stays for the sweep's next run, the partial removal stands.
     */
    public function testTheBuildRemovalWalkOwnsItsIoReturns(): void
    {
        $scratch = self::scratchPath('build-iofail');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/open', 0755, true);
        file_put_contents($scratch . '/open/gone.txt', 'bytes');
        mkdir($scratch . '/locked', 0755, true);
        file_put_contents($scratch . '/locked/x.txt', 'bytes');
        chmod($scratch . '/locked', 0555);
        // The stranded-shape probe (the t31-ocr4-1 root doctrine): a
        // host whose unlink ignores the mode bit cannot construct the
        // refusal — skip visibly, never a vacuous green.
        if (@unlink($scratch . '/locked/x.txt')) {
            chmod($scratch . '/locked', 0755);
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host unlinks through mode 0555 (uid 0 — t31-ocr4-1); the refused per-entry removal is unconstructible here.');
        }

        try {
            /*
             * Red at HEAD: the raw E_WARNING escaped at the first
             * refused unlink (the runner's failOnWarning conversion
             * wearing PHPUnit's vocabulary over the engine's words).
             * CHILD_FIRST order makes the failing entry deterministic
             * — 'locked/x.txt' is reached before its parent, and only
             * the locked subtree can refuse.
             */
            $remove = new ReflectionMethod(WpConnectorsBuild::class, 'rrmdir');
            $remove->invoke(null, $scratch);
            $this->assertFileDoesNotExist($scratch . '/open/gone.txt', 'The readable siblings are removed — the partial removal stands, the degrade never abandons the walk.');
            $this->assertDirectoryExists($scratch . '/locked', 'The refused subtree stays for the sweep\'s next run — the named failure is the silent degrade, never the engine\'s raw warning, never a rethrow from a finally.');
            $this->assertDirectoryExists($scratch, 'The not-emptied root stays with it — the final rmdir degrades the same way.');
        } finally {
            chmod($scratch . '/locked', 0755);
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-schema');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
                $refusal = $this->refusalOf(
                    fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                    "A build.json outside the closed schema ({$label}) must refuse the build, never ship its consequence.", \RuntimeException::class
                );
                $this->assertStringContainsString($fragment, $refusal->getMessage(), "The refusal must say why ({$label}): {$refusal->getMessage()}");
            }

            // t31-r5-6 control: the fence counts DECODED TOP-LEVEL keys
            // only — a nested object reusing the schema name lives in
            // its own frame and is not a duplicate, so this config
            // refuses through the unknown-key clause ('noted'), never
            // through the duplicate fence.
            file_put_contents($scratch . '/plugin/example-connector/build.json', '{"embed_shared": true, "noted": {"embed_shared": "nested"}}');
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An unknown top-level key must refuse as unknown, never ride the duplicate fence.', \RuntimeException::class
            );
            $this->assertStringContainsString('unknown key', $refusal->getMessage(), 'The nested same-name key must not count as a duplicate: ' . $refusal->getMessage());

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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A build.json that is not a regular file must refuse the build, never slip the seam silently.', \RuntimeException::class
            );
            $this->assertStringContainsString('not a regular file', $refusal->getMessage());
            rmdir($scratch . '/plugin/example-connector/build.json');
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The refused build must leave no zip behind.');

            /*
             * OCR-round-3 pin (t31-ocr3-3): the DIRECTORY row above
             * slipped the is_file() gate, but a SYMLINK skips the seam
             * itself — file_exists() follows links, so a DANGLING
             * build.json read as absent, the embed silently turned
             * off, and a library-less zip built and published at exit 0
             * (reproduced pre-fix). Both link shapes refuse at the
             * seam now: the dangling link (invisible to file_exists())
             * and the out-of-tree resolver (is_file() follows it, so a
             * config the plugin does not own would otherwise be read
             * through). The legs ride the CAPABILITY probe after the
             * controls below (t31-ocr11-8 over t31-ocr10-14: the gate
             * was function_exists — a host can have the function
             * without the privilege, and the suite converts the failed
             * call's warning to an error — and a mid-test invisible
             * skip; the visible skip names what already passed).
             */
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

            if (! self::canSymlink()) {
                $this->markTestSkipped('This host cannot create symlinks — the build.json link legs did not run (the seam legs and controls above already passed).');
            }
            $zipsBeforeLinkLegs = glob($scratch . '/dist/*.zip') ?: array();
            // The controls' last write left a REAL build.json behind —
            // the dangling-link leg needs the name free.
            unlink($scratch . '/plugin/example-connector/build.json');
            symlink($scratch . '/elsewhere-build.json', $scratch . '/plugin/example-connector/build.json');
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A DANGLING build.json symlink must refuse the build — file_exists() follows links and the seam would silently skip to no-embed.', \RuntimeException::class
            );
            $this->assertStringContainsString('is a symlink', $refusal->getMessage());
            unlink($scratch . '/plugin/example-connector/build.json');

            // A link that RESOLVES (out of the plugin tree, to a
            // perfectly valid embed config) refuses identically —
            // the old seam would have READ it through is_file().
            file_put_contents($scratch . '/outside-build.json', "{\"embed_shared\": true}\n");
            symlink($scratch . '/outside-build.json', $scratch . '/plugin/example-connector/build.json');
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An out-of-tree RESOLVING build.json symlink must refuse the build — the plugin\'s config may not be a link the release does not own.', \RuntimeException::class
            );
            $this->assertStringContainsString('is a symlink', $refusal->getMessage());
            $this->assertStringContainsString('outside-build.json', $refusal->getMessage(), 'The refusal names the link target.');
            unlink($scratch . '/plugin/example-connector/build.json');
            $this->assertSame($zipsBeforeLinkLegs, glob($scratch . '/dist/*.zip') ?: array(), 'The refused link legs must leave no zip behind (the controls\' zips survive untouched).');
        } finally {
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-residue');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An illegal namespace_suffix must refuse the build.', \RuntimeException::class
            );
            $this->assertStringContainsString('namespace segment', $refusal->getMessage());
            // t31-ocr5-4: these pins rode assertDirectoryDoesNotExist on
            // the pid-less `.stage-<slug>` name — a spelling nothing has
            // created since t31-r10-4, so every one was vacuous.
            $this->assertNoStageTree($scratch . '/dist', 'example-connector', 'The seam refusal must precede the staging tree (any pid spelling).');
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The seam refusal must leave no zip.');

            // (b) A throw that lands MID-BUILD, after the stage tree and
            // entries exist: the zip cannot land because a directory
            // sits at the destination path. The pre-flight lands nothing
            // (the old shape failed at open() with OVERWRITE; the
            // t31-r5-S shape produces every byte at a temp path first
            // and refuses the landing before any rename).
            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
            $blockedZip = $scratch . '/dist/' . self::fixtureZipName();
            mkdir($blockedZip, 0755, true);
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An un-landable zip path must fail the build.', \RuntimeException::class
            );
            $this->assertStringContainsString('not a regular file', $refusal->getMessage());
            $this->assertNoStageTree($scratch . '/dist', 'example-connector', 'A mid-build throw must tear the staging tree down (any pid spelling).');

            // Recovery: the same inputs build cleanly once the blocker
            // is gone (the failed run left nothing behind to collide).
            rmdir($blockedZip);
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($zipPath);
            $this->assertNoStageTree($scratch . '/dist', 'example-connector', 'The success path must tear the staging tree down too (any pid spelling).');

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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A postcondition-tripping shared source must fail the build mid-staging.', \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
            $this->assertNoStageTree($scratch . '/dist', 'example-connector', 'Every failure path tears the staging tree down (any pid spelling).');
            $this->assertSame($zipBefore, (string) file_get_contents($zipPath), 'A failure before the archive opens must not delete the previous good zip.');
            $this->assertSame($sidecarBefore, (string) file_get_contents($zipPath . '.sha256'), 'The sidecar must survive with the zip it describes.');
            $this->assertSame($manifestBefore, (string) file_get_contents($manifestPath), 'The manifest entry must stay consistent with the surviving artifact.');

            // (e) t31-r5-S pin, INVERTED at t31-ocr43-2 to the
            // guessable-spelling contract (the r42-2 twin sweep): the
            // zip temp is pid + random suffix now, so junk planted at
            // the once-predictable pid-only spelling never intersects
            // the run's own staging path — the build lands whole
            // beside it, and the planted spelling stands untouched (a
            // foreign tree at a spelling this run never owned is not
            // its to reclaim, the sweep's own-pid gate agreeing). The
            // archive-open refusal still owns a genuinely unusable
            // staging path; no test can aim one at 2^64 fresh bytes —
            // which is the fix's point.
            $stagingArchive = $scratch . '/dist/.' . self::fixtureZipName() . '.tmp-' . getmypid();
            unlink($scratch . '/shared/src/Broken.php');
            mkdir($stagingArchive, 0755, true);
            $rebuilt = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertFileExists($rebuilt, 'A blocker at the guessable pid-only spelling is inert — the staging temp is unpredictable, the build lands whole.');
            $this->assertDirectoryExists($stagingArchive, 'The planted spelling is never this run\'s to reclaim — it stands exactly where it was planted.');
            rmdir($stagingArchive);
        } finally {
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('digit-slug');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            $this->assertTrue(
                true === ($opened = $zip->open($zipPath)),
                sprintf(
                    'The zip must open for the entry read: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $zipPath,
                    var_export($opened, true)
                )
            );
            $embedded = (string) $zip->getFromName('3cx-oauth/src/Shared/Clock/ClockInterface.php');
            $shippedMain = (string) $zip->getFromName('3cx-oauth/3cx-oauth.php');
            $zip->close();
            $this->assertStringContainsString('namespace Deicod\\WpConnectors\\_3cxOauth\\Shared\\Clock;', $embedded);

            // The embedded copy is declarable PHP, not just a string the
            // zip accepted: the underscored namespace lints clean.
            /*
             * The exec-capability guard (t31-ocr20-5, the ocr18-2
             * doctrine): the lint and referenceability legs below spawn
             * engines (php -l twice, php -r once); the derivation, gate,
             * build, and embedded-content assertions above already
             * passed.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the embedded-copy lint and bare-code referenceability legs cannot run; the derivation, gate, build, and embedded-content assertions above already passed.');
            }
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
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-excluded-names');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            /*
             * The exec-capability guard (t31-ocr27-7, the ocr20-5/ocr26-9
             * doctrine): the green one-verdict leg inspects the whole
             * plugin tree plus the embedded src/Shared sources through
             * the internal php -l spawn; the build and entry-name legs
             * above already passed. The hostile traversal leg below
             * refuses PRE-extraction (spawn-free) and rides after the
             * skip point — it runs wherever this leg can.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the green one-verdict inspect leg (the inspector\'s internal php -l spawn over the plugin tree and embedded src/Shared) cannot run; the build and entry-name legs above already passed.');
            }
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
            $this->assertTrue(
                true === ($opened = $hostile->open($hostileZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for hostile-zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $hostileZip,
                    var_export($opened, true)
                )
            );
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
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-escaping-include');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An escaping include in a shared source must refuse the BUILD, not only the inspection.', \RuntimeException::class
            );
            $refused = $refusal->getMessage();

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
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-anchored-include');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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

            /*
             * The exec-capability guard (t31-ocr27-7, the ocr20-5/ocr26-9
             * doctrine): the green one-verdict leg (the same class as
             * the excluded-names test's) rides the inspector's internal
             * php -l spawn over the extracted tree; the build leg above
             * already passed.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the green one-verdict inspect leg (the inspector\'s internal php -l spawn over the extracted tree) cannot run; the build leg above already passed.');
            }

            // One verdict: the inspector scans the extracted tree with
            // the same full-tree anchor and accepts too.
            $this->assertSame(array(), wp_connectors_inspect_artifact($zipPath, $scratch . '/dist/.inspect-anchored'));
        } finally {
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-phpcase');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/shared/src/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
        file_put_contents($scratch . '/shared/src/ClockMath.PHP', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A non-canonical extension casing in shared/src must refuse the build, never ship a class no loader reaches.', \RuntimeException::class
            );
            $this->assertStringContainsString('non-canonical extension', $refusal->getMessage());
            $this->assertStringContainsString('ClockMath.PHP', $refusal->getMessage(), 'The refusal must name the file.');
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The refused build must leave no zip behind.');
            $this->assertNoStageTree($scratch . '/dist', 'example-connector');

            // Control: the canonical spelling of the same source ships,
            // rewritten, at its exact path.
            unlink($scratch . '/shared/src/ClockMath.PHP');
            file_put_contents($scratch . '/shared/src/ClockMath.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $names = $this->zipEntryNames($zipPath);
            $zip = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $zip->open($zipPath)),
                sprintf(
                    'The zip must open for the entry read: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $zipPath,
                    var_export($opened, true)
                )
            );
            $embedded = (string) $zip->getFromName('example-connector/src/Shared/ClockMath.php');
            $zip->close();
            $this->assertContains('example-connector/src/Shared/ClockMath.php', $names, 'The canonically-spelled source ships at its exact path.');
            $suffix = WpConnectorsBuild::namespaceSuffixFromSlug('example-connector');
            $this->assertStringContainsString('namespace Deicod\\WpConnectors\\' . $suffix . '\\Shared;', $embedded, 'The canonically-spelled copy is the REWRITTEN one.');
        } finally {
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('nearsource-edges');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
                $refusal = $this->refusalOf(
                    fn() => wp_connectors_php_source_files($scratch),
                    'A trailing-0x' . bin2hex($tail) . ' near-source tail must refuse the collector.', \RuntimeException::class
                );
                $this->assertStringContainsString('NEAR-SOURCE', $refusal->getMessage());
                $this->assertStringContainsString('ClockMath.php', $refusal->getMessage(), 'The refusal must name the file.');
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
                $refusal = $this->refusalOf(
                    fn() => wp_connectors_php_source_files($scratch),
                    'A leading-0x' . bin2hex($lead) . ' near-source spelling must refuse the collector.', \RuntimeException::class
                );
                $this->assertStringContainsString('NEAR-SOURCE', $refusal->getMessage());
                $this->assertStringContainsString('ClockMath.php', $refusal->getMessage(), 'The refusal must name the file.');
                unlink($scratch . '/' . $lead . 'ClockMath.php');
            }

            // The same edge junk on a DIRECTORY segment kills every
            // namespaced class under it — the fence judges every
            // segment of a collected source's path.
            mkdir($scratch . '/Clock ', 0755, true);
            file_put_contents($scratch . '/Clock /Math.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\nfinal class Math {}\n");
            $refusal = $this->refusalOf(
                fn() => wp_connectors_php_source_files($scratch),
                'A directory segment carrying a trailing edge byte must refuse the collector.', \RuntimeException::class
            );
            $this->assertStringContainsString('NEAR-SOURCE', $refusal->getMessage());
            $this->assertStringContainsString('Math.php', $refusal->getMessage(), 'The refusal must name the file under the junk segment.');
            WpHarness::releaseScratch($scratch . '/Clock ');

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
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-collision');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A plugin-owned path colliding with an embed destination must refuse the build, never silently replace the author\'s file.', \RuntimeException::class
            );
            $this->assertStringContainsString('collision', $refusal->getMessage());
            $this->assertStringContainsString('src/Shared/Clock/ClockInterface.php', $refusal->getMessage());
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A case-variant plugin path folding onto an embed destination must refuse the build.', \RuntimeException::class
            );
            $this->assertStringContainsString('case-insensitive collision', $refusal->getMessage());
        } finally {
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('license-case');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/LICENSE', "REPO LICENSE BYTES\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        $licenseEntries = function (array $names): array {
            return array_values(array_filter($names, static function (string $entry): bool {
                return 0 === strcasecmp($entry, 'example-connector/LICENSE');
            }));
        };
        $entryBytes = function (string $zipPath, string $entry): string {
            $zip = new ZipArchive();
            // The re-open is STRICTLY gated (t31-ocr11-19, the
            // t31-ocr10-16 doctrine on this site): a failed open over a
            // truthy ER_* int read empty bytes as the fixture's —
            // assertTrue(true === …), the raw return named in the
            // failure, the strict expression never the truthy int.
            $opened = $zip->open($zipPath);
            $this->assertTrue(
                true === $opened,
                sprintf('The zip must open for the byte snapshot: %s (ZipArchive::open() returned %s).', $zipPath, var_export($opened, true))
            );
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
            /*
             * The exec-capability guard (t31-ocr26-9, the ocr20-5
             * doctrine): both one-verdict inspect legs (this control
             * and the case-variant pin below) ride the inspector's
             * internal php -l spawn — on a disable_functions host the
             * spawn was an undefined-function \Error, never a visible
             * skip (the build and license-entry legs above already
             * passed; the skip unwinds through the rrmdir finally).
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the one-verdict inspect legs (the inspector\'s internal php -l spawn) cannot run; the build and license-entry legs above already passed.');
            }
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
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR round 13 (t31-ocr13-1): both collision fences — the LICENSE
     * injection's DEFERENCE and the embed destination's REFUSAL — fold
     * case through the ONE ASCII owner (wp_connectors_ascii_lower()),
     * never a locale-consulting strcasecmp() (the r11-6/ocr10-4
     * doctrine: a fold that feeds a verdict must be a constant of the
     * artifact, not a question about the process locale).
     *
     * The r11-6 posture, per the ledger's own record: on this 8.5.10
     * engine PHP's string folds measured ASCII-clean under a live
     * manufactured tr_TR locale (probed this round too: strcasecmp()
     * over the collision spellings returned 0 and left the 8-bit bytes
     * unfolded), while the C-level divergence is real (glibc
     * tolower('I') = 0xFD under the same locale) — so the pressure
     * half below pins the verdicts UNDER the live locale rather than
     * re-driving a red this engine cannot produce; re-open with an
     * engine whose string folds consult the locale. The C-locale
     * spelling pins are the battery's case-variant rows and the
     * license pin above.
     */
    public function testTheCollisionFencesFoldCaseThroughTheOneAsciiOwnerUnderTurkishLocale(): void
    {
        $scratch = self::scratchPath('collision-fold-pressure');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        file_put_contents($scratch . '/LICENSE', "REPO LICENSE BYTES\n");
        mkdir($scratch . '/shared/src/Clock', 0755, true);
        file_put_contents(
            $scratch . '/shared/src/Clock/ClockInterface.php',
            "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n"
        );
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        try {
            // C-locale control: the DEFERENCE half — the plugin's
            // 'license' wins, the repo copy is never injected beside it.
            file_put_contents($scratch . '/plugin/example-connector/license', "PLUGIN OWN LICENSE BYTES\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $names = $this->zipEntryNames($zipPath);
            $this->assertContains('example-connector/license', $names);
            $this->assertNotContains('example-connector/LICENSE', $names, 'The repo LICENSE defers to the plugin\'s own license (the C-locale control).');

            // C-locale control: the REFUSAL half — the case-variant
            // plugin-owned embed destination refuses the build.
            file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");
            mkdir($scratch . '/plugin/example-connector/src/Shared/Clock', 0755, true);
            file_put_contents(
                $scratch . '/plugin/example-connector/src/Shared/Clock/clockinterface.php',
                "<?php\n// the plugin author's case-variant own copy\n"
            );
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A case-variant plugin-owned embed destination must refuse the build (the C-locale control).', \RuntimeException::class
            );
            $this->assertStringContainsString('case-insensitive collision', $refusal->getMessage());

            /*
             * The pressure half: manufacture the 8-bit Turkish locale,
             * install it LIVE, and require both fence verdicts
             * unchanged — the same spells, the same deference and the
             * same refusal, under a locale whose libc folds I to the
             * dotless ı. A strcasecmp-riding fence is green here only
             * by this engine's mercy; the ASCII owner's fold has no
             * locale to consult. (The exec guard is the ocr6-12
             * visible-skip doctrine: a host that cannot run localedef
             * skips the pressure half, naming what did not run — the
             * C-locale controls above already passed.)
             */
            // The guard declares the TRIPLE (OCR round 22's verifier
            // pass, rd-2 — the t31-ocr22-4 class over this consumer):
            // the pressure leg putenv()s LOCPATH in the PARENT before
            // the locale installs, so a putenv-disabled host fataled
            // at the putenv (driven) instead of this visible skip.
            if (! self::canSpawnChildren('putenv')) {
                $this->markTestSkipped('This host has exec/escapeshellarg/putenv in disable_functions — the localedef pressure locale cannot be manufactured (the pressure leg sets LOCPATH through putenv in this very process); the locale-pressure half did not run (the C-locale controls above already passed).');
            }
            $locpath = sys_get_temp_dir() . '/wpct-locale-' . getmypid();
            @mkdir($locpath, 0755, true);
            exec('localedef -i tr_TR -f ISO-8859-9 ' . escapeshellarg($locpath . '/tr_TR.ISO-8859-9') . ' 2>/dev/null', $localedefOutput, $localedefExit);
            if (0 !== $localedefExit) {
                WpHarness::releaseScratch($locpath);
                $this->markTestSkipped('The tr_TR.ISO-8859-9 pressure locale could not be manufactured on this host (localedef exit ' . $localedefExit . ') — the locale-pressure half did not run; the C-locale controls above already passed.');
            }
            $previous = setlocale(LC_CTYPE, '0');
            $previousLocpath = getenv('LOCPATH');
            try {
                putenv('LOCPATH=' . $locpath);
                $this->assertNotFalse(setlocale(LC_CTYPE, 'tr_TR.ISO-8859-9'), 'The manufactured locale must install.');
                $this->assertTrue(ctype_lower("\xE3"), 'ctype consults the manufactured 8-bit LC_CTYPE — the pressure is live.');

                // REFUSAL under pressure (the scratch still carries the
                // control's plant): the case-variant collision still
                // refuses, naming the plugin's own file.
                $refusal = $this->refusalOf(
                    fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                    'The case-variant embed collision still refuses under the live Turkish locale.', \RuntimeException::class
                );
                $this->assertStringContainsString('case-insensitive collision', $refusal->getMessage(), 'The refusal keeps its class under the live Turkish locale — the fold is the ASCII owner\'s, not the locale\'s.');

                // DEFERENCE under pressure: with the collision plant
                // gone (the embed is not requested), still exactly the
                // plugin's one license entry, never the repo copy
                // beside it.
                unlink($scratch . '/plugin/example-connector/build.json');
                unlink($scratch . '/plugin/example-connector/src/Shared/Clock/clockinterface.php');
                $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
                $names = $this->zipEntryNames($zipPath);
                $this->assertContains('example-connector/license', $names, 'The plugin\'s own license still ships under the live Turkish locale.');
                $this->assertNotContains('example-connector/LICENSE', $names, 'The repo LICENSE is never injected beside the plugin\'s license under the live Turkish locale — a locale-consulting fold would read LICENSE as foreign and inject it.');
            } finally {
                // LOCPATH restored BEFORE the locale, the restore
                // CHECKED (the r11-6 idiom): a leaked pressure locale
                // breaks every later /i match in this process.
                putenv(false === $previousLocpath ? 'LOCPATH' : 'LOCPATH=' . $previousLocpath);
                if (false === setlocale(LC_CTYPE, $previous)) {
                    setlocale(LC_CTYPE, 'C');
                }
                WpHarness::releaseScratch($locpath);
            }
        } finally {
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('read-seam');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            // Root-runner skip (t31-ocr4-1): this guard covers BOTH
            // chmod-0000 legs of this test — (a) here and (c) below — a
            // skip aborts the rest, and the whitespace leg (b) between
            // them rides the same skip on a root container.
            $this->skipChmod0000LegOnRootRunner('the unreadable-source legs of the loud-read pin');
            // t31-r8-4 supersession: the refusal fires at the collector's
            // PSR-4 casing fence now — the config seam, before any
            // filesystem mutation — with readSharedSource's own loud read
            // seam kept behind it as defense in depth.
            chmod($sharedSource, 0000);
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An unreadable shared source must refuse the build, never ship as a 0-byte library file.', \RuntimeException::class
            );
            $this->assertStringContainsString('cannot be read', $refusal->getMessage());
            $this->assertStringContainsString('ClockInterface.php', $refusal->getMessage());
            chmod($sharedSource, 0644);

            // (b) The whitespace-only twin: no read failure, same ship —
            // refused by the same collector fence, one seam earlier
            // (t31-r8-4: declaration-less bytes declare no namespace).
            $sourceBefore = (string) file_get_contents($sharedSource);
            file_put_contents($sharedSource, " \n\t\n");
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A whitespace-only shared source must refuse the build, never rewrite to an empty file.', \RuntimeException::class
            );
            $this->assertStringContainsString('declares no namespace', $refusal->getMessage());
            $this->assertStringContainsString('ClockInterface.php', $refusal->getMessage());
            file_put_contents($sharedSource, $sourceBefore);

            // (c) The other collection point: an unreadable PLUGIN file.
            chmod($pluginSource, 0000);
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An unreadable plugin file must refuse the build, never ship a 0-byte zip entry.', \RuntimeException::class
            );
            $this->assertStringContainsString('cannot copy', $refusal->getMessage());
            $this->assertStringContainsString('ExampleProvider.php', $refusal->getMessage());
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
            WpHarness::releaseScratch($scratch);
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
        $scratch = self::scratchPath('embed-empty-tree');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/shared/src', 0755, true);
        mkdir($scratch . '/dist', 0755, true);
        // A tree that exists, carries a non-PHP file, and no sources.
        file_put_contents($scratch . '/shared/src/README.md', "# no sources here\n");

        $this->copyFixturePlugin($scratch . '/plugin/example-connector');
        file_put_contents($scratch . '/plugin/example-connector/build.json', "{\"embed_shared\": true}\n");

        try {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An embed requested against a source-less shared tree must refuse the build, never ship a library-less zip.', \RuntimeException::class
            );
            $this->assertStringContainsString('no PHP sources', $refusal->getMessage());
            $this->assertStringContainsString('shared/src', $refusal->getMessage());
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'A refused build must leave no zip behind.');
            $this->assertNoStageTree($scratch . '/dist', 'example-connector', 'The seam refusal must precede the staging tree (any pid spelling).');

            // The wholly empty directory refuses the same way.
            unlink($scratch . '/shared/src/README.md');
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An embed requested against an empty shared tree must refuse the build too.', \RuntimeException::class
            );
            $this->assertStringContainsString('no PHP sources', $refusal->getMessage());

            // Control: one source is enough — the embed builds.
            file_put_contents($scratch . '/shared/src/GrantInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface GrantInterface {}\n");
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertContains('example-connector/src/Shared/GrantInterface.php', $this->zipEntryNames($zipPath), 'A non-empty shared tree embeds normally.');
        } finally {
            WpHarness::releaseScratch($scratch);
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
        // The capability probe (t31-ocr10-14): the battery's bare
        // symlink() calls fataled the leg on exactly the hosts that
        // never exercised it.
        if (! self::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the excluded-path/shipped-link ordering legs cannot run on it.');
        }
        $tempPlugin = self::scratchPath('symlink-order-test') . '/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir()),
                'A symlink on a shipped path must still refuse the build.', \RuntimeException::class
            );
            $this->assertStringContainsString('symlink', $refusal->getMessage());
            $this->assertStringContainsString('linked-asset.svg', $refusal->getMessage());
        } finally {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $tempPlugin = self::scratchPath('deventry-test') . '/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        // The drifted spellings: the dotless cache dir (shipped pre-fix),
        // the dotted twin, and the bundler configs the inspector missed.
        mkdir($tempPlugin . '/phpunit.cache', 0755, true);
        file_put_contents($tempPlugin . '/phpunit.cache/cached.xml', '<c/>');
        mkdir($tempPlugin . '/.phpunit.cache', 0755, true);
        file_put_contents($tempPlugin . '/.phpunit.cache/cached.xml', '<c/>');
        // Real newlines (t31-ocr11-18): the single-quoted '\n' landed a
        // literal backslash-n in the fixtures — a copy-paste trap
        // beside the sibling writes that spell real ones.
        file_put_contents($tempPlugin . '/webpack.config.js', "module.exports = {};\n");
        file_put_contents($tempPlugin . '/vite.config.js', "export default {};\n");

        try {
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            foreach ($this->zipEntryNames($zipPath) as $entry) {
                $this->assertStringNotContainsString('phpunit.cache', $entry, 'No cache spelling may ship.');
                $this->assertStringNotContainsString('webpack.config.js', $entry, 'No bundler config may ship.');
                $this->assertStringNotContainsString('vite.config.js', $entry, 'No bundler config may ship.');
            }

            // ONE verdict: the inspector accepts exactly what the doctrine
            // ships (the pre-fix contradiction, re-driven by the verifier).
            /*
             * The exec-capability guard (t31-ocr26-9, the ocr20-5
             * doctrine): both verdict directions (this green arm and
             * the hostile-zip arm below) ride the inspector's internal
             * php -l spawn over the extracted tree; the build and
             * entry-name legs above already passed.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the one-verdict and hostile-zip inspect arms (the inspector\'s internal php -l spawn) cannot run; the build and entry-name legs above already passed.');
            }
            $this->assertSame(
                array(),
                wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-deventry')),
                'Build and inspect must agree on the development-entry vocabulary.'
            );

            // The reverse direction still judges: a HAND-CRAFTED zip
            // carrying any of the spellings rejects (the inspector keeps
            // its own teeth; the shared list only aligned them).
            $hostileZip = self::distDir() . '/connectors-deventry-demo-1.0.0.zip';
            $hostile = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $hostile->open($hostileZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for hostile-zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $hostileZip,
                    var_export($opened, true)
                )
            );
            $head = "Plugin Name:       deventry-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       deventry-demo\nAuthor:            x\n";
            $hostile->addFromString('deventry-demo/deventry-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'DEVENTRY_DEMO_VERSION', '1.0.0' );\n");
            foreach (array('deventry-demo/phpunit.cache/cached.xml', 'deventry-demo/.phpunit.cache/cached.xml', 'deventry-demo/webpack.config.js') as $entry) {
                $hostile->addFromString($entry, 'x');
            }
            $hostile->close();
            try {
                $violations = wp_connectors_inspect_artifact($hostileZip, self::scratchPath('inspect-deventry-hostile'));
                $this->assertNotSame(array(), $violations, 'A crafted zip carrying any development-entry spelling must reject.');
                $report = implode("\n", $violations);
                /*
                 * Quote-bounded full paths (t31-ocr11-17): the bare
                 * 'phpunit.cache/cached.xml' fragment was satisfied by
                 * the DOTTED twin's line alone ('.phpunit.cache/
                 * cached.xml' CONTAINS it), so the dotless spelling
                 * the round fixed was never independently pinned — a
                 * dotless-miss regression passed. The refusal line
                 * quotes the entry name; the quote is the boundary.
                 */
                $this->assertStringContainsString('"deventry-demo/phpunit.cache/cached.xml"', $report, 'The DOTLESS cache spelling is named on its own line — never as the dotted twin\'s substring.');
                $this->assertStringContainsString('"deventry-demo/.phpunit.cache/cached.xml"', $report, 'The dotted cache spelling is named on its own line.');
                $this->assertStringContainsString('"deventry-demo/webpack.config.js"', $report, 'The bundler config spelling is named on its own line.');
            } finally {
                @unlink($hostileZip);
                @unlink($hostileZip . '.sha256');
            }
        } finally {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $tempPlugin = self::scratchPath('deventry-case-test') . '/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
            /*
             * The exec-capability guard (t31-ocr26-9, the ocr20-5
             * doctrine): both verdict directions (this green arm and
             * the hostile-zip arm below) ride the inspector's internal
             * php -l spawn over the extracted tree; the build and
             * entry-name legs above already passed.
             */
            if (! self::canSpawnChildren()) {
                $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the one-verdict and hostile-zip inspect arms (the inspector\'s internal php -l spawn) cannot run; the build and entry-name legs above already passed.');
            }
            $this->assertSame(
                array(),
                wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-deventry-case')),
                'Build and inspect must agree on the case-folded vocabulary.'
            );

            // ONE verdict, the reverse: a HAND-CRAFTED zip carrying the
            // same case-variant spellings rejects, naming them.
            $hostileZip = self::distDir() . '/connectors-deventrycase-demo-1.0.0.zip';
            $hostile = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $hostile->open($hostileZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for hostile-zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $hostileZip,
                    var_export($opened, true)
                )
            );
            $head = "Plugin Name:       deventrycase-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       deventrycase-demo\nAuthor:            x\n";
            $hostile->addFromString('deventrycase-demo/deventrycase-demo.php', "<?php\n/**\n * {$head} */\ndefine( 'DEVENTRYCASE_DEMO_VERSION', '1.0.0' );\n");
            foreach (array( 'deventrycase-demo/Tests/Bootstrap.php', 'deventrycase-demo/Build.json', 'deventrycase-demo/VENDOR/lib.php', 'deventrycase-demo/vendor /acme/DevDependency.php' ) as $entry) {
                $hostile->addFromString($entry, 'x');
            }
            $hostile->close();
            try {
                $violations = wp_connectors_inspect_artifact($hostileZip, self::scratchPath('inspect-deventry-case-hostile'));
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
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        /*
         * The exec-capability guard (OCR round 17, t31-ocr17-4, the
         * t31-ocr16-12 doctrine over this child-process consumer): the
         * spawn escapes its arguments through escapeshellarg(), and on
         * a disable_functions host the first escaped argument was a
         * fatal undefined-function \Error (driven at HEAD under
         * -d disable_functions=escapeshellarg: 'Error: Call to
         * undefined function escapeshellarg()' at the spawn, the test
         * ERRORING before a single verdict) instead of the visible
         * skip the doctrine mandates — the same two-function probe
         * the suite's other shell consumers already carry
         * (SharedOAuthContractsHttpTest, the localedef legs). The
         * round's verifier pass drove the probe's own gap (closed
         * in-round, t31-ocr17-10): this consumer SPAWNS through
         * proc_open(), which the established pair never consulted —
         * under -d disable_functions=proc_open the guard passed and
         * the very first spawn fataled — so the probe names the
         * spawn function this leg actually rides too.
         */
        if (! self::canSpawnChildren('proc_open')) {
            $this->markTestSkipped('This host has exec/escapeshellarg/proc_open in disable_functions — the concurrent child builds cannot be spawned; the manifest-merge race half did not run.');
        }
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
            $unspawned = array();
            foreach (array_keys($connectors) as $slug) {
                $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=' . escapeshellarg($slug);
                /*
                 * The spawn is GATED and the pipes RESET per iteration
                 * (t31-ocr10-13): proc_open() returns resource|false —
                 * an ungated false left a null handle whose proc_close()
                 * and fclose() warnings confused the leg, and $pipes
                 * carried the PRIOR iteration's descriptors, so a
                 * failed spawn double-closed the previous child's pipe.
                 *
                 * The spawn verdict is COLLECTED here and asserted
                 * after the reap (t31-ocr18-3, the t31-ocr4-7 doctrine
                 * over the SPAWN loop itself): an assertIsResource()
                 * mid-loop aborted the foreach with the earlier
                 * children still live — un-reaped builds kept writing
                 * into the scratch repo the outer finally then rrmdirs
                 * underneath them.
                 */
                $pipes = array();
                $handle = proc_open(
                    $command,
                    array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
                    $pipes
                );
                if (! is_resource($handle)) {
                    $unspawned[ $slug ] = $command;
                    continue;
                }
                fclose($pipes[0]);
                $handles[ $slug ] = $handle;
            }

            // Close/collect EVERY exit before asserting (t31-ocr4-7): an
            // assertion inside the loop aborted the foreach and leaked
            // the remaining children — un-reaped builds kept writing
            // into the scratch repo the outer finally then rrmdirs, a
            // failing leg racing its own cleanup.
            $exits = array();
            foreach ($handles as $slug => $handle) {
                $exits[ $slug ] = proc_close($handle);
            }
            // Spawn verdicts assert after the reap too (t31-ocr18-3):
            // every child has finished, so the environmental refusal
            // names its slugs without racing anyone's cleanup.
            $this->assertSame(
                array(),
                $unspawned,
                'Every concurrent build must spawn — an unspawnable leg is environmental, never a silent pass (proc_open refused: ' . implode('; ', $unspawned) . ').'
            );
            foreach ($exits as $slug => $exit) {
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
            WpHarness::releaseScratch($repo);
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
     * (`.stage-<slug>-<pid>`, and RANDOM-suffixed since t31-ocr42-2 —
     * unpredictable, never pre-plantable), the finally releases exactly
     * the run's own tree, and the startup sweep reclaims only
     * DEAD-process orphans of the SAME plugin — a live run's tree is
     * never touched.
     */
    public function testConcurrentSamePluginBuildsKeepTheirStageTreesAndDeadOnesAreSwept(): void
    {
        /*
         * The exec-capability guard (t31-ocr21-2, the ocr17-10
         * three-function pair — this consumer SPAWNS through
         * proc_open(), both part 1's synchronized pair and part 2's
         * 60s live sibling): on a disable_functions host the first
         * escaped argument was an undefined-function \Error mid-test.
         */
        if (! self::canSpawnChildren('proc_open')) {
            $this->markTestSkipped('This host has exec/escapeshellarg/proc_open in disable_functions — the same-plugin concurrent pair and the stage-sweep legs cannot run (both spawn through proc_open).');
        }
        // Part 1, end-to-end through the CLI entry: two synchronized
        // builds of the SAME plugin both exit 0 and no stage tree of
        // any pid survives the pair (pre-fix, the shared name made the
        // pair racy — B's startup rrmdir of A's in-flight tree).
        $repo = $this->makeBuildCliRepo(array('race-same-demo' => true));

        try {
            $handles = array();
            $unspawned = array();
            for ($i = 0; $i < 2; ++$i) {
                /*
                 * The spawn is GATED and the pipes RESET per iteration
                 * (t31-ocr11-8, the t31-ocr10-13 doctrine on this
                 * loop's own spawns): proc_open() returns
                 * resource|false — an ungated false reached the reaping
                 * loop's proc_close() as a TypeError under PHP 8, an
                 * engine vocabulary in place of the environmental
                 * verdict the gate names.
                 *
                 * The spawn verdict is COLLECTED here and asserted
                 * after the reap (t31-ocr18-3, the t31-ocr4-7 doctrine
                 * over the SPAWN loop itself): the mid-loop
                 * assertIsResource() aborted the for with the first
                 * child still live, writing into the repo the finally
                 * rrmdirs underneath it.
                 */
                $pipes = array();
                $handle = proc_open(
                    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=race-same-demo',
                    array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
                    $pipes
                );
                if (! is_resource($handle)) {
                    $unspawned[] = $i;
                    continue;
                }
                $handles[] = $handle;
            }

            // Same shape as the manifest-race leg above (t31-ocr4-7):
            // collect both exits, assert afterward — never leak a child
            // by aborting the reaping loop. The spawn verdicts assert
            // here too (t31-ocr18-3): both children have finished.
            $exits = array();
            foreach ($handles as $handle) {
                $exits[] = proc_close($handle);
            }
            $this->assertSame(
                array(),
                $unspawned,
                'Both same-plugin concurrent builds must spawn — an unspawnable leg is environmental, never a proc_close() TypeError on a false (proc_open refused spawn #' . implode(', #', $unspawned) . ').'
            );
            foreach ($exits as $exit) {
                $this->assertSame(0, $exit, 'A concurrent same-plugin build must survive its sibling: the stage trees are pid-disjoint.');
            }
            $this->assertSame(array(), glob($repo . '/dist/.stage-race-same-demo*') ?: array(), 'No stage tree of any pid may survive the pair.');
            $this->assertStringContainsString('connectors-race-same-demo-1.0.0.zip  ', (string) file_get_contents($repo . '/dist/checksums.txt'));
        } finally {
            WpHarness::releaseScratch($repo);
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
        try {
            /*
             * $live initializes NULL before the spawn (t31-ocr21 pass,
             * verifier sc-1): proc_open() reports every environmental
             * refusal as an E_WARNING plus false, and under this
             * harness's failOnWarning the warning THROWS at the call —
             * before the assignment — so an uninitialized $live reached
             * the finally's null-guard as an undefined-variable error
             * thrown FROM the finally, unwinding past the rrmdir the
             * try exists to reach (the verifier's exact-shape probe:
             * the sentinel tree outlives the run). Null-initialized,
             * the warn-throw unwinds through the reap-guard (nothing
             * spawned to reap) into the rrmdir on every exit path.
             */
            $live = null;
            $live_pipes = array();
            $live = proc_open(
                'exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('file_put_contents(' . var_export($pid_file, true) . ', (string) getmypid()); sleep(60);'),
                array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
                $live_pipes
            );
            /*
             * The spawn is GATED (t31-ocr11-8, the t31-ocr10-13
             * doctrine) and the gate rides INSIDE the try that owns
             * the scratch tree (t31-ocr21-3, the t31-ocr16-14
             * staging discipline): an ungated false reached part 3's
             * proc_terminate() as a TypeError, and the gate's own
             * throw sat ABOVE the try — an environmental spawn
             * refusal (fork exhaustion, proc_open false) leaked the
             * whole scratch tree; inside, the finally reclaims it on
             * the verdict's way out.
             */
            $this->assertIsResource($live, 'The live sibling run must spawn — the sweep legs judge a live process, never a false.');

            $deadline = microtime(true) + 10.0;
            while (! is_file($pid_file) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($pid_file, 'The spawned live run must publish its pid.');
            $live_pid = (int) file_get_contents($pid_file);

            /*
             * The staging-temp CENSUS (OCR round 26, t31-ocr26-8):
             * every staging spelling the build lands carries the
             * pid — `.stage-<slug>-<pid>-<rand>` and `.<zip>.tmp-<pid>…`
             * (the part-1/part-2 legs below), and the MANIFEST
             * staging temp, pid-less since its t31-r5-S birth while
             * the sweep's own doc note carved it out as
             * unattributable. The name pins through the staging
             * owner itself (a successful build consumes the temp by
             * rename, so the name is only observable at the seam).
             */
            $stage_manifest = new ReflectionMethod(WpConnectorsBuild::class, 'stageManifest');
            $staged_manifest = $stage_manifest->invoke(null, $scratch . '/dist', $scratch . '/dist/checksums.txt', 'connectors-stage-demo-1.0.0.zip', str_repeat('a', 64));
            $this->assertMatchesRegularExpression(
                '/^\.checksums-' . getmypid() . '-[A-Za-z0-9]{1,}$/',
                basename((string) $staged_manifest),
                'The manifest staging temp is PID-NAMED — the sweep\'s crashed-run charter owns every staging temp the build lands.'
            );
            unlink($staged_manifest);

            // The live sibling's in-flight tree, a dead-pid orphan of the
            // same plugin (999999999 exceeds every Linux pid_max), a
            // pid-less foreign spelling, another plugin's dead-pid
            // orphan, and the crashed-run TEMP spellings (verifier round
            // t31-r10-13: the zip temp, its sidecar twin, and libzip's
            // in-window .part spelling — dead pid swept, live pid kept).
            mkdir($scratch . '/dist/.stage-stage-demo-' . $live_pid . '/stage-demo', 0755, true);
            file_put_contents($scratch . '/dist/.stage-stage-demo-' . $live_pid . '/stage-demo/inflight.txt', 'run A mid-flight');
            mkdir($scratch . '/dist/.stage-stage-demo-999999999', 0755, true);
            /*
             * The RANDOM-SUFFIXED dead-pid orphan (OCR round 42,
             * t31-ocr42-2): the current stage spelling
             * `.stage-<slug>-<pid>-<rand>` — the suffix rides the
             * sweep's stale-detection pattern tail-optionally, so the
             * crashed-run charter keeps owning it (red at HEAD: the
             * pid-anchored `$` pattern never matched the tail and the
             * orphan survived the sweep forever).
             */
            mkdir($scratch . '/dist/.stage-stage-demo-999999998-' . bin2hex(random_bytes(8)), 0755, true);
            mkdir($scratch . '/dist/.stage-stage-demo', 0755, true);
            mkdir($scratch . '/dist/.stage-other-demo-999999999', 0755, true);
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997', 'half a zip');
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.sha256', 'half a sidecar');
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.acce0w.part', 'libzip window');
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-' . $live_pid, 'live run temp');
            /*
             * The RANDOM-SUFFIXED dead-pid ZIP temp (OCR round 43,
             * t31-ocr43-2 — the r42-2 twin sweep): the current temp
             * spelling `.tmp-<pid>-<rand>` rides the sweep's
             * stale-detection pattern tail-optionally, so the
             * crashed-run charter keeps owning it (red at HEAD: the
             * pid-anchored tail never matched the suffix and the
             * orphan survived the sweep forever).
             */
            file_put_contents($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999996-' . bin2hex(random_bytes(8)), 'half a zip, new spelling');
            file_put_contents($scratch . '/dist/.checksums-orphan', 'pid-less manifest staging temp');
            // The manifest temps on the same charter (t31-ocr26-8):
            // dead pid swept, live pid kept, pid-less legacy alone.
            file_put_contents($scratch . '/dist/.checksums-999999996-orphan', 'dead run manifest staging temp');
            file_put_contents($scratch . '/dist/.checksums-' . $live_pid . '-inflight', 'live run manifest staging temp');

            // Run "B": builds green BESIDE the live sibling.
            WpConnectorsBuild::buildPlugin($scratch . '/plugin/stage-demo', $scratch . '/dist');

            $this->assertFileExists($scratch . '/dist/.stage-stage-demo-' . $live_pid . '/stage-demo/inflight.txt', 'A live run\'s stage tree is never touched by a sibling build.');
            $this->assertDirectoryDoesNotExist($scratch . '/dist/.stage-stage-demo-999999999', 'A dead-pid orphan of the plugin is swept by the next build — never orphaned forever.');
            $this->assertSame(
                array(),
                glob($scratch . '/dist/.stage-stage-demo-999999998-*') ?: array(),
                'A RANDOM-SUFFIXED dead-pid orphan is swept on the same charter — the unpredictability fix never weakens the crashed-run reclaim (t31-ocr42-2).'
            );
            $this->assertDirectoryExists($scratch . '/dist/.stage-stage-demo', 'A pid-less foreign spelling is left alone (nothing running this code creates it).');
            $this->assertDirectoryExists($scratch . '/dist/.stage-other-demo-999999999', 'Another plugin\'s stage dirs are that plugin\'s sweep\'s to reclaim.');
            /*
             * The run's OWN stage name is unpredictable since t31-ocr42-2
             * (the random suffix), so the teardown pins by GLOB: nothing
             * of this plugin survives beside the live sibling's tree.
             */
            $this->assertSame(
                array( $scratch . '/dist/.stage-stage-demo-' . $live_pid ),
                glob($scratch . '/dist/.stage-stage-demo-*') ?: array(),
                'The run\'s own random-suffixed stage tree tears down on success — the live sibling\'s tree alone survives the pair.'
            );
            $this->assertFileDoesNotExist($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997', 'A dead-pid zip temp is reclaimed — the crashed-run charter covers the temps too.');
            $this->assertFileDoesNotExist($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.sha256', 'A dead-pid sidecar temp is reclaimed.');
            $this->assertFileDoesNotExist($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999997.acce0w.part', 'A dead-pid libzip .part temp is reclaimed.');
            $this->assertSame(
                array(),
                glob($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-999999996-*') ?: array(),
                'A RANDOM-SUFFIXED dead-pid zip temp is swept on the same charter — the unpredictability fix never weakens the crashed-run reclaim (t31-ocr43-2).'
            );
            $this->assertFileExists($scratch . '/dist/.connectors-stage-demo-1.0.0.zip.tmp-' . $live_pid, 'A LIVE run\'s temp is never touched by a sibling build.');
            $this->assertFileExists($scratch . '/dist/.checksums-orphan', 'A pid-less manifest staging temp is unattributable — left alone, never raced.');
            $this->assertFileDoesNotExist($scratch . '/dist/.checksums-999999996-orphan', 'A dead-pid manifest staging temp is reclaimed — the crashed-run charter covers it since it carries the pid.');
            $this->assertFileExists($scratch . '/dist/.checksums-' . $live_pid . '-inflight', 'A LIVE run\'s manifest staging temp is never touched by a sibling build.');

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
            $this->assertFileDoesNotExist($scratch . '/dist/.checksums-' . $live_pid . '-inflight', 'A manifest staging temp whose owning process died is reclaimed by the next build — the crashed-run charter, one temp class further.');

            /*
             * Part 4, the symlink legs (verifier round t31-r10-10): a
             * matching-named SYMLINK at a dead pid is never deleted
             * THROUGH (is_dir follows links; the sweep would have
             * emptied the target tree, reproduced end-to-end by the
             * verifier), and a link at a GUESSABLE stage spelling is
             * neither the run's own name nor its business (the
             * own-name refusal the leg once drove is unreachable by
             * prediction since t31-ocr42-2 — see the own-name leg
             * below). The legs ride the CAPABILITY probe
             * (t31-ocr11-8): a bare symlink() call errors the suite on
             * exactly the hosts without the privilege (failOnWarning),
             * and the skip is visible, naming what already passed.
             */
            if (! self::canSymlink()) {
                $this->markTestSkipped('This host cannot create symlinks — the stage-sweep link legs (part 4) did not run (parts 1-3 above already passed).');
            }
            $victim = $scratch . '/victim';
            mkdir($victim . '/inner', 0755, true);
            file_put_contents($victim . '/inner/keep.txt', 'survivor');
            file_put_contents($victim . '/keep2.txt', 'survivor');
            symlink($victim, $scratch . '/dist/.stage-stage-demo-999999998');

            WpConnectorsBuild::buildPlugin($scratch . '/plugin/stage-demo', $scratch . '/dist');

            $this->assertFileExists($victim . '/inner/keep.txt', 'The sweep never deletes through a symlink — the target tree must survive intact.');
            $this->assertFileExists($victim . '/keep2.txt', 'The sweep never deletes through a symlink — the target tree must survive intact.');
            $this->assertTrue(is_link($scratch . '/dist/.stage-stage-demo-999999998'), 'The sweep leaves a symlinked stage-shaped entry standing (it is never this code\'s product).');

            /*
             * The own-name leg since t31-ocr42-2: the run's stage name
             * is UNPREDICTABLE (the random suffix), so a plant can no
             * longer sit at it — the link-at-own-name refusal this leg
             * once drove end-to-end is a belt no plant can reach BY
             * CONSTRUCTION (the fix's own point), and the drivable fact
             * INVERTS: a link at every GUESSABLE spelling (the pid-only
             * one planted here) neither blocks the build nor is touched
             * by it — the run stages under its own random name, and the
             * link and its target stand byte-untouched at exit 0.
             */
            symlink($victim, $scratch . '/dist/.stage-stage-demo-' . getmypid());
            WpConnectorsBuild::buildPlugin($scratch . '/plugin/stage-demo', $scratch . '/dist');
            $this->assertTrue(is_link($scratch . '/dist/.stage-stage-demo-' . getmypid()), 'A link at a GUESSABLE stage spelling stands untouched — the run\'s own unpredictable name never collides with a plant (t31-ocr42-2).');
            $this->assertFileExists($victim . '/inner/keep.txt', 'The build never staged through the guessed link — its own random-named tree carried every byte.');
            unlink($scratch . '/dist/.stage-stage-demo-999999998');
            unlink($scratch . '/dist/.stage-stage-demo-' . getmypid());
            $this->assertFileExists($victim . '/keep2.txt', 'The untouched link target survived both link legs.');
        } finally {
            if (null !== $live && is_resource($live)) {
                proc_terminate($live);
                proc_close($live);
            }
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-1 pin (t31-ocr1-3): the finally's stage teardown was
     * the ONE removal seam without a link guard — the r10-10 doctrine
     * ("never delete through a link") covered the sweep seam and the
     * build-start refusal, but a mid-build swap of the stage directory
     * for a symlink hands rrmdir() a LINK at the root: is_dir()
     * follows it, the RecursiveDirectoryIterator constructed on the
     * linked path walks the TARGET tree, and the loop empties it. The
     * guard lives in rrmdir() itself (the single-owner fix — every
     * call site inherits it); this pin drives the removal seam
     * directly with both shapes: a link AT the root (the stage-teardown
     * class) and a link INSIDE the tree (a child whose isDir() would
     * otherwise take the rmdir branch). The teardown class is not
     * drivable end-to-end without racing the build's own finally, so
     * the seam is probed the processIsAlive way.
     */
    public function testTheRemovalSeamNeverDeletesThroughALink(): void
    {
        // The capability probe (t31-ocr10-14): function_exists is NOT
        // the signal — symlink() exists on Windows without the
        // privilege to use it; the FALSE RETURN is.
        if (! self::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the removal-seam link legs cannot run on it.');
        }
        $scratch = self::distDir() . '/.rrmdir-link-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch, 0755, true);
        $remove = new ReflectionMethod(WpConnectorsBuild::class, 'rrmdir');

        try {
            // The root-link leg: the stage-teardown shape. The target
            // tree must survive intact and the link must stand.
            $victim = $scratch . '/victim';
            mkdir($victim . '/inner', 0755, true);
            file_put_contents($victim . '/inner/keep.txt', 'survivor');
            file_put_contents($victim . '/keep2.txt', 'survivor');
            $rootLink = $scratch . '/stage-link';
            symlink($victim, $rootLink);

            $remove->invoke(null, $rootLink);

            $this->assertFileExists($victim . '/inner/keep.txt', 'A link at the removal root is never deleted through — the target tree must survive intact.');
            $this->assertFileExists($victim . '/keep2.txt', 'A link at the removal root is never deleted through — the target tree must survive intact.');
            $this->assertTrue(is_link($rootLink), 'A link at the removal root stands exactly where it is.');

            // The child-link leg: a link INSIDE a tree the removal owns
            // is removed AS ITSELF (unlink), never descended into, never
            // rmdir'd through — and the real siblings still go.
            $tree = $scratch . '/owned-tree';
            mkdir($tree, 0755, true);
            file_put_contents($tree . '/real.txt', 'goes');
            symlink($victim, $tree . '/child-link');

            $remove->invoke(null, $tree);

            $this->assertFileExists($victim . '/inner/keep.txt', 'A linked child never drags its target into the removal — the target tree survives.');
            $this->assertDirectoryDoesNotExist($tree, 'The owned tree itself is removed, link and all.');

            /*
             * The HARNESS twin (verifier round t31-ocr1-11, both lenses
             * independently): WpHarness::rrmdir() became the one
             * test-side removal owner in this same round without the
             * link doctrine its build-side twin just gained — and the
             * tests' predictable /tmp scratch names make a planted root
             * link pre-plantable with no race. Same legs, same doctrine:
             * a root link stands untouched, the target tree survives.
             */
            $harnessRootLink = $scratch . '/harness-stage-link';
            symlink($victim, $harnessRootLink);

            WpHarness::rrmdir($harnessRootLink);

            $this->assertFileExists($victim . '/inner/keep.txt', 'The harness removal twin never deletes through a root link either — the target tree survives.');
            $this->assertFileExists($victim . '/keep2.txt', 'The harness removal twin never deletes through a root link either.');
            $this->assertTrue(is_link($harnessRootLink), 'A link at the harness removal root stands exactly where it is.');

            /*
             * The trailing-slash spelling (t31-ocr8-2): is_link()
             * resolves THROUGH a trailing slash (lstat never sees the
             * link), so the pre-fix guard passed and the walk emptied
             * the TARGET tree — the exact pre-plant shape. The path is
             * normalized before the probes now; the target survives,
             * the link stands.
             */
            WpHarness::rrmdir($harnessRootLink . '/');

            $this->assertFileExists($victim . '/inner/keep.txt', 'A TRAILING-SLASH link at the removal root is still a link — never deleted through.');
            $this->assertFileExists($victim . '/keep2.txt', 'A TRAILING-SLASH link at the removal root is still a link — the target tree survives.');
            $this->assertTrue(is_link($harnessRootLink), 'The trailing-slash spelling does not smuggle the link past the root guard.');

            /*
             * The '/.' spelling (t31-ocr8-13, the verifier lens's
             * one-spelling-over twin): stat resolves through a
             * trailing '/.' exactly as it does through a trailing
             * slash — pre-fix the walk EMPTIED the victim tree through
             * this spelling. Same verdict as the slash leg.
             */
            WpHarness::rrmdir($harnessRootLink . '/.');

            $this->assertFileExists($victim . '/inner/keep.txt', 'A TRAILING-/. link at the removal root is still a link — never deleted through.');
            $this->assertFileExists($victim . '/keep2.txt', 'A TRAILING-/. link at the removal root is still a link — the target tree survives.');
            $this->assertTrue(is_link($harnessRootLink), 'The \'/.\' spelling does not smuggle the link past the root guard either.');

            /*
             * The '/..' spelling (t31-ocr9-1, refuting round 8's
             * "names the parent" carve-out with driven evidence): the
             * parent it names is the LINK TARGET'S parent — a strictly
             * LARGER blast radius than the target (driven pre-fix:
             * the walk entered that parent tree and removed entries
             * through the link spelling before breaking). The probe
             * strips this third tail too: the walk never runs, the
             * victim stands, and the parent-of-the-target ($scratch
             * itself here) stands with it.
             */
            WpHarness::rrmdir($harnessRootLink . '/..');

            $this->assertFileExists($victim . '/inner/keep.txt', 'A TRAILING-/.. link at the removal root never deletes through to the target\'s PARENT.');
            $this->assertFileExists($victim . '/keep2.txt', 'A TRAILING-/.. link at the removal root — the target tree survives.');
            $this->assertTrue(is_link($harnessRootLink), 'The \'/..\' spelling does not smuggle the link past the root guard either.');
            $this->assertDirectoryExists($scratch, 'The parent OF THE TARGET — the directory the \'..\' names — survives too: the walk never ran.');

            /*
             * The MID-PATH link (OCR round 17, t31-ocr17-2): the probe
             * once stripped only the TRAILING tails, so a link with
             * components after it routed the is_link() probe THROUGH
             * it — stat followed the link to the real 'sub' inside the
             * victim and the walk then deleted the victim's entries
             * through the link spelling, dying mid-flight in the SPL
             * iterator's vocabulary (driven red at HEAD, both halves).
             * The probe resolves the FULL component chain now: a link
             * wherever it sits is the link at the root, same skip, and
             * the victim stands untouched.
             */
            mkdir($victim . '/sub', 0755, true);
            file_put_contents($victim . '/sub/keep3.txt', 'survivor');
            $midLink = $scratch . '/mid-path-link';
            symlink($victim, $midLink);

            WpHarness::rrmdir($midLink . '/sub/..');

            $this->assertFileExists($victim . '/inner/keep.txt', 'A MID-PATH link never routes the removal through it — the victim tree survives.');
            $this->assertFileExists($victim . '/keep2.txt', 'A MID-PATH link never routes the removal through it.');
            $this->assertFileExists($victim . '/sub/keep3.txt', 'The components named AFTER the link survive too — the walk never ran.');
            $this->assertTrue(is_link($midLink), 'The mid-path link stands exactly where it is.');

            /*
             * The THIRD twin (t31-ocr9-10, the round-9 refutation
             * lens over the r8/r9 link doctrine): the build side and
             * the harness side carried the root-link guard; the
             * inspector's own removal owner skipped it entirely —
             * driven pre-fix: a link planted at its predictable
             * workDir name EMPTIED the victim tree through this
             * owner. Same doctrine, third leg: the root link stands,
             * the target tree survives.
             */
            $inspectRootLink = $scratch . '/inspect-stage-link';
            symlink($victim, $inspectRootLink);

            wp_connectors_inspect_rrmdir($inspectRootLink);

            $this->assertFileExists($victim . '/inner/keep.txt', 'The inspector removal twin never deletes through a root link — the target tree survives.');
            $this->assertFileExists($victim . '/keep2.txt', 'The inspector removal twin never deletes through a root link either.');
            $this->assertTrue(is_link($inspectRootLink), 'A link at the inspector removal root stands exactly where it is.');

            /*
             * The inspector twin's TAIL spellings and ROOT clause
             * (t31-ocr10-15, the round-10 verifier's refutation lens —
             * r9-10's own ledgered re-open condition FIRED: the workDir
             * is a PUBLIC parameter of wp_connectors_inspect_artifact(),
             * so a caller-controlled spelling reaches this owner, and
             * driven pre-fix 'link/.' EMPTIED the victim tree past the
             * plain guard while '/' walked the filesystem root's
             * children). The link probe strips the stat-transparent
             * tails; the root collapses to a silent return — this
             * owner's vocabulary (a production finally never throws).
             */
            foreach (array('/', '/.', '/..') as $tail) {
                wp_connectors_inspect_rrmdir($inspectRootLink . $tail);
                $this->assertFileExists($victim . '/inner/keep.txt', "A '{$tail}' tail is not a disguise — the inspector twin never deletes through the link.");
                $this->assertTrue(is_link($inspectRootLink), "The '{$tail}' tail does not smuggle the link past the plain guard.");
            }

            /*
             * The '/..' tail over a REAL directory (OCR round 26,
             * t31-ocr26-3 — the link channel closed above, the real
             * twin now): the fences judge the STRIPPED probe while the
             * walk rode the caller's RAW spelling, and 'victim/..'
             * resolves to the victim's PARENT — driven red at HEAD,
             * this fire EMPTIED the scratch tree through the tail
             * (every fence passed: no link, realpath not the root).
             * The walk stays inside the caller-named root for every
             * tail spelling; a tail naming OUTSIDE it is never walked
             * (the silent return, the root clause's own vocabulary).
             */
            wp_connectors_inspect_rrmdir($scratch . '/victim/..');
            $this->assertFileExists($victim . '/inner/keep.txt', "A '/..' tail over a REAL directory never walks the PARENT tree — the tail names outside the caller-named root.");
            $this->assertFileExists($victim . '/keep2.txt', "A '/..' tail over a REAL directory never walks the PARENT tree — the victim survives whole.");
            $this->assertDirectoryExists($scratch, "The PARENT the '/..' names is never this owner's territory — the scratch tree survives the tail-spelled fire whole.");
            /*
             * The BACKSLASH tail, both spellings fenced (OCR round 29,
             * t31-ocr29-3): the strip loop fenced only '/'-spelled
             * tails, so the '\'-spelled twin — the NATIVE separator
             * spelling on a host where '\' joins paths, the spelling
             * that names the PARENT there — survived the probe. The
             * drive creates a REAL directory whose own NAME ends in
             * the backslash-tail bytes (an inert odd filename on this
             * POSIX host, so the fire is real at HEAD — driven red:
             * the walk EMPTIED the backslash-named tree) and the
             * fence must refuse it anyway: the fence judges the
             * spelling CLASS, never the host it runs on, and residue
             * for the pathological backslash-named entry beats the
             * parent walk the same bytes ride elsewhere. The
             * Windows-native parent-walk itself is construction-
             * evident (DIRECTORY_SEPARATOR, a constant no sim flips).
             */
            $backslashNamed = $scratch . '/kept-tail\\..';
            mkdir($backslashNamed, 0755, true);
            file_put_contents($backslashNamed . '/survivor.txt', 'survivor');
            wp_connectors_inspect_rrmdir($backslashNamed);
            $this->assertFileExists($backslashNamed . '/survivor.txt', "A '\\..' tail answers the refusal on the POSIX host too — the fence judges the spelling class, and the backslash-named tree it would have emptied (driven red at HEAD) survives whole.");
            $this->assertDirectoryExists($scratch, 'The PARENT the backslash tail names on a separator-host is never walked here either — the scratch tree survives the backslash-tailed fire whole.');
            /*
             * The WHOLE-PATH degenerates (OCR round 27, t31-ocr27-2):
             * '..', './', and '.' carry no '/..' tail, so the strip loop
             * left them unflagged — and driven pre-fix from a child CWD
             * rrmdir('..') walked and EMPTIED the parent of the process
             * CWD (the CWD's own tree included). A degenerate whole-path
             * names no caller-named root at all; every spelling refuses,
             * and the CWD's parent tree survives each fire whole. The
             * fires run from a CONTROLLED child CWD (the degenerate is
             * CWD-relative — the only spelling class this owner cannot
             * be handed an absolute form of), the CWD restored in the
             * finally.
             */
            $degenerate = $scratch . '/degenerate-parent';
            mkdir($degenerate . '/child', 0755, true);
            file_put_contents($degenerate . '/survivor.txt', 'survivor');
            $cwd = (string) getcwd();
            try {
                chdir($degenerate . '/child');
                wp_connectors_inspect_rrmdir('.');
                $this->assertFileExists($degenerate . '/survivor.txt', "The whole-path degenerate '.' never walks the CALLER CWD's parent — the spelling names no caller-named root.");
                wp_connectors_inspect_rrmdir('./');
                $this->assertFileExists($degenerate . '/survivor.txt', "The whole-path degenerate './' never walks the CALLER CWD's parent either.");
                wp_connectors_inspect_rrmdir('..');
                $this->assertFileExists($degenerate . '/survivor.txt', "The whole-path degenerate '..' never walks the parent of the CALLER'S CWD — driven red at HEAD: this fire emptied the parent tree through the spelling.");
                $this->assertDirectoryExists($degenerate . '/child', 'The CWD tree itself survives the degenerate fires — the walk never ran.');
                /*
                 * The BARE DRIVE-LETTER degenerate (OCR round 39,
                 * t31-ocr39-2): the whole-path fence refused '', '.',
                 * and '..' but 'C:' survived it — rtrim keeps the
                 * spelling (no trailing separator, no tail to strip)
                 * and is_dir('C:') is TRUE on Windows: the walk names
                 * the DRIVE ROOT, a directory the caller never named,
                 * the exact class this fence exists to kill. The leg
                 * creates a REAL directory whose own NAME is the bare
                 * drive letter (a legal, if odd, filename on this
                 * POSIX host, so the fire is real at HEAD — driven
                 * red: the walk EMPTIED the drive-letter-named tree)
                 * and the fence refuses it anyway: the fence judges
                 * the spelling CLASS, never the host it runs on, and
                 * residue for the pathological drive-letter-named
                 * entry beats the drive-root walk the same bytes ride
                 * on a separator host (the ocr29-3 trade). The 'C:/'
                 * twin folds through the strip loop's rtrim into the
                 * same bare spelling — one fence, both spellings. The
                 * Windows-native drive-root walk itself is
                 * construction-evident (DIRECTORY_SEPARATOR, the
                 * ocr28-3 doctrine), the fence's drive-root vocabulary
                 * the ocr33-5 spelling set below already names.
                 */
                mkdir('C:', 0755, true);
                file_put_contents('C:/survivor.txt', 'drive-letter survivor');
                wp_connectors_inspect_rrmdir('C:');
                $this->assertFileExists($degenerate . '/child/C:/survivor.txt', "The bare drive-letter spelling 'C:' answers the refusal family — a degenerate whole-path names no caller-named root, and its is_dir() truth on a separator host names the DRIVE ROOT (driven red at HEAD: the walk emptied the drive-letter-named tree).");
                wp_connectors_inspect_rrmdir('C:/');
                $this->assertFileExists($degenerate . '/child/C:/survivor.txt', "The 'C:/' spelling folds through the strip loop's rtrim into the same bare drive letter — the same refusal, both spellings one fence.");
            } finally {
                chdir($cwd);
            }
            /*
             * The root spellings are ROOT-ANCHORED, never a temp-parent
             * '..' (OCR round 11, t31-ocr11-2): POSIX resolves '.' and
             * '..' AT the root to the root itself on every host, so
             * '/', '/.', and '/..' collapse harmlessly everywhere —
             * while sys_get_temp_dir().'/..' collapses to '/' only
             * where the temp dir sits directly beneath it. On hosts
             * whose temp tree is deep (macOS TMPDIR=/var/folders/…/T/)
             * that spelling resolved to the temp dir's REAL parent,
             * passed the production root guard — which matches its
             * contract — and the TEST ITSELF walked and deleted the
             * host's tree: the test carried the portability doctrine
             * the production guard already kept. The sentinel below
             * (in the real temp tree) and the scratch assertions after
             * pin that nothing outside the controlled trees is touched.
             */
            /*
             * PROBE-BEFORE-FIRE (OCR round 25, t31-ocr25-4 — the
             * ocr4-1 doctrine extended to the destructive root fires;
             * boundary restated honestly by the round's verifier): the
             * '/'-anchored legs below — this silent-owner loop AND the
             * loud-owner loop under it — answer their safety to the
             * production guard alone, and a REGRESSED guard would walk
             * the filesystem root's children as this very test runs,
             * the sentinel assertions after the fire reading a
             * destroyed tree. The fires run only where the process
             * cannot WRITE the root directory: the root's FIRST level
             * is then unmutable (no child unlinked, no entry created
             * beside them), and the runner that writes the root (uid 0
             * through the DAC override, or a 0777-root host —
             * is_writable('/') is the capability probe, the
             * t31-ocr10-14 doctrine: the ANSWER is the signal) skips
             * visibly instead, the chmod-0000 skip's premise one shape
             * over. HONEST BOUNDARY (the verifier's driven refutation
             * of the first cut's wording): this bounds the FIRST
             * level, not the DEEP walk — a regressed guard recursing
             * past the root still destroys every USER-WRITABLE subtree
             * it can reach ($HOME, the repo checkout, /tmp) on any
             * host; the gate keeps the wholesale root-runner
             * destruction off CI and makes first-level mutation
             * impossible, while the genuinely sandboxed construction
             * (a scratch-rooted '/' via a user-namespace mount, a
             * dropped-capability child) stays the ledgered residual —
             * the guard itself remains the deep-walk safety, as the
             * legs' own docblocks state.
             */
            if (is_writable('/')) {
                $this->markTestSkipped('The destructive root-spelling fires (the silent inspector loop and the loud rrmdir loop alike) need a process that CANNOT write the filesystem root — this runner writes it (uid 0 / DAC override, the t31-ocr4-1 premise), and a regressed guard would destroy the host mid-test (t31-ocr25-4 probe-before-fire).');
            }
            $tmpSentinel = sys_get_temp_dir() . '/wpct-rrmdir-root-sentinel-' . getmypid();
            file_put_contents($tmpSentinel, 'sentinel');
            foreach (array('/', '/.', '/..') as $rootSpelling) {
                /*
                 * The safety net BEFORE each destructive leg (OCR round
                 * 20, t31-ocr20-4 — the ocr17-3 doctrine the harness
                 * twin's root-collapse legs below already carry): this
                 * owner's root clause is a SILENT return (the
                 * t31-ocr10-15 vocabulary — a production finally never
                 * throws), so these legs' safety rests ENTIRELY on the
                 * production collapse and a regressed guard would walk
                 * the root's children as this very test runs. The pin
                 * holds the refusal PRECONDITION itself: the spelling
                 * must resolve to the filesystem ROOT the guard names,
                 * so a spelling that resolved elsewhere (a platform
                 * normalization drift, a guard judging a different
                 * collapse) fails loudly BEFORE the removal is ever
                 * attempted.
                 */
                $this->assertSame('/', realpath($rootSpelling), "The leg's own precondition ({$rootSpelling}): the spelling resolves to the filesystem ROOT the silent root collapse guards — a spelling resolving elsewhere would point this leg's removal at the wrong tree.");
                $this->assertFileExists($tmpSentinel, "The canary stands BEFORE the fire ({$rootSpelling}) — the sentinel-intact assertion below is a live detector, never a post-hoc read over a tree the fire already ate (t31-ocr25-4).");
                wp_connectors_inspect_rrmdir($rootSpelling);
            }
            $this->assertFileExists($tmpSentinel, 'A root-collapsing spelling never walks — the universal tree is not a scratch dir.');
            unlink($tmpSentinel);
            $this->assertDirectoryExists($scratch, 'The pin\'s own scratch tree survives the inspector root-collapse legs.');

            /*
             * The ROOT collapse (t31-ocr10-1, the deletion twin of
             * copyTree's ocr9-9 mirror clause): the walk spelling keeps
             * a '/..' tail, so a scratch spelling collapsing to '/'
             * passed every guard and CHILD_FIRST deleted the root's
             * children (driven red pre-fix: rrmdir('/') walked into
             * unlink()/rmdir() over the filesystem root, and a
             * scratch/sub/.. spelling deleted the PARENT's entries
             * through the collapse). Every root-collapsing spelling
             * refuses LOUDLY now, naming the spelling; the walk never
             * runs.
             */
            foreach (array(
                'the literal root' => '/',
                'the root dotdot spelling' => '/..',
                /*
                 * The separators-only spellings (t31-ocr13-3):
                 * same_directory_spelling() collapsed '/.' and '//' to
                 * '' — names nothing, is_dir('') false, rrmdir()
                 * SILENTLY returned (red at HEAD: driven as a silent
                 * no-op, the refusal below never fired) — asymmetric
                 * with the loud refusals its siblings carry. They
                 * collapse to the root they name now and ride this
                 * same refusal.
                 */
                'the root dot spelling' => '/.',
                'the double-slash spelling' => '//',
            ) as $rootLabel => $rootSpelling) {
                /*
                 * The safety net BEFORE the destructive leg (OCR round
                 * 17, t31-ocr17-3): these legs' safety rests entirely
                 * on the production refusal — a regressed guard would
                 * walk the root's children as this very test runs. The
                 * pin holds the REFUSAL PRECONDITION itself: the
                 * spelling must resolve to the filesystem ROOT the
                 * guard names, so a spelling that resolved elsewhere
                 * (a platform normalization drift, a guard judging a
                 * different collapse) fails the pin loudly BEFORE the
                 * removal is ever attempted — the leg is only
                 * meaningful, and only safe to run, over the tree its
                 * refusal claims.
                 */
                $this->assertSame('/', realpath($rootSpelling), "The leg's own precondition ({$rootLabel}): the spelling resolves to the filesystem ROOT the guard refuses — a spelling resolving elsewhere would point this leg's removal at the wrong tree.");
                $refusal = $this->refusalOf(
                    fn() => WpHarness::rrmdir($rootSpelling),
                    "A root-collapsing spelling must refuse the removal loudly ({$rootLabel}), never delete the root's children.", \RuntimeException::class
                );
                $this->assertStringContainsString('collapses to the filesystem ROOT', $refusal->getMessage(), "The refusal names the root class ({$rootLabel}).");
                $this->assertStringContainsString($rootSpelling, $refusal->getMessage(), "The refusal names the spelling the caller passed ({$rootLabel}).");
            }
            $this->assertDirectoryExists($scratch, 'The pin\'s own scratch tree survives the root-collapse legs — the walk never ran.');
            $this->assertFileExists($victim . '/keep2.txt', 'The victim tree survives the root-collapse legs untouched.');

            /*
             * The guard-regression sim (t31-ocr25-4, cheap and
             * scratch-rooted): the same sentinel-intact shape the legs
             * above assert, driven against a walker with NO guard —
             * the canary DIES, proving the assertions are a live
             * detector for exactly the regression they pin (a
             * regressed production guard eats the sentinel the same
             * way, reddening the legs above — never asserting over an
             * already-destroyed tree).
             */
            $simRoot = $scratch . '/regress-sim-root';
            mkdir($simRoot . '/child', 0755, true);
            $simCanary = $simRoot . '/canary.txt';
            file_put_contents($simCanary, 'sentinel');
            $unguardedWalk = function (string $dir) use (&$unguardedWalk): void {
                foreach (scandir($dir) ?: array() as $entry) {
                    if ('.' === $entry || '..' === $entry) {
                        continue;
                    }
                    $path = $dir . '/' . $entry;
                    if (is_dir($path) && ! is_link($path)) {
                        $unguardedWalk($path);
                    }
                    @unlink($path);
                }
                @rmdir($dir);
            };
            $unguardedWalk($simRoot);
            $this->assertFileDoesNotExist($simCanary, 'Sim: the UNGUARDED walker eats the canary — the sentinel-intact assertions above are a live detector, never a vacuous truth.');
            $this->assertDirectoryDoesNotExist($simRoot, 'Sim: the unguarded walker completes the removal the guard exists to refuse.');

            /*
             * The DOTDOT-SPELLED real tree (OCR round 22, t31-ocr22-6;
             * the leg needs no link capability — it rides the rrmdir
             * pin battery's home): the walk spelling keeps a '/..'
             * tail, so rrmdir over a REAL tree spelled
             * 'parent/child/..' emptied the parent's children through
             * the collapse and then handed the final rmdir the walk
             * spelling — a resolution through a 'child' the walk
             * itself had removed, ENOENT under a warning, and the
             * PARENT stranded (driven red at HEAD: the tree emptied,
             * its top directory leaked per call). The final rmdir
             * receives the collapsed spelling now; the removal
             * completes whole over the spelling family.
             */
            mkdir($scratch . '/dotdot-src/sub', 0755, true);
            file_put_contents($scratch . '/dotdot-src/sub/x.txt', 'bytes');
            file_put_contents($scratch . '/dotdot-src/top.txt', 'bytes');
            WpHarness::rrmdir($scratch . '/dotdot-src/sub/..');
            $this->assertDirectoryDoesNotExist($scratch . '/dotdot-src', 'A \'/..\'-spelled real tree is removed WHOLE — the final rmdir names the walked directory itself, never the dead resolution through the tail the walk consumed.');

            /*
             * The verifier close (OCR round 22's refutation lens,
             * rd-1) — the ITEM-LEVEL dead resolution, the yield-order
             * shape: the walk once spelled every child pathname from
             * the caller's '/..'-bearing root, and when the tail's
             * own component is met FIRST the walk consumed it before
             * its later siblings — every item after it resolved
             * through the dead component and died ENOENT (driven at
             * the round's HEAD: five sibling files plus the parent
             * stranded). This runner's tmpfs answers readdir in
             * REVERSE-CREATION order (driven), so creating the
             * tail-dir LAST makes the walk meet it first — the exact
             * order that killed the siblings' spellings at HEAD. The
             * walk rides the collapsed root now: no pathname carries
             * a consumable '..', at any yield order.
             */
            mkdir($scratch . '/dotdot-order', 0755, true);
            for ($i = 0; $i < 5; ++$i) {
                file_put_contents($scratch . '/dotdot-order/f' . $i . '.txt', 'bytes');
            }
            mkdir($scratch . '/dotdot-order/sub', 0755, true);
            file_put_contents($scratch . '/dotdot-order/sub/x.txt', 'bytes');
            WpHarness::rrmdir($scratch . '/dotdot-order/sub/..');
            $this->assertDirectoryDoesNotExist($scratch . '/dotdot-order', 'A \'/..\'-spelled tree is removed WHOLE at every yield order — no item pathname resolves through a component the walk already consumed.');

            /*
             * The DOUBLE tail (the correctness lens's sc-1 over
             * t31-ocr22-6): 'sub/../..' — the walk's mid-flight
             * removal of sub killed every later '/..'-routed pathname
             * AND the final rmdir went 'not empty' (driven stranded
             * under both the round's and the pre-round harness — the
             * ocr22-6 commit's "complete removal over the '/..'
             * family" overreached its single-tail fix). The collapsed
             * walk owns the whole family now.
             */
            mkdir($scratch . '/dotdot-double/sub', 0755, true);
            file_put_contents($scratch . '/dotdot-double/sub/x.txt', 'bytes');
            file_put_contents($scratch . '/dotdot-double/top.txt', 'bytes');
            WpHarness::rrmdir($scratch . '/dotdot-double/sub/../..');
            $this->assertDirectoryDoesNotExist($scratch . '/dotdot-double', 'The DOUBLE tail is removed whole too — the collapsed walk owns the entire \'/..\' spelling family, single and double alike.');
        } finally {
            if (is_link($scratch . '/stage-link')) {
                unlink($scratch . '/stage-link');
            }
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-23 pin (t31-ocr23-2): the inspector's own removal owner
     * already SPELLS its verdict vocabulary in its docblock — "the
     * silent return (a production finally must not throw)" — and its
     * root clauses honor it (the link probes, the root collapse), but
     * the WALK below them still iterated a bare
     * RecursiveDirectoryIterator: a subdirectory the iterator cannot
     * OPEN mid-recursion (glm31-4's class) aborted the teardown of the
     * extraction work dir with an UNCAUGHT UnexpectedValueException —
     * the engine's vocabulary on the one seam whose contract names the
     * silent return. The walk honors the docblock now: the iteration
     * refusal degrades to the silent return (the removal up to the
     * refusal stands, the unopened subtree stays, wp_connectors_inspect_artifact()'s
     * verdict surface is untouched). The opendir probe is the capability
     * signal (glm17-16; uid 0 reads through mode 0000, t31-ocr4-1).
     */
    public function testTheInspectorRemovalTwinAnswersSilentlyOverAHostileTree(): void
    {
        $scratch = self::distDir() . '/.inspect-hostile-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/work', 0755, true);
        file_put_contents($scratch . '/work/plain.txt', 'extracted bytes');
        mkdir($scratch . '/work/locked/inner', 0755, true);
        file_put_contents($scratch . '/work/locked/inner/x.txt', 'bytes');
        chmod($scratch . '/work/locked', 0000);
        $probe = @opendir($scratch . '/work/locked');
        if (false !== $probe) {
            closedir($probe);
            chmod($scratch . '/work/locked', 0755);
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host opens chmod-0000 directories (uid 0 — t31-ocr4-1); the walk-hostile tree is unconstructible here.');
        }

        try {
            /*
             * Red at HEAD: the call THROWS the iterator's
             * UnexpectedValueException (the docblock's violated
             * contract). The pin holds the silent verdict — and what it
             * keeps: the unopened subtree stays inside the work root.
             * No pin on HOW MUCH was removed before the refusal: the
             * yield order is the filesystem's (this runner's tmpfs
             * meets the locked dir FIRST — the ocr22
             * order-dependence doctrine), so the partial removal is a
             * host fact, never the contract. Since t31-ocr24-3 the
             * refusal path runs the root rmdir as BEST-EFFORT — this
             * leg's root stays because the unopened subtree keeps it
             * non-empty, never because the rmdir was skipped.
             */
            wp_connectors_inspect_rrmdir($scratch . '/work');

            $this->assertDirectoryExists($scratch . '/work', 'The work root stands past a refused walk — the unopened subtree inside it keeps the best-effort root rmdir a no-op.');
            $this->assertDirectoryExists($scratch . '/work/locked', 'The unopened subtree stays exactly where it stood — the silent return leaves it for the OS temp sweep.');

            /*
             * The verifier close (rd-1, the CONSTRUCTION shape): the
             * first cut wrapped only the walk, and the iterator's own
             * CONSTRUCTOR over a locked removal ROOT threw through the
             * guard (driven at the round's HEAD) — the silent contract
             * owns the whole iteration seam, root shape included.
             */
            $lockedRoot = $scratch . '/locked-root';
            mkdir($lockedRoot . '/inner', 0755, true);
            file_put_contents($lockedRoot . '/inner/x.txt', 'bytes');
            chmod($lockedRoot, 0000);
            wp_connectors_inspect_rrmdir($lockedRoot);
            $this->assertDirectoryExists($lockedRoot, 'A locked removal ROOT answers the silent verdict too — the guard owns the construction, never the walk alone; the best-effort rmdir no-ops over its unopened children.');

            /*
             * OCR round 24 (t31-ocr24-3): the refusal path RECLAIMS the
             * root itself. An EMPTY locked root — the
             * construction-shape refusal over a root with nothing
             * inside it — falls through to the best-effort @rmdir now:
             * rmdir needs the PARENT's write bit, never the target's
             * read bit, so the root is reclaimed. Red at HEAD: the
             * catch returned before the rmdir — one leaked
             * unique-suffixed tree per refusal with no sweeper
             * anywhere (build's twin has the stage sweep; this owner's
             * extraction dirs are random-named, nothing ever revisits
             * them).
             */
            $lockedEmptyRoot = $scratch . '/locked-empty-root';
            mkdir($lockedEmptyRoot, 0755);
            chmod($lockedEmptyRoot, 0000);
            wp_connectors_inspect_rrmdir($lockedEmptyRoot);
            $this->assertDirectoryDoesNotExist($lockedEmptyRoot, 'An EMPTY locked removal root is reclaimed on the refusal path — rmdir needs the parent\'s write bit, never the target\'s read bit.');
        } finally {
            chmod($scratch . '/work/locked', 0755);
            @chmod($scratch . '/locked-root', 0755);
            @chmod($scratch . '/locked-empty-root', 0755);
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-32 pin (t31-ocr32-4): the removal walk's per-entry
     * IO failures. The rmdir()/unlink() calls run over the EXTRACTED
     * HOSTILE TREE — every path interpolated into an engine warning
     * is archive-controlled, the one diagnostics channel left off
     * the printable seam — and a stranded shape (a 0555 directory
     * whose children cannot be unlinked) or a removal race once
     * answered with a RAW E_WARNING: under PHPUnit (failOnWarning)
     * an exception wearing PHPUnit's vocabulary, outside it the raw
     * bytes — the exact two-way escape ocr30-4 closed for the copy
     * twin. The walk owns its returns now: the named refusal, the
     * path through the ONE printable seam, the callsites owning the
     * conversion (the teardown finally's silent degrade stays the
     * t31-ocr23-2 contract — the sibling test above holds it).
     */
    public function testTheInspectorRemovalWalkOwnsItsIoFailuresNeverTheRawWarning(): void
    {
        $scratch = self::scratchPath('inspect-iofail');
        mkdir($scratch . '/work/locked', 0755, true);
        file_put_contents($scratch . '/work/locked/x.txt', 'bytes');
        file_put_contents($scratch . '/work/plain.txt', 'bytes');
        chmod($scratch . '/work/locked', 0555);
        /*
         * The stranded-shape probe (the t31-ocr4-1 root doctrine): a
         * host whose unlink ignores the mode bit (uid 0) cannot
         * construct the failure, and the leg would assert its own
         * premise — skip visibly, never a vacuous green.
         */
        if (@unlink($scratch . '/work/locked/x.txt')) {
            chmod($scratch . '/work/locked', 0755);
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host unlinks through mode 0555 (uid 0 — t31-ocr4-1); the stranded removal failure is unconstructible here.');
        }

        try {
            /*
             * Red at HEAD: the call emitted the raw E_WARNING (under
             * failOnWarning, PHPUnit's vocabulary answered where the
             * harness refusal belongs). CHILD_FIRST order makes the
             * failing entry deterministic — 'locked/x.txt' is reached
             * before its parent directory, and only the locked
             * subtree's entries can fail.
             */
            $refusal = $this->refusalOf(
                fn() => wp_connectors_inspect_rrmdir($scratch . '/work'),
                'A per-entry removal failure must answer the named refusal — never the engine\'s raw warning over archive-controlled bytes.', \RuntimeException::class
            );
            $this->assertStringContainsString('cannot reclaim the extraction tree', $refusal->getMessage(), 'The refusal names the walk\'s own contract.');
            $this->assertStringContainsString('unlink()', $refusal->getMessage(), 'The refusal names the operation that failed.');
            $this->assertStringContainsString('locked/x.txt', $refusal->getMessage(), 'The refusal names the path — the harness\'s own vocabulary, printable-seam rendered.');
        } finally {
            chmod($scratch . '/work/locked', 0755);
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-32 pin (t31-ocr32-7): the HARNESS removal walk owns
     * its IO returns — the exact two-way escape ocr30-4 closed for
     * the copy twin (copyTree's landing loop). WpHarness::rrmdir()'s
     * per-entry unlink()/rmdir() calls once ran unchecked, so a
     * stranded 0555/0444 shape or a removal race answered with a RAW
     * E_WARNING — under PHPUnit (failOnWarning) an exception wearing
     * PHPUnit's vocabulary, outside it raw bytes — never the
     * harness's own refusal. The walk owns its returns now
     * (@-suppression + the loud policy refusal naming the path), the
     * emptied root's final rmdir owned the same way; the tree is
     * reclaimed or refused loudly.
     */
    public function testTheHarnessRemovalWalkOwnsItsIoReturns(): void
    {
        $scratch = self::scratchPath('harness-iofail');
        mkdir($scratch . '/locked', 0755, true);
        file_put_contents($scratch . '/locked/x.txt', 'bytes');
        chmod($scratch . '/locked', 0555);
        // The stranded-shape probe (the t31-ocr4-1 root doctrine): a
        // host whose unlink ignores the mode bit cannot construct the
        // failure — skip visibly, never a vacuous green.
        if (@unlink($scratch . '/locked/x.txt')) {
            chmod($scratch . '/locked', 0755);
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host unlinks through mode 0555 (uid 0 — t31-ocr4-1); the stranded removal failure is unconstructible here.');
        }

        try {
            /*
             * Red at HEAD: the raw E_WARNING escaped (failOnWarning's
             * conversion wearing PHPUnit's vocabulary over the
             * engine's words). CHILD_FIRST order makes the failing
             * entry deterministic — 'locked/x.txt' is reached before
             * its parent, and only the locked subtree can fail.
             */
            $refusal = $this->refusalOf(
                fn() => WpHarness::rrmdir($scratch),
                'A per-entry removal failure must answer the harness\'s own refusal — never the engine\'s raw warning, never PHPUnit\'s vocabulary.', \RuntimeException::class
            );
            $this->assertStringContainsString('WpHarness::rrmdir()', $refusal->getMessage(), 'The refusal speaks the harness policy\'s own vocabulary.');
            $this->assertStringContainsString('owns its IO returns', $refusal->getMessage(), 'The refusal names the doctrine it enforces.');
            $this->assertStringContainsString('locked/x.txt', $refusal->getMessage(), 'The refusal names the path.');
            // Refused loudly, tree left for the caller's finally: the
            // stranded subtree stands exactly where it was.
            $this->assertDirectoryExists($scratch . '/locked', 'The partial removal stands — the loud refusal is the verdict, the residue the caller\'s to reclaim.');
        } finally {
            chmod($scratch . '/locked', 0755);
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-33 pin (t31-ocr33-6): the removal walk fences its
     * RECURSION BOUNDARY. hasChildren() passes on stat alone, so an
     * unreadable SUBDIRECTORY mid-tree (a chmod-000 child) was
     * reached by the descent — RecursiveIteratorIterator's
     * getChildren() opens it — and the walk died in the SPL
     * iterator's own UnexpectedValueException: another library's
     * vocabulary answering a harness refusal (red at HEAD: the
     * family pin itself reddened, the SPL exception standing where
     * the policy's does) — the r30-3 class the lint gate closed,
     * the harness twin. The fence converts the abort to the
     * harness's refusal (the SPL message riding parenthetically —
     * it is what names the path); the partial removal stands for
     * the caller's finally, and readable trees still reclaim whole
     * (the finally's own release is the control).
     */
    public function testTheRemovalWalkFencesTheRecursionBoundary(): void
    {
        $scratch = self::scratchPath('harness-unlistable');
        mkdir($scratch . '/tree/open', 0755, true);
        file_put_contents($scratch . '/tree/open/x.txt', 'bytes');
        mkdir($scratch . '/tree/locked/inner', 0755, true);
        file_put_contents($scratch . '/tree/locked/inner/y.txt', 'bytes');
        chmod($scratch . '/tree/locked', 0000);
        // The unlistable-shape probe (the t31-ocr4-1 root doctrine):
        // a host whose process opens chmod-0000 directories cannot
        // construct the shape — skip visibly, never a vacuous green.
        $probe = @opendir($scratch . '/tree/locked');
        if (false !== $probe) {
            closedir($probe);
            chmod($scratch . '/tree/locked', 0755);
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host opens chmod-0000 directories (uid 0 — t31-ocr4-1); the mid-tree unlistable shape is unconstructible here.');
        }

        try {
            $refusal = $this->refusalOf(
                fn() => WpHarness::rrmdir($scratch),
                'An unlistable SUBDIRECTORY mid-tree must answer the harness\'s own refusal, never the SPL iterator\'s vocabulary.', \RuntimeException::class
            );
            $this->assertStringContainsString('WpHarness::rrmdir()', $refusal->getMessage(), 'The refusal speaks the harness policy\'s own vocabulary.');
            $this->assertStringContainsString('cannot be listed', $refusal->getMessage(), 'The refusal names the class the fence owns.');
            $this->assertStringContainsString('locked', $refusal->getMessage(), 'The refusal names the path — the SPL message parenthetical carries it.');
            $this->assertStringContainsString('Failed to open directory', $refusal->getMessage(), 'The parenthetical carries the engine\'s own diagnostic for the path — named, never laundered silent.');
        } finally {
            chmod($scratch . '/tree/locked', 0755);
            WpHarness::releaseScratch($scratch);
        }
        $this->assertDirectoryDoesNotExist($scratch, 'A readable tree still reclaims whole — the fence changed nothing about the green walk.');
    }

    /**
     * OCR-round-26 pin (t31-ocr26-4): a TAIL-SPELLED real-dir removal
     * root is fully reclaimed. The walk and the final @rmdir read the
     * caller's RAW spelling while the fences judged the stripped
     * probe, so 'dir/.' emptied the children through the iterator
     * (which normalizes) and then @rmdir('dir/.') failed EINVAL — the
     * root leaked, contradicting the ocr24-3 root-reclaim claim the
     * owner's own docblock states. Both ride the stripped spelling
     * now: the tail names the root, and the root goes whole.
     */
    public function testTailSpelledRealDirRootsAreFullyReclaimed(): void
    {
        $scratch = self::distDir() . '/.inspect-reclaim-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch, 0755, true);
        try {
            foreach (array('trailing slash' => '/', 'dot tail' => '/.') as $label => $tail) {
                $root = $scratch . '/' . str_replace(' ', '-', $label) . '-root';
                mkdir($root . '/inner', 0755, true);
                file_put_contents($root . '/inner/x.txt', 'bytes');

                wp_connectors_inspect_rrmdir($root . $tail);

                $this->assertDirectoryDoesNotExist($root, "A '{$tail}'-tailed real-dir root is reclaimed WHOLE ({$label}) — red at HEAD: the children went, the root leaked behind rmdir('…{$tail}') EINVAL.");
            }
        } finally {
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-24 pin (t31-ocr24-2): the post-extraction php -l walk
     * was the ONE walker left without the glm31-4
     * UnexpectedValueException fence — try had finally only and the
     * CLI call site caught nothing, so a directory the process cannot
     * OPEN inside the extracted tree escaped as an uncaught SPL
     * exception: the inspector died at exit 255, recorded NO verdict,
     * and the artifact escaped judgment. The walk (construction
     * included — the ocr23 rd-1 seam) converts the refusal into a
     * named violation and RETURNS whole-or-not-at-all, so the verdict
     * channel survives end-to-end.
     *
     * ENGINE PREMISE, probed this round: this host's extractTo() does
     * NOT land unix modes from the archive's external attributes
     * (PHP 8.5.10/libzip — both API-authored and attribute-indexed
     * entries land umask-derived modes; the long-standing php-src
     * behavior), so the finding's zip-carried-mode producer is not
     * constructible here. The leg constructs the CLASS through the
     * landing environment instead: umask 0444 lands every directory
     * the extraction creates at 0333 (owner -wx) — entry creation and
     * file writes succeed, the opendir every walker needs does not.
     * Re-open rule: an engine whose extractTo lands modes re-derives
     * the threat from the archive itself; the fence shape is the
     * same either way.
     *
     * The driven red escaped at the round's HEAD through the FIRST
     * unfenced construction — the shared self-containment scan's own
     * (its glm31-4 fence wrapped the foreach, never the iterator
     * construction): this commit completes that census too, and the
     * pin holds both named violations in one verdict list. The
     * secret-scanner's own walk stays this round's LEDGERED residual
     * (unfenced for its other consumers); inside the inspector the
     * syntax walk's early return keeps it one seam behind for every
     * refusal shape.
     */
    public function testThePostExtractionSyntaxWalkAnswersAVerdictOverAnUnopenableTree(): void
    {
        /*
         * The root-runner skip fires BEFORE the construction (OCR
         * round 25, t31-ocr25-6, the t31-ocr4-1 doctrine): the leg's
         * whole premise is a directory the process cannot OPEN (umask
         * 0444 landing every extraction directory at 0333) — uid 0
         * reads through mode 0333 via the DAC override, the walk
         * OPENS, $violations answers the clean judgment of a readable
         * tree, and the three refusal assertions below would pass (or
         * fail) as false positives over a premise that never
         * constructed. The root runner skips visibly instead of
         * asserting nothing, before a single byte of the scratch tree
         * exists.
         */
        if (self::runningAsRootRunner()) {
            $this->markTestSkipped('The umask-0444 walk-refusal leg premises a directory the process cannot open — uid 0 reads through mode 0333 (DAC override, the t31-ocr4-1 doctrine), the unopenable tree is unconstructible, and the refusal assertions would ride a premise that never fired.');
        }

        $scratch = self::distDir() . '/.inspect-walkrefusal-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch, 0755, true);
        $zipPath = $scratch . '/walk-refusal.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFromString('ocr24-slug/main.php', "<?php\n/**\n * Plugin Name: Walk Refusal\n */\necho 1;\n");
        $zip->close();

        $old = umask(0444);
        try {
            /*
             * Red at HEAD: the call THROWS the iterator's
             * UnexpectedValueException (exit 255's in-process shape —
             * no verdict recorded). The pin holds the verdict channel:
             * the function RETURNS, and the refusal is a named
             * violation beside the self-containment scan's own.
             */
            $violations = wp_connectors_inspect_artifact($zipPath, $scratch . '/work');

            $rendered = implode("\n", $violations);
            $this->assertStringContainsString('cannot walk ocr24-slug for the post-extraction syntax check', $rendered, 'The walk refusal answers in the verdict vocabulary, never a stack trace.');
            $this->assertStringContainsString('judged whole or not at all', $rendered, 'The refusal owns the whole-or-not-at-all doctrine — the secret scan never judges a partially readable tree.');
            $this->assertStringContainsString('unreadable subdirectory — the self-containment scan aborted', $rendered, 'The shared scan\'s own construction refusal answers named too — the round\'s census completion at the ocr23 rd-1 seam.');
            $this->assertNotSame(array(), $violations, 'The unopenable tree REJECTS — an artifact that cannot be read whole never passes judgment.');
        } finally {
            umask($old);
            /*
             * The teardown twin degrades silently over the 0333 tree
             * (the ocr23-2 contract), so the residue is this test's
             * to reclaim: open the landed roots back up, then remove.
             */
            foreach (glob($scratch . '/work-*') ?: array() as $leak) {
                chmod($leak, 0755);
                @chmod($leak . '/ocr24-slug', 0755);
                WpHarness::releaseScratch($leak);
            }
            WpHarness::releaseScratch($scratch);
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
     * setlocale is process-global: QUERIED with the '0' spelling
     * (t31-ocr1-6 — null behaves like "" and SETS from the
     * environment, so a null-"snapshot" could restore a DIFFERENT
     * LC_CTYPE than the one in effect), attempted and restored in a
     * finally so no other test sees it.
     *
     * OCR round 23 (t31-ocr23-9, the r22 ledger's residual head
     * converted): the ENV-PIN leg this test once opened — putenv()
     * pinning LC_CTYPE in the parent to prove the query spelling reads
     * rather than installs — moved to its OWN test below, gated on its
     * own capability: this test's fold legs consume setlocale only,
     * and gating the whole method on function_exists('putenv') would
     * have skipped them needlessly on a disable_functions host (the
     * gate-whole-method narrowing the r22 residual refused); the
     * spawn-pair floor was equally a misfit here (the fold-table
     * verdicts spawn nothing — the r21 producer-fence precedent, a
     * class closed at one fence re-opened at the next).
     */
    public function testTheSlugToIdentifierFoldIsLocaleIndependent(): void
    {
        $previous = setlocale(LC_CTYPE, '0');
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
     * The ENV-PIN leg of the fold test above, split to its own test and
     * its own capability gate (OCR round 23, t31-ocr23-9 — the r22
     * ledger's residual head: BuildArtifactsTest's fold battery
     * putenv()s LC_CTYPE in the PARENT with NO spawn at all, the
     * non-spawn putenv consumer the r21/r22 spawn-pair guards were a
     * misfit for). The premise: the setlocale QUERY spelling ('0') must
     * read the locale, never install the environment's (t31-ocr1-6:
     * null behaves like "" and SETS from the environment, so a
     * null-"snapshot" could restore a DIFFERENT LC_CTYPE than the one
     * in effect) — proving it needs putenv to pin an LC_CTYPE that
     * DIFFERS from the one in effect, and under -d
     * disable_functions=putenv the leg died at the putenv with 'Error:
     * Call to undefined function putenv()' mid-test (the exact fatal
     * class the spawn consumers' guards convert — the r22 rd-2
     * doctrine, at the one consumer that spawns nothing). The gate is
     * the capability the consumer alone reaches — function_exists
     * ('putenv'), the canSpawnChildren shape without the spawn pair
     * (driven: a visible skip under the flag, zero errors; green
     * otherwise). Census: setlocale — this test's other consumer and
     * the fold test's whole premise — stays ungated exactly as before,
     * a separate consumer class this round's finding does not name.
     */
    public function testTheSetlocaleQuerySpellingReadsNeverInstallsTheEnvironmentsLocale(): void
    {
        if (! function_exists('putenv')) {
            $this->markTestSkipped('This host has putenv in disable_functions — the env-pin leg cannot pin an LC_CTYPE that differs from the one in effect (its whole premise), and the pin would die at the putenv mid-test instead of answering the visible skip.');
        }
        /*
         * The QUERY spelling must be a read, never a set (t31-ocr1-6,
         * the verifier-hardened pin): the naive shape (current ==
         * current) is vacuous — null sets-then-returns the
         * environment's spelling and would pass it too. The pin
         * installs a locale that DIFFERS from the environment's and
         * asserts the query leaves it standing; null installs the
         * environment's here (reproduced: 'C' in effect +
         * LC_CTYPE=C.UTF-8 -> null returns and leaves 'C.UTF-8').
         */
        $previous = setlocale(LC_CTYPE, '0');
        $previousLcCtypeEnv = getenv('LC_CTYPE');
        try {
            putenv('LC_CTYPE=C.UTF-8');
            setlocale(LC_CTYPE, 'C');
            $this->assertSame('C', setlocale(LC_CTYPE, '0'), 'The setlocale query spelling must read the locale, never install the environment\'s.');
        } finally {
            putenv(false === $previousLcCtypeEnv ? 'LC_CTYPE' : 'LC_CTYPE=' . $previousLcCtypeEnv);
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

        $tempPlugin = self::scratchPath('dot-slug') . '/my.plugin';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
                    $this->assertTrue(
                        true === ($opened = $zip->open($zipPath)),
                        sprintf(
                            'The zip must open for the entry read: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                            $zipPath,
                            var_export($opened, true)
                        )
                    );
                    $shippedMain = (string) $zip->getFromName('my.plugin/my.plugin.php');
                    $zip->close();
                    /*
                     * The exec-capability guard (t31-ocr20-5, the ocr18-2
                     * doctrine), inside the state-preserving callback whose
                     * finally restores on the skip's throw: the parse probe
                     * below spawns an engine; the build and entry-read
                     * assertions above already passed. The guard fires
                     * BEFORE the probe write (OCR round 27, t31-ocr27-8):
                     * the skip once threw past a probe already sitting in
                     * real dist/ — the try/finally unlink started below
                     * the guard, so markTestSkipped()'s throw leaked the
                     * scratch file on every spawn-less host. The write
                     * rides inside the try now; the skip path leaves
                     * nothing.
                     */
                    if (! self::canSpawnChildren()) {
                        $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the shipped-main parse probe cannot run; the derivation, gate, and build assertions above already passed.');
                    }
                    $probe = self::scratchPath('dot-slug-probe-main.php');
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
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $scratch = self::scratchPath('manifest-read');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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

            // Root-runner skip (t31-ocr4-1): uid 0 reads the chmod-0000
            // manifest, the merge lands, and the refusal leg below (plus
            // the recovery leg after it) cannot fire.
            $this->skipChmod0000LegOnRootRunner('the unreadable-manifest leg of the manifest-merge pin');
            chmod($manifestPath, 0000);
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'An unreadable manifest must refuse the build, never land a one-entry replacement.', \RuntimeException::class
            );
            $this->assertStringContainsString('cannot read the checksum manifest', $refusal->getMessage());
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
            $this->assertStringContainsString(self::fixtureZipName(), $rebuilt);
        } finally {
            @chmod($manifestPath, 0644);
            WpHarness::releaseScratch($scratch);
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
        $tempPlugin = self::scratchPath('version-token') . '/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        $mainPath = $tempPlugin . '/example-connector.php';
        $main = (string) file_get_contents($mainPath);

        try {
            // Both the header and the constant carry the spelling (the
            // version-constant gate requires them to match).
            $traversal = '0.1/../../../vsec-precious';
            file_put_contents($mainPath, str_replace(array('Version:           ' . self::fixtureVersion(), "'" . self::fixtureVersion() . "'"), array("Version:           {$traversal}", "'{$traversal}'"), $main));
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir()),
                'A traversal-spelled Version header must refuse the build at the header gate.', \RuntimeException::class
            );
            $this->assertStringContainsString('version token', $refusal->getMessage());
            $this->assertStringContainsString($traversal, $refusal->getMessage());

            // Control: the ordinary version shape still builds, and the
            // token charset's legal specials (dot, plus) pass the gate.
            file_put_contents($mainPath, str_replace(array("Version:           {$traversal}", "'{$traversal}'"), array('Version:           ' . self::fixtureVersion(), "'" . self::fixtureVersion() . "'"), $main));
            $zipPath = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
            $this->assertFileExists($zipPath);

            foreach (array('1.0.0-beta.1', '1.0+build.2') as $legal) {
                file_put_contents($mainPath, str_replace(array('Version:           ' . self::fixtureVersion(), "'" . self::fixtureVersion() . "'"), array("Version:           {$legal}", "'{$legal}'"), $main));
                $legalZip = WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir());
                @unlink($legalZip);
                @unlink($legalZip . '.sha256');
            }
            @unlink(self::distDir() . '/checksums.txt');
        } finally {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace("no tag here\n" . $declaration . "\nclass A {}\n", 'OpenAiOauth', 'shared/src/Clock/A.php'),
            'A source with no PHP open tag must refuse — its generated copy would ship without the load-bearing provenance marker.', \RuntimeException::class
        );
        $refused = $refusal->getMessage();
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, $hostile, 'shared/src/Storage/TokenStore.php'),
                'A namespace_suffix that is not a namespace segment must be refused: ' . var_export($hostile, true), \RuntimeException::class
            );
            $this->assertStringContainsString('namespace segment', $refusal->getMessage());
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
         * OCR round 35 (t31-ocr35-1): the FOUR KEYWORD AXES ride the
         * engine's case-insensitivity — use, function, const, as. The
         * patterns once matched every keyword byte-exact lowercase, so
         * `USE …`, `use FUNCTION …`, `use Const …`, and `… AS Alias;`
         * were LEGAL imports the rewrite did not own (driven red at
         * HEAD: each refused at the postcondition's anonymous seam;
         * the r7-9 census had named only the `use` keyword axis —
         * never the other three). The keyword spellings ride the
         * output VERBATIM (the caller's casing is the source's own
         * byte, not the rewriter's to normalize); only the family
         * segments rewrite. The group-use twin (prefix keyword and
         * member KIND) rides the same census one seam over.
         */
        $keyword_case = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nUSE Deicod\\WpConnectors\\Shared\\Clock;\nUse Deicod\\WpConnectors\\Shared\\Token;\nuse FUNCTION Deicod\\WpConnectors\\Shared\\Clock\\now;\nuse Const Deicod\\WpConnectors\\Shared\\TTL;\nuse Deicod\\WpConnectors\\Shared\\Clock AS K;\nUSE Deicod\\WpConnectors\\{FUNCTION Shared\\Clock\\now};\nclass KeywordCaseStore\n{\n}\n";
        $keyword_battery = WpConnectorsBuild::rewriteSharedNamespace($keyword_case, 'OpenAiOauth', 'shared/src/Storage/KeywordCaseStore.php');
        $this->assertStringContainsString('USE Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock;', $keyword_battery, 'A USE-spelled import is rewritten, the keyword casing riding verbatim.');
        $this->assertStringContainsString('Use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Token;', $keyword_battery, 'A Use-spelled import is rewritten.');
        $this->assertStringContainsString('use FUNCTION Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\now;', $keyword_battery, "A 'use FUNCTION' spelling is rewritten.");
        $this->assertStringContainsString('use Const Deicod\\WpConnectors\\OpenAiOauth\\Shared\\TTL;', $keyword_battery, "A 'use Const' spelling is rewritten.");
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock AS K;', $keyword_battery, 'An AS-spelled alias is rewritten, the alias riding verbatim beside it.');
        $this->assertStringContainsString('USE Deicod\\WpConnectors\\{FUNCTION OpenAiOauth\\Shared\\Clock\\now};', $keyword_battery, 'The group-use PREFIX keyword and the member KIND keyword ride the same case-insensitive census.');
        $this->assertStringNotContainsString('Deicod\\WpConnectors\\Shared\\', $keyword_battery, 'No keyword-case spelling may survive un-rewritten.');
        $this->assertStringNotContainsString('Deicod\\WpConnectors\\{FUNCTION Shared', $keyword_battery, 'No keyword-case group member may survive un-rewritten.');

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
         * OCR round 31 (t31-ocr31-4): the member grammar is validated
         * BEFORE reassembly. The group-use callback once reassembled
         * member bytes through explode/trim/implode with no refusal
         * of its own, so every illegal member spelling normalized
         * into a silent pass — the empty member, the trailing comma,
         * the empty body, a dangling `as` — and the rewritten group
         * SHIPPED the parse-error spelling verbatim at exit 0 (driven
         * red at HEAD; the empty body alone reached a late,
         * mis-named postcondition refusal — "survived the rewrite"
         * over a body the grammar should have named itself). Each
         * illegal shape answers the member grammar's own refusal now,
         * naming the spelling the engine rejects at compile time —
         * and the survivors battery's t31-r10-9 EMPTY-BODY row moved
         * HERE with it: its charge (the bare vendor prefix never
         * launders through a group body) holds one seam earlier, the
         * grammar refusing the body before any prefix could survive
         * to the postcondition.
         */
        foreach (array(
            'empty member before its comma' => 'use Deicod\\WpConnectors\\{, Shared\\Clock};',
            'trailing comma' => 'use Deicod\\WpConnectors\\{Shared\\Clock,};',
            'empty brace body (the t31-r10-9 row, moved to its owning seam)' => 'use Deicod\\WpConnectors\\{};',
            'dangling as' => 'use Deicod\\WpConnectors\\{Shared\\Clock as};',
            /*
             * OCR round 32 (t31-ocr32-2): the member's ALIAS. The
             * grammar validated the member and its dangling `as` but
             * re-emitted the extracted identifier unvalidated, so
             * `{Shared\Clock as self}` member-rewrote to
             * `<Suffix>\Shared\Clock as self` verbatim (driven at
             * HEAD) — the same exit-0 compile-error class the
             * use-statement seam refused at t31-ocr32-1, one re-emit
             * seam over; the alias rides the same reserved-vocab
             * owner now (the round's census rule).
             */
            'reserved member alias: self' => 'use Deicod\\WpConnectors\\{Shared\\Clock as self};',
            'reserved member alias: TRUE (case-folded)' => 'use Deicod\\WpConnectors\\{Shared\\Clock as TRUE};',
            'reserved member alias: Float (case-folded type keyword)' => 'use Deicod\\WpConnectors\\{const Shared\\TTL as Float};',
            /*
             * OCR round 32 (t31-ocr32-3), the member half: an `as`
             * whose tail is not one plain identifier. The
             * identifier-only capture left a non-matching tail
             * attached to the member, and the whole member string
             * flowed into the leaf rewrite — the leading segment
             * rewritten, ` as Foo\Bar` re-emitted verbatim beside it
             * at exit 0 (driven at HEAD); the engine accepts only a
             * bare identifier in the slot (php -l refuses the
             * qualified alias). The extraction matches ANY `as`-tail
             * case-insensitively now (`AS` is a legal keyword
             * spelling that keeps riding) and the tail's shape is
             * judged at the seam.
             */
            'qualified member alias: Foo\\Bar' => 'use Deicod\\WpConnectors\\{Shared\\Clock as Foo\\Bar};',
            'member alias tail with a second as' => 'use Deicod\\WpConnectors\\{Shared\\Clock as X as Y};',
            /*
             * OCR round 36 (t31-ocr36-1): the member NAME's
             * leading-separator verdict, DERIVED from the engine
             * oracle (php -l refuses a fully-qualified member inside
             * a group-use at compile time — a group member resolves
             * against the statement's prefix). The grammar validated
             * everything around the name while the name rode
             * unjudged: the leaf rewrite cannot match the leading
             * backslash, so the member re-emitted VERBATIM — and
             * beside a rewritten sibling the postcondition saw no
             * family reference in it at all (an absolute member
             * reports un-composed; the composed sibling kept the
             * prefix from reporting), the zip shipping the
             * compile-error bytes at exit 0 (driven at HEAD on the
             * mixed row: returned normally; the alone row refused at
             * the postcondition's anonymous seam one verdict late).
             */
            'fully-qualified member beside a rewritten sibling (the ship shape)' => 'use Deicod\\WpConnectors\\{function \\Shared\\Clock\\now as N, Shared\\Storage\\Widget as W};',
            'fully-qualified member alone' => 'use Deicod\\WpConnectors\\{\\Shared\\Clock};',
            /*
             * OCR round 43 (t31-ocr43-3): the member NAME's own shape,
             * DERIVED from the engine oracle (php -l refuses '1y',
             * 'Foo Bar', 'Foo-Bar', and 'Foo\1b' in the member slot;
             * it accepts 'Foo\Bar' and high-byte labels). The grammar
             * validated everything AROUND the name while a member
             * that was not a name at all passed every check and was
             * re-emitted VERBATIM by both re-emit seams — at HEAD
             * these rows returned normally (red), the postcondition
             * judging only family references and waving them through:
             * compile-error bytes in the zip at exit 0.
             */
            'digit-initial member name' => 'use Deicod\\WpConnectors\\{1y};',
            'member name with a mid-name space' => 'use Deicod\\WpConnectors\\{Foo Bar};',
            'member name with a mid-name hyphen' => 'use Deicod\\WpConnectors\\{Foo-Bar};',
            'digit-initial member sub-segment' => 'use Deicod\\WpConnectors\\{Storage\\1y};',
        ) as $label => $statement) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n{$statement}\nclass IllegalGroupStore\n{\n}\n", 'OpenAiOauth', 'shared/src/IllegalGroupStore.php'),
                "An illegal group-use member spelling must refuse the rewrite ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('group-use member grammar refuses', $refusal->getMessage(), "The refusal names the member grammar's own seam — never a late postcondition verdict over a body the grammar should have named ({$label}).");
        }

        /*
         * OCR round 40 (t31-ocr40-1 — the sixth use-grammar
         * generation): the FAMILY-PREFIX brace tail. A group whose
         * PREFIX is the family itself (`use …\Shared\{…};`, or deeper
         * through the sub-segment tail `…\Shared\Storage\{…}`) matches
         * the PLAIN use-statement pattern — the vendor-prefix group
         * seam above owns only the spelling whose brace sits
         * immediately after the vendor prefix — and the tail once
         * re-emitted VERBATIM beside the rewritten prefix with NO
         * member validation: the members are relative to the prefix
         * (riding them verbatim is the correct rewrite), but the
         * engine-illegal member spellings rode with them — `as self`
         * re-emitted at exit 0, compile-error bytes in the zip with
         * every gate green (the postcondition judges family
         * references, and a member riding a target-prefixed prefix
         * waves through). Both group seams ride the ONE member-grammar
         * owner now; every row here refused at the POSTCONDITION or
         * not at all at HEAD (driven red: the rewrite returned
         * normally over the alias row).
         */
        foreach (array(
            'reserved family-prefix member alias: self' => 'use Deicod\\WpConnectors\\Shared\\{Clock as self};',
            'reserved family-prefix member alias, deeper prefix: True' => 'use Deicod\\WpConnectors\\Shared\\Storage\\{Widget as True};',
            'fully-qualified family-prefix member' => 'use Deicod\\WpConnectors\\Shared\\{\\Clock};',
            'empty family-prefix member before its comma' => 'use Deicod\\WpConnectors\\Shared\\{, Clock};',
            // t31-ocr43-3, the second seam's row: the member NAME's
            // shape judges at BOTH re-emit seams — the family-prefix
            // tail once re-emitted a non-name verbatim beside the
            // rewritten prefix (red at HEAD: returned normally).
            'non-name family-prefix member: Foo-Bar' => 'use Deicod\\WpConnectors\\Shared\\{Foo-Bar};',
        ) as $label => $statement) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n{$statement}\nclass FamilyPrefixGroupStore\n{\n}\n", 'OpenAiOauth', 'shared/src/FamilyPrefixGroupStore.php'),
                "An illegal family-prefix brace-group member must refuse the rewrite ({$label}) — red at HEAD: the tail re-emitted verbatim beside the rewritten prefix, compile-error bytes in the zip at exit 0.", \RuntimeException::class
            );
            $this->assertStringContainsString('group-use member grammar refuses', $refusal->getMessage(), "The refusal names the ONE member grammar both group spellings ride ({$label}).");
        }
        // The LEGAL family-prefix control: the prefix rewrites, the
        // members ride verbatim (they are relative to it) — the
        // grammar validates what re-emits, it never re-spells a
        // member at this seam (the vendor-prefix group's member
        // rewrite is pinned above in the $groupUse battery).
        $familyPrefixLegal = WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\{Clock, Storage\\Widget as W};\nclass FamilyPrefixLegalStore\n{\n}\n", 'OpenAiOauth', 'shared/src/FamilyPrefixLegalStore.php');
        $this->assertStringContainsString('use Deicod\\WpConnectors\\OpenAiOauth\\Shared\\{Clock, Storage\\Widget as W};', $familyPrefixLegal, 'A LEGAL family-prefix group rewrites the prefix and rides its members verbatim — validation owns the refusal, never the re-spelling.');

        /*
         * OCR round 32 (t31-ocr32-1): the alias grammar at the
         * USE-STATEMENT seam. The optional alias group re-emitted any
         * identifier spelling verbatim — including the fourteen
         * engine-illegal ones the relative-path alias state already
         * refuses — so `use …\Shared\Clock as self;` rewrote the
         * family and re-emitted ` as self` beside it: compile-error
         * bytes in the zip at exit 0 with every gate green (driven at
         * HEAD: the rewrite returned normally, the alias riding a
         * target-prefixed import the postcondition waves through).
         * The round's census rule, pinned here for the whole family:
         * the alias grammar rejects what the engine rejects, at EVERY
         * seam that re-emits an alias (the member seam's rows ride
         * t31-ocr32-2/3 below).
         */
        foreach (array(
            'reserved: self' => 'use Deicod\\WpConnectors\\Shared\\Clock as self;',
            'reserved: True (case-folded literal)' => 'use Deicod\\WpConnectors\\Shared\\Clock as True;',
            'reserved: Int (case-folded type keyword)' => 'use Deicod\\WpConnectors\\Shared\\Clock as Int;',
            'reserved: NEVER (case-folded, function kind)' => 'use function Deicod\\WpConnectors\\Shared\\Clock\\now as NEVER;',
        ) as $label => $statement) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n{$statement}\nclass IllegalAliasStore\n{\n}\n", 'OpenAiOauth', 'shared/src/IllegalAliasStore.php'),
                "An engine-illegal use-statement alias must refuse the rewrite ({$label}) — the zip ships the compile-error bytes otherwise.", \RuntimeException::class
            );
            $this->assertStringContainsString('must be one plain identifier', $refusal->getMessage(), "The refusal speaks the alias grammar's own vocabulary ({$label}).");
            $this->assertStringContainsString('IllegalAliasStore.php', $refusal->getMessage(), "The refusal names the file ({$label}).");
        }
        // The legal controls keep riding: the aliased spellings the
        // engine accepts rewrite unchanged (pinned above in the
        // $spellings battery — 'as SharedNs', 'as C', 'as nowish' —
        // and the group battery's 'as W'/'as T' members).

        /*
         * OCR round 33 (t31-ocr33-1): the HARD half of the reserved-
         * alias census. The round-32 owner enumerated only the
         * fourteen spellings that lex as plain T_STRING; every
         * keyword that lexes as its OWN token id (array, fn, list,
         * if, foreach, function, class, new, match, readonly, …)
         * matched the alias grammar's identifier bytes and shipped
         * parse-error bytes at exit 0 (driven red at HEAD: the
         * rewrite returned normally over `as array`). The class is
         * DERIVED from the engine now — a php -l oracle over the
         * whole keyword table refused every own-token keyword in the
         * slot and accepted none — and the derivation rides the ONE
         * owner, so both re-emit seams (the use-statement capture
         * and the group-use member tail) refuse the whole class.
         */
        $hard_keywords = array(
            'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class',
            'clone', 'const', 'continue', 'declare', 'default', 'do', 'else', 'elseif',
            'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'extends',
            'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
            'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface',
            'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private',
            'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'static',
            'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
            /*
             * Case variants fold with the engine: the lexer spells
             * 'ARRAY' as T_ARRAY exactly like its lowercase twin.
             */
            'ARRAY', 'Match', 'ReAdOnLy',
        );
        foreach ($hard_keywords as $keyword) {
            /*
             * 'namespace' refuses one walk EARLIER — the relative-use
             * walk's bare-keyword fence owns the word's verdict in
             * both positions (php -l agreeing), so the fragment pins
             * that seam's vocabulary for it; every other hard keyword
             * reaches the alias seam the census owns.
             */
            $seam_fragment = 'namespace' === $keyword
                ? 'not a spelling PHP accepts'
                : 'must be one plain identifier';
            $use_refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\Clock as {$keyword};\nclass HardAliasStore\n{\n}\n", 'OpenAiOauth', 'shared/src/HardAliasStore.php'),
                "A hard-keyword use-statement alias must refuse the rewrite ('as {$keyword}') — red at HEAD: the fourteen-entry census passed it and the zip shipped the parse-error bytes.", \RuntimeException::class
            );
            $this->assertStringContainsString($seam_fragment, $use_refusal->getMessage(), "The use-statement seam refuses the hard keyword '{$keyword}' through a named grammar vocabulary.");
            $member_fragment = 'namespace' === $keyword
                ? 'not a spelling PHP accepts'
                : 'group-use member grammar refuses';
            $member_refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Shared\\Clock as {$keyword}};\nclass HardMemberAliasStore\n{\n}\n", 'OpenAiOauth', 'shared/src/HardMemberAliasStore.php'),
                "A hard-keyword member alias must refuse the rewrite ('as {$keyword}') — the member seam re-emits the extracted identifier the same way.", \RuntimeException::class
            );
            $this->assertStringContainsString($member_fragment, $member_refusal->getMessage(), "The member seam refuses the hard keyword '{$keyword}'.");
        }
        /*
         * The oracle leg, driven against php -l for the WHOLE class
         * (hard and soft alike, the same table the census derived
         * from): the census's verdict and the engine's own stay
         * tied — a future engine generation minting or lifting a
         * reserved word redden here first, naming the drift.
         */
        if (WpHarness::canSpawnChildren()) {
            foreach (array_merge($hard_keywords, array(
                'self', 'parent', 'true', 'false', 'null',
                'int', 'float', 'bool', 'string', 'void', 'iterable', 'object', 'mixed', 'never',
            )) as $word) {
                $probe = tempnam(sys_get_temp_dir(), 'ocr33-alias-');
                $this->assertNotFalse(file_put_contents($probe, "<?php use A\\B as {$word};\n"), "The oracle probe for '{$word}' must stage — an unwritten probe lints empty bytes.");
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($probe) . ' 2>/dev/null', $oracle_output, $oracle_exit);
                unlink($probe);
                $this->assertNotSame(0, $oracle_exit, "php -l refuses '{$word}' in the alias slot — the census's own derivation premise, driven against the engine.");
            }
        }

        /*
         * OCR round 32 (t31-ocr32-3), the rider half over the
         * use-statement seam: the optional alias group and the
         * optional brace-group tail are each legal ALONE, but
         * together they compose into a spelling the engine rejects
         * (an aliased import opens no group; php -l refuses) — and
         * the pattern once matched both and re-emitted both verbatim
         * beside the rewritten name at exit 0 (driven at HEAD). The
         * composition refuses at the seam; each half alone keeps
         * riding (the brace-alone and alias-alone controls above).
         */
        foreach (array(
            'aliased sub-segment import with a brace tail' => 'use Deicod\\WpConnectors\\Shared\\Clock as X {Y};',
            'aliased exact-namespace import with a brace tail' => 'use Deicod\\WpConnectors\\Shared as S {Y};',
        ) as $label => $statement) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n{$statement}\nclass RiderAliasStore\n{\n}\n", 'OpenAiOauth', 'shared/src/RiderAliasStore.php'),
                "An alias and a brace-group tail never compose legally ({$label}) — the zip ships the parse-error bytes otherwise.", \RuntimeException::class
            );
            $this->assertStringContainsString('both an alias and a brace-group tail', $refusal->getMessage(), "The refusal names the composition ({$label}).");
            $this->assertStringContainsString('RiderAliasStore.php', $refusal->getMessage(), "The refusal names the file ({$label}).");
        }

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
            /*
             * OCR round 20 (t31-ocr20-3): the value lens judged the raw
             * computed value without the leading-backslash tolerance
             * every other family fold carries (the target fold at the
             * detector's own head, the text finding's 'lower' twin), so
             * a literal whose VALUE is the FULLY-QUALIFIED family name
             * — the leading backslash produced by an ESCAPE the text
             * lens cannot see (octal \134, hex \x5C), the double-
             * backslash twin was already caught by the text lens's
             * unescaped view — matched NO family predicate: the class
             * string laundered past both gates at zero references
             * (driven red at HEAD). The lens folds through the same
             * tolerance now; the heredoc twin rides the same value
             * lens (a nowdoc body carries no escapes, so its value is
             * its bytes and the text lens already owned it).
             */
            'escaped fully-qualified class-string, judged by value (t31-ocr20-3)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass FqValueStore\n{\n    public function name(): string\n    {\n        return \"\\134Deicod\\\\WpConnectors\\\\Shared\\\\Clock\";\n    }\n}\n",
            'escaped fully-qualified heredoc, judged by value (t31-ocr20-3)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass FqHeredocStore\n{\n    public function name(): string\n    {\n        return <<<EOT\n\\134Deicod\\\\WpConnectors\\\\Shared\\\\Clock\nEOT;\n    }\n}\n",
            'fully-qualified group member against a non-family prefix (t31-r10-9)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse OtherVendor\\Stuff\\{ \\Deicod\\WpConnectors\\Shared\\Clock };\nclass FqMemberStore\n{\n}\n",
            'qualified name after as, plain use (t31-r10-9)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse OtherVendor\\X as \\Deicod\\WpConnectors\\Shared\\Clock;\nclass FqAliasStore\n{\n}\n",
            'qualified name after as, group body (t31-r10-9)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse OtherVendor\\Stuff\\{ Y as \\Deicod\\WpConnectors\\Shared\\Clock };\nclass FqGroupAliasStore\n{\n}\n",
            'multi-trait adaptation CLAUSE naming the family (t31-r10-11)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait ClauseListStoreA { public function s(): void {} }\nfinal class ClauseListStore\n{\n    use ClauseListStoreA, Deicod\\WpConnectors\\Shared\\Clock {\n        ClauseListStoreA::s insteadof Clock;\n    }\n}\n",
            /*
             * OCR round 35 (t31-ocr35-3), the OPENER-SPELLING rows —
             * the round's REFUTATION OF RECORD, pinned to the
             * engine's verdict: the finding claimed a heredoc label
             * "legally carries an apostrophe" (<<<"E'OT") that a
             * strpos-over-the-whole-opener misread as nowdoc. PREMISE
             * REFUTED, driven at both legs before the fix (the r21
             * doctrine): labels are IDENTIFIERS, 0x27 is not a label
             * byte, and php -l refuses the spelling AT THE OPENER —
             * the misclassified token never exists; the legal
             * quote-LIKE class (high bytes, U+2019 '’') never trips a
             * 0x27 byte scan (driven at HEAD: the high-byte opener
             * classifies heredoc and the value lens catches the
             * escape-composed family). WHAT SURVIVES: the
             * classification reads the quote DELIMITERS now (the
             * engine's own rule — the anchored reading cannot drift
             * with the label vocabulary), and these rows PIN every
             * legal opener spelling to the engine's verdict — the
             * quoted and bare heredoc openers resolve escapes (the
             * escape-composed family refuses through the value lens),
             * the high-byte label rides the heredoc arm, and the
             * NOWDOC opener resolves nothing (the clean-direction
             * assert below).
             */
            'quoted-heredoc opener, escape-composed, judged by value (t31-ocr35-3)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass QuotedOpenerStore\n{\n    public function name(): string\n    {\n        return <<<\"EOT\"\n\\104eicod\\\\WpConnectors\\\\Shared\\\\Clock\nEOT;\n    }\n}\n",
            'bare-heredoc opener, escape-composed, judged by value (t31-ocr35-3)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass BareOpenerStore\n{\n    public function name(): string\n    {\n        return <<<EOT\n\\104eicod\\\\WpConnectors\\\\Shared\\\\Clock\nEOT;\n    }\n}\n",
            'high-byte-label heredoc opener, escape-composed, judged by value (t31-ocr35-3)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass HighByteOpenerStore\n{\n    public function name(): string\n    {\n        return <<<\"E’OT\"\n\\104eicod\\\\WpConnectors\\\\Shared\\\\Clock\nE’OT;\n    }\n}\n",
        );
        foreach ($survivors as $label => $hostile) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($hostile, 'OpenAiOauth', 'shared/src/Hostile.php'),
                "A shared-namespace spelling the rewriter does not know ({$label}) must refuse the rewrite, never survive it.", \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
            $this->assertStringContainsString('Hostile.php', $refusal->getMessage(), "The refusal must name the file ({$label}).");
            $this->assertStringContainsString('byte offset', $refusal->getMessage(), "The refusal must locate the survivor ({$label}).");
        }

        /*
         * The t31-ocr35-3 opener rows' clean-direction twin: the
         * NOWDOC opener (single-quoted label) resolves NOTHING — the
         * identical escape-composed body is its RAW BYTES, never a
         * family value, so the rewrite OWNS the file clean. The
         * quoted/bare/high-byte rows above refuse through the value
         * lens; this leg pins the other side of the engine's opener
         * rule, so a classification drift reddens one of the two
         * directions.
         */
        $nowdoc_opener = WpConnectorsBuild::rewriteSharedNamespace(
            "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass NowdocOpenerStore\n{\n    public function name(): string\n    {\n        return <<<'EOT'\n\\104eicod\\\\WpConnectors\\\\Shared\\\\Clock\nEOT;\n    }\n}\n",
            'OpenAiOauth',
            'shared/src/NowdocOpenerStore.php'
        );
        $this->assertStringContainsString('Deicod\\WpConnectors\\OpenAiOauth\\Shared;', $nowdoc_opener, 'The nowdoc opener\'s file rewrites clean — its body resolves no escapes, so no family value exists to refuse.');

        /*
         * OCR round 7 (t31-ocr7-2): LEGAL import spellings the rewriter
         * does not OWN refuse with the spelling class NAMED — never the
         * anonymous postcondition refusal alone. Survey verdicts:
         * comma-list, close-tag termination, comment inside the
         * statement — REFUSE-NAMED (the pattern's byte grammar cannot
         * see them; php -l accepts all three); grouped
         * `use Deicod\WpConnectors\{Shared\Clock, …};` — OWNED, pinned
         * above (the member rewrite carries the body's commas at brace
         * depth, the legs at the groupUse battery). The refusal keeps
         * the anonymous seam's text (the postcondition stays the total
         * authority) and appends the class sentence.
         */
        $unowned_spellings = array(
            'comma-separated import list' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\Clock, Other\\X;\nclass CommaStore\n{\n}\n",
                'comma-separated import list',
            ),
            'close-tag-terminated import' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\Clock ?>\n<p>x</p>\n<?php\nclass TagStore\n{\n}\n",
                'close-tag-terminated import',
            ),
            'comment inside the import statement' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\Clock /* pick one */;\nclass CommentStore\n{\n}\n",
                'a comment inside the use statement',
            ),
            /*
             * Verifier-pass fix (t31-ocr7-9, the refutation lens's
             * driven counterexample): the case-insensitivity axis.
             * The engine accepts the keyword case-variant and resolves
             * NAMES case-insensitively; the rewrite's patterns match
             * byte-exact spellings — both survived to the anonymous
             * postcondition refusal, the exact class r7-2 declared
             * closed. The FAMILY-NAME half of that doctrine stands
             * (the row below: the rewriter owns the declared spelling
             * byte-exactly, so complying genuinely rewrites). The
             * KEYWORD half was SUPERSEDED by t31-ocr35-1: all four
             * keyword axes (use, function, const, as) are OWNED at the
             * patterns through scoped case-insensitive groups, so the
             * keyword rows moved to the owned battery above and the
             * classifier's keyword label died with its dead errand
             * (every surviving keyword-case spelling refuses through
             * its OTHER cause — a comma list, a close tag, a comment —
             * and lowercasing the keyword fixes none of them).
             */
            'case-variant family name' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse DEICOD\\WpConnectors\\SHARED\\Clock;\nclass CaseNameStore\n{\n}\n",
                'a case-variant spelling of the family name',
            ),
        );
        foreach ($unowned_spellings as $label => $row) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($row[0], 'OpenAiOauth', 'shared/src/UnownedSpelling.php'),
                "A legal import spelling the rewrite does not own ({$label}) must refuse — never ship the family import un-rewritten.", \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage(), "The postcondition seam stays the authority ({$label}).");
            $this->assertStringContainsString($row[1], $refusal->getMessage(), "The refusal NAMES the spelling class ({$label}) — never anonymous.");
        }

        /*
         * OCR round 24 (t31-ocr24-4): the case-variant label's canonical
         * judgment is SEGMENT-BOUNDARY-AWARE — matching the ONE family
         * vocabulary's own fold (plugin-tools' $is_family: exact, or
         * prefix + '\\'). The bare prefix match mislabeled a
         * below-vendor SIBLING whose first segment merely starts with
         * the family leaf: 'use Deicod\WpConnectors\SHAREDly\Clock;'
         * wore 'a case-variant spelling of the family name … write the
         * family spelling' — a DEAD ERRAND, because complying leaves
         * the sibling, which refuses in every casing (the survivors
         * battery's own 'SharedStorage-prefixed sibling' leg). The
         * sibling keeps its own refusal now — the anonymous
         * postcondition seam, the total authority — and the TRUE
         * family spellings keep their label (the
         * 'case-variant family name' leg above rides unchanged).
         */
        $sibling_spellings = array(
            'family-leaf case-variant sibling (red at HEAD: mislabeled)' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\SHAREDly\\Clock;\nclass SiblingCaseStore\n{\n}\n",
            ),
            'exact-case sibling, control' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Sharedly\\Clock;\nclass SiblingExactStore\n{\n}\n",
            ),
            /*
             * The round's verifier close (rd-1, the refutation lens's
             * driven finding, both lenses' evidence agreeing): the
             * VENDOR branch of the canonical fold emitted the family
             * label for a case-variant vendor lead-in — and every such
             * firing is a DEAD ERRAND, because nothing that reaches
             * the vendor branch (the bare vendor, or a below-vendor
             * sibling — the detector's own $is_family fold includes
             * the vendor prefix) can become rewrite-owned by
             * re-casing: complying leaves the identical refusal
             * (driven: 'use DEICOD\WpConnectors\Zai\ApiClient;' wore
             * the label; the complied spelling refused identically,
             * the hint gone). The vendor branch is deleted; shapes
             * whose refusal is doctrine carry NO class sentence (the
             * ocr7-7 doctrine).
             */
            'vendor-lead-in case-variant sibling (rd-1, red at HEAD: dead-labeled)' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse DEICOD\\WpConnectors\\Zai\\ApiClient;\nclass VendorCaseStore\n{\n}\n",
            ),
            'case-variant bare vendor (rd-1)' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse DEICOD\\WpConnectors;\nclass VendorBareStore\n{\n}\n",
            ),
        );
        foreach ($sibling_spellings as $label => $row) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($row[0], 'OpenAiOauth', 'shared/src/SiblingSpelling.php'),
                "A below-vendor sibling import ({$label}) must refuse the rewrite — the sibling is not a spelling the rewriter owns.", \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage(), "The postcondition seam stays the authority ({$label}).");
            $this->assertStringNotContainsString('a case-variant spelling of the family name', $refusal->getMessage(), "The sibling refuses under its OWN class ({$label}) — never the family-spelling errand: complying leaves the sibling, which refuses in every casing.");
        }

        /*
         * Verifier-pass fix (t31-ocr7-7, over the r7-2 comma carve —
         * the correctness lens drove both misses): TRAIT clause lists
         * carry NO import class. The r7-2 carve knew only the braced
         * shape (`use A, B {…}`); a BRACELESS clause list
         * (`use TraitA, FamilyTrait;` inside a class — legal PHP, no
         * '{' signal) wore the comma-list class, and the comment label
         * had no carve at all, so a commented adaptation wore 'move
         * the comment outside the statement' — a dead errand (the
         * identical shape minus the comment still refuses: the r10-1
         * doctrine owns the refusal, the rewriter owns no adaptation
         * spelling). Both shapes keep the ANONYMOUS verdict now.
         */
        $trait_shapes = array(
            'braceless trait clause list' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait TraitShapeA { public function s(): void {} }\nfinal class BracelessClauseStore\n{\n    use TraitShapeA, Deicod\\WpConnectors\\Shared\\ClockFamily;\n}\n",
            'commented trait adaptation clause' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait CommentShapeA { public function s(): void {} }\nfinal class CommentedClauseStore\n{\n    use CommentShapeA, Deicod\\WpConnectors\\Shared\\ClockFamily { /* pick one */ CommentShapeA::s insteadof ClockFamily; }\n}\n",
        );
        foreach ($trait_shapes as $label => $source) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/TraitShape.php'),
                "A family trait clause ({$label}) must refuse the rewrite — the rewriter owns no adaptation spelling.", \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage(), "The refusal stands ({$label}).");
            $this->assertStringNotContainsString('spelling class the rewrite does not own', $refusal->getMessage(), "A trait clause list wears NO import class ({$label}) — the anonymous verdict is its doctrine's own, and the class guidance would be a dead errand.");
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
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($foreign, 'OpenAiOauth', 'shared/src/Storage/WidgetStore.php'),
            'A Shared-prefixed SIBLING namespace must refuse the rewrite (the r7 sibling doctrine), never stay untouched.', \RuntimeException::class
        );
        $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
        $this->assertStringContainsString('WidgetStore.php', $refusal->getMessage());

        // The postcondition: a spelling the pattern does not know (a
        // comment-interrupted use line) refuses the rewrite loudly —
        // never ships a broken import silently.
        $interrupted = "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Storage;\nuse Deicod\\WpConnectors\\Shared\\Clock /* timing */ as C;\nclass ClockStore\n{\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($interrupted, 'OpenAiOauth', 'shared/src/Storage/ClockStore.php'),
            'An unhandled shared-namespace spelling must refuse the rewrite, never survive it.', \RuntimeException::class
        );
        $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
        $this->assertStringContainsString('ClockStore.php', $refusal->getMessage());

        // The rewritten file must be valid PHP (provenance placement must not
        // precede the open tag / strict_types) and must load without output.
        // Scratch hygiene (t31-r3-11): the lint/load scratch matches no
        // tearDown glob, so its lifecycle rides try/finally — an assertion
        // failure between write and unlink must not leak it into dist/.
        /*
         * The exec-capability guard (t31-ocr20-5, the ocr18-2 doctrine):
         * the lint/load leg below spawns an engine and requires its output
         * file; every detector and rewrite assertion above already passed.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the rewritten-source lint/load leg cannot run; every detector and rewrite assertion above already passed.');
        }
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/TagBound.php'),
                "A use statement's tracking state must die at every statement-boundary spelling ({$label}) — never eat the name run after the boundary.", \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage(), "The refusal is the postcondition's ({$label}).");
            $this->assertStringContainsString('TagBound.php', $refusal->getMessage(), "The refusal must name the file ({$label}).");
            $this->assertStringContainsString('Deicod\\WpConnectors\\Zai\\Api', $refusal->getMessage(), "The refusal must name the laundered reference ({$label}).");
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
     * OCR-round-10 pin (t31-ocr10-4): the family-verdict folds ride the
     * LOCALE-INDEPENDENT ASCII owner (wp_connectors_ascii_lower(), the
     * r11-6 mechanism), never strtolower() — whose byte mapping is a
     * question about the engine and the process locale (glibc's tr_*
     * maps 'I' to the dotless ı at the libc level; a fold riding it
     * could let a case-variant 'DEICOD\…' spelling LAUNDER past the
     * vendor predicate under a Turkish locale, both gates green). The
     * pin drives the detector over case-variant hostile spellings in
     * the C locale and under a MANUFACTURED live tr_TR locale (the
     * suite's established locale-pressure idiom: localedef into a
     * private LOCPATH, visible skip where the host cannot manufacture
     * it, '0'-spelling snapshot, LOCPATH-first checked restore), and
     * requires the BYTE-IDENTICAL verdict — every 'lower' twin pure
     * ASCII under pressure.
     */
    public function testTheFamilyVerdictFoldSurvivesATurkishLocale(): void
    {
        // The fold-table half first (no locale needed): hostile
        // case-variant bytes fold to the pure-ASCII comparison spelling.
        $hostile = 'DEICOD\\WPCONNECTORS\\SHARED\\CLOCK';
        $this->assertSame('deicod\\wpconnectors\\shared\\clock', wp_connectors_ascii_lower($hostile), 'The ASCII owner folds hostile case-variant bytes to the comparison spelling.');

        // The verdict input: a case-variant family spelling in a STRING
        // literal (the value lens folds the bytes — the sibling pattern
        // reports it twice, stem and full) and in a code position (the
        // name walk's lower twin), both outside the rewrite's trees —
        // all must report.
        $source = "<?php\nnamespace Other;\n\$x = 'DEICOD\\\\WPCONNECTORS\\\\SHARED\\\\CLOCK';\n\$y = DEICOD\\WPCONNECTORS\\Zai::class;\n";
        $c_locale_verdict = wp_connectors_shared_family_references($source);
        $this->assertCount(3, $c_locale_verdict, 'Every case-variant spelling reports under the C locale: ' . implode(', ', array_column($c_locale_verdict, 'name')));
        foreach ($c_locale_verdict as $reference) {
            $this->assertStringStartsWith('deicod\\wpconnectors', (string) $reference['lower'], 'Every lower twin folds through the ASCII table — the vendor prefix stays pure ASCII.');
        }

        /*
         * The locale-pressure half: manufacture tr_TR, install it live,
         * and require the fold seam's verdict byte-identical. The seam
         * this round owns is the PHP fold (the 'lower' twins and the
         * value-lens is_family comparison); the TEXT lens's PCRE /i
         * matching is a DIFFERENT, already-ledgered engine behavior
         * (the /i fold consults the active locale — SharedOAuthContracts
         * HttpTest's restore note; driven live by this very pin: under
         * tr_TR the /i stem finding 'DEICOD\WPCONNECTORS\SHARED' drops
         * while every fold-seam finding survives byte-identical), and it
         * is the next round's lead, not this finding's mechanism.
         */
        $foldSeamVerdict = static function (array $verdict): array {
            return array_values(array_filter($verdict, static function (array $reference): bool {
                return 'code' === $reference['kind'] || '\\clock' === substr((string) $reference['lower'], -6);
            }));
        };
        $c_fold_seam = $foldSeamVerdict($c_locale_verdict);
        $this->assertCount(2, $c_fold_seam, 'The fold seam\'s findings are the code-position name run and the value-lens full spelling.');

        /*
         * The capability probe before the open resource (t31-ocr13-4,
         * the ocr6-12 visible-skip doctrine): a host with exec or
         * escapeshellarg in disable_functions FATALS this leg with an
         * undefined-function Error — @ cannot suppress it — instead of
         * the visible skip. The spelling pins above already passed.
         */
        // The guard declares the TRIPLE (OCR round 22's verifier pass,
        // rd-2 — the t31-ocr22-4 class over this consumer): the
        // pressure leg putenv()s LOCPATH in the PARENT before the
        // locale installs, so a putenv-disabled host fataled at the
        // putenv (driven) instead of this visible skip.
        if (! self::canSpawnChildren('putenv')) {
            $this->markTestSkipped('This host has exec/escapeshellarg/putenv in disable_functions — the localedef pressure locale cannot be manufactured (the pressure leg sets LOCPATH through putenv in this very process); the locale-pressure half did not run (the fold-table and C-locale verdict pins above already passed).');
        }
        $locpath = sys_get_temp_dir() . '/wpct-locale-' . getmypid();
        @mkdir($locpath, 0755, true);
        exec('localedef -i tr_TR -f ISO-8859-9 ' . escapeshellarg($locpath . '/tr_TR.ISO-8859-9') . ' 2>/dev/null', $localedefOutput, $localedefExit);
        if (0 !== $localedefExit) {
            WpHarness::releaseScratch($locpath);
            $this->markTestSkipped('The tr_TR.ISO-8859-9 pressure locale could not be manufactured on this host (localedef exit ' . $localedefExit . ') — the locale-pressure half did not run; the fold-table and C-locale verdict pins above already passed.');
        }
        $previous = setlocale(LC_CTYPE, '0');
        $previousLocpath = getenv('LOCPATH');
        try {
            putenv('LOCPATH=' . $locpath);
            $this->assertNotFalse(setlocale(LC_CTYPE, 'tr_TR.ISO-8859-9'), 'The manufactured locale must install.');
            // The pressure is LIVE (ctype consults it) — not a setlocale
            // that silently fell back.
            $this->assertTrue(ctype_lower("\xE3"), 'ctype consults the manufactured 8-bit LC_CTYPE — the pressure is live.');

            $tr_fold_seam = $foldSeamVerdict(wp_connectors_shared_family_references($source));
            $this->assertSame($c_fold_seam, $tr_fold_seam, 'The fold seam\'s verdict is byte-identical under the live Turkish locale — a locale-consulting fold would launder the case-variant spellings (0 references) or fold a dotless-I into the lower twins.');
        } finally {
            // LOCPATH restored BEFORE the locale, the restore CHECKED
            // (glibc resolves it through LOCPATH) — the r11-6 idiom: a
            // leaked pressure locale breaks every later /i match in
            // this process.
            putenv(false === $previousLocpath ? 'LOCPATH' : 'LOCPATH=' . $previousLocpath);
            if (false === setlocale(LC_CTYPE, $previous)) {
                setlocale(LC_CTYPE, 'C');
            }
            WpHarness::releaseScratch($locpath);
        }
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
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($escape, 'OpenAiOauth', 'shared/src/Relative.php'),
            'A family-resolving relative under a non-owned declaration must refuse the rewrite, never ship un-rewritten.', \RuntimeException::class
        );
        $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
        $this->assertStringContainsString('Relative.php', $refusal->getMessage());
        $this->assertStringContainsString('Deicod\\WpConnectors\\Shared\\Clock', $refusal->getMessage(), 'The refusal names the RESOLVED reference.');
        $this->assertStringContainsString('relative position', $refusal->getMessage(), 'The refusal names the relative kind — never "use", whose rewritable-position reading would wave it through the sweep.');

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
         * A RELATIVE GROUP-USE MEMBER resolves against the DECLARED
         * namespace, never the group prefix (OCR round 11, t31-ocr11-1):
         * PHP's relative operator ignores the prefix entirely, so
         * composing the member spelled `Psr\Log\namespace\…` — a name no
         * family predicate matches — while the member actually resolves
         * to `Deicod\WpConnectors\…` (the family) laundered the spelling
         * past every gate at zero references (driven red at HEAD). The
         * member reports UN-composed and the resolution the plain use
         * spelling gets is the one the group member gets.
         */
        $relative_group_member = "<?php\nnamespace Deicod;\nuse Psr\\Log\\{namespace\\WpConnectors\\Shared\\Clock};\ninterface GroupRelativeMemberFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($relative_group_member);
        $this->assertCount(1, $found, 'A relative group-use member resolves through the declared namespace — the family spelling never launders through a group-prefix composition.');
        $this->assertSame(array( 'Deicod\\WpConnectors\\Shared\\Clock', 'relative' ), array( $found[0]['name'], $found[0]['kind'] ), 'The member resolves exactly like its plain-use twin: declared namespace + relative tail.');

        /*
         * The verifier's completion sweep (t31-ocr11-21, the round-11
         * refutation lens over the first cut): the guard rode a
         * CASE-SENSITIVE byte match and the fused token only — the
         * keyword is case-insensitive PHP (an UPPERCASE fused spelling
         * still lexes T_NAME_RELATIVE) and the INTERRUPTED spellings
         * arrive as keyword + separator + name (the fused token never
         * forms), so four spellings of the SAME member still composed
         * or resolved nowhere and laundered at ZERO references while
         * the rewriter (token-id-keyed) refused every one — verdict
         * drift. Relativeness rides the TOKEN ID and the pending arm
         * now, and the ledger never opens a declaration from a
         * namespace keyword inside a use statement (the corrupted base
         * re-based every LATER relative — the r8-10 misattribution
         * class, driven red at HEAD through the bare-keyword spelling).
         */
        $relative_member_spellings = array(
            'fused UPPERCASE keyword' => 'use Psr\\Log\\{NAMESPACE\\WpConnectors\\Shared\\Clock};',
            'fused mixed-case keyword' => 'use Psr\\Log\\{NameSpace\\WpConnectors\\Shared\\Clock};',
            'interrupted by whitespace' => 'use Psr\\Log\\{namespace \\WpConnectors\\Shared\\Clock};',
            'interrupted by a comment' => 'use Psr\\Log\\{namespace/* c */\\WpConnectors\\Shared\\Clock};',
            'bare keyword, no separator' => 'use Psr\\Log\\{namespace WpConnectors\\Shared\\Clock};',
            'interrupted PLAIN use' => 'use namespace \\WpConnectors\\Shared\\Clock;',
        );
        foreach ($relative_member_spellings as $label => $statement) {
            $found = wp_connectors_shared_family_references("<?php\nnamespace Deicod;\n{$statement}\ninterface GroupRelativeMemberFixture\n{\n}\n");
            $this->assertCount(1, $found, "Every spelling of a family-resolving relative use member reports — case, interruption, and separator-drop are one operator ({$label}; red at HEAD: zero references).");
            $this->assertSame(array( 'Deicod\\WpConnectors\\Shared\\Clock', 'relative' ), array( $found[0]['name'], $found[0]['kind'] ), "The spelling resolves exactly like its fused lowercase twin ({$label}).");
        }

        /*
         * The interrupted relative in the ALIAS slot, single-segment
         * tail (OCR round 20, t31-ocr20-2): the alias skip judged
         * qualifiedness on the RAW run name, so a tail that arrives as
         * a bare single-segment T_STRING — the separator its OWN token
         * with trivia AFTER it (`namespace \ WpConnectors`, the
         * comment twin, the newline twin; the GLUED spelling lexes the
         * whole tail as T_NAME_FULLY_QUALIFIED and already reported)
         * — was silently EATEN as the alias while its re-attached
         * spelling resolves against the declaration into the family:
         * ZERO references, verdict drift (the rewriter's own
         * alias-slot fence refused the same bytes — the sweep waved
         * them through). Qualifiedness includes the relative arm now;
         * the skip eats only BARE runs.
         */
        $alias_slot_spellings = array(
            'space after the separator' => 'use Foo as namespace \\ WpConnectors;',
            'comment after the separator' => 'use Foo as namespace \\/* c */WpConnectors;',
            'newline after the separator' => "use Foo as namespace \\\nWpConnectors;",
        );
        foreach ($alias_slot_spellings as $label => $statement) {
            $found = wp_connectors_shared_family_references("<?php\nnamespace Deicod;\n{$statement}\ninterface AliasSlotFixture\n{\n}\n");
            $this->assertCount(1, $found, "An interrupted relative in the alias slot reports — its single-segment tail is not the bare alias the skip may eat ({$label}; red at HEAD: zero references, silently eaten).");
            $this->assertSame(array( 'Deicod\\WpConnectors', 'relative' ), array( $found[0]['name'], $found[0]['kind'] ), "The alias-slot relative resolves exactly like its leading-position twin ({$label}).");
        }
        // Control: the skip still eats what an alias IS — a BARE run.
        // The aliased import itself is the one reference; the alias
        // name never reports (and the glued FQ twin above the fix
        // already carried the qualified tail to the same report).
        $bare_alias = "<?php\nnamespace Deicod;\nuse Deicod\\WpConnectors\\Shared\\Clock as C;\ninterface BareAliasControlFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($bare_alias);
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\Shared\\Clock', 'use' ) ), array_map(static function (array $reference): array {
            return array( $reference['name'], $reference['kind'] );
        }, $found), 'The legal bare alias stays eaten — the import is the one reference.');
        // The base-integrity half (the r8-10 class, driven red at HEAD
        // through the ledger corruption): an interrupted relative use
        // must not re-base the relatives that FOLLOW it.
        $corrupting = "<?php\nnamespace Deicod;\nuse namespace Foo;\n\$x = namespace\\WpConnectors\\Shared\\Clock::class;\n";
        $found = wp_connectors_shared_family_references($corrupting);
        $this->assertCount(1, $found, 'An interrupted relative use corrupts no resolution base — the later legal relative still resolves against the true declaration.');
        $this->assertSame(array( 'Deicod\\WpConnectors\\Shared\\Clock', 'relative' ), array( $found[0]['name'], $found[0]['kind'] ));

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
        /*
         * Since t31-ocr5-1 the BUILD refusal fires one seam earlier: the
         * `namespace \Junk;` line itself is the interrupted keyword the
         * rewriter refuses outside use statements (the detector legs
         * above still pin the r8-10 base-integrity property — the junk
         * corrupts nothing — and the reporting is what the
         * postcondition rides, so the launder-proof verdict chain is
         * unchanged).
         */
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($corrupted, 'ExampleConnector', 'shared/src/Corrupt.php'),
            'A relative laundering behind an invalid fully-qualified declaration must refuse the rewrite, exactly like its control.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a spelling PHP accepts', $refusal->getMessage());

        // A fully-qualified FAMILY declaration (`namespace \Deicod\…`)
        // is a code-position name now — refused by both consumers, one
        // verdict, never a declaration that overwrites the base. The
        // BUILD refusal is the t31-ocr5-1 shape fence (the same
        // interrupted-keyword spelling), one seam earlier than the
        // postcondition's 'code position' verdict.
        $fq_family = "<?php\nnamespace Deicod;\nnamespace \\Deicod\\WpConnectors\\Shared;\ninterface FqFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($fq_family);
        $this->assertContains(array( 'name' => 'Deicod\\WpConnectors\\Shared', 'lower' => 'deicod\\wpconnectors\\shared', 'kind' => 'code', 'offset' => 34, 'line' => 3 ), $found, 'The invalid fully-qualified family spelling reports as a code-position name, never a declaration.');
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($fq_family, 'ExampleConnector', 'shared/src/Fq.php'),
            'A fully-qualified family declaration must refuse the rewrite.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a spelling PHP accepts', $refusal->getMessage());

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
     * OCR round 11 (t31-ocr11-4): the empty-body fence armed on ANY
     * reported member — including an ABSOLUTE member deliberately NOT
     * composed (t31-r10-9) — so a body nothing composes kept the fence
     * silent and the GROUP PREFIX's own spelling was judged nowhere:
     * `use Deicod\WpConnectors\{\Zai\Api};` reported only `Zai\Api`
     * while the family-spelled prefix went unreported through every
     * gate (driven red at HEAD: zero family references). The fence
     * arms on COMPOSITION now — one verdict path: a composed member
     * carries the prefix's spelling into its own report, and a body
     * nothing composes trips the fence and the prefix reports itself.
     */
    public function testAGroupPrefixWithOnlyNonComposingMembersReportsThePrefix(): void
    {
        // (a) THE REPRO: an absolute-only body — the prefix reports
        // itself (red at HEAD: the absolute member armed the fence and
        // the family prefix never reported).
        $absolute_only = "<?php\nnamespace Deicod;\nuse Deicod\\WpConnectors\\{\\Zai\\Api};\ninterface AbsoluteOnlyFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($absolute_only);
        $this->assertContains(array( 'name' => 'Deicod\\WpConnectors', 'lower' => 'deicod\\wpconnectors', 'kind' => 'use' ), array_map(static function (array $reference): array {
            return array( 'name' => $reference['name'], 'lower' => $reference['lower'], 'kind' => $reference['kind'] );
        }, $found), 'A family-spelled group PREFIX with a body nothing composes reports itself — the prefix is never laundered by its own members.');

        // (b) Control, the composed half of the one verdict path: a
        // composed member carries the prefix's spelling and the prefix
        // does NOT report separately.
        $composed = "<?php\nnamespace Deicod;\nuse Deicod\\WpConnectors\\{Zai\\Api};\ninterface ComposedFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($composed);
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\Zai\\Api', 'use' ) ), array_map(static function (array $reference): array {
            return array( $reference['name'], $reference['kind'] );
        }, $found), 'A composed member is the prefix\'s one carrier — the composed name reports, the prefix never reports twice.');

        // (c) The relative-only body rides BOTH doctrines (t31-ocr11-1
        // + this round): the member resolves against the declaration
        // (un-composed, kind 'relative'), and the prefix — composed
        // with nothing — reports itself.
        $relative_only = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{namespace\\Clock};\ninterface RelativeOnlyFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($relative_only);
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\Shared', 'declaration' ), array( 'Deicod\\WpConnectors\\Shared\\Clock', 'relative' ), array( 'Deicod\\WpConnectors', 'use' ) ), array_map(static function (array $reference): array {
            return array( $reference['name'], $reference['kind'] );
        }, $found), 'A relative-only body reports the resolved member AND the prefix (the file\'s own declaration beside them) — nothing composes, everything is judged.');

        /*
         * (d) The EOF-truncated spellings (OCR round 27, t31-ocr27-3):
         * the fence fired only at the boundary handlers, so a group
         * use cut at end-of-file — bare, or with a non-composing
         * member in progress — dropped the prefix without its report
         * (red at HEAD: zero family references, the family-spelled
         * prefix judged by no gate). EOF is the last boundary: both
         * spellings report the prefix exactly as the ';' and '}'
         * handlers do.
         */
        $truncated_bare = "<?php\nnamespace Deicod;\nuse Deicod\\WpConnectors\\{";
        $found = wp_connectors_shared_family_references($truncated_bare);
        $this->assertContains(array( 'Deicod\\WpConnectors', 'use' ), array_map(static function (array $reference): array {
            return array( $reference['name'], $reference['kind'] );
        }, $found), 'A group use truncated at EOF still reports its prefix — the bare `use Prefix\{` spelling judged by the fence, never silently dropped.');

        $truncated_member = "<?php\nnamespace Deicod;\nuse Deicod\\WpConnectors\\{\\Zai\\Api";
        $found = wp_connectors_shared_family_references($truncated_member);
        $this->assertContains(array( 'Deicod\\WpConnectors', 'use' ), array_map(static function (array $reference): array {
            return array( $reference['name'], $reference['kind'] );
        }, $found), 'A group use truncated at EOF mid-member still reports its prefix — the absolute member in progress composes nothing, and EOF flushes the fence exactly as the \';\' handler would.');
    }

    /**
     * OCR round 28 (t31-ocr28-2): the INTERRUPTED ABSOLUTE — a leading
     * separator standing apart from its name (`\ Deicod\…`, trivia
     * between) lexes as a standalone T_NS_SEPARATOR the walk's non-name
     * branch consumed, and the qualified name rode on as relative: the
     * group prefix composed it (`Psr\Log\Deicod\…`, the r10-9
     * laundering verdict) and the fully-qualified spelling escaped the
     * detector — driven red at HEAD at ZERO references while the glued
     * twin reported. A standalone separator the run assembly did not
     * swallow arms the ABSOLUTE expectation now (the interrupted-
     * relative sibling's own pending-arm pattern, t31-ocr11-21), and
     * the following name is judged fully-qualified regardless of the
     * intervening trivia — exactly like its glued T_NAME_FULLY_QUALIFIED
     * twin at every verdict: no composition, the empty-body fence, the
     * alias slot's qualified report.
     */
    public function testAnInterruptedAbsoluteNameIsJudgedFullyQualifiedLikeItsGluedTwin(): void
    {
        /*
         * THE REPRO (red at HEAD: zero references — the member composed
         * with the non-family prefix): every interruption spelling of a
         * fully-qualified group member reports un-composed, exactly the
         * verdict its glued twin (the r10-9 doctrine) already gets.
         */
        $spellings = array(
            'space after the separator' => 'use Psr\\Log\\{ \\ Deicod\\WpConnectors\\Shared\\Clock};',
            'comment after the separator' => 'use Psr\\Log\\{ \\/* x */Deicod\\WpConnectors\\Shared\\Clock};',
            'newline after the separator' => "use Psr\\Log\\{ \\\nDeicod\\WpConnectors\\Shared\\Clock};",
            'plain use, interrupted absolute' => 'use \\ Deicod\\WpConnectors\\Shared\\Clock;',
        );
        foreach ($spellings as $label => $statement) {
            $found = wp_connectors_shared_family_references("<?php\nnamespace Deicod;\n{$statement}\ninterface InterruptedAbsoluteFixture\n{\n}\n");
            $this->assertSame(array( array( 'Deicod\\WpConnectors\\Shared\\Clock', 'use' ) ), array_map(static function (array $reference): array {
                return array( $reference['name'], $reference['kind'] );
            }, $found), "The interrupted absolute reports un-composed, judged fully-qualified like its glued twin ({$label}; red at HEAD: zero references, the member composed with the prefix).");
        }

        /*
         * The fence rides the arm too (the ocr11-4 twin parity): a body
         * of only interrupted-absolute members composes nothing, so the
         * family-spelled prefix reports itself — the glued absolute-only
         * body's own verdict.
         */
        $absolute_only = "<?php\nnamespace Deicod;\nuse Deicod\\WpConnectors\\{ \\ Zai\\Api};\ninterface InterruptedAbsoluteOnlyFixture\n{\n}\n";
        $found = wp_connectors_shared_family_references($absolute_only);
        $this->assertContains(array( 'Deicod\\WpConnectors', 'use' ), array_map(static function (array $reference): array {
            return array( $reference['name'], $reference['kind'] );
        }, $found), 'A body of only interrupted-absolute members trips the empty-body fence — the prefix reports itself exactly as the glued absolute-only body does.');

        /*
         * The dangling separators stay silent (the arm's own grammar):
         * a separator no name follows is judged by nothing — the guard
         * kills the pending before a name run can consume it, and the
         * `use Prefix \ {` brace join keeps composing its members (the
         * separator before the brace is the PREFIX's own, never a
         * member lead).
         */
        $this->assertSame(array(), wp_connectors_shared_family_references("<?php\nnamespace Deicod;\nuse \\ ;\n\$x = 1;\n"), 'A dangling separator no name follows arms nothing the walk reports.');
        $found = wp_connectors_shared_family_references("<?php\nnamespace Deicod;\nuse Deicod\\WpConnectors \\ {Zai\\Api};\ninterface JoinFixture\n{\n}\n");
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\Zai\\Api', 'use' ) ), array_map(static function (array $reference): array {
            return array( $reference['name'], $reference['kind'] );
        }, $found), 'The interrupted prefix-brace join keeps composing its members — that separator is the prefix\'s own, never a member lead.');

        // End to end through the build's postcondition: the laundered
        // group member REFUSES the rewrite (red at HEAD: exit 0 — the
        // member composed past every gate, and the rewriter's own
        // patterns own no interrupted spelling).
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Psr\\Log\\{ \\ Deicod\\WpConnectors\\Shared\\Clock};\nclass InterruptedAbsoluteStore\n{\n}\n", 'OpenAiOauth', 'shared/src/InterruptedAbsolute.php'),
            'An interrupted absolute group member must refuse the rewrite — the leading separator standing apart from its name is still a fully-qualified spelling, never prefix-composable material.', \RuntimeException::class
        );
        $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
        $this->assertStringContainsString('InterruptedAbsolute.php', $refusal->getMessage());
        $this->assertStringContainsString('Deicod\\WpConnectors\\Shared\\Clock', $refusal->getMessage(), 'The refusal names the member — un-composed, exactly the glued twin\'s own refusal.');
    }

    /**
     * OCR round 5 (t31-ocr5-1): the INTERRUPTED relative operator in a
     * CODE position — `$x = namespace \WpConnectors\Shared\Clock;`, the
     * keyword separated from its '\' — is a parse error the engine never
     * accepts, but the family detector's walk drops the bare keyword
     * (the r8-10 rule keeps it from corrupting the resolution base) and
     * reports only the following name run, `WpConnectors\Shared\Clock` —
     * a name no family predicate matches. The FUSED twin reports as a
     * 'relative' family reference; the interrupted twin reported
     * NOTHING, so the bytes rode the rewrite, the postcondition, and the
     * sweep at exit 0 (reproduced red through this seam: the rewrite
     * returned with the parse-error line intact, php -l-verified as
     * `unexpected token "namespace"`; the build runs no lint gate over
     * the zip's output). The rewriter owns the predicate — never-legal
     * spellings that could reach the zip — and refuses the shape at
     * every position outside a use statement, family-resolving or not,
     * base owned or not: the spelling adapts nowhere (the r8-2
     * adaptation premise is false for it even under an owned
     * declaration — the bytes are a parse error before any resolution).
     */
    public function testAnInterruptedRelativeOperatorInACodePositionRefusesTheRewrite(): void
    {
        // THE REPRO: the fused twin under a non-owned declaration refuses
        // via the postcondition ('relative position') — the interrupted
        // twin shipped at exit 0.
        $planted = "<?php\nnamespace Deicod;\n\$x = namespace \\WpConnectors\\Shared\\Clock::class;\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($planted, 'OpenAiOauth', 'shared/src/InterruptedCodeRel.php'),
            'The keyword-interrupted relative operator in a code position must refuse the rewrite — it differs from its reporting fused twin only by whitespace the engine refuses.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a spelling PHP accepts', $refusal->getMessage());
        $this->assertStringContainsString('InterruptedCodeRel.php', $refusal->getMessage());

        // The carve-out position refuses too: under an OWNED declaration
        // the fused relative adapts by construction (t31-r11-1), but the
        // interrupted twin adapts nowhere — the parse error survives any
        // declaration rewrite.
        $owned = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n\$x = namespace \\Forms\\Clock::class;\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($owned, 'OpenAiOauth', 'shared/src/OwnedBaseInterrupted.php'),
            'An interrupted relative under an owned declaration must refuse the rewrite — the adaptation carve-out is for spellings PHP accepts.', \RuntimeException::class
        );
        $this->assertStringContainsString('outside a use statement', $refusal->getMessage());

        // The non-family twin refuses too — the fence owns the SHAPE,
        // not the family-ness (`namespace \Junk;` once rode at exit 0 in
        // this very declaration slot).
        $foreign = "<?php\nnamespace Deicod;\nnamespace \\Junk;\ninterface ForeignFixture\n{\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($foreign, 'OpenAiOauth', 'shared/src/ForeignInterrupted.php'),
            'A non-family interrupted spelling must refuse the rewrite — the zip ships through no lint gate.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a spelling PHP accepts', $refusal->getMessage());

        // The fence is spelling-exact: the FUSED code-position relative
        // keeps its own verdicts — refusal under a non-owned base
        // (postcondition, 'relative position')…
        $fused = "<?php\nnamespace Deicod;\n\$x = namespace\\WpConnectors\\Shared\\Clock::class;\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($fused, 'OpenAiOauth', 'shared/src/FusedCodeRel.php'),
            'The fused control must keep refusing via the postcondition.', \RuntimeException::class
        );
        $this->assertStringContainsString('relative position', $refusal->getMessage(), 'The fused twin refuses at the postcondition, not the shape fence — the fence never widened past the interrupted spelling.');
        // …and adaptation under an owned one (the sibling test's Carrier
        // pin, unchanged).
        $adapting = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n\$x = namespace\\FormsFixture;\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($adapting, 'OpenAiOauth', 'shared/src/AdaptingFused.php');
        $this->assertStringContainsString('namespace\\FormsFixture', $rewritten, 'The fused code-position relative still rides verbatim under an owned declaration.');
    }

    /**
     * Verifier-lens pin over t31-ocr5-1's fence (t31-ocr5-9, the
     * round's own refutation lens): the fence's first spelling owned
     * only the '\'-led tail across whitespace/comments, so two classes
     * of the SAME never-legal predicate shipped parse-error bytes at
     * exit 0 through the seam — the keyword interrupted from its tail
     * by a MODE BOUNDARY (`namespace ?> <?php \Junk;` — a close tag is
     * trivia to the r8-1 boundary owner, but the fence's follower walk
     * was blind to tags and inline HTML; the lens reproduced the ship
     * end-to-end through buildPlugin with php -l red inside the zip)
     * and the bare keyword in any non-declaration role (`$x =
     * namespace;`, `$x = namespace` at EOF, `$x = namespace Junk;` — a
     * declaration SHAPE in an expression POSITION). The fence is total
     * now: outside a use statement the keyword must OPEN a declaration
     * (a name, or a braced block) STANDING at a statement boundary,
     * judged across mode boundaries; every other spelling refuses.
     * The legal controls pin the fence's exactness — declarations,
     * braced blocks (named and global), a declaration interrupted by a
     * close tag, and the residual: a declaration-shaped keyword at a
     * boundary but not first in the file (a compile-time fatal, not a
     * parse error) still rides, dev-time lint owns it.
     */
    public function testABareNamespaceKeywordOutsideAUseStatementRefusesEveryIllegalShape(): void
    {
        $refusals = array(
            'close tag + re-entry, relative tail' => "<?php\nnamespace Deicod;\n\$x = namespace ?> <?php \\Junk\\Clock;\n",
            'close tag, inline-HTML tail (no re-entry)' => "<?php\nnamespace Deicod;\n\$x = namespace ?> \\Junk;\n",
            'expression position, bare statement' => "<?php\nnamespace Deicod;\n\$x = namespace;\n",
            'expression position, end of file' => "<?php\nnamespace Deicod;\n\$x = namespace",
            'declaration shape in expression position' => "<?php\nnamespace Deicod;\n\$x = namespace Junk;\n",
            /*
             * OCR round 7 (t31-ocr7-3): the r5-9 follower crossed mode
             * boundaries, so the re-entered NAME behind the HTML bound
             * to the keyword as its declaration (a parse error php -l
             * rejects) — the fence waved it and the postcondition
             * caught the name one seam late as an anonymous code
             * position. A boundary ends the scan now: the bare
             * keyword's own named verdict.
             */
            'mode boundary between keyword and name' => "<?php\nnamespace ?> <p>hi</p> <?php Deicod\\WpConnectors\\Shared\\Clock;\n",
        );
        foreach ($refusals as $label => $source) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/IllegalKeyword.php'),
                "A bare 'namespace' keyword in a never-legal shape must refuse the rewrite ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('not a spelling PHP accepts', $refusal->getMessage(), "The refusal names the shape class ({$label}).");
            $this->assertStringContainsString('IllegalKeyword.php', $refusal->getMessage(), "The refusal names the file ({$label}).");
        }

        $controls = array(
            'plain declaration' => "<?php\nnamespace Deicod;\ninterface LegalFixture\n{\n}\n",
            'qualified declaration' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface LegalFixture\n{\n}\n",
            'braced global block' => "<?php\nnamespace {\ninterface LegalFixture\n{\n}\n}\n",
            'braced named block' => "<?php\nnamespace Deicod {\ninterface LegalFixture\n{\n}\n}\ninterface AfterBlock\n{\n}\n",
            'declaration interrupted by a close tag' => "<?php\nnamespace Deicod ?> <?php\n\$x = 1;\n",
            'declaration after declare()' => "<?php\ndeclare(strict_types=1);\nnamespace Deicod;\ninterface LegalFixture\n{\n}\n",
            'second declaration after a use block' => "<?php\nnamespace A;\nuse RuntimeException;\nnamespace B;\ninterface LegalFixture\n{\n}\n",
            'residual: declaration at a boundary, not first' => "<?php\n\$x = 1;\nnamespace Deicod;\ninterface LegalFixture\n{\n}\n",
        );
        foreach ($controls as $label => $source) {
            $rewritten = WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/LegalKeyword.php');
            $this->assertStringContainsString('Do not edit here', $rewritten ?: '', "The legal spelling rides the whole seam — banner, rewrite, postcondition ({$label}).");
        }
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
        /*
         * The exec-capability guard (t31-ocr20-5, the ocr18-2 doctrine):
         * the parse-probe legs of this test (this probe and the
         * interrupted-spelling probe below) spawn engines; the skip
         * aborts at the first, so the legs below it do not run either —
         * named here, never silently half-run. The detector and rewrite
         * assertions above already passed.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the parse-probe legs cannot run (and the legs below them with them); the detector and rewrite assertions above already passed.');
        }
        $probe = self::scratchPath('rel-use-probe.php');
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

        // A comma-LIST member rides the same splice (the tail judgment
        // owns t31-ocr16-4: the list separator is a legal rider — the
        // next import carries its own trigger through the walk); the
        // rewritten member keeps its meaning beside the untouched
        // non-family one.
        $listed = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock, Other\\Thing;\ninterface ListFixture\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($listed, 'OpenAiOauth', 'shared/src/ListFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock, Other\\Thing;', $rewritten, 'A comma-listed relative member rewrites in place — the separator is a rider the grammar allows, never a refusal.');

        /*
         * OCR round 26 (t31-ocr26-2): the comma once TERMINATED the
         * rider judgment one member early, so a following member
         * carrying no relative trigger of its own was judged by
         * nobody — the main loop's trigger condition skips
         * non-relative members. Driven red at HEAD: this very list
         * spliced its first member and shipped the second's rider
         * bytes verbatim at exit 0 (`use namespace\Clock, Other\Thing
         * SystemClock;` — php -l rejects the shipped line). The
         * judgment walks EVERY member of the list now; the legal
         * list shapes above keep their verdicts.
         */
        foreach (array(
            'plain member with rider bytes' => 'use namespace\\Clock, Other\\Thing SystemClock;',
            'alias on the first member, rider on the second' => 'use namespace\\Clock as C, Other Thing;',
            'kinded member with rider bytes' => 'use namespace\\Clock, function Other\\fn SystemClock;',
        ) as $label => $statement) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n{$statement}\ninterface ListRiderFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/ListRiderFixture.php'),
                "A comma-list member carrying rider bytes must refuse the rewrite ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('rider bytes', $refusal->getMessage(), "The judgment owns every member of the list, past the comma ({$label}).");
        }
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock,;\ninterface EmptyMemberFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/EmptyMemberFixture.php'),
            'An empty member after the comma must refuse.', \RuntimeException::class
        );
        $this->assertStringContainsString('no import member', $refusal->getMessage());

        /*
         * OCR round 30 (t31-ocr30-5): the relative member past the
         * comma keeps its own trigger through the main loop in BOTH
         * lexer spellings. The fused twin always routed — a
         * name-token id rides the member grammar and the main loop's
         * own trigger answers the verdict — while the interrupted
         * twin (a bare T_NAMESPACE at member-start) fell to the
         * EMPTY-MEMBER refusal, a verdict mis-naming a member that IS
         * there (driven red at HEAD: 'the comma is followed by no
         * import member' over `use namespace\Clock, namespace \Forms;`),
         * and the routing comment's claim held for the fused spelling
         * only. The keyword rides the member grammar now and both
         * twins answer the SAME mid-name refusal — one shape for both
         * spellings of the operator, the r11-10 doctrine.
         */
        foreach (array(
            'fused relative member after the comma' => 'use namespace\\Clock, namespace\\Forms;',
            'interrupted relative member after the comma' => 'use namespace\\Clock, namespace \\Forms;',
        ) as $label => $statement) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n{$statement}\ninterface RelativeMemberFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/RelativeMemberFixture.php'),
                "A relative member past the comma must refuse through the main loop's own fences ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('mid-name or in the alias slot', $refusal->getMessage(), "Both lexer spellings of the relative member answer the SAME refusal — the rewrite owns the operator only as the import's leading name (red at HEAD: the interrupted twin wore the empty-member verdict, a mis-named refusal over a member that is there) ({$label}).");
        }

        /*
         * OCR round 31 (t31-ocr31-3): a separator CONSUMED owes a
         * name. The lexer bakes every legal separator into the
         * member's own name tokens (`\Other\Thing` is ONE
         * T_NAME_FULLY_QUALIFIED token), so a bare '\' in the stream
         * is a doubled or dangling spelling the engine rejects at
         * parse time (php -l: "unexpected \") — and the member-start
         * separator branch once consumed it unconditionally, with no
         * state recording separator-consumed-with-no-name, so every
         * spelling below SHIPPED verbatim beside the rewritten name
         * at exit 0 (driven red at HEAD). The open separator answers
         * for exactly one name piece now: a second separator, or one
         * dangling before the terminator, the comma, or the alias,
         * each refuse with the engine's own verdict.
         */
        foreach (array(
            'doubled separators' => 'use namespace\\Clock, \\\\Other;',
            'separator dangling before the terminator' => 'use namespace\\Clock, Other\\;',
            'separator dangling before the comma' => 'use namespace\\Clock, Other\\, Thing;',
            'separator dangling before the alias' => 'use namespace\\Clock, Other\\ as O;',
        ) as $label => $statement) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n{$statement}\ninterface SepMemberFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/SepMemberFixture.php'),
                "A doubled or dangling member separator must refuse the rewrite ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('separator names no member', $refusal->getMessage(), "The member grammar owns the engine's own rejection — the lexer bakes legal separators into the name tokens, so a bare '\\' is a parse error the walk once shipped at exit 0 ({$label}).");
        }
        // The LEGAL fully-qualified member keeps riding verbatim beside
        // the splice — one baked \Name token, never a bare separator.
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock, \\Other\\Thing;\ninterface FqMemberFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/FqMemberFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock, \\Other\\Thing;', $rewritten, 'A fully-qualified member (ONE baked name token, separators inside it) rides untouched — the fence judges the bare-separator spellings only.');
        // The legal aliases in later members keep riding (the member
        // grammar owns `as` past the comma exactly as member 1 does).
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock, function Other\\fn as F, Other as O;\ninterface ListAliasFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/ListAliasFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock, function Other\\fn as F, Other as O;', $rewritten, 'Every LEGAL member shape past the comma keeps its verdict — kind keywords, aliases, piece-spelled names.');

        // A separator-INTERRUPTED relative (the walk reassembles; the
        // splice replaces the whole run) resolves like its contiguous
        // twin.
        $interrupted = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors \\\n Shared \\ Clock;\ninterface InterruptedRelFixture\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($interrupted, 'OpenAiOauth', 'shared/src/InterruptedRelFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\WpConnectors\\Shared\\Clock;', $rewritten, 'An interrupted relative spelling is replaced whole, reassembled like its contiguous twin.');

        /*
         * The EXACT-ROOT member (OCR round 29, t31-ocr29-2): the
         * family check tested only the prefix-with-separator form, so
         * a relative resolving to EXACTLY the family root was refused
         * as a "SIBLING" (driven red at HEAD) — the root is not a
         * sibling, it is the family's own stem, and the member
         * verdict it earns rewrites it to the REWRITTEN root: the
         * below-root tail stays empty and no trailing separator ships
         * (the prefix form's splice over an empty tail would emit
         * `\…\Shared\`, a parse error). The aliased twin keeps its
         * alias through the same splice, and the SIBLING refusal
         * below keeps its own verdict — outside the root is still
         * outside.
         */
        $exactRoot = "<?php\nnamespace Deicod;\nuse namespace\\WpConnectors\\Shared;\ninterface ExactRootFixture\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($exactRoot, 'OpenAiOauth', 'shared/src/ExactRootFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared;', $rewritten, 'A relative resolving to EXACTLY the family root is a MEMBER — it rewrites to the rewritten root itself (red at HEAD: the SIBLING refusal answered a member).');
        $this->assertStringNotContainsString('OpenAiOauth\\Shared\\;', $rewritten, 'The root member carries no trailing separator — the empty below-root tail never rides the prefix form\'s splice.');
        $aliasedRoot = "<?php\nnamespace Deicod;\nuse namespace\\WpConnectors\\Shared as Sh;\ninterface AliasedRootFixture\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($aliasedRoot, 'OpenAiOauth', 'shared/src/AliasedRootFixture.php');
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared as Sh;', $rewritten, 'The aliased exact-root twin keeps its alias through the same member splice.');

        // The other direction: a relative that ESCAPES the family —
        // under a foreign declaration, where the resolution lands
        // outside the vendor prefix — refuses loudly, never rides (in
        // the output it would silently re-resolve against the
        // REWRITTEN declaration, changing its meaning).
        $escaping = "<?php\nnamespace Other\\Tree;\nuse namespace\\Foo\\Bar;\ninterface EscapeFixture\n{\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($escaping, 'OpenAiOauth', 'shared/src/EscapeFixture.php'),
            'An escaping relative use import must refuse the rewrite, never ride verbatim.', \RuntimeException::class
        );
        $this->assertStringContainsString('outside the shared-namespace family', $refusal->getMessage());
        $this->assertStringContainsString('Other\\Tree\\Foo\\Bar', $refusal->getMessage(), 'The refusal names the RESOLVED spelling.');

        // A SIBLING resolution (family, but not under the rewrite's own
        // tree) refuses too — the rewriter owns no sibling spelling.
        $sibling = "<?php\nnamespace Deicod\\WpConnectors;\nuse namespace\\Zai\\Api;\ninterface SiblingFixture\n{\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($sibling, 'OpenAiOauth', 'shared/src/SiblingFixture.php'),
            'A sibling-resolving relative use import must refuse the rewrite.', \RuntimeException::class
        );
        $this->assertStringContainsString('SIBLING', $refusal->getMessage());

        // UNRESOLVABLE: no declaration in effect where the relative
        // stands (and the multi-block resolution rule — the relative
        // resolves against the declaration IN EFFECT, not the file's
        // first).
        $unresolvable = "<?php\nuse namespace\\Foo\\Bar;\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($unresolvable, 'OpenAiOauth', 'shared/src/NoDecl.php'),
            'A relative with no declaration in effect must refuse the rewrite.', \RuntimeException::class
        );
        $this->assertStringContainsString('cannot resolve', $refusal->getMessage());
        $multi_block = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors\\Shared\\Clock;\nnamespace Other;\nuse namespace\\Baz;\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($multi_block, 'OpenAiOauth', 'shared/src/MultiBlock.php'),
            'A relative resolving against a LATER block\'s foreign declaration must refuse, never resolve against the first block.', \RuntimeException::class
        );
        $this->assertStringContainsString('outside the shared-namespace family', $refusal->getMessage(), 'The second block\'s relative resolves against the declaration in effect (Other), not the first block.');

        /*
         * OCR round 4 (t31-ocr4-5): a BRACED namespace block expires at
         * its closing brace. The ledger once let `namespace X { … }`
         * stay in effect to EOF, so a use statement after the block —
         * legal PHP standing in GLOBAL scope — misattributed to the
         * expired declaration (pre-fix this very leg refused as
         * 'outside the shared-namespace family': resolved against
         * Other). Inside the block the declaration still resolves; the
         * post-block verdict is the no-declaration one, exactly the
         * unbraced equivalent (a file with no declaration at all).
         */
        $braced_inside = "<?php\nnamespace Other {\n    use namespace\\Foo;\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($braced_inside, 'OpenAiOauth', 'shared/src/BracedInside.php'),
            'A relative INSIDE a braced block must still resolve against the block\'s declaration (and refuse as the foreign resolution it is).', \RuntimeException::class
        );
        $this->assertStringContainsString('outside the shared-namespace family', $refusal->getMessage(), 'The in-block relative resolved against Other — the block is in effect inside its braces.');
        $this->assertStringContainsString('Other\\Foo', $refusal->getMessage(), 'The refusal names the resolved spelling.');
        $braced_after = "<?php\nnamespace Other {\n    interface InBlock\n    {\n    }\n}\nuse namespace\\Foo;\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($braced_after, 'OpenAiOauth', 'shared/src/BracedAfter.php'),
            'A relative AFTER a closed braced block stands in GLOBAL scope — the block expired at its closing brace, never resolves against it.', \RuntimeException::class
        );
        $this->assertStringContainsString('cannot resolve', $refusal->getMessage(), 'The post-block verdict is the no-declaration-in-effect one — the ledger\'s answer matches the unbraced equivalent.');

        /*
         * OCR round 7 (t31-ocr7-1): the OTHER declaration ledger — the
         * DETECTOR's resolution walk carried the same no-expiry defect
         * the r4-5 round fixed only in the rewriter's: after a braced
         * `namespace Other { … }` its incremental base kept Other in
         * effect to EOF, so a post-block relative resolved
         * `Other\Deicod\…` — NOT family — and laundered past both gates
         * invisible (the pre-fix verdict of this very leg: zero
         * references). The detector rides the ONE ledger now (with the
         * rewriter's, one expiry semantics): post-block resolves
         * GLOBAL, and the family spelling reports under the relative
         * kind exactly like its unbraced control.
         */
        $detector_escape = "<?php\nnamespace Other {\n    interface InBlock\n    {\n    }\n}\n\$x = namespace\\Deicod\\WpConnectors\\Shared\\Clock::class;\n";
        $found = wp_connectors_shared_family_references($detector_escape);
        $this->assertCount(1, $found, 'A family-resolving relative after a closed braced block resolves GLOBAL — the expired declaration never launders it into a non-family name.');
        $this->assertSame(array( 'Deicod\\WpConnectors\\Shared\\Clock', 'relative' ), array( $found[0]['name'], $found[0]['kind'] ), 'The post-block verdict is the global-resolution one, the same answer the rewriter\'s ledger (the r4-5 legs above) hands its walk.');
        // The unbraced control: the same spelling under NO declaration
        // resolves identically — the block's expiry is what changed,
        // nothing else.
        $found = wp_connectors_shared_family_references("<?php\n\$x = namespace\\Deicod\\WpConnectors\\Shared\\Clock::class;\n");
        $this->assertCount(1, $found, 'The global-scope control reports the same reference — the braced file now matches its unbraced equivalent.');

        /*
         * Verifier round t31-ocr4-9: INLINE HTML is not the block's
         * grammar — a close tag inside a braced block exits PHP mode
         * and the block CONTINUES at re-entry (only a CODE '}' closes
         * it), but the masker blanks string/comment bytes only, so an
         * HTML '}' once expired the block early (a resolvable relative
         * refused as 'cannot resolve') and an HTML '{' over-counted
         * depth (a post-block relative spliced against the expired
         * declaration — the misattribution class the expiry kills).
         * Both legs judge through the verdict the ledger hands the
         * resolution, and PHP itself draws the same line (__NAMESPACE__
         * echoes the block's namespace past an HTML '}').
         */
        $html_close = "<?php\nnamespace Other {\n?>\n}\n<?php\n    use namespace\\Foo;\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($html_close, 'OpenAiOauth', 'shared/src/BracedHtmlClose.php'),
            'A relative past an HTML \'}\' but still INSIDE the braced block must resolve against the block — the HTML brace is not the close.', \RuntimeException::class
        );
        $this->assertStringContainsString('outside the shared-namespace family', $refusal->getMessage(), 'The block is still in effect at the use — the refusal is the resolved-foreign one, never \'cannot resolve\'.');
        $html_open = "<?php\nnamespace Other {\n?>\n<div>{</div>\n<?php\n    interface InBlock\n    {\n    }\n}\nuse namespace\\Foo;\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($html_open, 'OpenAiOauth', 'shared/src/BracedHtmlOpen.php'),
            'A relative AFTER a closed braced block stands in GLOBAL scope even when inline HTML carried an extra \'{\' — the HTML brace is not an opener.', \RuntimeException::class
        );
        $this->assertStringContainsString('cannot resolve', $refusal->getMessage(), 'The verdict is the no-declaration-in-effect one — the HTML \'{\' never deepened the block.');

        // The group-use PREFIX shape — a parse-error spelling whose
        // members the rewrite owns no map for — refuses by name.
        $group_prefix = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors\\{Shared\\Clock};\ninterface GroupRelFixture\n{\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($group_prefix, 'OpenAiOauth', 'shared/src/GroupRelFixture.php'),
            'A relative group-use PREFIX must refuse the rewrite.', \RuntimeException::class
        );
        $this->assertStringContainsString('group-use PREFIX', $refusal->getMessage());

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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/GroupMemberFixture.php'),
                "A relative group-use MEMBER must refuse the rewrite, never splice an illegal fully-qualified member ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('group-use MEMBER', $refusal->getMessage(), "The refusal names the member shape ({$label}).");
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
            // ONE leading separator, aligned with its siblings (OCR
            // round 16, t31-ocr16-15f): this row's payload spelled
            // '\\\\Clock' — TWO backslashes in the fixture source —
            // while every sibling carries one ('\\Clock\\SystemClock'
            // et al.); the walk resolved the doubled spelling through
            // the lexer's separator-plus-FQ-piece split, so the row
            // passed by a spelling the round never chose. The
            // intended fixture is the comment-interrupted plain
            // relative operator, same as its siblings.
            'line comment between' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\n// c\n\\Clock;\ninterface InterruptedKeywordFixture\n{\n}\n",
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
        $probe = self::scratchPath('interrupted-rel-probe.php');
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
            /*
             * OCR round 3 (t31-ocr3-4): the MID-NAME and alias-slot
             * spellings. The keyword reaches the walk as the bare
             * T_NAMESPACE behind a separator plus trivia (whitespace or
             * a comment — the comment-interrupted shape arrives as the
             * FUSED T_NAME_RELATIVE the finding names; the
             * uninterrupted `use Foo\namespace\Bar;` spelling demotes
             * the keyword to a plain name piece on this lexer, a LEGAL
             * non-family import the rewrite never sees), and the splice
             * once started AT the keyword: `use Foo\ namespace \Bar;`
             * shipped `use Foo\ \Deicod\…` — a double-separated parse
             * error the postcondition waved through (the glued run
             * reports target-prefixed 'use') — at exit 0 (reproduced
             * through the real rewriter, php -l-verified). The alias
             * slot is the same class: a fully-qualified name spliced
             * where the grammar wants an identifier. All three refuse —
             * the operator is only the grammar's as the import's
             * LEADING name (the controls above: leading fused and
             * interrupted spellings and the function/const kinds still
             * rewrite).
             */
            'mid-name keyword, space-interrupted' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Foo\\ namespace \\Clock;\ninterface MidNameRelFixture\n{\n}\n",
                'mid-name or in the alias slot',
            ),
            'mid-name keyword, comment-interrupted (fused token)' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Foo\\/* c */namespace\\Clock;\ninterface MidNameRelFixture\n{\n}\n",
                'mid-name or in the alias slot',
            ),
            'alias-slot keyword' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Foo as namespace\\Clock;\ninterface AliasSlotRelFixture\n{\n}\n",
                'mid-name or in the alias slot',
            ),
            /*
             * OCR round 16 (t31-ocr16-4): the statement-TAIL riders.
             * The splice covers the keyword through the name run, and
             * everything after the run once rode verbatim beside the
             * rewritten name — `use namespace\Clock SystemClock;`
             * shipped at exit 0 as `use \…\Clock SystemClock;`
             * (driven: php -l rejects the shipped line — the exact
             * 'zip ships the parse-error line' class this step exists
             * to refuse; the postcondition sees only family
             * references, so the rider was judged by nobody). The
             * legal tail is an optional alias before the terminator;
             * the tail is judged through the lexer's own boundaries
             * now, and the direct-brace group spelling (no separator
             * before '{') rides the same refusal.
             */
            'rider bytes after the name run' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock SystemClock;\ninterface TailRiderFixture\n{\n}\n",
                'parse-error bytes ride the relative use import',
            ),
            /*
             * OCR round 16 (t31-ocr16-9): the SEPARATOR-LESS spelling.
             * `use namespace Clock;` is a parse error the engine
             * never accepts (php -l-verified), and the optional-
             * separator follower scan once accepted the name directly
             * behind the keyword — the rewrite silently legalized the
             * parse error into `use \…\Clock;` at exit 0 (driven at
             * HEAD). The separator is required now; the refusal
             * keeps the shared 'not a spelling PHP accepts'
             * vocabulary the bare-keyword row pins.
             */
            'separator-less keyword-name spelling' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace Clock;\ninterface SeplessFixture\n{\n}\n",
                'never legalizes',
            ),
            'rider bytes after the alias' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock as C extra;\ninterface TailRiderFixture\n{\n}\n",
                'after the alias only the terminator may follow',
            ),
            /*
             * The identifier-slot reserved vocabulary (the refutation
             * lens over the round's OWN tail gate, caught in the
             * verifier pass): 'self'/'true'/'int' lex as plain
             * T_STRING, so the first cut of the alias grammar
             * accepted them and the rewrite SHIPPED
             * 'use \…\Clock as self;' — an engine-illegal alias, php
             * -l exit 255 on the shipped bytes. Fourteen spellings,
             * case-insensitively (php -l-derived on this engine):
             * self/parent, the three literals, the type keywords.
             */
            'keyword alias: self' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock as self;\ninterface TailRiderFixture\n{\n}\n",
                'must be one plain identifier',
            ),
            'keyword alias: True (case-folded)' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock as True;\ninterface TailRiderFixture\n{\n}\n",
                'must be one plain identifier',
            ),
            'keyword alias: Int (case-folded type keyword)' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock as Int;\ninterface TailRiderFixture\n{\n}\n",
                'must be one plain identifier',
            ),
            'brace group without its separator' => array(
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\WpConnectors {Shared\\Clock};\ninterface TailRiderFixture\n{\n}\n",
                'parse-error bytes ride the relative use import',
            ),
        );
        foreach ($refusals as $label => $case) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($case[0], 'OpenAiOauth', 'shared/src/BareKeywordFixture.php'),
                "An un-ownable keyword spelling must refuse the rewrite ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString($case[1], $refusal->getMessage(), "The refusal names the shape ({$label}).");
        }
    }

    /**
     * OCR-round-23 pin (t31-ocr23-7): the fused relative operator
     * inside a CLOSURE use list. A closure's `use (…)` is a lexical
     * BINDING list, never a namespace import (the t31-r13-3 fence
     * both walks ride), and a name standing in it is a parse error in
     * every reading — php -l: "unexpected namespace-relative name,
     * expecting variable or '&'" — but the two lexer spellings of the
     * same illegal construct took OPPOSITE verdicts: the INTERRUPTED
     * twin (a bare T_NAMESPACE) hit the bare-keyword fence's refusal,
     * while the FUSED token fell through the walk's use-statement gate
     * (use_open false for a closure list), rode the rewrite verbatim,
     * and — a code position under an owned declaration — passed the
     * postcondition's r8-2 carve-out too: parse-error bytes shipped at
     * exit 0 by lexer accident. The closure-use arm joins the refused
     * class now: the fused spelling refuses like its twin, the fence's
     * vocabulary unchanged.
     */
    public function testAFusedRelativeOperatorInsideAClosureUseListRefusesLikeItsInterruptedTwin(): void
    {
        // RED at HEAD: the fused spelling rides every gate (driven:
        // the rewrite returns normally, the parse-error line intact).
        $fused = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n\$f = function () use (namespace\\WpConnectors\\Shared\\Clock\\SystemClock) {};\ninterface ClosureUseFixture\n{\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($fused, 'OpenAiOauth', 'shared/src/ClosureUseFused.php'),
            'The fused relative operator inside a closure use list must refuse the rewrite — it is the same never-legal construct its interrupted twin refuses (red at HEAD: the spelling rode the rewrite and the postcondition at exit 0).', \RuntimeException::class
        );
        $this->assertStringContainsString('closure use', $refusal->getMessage(), 'The refusal names the closure-use class — the arm\'s own vocabulary.');
        $this->assertStringContainsString('ClosureUseFused.php', $refusal->getMessage(), 'The refusal names the file.');

        // The interrupted twin keeps the bare-keyword fence's own
        // verdict — the fix moved the fused twin TO the refusal, never
        // the twin away from its fence.
        $interrupted = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n\$f = function () use (namespace \\WpConnectors\\Shared\\Clock\\SystemClock) {};\ninterface ClosureUseFixture\n{\n}\n";
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($interrupted, 'OpenAiOauth', 'shared/src/ClosureUseInterrupted.php'),
            'The interrupted twin keeps refusing — the lexer accident that spared the fused spelling never legalized either.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a spelling PHP accepts', $refusal->getMessage(), 'The interrupted twin\'s verdict is the bare-keyword fence\'s own, unchanged.');

        // The control: a real closure use list with VARIABLE bindings
        // rides the rewrite untouched — the arm owns exactly the
        // name-in-list class, never the list itself.
        $legal = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n\$c = 1;\n\$f = function () use (\$c, &\$c) { return \$c; };\ninterface ClosureUseFixture\n{\n}\n";
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace($legal, 'OpenAiOauth', 'shared/src/ClosureUseControl.php');
        $this->assertStringContainsString('use ($c, &$c)', $rewritten, 'A closure use list of variable bindings rides verbatim — the arm refuses names in the list, never the list.');
    }

    /**
     * OCR-round-26 pin (t31-ocr26-1): the relative operator in a
     * TRAIT use position refuses, never rides the family splice. The
     * class-body `use` is a trait import — its relative spelling is
     * LEGAL PHP (php -l clean, and it resolves + loads under the
     * declaration in effect — probed at round time), which made the
     * legal-versus-refuse instinct exactly wrong here: the rewrite
     * walk's fence (wp_connectors_use_opens_import()) draws its line
     * from the FOLLOWER shape, and a name follower opens an import
     * statement at the top level and a trait clause list inside a
     * class body — the same bytes in both. $use_open once armed for
     * the trait spelling, and the splice RETARGETED the trait
     * reference silently through the family map (driven red at HEAD:
     * `class C { use namespace\Clock\SystemClock; }` shipped
     * `use \Deicod\WpConnectors\OpenAiOauth\Shared\Clock\SystemClock;`
     * — a DIFFERENT trait, exit 0). The trait fence derives from the
     * brace-kind stack (the t31-ocr7-7 vocabulary the classifier
     * already rides): a use statement with an 'other' frame below it
     * stands in a trait position and refuses.
     */
    public function testARelativeTraitUseRefusesNeverRetargetsTheTrait(): void
    {
        $cases = array(
            'fused class-body trait use' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass TraitUseFusedFixture\n{\n    use namespace\\Clock\\SystemClock;\n}\n",
            'trait use leading a comma list' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass TraitUseListFixture\n{\n    use namespace\\Clock\\SystemClock, OtherTrait;\n}\n",
            'trait use trailing a comma list' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass TraitUseLateFixture\n{\n    use OtherTrait, namespace\\Clock\\SystemClock;\n}\n",
            'interrupted trait spelling' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass TraitUseInterruptedFixture\n{\n    use namespace \\Clock\\SystemClock;\n}\n",
        );
        foreach ($cases as $label => $source) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/TraitUseFixture.php'),
                "A class-body relative trait use must refuse the rewrite, never retarget the trait ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('TRAIT use position', $refusal->getMessage(), "The refusal names the trait position — the rewrite owns import statements only ({$label}).");
            $this->assertStringContainsString('namespace\\Clock\\SystemClock', $refusal->getMessage(), "The refusal names the spelling ({$label}).");
        }

        // The fence narrows EXACTLY: the top-level import twin of the
        // same bytes keeps its rewrite, and the closure twin keeps its
        // own refusal — the trait carve touched neither verdict.
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace(
            "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse namespace\\Clock\\SystemClock;\ninterface ImportTwinFixture\n{\n}\n",
            'OpenAiOauth',
            'shared/src/ImportTwinFixture.php'
        );
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\SystemClock;', $rewritten, 'The top-level twin of the same bytes still rewrites — the trait fence carved the class-body position only.');
        $closure = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\n\$f = function () use (namespace\\Clock) { return 1; };\ninterface ClosureTwinFixture\n{\n}\n", 'OpenAiOauth', 'shared/src/ClosureTwinFixture.php'),
            'The closure twin keeps its own refusal.', \RuntimeException::class
        );
        $this->assertStringContainsString('closure use(...) list', $closure->getMessage());
    }

    /**
     * OCR-round-16 pin (t31-ocr16-10): the unowned-spelling
     * classifier's brace-kind stack stays balanced through string
     * interpolation. A double-quoted `{$a}` lexes T_CURLY_OPEN plus
     * a PLAIN '}' (`${a}` rides T_DOLLAR_OPEN_CURLY_BRACES the same
     * way), and the plain closer once popped a frame that was never
     * pushed — the stack ran one short per interpolation, an
     * enclosing class frame fell off early, and a trait clause list
     * AFTER an interpolation-bearing method was judged as an IMPORT
     * (driven at HEAD: the classifier handed the trait list the dead
     * 'write one use per line' errand the t31-ocr7-7 doctrine
     * reserves for import lists, while the identical shape minus the
     * interpolation got the anonymous trait verdict — the file's own
     * twin judged by two different doctrines). The interpolation
     * openers push their own 'other' frame now: the stack
     * round-trips balanced, and both twins wear the anonymous
     * verdict their doctrine owns.
     */
    public function testTheClassifierBraceStackStaysBalancedThroughInterpolation(): void
    {
        $head = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait FamilyTrait\n{\n}\nfinal class InterpCarrier\n{\n    public function m(%s)\n    {\n        %s\n    }\n    use Deicod\\WpConnectors\\Shared\\FamilyTrait, OtherTrait;\n}\n";
        $with_interpolation = sprintf($head, '$x', '$v = "{$x}";' . "\n        " . 'return $v;');
        $with_dollar_interpolation = sprintf($head, '$x', 'return "${x}";');
        $without_interpolation = sprintf($head, '', 'return 1;');

        foreach (array('curly interpolation' => $with_interpolation, 'dollar interpolation' => $with_dollar_interpolation) as $label => $source) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/InterpCarrier.php'),
                "The trait-list fixture must refuse the rewrite — its family member rides a comma list no pattern owns ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage(), "The refusal is the postcondition's own ({$label}).");
            $this->assertStringNotContainsString('write one use per line', $refusal->getMessage(), "A TRAIT clause list after an interpolation-bearing method wears its doctrine's anonymous verdict — the stack saw the class frame (red at HEAD: the interpolation's plain '}' ate it and the classifier named the import-list errand, a dead errand for a trait list) ({$label}).");
        }

        // The control: the identical shape minus the interpolation —
        // the anonymous verdict is the twins' shared doctrine, held
        // on both sides of the fix.
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($without_interpolation, 'OpenAiOauth', 'shared/src/InterpCarrier.php'),
            'The interpolation-free control must refuse the rewrite too.', \RuntimeException::class
        );
        $this->assertStringNotContainsString('write one use per line', $refusal->getMessage(), 'The control keeps the anonymous trait verdict — the fix moved the interpolated twin TO it, never the control away.');
    }

    /**
     * OCR-round-30 pin (t31-ocr30-1): the use-statement state survives
     * a TRAIT-ADAPTATION body. The adaptation's grammar-required ';'
     * (`use T { m as n; }`) arrives while the adaptation brace stands
     * open, and the boundary reset once fired THERE — mid-adaptation —
     * so the adaptation's closing '}' was judged outside a use
     * statement and popped the enclosing class's 'other' frame off the
     * brace-kind stack, and every use statement after the class drew
     * its trait fence (t31-ocr26-1) from a corrupted stack. Driven red
     * at HEAD: `use T { m as n; } use namespace\Clock\SystemClock;`
     * inside a class body shipped the second use SPLICED through the
     * family map — a DIFFERENT trait loads, exit 0, the exact
     * retarget the fence exists to refuse. The adaptation-inner ';'
     * rides the adaptation's frame now and the '}' that closes it IS
     * the trait use's terminator, so the stack stays balanced through
     * the block.
     */
    public function testTheUseStateSurvivesATraitAdaptationBody(): void
    {
        // RED at HEAD: the fence disarmed — the following relative use
        // spliced (exit 0), never refused.
        $cases = array(
            'fused trait use after an adaptation' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass AdaptFenceFusedFixture\n{\n    use OtherTrait { m as n; }\n    use namespace\\Clock\\SystemClock;\n}\n",
            'interrupted trait use after an adaptation' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass AdaptFenceInterruptedFixture\n{\n    use OtherTrait { m as n; }\n    use namespace \\Clock\\SystemClock;\n}\n",
            'adaptation on the relative use itself' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass AdaptFenceOwnFixture\n{\n    use namespace\\Clock\\SystemClock { m as n; }\n}\n",
        );
        foreach ($cases as $label => $source) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/AdaptFenceFixture.php'),
                "A relative trait use around an adaptation body must refuse the rewrite, never retarget the trait ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('TRAIT use position', $refusal->getMessage(), "The trait fence answers from a balanced stack — the adaptation's inner ';' no longer disarms it ({$label}).");
        }

        // The control: an adaptation WITHOUT a relative use rides the
        // rewrite verbatim (no relative trigger anywhere in the class).
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace(
            "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass AdaptPlainFixture\n{\n    use OtherTrait { m as n; }\n    use SecondTrait;\n}\ninterface AdaptPlainTail\n{\n}\n",
            'OpenAiOauth',
            'shared/src/AdaptPlainFixture.php'
        );
        $this->assertStringContainsString('use OtherTrait { m as n; }', $rewritten, 'A plain adaptation rides verbatim — the frame fix owns the relative arm only.');
        $this->assertStringContainsString('use SecondTrait;', $rewritten, 'A plain trait use after an adaptation rides verbatim too.');

        // The stack stays balanced BEYOND the class: the top-level
        // import twin of the same relative bytes still rewrites, and a
        // closure use list inside the class keeps its own fence's
        // verdict — the adaptation corrupted neither judgment.
        $rewritten = WpConnectorsBuild::rewriteSharedNamespace(
            "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass AdaptBeyondFixture\n{\n    use OtherTrait { m as n; }\n}\nuse namespace\\Clock\\SystemClock;\ninterface AdaptBeyondTail\n{\n}\n",
            'OpenAiOauth',
            'shared/src/AdaptBeyondFixture.php'
        );
        $this->assertStringContainsString('use \\Deicod\\WpConnectors\\OpenAiOauth\\Shared\\Clock\\SystemClock;', $rewritten, 'The top-level twin after an adaptation-carrying class still rewrites — the stack round-tripped balanced.');
        $closure = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass AdaptClosureFixture\n{\n    use OtherTrait { m as n; }\n    public function m(): int\n    {\n        \$f = function () use (namespace\\Clock) { return 1; };\n\n        return \$f();\n    }\n}\n", 'OpenAiOauth', 'shared/src/AdaptClosureFixture.php'),
            'A closure use list after an adaptation keeps its own refusal.', \RuntimeException::class
        );
        $this->assertStringContainsString('closure use(...) list', $closure->getMessage(), 'The closure fence is untouched — its verdict is its own.');
    }

    /**
     * OCR-round-30 pin (t31-ocr30-2): the unowned-spelling classifier's
     * brace-kind stack stays balanced through a TRAIT-ADAPTATION body.
     * The adaptation's inner ';' once reset the classifier's $in_use
     * while the adaptation brace stood open, the adaptation's closing
     * '}' then popped the enclosing CLASS's 'other' frame (a frame with
     * no matching push — the walk was "outside" a use statement it was
     * still inside), and the stack ran one short per adaptation: a
     * trait clause list AFTER the adaptation was judged as an IMPORT
     * and handed the dead 'write one use per line' errand the
     * t31-ocr7-7 doctrine reserves for import lists (driven at HEAD —
     * the identical shape minus the adaptation wore the anonymous trait
     * verdict; the interpolation pin t31-ocr16-10 is this same defect
     * class through the other unbalanced-pusher door). The inner ';'
     * rides the adaptation's frame now and the closing '}' terminates
     * the trait use at its own brace, so both twins wear the anonymous
     * verdict their doctrine owns.
     */
    public function testTheClassifierBraceStackStaysBalancedThroughATraitAdaptation(): void
    {
        $head = "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait FamilyTrait\n{\n    public function m(): void\n    {\n    }\n}\nfinal class AdaptListCarrier\n{\n%s    use Deicod\\WpConnectors\\Shared\\FamilyTrait, SecondTrait;\n}\n";
        $with_adaptation = sprintf($head, '    use OtherTrait { m as n; }' . "\n");
        $without_adaptation = sprintf($head, '    use OtherTrait;' . "\n");

        foreach (array('adaptation' => $with_adaptation, 'adaptation-free control' => $without_adaptation) as $label => $source) {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::rewriteSharedNamespace($source, 'OpenAiOauth', 'shared/src/AdaptListCarrier.php'),
                "The trait-list fixture must refuse the rewrite — its family member rides a comma list no pattern owns ({$label}).", \RuntimeException::class
            );
            $this->assertStringContainsString('survived the rewrite', $refusal->getMessage(), "The refusal is the postcondition's own ({$label}).");
            $this->assertStringNotContainsString('write one use per line', $refusal->getMessage(), "A TRAIT clause list wears its doctrine's anonymous verdict on both sides of the adaptation — the stack saw the class frame (red at HEAD: the adaptation's early-closed ';' let its '}' eat the class frame and the classifier named the import-list errand, a dead errand for a trait list) ({$label}).");
        }

        // The carve narrows EXACTLY: a genuine import list at the top
        // level keeps its named errand — the fix moved the trait twin
        // TO the anonymous verdict, never the import away from its own.
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass AdaptImportCarrier\n{\n    use OtherTrait { m as n; }\n}\nuse Deicod\\WpConnectors\\Shared\\Clock\\SystemClock, Other\\Thing;\ninterface AdaptImportTail\n{\n}\n", 'OpenAiOauth', 'shared/src/AdaptImportCarrier.php'),
            'The genuine import list must refuse the rewrite too.', \RuntimeException::class
        );
        $this->assertStringContainsString('write one use per line', $refusal->getMessage(), 'The import-list label still fires for a real import — the adaptation fix owns the trait position only.');
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

        /*
         * OCR round 16 (t31-ocr16-7): the legal NUL escape. \u{0} is
         * a codepoint the engine resolves (eval-verified: it equals
         * the NUL byte), and the unescaper's exclusive `> 0` range
         * guard dropped it into the unrecognized-escape branch — the
         * literal '\u{0}' bytes kept in the value (driven at HEAD),
         * a value no runtime would compute for the literal. The
         * empty-braces spelling stays literal: the engine itself
         * refuses \u{} at compile time, never resolving it.
         */
        $this->assertSame("\0", wp_connectors_unescape_php_string_literal('"', '\\u{0}'), '\u{0} resolves to the NUL byte exactly as the engine computes it.');
        $this->assertSame("\\u{}", wp_connectors_unescape_php_string_literal('"', '\\u{}'), 'The EMPTY braces spelling stays literal — the engine never resolves it (a compile error), so neither does the unescaper.');
        $this->assertSame("\\u{110000}", wp_connectors_unescape_php_string_literal('"', '\\u{110000}'), 'An over-range codepoint stays literal — the unrecognized branch keeps the engine\'s own refusal spelling.');

        /*
         * OCR round 23 (t31-ocr23-4): the hex-VALIDATED braces. The
         * engine refuses every non-hex-digit spelling at compile time
         * (php -l-verified: "\u{zz}" and "\u{ 41 }" are both Invalid
         * UTF-8 codepoint escape sequences), but hexdec() ignores the
         * offending bytes — '\u{zz}' modeled as the NUL byte and
         * '\u{ 41 }' as 'A', values no runtime computes for literals
         * the engine never compiles, and a DEPRECATION raised mid-gate
         * on top (the 8.5 'Invalid characters passed' notice, the
         * r11-8 doctrine this battery exists for). The digits must be
         * hex ALONE: such spellings stay literal, the engine's own
         * refusal spelling kept like the empty-braces and over-range
         * siblings above.
         */
        $hex_deprecations = array();
        set_error_handler(static function (int $errno, string $message) use (&$hex_deprecations): bool {
            if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
                $hex_deprecations[] = $message;
            }

            return true;
        });
        try {
            $non_hex = wp_connectors_unescape_php_string_literal('"', '\\u{zz}\\u{1z}\\u{ 41 }');
        } finally {
            restore_error_handler();
        }
        $this->assertSame("\\u{zz}\\u{1z}\\u{ 41 }", $non_hex, 'Non-hex braces spellings stay literal — the engine refuses them at compile time (red at HEAD: \'\\u{zz}\' modeled as the NUL byte, \'\\u{ 41 }\' as \'A\'), so the unescaper keeps the engine\'s own refusal spelling.');
        $this->assertSame(array(), $hex_deprecations, 'A non-hex \u{} spelling must not raise the hexdec() deprecation mid-gate — the hex judgment precedes the conversion (the r11-8 doctrine).');
        $this->assertSame('A', wp_connectors_unescape_php_string_literal('"', '\\u{41}'), 'The plain hex control still resolves — the guard narrows exactly the non-hex class.');

        /*
         * The verifier close (rd-2, the MAGNITUDE shape): hexdec()
         * answers a FLOAT once the digit run outgrows the int range,
         * and the (int) cast collapsed it to 0 — the range check read
         * the COLLAPSED int, '\u{FFFFFFFFFFFFFFFF}' resolved as the
         * NUL byte, and the cast itself raised 'the float … is not
         * representable as an int' MID-GATE (the r11-8 class), while
         * the engine refuses every over-magnitude spelling at compile
         * time (php -l-verified, driven). The comparison keeps the
         * float now: an over-magnitude run refuses the range and
         * stays literal, warning-free.
         */
        $cast_warnings = array();
        set_error_handler(static function (int $errno, string $message) use (&$cast_warnings): bool {
            if (E_WARNING === $errno || E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
                $cast_warnings[] = $message;
            }

            return true;
        });
        try {
            $huge = wp_connectors_unescape_php_string_literal('"', '\\u{FFFFFFFFFFFFFFFF}\\u{1000000}');
        } finally {
            restore_error_handler();
        }
        $this->assertSame("\\u{FFFFFFFFFFFFFFFF}\\u{1000000}", $huge, 'Over-magnitude hex runs stay literal — the engine refuses them at compile time (red at HEAD: the 2^63-over run collapsed through the (int) cast and modeled as the NUL byte), and the range check reads the digit run\'s magnitude, never a collapsed int.');
        $this->assertSame(array(), $cast_warnings, 'An over-magnitude \u{} spelling must raise nothing mid-gate — no deprecation from hexdec, no cast warning from the collapsed float (the r11-8 doctrine).');

        /*
         * The SURROGATE adjudication (OCR round 28, t31-ocr28-4 — the
         * finding's engine premise driven and REFUTED): the round
         * claimed the engine refuses 0xD800–0xDFFF at compile time
         * "with the very error" the non-hex comment cites, but the
         * DRIVEN engine (8.5.10) compiles every surrogate spelling
         * clean and computes its raw three-byte UTF-8 spelling
         * ('\u{D800}' → ED A0 80 — bin2hex-driven) — the RFC-era
         * refusal was lifted upstream, and only the over-range and
         * non-hex spellings still refuse. The model mirrors the
         * ENGINE, never the RFC (the ocr23-4 charter, both
         * directions): keeping the class literal would invent a
         * refusal the running engine does not give. The pin drives the
         * engine ITSELF as the oracle (the ocr16-7 eval idiom) over
         * the range's every boundary — a future engine generation that
         * refuses the class again fails THIS pin loudly, naming the
         * drift instead of leaving a silently-wrong model; on an
         * engine that refuses, the eval itself is the compile-time
         * refusal, and that failure is the honest signal, never a
         * red to suppress.
         */
        foreach (array('D7FF', 'D800', 'DBFF', 'DFFF', 'E000') as $codepoint) {
            $engine_value = eval('return "\u{' . $codepoint . '}";');
            $this->assertSame(
                $engine_value,
                wp_connectors_unescape_php_string_literal('"', '\\u{' . $codepoint . '}'),
                "The unescaper answers the engine's own bytes for \\u{{$codepoint}} — surrogate spellings included (the driven engine resolves them; the RFC-era refusal is not this engine's behavior, and the model mirrors the engine, both directions of the ocr23-4 charter)."
            );
        }

        /*
         * The hex-arm CASE premise, driven and refuted (OCR round 31,
         * t31-ocr31-2 — the ocr28-4 engine-truth doctrine over this
         * finding's own premise): the finding claimed the engine
         * resolves only lowercase \x and demanded '\X' ride the
         * literal doctrine. DRIVEN AT FIX TIME on the runner engine
         * (8.5.10): the double-quoted "\X41" computes 'A' exactly
         * like its lowercase twin — the hex handler reads BOTH cases —
         * so the unescaper's answer was already the engine's own on
         * every spelling, and the demanded narrowing would have
         * INVENTED the exact divergence it accused (a literal '\X41'
         * where every runtime computes 'A' — values no runtime
         * computes are what BOTH directions of the mirror charter
         * refuse, the ocr23-4 doctrine). The pin drives the engine
         * itself as the oracle, both cases and the no-digit tails
         * included; a future engine that stops resolving \X fails
         * this pin loudly, naming the drift, and the arm narrows
         * with it.
         */
        foreach (array('\\x41', '\\X41', '\\x4f', '\\X4F', '\\x', '\\X', '\\xg', '\\Xg') as $escape) {
            $engine_value = eval('return "' . $escape . '";');
            $this->assertSame(
                $engine_value,
                wp_connectors_unescape_php_string_literal('"', $escape),
                "The hex arm mirrors the engine for '{$escape}' — both cases decode, the no-digit and non-hex tails keep their literal bytes, the engine's own answer on every spelling."
            );
        }
    }

    /**
     * OCR-round-16 pin (t31-ocr16-8): the sibling pattern's exclusion
     * lookahead exists only when a tail does. An empty $excluded_tails
     * built the lookahead over an EMPTY alternation — and an empty
     * alternation matches at every position, so the negative lookahead
     * failed at every separator-following position and the pattern
     * silently degraded below its own baseline (driven at HEAD: the
     * bare vendor stem matched while the stem plus a sibling
     * continuation — the vocabulary's own core spelling — did not).
     * The pattern's only caller always passes at least the source
     * tail, so the empty shape is a latent seam of the helper —
     * pinned here so the seam degrades to no-exclusions, never to a
     * separator-position artifact.
     */
    public function testTheSiblingPatternsWithNoExcludedTailsBehaveAsTheBaseline(): void
    {
        $pattern = wp_connectors_family_sibling_pattern(array());

        $this->assertSame(1, preg_match($pattern, 'deicod\\wpconnectors'), 'The bare vendor stem matches — the baseline core.');
        $this->assertSame(1, preg_match($pattern, 'Deicod\\WpConnectors\\Zai'), 'The stem plus a sibling continuation matches — no separator-position artifact (red at HEAD: the empty alternation made the lookahead forbid every separator).');
        $this->assertSame(0, preg_match($pattern, 'other vendor text'), 'Unrelated text never matches.');
        $this->assertSame(0, preg_match($pattern, 'xdeicod\\wpconnectors'), 'A name fragment never matches — the lookbehind holds in the empty-tails shape too.');

        // The with-tails control: the SAME spellings judge identically
        // through the exclusion-carrying pattern (the tail itself
        // excluded, the sibling continuation matched).
        $with_tails = wp_connectors_family_sibling_pattern(array('shared'));
        $this->assertSame(1, preg_match($with_tails, 'Deicod\\WpConnectors\\Zai'), 'The with-tails baseline matches the sibling continuation — the empty-tails shape agrees with it, never less.');
        $this->assertSame(0, preg_match($with_tails, 'Deicod\\WpConnectors\\Shared'), 'The excluded tail itself does not match through the with-tails control.');
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
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($drift, 'ExampleConnector', 'shared/src/Drift.php'),
            'A docblock naming a sibling under the building plugin\'s own segment must refuse the rewrite — never ship the dangling spelling the sweep refuses.', \RuntimeException::class
        );
        $this->assertStringContainsString('Drift.php', $refusal->getMessage());
        $this->assertStringContainsString('Deicod\\WpConnectors\\ExampleConnector', $refusal->getMessage());
        $target_found = array();
        foreach (wp_connectors_shared_family_references($declaration . "/** @throws Deicod\\\\WpConnectors\\\\OpenAiOauth\\\\Shared\\\\Clock */\ninterface TargetReportFixture\n{\n}\n", 'Deicod\\WpConnectors\\OpenAiOauth\\Shared') as $reference) {
            if ('declaration' !== $reference['kind']) {
                $target_found[] = array( $reference['name'], $reference['kind'] );
            }
        }
        $this->assertSame(array( array( 'Deicod\\WpConnectors\\OpenAiOauth\\Shared', 'comment' ) ), $target_found, 'A double-backslash target spelling keeps its dedicated target report, not a sibling stem.');

        // End to end through the build's postcondition: the docblock
        // sibling REFUSES the rewrite, naming the sibling.
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace($declaration . "/**\n * @throws \\Deicod\\WpConnectors\\Zai\\ApiClient\n */\ninterface DocSiblingStore\n{\n}\n", 'OpenAiOauth', 'shared/src/DocSibling.php'),
            'A docblock naming a sibling must refuse the rewrite — the embedded copy would ship a reference to a namespace that does not exist inside the plugin.', \RuntimeException::class
        );
        $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
        $this->assertStringContainsString('DocSibling.php', $refusal->getMessage());
        $this->assertStringContainsString('Deicod\\WpConnectors\\Zai', $refusal->getMessage(), 'The refusal names the sibling.');
        $this->assertStringContainsString('comment position', $refusal->getMessage(), 'The refusal names the text position.');
    }

    /**
     * OCR round 28 (t31-ocr28-1): the heredoc text-lens state had no EOF
     * flush — the SAME totality gap the name walk's group-prefix EOF
     * flush closed one round earlier (t31-ocr27-3), missed in the
     * sibling lens of the same detector. A source truncated inside a
     * heredoc never tokenizes T_END_HEREDOC, and the flush lived inline
     * under that handler alone, so the open state died with the loop and
     * the body's findings dropped without their report (red at HEAD:
     * zero family references where the terminated twin reports two).
     * The label handler and EOF ride the ONE flush closure now; every
     * truncation spelling answers its violation exactly as its
     * terminated twin does — heredoc and nowdoc alike, the interpolated
     * twin keeping its text-only verdict (the ledgered K1 boundary: a
     * runtime-built value is never judged by value).
     */
    public function testAHeredocTruncatedAtEndOfFileStillAnswersItsFindings(): void
    {
        $heredoc_truncated = "<?php\nnamespace Deicod;\n\$x = <<<EOT\nDeicod\\WpConnectors\\Shared\\Clock\n";
        $heredoc_terminated = $heredoc_truncated . "EOT;\n";
        $this->assertSame(
            wp_connectors_shared_family_references($heredoc_terminated),
            wp_connectors_shared_family_references($heredoc_truncated),
            'A heredoc truncated at EOF reports exactly what its terminated twin reports — EOF is the last boundary, and the flush the label handler rides never depends on the label arriving.'
        );

        // The nowdoc twin: no escape resolution either way, one verdict
        // path shared with its terminated twin.
        $nowdoc_truncated = "<?php\nnamespace Deicod;\n\$x = <<<'EOT'\nDeicod\\WpConnectors\\Shared\\Clock\n";
        $nowdoc_terminated = $nowdoc_truncated . "EOT;\n";
        $this->assertSame(
            wp_connectors_shared_family_references($nowdoc_terminated),
            wp_connectors_shared_family_references($nowdoc_truncated),
            'A NOWDOC truncated at EOF reports exactly what its terminated twin reports — the truncation class owns both enclosure flavors.'
        );

        /*
         * The interpolated twin keeps its SPLIT verdict under the same
         * truncation: the text lens still judges the body's chunks
         * (the stem finding), while the value lens stands down — a
         * dynamic body is runtime-built, the K1 boundary, and EOF
         * inherits the label handler's split, never a value verdict
         * the terminated twin would not give.
         */
        $dynamic_truncated = "<?php\nnamespace Deicod;\n\$y = 1;\n\$x = <<<EOT\nDeicod\\WpConnectors\\Shared\\Clock\n\$y\n";
        $dynamic_terminated = $dynamic_truncated . "EOT;\n";
        $this->assertSame(
            wp_connectors_shared_family_references($dynamic_terminated),
            wp_connectors_shared_family_references($dynamic_truncated),
            'An interpolated heredoc truncated at EOF keeps the terminated twin\'s split verdict — text findings on the chunks, never a value finding over a runtime-built body.'
        );
        $this->assertContains(array( 'name' => 'Deicod\\WpConnectors\\Shared', 'lower' => 'deicod\\wpconnectors\\shared', 'kind' => 'string' ), array_map(static function (array $reference): array {
            return array( 'name' => $reference['name'], 'lower' => $reference['lower'], 'kind' => $reference['kind'] );
        }, wp_connectors_shared_family_references($dynamic_truncated)), 'The truncated dynamic body still carries its text finding — the truncation never drops what the lens sees.');

        /*
         * The empty-body control: a truncation with NOTHING to report
         * reports nothing — the flush is a boundary, not a fence that
         * invents findings for a body carrying none.
         */
        $this->assertSame(array(), wp_connectors_shared_family_references("<?php\nnamespace Deicod;\n\$x = <<<EOT\n"), 'A truncated heredoc whose body carries no finding reports none — the EOF flush judges the body, it never manufactures one.');

        // End to end through the build's postcondition: the truncated
        // body's finding REFUSES the rewrite (red at HEAD: exit 0, the
        // finding dropped before any gate could judge it).
        $refusal = $this->refusalOf(
            fn() => WpConnectorsBuild::rewriteSharedNamespace("<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass TruncStore\n{\n    public function name(): string\n    {\n        return <<<EOT\nDeicod\\WpConnectors\\Shared\\Clock\n", 'OpenAiOauth', 'shared/src/Trunc.php'),
            'A family spelling inside a heredoc truncated at EOF must refuse the rewrite — the truncation drops the label, never the finding.', \RuntimeException::class
        );
        $this->assertStringContainsString('survived the rewrite', $refusal->getMessage());
        $this->assertStringContainsString('Trunc.php', $refusal->getMessage());
        $this->assertStringContainsString('string position', $refusal->getMessage(), 'The refusal names the string position — the heredoc body\'s own kind.');
    }

    /**
     * OCR round 31 (t31-ocr31-1): the heredoc text-lens state is a
     * STACK. The lexer genuinely produces a T_START_HEREDOC while
     * another heredoc is still open — a heredoc nested inside the
     * outer body's interpolation ({$a[<<<K … K]}, tokenized and driven
     * on this engine) — and the four scalars the lens once carried
     * were clobbered by the inner open: the outer body's chunks
     * collected before the nesting were lost with no flush of their
     * own (red at HEAD: the outerhead finding dropped while the
     * nested body's survived), the outer's dynamic mark reset, the
     * offsets re-anchored to the inner's start. Every nesting level
     * answers its own verdict now, at its own byte offset; the
     * single-level spellings keep theirs (the t31-ocr28-1 battery one
     * method up).
     */
    public function testAHeredocNestedInsideTheOuterBodysInterpolationScansBothBodies(): void
    {
        $nested = "<?php\nnamespace Deicod;\n\$a = array();\n\$x = <<<EOT\nouterhead Deicod\\WpConnectors\\Shared\\Clock\n{\$a[<<<K\nnested Deicod\\WpConnectors\\Shared\\Storage\nK]}\noutertail Deicod\\WpConnectors\\Shared\\Widget\nEOT;\n";
        $by_offset = array();
        foreach (wp_connectors_shared_family_references($nested) as $reference) {
            $by_offset[ $reference['offset'] ] = $reference['name'];
        }
        ksort($by_offset);
        $this->assertSame(array(
            60 => 'Deicod\\WpConnectors\\Shared',
            109 => 'Deicod\\WpConnectors\\Shared',
            158 => 'Deicod\\WpConnectors\\Shared',
        ), $by_offset, 'Both bodies of the nested spelling scan at their own byte offsets — the outerhead chunk (60) is the one the clobbered state dropped at HEAD; the nested body (109) and the outer tail chunk (158) are the two it kept; each nesting level carries its own frame (chunks, offset, quote, dynamic), the label closes the innermost open, and EOF flushes every frame still open.');
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
            $this->assertTrue(
                true === ($opened = $zip->open($good, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr10-16).',
                    $good,
                    var_export($opened, true)
                )
            );
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
            // Root-runner skip (t31-ocr4-1): uid 0 reads the staged
            // source through mode 0000, close() succeeds, and the
            // finalization refusal never fires.
            $this->skipChmod0000LegOnRootRunner('the staged-source chmod-0000 leg of the finalization pin');
            file_put_contents($staged, 'staged content');
            chmod($staged, 0000);
            $zip = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $zip->open($bad, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr10-16).',
                    $bad,
                    var_export($opened, true)
                )
            );
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
            WpHarness::releaseScratch($scratch);
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
            WpHarness::releaseScratch($scratch);
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
        // The whole pin is link-bearing (t31-ocr11-8): the capability
        // probe gates it visibly — a bare symlink() would error the
        // suite on a host without the privilege.
        if (! self::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the linked shared-source refusal cannot be driven on it.');
        }
        $scratch = self::scratchPath('embed-symlink');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A symlinked directory inside shared/src must refuse the embed build, never ship a library that silently drops it.', \RuntimeException::class
            );
            $this->assertStringContainsString('symlink', $refusal->getMessage());
            $this->assertStringContainsString('LinkedDir', $refusal->getMessage());
            $this->assertSame(array(), glob($scratch . '/dist/*.zip') ?: array(), 'The refused build must leave no zip behind.');
            $this->assertNoStageTree($scratch . '/dist', 'example-connector');
        } finally {
            WpHarness::releaseScratch($scratch);
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
        /*
         * The exec-capability guard (t31-ocr21-2, the ocr18-2/ocr16-12
         * doctrine): both runs (the full build and the --slug rebuild)
         * ride a spawned build.php, and on a disable_functions host the
         * first escaped argument was an undefined-function \Error
         * mid-test.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the slug-rebuild leg cannot run (both the full run and the partial rebuild ride a spawned CLI).');
        }
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
            // exec() APPENDS by ref — reset, or this run's lines ride
            // the first run's (t31-ocr8-11).
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/bin/build.php') . ' --slug=alpha-demo 2>&1', $output, $exit);
            $this->assertSame(0, $exit);

            $rebuiltManifest = (string) file_get_contents($manifestPath);
            $this->assertStringContainsString('connectors-alpha-demo-1.0.0.zip', $rebuiltManifest, 'The rebuilt plugin keeps its own entry.');
            $this->assertStringContainsString('connectors-beta-demo-1.0.0.zip', $rebuiltManifest, 'The unbuilt plugin keeps its entry (the old pre-run wipe dropped it).');
            $this->assertSame($betaSidecarBefore, (string) file_get_contents($betaZip . '.sha256'), 'The unbuilt plugin\'s sidecar survives byte-for-byte.');
            $this->assertFileExists($betaZip, 'The unbuilt plugin\'s zip survives.');
        } finally {
            WpHarness::releaseScratch($repo);
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
        /*
         * The exec-capability guard (t31-ocr21-2, the ocr18-2/ocr16-12
         * doctrine): both runs (the successful full build and the
         * failing rebuild) ride a spawned build.php, and on a
         * disable_functions host the first escaped argument was an
         * undefined-function \Error mid-test.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the failing-rebuild leg cannot run (both the good run and the failing rebuild ride a spawned CLI).');
        }
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

            /*
             * exec() APPENDS by ref (t31-ocr8-11): without the reset,
             * this run's fragments were asserted over the first run's
             * lines too — an earlier run's output could satisfy a
             * fragment the failing run never printed.
             */
            $output = array();
            $exit = 0;
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
            WpHarness::releaseScratch($repo);
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
        $tempPlugin = self::scratchPath('phpcase-containment') . '/upper-demo';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
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
            WpHarness::releaseScratch(dirname($tempPlugin));
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
        $refusal = $this->refusalOf(
            fn() => $guard->invoke(null, null, 'namespace declaration rewrite', 'shared/src/Http/Url.php'),
            'A null preg_replace result must refuse the rewrite, never cast to an empty file.', \RuntimeException::class
        );
        $this->assertStringContainsString('aborted (PCRE)', $refusal->getMessage());
        $this->assertStringContainsString('namespace declaration rewrite', $refusal->getMessage());
        $this->assertStringContainsString('shared/src/Http/Url.php', $refusal->getMessage());

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
        $scratch = self::scratchPath('publish-check');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        try {
            // (a) The sidecar landing path blocked: the pre-flight
            // refuses before anything lands — no zip, no manifest, the
            // blocking directory untouched.
            mkdir($scratch . '/dist/' . self::fixtureZipName() . '.sha256');
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A blocked sidecar landing must refuse the build, never exit 0 with a half-described artifact set.', \RuntimeException::class
            );
            $this->assertStringContainsString('not a regular file', $refusal->getMessage());
            $this->assertStringContainsString(self::fixtureZipName() . '.sha256', $refusal->getMessage());
            $this->assertFileDoesNotExist($scratch . '/dist/' . self::fixtureZipName(), 'Nothing lands when the pre-flight refuses.');
            $this->assertFileDoesNotExist($scratch . '/dist/checksums.txt', 'No manifest may land beside a refused landing.');
            rmdir($scratch . '/dist/' . self::fixtureZipName() . '.sha256');

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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                'A blocked manifest landing must refuse the build too.', \RuntimeException::class
            );
            $this->assertStringContainsString('not a regular file', $refusal->getMessage());
            $this->assertStringContainsString('checksums.txt', $refusal->getMessage());
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
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-25 pin (t31-ocr25-2): the landing order is the ARCHIVE
     * first, descriptors after — a rename refusal the pre-flight cannot
     * see (the target IS a regular file; EPERM at the call) once
     * refused at the archive landing AFTER the descriptors had moved,
     * stranding the NEW checksum beside the OLD zip: a descriptor
     * naming a release that is not the artifact standing beside it.
     * The planted refusal rides the immutable flag, and the capability
     * is probed by DOING it to a scratch file (the t31-ocr10-14
     * doctrine — the ANSWER is the signal, never function_exists):
     * unprivileged hosts skip visibly (no CAP_LINUX_IMMUTABLE — the
     * t31-ocr4-1 root-runner premise, probed this direction too); the
     * rebuild carries a probe asset so its checksum genuinely differs
     * from the prior set's (the build is deterministic — an identical
     * rebuild would strand a byte-identical checksum, the strand
     * invisible).
     */
    public function testARefusedArchiveRenameStrandsNoChecksumBesideTheOldZip(): void
    {
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the planted rename refusal rides chattr through a spawned engine.');
        }

        $scratch = self::distDir() . '/.landing-refusal-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch . '/dist', 0755, true);
        $this->copyFixturePlugin($scratch . '/plugin/example-connector');

        // The capability probe: chattr +i on a scratch file — a host
        // without CAP_LINUX_IMMUTABLE (every unprivileged runner) cannot
        // construct the planted refusal at all.
        $probe = $scratch . '/immutable-probe';
        file_put_contents($probe, 'capability probe');
        exec('chattr +i ' . escapeshellarg($probe) . ' 2>&1', $probeOutput, $probeExit);
        if (0 !== $probeExit) {
            WpHarness::releaseScratch($scratch);
            $this->markTestSkipped('This host cannot set the immutable flag (no CAP_LINUX_IMMUTABLE — the unprivileged runner; the t31-ocr4-1 doctrine probed by doing it): the planted archive-rename refusal is unconstructible here.');
        }
        exec('chattr -i ' . escapeshellarg($probe));
        unlink($probe);

        try {
            // The prior good set.
            $zipPath = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $sidecarPath = $zipPath . '.sha256';
            $manifestPath = $scratch . '/dist/checksums.txt';
            $sidecarBefore = (string) file_get_contents($sidecarPath);
            $manifestBefore = (string) file_get_contents($manifestPath);

            // The rebuild's bytes differ (a probe asset): its checksum
            // differs too, so a stranded descriptor would show.
            file_put_contents($scratch . '/plugin/example-connector/assets/landing-probe.txt', "landing-refusal probe\n");

            // The planted refusal: a regular-file target the pre-flight
            // passes and the rename cannot replace.
            exec('chattr +i ' . escapeshellarg($zipPath));
            try {
                $refusal = $this->refusalOf(
                    fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                    'A refused archive rename must fail the build loudly, never exit 0.', \RuntimeException::class
                );
                $this->assertStringContainsString('cannot land the archive', $refusal->getMessage(), 'The refusal names the landing that refused.');

                /*
                 * THE STRAND (red at HEAD, where the descriptors had
                 * already landed when the archive rename refused): the
                 * prior set stands WHOLE — the old sidecar and the old
                 * manifest, never the new checksum beside the old zip.
                 */
                $this->assertSame($sidecarBefore, (string) file_get_contents($sidecarPath), 'The prior sidecar stands byte-for-byte — a checksum never describes an artifact that is not standing.');
                $this->assertSame($manifestBefore, (string) file_get_contents($manifestPath), 'The prior manifest stands byte-for-byte — the refused run\'s entry never landed.');
                $this->assertSame(array(), glob($scratch . '/dist/.*' . basename($zipPath) . '.tmp-*') ?: array(), 'The staging temps are released by the failure\'s finally.');
            } finally {
                exec('chattr -i ' . escapeshellarg($zipPath));
            }

            // Recovery: the same inputs rebuild cleanly once the flag is
            // gone (the refused landing left nothing behind but the
            // prior set it kept whole).
            $rebuilt = WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist');
            $this->assertSame($zipPath, $rebuilt);
            $this->assertNotSame($sidecarBefore, (string) file_get_contents($sidecarPath), 'The recovery build lands its own checksum — the probe asset made it a different artifact.');
        } finally {
            WpHarness::releaseScratch($scratch);
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
        /*
         * The exec-capability guard (t31-ocr26-9, the ocr20-5 doctrine):
         * the verdict under pin IS the internal php -l spawn's output —
         * on a disable_functions host the spawn was an
         * undefined-function \Error, never a visible skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the inspector\'s internal php -l spawn cannot run, so the .PHP-syntax verdict cannot be driven.');
        }

        $zipPath = self::distDir() . '/connectors-phplint-demo-1.0.0.zip';
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $head = "Plugin Name:       phplint-demo\nVersion:           1.0.0\nRequires at least: 6.9\nRequires PHP:      8.2\nLicense:           GPL-2.0-or-later\nText Domain:       phplint-demo\nAuthor:            x\n";
        $main = "<?php\n/**\n * {$head} */\ndefine( 'PHPLINT_DEMO_VERSION', '1.0.0' );\n";
        $zip->addFromString('phplint-demo/phplint-demo.php', $main);
        // The parse error rides the '.PHP' spelling the exact-case check skipped.
        $zip->addFromString('phplint-demo/src/Broken.PHP', "<?php\nnamespace Deicod\\WpConnectors\\PhplintDemo\\;\nclass Broken {\n");
        $zip->close();

        try {
            $violations = wp_connectors_inspect_artifact($zipPath, self::scratchPath('inspect-phplint'));
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
        $scratch = self::scratchPath('rewrite-refuse');
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
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
            $this->assertTrue(
                true === ($opened = $zip->open($zipPath)),
                sprintf(
                    'The zip must open for the entry read: %s (ZipArchive::open() returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                    $zipPath,
                    var_export($opened, true)
                )
            );
            $embedded = (string) $zip->getFromName('example-connector/src/Shared/GroupUse.php');
            $zip->close();
            $this->assertStringContainsString('use Deicod\\WpConnectors\\{' . $suffix . '\\Shared\\Clock\\ClockInterface};', $embedded, 'A group-use member must ship REWRITTEN, never pointing at the source namespace.');
            unlink($scratch . '/shared/src/GroupUse.php');

            foreach ($hostile_sources as $label => $source) {
                file_put_contents($scratch . '/shared/src/Hostile.php', $source);
                $refusal = $this->refusalOf(
                    fn() => WpConnectorsBuild::buildPlugin($scratch . '/plugin/example-connector', $scratch . '/dist'),
                    "A shared source carrying an unrewritable namespace spelling ({$label}) must refuse the build, never package it.", \RuntimeException::class
                );
                $this->assertStringContainsString('survived the rewrite', $refusal->getMessage(), "The refusal must say what happened ({$label}).");
                $this->assertStringContainsString('shared/src/Hostile.php', $refusal->getMessage(), "The refusal must name the offending file ({$label}).");
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
            WpHarness::releaseScratch($scratch);
        }
    }

    /*
     * The prune's name/checksum split (t31-ocr14-3): the writer joins
     * name . '  ' . checksum, and an entry name containing a double
     * space must survive the split — the first-gap spelling read
     * 'double  space.zip' as 'double' and pruned the LIVE entry.
     */
    public function testTheManifestPruneSplitsAtTheWritersSeparatorNotTheFirstNameGap()
    {
        $scratch = self::distDir() . '/.prune-dblspace-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::releaseScratch($scratch);
        }
        mkdir($scratch, 0755, true);
        try {
            // The live artifact whose NAME carries a double space (a
            // plugin-dir basename spelling the writer would faithfully
            // join), plus a genuinely stale line — the merge keeps the
            // first and drops the second.
            $artifact = $scratch . '/double  space.zip';
            file_put_contents($artifact, 'artifact bytes');
            $digest = hash_file('sha256', $artifact);
            file_put_contents(
                $scratch . '/checksums.txt',
                "double  space.zip  {$digest}\nvanished.zip  " . str_repeat('a', 64) . "\n"
            );

            $merge = new \ReflectionMethod(WpConnectorsBuild::class, 'manifestLinesWithout');
            $lines = $merge->invoke(null, $scratch . '/checksums.txt', 'unrelated.zip');

            $this->assertSame(
                array("double  space.zip  {$digest}"),
                $lines,
                'The double-space entry name survives the split (red as the first-gap spelling: it read "double", named no file, and pruned the live entry) while the vanished artifact\'s line dies by the regeneration rule.'
            );
        } finally {
            WpHarness::releaseScratch($scratch);
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
            WpHarness::releaseScratch($scratch);
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
            // Its checksum SIDECAR stands beside the gone zip first —
            // the shape the t31-ocr35-2 reclaim answers (red at HEAD:
            // the sidecar outlived the prune that dropped its entry).
            $this->assertFileExists($zipBeta . '.sha256', 'The vanished connector\'s sidecar stands beside the gone zip before the regeneration — the shape the reclaim leg names.');
            unlink($zipBeta);
            WpConnectorsBuild::buildPlugin($plugins['alpha-demo'], $scratch . '/dist');

            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($manifestPath)), static function ($line): bool {
                return '' !== $line;
            }));
            $this->assertCount(1, $lines, 'The vanished connector\'s entry is dropped by regeneration: ' . implode(' | ', $lines));
            $this->assertStringContainsString(basename($zipAlpha), $lines[0]);
            /*
             * The prune reclaims the SIDECAR with the entry (OCR round
             * 35, t31-ocr35-2): the manifest is an inventory of
             * standing artifacts, and a checksum naming a non-standing
             * artifact is the exact class the landing order closed —
             * the r12-7 prune dropped the line but left
             * dist/<zip>.sha256 standing forever (driven red at HEAD).
             * A STANDING entry's sidecar is untouched by the same
             * merge: the reclaim fires on the pruned entry alone.
             */
            $this->assertFileDoesNotExist($zipBeta . '.sha256', 'The pruned entry\'s checksum sidecar is reclaimed with its line — never a checksum naming a non-standing artifact.');
            $this->assertFileExists($zipAlpha . '.sha256', 'A standing entry\'s sidecar keeps standing through the same merge.');

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
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($plugins['beta-demo'], $scratch . '/dist'),
                'A headerless plugin must refuse the build.', \RuntimeException::class
            );
            $this->assertStringContainsString('no main plugin file', $refusal->getMessage());
            // The finally twin of the old shape: the damaged source is
            // restored after the verdict either way.
            file_put_contents($damaged, $source);
            $this->assertSame($manifestBefore, (string) file_get_contents($manifestPath), 'A failed run lands no manifest change, prune included.');
        } finally {
            WpHarness::releaseScratch($scratch);
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
        $tempPlugin = self::scratchPath('ns-test') . '/openai-oauth';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
        }
        mkdir($tempPlugin . '/src', 0755, true);
        $autoload = "<?php\nspl_autoload_register( static function ( \$class ): void {\n    \$prefix = 'Deicod\\\\WpConnectors\\\\OpenAiOauth\\\\';\n    if ( 0 !== strncmp( \$class, \$prefix, strlen( \$prefix ) ) ) {\n        return;\n    }\n    \$file = __DIR__ . '/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n    if ( is_file( \$file ) ) {\n        require \$file;\n    }\n} );\n";
        file_put_contents($tempPlugin . '/src/autoload.php', $autoload);

        $this->assertSame(array(), wp_connectors_autoloader_violations($tempPlugin), 'The documented OpenAiOauth prefix must be accepted.');

        file_put_contents($tempPlugin . '/src/autoload.php', str_replace('OpenAiOauth', 'OpenaiOauth', $autoload));
        $this->assertNotSame(array(), wp_connectors_autoloader_violations($tempPlugin), 'A lowercased OpenaiOauth prefix must be rejected.');

        WpHarness::releaseScratch(dirname($tempPlugin));
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
        // The whole pin is link-bearing (t31-ocr11-8): the capability
        // probe gates it visibly.
        if (! self::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the in-tree leak-link refusal cannot be driven on it.');
        }
        $tempPlugin = self::scratchPath('symlink-test') . '/example-connector';
        if (is_dir(dirname($tempPlugin))) {
            WpHarness::releaseScratch(dirname($tempPlugin));
        }
        mkdir(dirname($tempPlugin), 0755, true);
        $this->copyFixturePlugin($tempPlugin);
        $secretOutside = dirname($tempPlugin) . '/outside-secret.txt';
        file_put_contents($secretOutside, 'not-packaged');
        symlink($secretOutside, $tempPlugin . '/leaked-config.txt');

        try {
            $refusal = $this->refusalOf(
                fn() => WpConnectorsBuild::buildPlugin($tempPlugin, self::distDir()),
                'A symlink inside the plugin tree must refuse the build, never skip it silently.', \RuntimeException::class
            );
            $this->assertStringContainsString('symlink', $refusal->getMessage());
            $this->assertStringContainsString('leaked-config.txt', $refusal->getMessage());
            $this->assertStringContainsString('outside-secret.txt', $refusal->getMessage(), 'The refusal names the link target — the leak half stays visible in the diagnostic.');
            $this->assertSame(array(), glob(self::distDir() . '/' . self::fixtureZipName() . '*') ?: array(), 'The refused build must leave no artifact behind.');
        } finally {
            WpHarness::releaseScratch(dirname($tempPlugin));
        }
    }

    /**
     * The plugin header's Version token, read at runtime from the main
     * file the BUILD itself reads (OCR round 17, t31-ocr17-6): every
     * version the pins compare is DERIVED from that source of truth,
     * never a literal — a version bump then changes the header exactly
     * once and every assertion follows it, where a hardcoded '0.1.0'
     * broke the suite in non-obvious ways (an artifact-name pin
     * failing far from the bump; a str_replace patch silently matching
     * nothing, its leg vacuous green).
     *
     * @param string $mainFile Absolute path to a plugin main file.
     * @return string The header's Version token.
     */
    private static function headerVersion( string $mainFile ): string
    {
        self::assertSame(
            1,
            preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $mainFile ), $matches ),
            "The plugin main file must carry a header Version line for the pins to read: {$mainFile}"
        );

        return $matches[1];
    }

    /**
     * The fixture plugin's version, from the fixture's own header.
     *
     * @return string The example-connector fixture's Version token.
     */
    private static function fixtureVersion(): string
    {
        return self::headerVersion( __DIR__ . '/fixtures/plugins/' . self::FIXTURE . '/' . self::FIXTURE . '.php' );
    }

    /**
     * The artifact zip basename the build derives from the fixture —
     * connectors-<slug>-<version>.zip, both halves from source.
     *
     * @return string The expected artifact basename.
     */
    private static function fixtureZipName(): string
    {
        return 'connectors-' . self::FIXTURE . '-' . self::fixtureVersion() . '.zip';
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
     * t31-ocr6-15 (verifier-lens find over t31-ocr6-5, driver-confirmed):
     * the helper's own loop was the SURVIVING inline twin of the one
     * ocr6-5 deleted from makeScratchRepo — str_replace every-occurrence
     * prefix strip (the ocr4-2 mis-nesting shape) and no isLink() guard
     * (the ocr4-3 follow/skip shape), over the SAME example-connector
     * fixture, predating and surviving both fencing rounds. It rides
     * WpHarness::copyTree(), the ONE scratch-tree copy owner; the
     * r6-7 pre-create is absorbed the same way (copyTree mkdirs each
     * target's dirname recursively, order-free).
     *
     * @param string $targetDir Absolute target directory (the plugin root
     *                          inside it is created as needed).
     * @return string The target directory, for call-site chaining.
     */
    private function copyFixturePlugin(string $targetDir): string
    {
        WpHarness::copyTree(__DIR__ . '/fixtures/plugins/' . self::FIXTURE, $targetDir);

        return $targetDir;
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

        $tmp = self::scratchPath('badzip-' . $slug);
        if (is_dir($tmp)) {
            WpHarness::releaseScratch($tmp);
        }
        mkdir($tmp . '/' . $slug . '/src', 0755, true);
        file_put_contents($tmp . '/' . $slug . '/' . $slug . '.php', $main);
        file_put_contents($tmp . '/' . $slug . '/src/autoload.php', $autoload);

        $zipPath = self::distDir() . "/connectors-{$slug}-1.0.0.zip";
        $zip = new ZipArchive();
        $this->assertTrue(
            true === ($opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
            sprintf(
                '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr13-5).',
                $zipPath,
                var_export($opened, true)
            )
        );
        $zip->addFile($tmp . '/' . $slug . '/' . $slug . '.php', "{$slug}/{$slug}.php");
        $zip->addFile($tmp . '/' . $slug . '/src/autoload.php', "{$slug}/src/autoload.php");
        $zip->close();
        WpHarness::releaseScratch($tmp);

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
            WpHarness::releaseScratch($repo);
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
     * Stat is unavailable on the scheme (a write-only virtual stream).
     *
     * The protocol's failure spelling, not an off-protocol empty
     * array (OCR round 16, t31-ocr16-15g): url_stat() answers a full
     * 13-element stat array or FALSE — an empty array is neither,
     * and if anything ever stats the scheme (an is_file()/filesize()
     * probe reaching the wrapper) the engine would treat the empty
     * shape as a REAL stat with garbage fields. Nothing stats it
     * today (the pin only writes); the unreachable arm answers the
     * protocol anyway: FALSE, stat unavailable — a probe then reads
     * "does not exist", the honest answer for this scheme.
     *
     * @param string $path  The stat target.
     * @param int    $flags Stat flags.
     * @return array<int|string, int|string>|false Always false (stat unavailable).
     */
    public function url_stat(string $path, int $flags)
    {
        return false;
    }
}
