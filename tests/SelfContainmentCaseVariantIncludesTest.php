<?php
/**
 * Self-containment case-variant include fixtures (the R31-C2 class:
 * t31-glm31-2's include owner).
 *
 * PHP lexes require/include (and their _once forms)
 * case-insensitively, but the include owner's keyword arm spelled
 * the keywords byte-exact lowercase — so '<?PHP REQUIRE …' and
 * '<?php Include_Once …', both lint-clean, were INVISIBLE to every
 * gate riding the owner (driven: 0 violations where the lowercase
 * twin flags). The phpcs lowercase-keywords boundary gates the repo
 * tree only; the artifact channel is a demonstrated production path
 * php -l passes and phpcs never touches — the re-open rule's
 * demonstrated-production-path leg. The keywords match through
 * SCOPED (?i:…) groups now (the ocr46-9 idiom at this owner, the
 * same widening the import scanner and the argument derivations
 * already ride): the case fold lives on the keyword tokens alone,
 * and every spelling reaches the same terminator-matched statement
 * the lowercase ones always did. Round 32 (t31-glm32-2) swept the
 * same class through the LOOP-PROOF machinery the widened owner
 * feeds — the write-visibility span pattern, the array-writes
 * helper's arms, the assignment collector's region refusals, the
 * foreach-header collector and its as-split — where every keyword
 * once spelled byte-exact lowercase, so case-variant loop
 * carriers and 'AS &' bindings laundered foreign includes through
 * benign same-file writes (driven: 0 violations where the
 * all-lowercase twins flag). These fixtures pin the driven
 * fail-opens closed and the lowercase spellings byte-identical.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentCaseVariantIncludesTest extends TestCase
{
    /**
     * @var string Per-test fixture root.
     */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-connectors-case-variant-' . uniqid('', true);
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
     * @return list<array{0: string, 1: string}> Fixture name and code pairs.
     */
    public function provideCaseVariantEscapes(): array
    {
        return array(
            'uppercase REQUIRE with uppercase open tag' => array(
                'REQUIRE',
                '<?PHP REQUIRE dirname(__DIR__, 2) . "/outside.php";',
            ),
            'mixed-case Include_Once' => array(
                'Include_Once',
                '<?php Include_Once dirname(__DIR__, 2) . "/outside2.php";',
            ),
        );
    }

    /**
     * @dataProvider provideCaseVariantEscapes
     */
    public function testACaseVariantIncludeFlagsLikeItsLowercaseTwin(string $spelling, string $code): void
    {
        /*
         * The driven fail-open (R31-C2, security): both payloads are
         * lint-clean (php -l verified this round) and answered 0
         * violations at HEAD — the keywords invisible to the owner's
         * lowercase arm (red at HEAD: 0 violations). The scoped
         * (?i:…) groups widen the keyword tokens; the escape flags
         * naming its statement exactly like the lowercase twin.
         */
        $lowercase_twin = str_replace($spelling, strtolower($spelling), $code);

        file_put_contents($this->root . '/fixture.php', $code);
        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, sprintf('A %s escape is a live include — PHP lexes the keyword case-insensitively (red at HEAD: 0 violations).', $spelling));
        $this->assertStringContainsString($spelling . ' dirname(__DIR__, 2)', $report, 'The violation names the case-variant include.');

        $twin_root = $this->root . '-twin';
        mkdir($twin_root, 0755, true);
        file_put_contents($twin_root . '/fixture.php', $lowercase_twin);
        $twin_violations = wp_connectors_self_containment_violations($twin_root);

        $this->assertSame(
            str_replace(array($spelling, basename($this->root)), array(strtolower($spelling), basename($twin_root)), $report),
            implode("\n", $twin_violations),
            'The case variant and its lowercase twin answer byte-identical reports modulo the keyword spelling and the fixture roots the slug names — the widening touched the keyword tokens, never the derivations.'
        );
        foreach ((glob($twin_root . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($twin_root);
    }

    /**
     * Drives one case-variant laundering shape beside its lowercase twin.
     *
     * @param string $spelling The case-variant keyword the shape rides.
     * @param string $code     The case-variant payload.
     * @param string $needle   The violation fragment the twin is known to answer.
     */
    private function assertCaseVariantLaunderingFlagsLikeItsTwin(string $spelling, string $code, string $needle): void
    {
        file_put_contents($this->root . '/fixture.php', $code);
        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, sprintf('A %s-shaped carrier is live PHP — the loop-proof machinery must not spell the keyword case-sensitively (red at HEAD: 0 violations).', $spelling));
        $this->assertStringContainsString($needle, $report, 'The violation names the laundering the case-variant carrier performed.');

        $twin_root = $this->root . '-twin';
        mkdir($twin_root, 0755, true);
        file_put_contents($twin_root . '/fixture.php', strtolower($spelling) === $spelling ? $code : str_replace($spelling, strtolower($spelling), $code));
        $twin_violations = wp_connectors_self_containment_violations($twin_root);

        $this->assertSame(
            str_replace(basename($this->root), basename($twin_root), $report),
            implode("\n", $twin_violations),
            'The case variant and its all-lowercase twin answer byte-identical reports modulo the fixture root the slug names — the fold touched the keyword tokens, never the proof machinery.'
        );
        foreach ((glob($twin_root . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($twin_root);
    }

    public function testACaseVariantForeachHeaderBindingIsCollected(): void
    {
        /*
         * R32-2 (security:medium, driven fail-open): the
         * foreach-header collector spelled 'foreach' byte-exact
         * lowercase, so a lint-clean 'FOREACH (... as $f)' loop's
         * value binding was never collected and the loop-shaped
         * include laundered through the benign same-file write.
         */
        $this->assertCaseVariantLaunderingFlagsLikeItsTwin(
            'FOREACH',
            '<?php $f = __DIR__ . "/safe.php"; FOREACH ($evil as $f) { require $f; }',
            'variable $f depends on $evil with no resolvable same-file assignment'
        );
    }

    public function testACaseVariantLoopCarrierOpensTheWriteVisibilitySpan(): void
    {
        /*
         * R32-3 (security:medium, driven fail-open): the
         * write-visibility span pattern spelled while/for/foreach/do
         * byte-exact lowercase, so an uppercase carrier never opened a
         * span and the post-include foreign write read 'not visible in
         * any span' — the map-literal proof stood on the benign
         * literal alone.
         */
        $this->assertCaseVariantLaunderingFlagsLikeItsTwin(
            'FOREACH',
            '<?php $map = array(__DIR__ . "/safe.php"); FOREACH ($rows as $r) { foreach ($map as $f) { require $f; } $map = array(__DIR__ . "/../../outside.php"); }',
            'resolves outside the plugin dir'
        );
    }

    public function testACaseVariantAsByReferenceBindingRefusesTheProof(): void
    {
        /*
         * R32-4 (security:medium, driven fail-open): the by-ref
         * refusal arms spelled 'as' byte-exact lowercase, so a
         * lint-clean 'AS &$map' value binding re-bound the map per
         * iteration and the by-ref laundering refusal never fired —
         * the glm18-18/glm36-8 channel reopened on the case axis.
         */
        $this->assertCaseVariantLaunderingFlagsLikeItsTwin(
            'AS',
            '<?php $map = array(__DIR__ . "/safe.php"); foreach ($rows AS &$map) {} foreach ($map as $f) { require $f; }',
            'variable $f has no resolvable same-file assignment'
        );
    }

    public function testALiteralDirAnchorDoesNotAnchor(): void
    {
        /*
         * R33-2 (security:medium, driven fail-open — round 32's
         * stripos consulted the raw statement bytes, where quoted
         * literals are intact): the magic constant's TEXT inside
         * a quoted literal counted as an anchor for every casing,
         * so 'require "__dir__/sub/x.php";' — at runtime a
         * literal directory name resolved against
         * include_path/cwd, never the plugin dir — laundered the
         * unanchored flag (the exact-case '__DIR__' spelling rode
         * the same pre-existing heuristic; the fold widened it).
         * The anchor consults judge the LITERAL-BLANKED view now:
         * string data never anchors anything, and the code-token
         * anchors stay clean in every casing beside them.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php require "__dir__/sub/x.php";'
        );
        $this->assertNotEmpty(wp_connectors_self_containment_violations($this->root), 'A quoted literal is a directory NAME at runtime, never an anchor — the unanchored flag fires (red at HEAD: laundered clean).');

        $root2 = $this->root . '-canonical';
        mkdir($root2, 0755, true);
        file_put_contents(
            $root2 . '/fixture.php',
            '<?php require "__DIR__/sub/x.php";'
        );
        $this->assertNotEmpty(wp_connectors_self_containment_violations($root2), 'The exact-case literal spelling flags identically — the pre-existing heuristic hole the fold widened, closed with its root.');
        foreach ((glob($root2 . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($root2);

        $root3 = $this->root . '-code';
        mkdir($root3, 0755, true);
        file_put_contents(
            $root3 . '/fixture.php',
            '<?PHP REQUIRE __dir__ . "/sub/x.php";'
        );
        $this->assertSame(array(), wp_connectors_self_containment_violations($root3), 'The code-token anchor stays clean in every casing — the blanked view keeps what the runtime keeps.');
        foreach ((glob($root3 . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($root3);
    }

    public function testACaseVariantDoWhileTailWriteStaysVisible(): void
    {
        /*
         * R33-1 (security:medium, driven fail-open — round 32's
         * fold incomplete at its own target seat): the span
         * dispatch classified the matched keyword by its last byte
         * case-sensitively, so the uppercase DO misrouted into
         * the while/for/foreach paren walk and its phantom bounds
         * excluded the trailing WHILE-condition write — the
         * glm18-17 tail-laundering channel reopened on the case
         * axis. The dispatch reads the folded tail byte now: the
         * braceless-do EOF approximation owns the whole
         * statement, tail included, exactly like the lowercase
         * twin.
         */
        $this->assertCaseVariantLaunderingFlagsLikeItsTwin(
            'DO',
            '<?php $f = __DIR__ . "/safe.php"; DO if (true) { require $f; } WHILE ($f = "/etc/passwd");',
            'resolves to a path that is not anchored'
        );
    }

    public function testACaseVariantInterfaceFunctionDeclarationScansClean(): void
    {
        /*
         * R33-1's fail-closed direction: a misrouted FUNCTION
         * match planted a phantom visibility span — an
         * interface's 'FUNCTION nb();' false-flagged where its
         * 'function' twin scans clean (a bodyless declaration
         * bounds nothing). Both spellings bound nothing now.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php interface I { FUNCTION nb(); } $map = array(__DIR__ . "/safe.php"); foreach ($map as $f) { require $f; } $map[] = 1;'
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root), 'A case-variant bodyless interface declaration bounds nothing — clean, exactly like its function twin (red at HEAD: the phantom no-resolvable-assignment flag).');

        $twin_root = $this->root . '-twin';
        mkdir($twin_root, 0755, true);
        file_put_contents(
            $twin_root . '/fixture.php',
            '<?php interface I { function nb(); } $map = array(__DIR__ . "/safe.php"); foreach ($map as $f) { require $f; } $map[] = 1;'
        );
        $this->assertSame(array(), wp_connectors_self_containment_violations($twin_root), 'The function twin stays clean beside it.');
        foreach ((glob($twin_root . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($twin_root);
    }

    public function testACaseVariantDirAnchorScansClean(): void
    {
        /*
         * R32-8 (driven false-anchored flag — R31-C2's parity at
         * the consults the widened arm feeds): PHP folds the
         * __DIR__ magic constant case-insensitively (verified on
         * this engine: '__dir__' resolves), so the lint-clean
         * downward '<?PHP REQUIRE __dir__ . "/sub/x.php";' is
         * anchored at runtime — but the anchor consults once
         * spelled the token byte-exact, flagging it 'not anchored'
         * where its '__DIR__' twin scanned clean. Every consult
         * folds now (stripos, the dirname regexes' /i, the segment
         * walk's strcasecmp); ABSPATH stays byte-exact by design
         * (a define()'d constant is case-sensitive — 'abspath'
         * names nothing a runtime resolves, and the unanchored
         * flag over it is the correct verdict).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?PHP REQUIRE __dir__ . "/sub/x.php";'
        );

        $this->assertSame(array(), wp_connectors_self_containment_violations($this->root), 'A case-variant __dir__ anchor is a legal downward anchored include — clean, exactly like its __DIR__ twin (red at HEAD: the false not-anchored flag).');

        $twin_root = $this->root . '-twin';
        mkdir($twin_root, 0755, true);
        file_put_contents(
            $twin_root . '/fixture.php',
            '<?PHP REQUIRE __DIR__ . "/sub/x.php";'
        );
        $this->assertSame(array(), wp_connectors_self_containment_violations($twin_root), 'The __DIR__ twin stays clean beside it.');
        foreach ((glob($twin_root . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($twin_root);
    }

    public function testACaseVariantDirnameAnswersItsTwinSReason(): void
    {
        /*
         * R32-8's reason-parity half: 'require DirName(__DIR__, 2)'
         * once misattributed its reason ('combines the anchor with
         * unresolvable runtime segments') where the lowercase twin
         * answered the anchor-family verdict — the widened keyword
         * arm had routed a legal case variant into consults that
         * disagreed with their own twins. The dirname consults fold
         * now; the twins answer byte-identical reports.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php require DirName(__DIR__, 2) . "/outside.php";'
        );
        $report = implode("\n", wp_connectors_self_containment_violations($this->root));
        $this->assertNotEmpty($report, 'The upward escape flags.');

        $twin_root = $this->root . '-twin';
        mkdir($twin_root, 0755, true);
        file_put_contents(
            $twin_root . '/fixture.php',
            '<?php require dirname(__DIR__, 2) . "/outside.php";'
        );
        $this->assertSame(
            str_replace(array(basename($this->root), 'DirName'), array(basename($twin_root), 'dirname'), $report),
            implode("\n", wp_connectors_self_containment_violations($twin_root)),
            'DirName and dirname answer byte-identical reports modulo the fixture root and the keyword spelling the statement text carries — the consults fold, never disagree with their own twins.'
        );
        foreach ((glob($twin_root . '/*') ?: array()) as $entry) {
            @unlink($entry);
        }
        @rmdir($twin_root);
    }
}
