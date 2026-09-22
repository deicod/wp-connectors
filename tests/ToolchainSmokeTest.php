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
         * The platform gate (OCR round 29, t31-ocr29-7, the suite's
         * t31-ocr28-8 pattern): this leg's own premise is the
         * SEPARATOR the patch spells — the scratch tool's connectors
         * root is re-spelled with a trailing '/' while the production
         * offset strips DIRECTORY_SEPARATOR, so on a host whose
         * separator is '\' the rtrim eats NOTHING, the offset eats
         * the first byte of the first segment, and the leg would
         * drive its own pinned defect back as the platform's
         * vocabulary — through no defect of the contract it pins.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('This host\'s platform separator is not the POSIX one — the trailing-separator patch spells \'/\' while the offset strips DIRECTORY_SEPARATOR, so the leg would judge the first-byte shift it exists to pin as the platform\'s own vocabulary (t31-ocr29-7).');
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
            /*
             * glm22-10: every declared root the walk names — the
             * roots this leg does not drive ride as EMPTY trees (the
             * walk's missing-root refusal, swept the same round, made
             * the silent absence of a declared root a red).
             * glm23-13/glm24-8: the declared set AND the staging loop
             * ride the ONE choreography owner — the leg names whatever
             * the walk names, never its own hand copy.
             */
            WpHarness::stageLintRoots($scratch);
            /*
             * The staging READ asserts its own success (OCR round 29,
             * t31-ocr29-8, the t31-ocr27-9 doctrine's read twin): the
             * former bare (string) cast turned a failed read into ''
             * and flowed it downstream — the patch-count assertion
             * then wore the read failure as its own verdict. Staging
             * failures fail as staging, before any child spawns.
             */
            $library = file_get_contents(__DIR__ . '/../bin/lib/plugin-tools.php');
            $this->assertNotFalse($library, 'staging: the tool library must read — a staging failure fails as staging, never as the patch verdict.');
            /*
             * The exactly-once claim is PINNED, not implied (OCR
             * round 16, t31-ocr16-15c): the old assertNotSame
             * message said "the connectors spelling exists exactly
             * once" while str_replace proved only PRESENCE — a
             * second occurrence would patch BOTH and the message
             * would still be true to the check, false to the claim.
             * The count is the claim now: exactly one roots line.
             * glm23-13: the patch target rides the ROOTS OWNER the
             * walk consults — the trailing separator is spelled at
             * the owner's connectors line in the copied library, the
             * walk's own root derivation one seam over.
             */
            $this->assertSame(
                1,
                substr_count($library, "'/connectors'"),
                'The patch target must exist exactly once — the owner\'s connectors line is one line; a second occurrence would patch both and this pin owns the count.'
            );
            $patched_library = str_replace("'/connectors'", "'/connectors/'", $library);
            $this->assertNotSame($library, $patched_library, 'The patch must reach the owner\'s roots line.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lint-php.php', $scratch . '/bin/lint-php.php'), 'staging: the lint tool must copy — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/bin/lib/plugin-tools.php', $patched_library), 'staging: the patched tool library must write — a staging failure fails as staging, never as the lint verdict.');
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
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-50 pin (t31-ocr50-7, the lint twin of the collectors'
     * no-symlinks doctrine): the walk silently SKIPPED symlinked *.php
     * entries under a claim of "the sibling collectors' parity" — but
     * collectFiles() and wp_connectors_php_source_files() THROW on
     * links, they do not skip, and pre-change a symlinked source
     * reached `php -l`, which FOLLOWS the link — the skip was a
     * coverage regression (a linked source silently escaped the gate,
     * red at HEAD: exit 0, the link unseen). The link answers the
     * walk's FAIL vocabulary now (a counted walk refusal, the exit
     * red, the tree still walked), and the exclusion judgment stays
     * first — a link under a third-party tree the gate never charges
     * refuses nothing.
     */
    public function testASymlinkedSourceAnswersTheLoudRefusalNotTheSilentSkip(): void
    {
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the lint leg verdicts through a spawned engine and cannot run (the t31-ocr16-12 doctrine).');
        }
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the planted-link leg did not run (the no-symlinks doctrine seam it drives is unconstructible here).');
        }
        /*
         * The platform gate this battery owed its two siblings (OCR
         * round 51, t31-ocr51-2 — the census's third '/'-fragment
         * consumer): the refusal messages are built from raw
         * HOST-joined pathnames, while the verdicts below assert
         * '/'-joined fragments ('connectors/demo/linked.php',
         * 'vendor/linked.php') — the trailing-separator pin and the
         * exclusions pin gate the same divergence (t31-ocr29-7), and
         * this battery grew the vocabulary in round 50 without the
         * gate: on a Win32 host the separator vocabulary diverges and
         * the battery would fail as an environment defect, never as
         * the walk's own judgment.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('This host\'s platform separator is not the POSIX one — the refusal messages arrive host-joined while the verdicts assert \'/\'-joined fragments, so the leg would judge the platform\'s own separator vocabulary, never the walk\'s refusal (t31-ocr29-7, its two siblings\' gate).');
        }

        $scratch = sys_get_temp_dir() . '/wpct-lint-link-' . uniqid('', true);

        try {
            // Staging success is ASSERTED at each site (the
            // t31-ocr27-9 doctrine): a failed mkdir/copy/write fails as
            // staging, never as the lint verdict.
            $this->assertTrue(mkdir($scratch . '/bin/lib', 0755, true), 'staging: the scratch bin/lib must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lint-php.php', $scratch . '/bin/lint-php.php'), 'staging: the lint tool must copy — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lib/plugin-tools.php', $scratch . '/bin/lib/plugin-tools.php'), 'staging: the tool library must copy — a staging failure fails as staging, never as the lint verdict.');
            /*
             * glm22-10: every declared root the walk names — shared
             * and tests ride as empty trees here. glm23-13/glm24-8:
             * the set and the staging loop ride the ONE choreography
             * owner the walk consults.
             */
            WpHarness::stageLintRoots($scratch);
            $this->assertTrue(mkdir($scratch . '/connectors/demo', 0755, true), 'staging: the demo connector tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/good.php', "<?php\n// lintable connector source\n"), 'staging: the good connector source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/real.php', "<?php\n// the linked source's own bytes\n"), 'staging: the linked source must write — a staging failure fails as staging, never as the lint verdict.');
            // The plant: a '*.php'-named link AT a regular file under a
            // walked root — the follow shape every is_file() gate
            // passes, the exact source the silent skip dropped from
            // coverage.
            $this->assertTrue(symlink($scratch . '/connectors/demo/real.php', $scratch . '/connectors/demo/linked.php'), 'staging: the planted link must take — a staging failure fails as staging, never as the lint verdict.');
            // The out-of-charge control: the same link shape under the
            // excluded third-party tree — not this doctrine's charge.
            $this->assertTrue(mkdir($scratch . '/connectors/vendor', 0755, true), 'staging: the excluded tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(symlink($scratch . '/connectors/demo/real.php', $scratch . '/connectors/vendor/linked.php'), 'staging: the excluded link must take — a staging failure fails as staging, never as the lint verdict.');

            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $report = implode("\n", $output);

            $this->assertSame(1, $exit, "A symlinked source answers the loud refusal — red at HEAD the gate exited 0 with the link silently skipped: {$report}");
            $this->assertStringContainsString('symlinked source', $report, 'The refusal names the link shape in the walk\'s FAIL vocabulary.');
            $this->assertStringContainsString('connectors/demo/linked.php', $report, 'The refusal names the linked source the walk judged.');
            $this->assertStringContainsString('1 walk refusal(s)', $report, 'The link rides the walk-refusal count — the exit is red by its own census.');
            $this->assertStringContainsString('0 failure(s)', $report, 'The regular sources keep their verdicts — the tree still lints beside the refusal.');
            $this->assertStringNotContainsString('vendor/linked.php', $report, 'A link under the excluded third-party tree refuses nothing — the exclusion judgment stays first.');
        } finally {
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-40 pin (t31-ocr40-7): the SCANNER half of the twin
     * above. The t31-ocr31-5 dual-separator arithmetic
     * (rtrim($root, '/\\') before the below-root offset) lives in TWO
     * tools — the lint walk this file drives at the twin, and
     * wp_connectors_scan_paths() — and the scanner's variant existed
     * in PROSE only: the twin's docblock asserted "the sibling
     * scanner's rtrim spelling keeps every relative path whole under
     * any spelling" with no leg anywhere driving it. The scanner leg
     * drives the walk DIRECTLY (in-process — the library is
     * self-contained, t31-ocr3-5) with a trailing-separator root
     * spelling, and the DRIVABLE class on this POSIX-gated runner is
     * the rtrim's PRESENCE: a regressed bare strlen($root) + 1
     * answers an offset one byte over for the '/'-suffixed root, so
     * 'vendor/x.php' reads 'endor/x.php', the dev-entry prune misses
     * it, and the canary under the pruned tree FINDS instead of
     * pruning (the ocr14-4 shape, verified red at the patched seam).
     * The narrowing half (dual vs a native-only rtrim) is
     * POSIX-judgment-neutral by construction — the two spellings
     * differ only over a trailing '\' byte, where the dual arm leaves
     * an empty leading segment the prune ignores (the ocr29-3
     * residue trade) — so no POSIX pin can split them; on a '\'
     * host this leg's own platform gate skips it (the twin's
     * t31-ocr29-7 premise).
     */
    public function testTheScannerBelowRootArithmeticToleratesATrailingSeparatorRootSpelling(): void
    {
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('This host\'s platform separator is not the POSIX one — the trailing-separator patch spells \'/\' while a regressed arithmetic strips DIRECTORY_SEPARATOR only, so the leg would judge the first-byte shift it exists to pin as the platform\'s own vocabulary (the t31-ocr29-7 premise, the twin above\'s gate).');
        }
        require_once __DIR__ . '/../bin/lib/secret-scanner.php';

        // Built at runtime (never a literal in source), the
        // SecureFixturesTest shape: a realistic z.ai-shaped key with
        // no fixture markers around it.
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));

        $scratch = sys_get_temp_dir() . '/wpct-scan-trailroot-' . uniqid('', true);

        try {
            // Staging success is asserted at each site (the
            // t31-ocr27-9 doctrine): a failed mkdir/write fails as
            // staging, never as the prune verdict.
            $this->assertTrue(mkdir($scratch . '/vendor', 0755, true), 'staging: the pruned first-segment tree must create — a staging failure fails as staging, never as the prune verdict.');
            $this->assertTrue(mkdir($scratch . '/covered', 0755, true), 'staging: the covered tree must create — a staging failure fails as staging, never as the prune verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/vendor/leak.conf', "api_key = {$zaiKey}\n"), 'staging: the pruned canary must write — a staging failure fails as staging, never as the prune verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/covered/leak.conf', "api_key = {$zaiKey}\n"), 'staging: the covered canary must write — a staging failure fails as staging, never as the prune verdict.');

            // The root spelled WITH its trailing separator — the
            // spelling whose relative arithmetic this pin owns.
            $report = implode("\n", wp_connectors_scan_paths(array( $scratch . '/' )));

            // The PRUNE holds under the trailing-separator spelling:
            // the first below-root segment stays WHOLE (red at a
            // regressed native-only rtrim: 'vendor' read 'endor',
            // the prune missed, and this canary FOUND).
            $this->assertStringNotContainsString('vendor' . DIRECTORY_SEPARATOR . 'leak.conf', $report, 'The pruned tree stays pruned under the trailing-separator root — the below-root relatives stay whole, their first byte never eaten.');
            // The scan itself RAN under the shifted spelling — never
            // a vacuous pass over a root nothing walked (the
            // ocr1-12 ancestor doctrine's own non-vacuity shape).
            $this->assertStringContainsString('covered' . DIRECTORY_SEPARATOR . 'leak.conf', $report, 'The covered tree stays scanned under the trailing-separator root spelling — the walk reached it and found the canary.');
            $this->assertStringContainsString('zai-key', $report, 'The finding names the pattern class the canary spells.');
            // Findings still never echo the secret itself.
            $this->assertStringNotContainsString($zaiKey, $report);
        } finally {
            WpHarness::releaseScratch($scratch);
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
         * The platform gate (OCR round 29, t31-ocr29-7, the suite's
         * t31-ocr28-8 pattern): this battery's verdicts ride the
         * exclusion fold's SEPARATOR arithmetic — the production
         * judge explodes the below-root relative on
         * DIRECTORY_SEPARATOR while the staged trees and their
         * composed relatives spell '/'-joined segments, and on a
         * host whose separator is '\' the fold never splits: the
         * excluded spellings would read as one giant segment and
         * every skip/count verdict would judge a fold the platform
         * mangled — through no defect of the gate they pin.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('This host\'s platform separator is not the POSIX one — the exclusion fold judges DIRECTORY_SEPARATOR-joined relatives over \'/\'-composed trees and never splits, so the skip/count verdicts would judge a platform-mangled fold, never the gate\'s own judgment (t31-ocr29-7).');
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
            /*
             * glm22-10: the declared shared root rides as an empty
             * tree here (tests/connectors stage with content below).
             * glm23-13/glm24-8: the set and the staging loop ride the
             * ONE choreography owner the walk consults — the leg
             * names whatever the walk names, never its own hand copy.
             */
            WpHarness::stageLintRoots($scratch);
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
             * The link-shape leg (t31-ocr10-7, superseded by
             * t31-ocr50-7): a '*.php'-named symlink-to-directory
             * passes the extension owner and once reached `php -l
             * <dir>` — which passed VACUOUSLY (driven: exit 0 over a
             * directory) while the linked tree's real sources escaped
             * the gate. The ocr10-7 answer was the silent skip (a
             * false "collectors' parity" — they THROW); OCR round 50
             * took it back as the coverage regression it was: the link
             * answers the walk's LOUD refusal now (the no-symlinks
             * doctrine, the lint twin of the collectors' throw), the
             * count stays 5 (the link itself never counted), and the
             * broken source INSIDE the linked tree is judged by nobody
             * either way — the link is refused, never descended.
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
            $this->assertSame(1, $exit, "A '*.php'-named dir symlink answers the loud refusal, never the vacuous `php -l <dir>` pass and never the silent skip (the t31-ocr50-7 supersession): {$linked}");
            $this->assertStringContainsString('symlinked source', $linked, 'The refusal names the link shape — the no-symlinks doctrine at the lint seam.');
            $this->assertStringContainsString('dirlink.php', $linked, 'The refusal names the dir-link the walk judged.');
            $this->assertStringContainsString('5 file(s) checked, 0 failure(s), 1 walk refusal(s)', $linked, 'The refused link does not change the checked count (pre-ocr10-7 it was counted as a 6th file) and the refusal rides the summary beside a clean parse count.');
        } finally {
            WpHarness::releaseScratch($scratch);
        }
    }

    /**
     * OCR-round-30 pin (t31-ocr30-3): the lint walk NAMES an unreadable
     * subdirectory instead of dying as an uncaught SPL fatal. A
     * directory entry the walking process cannot open — a
     * permission-bearing entry, shapes this repo's own adversarial
     * tests plant — aborts the bare RecursiveDirectoryIterator walk
     * with its own UnexpectedValueException (from the constructor or
     * mid-recursion through getChildren()), and the lint gate caught
     * nothing: driven red at HEAD, the whole run died at exit 255 with
     * a stack trace, no verdict, no summary — the unreadable tree (and
     * every root after it) escaped the gate unnamed. The walk fences
     * the abort the way the repo's other iterators do
     * (check-conventions' glm17-17 conversion, inspect-artifact's
     * t31-ocr24-2 walk — the scan_paths walk-unfenced residual head,
     * landed at its owner): the construction rides the try, the abort
     * converts to the gate's FAIL vocabulary naming the root, the
     * files collected from the readable trees stay counted, and the
     * exit fails.
     */
    public function testTheLintWalkNamesAnUnreadableSubdirectoryInsteadOfDyingUncaught(): void
    {
        /*
         * The child-process capability gate first (t31-ocr16-12): the
         * legs verdict through a spawned engine; on an exec-less host
         * the battery names the capability and stops, never a fatal.
         */
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the child-process lint legs cannot run (t31-ocr16-12).');
        }

        $scratch = sys_get_temp_dir() . '/wpct-lint-locked-' . uniqid('', true);
        $locked = $scratch . '/tests/locked';

        try {
            $this->assertTrue(mkdir($scratch . '/bin/lib', 0755, true), 'staging: the scratch bin/lib must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lint-php.php', $scratch . '/bin/lint-php.php'), 'staging: the lint tool must copy — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(copy(__DIR__ . '/../bin/lib/plugin-tools.php', $scratch . '/bin/lib/plugin-tools.php'), 'staging: the tool library must copy — a staging failure fails as staging, never as the lint verdict.');
            /*
             * glm22-10: the declared shared root rides as an empty
             * tree here (tests/connectors stage with content below).
             * glm23-13/glm24-8: the set and the staging loop ride the
             * ONE choreography owner the walk consults — the leg
             * names whatever the walk names, never its own hand copy.
             */
            WpHarness::stageLintRoots($scratch);
            $this->assertTrue(mkdir($scratch . '/connectors/demo', 0755, true), 'staging: the demo connector tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/connectors/demo/good.php', "<?php\n// lintable connector source\n"), 'staging: the connector source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(mkdir($scratch . '/tests/unit', 0755, true), 'staging: the scratch tests tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($scratch . '/tests/unit/RealTest.php', "<?php\n// lintable tests-root source\n"), 'staging: the tests-root source must write — a staging failure fails as staging, never as the lint verdict.');

            /*
             * The readable-trees control FIRST: the same scratch,
             * everything readable, exits 0 with every staged source
             * counted — the fence below changes nothing about the
             * walk's green shape.
             */
            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $readable = implode("\n", $output);
            $this->assertSame(0, $exit, "The readable scratch tree must lint green: {$readable}");
            $this->assertStringContainsString('4 file(s) checked, 0 failure(s)', $readable, 'Every staged source counts (both real sources plus the copied tool files under bin/).');

            /*
             * The permission-denial probe (the capability this leg
             * premises, in the canSymlink shape — probed, never
             * assumed): a process the permissions cannot deny (root
             * walks a chmod-000 directory open) can never drive the
             * refusal, and a leg that cannot go red is a vacuous
             * green — skip, naming the premise. The probe restores its
             * own permissions so the finally's rrmdir owns it either
             * way.
             */
            $probe = $scratch . '/perm-probe';
            $this->assertTrue(mkdir($probe, 0755, true), 'staging: the probe directory must create — a staging failure fails as staging, never as the capability verdict.');
            $this->assertTrue(chmod($probe, 0000), 'staging: the probe directory must lock — a staging failure fails as staging, never as the capability verdict.');
            $denied = WpHarness::canDenyDirectoryOpen($probe);
            $this->assertTrue(chmod($probe, 0755), 'staging: the probe directory must unlock again — a staging failure fails as staging, never as the finally\'s cleanup.');
            if (! $denied) {
                $this->markTestSkipped('This process walks a chmod-000 directory open (permissions cannot deny it — root-shaped), so the unreadable-subdirectory leg can never drive its refusal: the walk would read the tree and exit as the readable control above.');
            }

            /*
             * The locked leg: the chmod-000 child under the tests root
             * aborts the walk mid-recursion; the gate answers with the
             * NAMED failure and a non-zero exit, the readable trees'
             * count stays in the summary, and no uncaught SPL fatal
             * rides (red at HEAD: exit 255, a stack trace, no
             * summary, no verdict).
             */
            $this->assertTrue(mkdir($locked, 0755, true), 'staging: the locked tree must create — a staging failure fails as staging, never as the lint verdict.');
            $this->assertNotFalse(file_put_contents($locked . '/Hidden.php', "<?php\n// unreachable through the lock\n"), 'staging: the locked-tree source must write — a staging failure fails as staging, never as the lint verdict.');
            $this->assertTrue(chmod($locked, 0000), 'staging: the locked tree must lock — a staging failure fails as staging, never as the lint verdict.');

            $output = array();
            $exit = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/bin/lint-php.php') . ' 2>&1', $output, $exit);
            $refusal = implode("\n", $output);
            $this->assertSame(1, $exit, "The unreadable subdirectory fails the gate by its own named verdict — never the uncaught fatal's exit 255 (red at HEAD): {$refusal}");
            $this->assertStringContainsString('unreadable subdirectory', $refusal, 'The refusal names the unreadable-entry class — the tree is named, never a stack trace.');
            $this->assertStringContainsString('file(s) checked', $refusal, 'The partial count from the readable trees stays loud in the summary (the glm17-17 conversion shape).');
            /*
             * OCR round 43 (t31-ocr43-7): the summary names the count
             * that CARRIES the verdict — the refusal-only red shape
             * once printed '0 failure(s)' while exiting 1, the line
             * and the verdict disagreeing about where the red came
             * from (red at HEAD: no refusal count in the output).
             */
            $this->assertStringContainsString('0 failure(s), 1 walk refusal(s)', $refusal, 'The refusal count rides the summary line beside the failure count — the printed line and the exit verdict agree about the red\'s source.');
            $this->assertStringNotContainsString('Fatal error', $refusal, 'No uncaught SPL fatal rides the walk anymore.');
            $this->assertStringNotContainsString('Hidden.php', $refusal, 'The unreachable source itself is never linted — the tree is judged whole or not at all.');
        } finally {
            // The locked tree must unlock BEFORE the removal owner walks
            // it (rrmdir cannot enter what the process cannot read).
            @chmod($locked, 0755);
            WpHarness::releaseScratch($scratch);
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
