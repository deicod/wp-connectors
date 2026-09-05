<?php
/**
 * Self-containment scanner compound-write fixtures (glm18-8).
 *
 * The write-shape recognizer matched only '$var =' / '$var .=', so a
 * compound '$map += $other;' union-merge was an INVISIBLE write channel
 * — the element-literal proof then concluded all runtime values were
 * the proven literals while the array union actually injected foreign
 * entries (round 18 #8, empirically confirmed: zero violations on a
 * crafted fixture whose runtime require loaded a foreign path;
 * array_merge was correctly flagged, += was not). Both write-shape
 * checks (the collector and the map-literal recognizer) match every
 * compound assignment form now. These fixtures pin the laundering
 * shapes flag and the legitimate shapes stay clean.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentCompoundWritesTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-compound-writes-' . uniqid('', true);
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

    public function testAnArrayUnionMergeAfterTheLiteralFlags(): void
    {
        /*
         * The round-18 laundering shape: the += write was invisible to
         * both write-shape checks, so the proof held on the literal
         * alone while the union iterated $other's foreign entries.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$map = array( __DIR__ . '/safe.php' );\n\$map += \$other;\nforeach (\$map as \$f) {\n    require \$f;\n}\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An += union-merge write must refuse the map-literal proof.');
        $this->assertStringContainsString('require $f', implode("\n", $violations));
    }

    /**
     * @dataProvider compoundOperatorProvider
     */
    public function testCompoundWritesToThePathVariableFlag(string $operator): void
    {
        /*
         * A compound write to the include variable itself collected only
         * its literal predecessor when the operator was anything but =
         * or .= — the runtime value (concatenation/arithmetic on the
         * path) was never proven.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$f = __DIR__ . '/a.php';\n\$f {$operator} \$suffix;\nrequire \$f;\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, "A '{$operator}=' write must not hide behind the plain-literal proof.");
        $this->assertStringContainsString('require $f', implode("\n", $violations));
    }

    /**
     * @return array<string, list<string>>
     */
    public function compoundOperatorProvider(): array
    {
        return array(
            '+=' => array('+='),
            '-=' => array('-='),
            '*=' => array('*='),
            '/=' => array('/='),
            '%=' => array('%='),
            '**=' => array('**='),
            '??=' => array('??='),
            '&=' => array('&='),
            '|=' => array('|='),
            '^=' => array('^='),
            '<<=' => array('<<='),
            '>>=' => array('>>='),
        );
    }

    public function testAWholeLiteralAppendUnionStaysAnalyzedPerSource(): void
    {
        /*
         * '$map += array(...)' is a literal-shaped compound write: the
         * write-shape check passes (both writes whole-array literals),
         * and the collector's every-assignment-must-prove rule then
         * proves each union SOURCE separately — an out-of-root element
         * in the unioned literal flags exactly as the same element in
         * the initial literal would.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$map = array( __DIR__ . '/ok.php' );\n\$map += array( '/etc/passwd' );\nforeach (\$map as \$f) {\n    require \$f;\n}\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'The unioned literal\'s out-of-root element must flag.');
        $this->assertStringContainsString('require $f', implode("\n", $violations));
    }

    public function testAWhollyInRootCompoundUnionStaysClean(): void
    {
        /*
         * The gate stays permissive for the proven shape: two in-root
         * literals joined by += prove like one literal list.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$map = array( __DIR__ . '/x.php' );\n\$map += array( __DIR__ . '/y.php' );\nforeach (\$map as \$f) {\n    require \$f;\n}\n"
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root));
    }

    public function testPlainConcatAppendStaysFlaggedAsBefore(): void
    {
        /*
         * .= was the one compound form the old regexes matched; it must
         * stay covered (the runtime value is the concatenation, never
         * the RHS alone).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$f = __DIR__ . '/a.php';\n\$f .= \$suffix;\nrequire \$f;\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A .= write must keep flagging.');
        $this->assertStringContainsString('require $f', implode("\n", $violations));
    }
}
