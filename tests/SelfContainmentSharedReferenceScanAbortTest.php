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
 * is the LOUD refusal naming the file now, and the repetition stays:
 * every linear respelling measured worse than the abort (possessive
 * trades it for an O(n-squared) restart storm, a bounded repeat still
 * answers the quadratic class) — the refusal the host-independent
 * half, R30-C1's own precedent. These fixtures pin the fail-open
 * closed, the refusal loud at the pinned recursion floor, and the
 * small-run twin byte-identical.
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
         * The driven fail-open (R31-C1, security): the lint-clean
         * 600KB '../' run (php -l verified this round) exhausted the
         * repetition's recursion frames at default limits,
         * preg_match() answered FALSE call-wide, and the truthiness
         * read "no reference" — the shared/ twin beside it went
         * invisible with the aborting arm (red at HEAD: 0
         * violations). The abort answers the REFUSAL now, never a
         * clean pass, and the 3KB twin keeps its one violation
         * byte-identical — the refusal is the abort, never the
         * payload's size class below the frames it takes.
         */
        file_put_contents(
            $this->root . '/large.php',
            '<?php $x = "' . str_repeat('../', 200000) . '"; $y = "shared/foo.php";'
        );
        file_put_contents(
            $this->root . '/small.php',
            '<?php $x = "' . str_repeat('../', 1000) . '"; $y = "shared/foo.php";'
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertStringContainsString('large.php could not be scanned for shared/ references', $report, 'A size-triggered abort over the shared/ seat is the loud refusal naming the file — never a silent clean pass (red at HEAD: 0 violations).');
        $this->assertStringContainsString('the self-containment scan aborted (PCRE:', $report, 'The refusal rides the sibling seats\' own loud vocabulary with the engine\'s diagnostic.');
        $this->assertStringContainsString('small.php references shared/ (generated copies only, never source includes).', $report, 'The 3KB twin keeps its one violation byte-identical — the fix changed the abort\'s consumption, never the matching verdicts.');
    }

    public function testAnAbortOverTheSharedReferenceScanRefusesLoudlyNamingTheFile(): void
    {
        /*
         * The refusal half (glm36-8, the pinned-limit idiom the
         * suite's abort pins ride — glm28-1): at the recursion floor
         * 1 ANY candidate-bearing match attempt aborts — this seat's
         * frames nest per '../' repetition iteration, so the
         * recursion lever fires where the include seat's pin rides
         * the backtrack lever — the limit restored on every exit
         * path. The refusal names the file and carries the engine's
         * diagnostic; a candidate-free payload never starts a match
         * attempt and keeps its clean verdict under the same floor;
         * the control at the restored limit flags normally on its
         * own root.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $x = "../shared/foo.php";'
        );

        $host_limit = (string) ini_get('pcre.recursion_limit');
        ini_set('pcre.recursion_limit', '1');
        try {
            $violations = wp_connectors_self_containment_violations($this->root);

            $this->assertCount(1, $violations, 'The aborting shared/ scan answers exactly the one refusal line — never a clean pass over a file whose references went unscanned.');
            $this->assertStringContainsString('fixture.php could not be scanned for shared/ references', $violations[0], 'The refusal names the file whose scan aborted.');
            $this->assertStringContainsString('the self-containment scan aborted (PCRE:', $violations[0], 'The refusal rides the seat\'s own loud vocabulary with the engine\'s diagnostic.');
        } finally {
            ini_set('pcre.recursion_limit', $host_limit);
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
