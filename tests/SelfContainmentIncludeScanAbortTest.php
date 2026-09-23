<?php
/**
 * Self-containment include-scan abort fixtures (the R30-C1 class:
 * t31-glm30-1's include owner).
 *
 * Round 29 gave the include owner the ';|?>' terminator alternation
 * with a LAZY body — correct at the semantics (the match ends at
 * whichever terminator comes first) but burning a per-byte step
 * across every terminator-free span: quadratic, and past
 * pcre.backtrack_limit on a ~490KB span preg_match_all() returned
 * FALSE, which the seat's truthiness consumed as "no includes" —
 * every include in the file silently INVISIBLE (driven: the
 * laundering payload — lint-clean alone, php -l verified — flags
 * alone, while preceded by one benign ~700KB 'require $x .
 * "AAA…";' statement it answered 0
 * violations; inspect-artifact rides this seat over hostile
 * extracted trees with no size cap, and its php -l rejection runs
 * after the scan — glm36-8's abort-is-a-refusal doctrine at the one
 * seat that round never swept). The body is the possessive unrolled
 * loop now (linear, byte-identical match sets — pinned by the
 * round-29 file's terminator fixtures), and an abort an engine still
 * answers is the LOUD refusal naming the file, never a clean pass.
 * These fixtures pin the fail-open closed, the refusal loud, and the
 * megabyte-span scan fast and abort-free.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/check-conventions.php';

use PHPUnit\Framework\TestCase;

final class SelfContainmentIncludeScanAbortTest extends TestCase
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
        $this->root = sys_get_temp_dir() . '/wp-connectors-include-abort-' . uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        WpHarness::releaseScratch($this->root, ...$this->extra_roots);
    }

    public function testASizeLaunderingPadCannotHideTheIncludes(): void
    {
        /*
         * The driven fail-open (R30-C1, security): the laundering
         * payload flags on its own, but one ~700KB benign
         * 'require $x . "AAA…";' statement ahead of it exhausted the
         * lazy body's backtrack limit — preg_match_all() answered
         * FALSE, the seat's truthiness read "no includes", and the
         * whole file went invisible (red at HEAD: 0 violations). The
         * pad buys nothing now: the includes match through it, and
         * the report carries the seat's VIOLATIONS — never its
         * refusal, the scan having run rather than aborted.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $x = __DIR__ . "/a.php"; require $x . "' . str_repeat('A', 700000) . '";' . "\n"
            . "<?php \$map = array( __DIR__ . '/safe.php' );\nforeach (\$map as \$f) { require \$f ?><?php \$map = \$_GET[\"page\"] ?><?php }\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'The laundering include is visible through the pad — a size-triggered abort never reads as "no includes" (red at HEAD: 0 violations).');
        $this->assertStringContainsString('require $f', $report, 'The violation names the laundering include the pad sat ahead of.');
        $this->assertStringNotContainsString('could not be scanned for includes', $report, 'The pad alone never fires the refusal — the seat scanned the span, it did not abort on its size.');
    }

    public function testAnAbortOverTheIncludeScanRefusesLoudlyNamingTheFile(): void
    {
        /*
         * The refusal half (glm36-8, the pinned-limit idiom the
         * suite's abort pins ride — SecureFixturesTest's glm28-1):
         * at the floor limit 1 ANY match attempt aborts, the one
         * deterministic injection this engine offers, the limit
         * restored on every exit path. The refusal names the file
         * and carries the engine's diagnostic; a candidate-free
         * payload never starts a match attempt and keeps its clean
         * verdict under the same floor; the control at the restored
         * limit flags normally — the abort above was the pinned
         * limit, never the payload. The fixture keeps no '.' or 's'
         * bytes so the shared/-reference sibling (t31-glm31-1, its
         * own floor pin in its own file) never starts a match
         * attempt under this pin's floor — exactly one refusal here.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php require dirname(__DIR__, 2) ?>'
        );

        $host_limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $violations = wp_connectors_self_containment_violations($this->root);

            $this->assertCount(1, $violations, 'The aborting include scan answers exactly the one refusal line — never a clean pass over a file whose includes went unscanned.');
            $this->assertStringContainsString('fixture.php could not be scanned for includes', $violations[0], 'The refusal names the file whose scan aborted.');
            $this->assertStringContainsString('the self-containment scan aborted (PCRE:', $violations[0], 'The refusal rides the seat\'s own loud vocabulary with the engine\'s diagnostic.');
        } finally {
            ini_set('pcre.backtrack_limit', $host_limit);
        }

        $plain_root = $this->root . '-plain';
        mkdir($plain_root, 0755, true);
        $this->extra_roots[] = $plain_root;
        file_put_contents(
            $plain_root . '/plain.php',
            "<?php \$plain = 1;\n"
        );
        ini_set('pcre.backtrack_limit', '1');
        try {
            // A file no include candidate lives in never starts a
            // match attempt, so the floor limit never fires.
            $this->assertSame(array(), wp_connectors_self_containment_violations($plain_root), 'A candidate-free payload keeps its clean verdict under the pinned floor — the refusal is the abort, never the size.');
        } finally {
            ini_set('pcre.backtrack_limit', $host_limit);
        }

        // The control at the restored limit, on its own root (the
        // views cache keys the path it already answered): the same
        // include flags through the scan it just aborted on.
        $control = $this->root . '-control';
        mkdir($control, 0755, true);
        $this->extra_roots[] = $control;
        file_put_contents(
            $control . '/fixture.php',
            '<?php require dirname(__DIR__, 2) ?>'
        );
        $this->assertNotEmpty(wp_connectors_self_containment_violations($control), 'The control flags at the host default — the abort above was the pinned limit, never the payload.');
    }

    public function testTheGenuinelyLintCleanCompositeLaunderingPadFlags(): void
    {
        /*
         * The record correction's pin (t31-glm31-3, the r26-8
         * post-mortem class over round 30's own records): the
         * composite round 30 drove and pinned was NOT lint-clean —
         * the pad's '";' leaves the lexer in PHP mode at the
         * laundering half's second '<?php' (php -l: "unexpected
         * token <", line 2; verified this round against the exact
         * fixture spelling) — while the laundering payload alone IS
         * lint-clean, and the records' "lint-clean" adjective
         * attached to the composite was false. The GENUINELY
         * lint-clean composite closes the pad with '?>' (the
         * implied semicolon, glm29-2's own terminator class), so
         * the lexer re-enters HTML mode and the laundering half's
         * open tag is legal again — php -l verified — and the drive
         * it never got in round 30 rides here: the pad hides
         * nothing, the laundering include flags through it, and the
         * report carries violations, never the refusal.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $x = __DIR__ . "/a.php"; require $x . "' . str_repeat('A', 700000) . '" ?>' . "\n"
            . "<?php \$map = array( __DIR__ . '/safe.php' );\nforeach (\$map as \$f) { require \$f ?><?php \$map = \$_GET[\"page\"] ?><?php }\n"
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'The genuinely lint-clean composite laundering pad flags — the close-tag-terminated pad hides nothing.');
        $this->assertStringContainsString('require $f', $report, 'The violation names the laundering include behind the lint-clean pad.');
        $this->assertStringNotContainsString('could not be scanned for includes', $report, 'The lint-clean composite scans — never refused.');
    }

    public function testAnAbortOverTheForeachBindingCollectorRefusesTheProof(): void
    {
        /*
         * The driven fail-open (R32-5, security): the header
         * collector's truthiness read a size-triggered
         * preg_match_all FALSE as "no foreach bindings" — the
         * tempered lazy dot exhausting pcre.backtrack_limit — so
         * the '$evil as $f' binding went invisible beside the
         * benign same-file write and the loop-shaped include
         * laundered (driven at DEFAULT limits with a 2.5MB ';'
         * poison: 0 violations where the poison-free twin flags;
         * the in-suite fixture rides the PINNED-floor idiom — the
         * suite's deterministic abort injection — because the
         * 2.5MB spelling OOMs the 128M runner at the tokenizer).
         * The abort refuses the proof now: no binding is provable
         * over bytes the collector could not scan, the include
         * flagging through its no-resolvable-assignment reason —
         * the reason text that distinguishes the refused proof
         * ('has no resolvable same-file assignment') from the
         * normally-collected one ('depends on $evil with …'),
         * both pinned. THE POISON IS DELIBERATELY NOT LINT-CLEAN
         * (raw ';' bytes inside the header paren — php -l refuses
         * it): the seat's own threat model runs the scan BEFORE
         * any lint rejection, inspect-artifact riding it over
         * hostile extracted trees with no size cap — recorded,
         * per the round-31 doctrine, rather than laundered
         * around.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $f = __DIR__ . "/safe.php"; foreach (' . str_repeat(';', 8192) . ') { } foreach ($evil as $f) { require $f; }'
        );

        $host_limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '4096');
        try {
            $violations = wp_connectors_self_containment_violations($this->root);
        } finally {
            ini_set('pcre.backtrack_limit', $host_limit);
        }
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'A size-triggered abort over the foreach binding collector refuses the proof — never a silent clean pass over bindings that went unscanned (red at HEAD: 0 violations on the driven 2.5MB default-limit shape).');
        $this->assertStringContainsString('variable $f has no resolvable same-file assignment', $report, 'The refused proof\'s own reason — the collector aborting, no binding provable.');
        $this->assertStringNotContainsString('could not be scanned for includes', $report, 'The include scan itself survives this floor — the abort pinned is the collector\'s alone.');

        // The same fixture at the restored limit collects normally: the
        // binding visible, the flag naming what $f depends on.
        $control = $this->root . '-control';
        mkdir($control, 0755, true);
        $this->extra_roots[] = $control;
        file_put_contents(
            $control . '/fixture.php',
            '<?php $f = __DIR__ . "/safe.php"; foreach (' . str_repeat(';', 8192) . ') { } foreach ($evil as $f) { require $f; }'
        );
        $control_report = implode("\n", wp_connectors_self_containment_violations($control));
        $this->assertStringContainsString('variable $f depends on $evil with no resolvable same-file assignment', $control_report, 'At the restored limit the collector runs — the binding collected, the flag naming $evil (the normal-collection twin beside the refused-proof arm).');
    }

    public function testAWhitespaceRunHeaderScansFast(): void
    {
        /*
         * R33-3 (cost, driven): the foreach-header as-split once
         * burned a quadratic lazy-dot × greedy-\s+ search over
         * whitespace-run headers — 4.55s end-to-end on this
         * lint-clean 60,000-space header (php -l passes; 32.5s at
         * 160KB, ~7min at 1MB measured at pattern level), the
         * R32-1 hostile-extracted-tree stall class at a seat
         * round 32's census claimed swept. The separator is a
         * quantifier-free find on the masked slice now — linear by
         * construction; the wall bound below is a generous CLASS
         * guard in the round-30 cost pin's style (the valley paid
         * seconds at this size), the DISCRIMINATING pin being the
         * verdict: the binding collected, the include flagging
         * exactly like its tight twin. The key-value boundary
         * shapes ride beside it (a string key's contents blank in
         * the masked view, the real 'as' still found).
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $f = __DIR__ . "/safe.php"; foreach (' . str_repeat(' ', 60000) . '$evil as $f) { require $f; }'
        );

        $started = microtime(true);
        $violations = wp_connectors_self_containment_violations($this->root);
        $elapsed = microtime(true) - $started;

        $this->assertStringContainsString('variable $f depends on $evil with no resolvable same-file assignment', implode("\n", $violations), 'The binding through the whitespace-run header is collected — the include flags exactly like its tight twin.');
        /*
         * t31-glm35-5 (the review's R35-7): 3.0s -> 1.5s — the 3.0s
         * bound had narrowed the discrimination range to hosts no
         * faster than ~1.52x this one (mainstream hardware), the old
         * quadratic measuring 4.556s against the linear 0.032s here
         * with byte-identical violation text, so only the wall can
         * catch a pure cost regression. 1.5s guards hosts to ~3x
         * this speed while holding 47x headroom over the measured
         * actual — contention-decoupled AND discrimination-ranged.
         */
        $this->assertLessThan(1.5, $elapsed, sprintf('A whitespace-run header scans in wall-clock the linear pipeline owns (%.3fs here; red at HEAD: 4.55s; the reintroduced quadratic at 4.556s still reddens).', $elapsed));

        /*
         * The masked-view boundary the round-33 comment claimed and
         * the old boundary leg never drove (the review's R34-8 — the
         * '-boundary' fixture's string key lived in the assignment
         * line, contained no ' as ' bytes, and never touched the
         * header split): the shape the masked-slice split actually
         * changes is a header-EMBEDDED ' as ' string. The old
         * code-view split mis-split at the literal's ' as ' — the
         * binding lost, the include false-flagging 'no resolvable
         * same-file assignment' (driven at the pre-round-33
         * baseline); the masked split finds the real separator —
         * the binding collected, the element-resolution reason
         * answering instead (driven at HEAD: the reason text is the
         * discriminator, a raw-view-split regression flipping it
         * back to the lost-binding reason).
         */
        $boundary_root = $this->root . '-boundary';
        mkdir($boundary_root, 0755, true);
        $this->extra_roots[] = $boundary_root;
        file_put_contents(
            $boundary_root . '/fixture.php',
            '<?php $map = array(__DIR__ . "/safe.php"); foreach ($map["k as v"] as $f) { require $f; }'
        );
        $boundary_report = implode("\n", wp_connectors_self_containment_violations($boundary_root));
        $this->assertStringContainsString('resolves to a path', $boundary_report, 'The binding through the header-embedded \' as \' string is COLLECTED — the element-resolution reason answers, never the lost-binding reason a raw-view split would flip back to (red at the pre-round-33 baseline).');
        $this->assertStringNotContainsString('no resolvable same-file assignment', $boundary_report, 'The masked-slice separator found the real keyword past the string — the reason the old code-view split answered is gone.');
    }

    public function testAnEmptyForeachSourceIsNotABinding(): void
    {
        /*
         * R34-1 (security:medium, driven fail-open — round 33's
         * own regression at the seat it claims closed the
         * fail-open doctrine): the quantifier-free separator find
         * dropped the old anchored split's non-empty-source
         * requirement, so the parse-error header 'foreach ( as
         * $f)' matched at offset 0, collected the synthetic
         * '$f = ;' over an empty RHS, and PROVED a mixed-anchored
         * include clean. THE FIXTURE IS DELIBERATELY NOT
         * LINT-CLEAN (php -l refuses 'foreach ( as'): the seat's
         * own threat model scans hostile extracted trees before
         * any lint rejection — the R32-5 precedent, recorded per
         * the round-31 doctrine rather than laundered around.
         * The empty source is not a binding: the include flags
         * through its no-resolvable-assignment reason, exactly as
         * the pre-round-33 baseline answered.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php foreach ( as $f) { require __DIR__ . "/" . $f; }'
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'An empty foreach source is not a binding — the synthetic assignment over an empty RHS never proves an include clean (red at HEAD: 0 violations).');
        $this->assertStringContainsString('depends on $f with no resolvable same-file assignment', $report, 'The include flags through its no-resolvable-assignment reason — the empty source collected nothing, the mixed anchor did not resolve.');
    }

    public function testAStatementJunkForeachSourceIsNotABinding(): void
    {
        /*
         * R35-1 (security:medium, driven fail-open — round 34's
         * empty-source guard was a SYMPTOM patch): the trimmed-
         * to-nothing check only covered 'foreach ( as', so the
         * parse-error 'foreach (; as $f)' — the scanner's
         * established poison byte, the same pre-lint threat model —
         * minted '$f = ;;', the value extractor reduced it to '',
         * and the substitution deleted $f leaving a statement every
         * gate judged clean. The guard reads the MASKED source
         * side now: a ';' there is real code junk (string bytes
         * blanked — a whole-literal source masks to spaces and its
         * mint stays the recorded pre-existing shape), and no valid
         * header expression carries a statement terminator outside
         * strings. THE FIXTURE IS DELIBERATELY NOT LINT-CLEAN
         * (php -l refuses 'foreach (; as'), recorded per the
         * round-31 doctrine.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php foreach (; as $f) { require __DIR__ . "/" . $f; }'
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'Statement junk where an expression must be is not a binding — the synthetic mint over junk never proves an include clean (red at HEAD: 0 violations).');
        $this->assertStringContainsString('depends on $f with no resolvable same-file assignment', $report, 'The include flags through its no-resolvable-assignment reason — the junk source collected nothing.');
    }

    public function testAMegabyteNowdocQuoteByteCannotMisPairTheView(): void
    {
        /*
         * R36-1 (security:medium, driven fail-open — round 35's
         * megabyte degrade arm): the quote-grammar view MIS-PAIRS
         * when a quote byte rides inside a megabyte nowdoc body —
         * the mis-paired span blanking the real __DIR__ code token
         * from every statement-seat consult and consuming the
         * traversal literal's opening quote, the whole
         * anchor/escape analysis silently dropped (driven,
         * lint-clean: 0 violations where the one-quote-byte-short
         * control flags — the arm's documented ceiling covered
         * only body-text-reads-as-anchored, the fail-OPEN
         * direction undocumented; and the arm carried no
         * abort/length guard of its own, a PCRE abort collapsing
         * the blanked view to zero bytes silently consumed —
         * R36-3). THE ARM IS DELETED — the tokenizer view at
         * every size, its OOM motivation having been the 8MB pad
         * whose halving removed it (a synthetic fail-closed view
         * was tried and refuted in derivation: the seats DEFER to
         * the literal analysis on an anchor-less view, so the
         * sentinel silenced the deferring seats while the loop
         * seat stayed anchored — fail-open through the deferral
         * chain, driven 0 on the control itself). Both the
         * mis-paired and the escaping-literal shapes flag now.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            "<?php require <<<'EOT'\n" . str_repeat('A', 1100000) . "\"\nEOT . __DIR__ . \"/../outside.php\";"
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'A quote byte inside a megabyte nowdoc body cannot mis-pair the anchor/escape analysis away — the escaping literal flags (red at HEAD: 0 violations where the control flags).');
        $this->assertStringContainsString('not provably inside the plugin dir', implode("\n", $violations), 'The escape through the megabyte nowdoc flags through the tokenizer view at every size.');
    }

    public function testATerminatorJunkAssignmentProvesNothing(): void
    {
        /*
         * R36-2 (security:medium, driven fail-open — round 35's
         * junk guard was ONE SPELLING of a class): every
         * terminator byte in the value extractor's trim class
         * reduces a minted assignment to nothing — '$f = ;' at the
         * plain collector, '?' or ')' on a foreach source, junk on
         * the value side — and the layered trims erased the junk
         * from the substituted statement until nothing remained to
         * flag (driven: four spellings, all 0 violations at HEAD
         * where the parse-valid twins flag, ALL pre-existing at
         * the baseline — round 35's records claiming the class
         * root-fixed, the class-level correction at the docs). THE
         * GUARD at the extractor seam — one class, both collector
         * seats, every spelling: an assignment whose extracted
         * value reduces to nothing under the terminator trim is no
         * assignment. THE FIXTURES ARE DELIBERATELY NOT LINT-CLEAN
         * (php -l refuses each junk spelling), the pre-lint
         * hostile-tree threat model the standing precedent.
         */
        $shapes = array(
            'plain mint' => '<?php $f = ; require __DIR__ . "/" . $f;',
            'question source' => '<?php foreach (? as $f) { require __DIR__ . "/" . $f; }',
            'paren source' => '<?php foreach () as $f) { require __DIR__ . "/" . $f; }',
            'value-side junk' => '<?php foreach ($map as $f = ;) { require __DIR__ . "/" . $f; }',
        );

        foreach ($shapes as $name => $code) {
            $root = $this->root . '-' . md5($name);
            mkdir($root, 0755, true);
            $this->extra_roots[] = $root;
            file_put_contents($root . '/fixture.php', $code);

            $violations = wp_connectors_self_containment_violations($root);

            $this->assertNotEmpty($violations, sprintf('The %s junk shape proves nothing — the include flags, never laundering through trims that erase the junk (red at HEAD: 0 violations).', $name));
            $this->assertStringContainsString('no resolvable same-file assignment', implode("\n", $violations), sprintf('The %s shape flags through the no-resolvable-assignment reason.', $name));
        }

        // The parse-valid control keeps its own reason.
        $control = $this->root . '-control';
        mkdir($control, 0755, true);
        $this->extra_roots[] = $control;
        file_put_contents($control . '/fixture.php', '<?php $f = $unknown; require __DIR__ . "/" . $f;');
        $this->assertStringContainsString('depends on $f built from unresolvable runtime segments', implode("\n", wp_connectors_self_containment_violations($control)), 'The parse-valid twin keeps its own runtime-segment reason — the guard refuses only the reduces-to-nothing spellings.');
    }

    public function testAnUnclosableLoopHeaderBoundsToEof(): void
    {
        /*
         * R37-4 (security:medium, driven fail-open — the ONE seat
         * that UNDER-bounded): an unclosable while/for/foreach
         * header once 'continued' with NO span, so a post-include
         * write inside the unbounded loop read 'not visible in any
         * span' and the include proved clean on the pre-include
         * assignment alone — contradicting the function's own
         * docblock ('a wider region can only refuse more proofs,
         * never launder one'). The arm over-approximates to EOF
         * now, the sibling arms' policy. THE FIXTURE IS
         * DELIBERATELY NOT LINT-CLEAN (php -l refuses the unclosed
         * paren), the pre-lint hostile-tree threat model the
         * standing precedent.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $f = __DIR__ . "/safe.php"; foreach ($evil as $f ( { require $f; $f = "/etc/passwd";'
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'An unclosable loop header bounds everything after it — the post-include write stays visible, the include never proving clean on the pre-include assignment alone (red at HEAD: 0 violations).');
        $this->assertStringContainsString('variable $f resolves to a path', $report, 'The trailing write target joins the proof — the EOF over-approximation refusing, never laundering.');
    }

    public function testAByReferenceClosureCaptureMakesEveryWriteVisible(): void
    {
        /*
         * R38-3 (security:medium, driven fail-open — the
         * deferred-execution channel): a closure capturing the proof
         * variable BY REFERENCE may be invoked after any write in
         * the file — the runtime reads the variable's LAST value,
         * wherever written. The function-arm span once covered only
         * the closure body, so the post-definition write was
         * invisible and the php -l CLEAN shape '$f = ...inside...;
         * $go = function () use (&$f) { require $f; }; $f =
         * .../../../outside.php; $go();' answered 0 violations while
         * executing it requires the OUTSIDE path. The by-ref capture
         * extends the span to the whole file; the BY-VALUE twin
         * (arrow-function semantics — the capture snapshots the
         * value) keeps its clean verdict.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $f = __DIR__ . "/inside.php"; $go = function () use (&$f) { require $f; }; $f = __DIR__ . "/../../outside.php"; $go();'
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'A by-ref closure capture may be invoked after any write — the whole file is visible to the proof (red at HEAD: 0 violations while execution requires the outside path).');
        $this->assertStringContainsString('variable $f resolves to a path', $report, 'The post-definition write joins the proof — the deferred invocation reading the LAST value.');

        $value_root = $this->root . '-byvalue';
        mkdir($value_root, 0755, true);
        $this->extra_roots[] = $value_root;
        file_put_contents(
            $value_root . '/fixture.php',
            '<?php $f = __DIR__ . "/inside.php"; $go = function () use ($f) { require $f; }; $f = __DIR__ . "/outside.php"; $go();'
        );
        $this->assertSame(array(), wp_connectors_self_containment_violations($value_root), 'The by-value twin captures the snapshot — the later write never reaches the closure, the verdict stays clean.');
    }

    public function testAnUnclosableFunctionHeaderBoundsToEof(): void
    {
        /*
         * R38-5 (security:medium, driven — the R37-4 class one arm
         * over): the function arm's forward scan once continued with
         * NO span when no '{' followed — EOF before any body opener,
         * or the include sitting inside an unclosed header ahead of
         * the ';' the bodyless break reads — the post-include write
         * invisible, the include proving clean on the pre-include
         * assignment. Both spellings over-approximate to EOF now;
         * the true bodyless declarations (interface/abstract, the
         * include never inside their headers) keep bounding nothing.
         * THE FIXTURE IS DELIBERATELY NOT LINT-CLEAN (php -l refuses
         * the unclosed header), the pre-lint hostile-tree threat
         * model the standing precedent.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $f = __DIR__ . "/safe.php"; function evil ( require $f; $f = __DIR__ . "/../../outside.php";'
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'An unclosable function header bounds everything after it — the include inside the header never proves clean on the pre-include assignment alone (red at HEAD: 0 violations).');
        $this->assertStringContainsString('variable $f resolves to a path', $report, 'The trailing write target joins the proof — the EOF over-approximation refusing, never laundering.');
    }

    public function testAnUnterminatedWriteAtEofIsStillCollected(): void
    {
        /*
         * R39-2 (security:medium, driven fail-open): both assignment
         * collectors' terminator alternation had no END-OF-INPUT
         * arm, so a write terminated by neither ';' nor '?>' at the
         * end of the file was invisible even though the span walk
         * over-approximates the unclosed '{' to EOF (R38-5) — the
         * unterminated while and map shapes answering 0 violations
         * where their terminated twins flag, the pre-include benign
         * assignment alone proving the include clean. The
         * alternation admits the end of input: an unterminated
         * write is still a write. THE FIXTURES ARE DELIBERATELY
         * NOT LINT-CLEAN (php -l refuses each unterminated shape),
         * the pre-lint hostile-tree threat model the standing
         * precedent.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php $f = __DIR__ . "/inside.php"; while (true) { require $f; $f = "/etc/passwd"'
        );

        $violations = wp_connectors_self_containment_violations($this->root);
        $report = implode("\n", $violations);

        $this->assertNotEmpty($violations, 'An unterminated write at EOF is still collected — the benign pre-include assignment never proves the include alone (red at HEAD: 0 violations).');
        $this->assertStringContainsString('variable $f resolves to a path', $report, 'The trailing write target joins the proof — collected to the last byte.');

        $map_root = $this->root . '-map';
        mkdir($map_root, 0755, true);
        $this->extra_roots[] = $map_root;
        file_put_contents(
            $map_root . '/fixture.php',
            '<?php $map = array(__DIR__ . "/safe.php"); foreach ($map as $f) { require $f; $map = array("/etc/passwd")'
        );
        $map_violations = wp_connectors_self_containment_violations($map_root);
        $this->assertNotEmpty($map_violations, 'The map twin likewise — the unterminated map write collected beside the benign literal.');
    }

    public function testAnUnterminatedIncludeAtEofIsStillCollected(): void
    {
        /*
         * R40-1 (security:medium, driven fail-open — R39-2's EOF arm
         * swept the assignment seats and missed the include scan
         * itself): the include collector's terminator alternation had
         * no END-OF-INPUT arm, so an include terminated by neither
         * ';' nor '?>' at the end of the file was invisible to every
         * self-containment gate. THE FIXTURE IS DELIBERATELY NOT
         * LINT-CLEAN (php -l refuses the unterminated statement), the
         * pre-lint hostile-tree threat model the standing precedent.
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php require __DIR__ . "/../../outside.php"'
        );

        $violations = wp_connectors_self_containment_violations($this->root);

        $this->assertNotEmpty($violations, 'An unterminated include at EOF is still an include — collected, never invisible (red at HEAD: 0 violations where the terminated twin flags).');
        $this->assertStringContainsString('require __DIR__ . "/../../outside.php"', implode("\n", $violations), 'The violation names the unterminated include exactly.');
    }

    public function testTheScanOfATerminatorFreeMegabytePadStaysFast(): void
    {
        /*
         * The cost half, honestly measured on the dev host: at the
         * pattern level over terminator-free bytes the lazy body
         * answered 18.5ms at 490KB where the possessive unrolled
         * loop answers 1.25ms and master's auto-possessified greedy
         * spelling 0.022ms — the respelling's win is the constant
         * factor and the abort it removes, and at function level the
         * megabyte span this fixture rides scans all-in in ~1.05s
         * either way (the tokenizer and masker own that wall).
         * t31-glm35-6: the pad is 4MB now (was 8MB) — still 8x past
         * the old lazy body's ~490KB abort threshold, the
         * discriminating shape unchanged, while the DRIVER's own
         * file-level tokenize of the fixture sat knife-edge at the
         * 128M runner under random-order ambient memory (the
         * ledgered glm28-16 class, this round's added tests tipping
         * it — driven: order-dependent 8,000,040-byte
         * token_get_all fatals through file_code_views, the
         * statement seats' own fatals closed one commit up). So the wall
         * bound below is a generous CLASS guard in the spawn-bound
         * tests' style, never a millisecond discriminator — the
         * DISCRIMINATING pin on this fixture is the report's shape:
         * at HEAD the seat ABORTED on the 8MB span (every include
         * invisible, zero violations); now the span is scanned with
         * no refusal. (The file's own verdict rides other seats'
         * out-of-scope behavior — round 30's residuals carry the
         * literal grammar's fail-closed flag on megabyte literals —
         * so this test pins the seat's scannability and cost class,
         * never that file's verdict.)
         */
        file_put_contents(
            $this->root . '/fixture.php',
            '<?php require __DIR__ . "/inc.php' . str_repeat('A', 4000000) . '";' . "\n"
        );

        $started = microtime(true);
        $report = implode("\n", wp_connectors_self_containment_violations($this->root));
        $elapsed = microtime(true) - $started;

        $this->assertStringNotContainsString('could not be scanned for includes', $report, 'A megabyte terminator-free span is scanned, never refused — the respelling left no limit to exhaust at this size (red at HEAD: the seat aborted and every include went invisible).');
        $this->assertLessThan(3.0, $elapsed, sprintf('The megabyte pad scans in wall-clock the linear pipeline owns (%.2fs here) — the bound guards the quadratic class at pad scale, not millisecond discrimination.', $elapsed));
    }
}
