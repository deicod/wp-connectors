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
 * shapes flag and the legitimate shapes stay clean. glm36-1 extends
 * the charter to the destructuring WRITE spellings: '[ ... ] =' and
 * list() targets re-bind the include variable through a channel
 * neither write-shape check saw on the plain path (and the square
 * spelling escaped both), so both refuse on both paths now.
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

    public function testAByReferenceAliasOfTheIncludeVariableRefuses(): void
    {
        /*
         * glm18-18 (verifier round on the round-18 scanner fixes):
         * `$alias = &$f;` is a write channel the plain-variable
         * collector cannot see — writes through the alias never match
         * the assignment regex — so the proof held on the literal alone
         * while the runtime include loaded a foreign path (empirically
         * confirmed). The alias's existence in a visible region
         * refuses the proof, the same channel the map path has refused
         * since the GLM10 #14 round.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$f = __DIR__ . '/ok.php';\n\$alias = &\$f;\n\$alias = '/outside/pwned.php';\nrequire \$f;\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An alias of the include variable must refuse the proof.');
        $this->assertStringContainsString('require $f', implode("\n", $violations));
    }

    public function testAVariableThroughVariableChainResolvesTwoLevels(): void
    {
        /*
         * glm28-15: the one per-assignment proof helper must keep the
         * two-level resolution the uninstall owner chain rides — a
         * plain variable whose assignment is another variable whose
         * assignment carries the anchor literal proves in-root, and an
         * escaping second hop keeps the 'resolves through' reason.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$a = \$b;\n\$b = __DIR__ . '/inside.php';\nrequire \$a;\n"
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root), 'A two-level anchored chain stays clean.');

        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$a = \$b;\n\$b = '/outside/escape.php';\nrequire \$a;\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An escaping second hop keeps flagging.');
        $this->assertStringContainsString('variable $a resolves through $b to a path that is not anchored', implode("\n", $violations));
    }

    public function testAThreeHopChainKeepsTheCapRejection(): void
    {
        /*
         * glm28-15's deliberate TWO-LEVEL cap, pinned: a 3-hop chain
         * keeps the generic not-anchored rejection at the second hop
         * (the plain-variable branch re-enters only from depth 0).
         * The cap is the cycle guard — deepening the resolution would
         * be a verdict CHANGE (the clean third hop would start
         * proving), not a refactor, and must come with new fixtures.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$a = \$b;\n\$b = \$c;\n\$c = __DIR__ . '/inside.php';\nrequire \$a;\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A third hop stays a violation under the two-level cap.');
        $this->assertStringContainsString('variable $a resolves through $b to a path that is not anchored', implode("\n", $violations));

        // A variable cycle terminates at depth 1 through the fall-through
        // proofs — never a recursion.
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$a = \$b;\n\$b = \$a;\nrequire \$a;\n"
        );

        $this->assertNotEmpty(wp_connectors_self_containment_violations($this->root), 'A variable cycle terminates with a violation.');
    }

    /**
     * @dataProvider destructuringLaunderingProvider
     */
    public function testADestructuringWriteRefusesTheProofOnBothPaths(string $source, string $include_variable): void
    {
        /*
         * glm36-1: the PHP 7.1+ '[ ... ] =' destructuring target is the
         * spelling twin of the list() refusal — and neither spelling was
         * refused on the PLAIN-variable path (the collector matches
         * writes TO the variable only). Both laundered a foreign rewrite
         * past the gate on both paths (empirically confirmed: zero
         * violations while the runtime require loaded /etc/passwd;
         * the semantically identical list() form refused on the map
         * path). Both spellings refuse on both paths now.
         */
        file_put_contents($this->root . '/fixture.php', $source);

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A destructuring write to the include variable must refuse the proof.');
        $this->assertStringContainsString('require ' . $include_variable, implode("\n", $violations));
    }

    /**
     * @return array<string, list<string>>
     */
    public function destructuringLaunderingProvider(): array
    {
        return array(
            'map path, square spelling' => array(
                "<?php\n\$map = array( __DIR__ . '/ok.php' );\n[ \$map ] = array( '/etc/passwd' );\nforeach (\$map as \$p) {\n    require \$p;\n}\n",
                '$p',
            ),
            'map path, nested group carrying the map' => array(
                "<?php\n\$map = array( __DIR__ . '/ok.php' );\n[ \$other, \$map ] = array( 'x', '/etc/passwd' );\nforeach (\$map as \$p) {\n    require \$p;\n}\n",
                '$p',
            ),
            'map path, keyed spelling' => array(
                "<?php\n\$map = array( __DIR__ . '/ok.php' );\n[ 'k' => \$map ] = array( 'k' => '/etc/passwd' );\nforeach (\$map as \$p) {\n    require \$p;\n}\n",
                '$p',
            ),
            'map path, loop-visible square write' => array(
                "<?php\n\$map = array( __DIR__ . '/g.php' );\nforeach (\$map as \$p) {\n    require \$p;\n    [ \$map ] = array( '/etc/passwd' );\n}\n",
                '$p',
            ),
            'plain path, square spelling' => array(
                "<?php\n\$f = __DIR__ . '/ok.php';\n[ \$f ] = array( '/etc/passwd' );\nrequire \$f;\n",
                '$f',
            ),
            'plain path, list() spelling' => array(
                "<?php\n\$f = __DIR__ . '/ok.php';\nlist( \$f ) = array( '/etc/passwd' );\nrequire \$f;\n",
                '$f',
            ),
        );
    }

    public function testDestructuringReadsOfTheIncludeVariablesStayClean(): void
    {
        /*
         * The refusals judge WRITE channels only: the map as an array
         * VALUE ('$rows = array( $map )'), as a whole-array KEY
         * ('$rows[ $map ] = ...' — the statement-level anchor keeps a
         * bracket group preceded by the indexer variable a read), and a
         * destructuring targeting OTHER variables never re-bind the
         * include variable, so the proven literals keep holding.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$map = array( __DIR__ . '/a.php', __DIR__ . '/b.php' );\n\$rows = array( \$map );\n\$rows2[ \$map ] = 'v';\n[ \$x, \$y ] = array( 1, 2 );\n\$z = \$x + \$y;\nforeach (\$map as \$p) {\n    require \$p;\n}\n"
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root), 'Reads through the map and other-variable destructuring stay clean.');
    }
}
