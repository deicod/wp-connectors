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
        $scratch = sys_get_temp_dir() . '/wpct-lint-' . getmypid();
        if (is_dir($scratch)) {
            WpHarness::rrmdir($scratch);
        }
        mkdir($scratch . '/bin/lib', 0755, true);
        copy(__DIR__ . '/../bin/lint-php.php', $scratch . '/bin/lint-php.php');
        copy(__DIR__ . '/../bin/lib/plugin-tools.php', $scratch . '/bin/lib/plugin-tools.php');

        try {
            // Real sources: one under tests/ (the root lints), one under
            // connectors/, one under a NESTED tests-named tree (the
            // ocr8-7 coverage — the vocabulary ride skipped it); then
            // the drifted spellings with parse-broken PHP inside — both
            // must be SKIPPED, not linted.
            mkdir($scratch . '/tests/unit', 0755, true);
            file_put_contents($scratch . '/tests/unit/RealTest.php', "<?php\n// lintable tests-root source\n");
            mkdir($scratch . '/connectors/demo', 0755, true);
            file_put_contents($scratch . '/connectors/demo/demo.php', "<?php\n// lintable connector source\n");
            mkdir($scratch . '/connectors/demo/tests', 0755, true);
            file_put_contents($scratch . '/connectors/demo/tests/NestedTest.php', "<?php\n// lintable NESTED tests-named source\n");
            mkdir($scratch . '/connectors/demo/phpunit.cache', 0755, true);
            file_put_contents($scratch . '/connectors/demo/phpunit.cache/broken.php', "<?php this is not php");
            mkdir($scratch . '/connectors/demo/VENDOR', 0755, true);
            file_put_contents($scratch . '/connectors/demo/VENDOR/broken.php', "<?php this is not php either");

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
             * never descended. The capability rides the probe, not
             * function_exists (the t31-ocr6-14 lesson).
             */
            $probe = sys_get_temp_dir() . '/wpct-lint-capability-' . getmypid();
            if (@symlink('/usr/bin/true', $probe)) {
                unlink($probe);
                mkdir($scratch . '/linked-tree', 0755, true);
                file_put_contents($scratch . '/linked-tree/broken-inside.php', "<?php nor is this reachable only through the link");
                symlink($scratch . '/linked-tree', $scratch . '/connectors/demo/dirlink.php');
                $output = array();
                $exit = 0;
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
                $linked = implode("\n", $output);
                $this->assertSame(0, $exit, "A '*.php'-named dir symlink is skipped, never linted as a vacuous directory: {$linked}");
                $this->assertStringContainsString('5 file(s) checked, 0 failure(s)', $linked, 'The skipped dir-link does not change the checked count (pre-fix it was counted as a 6th file).');
                $this->assertStringNotContainsString('dirlink.php', $linked);
            }

            /*
             * The still-fails controls: a broken file on no excluded
             * path keeps failing the lint, and — the ocr8-7 leg — a
             * parse-broken file under a NESTED tests-named tree fails
             * it too (red at HEAD: the pre-ocr8 vocabulary ride skipped
             * the whole tree, exit 0, no failure).
             */
            file_put_contents($scratch . '/connectors/demo/broken-too.php', "<?php nor is this");
            file_put_contents($scratch . '/connectors/demo/tests/broken-nested.php', "<?php neither is this nested one");
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $combined = implode("\n", $output);
            $this->assertSame(1, $exit, 'A parse-broken real source still fails the lint.');
            $this->assertStringContainsString('broken-too.php', $combined);
            $this->assertStringContainsString('broken-nested.php', $combined, 'A parse-broken source under a NESTED tests-named tree must FAIL the lint — tests are the gate\'s charge, never a release exclusion.');
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
