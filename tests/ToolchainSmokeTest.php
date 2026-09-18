<?php
/**
 * Toolchain smoke test (Task 0.2).
 *
 * Verifies the pinned development dependencies are installed and loadable,
 * in particular the exact wordpress/php-ai-client SDK version the connectors
 * are built against.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ToolchainSmokeTest extends TestCase
{
    public function testPhpVersionIsInSupportedRange(): void
    {
        // floor82 (user decision 2026-09-11): the supported floor is 8.2;
        // the runtime may be newer (dev hosts run 8.5), but a suite run on
        // anything older proves nothing about the shipped plugin.
        $this->assertGreaterThanOrEqual(80200, PHP_VERSION_ID);
    }

    public function testPinnedAiClientSdkIsInstalled(): void
    {
        $this->assertTrue(class_exists(\WordPress\AiClient\AiClient::class));
        $this->assertSame('1.3.1', \WordPress\AiClient\AiClient::VERSION);
    }

    public function testComposerDeclaresExtZipForBuildAndArtifactTests(): void
    {
        // bin/build.php and the artifact tests fatal at `new ZipArchive()`
        // without ext-zip; declaring it in require-dev makes dependency
        // setup fail with an actionable platform error instead of a mid-run
        // fatal (review finding).
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertIsArray($composer['require-dev'] ?? null);
        $this->assertArrayHasKey('ext-zip', $composer['require-dev'], 'require-dev must declare the ext-zip platform package.');
    }

    public function testAiClientRegistryIsUsable(): void
    {
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();
        $this->assertInstanceOf(\WordPress\AiClient\Providers\ProviderRegistry::class, $registry);
        $this->assertSame($registry, \WordPress\AiClient\AiClient::defaultRegistry());
    }

    /**
     * floor82 (superseding glm38-1's pin): the curated POST-floor function
     * vocabulary for every tree the phpcs-compat ruleset holds to "must run
     * on 8.2-8.4" (bin, connectors, shared, tests).
     *
     * glm38-1's form banned 8.0+ functions against the former 7.4 floor —
     * fdiv fatals were the demonstrated class, and the locked
     * PHPCompatibility 9.3.5 predates the function and has no sniff for it.
     * With the 8.2 floor every pre-8.2 function is legal (fdiv,
     * str_contains, array_is_list, enum_exists included — the fdiv spelling
     * in the NAN pin stays the NAN constant anyway: clearer than the
     * division spelling, and the zai sibling suite's idiom since GLM2 #4),
     * so the sweep's job flips to the OTHER side of the range: functions
     * introduced ABOVE the floor (8.3/8.4 additions) would fatal every 8.2
     * install while phpcs-compat 9.3.5 — pinned in 2019 — cannot see them
     * either. Same grep-shaped backstop, same sniff-gap class, opposite
     * direction. Comments included: prose naming the call shape gets
     * rewritten, not exempted (the pin flagged its own first docblock).
     *
     * A name joins this list only with a demonstrated unavailable-at-8.2
     * fatal and no polyfill in the tree.
     */
    public function testNoPostFloorFunctionCallsInTheCompatTrees(): void
    {
        /*
         * floor82-5 (verifier round): the list is assembled from the
         * php.net migration pages, not recollection — the first form
         * misattributed mysqli_execute_query (a PHP 8.2.0 function,
         * legal on the floor) to 8.3 and missed the mb_str_pad,
         * socket_atmark, mb_ucfirst/lcfirst, bcceil/floor/round,
         * request_parse_body, http_*_last_response_headers, and
         * grapheme_str_split families. PHP calls are CASE-INSENSITIVE
         * (an uppercase spelling of any banned name evaded the
         * case-sensitive pattern — the security lens), so the pattern
         * carries the /i flag.
         */
        $postFloorFunctions = array(
            // PHP 8.3 additions.
            'json_validate',
            'str_increment',
            'str_decrement',
            'stream_context_set_options',
            'mb_str_pad',
            'socket_atmark',
            'posix_eaccess',
            'posix_sysconf',
            'posix_pathconf',
            'posix_fpathconf',
            // PHP 8.4 additions.
            'array_find',
            'array_find_key',
            'array_any',
            'array_all',
            'mb_trim',
            'mb_ltrim',
            'mb_rtrim',
            'mb_ucfirst',
            'mb_lcfirst',
            'bcdivmod',
            'bcceil',
            'bcfloor',
            'bcround',
            'request_parse_body',
            'http_get_last_response_headers',
            'http_clear_last_response_headers',
            'grapheme_str_split',
        );

        $pattern = '/\b(' . implode('|', $postFloorFunctions) . ')\s*\(/i';
        $scanned = 0;

        foreach ($this->compatTreeFiles() as $path) {
            ++$scanned;
            foreach (preg_split('/\R/u', (string) file_get_contents($path)) as $index => $line) {
                if (1 === preg_match($pattern, $line, $matches)) {
                    $this->fail(
                        sprintf(
                            'Function call unavailable on the 8.2 floor: %s() at %s:%d (PHPCompatibility 9.3.5 has no sniff for it — see floor82/glm38-1).',
                            $matches[1],
                            $path,
                            $index + 1
                        )
                    );
                }
            }
        }

        // Non-vacuity: the sweep must see the real trees, not an empty root.
        $this->assertGreaterThan(0, $scanned, 'The floor sweep must visit at least one file.');
    }

    /**
     * OCR-round-14 pin (t31-ocr14-4): the below-root offset tolerates a
     * trailing-separator root spelling. The production roots carry no
     * trailing separator today, so the pin drives a scratch copy of the
     * tool whose connectors root IS spelled with one. The former bare
     * strlen($root) + 1 started one byte late (the iterator keeps the
     * root's own spelling verbatim, so the double slash never happens)
     * and ate the FIRST byte of the first below-root segment — green by
     * accident while that first segment was a plugin dir ('demo' read
     * as 'emo'), red the moment the first segment is the excluded tree
     * itself: 'vendor/broken.php' read as 'endor/broken.php', dodged
     * the exclusion, and FAILED the lint it must skip. The sibling
     * scanner's rtrim spelling (bin/lib/secret-scanner.php) keeps
     * every relative path whole under any spelling.
     */
    public function testTheBelowRootOffsetToleratesATrailingSeparatorRootSpelling(): void
    {
        /*
         * The child-process capability gate (OCR round 16,
         * t31-ocr16-12, the t31-ocr6-12 doctrine the suite's other
         * exec consumers already carry): this whole test drives the
         * lint through a child process — under
         * disable_functions(exec) the bare call was an
         * undefined-function \Error (a fatal, never a verdict). It
         * skips VISIBLY instead, naming the capability and what did
         * not run.
         */
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the child-process lint legs cannot run (t31-ocr16-12).');
        }

        /*
         * RANDOM-suffixed and staged INSIDE the try (OCR round 16,
         * t31-ocr16-14): the pid-only name was the pre-plantable
         * spelling the ocr10-2 doctrine rejects (pids enumerable on
         * every host), and the staging rode BEFORE the try/finally
         * pair — a failed guard, copy, or the patch assertion itself
         * leaked the whole scratch tree into /tmp. The name carries a
         * unique suffix now, and creation-to-assertion lives under
         * the finally that owns the tree.
         */
        $scratch = sys_get_temp_dir() . '/wpct-lint-trailroot-' . uniqid('', true);

        try {
            /*
             * Staging success is ASSERTED at each site (OCR round 27,
             * t31-ocr27-9, the t31-ocr26-12 misattribution doctrine):
             * a failed mkdir()/copy()/file_put_contents() once
             * surfaced only through the child run — the gate's green
             * or red verdict wore a staging problem as its own
             * defect. Staging failures fail as staging now, before
             * any child is spawned.
             */
            $this->assertTrue(mkdir($scratch . '/bin/lib', 0755, true), 'staging: the scratch bin/lib must create — a staging failure fails as staging, never as the lint verdict.');
            $tool = (string) file_get_contents(__DIR__ . '/../bin/lint-php.php');
            /*
             * The exactly-once claim is PINNED, not implied (OCR
             * round 16, t31-ocr16-15c): the old assertNotSame
             * message said "the connectors spelling exists exactly
             * once" while str_replace proved only PRESENCE — a
             * second occurrence would patch BOTH and the message
             * would still be true to the check, false to the claim.
             * The count is the claim now: exactly one roots line.
             */
            $this->assertSame(
                1,
                substr_count($tool, "__DIR__ . '/../connectors'"),
                'The patch target must exist exactly once — the roots line is one line; a second occurrence would patch both and this pin owns the count.'
            );
            $patched = str_replace("__DIR__ . '/../connectors'", "__DIR__ . '/../connectors/'", $tool);
            $this->assertNotSame($tool, $patched, 'The patch must reach the roots line.');
            $this->assertNotFalse(file_put_contents($scratch . '/bin/lint-php.php', $patched), 'staging: the patched lint tool must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lib/plugin-tools.php', $scratch . '/bin/lib/plugin-tools.php'), 'staging: the tool library must copy — a staging failure fails as staging, never as the lint verdict.');
            // The excluded tree is the FIRST segment below the root —
            // the position whose first byte the bare offset ate.
            $this->assertTrue(mkdir($scratch . '/connectors/vendor', 0755, true), 'staging: the excluded first-segment tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(mkdir($scratch . '/connectors/demo', 0755, true), 'staging: the demo connector tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/good.php', "<?php\n// lintable connector source\n"), 'staging: the good connector source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/vendor/broken.php', "<?php this must stay excluded"), 'staging: the excluded broken source must write — a staging failure fails as staging, never as the lint verdict.');

            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $report = implode("\n", $output);

            $this->assertSame(0, $exit, "The trailing-separator root must not shift the exclusion judgment: {$report}");
            $this->assertStringNotContainsString('broken.php', $report, 'The excluded tree stays excluded under the trailing-separator spelling (red as the bare offset: the shifted first segment read the vendor file into the lint).');
            $this->assertStringContainsString('3 file(s) checked, 0 failure(s)', $report, 'The good source under the shifted root stays counted (good.php plus the two copied tool files).');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Review-round pin (t31-r12-9, narrowed in OCR round 8 t31-ocr8-7):
     * the lint gate's exclusion is the gate's OWN NAMED SUBSET of the
     * development-entry vocabulary — generated and third-party trees
     * (vendor/, tools/, dist/, node_modules/, both cache spellings),
     * judged root-relative through the vocabulary owner's one fold
     * (the hand-rolled case-sensitive list had drifted: the dotless
     * 'phpunit.cache/' and a 'VENDOR/' spelling were LINTED while the
     * builder excluded and the inspector rejected both — the exact
     * drift the owner's docblock forbids). The ocr8-7 narrowing: the
     * r12-9 ride on the WHOLE vocabulary was a silent COVERAGE cut —
     * the vocabulary's 'tests'/'test'/'.github' entries are
     * release-exclusion concerns, and a nested tests-named tree under
     * a connector root is this gate's charge exactly as the tests ROOT
     * is (the pre-ocr8 gate skipped it unseen). Driven as a child
     * process against a scratch copy of the tool (the real script's
     * roots are its own __DIR__), with broken files inside the
     * excluded spellings (must be SKIPPED) and inside a nested tests
     * tree (must be LINTED).
     */
    public function testLintPhpExclusionsAreTheGatesOwnNamedSubset(): void
    {
        /*
         * The child-process capability gate (OCR round 16,
         * t31-ocr16-12, the t31-ocr6-12 doctrine): under
         * disable_functions(exec) the first child-process lint call
         * was an undefined-function \Error — a FATAL before the
         * battery's own controls, which also exec (their verdicts
         * are engine-spawned), and before the canSymlink skip
         * below, whose message promises "the still-fails controls
         * above already ran" — a promise nothing could keep on a
         * host where the test dies at its first exec. The skip is
         * visible and first now: on exec-capable hosts (the only
         * ones that reach it) the controls DO run before the
         * symlink skip, and the promise holds; on exec-less hosts
         * the test names the capability and stops — never a fatal.
         */
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the child-process lint legs cannot run, the still-fails controls included (they verdict through a spawned engine); the canSymlink promise below is never reached here (t31-ocr16-12).');
        }

        /*
         * RANDOM-suffixed and staged INSIDE the try (t31-ocr16-14, the
         * trailroot sibling's own shape): the pid-only name was
         * pre-plantable, and the staging copies rode before the
         * try/finally pair — a failed copy leaked the tree. Creation
         * through every assertion lives under the finally now.
         */
        $scratch = sys_get_temp_dir() . '/wpct-lint-' . uniqid('', true);

        try {
            /*
             * Staging success is ASSERTED at each site (OCR round 27,
             * t31-ocr27-9, the t31-ocr26-12 misattribution doctrine —
             * this test's twin shape): every mkdir/copy/write feeding
             * a child verdict, and every re-staging call between the
             * child runs, names its own staging premise when it
             * fails; staging failures never wear the gate's verdict.
             */
            $this->assertTrue(mkdir($scratch . '/bin/lib', 0755, true), 'staging: the scratch bin/lib must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lint-php.php', $scratch . '/bin/lint-php.php'), 'staging: the lint tool must copy — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lib/plugin-tools.php', $scratch . '/bin/lib/plugin-tools.php'), 'staging: the tool library must copy — a staging failure fails as staging, never as the lint verdict.');
            // Real sources: one under tests/ (the root lints), one under
            // connectors/, one under a NESTED tests-named tree (the
            // ocr8-7 coverage — the vocabulary ride skipped it); then
            // the drifted spellings with parse-broken PHP inside — both
            // must be SKIPPED, not linted.
            $this->assertTrue(mkdir($scratch . '/tests/unit', 0755, true), 'staging: the scratch tests tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/tests/unit/RealTest.php', "<?php\n// lintable tests-root source\n"), 'staging: the tests-root source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(mkdir($scratch . '/connectors/demo', 0755, true), 'staging: the demo connector tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/demo.php', "<?php\n// lintable connector source\n"), 'staging: the connector source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(mkdir($scratch . '/connectors/demo/tests', 0755, true), 'staging: the nested tests tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/tests/NestedTest.php', "<?php\n// lintable NESTED tests-named source\n"), 'staging: the nested tests-named source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(mkdir($scratch . '/connectors/demo/phpunit.cache', 0755, true), 'staging: the drifted cache spelling must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/phpunit.cache/broken.php', "<?php this is not php"), 'staging: the cache-hidden broken source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(mkdir($scratch . '/connectors/demo/VENDOR', 0755, true), 'staging: the drifted VENDOR spelling must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/VENDOR/broken.php', "<?php this is not php either"), 'staging: the VENDOR-hidden broken source must write — a staging failure fails as staging, never as the lint verdict.');

            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $report = implode("\n", $output);

            $this->assertSame(0, $exit, "The drift spellings must be skipped by the ONE fold, not linted: {$report}");
            // Three real sources plus the copied tool files under bin/ (the
            // tool lints its own tree too): every root stays in charge,
            // and the NESTED tests tree stays in coverage.
            $this->assertStringContainsString('5 file(s) checked, 0 failure(s)', $report, 'The nested tests-named source must be counted — the vocabulary ride silently cut it.');
            $this->assertStringNotContainsString('broken.php', $report);

            /*
             * The non-regular-file skip (t31-ocr10-7, posix leg): a
             * '*.php'-named symlink-to-directory passes the extension
             * owner and once reached `php -l <dir>` — which passes
             * VACUOUSLY (driven: exit 0 over a directory) while the
             * linked tree's real sources escape the gate. The link is
             * SKIPPED now (is_link || ! isFile — the collectors'
             * parity): the checked count stays 5 (pre-fix: 6, the link
             * itself counted), and the broken source INSIDE the linked
             * tree is judged by nobody either way — the leg is skipped,
             * never descended.
             */
            /*
             * The still-fails controls, HOISTED above the capability
             * skip (t31-ocr13-8): they need no symlink — a broken file
             * on no excluded path keeps failing the lint, and — the
             * ocr8-7 leg — a parse-broken file under a NESTED
             * tests-named tree fails it too (red at HEAD: the pre-ocr8
             * vocabulary ride skipped the whole tree, exit 0, no
             * failure). The skip used to fire FIRST, so on a
             * symlink-incapable host these controls never ran — the
             * no-symlink-needed legs must not ride the capability
             * gate. The control files are removed after their verdict
             * so the dir-link leg below lints the pristine tree.
             */
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/broken-too.php', "<?php nor is this"), 'staging: the still-fails control source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/tests/broken-nested.php', "<?php neither is this nested one"), 'staging: the nested still-fails control must write — a staging failure fails as staging, never as the lint verdict.');
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $combined = implode("\n", $output);
            $this->assertSame(1, $exit, 'A parse-broken real source still fails the lint.');
            $this->assertStringContainsString('broken-too.php', $combined);
            $this->assertStringContainsString('broken-nested.php', $combined, 'A parse-broken source under a NESTED tests-named tree must FAIL the lint — tests are the gate\'s charge, never a release exclusion.');
            $this->assertTrue(unlink($scratch . '/connectors/demo/broken-too.php'), 'staging: the still-fails control must remove again — a failed re-stage fails as staging, never as the dir-link leg\'s count.');
            $this->assertTrue(unlink($scratch . '/connectors/demo/tests/broken-nested.php'), 'staging: the nested still-fails control must remove again — a failed re-stage fails as staging, never as the dir-link leg\'s count.');

            /*
             * The capability rides the ONE owner, WpHarness::canSymlink()
             * (t31-ocr12-1): this file's former inline '@symlink probe —
             * pid-only-suffixed, the t31-ocr10-18 shape — was the last
             * twin outside it, and under disable_functions(symlink) @
             * cannot suppress the missing-function \Error: the probe
             * FATALED the very leg it existed to guard. The owner guards
             * function_exists first and skips VISIBLY, never fatals.
             */
            if (! WpHarness::canSymlink()) {
                $this->markTestSkipped('This host cannot create symlinks — the dir-link skip leg cannot run on it (t31-ocr12-1); the still-fails controls above already ran (t31-ocr13-8).');
            }
            $this->assertTrue(mkdir($scratch . '/linked-tree', 0755, true), 'staging: the link target tree must create — a staging failure fails as staging, never as the dir-link leg\'s verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/linked-tree/broken-inside.php', "<?php nor is this reachable only through the link"), 'staging: the linked-tree source must write — a staging failure fails as staging, never as the dir-link leg\'s verdict.');
            /*
             * The symlink CREATION is asserted (OCR round 27,
             * t31-ocr27-9): symlink() returns FALSE silently on
             * failure (canSymlink() proved the capability, not this
             * call), and a link that never landed left the leg
             * passing VACUOUSLY — '5 file(s) checked' reads as the
             * pinned green with or without the link, a skip-shaped
             * verdict nothing distinguishes from the pass. The leg
             * names its own creation failure now.
             */
            $this->assertTrue(symlink($scratch . '/linked-tree', $scratch . '/connectors/demo/dirlink.php'), 'The dir-link must land — the leg lints a tree whose dirlink.php entry exists; a failed symlink creation is the leg\'s own failure, never a vacuous green.');
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $linked = implode("\n", $output);
            $this->assertSame(0, $exit, "A '*.php'-named dir symlink is skipped, never linted as a vacuous directory: {$linked}");
            $this->assertStringContainsString('5 file(s) checked, 0 failure(s)', $linked, 'The skipped dir-link does not change the checked count (pre-fix it was counted as a 6th file).');
            $this->assertStringNotContainsString('dirlink.php', $linked);
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Every PHP file under the phpcs-compat ruleset's tree set, mirroring
     * its vendor/dist/tools and tests/fixtures/data exclusions.
     *
     * @return string[]
     */
    private function compatTreeFiles(): array
    {
        $files = array();
        $excludedData = realpath(__DIR__ . '/../tests/fixtures/data');

        foreach (array('/../bin', '/../connectors', '/../shared', '/../tests') as $tree) {
            $root = realpath(__DIR__ . $tree);
            if (false === $root) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if ('.php' !== substr($path, -4)) {
                    continue;
                }
                if (1 === preg_match('#(^|/)(vendor|dist|tools)/#', substr($path, strlen($root) + 1))) {
                    continue;
                }
                if (false !== $excludedData && 0 === strpos($path, $excludedData . '/')) {
                    continue;
                }
                $files[] = $path;
            }
        }

        return $files;
    }
}
