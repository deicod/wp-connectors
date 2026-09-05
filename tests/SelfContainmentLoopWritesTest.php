<?php
/**
 * Self-containment scanner loop-write fixtures (glm18-7).
 *
 * The include analyses' offset cut — "an assignment after the include
 * cannot be read by it" — modeled straight-line execution only: inside
 * a loop the include also sits in, a write placed AFTER it in the loop
 * body still executes before the include's next iteration, so loop
 * shapes laundered foreign include paths past the CI gate (round 18,
 * empirically confirmed: a while-loop reassignment after the require
 * produced zero violations while the second iteration included an
 * out-of-root marker file). These fixtures pin both directions: loop-
 * nested writes are visible to an include in the same loop, and the
 * legitimate loop shapes the real tree carries (a foreach over a
 * whole-array literal map — the uninstall owner chain's shape) stay
 * clean.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentLoopWritesTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-self-containment-' . uniqid('', true);
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

    /**
     * @dataProvider loopWriteFixtureProvider
     */
    public function testALoopNestedWriteAfterTheIncludeFlags(string $source): void
    {
        file_put_contents($this->root . '/fixture.php', $source);

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A write after the include inside a loop the include sits in must flag.');
        $this->assertStringContainsString('require $f', implode("\n", $violations));
    }

    /**
     * @return array<string, list<string>>
     */
    public function loopWriteFixtureProvider(): array
    {
        return array(
            'while loop' => array(
                "<?php\n\$f = __DIR__ . '/a.php';\n\$rounds = 0;\nwhile (\$rounds < 2) {\n    require \$f;\n    \$f = \$_GET['page'];\n    \$rounds++;\n}\n",
            ),
            'for loop' => array(
                "<?php\n\$f = __DIR__ . '/b.php';\nfor (\$i = 0; \$i < 2; \$i++) {\n    require \$f;\n    \$f = \$_REQUEST['target'];\n}\n",
            ),
            'foreach loop over a runtime source' => array(
                "<?php\n\$f = __DIR__ . '/c.php';\nforeach (array(1, 2) as \$n) {\n    require \$f;\n    \$f = \$_GET['page'];\n}\n",
            ),
        );
    }

    public function testAStraightLineWriteAfterTheIncludeStaysInvisible(): void
    {
        /*
         * The offset cut stays sound outside loops: a write after the
         * include in straight-line order never executes before it.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$f = __DIR__ . '/d.php';\nrequire \$f;\n\$f = \$_GET['page'];\n"
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root));
    }

    public function testAForEachOverAWholeArrayLiteralMapStaysClean(): void
    {
        /*
         * glm18-7's visible region widens for every loop that spans the
         * include — the legitimate shape the real tree carries (the
         * uninstall owner chain's literal __DIR__ map, GLM10 #14) must
         * stay clean: the map's only visible write is the whole-array
         * literal, and the binding resolves through it.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$map = array( __DIR__ . '/e.php', __DIR__ . '/f.php' );\nforeach (\$map as \$file) {\n    require \$file;\n}\n"
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root));
    }

    public function testALoopWriteAfterTheIncludeRefusesTheLiteralMapProof(): void
    {
        /*
         * The same widened region must refuse the map-literal proof: an
         * element write after the include inside the loop is a write the
         * map analysis cannot model, so the binding over the map stays
         * flagged (the array_writes_recognized half of glm18-7).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$map = array( __DIR__ . '/g.php' );\nforeach (\$map as \$file) {\n    require \$file;\n    \$map[] = \$_GET['extra'];\n}\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An element write inside the loop must refuse the map-literal proof.');
        $this->assertStringContainsString('require $file', implode("\n", $violations));
    }
}
