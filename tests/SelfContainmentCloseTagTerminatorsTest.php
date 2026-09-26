<?php
/**
 * Self-containment scanner close-tag terminator fixtures (the R29-2 /
 * R29-3 class: t31-glm29-2's include seat and t31-glm29-3's collector
 * twins).
 *
 * PHP implies the semicolon at '?>' — a statement terminated by the
 * close tag is exactly as live as its ';'-spelled twin — but the
 * include owner's pattern demanded a literal ';', so a
 * close-tag-terminated include was INVISIBLE to every gate riding the
 * owner (driven: a php -l clean 'require dirname(__DIR__, 2) .
 * "/outside.php" ?>' answered 0 violations where the ';' twin flags),
 * and where a later ';' existed the greedy body GLUED across the close
 * tag into the unrelated code after it. The terminator alternation
 * ';|?>' (the ocr62-1 shape, generalized to this seat) rides the owner
 * now: the match is lazy and ends at whichever terminator comes first.
 * The SAME alternation rides the two collector seats (the assignment
 * collector and its write-shape twin, moving together per the glm27-10
 * owner doctrine): a close-tag-terminated WRITE with no later ';' was
 * never collected — the benign literal predecessor alone satisfied a
 * loop-shaped include while the second iteration required the outside
 * path, and an invisible non-literal write left the map-literal proof
 * standing (the glm18-7/8 write-visibility contract). These fixtures
 * pin the driven fail-opens closed, the glue bounded, and the benign
 * spellings byte-identical with their ';' twins.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentCloseTagTerminatorsTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-close-tag-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        WpHarness::releaseScratch($this->root);
    }

    public function testACloseTagTerminatedIncludeFlags(): void
    {
        /*
         * The driven fail-open (R29-2, security:medium): php -l clean,
         * zero violations at HEAD, flags exactly like its ';' twin now
         * (red at HEAD: 0 violations).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php require dirname(__DIR__, 2) . "/outside.php" ?>'
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A close-tag-terminated include is a live statement — PHP implies the semicolon (red at HEAD: 0 violations).');
        $this->assertStringContainsString('require dirname(__DIR__, 2)', implode("\n", $violations), 'The violation names the include.');
    }

    public function testTheMatchDoesNotGlueAcrossTheCloseTag(): void
    {
        /*
         * The second half of the finding: with a later ';' in the file
         * the greedy body glued from the include through the close tag
         * into the unrelated code after it — the violation once quoted
         * code the statement never contained. The lazy match ends at
         * whichever terminator comes FIRST.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php require dirname(__DIR__, 2) . \"/outside.php\" ?>\n<?php \$innocent = 'totally fine';\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'The include still flags through the close tag.');
        $this->assertStringContainsString('require dirname(__DIR__, 2)', $report, 'The include is flagged, never the unrelated code after the close tag.');
        $this->assertStringNotContainsString('totally fine', $report, 'The statement ends at the close tag — the later \';\' never glues back into it.');
    }

    public function testBenignCloseTagTerminatedIncludesStayCleanLikeTheirSemicolonTwins(): void
    {
        /*
         * The ';'-spelling parity the fix owes the benign tree: an
         * in-root literal include and a resolved plain-variable include
         * keep their clean verdicts through the close-tag spelling —
         * the implied-semicolon tail never rides the argument (a
         * 'require $path ?>' that fell off the variable arm would
         * phantom-flag as unanchored where its ';' twin resolves
         * clean).
         */
        file_put_contents(
            $this->root . '/literal.php',
            '<?php require __DIR__ . "/inc.php" ?>'
        );
        file_put_contents(
            $this->root . '/variable.php',
            '<?php $path = __DIR__ . "/ok.php"; require $path ?>'
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root), 'The benign close-tag spellings answer exactly their \';\' twins: zero violations.');
    }

    public function testACloseTagTerminatedWriteIsCollectedInTheLoopShape(): void
    {
        /*
         * The collector seat (t31-glm29-3): a close-tag-terminated
         * write with no later ';' was never collected, so the benign
         * literal predecessor alone satisfied the include while the
         * loop's second iteration required the outside path — a
         * lint-clean payload whose execution attempts the outside
         * require (red at the include-seat fix's tree: 0 violations).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php \$path = __DIR__ . '/a.php';\nforeach (array(1, 2) as \$n) { require \$path ?><?php \$path = dirname(__DIR__) . '/../outside.php' ?><?php }\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'The close-tag-terminated evil write is collected — the include resolves through it and flags.');
        $this->assertStringContainsString('escapes upward through dirname()', $report, 'The COLLECTED write resolves to the outside path, never the benign predecessor alone.');
        $this->assertStringContainsString('require $path', $report, 'The violation names the include.');
    }

    public function testACloseTagTerminatedWriteRefusesTheMapLiteralProof(): void
    {
        /*
         * The write-shape twin seat: an invisible non-literal write
         * left the map-literal proof standing on the collected literal
         * alone while the runtime value was the request parameter —
         * the map's foreach binding refuses and the include flags
         * (red at the include-seat fix's tree: 0 violations).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php \$map = array( __DIR__ . '/safe.php' );\nforeach (\$map as \$f) { require \$f ?><?php \$map = \$_GET['page'] ?><?php }\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'The close-tag-terminated non-literal write refuses the map-literal proof — the include flags.');
        $this->assertStringContainsString('require $f', implode("\n", $violations), 'The violation names the include.');
    }

    public function testTheSemicolonTwinsOfTheWriteShapesFlagIdentically(): void
    {
        /*
         * ';'-spelling parity for the collector seats: the same two
         * shapes with every close-tag terminator spelled ';' flag
         * through the same seats — the alternation changed nothing for
         * the ';' spellings.
         */
        file_put_contents(
            $this->root . '/collector.php',
            "<?php \$path = __DIR__ . '/a.php';\nforeach (array(1, 2) as \$n) { require \$path; \$path = dirname(__DIR__) . '/../outside.php'; }\n"
        );
        file_put_contents(
            $this->root . '/writeshape.php',
            "<?php \$map = array( __DIR__ . '/safe.php' );\nforeach (\$map as \$f) { require \$f; \$map = \$_GET['page']; }\n"
        );

        $report = implode("\n", wp_connectors_self_containment_violations($this->root));

        $this->assertStringContainsString('require $path', $report, 'The \';\' twin of the collector shape flags.');
        $this->assertStringContainsString('require $f', $report, 'The \';\' twin of the write-shape fixture flags.');
    }
}
