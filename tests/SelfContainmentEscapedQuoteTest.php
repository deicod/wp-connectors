<?php
/**
 * Self-containment scanner escaped-quote fixtures (glm14-1).
 *
 * The quoted-literal extraction stopped each match AT a backslash-
 * escaped closing quote, so every byte after it was invisible to all
 * three self-containment gates (check-conventions, build pre-gate +
 * staged gate, inspect-artifact — the one shared engine walk): the
 * truncated head carried no '..' segment, the escape walk never ran,
 * and the escape-aware runtime-segment blanker erased the whole
 * literal, so `require __DIR__ . '/a\'./../../../outside.php';`
 * (php -l clean; the runtime value resolves outside the plugin dir)
 * scanned to zero violations. The glm29 ledger line that claimed
 * escaped-quote shapes are caught downstream was falsified by this
 * spelling — its pinned shape was an escaped '$' inside double
 * quotes, a different vector with no traversal behind the escape.
 * These fixtures pin the extraction seam (escape-aware, decoded to
 * the runtime value) and the gate verdicts on both sides of the fix.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentEscapedQuoteTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-self-containment-escaped-quote-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        WpHarness::releaseScratch($this->root);
    }

    public function testTheEscapedQuoteTraversalLaunderingFlags(): void
    {
        /*
         * The round's repro, exactly as driven red at HEAD: php -l
         * clean (verified by the driven leg), the runtime value
         * resolves outside the plugin dir, and the pre-fix scan
         * answered ZERO violations through all three gates.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\nrequire __DIR__ . '/a\\'./../../../outside.php';\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An escaped-quote include whose runtime value escapes the plugin dir must flag.');
        $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));
    }

    public function testTheExtractionIsEscapeAwareAndDecodedToTheRuntimeValue(): void
    {
        /*
         * The seam pin: ONE literal, its inner text the RUNTIME VALUE
         * (the escaped quote decoded). Red at HEAD the extraction
         * answered the truncated head '/a\' — no '..' segment, which
         * is the laundering itself. The legitimate-escape leg beside
         * it pins that ordinary escaped quotes decode without
         * corrupting the literal set.
         */
        $repro = wp_connectors_quoted_literals("require __DIR__ . '/a\\'./../../../outside.php';");

        $this->assertSame(array( array( '\'', "/a'./../../../outside.php" ) ), $repro);

        $legit = wp_connectors_quoted_literals("require __DIR__ . '/sub/it\\'s/fine.php';");

        $this->assertSame(array( array( '\'', "/sub/it's/fine.php" ) ), $legit);
    }

    public function testTheDecodingIsQuoteStyleAware(): void
    {
        /*
         * glm28-15: the blind callback decoded \' and \" alike, so a
         * SINGLE-QUOTED \" decoded to " — a value PHP never computes
         * (the backslash IS the value there), violating glm14-1's
         * runtime-values promise while the correct owner
         * (wp_connectors_unescape_php_string_literal) sat unused.
         * The pair's runtime value rides the owner now: the single
         * quote resolves \' and \\ only, the double quote the full
         * escape table PHP itself computes.
         */
        $single = wp_connectors_quoted_literals('$x = ' . "'a\\\\\"b'" . ';');
        $this->assertSame(array( array( "'", 'a\\"b' ) ), $single, 'A single-quoted \\" keeps its backslash — PHP never decodes it there (red at HEAD: decoded to a plain ").');

        $single_slash = wp_connectors_quoted_literals('$x = ' . "'a\\\\b'" . ';');
        $this->assertSame(array( array( "'", 'a\\b' ) ), $single_slash, 'A single-quoted double backslash resolves to one — the two escapes the single-quote arm owns.');

        $double = wp_connectors_quoted_literals('$x = "a\\"b";');
        $this->assertSame(array( array( '"', 'a"b' ) ), $double, 'A double-quoted \\" decodes to the quote — the blind leg that must stay.');

        $hex = wp_connectors_quoted_literals('$x = "/sub/\\x2e\\x2e/x.php";');
        $this->assertSame(array( array( '"', '/sub/../x.php' ) ), $hex, 'The double-quoted value computes the FULL escape table PHP resolves — the hex-spelled dots are the traversal they spell at runtime, the owner\'s own contract (glm14-1: judged by what PHP computes from it).');
    }

    public function testTheIdenticalIncludeWithoutTheEscapedQuoteStaysRefused(): void
    {
        /*
         * The control leg: the same traversal with a plain segment
         * byte where the escaped quote sat was always refused and
         * must stay refused — the fix widens the literal view, never
         * narrows the verdict.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\nrequire __DIR__ . '/a/../../../outside.php';\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An unescaped traversal include must stay flagged.');
        $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));
    }

    public function testALegitimateEscapedQuoteInADownwardPathStaysClean(): void
    {
        /*
         * The tolerance leg: an escaped quote inside an ordinary
         * downward literal (an apostrophe in a filename) is a legal
         * spelling and must not corrupt the extracted literal set
         * into a violation.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\nrequire __DIR__ . '/sub/it\\'s/fine.php';\n"
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root));
    }

    public function testTheEmptyLiteralPairingLaunderingFlags(): void
    {
        /*
         * glm15-2: the grammar's '+'-quantifier copy (the glm14-1
         * spelling this seam rode until the consolidation) could not
         * match an EMPTY literal, so the empty literal's closing quote
         * PAIRED with the next literal's opening quote — the traversal
         * literal never captured, zero violations through every gate
         * (driven red at HEAD over exactly this fixture).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\nrequire __DIR__ . \"\" . \"/sub/../../outside.php\";\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An empty-literal-glued traversal include must flag (red at HEAD: zero violations).');
        $this->assertStringContainsString('outside.php', implode("\n", $violations), 'The verdict names the escaping include.');
    }

    public function testTheExtractionCapturesEmptyLiteralsAsThemselves(): void
    {
        /*
         * The seam pin: an empty literal matches ITSELF, so the
         * pairing can never cross literal boundaries — both literals
         * of the driven expression answer, the empty one with its
         * (empty) runtime value (red at HEAD: ONE mispaired literal,
         * the ' . ' text between the quotes).
         */
        $repro = wp_connectors_quoted_literals('__DIR__ . "" . "/sub/../../outside.php"');

        $this->assertSame(
            array(
                array( '"', '' ),
                array( '"', '/sub/../../outside.php' ),
            ),
            $repro
        );
    }
}
