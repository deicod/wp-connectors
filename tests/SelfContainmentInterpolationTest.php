<?php
/**
 * Self-containment scanner interpolation fixtures (glm29-3).
 *
 * The include proof treated interpolated double-quoted literals
 * ("/sub/$name.php") as static because only '${' counted as dynamic —
 * so runtime-controlled '../' traversal laundered past every layer:
 * the literal scan judged the interpolated text as a static in-root
 * segment, the runtime-segment blanking erased the whole quoted string
 * to '', and the assignment substitution never ran because the include
 * statement textually references no variable (the '$name' lives inside
 * the quotes). The '${'-only dynamic test also SUPPRESSED the
 * unanchored-literal flag for the one form it did see, leaving an
 * unanchored runtime-built target flagged nowhere. These fixtures pin
 * every interpolation spelling closed in both positions, the
 * assignment-mediated laundering route, and the tolerances that stay
 * (single-quoted '$' text is a literal filename; the deliberate
 * over-detection of an escaped '$' is documented here as the
 * conservative direction).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentInterpolationTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-self-containment-interpolation-' . uniqid('', true);
        mkdir($this->root . '/sub', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->root . '/sub/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($this->root . '/sub');
        foreach ((glob($this->root . '/*') ?: array()) as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->root);
    }

    public function testTheRound29ReproLaunderingTraversalThroughInterpolationFlags(): void
    {
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$name = \"../../../evil\";\nrequire __DIR__ . \"/sub/\$name.php\";\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An interpolated include whose variable carries traversal must flag.');
        $this->assertStringContainsString('not provably inside the plugin dir', implode("\n", $violations));
    }

    /**
     * @return array<string, list<string>>
     */
    public function interpolationSpellingProvider(): array
    {
        return array(
            'simple syntax' => array(
                "<?php\n\$name = 'down';\nrequire __DIR__ . \"/sub/\$name.php\";\n",
            ),
            'complex syntax' => array(
                "<?php\n\$name = 'down';\nrequire __DIR__ . \"/sub/{\$name}.php\";\n",
            ),
            'deprecated syntax' => array(
                "<?php\n\$name = 'down';\nrequire __DIR__ . \"/sub/\${name}.php\";\n",
            ),
            'variable variables' => array(
                "<?php\n\$name = 'down';\nrequire __DIR__ . \"/sub/\$\$name.php\";\n",
            ),
        );
    }

    /**
     * @dataProvider interpolationSpellingProvider
     */
    public function testEveryInterpolationSpellingIsARuntimeSegmentNotAStaticLiteral(string $source): void
    {
        /*
         * Even with an entirely downward assigned value, the
         * interpolated literal is runtime-built text: the gate must
         * classify it unprovable rather than trust the quoted bytes —
         * the assignment's value is invisible to a literal judgment
         * (the round's repro carried '../' through exactly that gap).
         */
        file_put_contents($this->root . '/fixture.php', $source);

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'Every interpolation spelling must treat the literal as runtime.');
        $this->assertStringContainsString('unresolvable runtime segments', implode("\n", $violations));
    }

    public function testAnUnanchoredInterpolatedIncludeFlagsToo(): void
    {
        /*
         * The old '${'-only dynamic test suppressed the unanchored flag
         * and the runtime layers route unanchored statements back to
         * the literal analysis — the one form the old rule recognized
         * was flagged NOWHERE.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$name = 'sub';\nrequire \"/\$name/x.php\";\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An unanchored runtime-built include must flag.');
        $this->assertStringContainsString('not anchored to the plugin dir', implode("\n", $violations));
    }

    public function testAnUnanchoredIncludeFlagsExactlyOncePerStatement(): void
    {
        /*
         * glm38-7: the unanchored judgment is per INCLUDE, not per
         * quoted literal — the per-literal loop this pin replaced never
         * read its variable and appended the identical violation N times
         * for an N-literal statement (check-conventions/build/inspect
         * output printed the same line repeatedly, and every reader had
         * to puzzle over a loop whose variable was unused). A
         * multi-literal unanchored statement flags exactly ONCE; the
         * runtime-segment analysis below still judges each literal's
         * own segments separately.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\nrequire 'a/' . 'b.php';\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertSame(
            1,
            substr_count(implode("\n", $violations), 'not anchored to the plugin dir'),
            'A two-literal unanchored include flags exactly once — the judgment is per statement.'
        );
    }

    public function testAssignmentMediatedInterpolationLaunderingFlags(): void
    {
        /*
         * The hidden-include substitution route: the include carries a
         * plain variable whose assignment builds the path through an
         * interpolated literal. The assignment's value must count as
         * built from unresolvable runtime segments.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\n\$page = 'x';\n\$path = __DIR__ . \"/sub/\$page.php\";\nrequire \$path;\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A path assignment built through interpolation must not prove the include.');
        $this->assertStringContainsString('unresolvable runtime segments', implode("\n", $violations));
    }

    public function testASingleQuotedDollarIsALiteralFilenameAndStaysClean(): void
    {
        /*
         * The quote-aware half of the predicate: '$' inside SINGLE
         * quotes never interpolates — the text is the filename, and a
         * downward single-quoted path stays proven in-root.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\nrequire __DIR__ . '/sub/\$name.php';\n"
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root));
    }

    public function testAnEscapedDollarInTheDoubleQuotedIncludeFlagsConservatively(): void
    {
        /*
         * The documented over-detection: an escaped "\$name" is a
         * literal dollar at runtime, but the gate treats any '$' in a
         * double-quoted literal as dynamic — a false violation a
         * maintainer can see beats a silent traversal hole (security
         * over tool convenience).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php\nrequire __DIR__ . \"/sub/\\\$name.php\";\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A double-quoted dollar flags even when escaped — the conservative direction.');
    }

    public function testTheOldDollarBraceDynamicJudgmentIsGone(): void
    {
        /*
         * Source pin: the quote-blind '${' spelling test no longer
         * exists anywhere in the proof machinery — every dynamic
         * judgment rides the quote-aware predicate (a reintroduced
         * spelling test would reopen the hole for the other forms).
         */
        $source = (string) file_get_contents(dirname(__DIR__) . '/bin/lib/plugin-tools.php');

        $this->assertSame(
            0,
            substr_count($source, "strpos(\$literal, '\${')"),
            'The quote-blind dynamic judgment must not come back.'
        );
    }
}
