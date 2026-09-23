<?php
/**
 * Self-containment shared/-reference scan abort fixtures (the R31-C1
 * class: t31-glm31-1's shared/ seat).
 *
 * The '../' arm's unbounded repetition exhausts pcre.recursion_limit
 * at DEFAULT limits on a long '../' run, and the seat's truthiness
 * consumed the FALSE as "no reference" — call-wide, so the
 * '\bshared/' arm died with it: every shared/ reference in the file
 * silently invisible (driven: a lint-clean 600KB '../' run ahead of
 * 'shared/foo.php' answered 0 violations where the 3KB twin flags;
 * inspect-artifact rides this seat over hostile extracted trees with
 * no size cap and its php -l rejection runs after the scan — R30-C1's
 * exact threat model at the sibling seat that round hardened). FALSE
 * is the LOUD refusal naming the file now. Round 32 (t31-glm32-1)
 * corrected the seat's own spelling: the kept repetition burned the
 * same O(n-squared) restart storm BELOW its recursion threshold (a
 * benign 90KB '../' file scanned clean in 27s, 180KB in 4.4 minutes
 * — a hostile extracted tree stalls the gate for minutes per file
 * with a clean verdict and no diagnostic) and answered a FALSE
 * refusal PAST it over bytes that scan in milliseconds; the
 * round-31 derivation had measured only repetition-keeping
 * respellings, at the one size where the abort hides the storm. The
 * FLAT '\.\./shared/' arm is verdict-identical (20,000 fuzz shapes,
 * zero mismatches, beside the structural argument: the last
 * repetition of any run sits immediately before 'shared/'), linear
 * at every size, and still abortable at the floor levers. These
 * fixtures pin the reference visible through the run at every
 * size, the benign run clean and fast, the refusal loud at the
 * pinned floors, and the small-run twin byte-identical.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentSharedReferenceScanAbortTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    /**
     * @var list<string> Extra scratch roots created mid-test; tearDown
     *                    releases every exit path through the ONE owner.
     */
    private $extra_roots = array();

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-shared-abort-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        WpHarness::releaseScratch($this->root, ...$this->extra_roots);
    }

    public function testASizeDotDotRunCannotHideTheSharedReference(): void
    {
        /*
         * The driven fail-open (R31-C1, security) and its round-32
         * correction (R32-1): the kept repetition made the 600KB
         * '../' run answer the ABORT's refusal — visible, but only
         * by luck of the threshold — while below it the same
         * repetition burns the quadratic restart storm (measured
         * 27s at 90KB benign, 4.4 minutes at 180KB) and a benign
         * past-threshold run answers a FALSE refusal. The FLAT arm
         * is linear at every size: the shared/ reference is VISIBLE
         * through the 600KB run (its real violation, not the
         * abort's refusal — strictly more precise than round 31's
         * shape), the benign twin scans clean in milliseconds, and
         * the 3KB twin keeps its byte-identical violation. The
         * recursion limit is PINNED for the duration (the floor
         * pin's own idiom): a host default far above 200000
         * iterations must not re-open the storm, one far below
         * must not abort the twin.
         */
        file_put_contents(
            $this->root . '/large.php',
            '<?php $x = "' . str_repeat('../', 200000) . '"; $y = "shared/foo.php";'
        );
        file_put_contents(
            $this->root . '/benign.php',
            '<?php $x = "' . str_repeat('../', 200000) . '";'
        );
        file_put_contents(
            $this->root . '/small.php',
            '<?php $x = "' . str_repeat('../', 1000) . '"; $y = "shared/foo.php";'
        );

        $host_limit = (string) ini_get('pcre.recursion_limit');
        ini_set('pcre.recursion_limit', '100000');
        try {
            $started = microtime(true);
            $violations = wp_connectors_self_containment_violations($this->root);
            $elapsed = microtime(true) - $started;
        } finally {
            ini_set('pcre.recursion_limit', $host_limit);
        }
        $report = implode("\n", $violations);

        $this->assertCount(2, $violations, 'The 600KB and 3KB reference-bearing twins each answer exactly their one violation — the benign 600KB run contributes nothing.');
        $this->assertStringContainsString('large.php references shared/ (generated copies only, never source includes).', $report, 'The shared/ reference is visible THROUGH the 600KB run — the real violation, never the abort\'s refusal and never invisibility (red at round 31\'s repetition: the refusal; red at round 31\'s HEAD: 0 violations).');
        $this->assertStringContainsString('small.php references shared/ (generated copies only, never source includes).', $report, 'The 3KB twin keeps its one violation byte-identical.');
        $this->assertStringNotContainsString('could not be scanned for shared/ references', $report, 'A benign or reference-bearing run at the default limit never answers the refusal — the flat arm left no threshold to cross.');
        $this->assertLessThan(10.0, $elapsed, sprintf('The 600KB run scans in wall-clock the linear pipeline owns (%.2fs here) — the bound guards the quadratic class the repetition paid in minutes at this size (red at round 31\'s repetition: minutes-to-abort).', $elapsed));
    }

    public function testAnAbortOverTheSharedReferenceScanRefusesLoudlyNamingTheFile(): void
    {
        /*
         * The refusal half (glm36-8, the pinned-limit idiom the
         * suite's abort pins ride — glm28-1): at the recursion floor
         * 1 ANY candidate-bearing match attempt aborts — the flat
         * arm's alternation still consumes the frames, verified on
         * this engine, so the recursion lever fires where the
         * include seat's pin rides the backtrack lever. THE JIT
         * CLAUSE (round 32) IS A SPAWNED CHILD NOW (round 33,
         * php-src-verified): PCRE2's JIT ignores the depth limit
         * pcre.recursion_limit maps to, and round 32's mid-process
         * ini_set('pcre.jit','0') cannot reach an already-compiled
         * pattern — PHP's per-process preg cache keys on the
         * pattern string alone, JIT is baked at cache-insert, and
         * the pcre.jit handler never invalidates the cache, so on
         * a stock JIT host any earlier scan of the same pattern
         * (this class's own first test included) leaves the floor
         * dead: an order-dependent false red. The floor leg rides
         * a fresh child with both flags on the COMMAND LINE
         * (before any compile — the ledgered -d-before-script
         * note), pcre.jit=0 beside the recursion floor exactly as
         * PHP's own ext/pcre recursion_limit test pairs them; the
         * child is deterministically abortable on every host.
         * Exec-less hosts skip loudly (the ocr20-5 doctrine). ROUND
         * 34 RESTORED THE PIN'S FULL REGIME (the review's
         * R34-4/5/7): the child scans BOTH roots — the
         * candidate-bearing file answering exactly ONE line (the
         * count printed ahead of the verdicts, the deleted
         * assertCount restored in child form: a duplicated refusal
         * or an extra line beside it reddens) and the
         * candidate-free plain file keeping its CLEAN verdict under
         * the same floor (the recursion-lever leg round 33's
         * rewrite dropped — nothing else pins it, the include
         * seat's plain leg riding the backtrack lever); the spawn
         * rides coreutils timeout(1) on POSIX hosts (the glm20-6
         * doctrine — a stalled scan hangs phpunit inside exec()
         * forever otherwise, this very loop having measured
         * minutes-per-megabyte quadratic seats) and the child's
         * report carries the negative diagnostics needle
         * (glm20-3): a Warning/Notice/Deprecated printed beside
         * the verdicts fails the test, never passes green. The
         * control at the restored limit flags normally in-process
         * on its own root.
         */
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('The shared/-reference recursion floor requires a spawned child with pcre.jit=0 set before compile — no spawn capability on this host.');
        }

        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $x = "../shared/foo.php";'
        );
        $plain_root = $this->root . '-plain';
        mkdir($plain_root, 0755, true);
        $this->extra_roots[] = $plain_root;
        file_put_contents(
            $plain_root . '/plain.php',
            "<?php \$plain = 1;\n"
        );

        $library = realpath(__DIR__ . '/../bin/check-conventions.php');
        $this->assertNotFalse($library, 'The conventions library resolves before the child embeds it.');
        $child_code = 'require ' . var_export($library, true) . ';'
            . ' $candidate = wp_connectors_self_containment_violations(' . var_export($this->root, true) . ');'
            . ' $plain = wp_connectors_self_containment_violations(' . var_export($plain_root, true) . ');'
            . ' print("candidate=" . count($candidate) . "\\n" . implode("\\n", $candidate)'
            . ' . "\\nplain=" . count($plain) . "\\n" . implode("\\n", $plain));';
        /*
         * t31-glm35-5 (the review's R35-4): the timeout prefix probes
         * for the BINARY, not the platform — stock macOS is a POSIX
         * host without coreutils timeout(1), where the round-34
         * prefix made sh answer 'command not found' exit 127 and the
         * test RED as a failure on a previously-green host class the
         * harness doctrine names. The prefix rides only where the
         * binary exists; without it the child runs unbounded exactly
         * as it did before round 34 (the SecureFixtures owner's own
         * accepted posture for the same corner).
         */
        $timeout_prefix = '';
        if (WpHarness::isPosixHost()) {
            exec('command -v timeout', $probe_output, $probe_exit);
            if (0 === $probe_exit) {
                $timeout_prefix = 'timeout 30 ';
            }
        }
        $command = sprintf(
            '%s%s -d pcre.jit=0 -d pcre.recursion_limit=1 -r %s 2>&1',
            $timeout_prefix,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($child_code)
        );
        $output = array();
        $exit = 0;
        exec($command, $output, $exit);
        $report = implode("\n", $output);

        $this->assertSame(0, $exit, 'The floor child completes — the abort is a verdict, never a fatal (a timeout exit 124 is the stall it bounds).');
        $this->assertMatchesRegularExpression('/^candidate=1$/m', $report, 'The aborting shared/ scan answers EXACTLY the one refusal line — the count restored in child form, a duplicated refusal or any extra line reddening (round 33\'s rewrite had weakened assertCount to contains-only).');
        $this->assertStringContainsString('fixture.php could not be scanned for shared/ references', $report, 'The aborting shared/ scan answers the loud refusal naming the file — on every host, JIT or not (round 32\'s in-process floor was JIT-blind).');
        $this->assertStringContainsString('the self-containment scan aborted (PCRE:', $report, 'The refusal rides the seat\'s own loud vocabulary with the engine\'s diagnostic.');
        $this->assertStringNotContainsString('references shared/ (generated copies only', $report, 'The floor answers the refusal, never the violation — the abort fired before any match completed.');
        $this->assertMatchesRegularExpression('/^plain=0$/m', $report, 'The candidate-free payload keeps its CLEAN verdict under the same recursion floor — the refusal is the abort, never the size (round 33\'s rewrite dropped this leg; nothing else pins the recursion lever).');
        $this->assertDoesNotMatchRegularExpression('/^(?:PHP )?(?:Warning|Notice|Deprecated):/m', $report, 'The child\'s report carries no engine diagnostic beside the verdicts (the glm20-3 boundary-regime needle).');

        // The control at the host default, in-process on its own root
        // (the views cache keys the path it already answered): the same
        // reference flags through the scan the child refused.
        $control = $this->root . '-control';
        mkdir($control, 0755, true);
        $this->extra_roots[] = $control;
        file_put_contents(
            $control . '/fixture.php',
            '<?php $x = "../shared/foo.php";'
        );
        $this->assertNotEmpty(wp_connectors_self_containment_violations($control), 'The control flags at the host default — the abort above was the pinned limit, never the payload.');
    }
}
