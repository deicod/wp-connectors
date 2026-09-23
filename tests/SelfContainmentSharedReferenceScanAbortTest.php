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

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-shared-abort-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->root . '/*') ?: array()) as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->root);
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
         * CLAUSE (round 32): PCRE2's JIT ignores the depth limit
         * pcre.recursion_limit maps to (PCRE2 10.44's own docs —
         * "The depth limit is ignored by JIT"; PHP's own
         * ext/pcre recursion_limit test pins pcre.jit=0 beside the
         * lever), so on a JIT-enabled host the floor never fires
         * without this — the pin disables JIT for its duration
         * (construction-evident on this --without-pcre-jit build,
         * ini_set a no-op here). The limits are restored on every
         * exit path. The refusal names the file and carries the
         * engine's diagnostic; a candidate-free payload never
         * starts a match attempt and keeps its clean verdict under
         * the same floor; the control at the restored limit flags
         * normally on its own root.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $x = "../shared/foo.php";'
        );

        $host_limit = (string) ini_get('pcre.recursion_limit');
        $host_jit = ini_set('pcre.jit', '0');
        ini_set('pcre.recursion_limit', '1');
        try {
            $violations = wp_connectors_self_containment_violations($this->root);

            $this->assertCount(1, $violations, 'The aborting shared/ scan answers exactly the one refusal line — never a clean pass over a file whose references went unscanned.');
            $this->assertStringContainsString('fixture.php could not be scanned for shared/ references', $violations[0], 'The refusal names the file whose scan aborted.');
            $this->assertStringContainsString('the self-containment scan aborted (PCRE:', $violations[0], 'The refusal rides the seat\'s own loud vocabulary with the engine\'s diagnostic.');
        } finally {
            ini_set('pcre.recursion_limit', $host_limit);
            if (false !== $host_jit) {
                ini_set('pcre.jit', $host_jit);
            } else {
                ini_restore('pcre.jit');
            }
        }

        $plain_root = $this->root . '-plain';
        mkdir($plain_root, 0755, true);
        file_put_contents(
            $plain_root . '/plain.php',
            "<?php \$plain = 1;\n"
        );
        ini_set('pcre.recursion_limit', '1');
        try {
            // A file no shared/ or ../ candidate lives in never starts
            // a match attempt, so the floor limit never fires.
            $this->assertSame(array(), wp_connectors_self_containment_violations($plain_root), 'A candidate-free payload keeps its clean verdict under the pinned floor — the refusal is the abort, never the size.');
        } finally {
            ini_set('pcre.recursion_limit', $host_limit);
        }
        foreach ((glob($plain_root . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($plain_root);

        // The control at the restored limit, on its own root (the
        // views cache keys the path it already answered): the same
        // reference flags through the scan it just aborted on.
        $control = $this->root . '-control';
        mkdir($control, 0755, true);
        file_put_contents(
            $control . '/fixture.php',
            '<?php $x = "../shared/foo.php";'
        );
        $this->assertNotEmpty(wp_connectors_self_containment_violations($control), 'The control flags at the host default — the abort above was the pinned limit, never the payload.');
        foreach ((glob($control . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($control);
    }
}
