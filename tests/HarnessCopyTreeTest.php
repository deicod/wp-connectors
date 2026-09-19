<?php
/**
 * WpHarness::copyTree() pins (the ONE scratch-tree copy owner,
 * t31-ocr1-9 — its own regressions live beside its own subject).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HarnessCopyTreeTest extends TestCase
{
    /*
     * The symlink-capability probe rides the ONE shared owner
     * (t31-ocr11-9): WpHarness::canSymlink(), hoisted from the
     * WpConnectorsTestCase wrapper this test does not extend — the
     * private twin here was a verbatim copy of the body the rounds
     * kept having to fix twice (the random suffix worn on it
     * t31-ocr10-18, the function_exists guard arriving only with the
     * hoist).
     */
    /**
     * OCR-round-4 pin (t31-ocr4-2): the relative path was computed by
     * str_replace($from . '/', '', …), which strips EVERY occurrence —
     * a source tree containing the source dir's own name as a NESTED
     * segment silently copied to the wrong target: both occurrences
     * eaten, the nested file landing at the collapsed GLUED path
     * ('vendornested.php' — the two stripped halves fused). The
     * reproducer replays the ENTIRE source path as literal nested
     * directory names under vendor/ (a relative source root, or any
     * checkout whose full path repeats inside itself, is the same
     * shape); the prefix strip is positional now — position 0, exactly
     * once. (The negative leg originally asserted 'vendor/nested.php'
     * — a path that existed under NEITHER behavior, an inert pin
     * swapped for the real glue shape in OCR round 6, t31-ocr6-10.)
     */
    public function testANestedSameNameSegmentCopiesToItsExactTarget(): void
    {
        /*
         * The platform gate (OCR round 28, t31-ocr28-8 — the
         * t31-ocr22-2 split's battery had it; these legs rode
         * ungated): the leg's whole subject is the '/'-joined prefix
         * strip (the ocr6-4 positional splice over '$from . '/''), and
         * on a host whose platform separator is not the POSIX one the
         * iterator's pathnames join through the native separator —
         * they never meet the '/'-joined prefix, and the leg would
         * judge a different seam than the one it pins.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The nested same-name leg rides the POSIX separator join of the prefix strip — this host\'s platform separator is not the POSIX one, and the iterator pathnames would never meet the prefix the leg pins.');
        }

        $holder = sys_get_temp_dir() . '/wpct-copytree-src-' . uniqid('', true);
        $from = $holder . '/example-connector';
        $to = sys_get_temp_dir() . '/wpct-copytree-dst-' . uniqid('', true);
        $nested = $from . '/vendor' . $from;
        mkdir($nested, 0755, true);
        $this->stage($from . '/plain.php', 'plain bytes');
        $this->stage($nested . '/nested.php', 'nested bytes');

        try {
            WpHarness::copyTree($from, $to);

            $this->assertFileExists($to . '/plain.php');
            $this->assertSame('nested bytes', (string) file_get_contents($to . '/vendor' . $from . '/nested.php'), 'The nested same-name path keeps its exact position — only the SOURCE prefix strips, never a nested repetition.');
            $this->assertFileDoesNotExist($to . '/vendornested.php', 'The pre-fix str_replace() glue target (every occurrence stripped, the halves fused) must not appear — this leg is the regression detector for a return to str_replace().');
        } finally {
            WpHarness::releaseScratch($holder, $to);
        }
    }

    /**
     * OCR-round-6 pin (t31-ocr6-4): the prefix strip's no-match arm
     * kept the FULL absolute path as the relative tail — reachable via
     * a trailing-slash $from, whose iterator pathnames never start with
     * the doubled slash of $from.'/', so every file silently landed
     * nested under the target with no error. The no-match arm refuses
     * loudly now, naming the path and the expected prefix.
     */
    public function testATrailingSlashSourceRefusesInsteadOfSilentlyNesting(): void
    {
        /*
         * The platform gate (OCR round 28, t31-ocr28-8): the leg's
         * premise is that a trailing '/' IS the separator spelling of
         * the source's last component — POSIX. On a host whose platform
         * separator is not '/', the trailing slash is a byte the native
         * spelling never carries, the doubled-prefix relativize refusal
         * the leg pins never fires for it, and the leg would pass or
         * fail through a seam it does not name.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The trailing-slash leg premises the POSIX separator — a trailing \'/\' names the source\'s last-component separator only where \'/\' is the platform separator.');
        }

        $from = sys_get_temp_dir() . '/wpct-copytree-slash-' . uniqid('', true);
        $to = sys_get_temp_dir() . '/wpct-copytree-slash-dst-' . uniqid('', true);
        mkdir($from . '/src', 0755, true);
        $this->stage($from . '/src/file.php', 'bytes');

        try {
            // The verdict rides the ONE refusal owner (t31-ocr15-7) —
            // the family this site's original catch declared rides the
            // third parameter.
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($from . '/', $to),
                'A trailing-slash source must refuse the copy loudly, never nest every file under the target.',
                RuntimeException::class
            );
            $this->assertStringContainsString($from . '/src/file.php', $caught->getMessage(), 'The refusal names the path it could not relativize.');
            $this->assertStringContainsString($from . '//', $caught->getMessage(), 'The refusal names the prefix it expected.');
            $this->assertFileDoesNotExist($to, 'Nothing landed under the target.');
        } finally {
            WpHarness::releaseScratch($from, $to);
        }
    }

    /**
     * OCR-round-30 pin (t31-ocr30-4): a mid-landing IO failure answers
     * the POLICY refusal, never the engine's vocabulary. The landing
     * loop's mkdir()/copy() returns were unchecked, so a mid-landing
     * failure (EACCES, ENOSPC, path-length) escaped the contract two
     * ways: under PHPUnit (failOnWarning + convertWarningsToExceptions)
     * the raw E_WARNING became an exception wearing PHPUnit's
     * vocabulary, and outside PHPUnit the raw warning rode while
     * copyTree() RETURNED NORMALLY having moved nothing. Both returns
     * are owned now — the @ suppresses only the diagnostic (the
     * builder's copyNormalized shape), the failed return refuses
     * loudly naming the operation and the path.
     */
    public function testAMidLandingIoFailureAnswersThePolicyRefusalNeverTheEngineVocabulary(): void
    {
        /*
         * The platform gate (the t31-ocr28-8 doctrine, this file's
         * sibling legs): the landing loop's prefix is '/'-joined and
         * the permission bits premise POSIX semantics.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The mid-landing IO legs premise the POSIX separator join and POSIX permission bits — this host\'s platform separator is not the POSIX one.');
        }

        $holder = sys_get_temp_dir() . '/wpct-copytree-io-' . uniqid('', true);
        $from_nested = $holder . '/src-nested';
        $from_flat = $holder . '/src-flat';
        $locked_to = $holder . '/locked-dst';
        $readonly_to = $holder . '/readonly-dst';

        try {
            /*
             * Each leg's source carries exactly ONE file, shaped for
             * the arm it drives: the nested tree cannot land without
             * the recursive mkdir (the directory arm), the flat tree
             * needs no mkdir at all (the copy arm) — the iteration
             * order of a mixed tree would decide which arm a leg
             * drives, and the legs pin one arm each.
             */
            mkdir($from_nested . '/sub', 0755, true);
            $this->stage($from_nested . '/sub/file.php', 'nested bytes');
            mkdir($from_flat, 0755, true);
            $this->stage($from_flat . '/plain.php', 'plain bytes');
            mkdir($locked_to, 0755, true);
            mkdir($readonly_to, 0755, true);
            $this->stage($readonly_to . '/plain.php', 'stale bytes');

            /*
             * The permission-denial probe (the capability these legs
             * premise, in the canSymlink shape — probed, never
             * assumed): a process the permission bits cannot deny
             * (root walks 0555 and 0444 alike open) can never drive
             * the refusals, and legs that cannot go red are vacuous
             * greens — skip, naming the premise. The probe restores
             * its own permissions so the finally's cleanup owns it.
             */
            $probe = $holder . '/perm-probe';
            mkdir($probe . '/inner', 0755, true);
            chmod($probe . '/inner', 0555);
            $denied = ! @mkdir($probe . '/inner/child');
            chmod($probe . '/inner', 0755);
            if (! $denied) {
                $this->markTestSkipped('This process writes through 0555/0444 permission bits (root-shaped), so the mid-landing IO legs can never drive their refusals: the landings would succeed and the legs would pin nothing.');
            }

            /*
             * The mkdir leg: the nested source file's landing must
             * CREATE '$locked_to/sub' under a read-only parent — the
             * recursive mkdir fails, and the refusal names the
             * operation and the path (red at HEAD: the raw E_WARNING
             * in PHPUnit's vocabulary, never the policy's own).
             */
            chmod($locked_to, 0555);
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($from_nested, $locked_to),
                'A landing whose directory cannot be created must refuse the copy loudly, never wear the engine\'s vocabulary.',
                RuntimeException::class
            );
            $this->assertStringContainsString('cannot be created', $caught->getMessage(), 'The refusal names the mkdir operation that failed.');
            $this->assertStringContainsString($locked_to . '/sub', $caught->getMessage(), 'The refusal names the path the mkdir owned.');

            /*
             * The copy leg: a read-only FILE already squatting the
             * landing path makes copy() itself fail with every
             * directory writable — the copy return is owned too, its
             * refusal naming the copy and both paths.
             */
            chmod($readonly_to . '/plain.php', 0444);
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($from_flat, $readonly_to),
                'A landing whose file cannot be copied must refuse the copy loudly, never return normally over a partial tree.',
                RuntimeException::class
            );
            $this->assertStringContainsString('cannot be copied', $caught->getMessage(), 'The refusal names the copy operation that failed.');
            $this->assertStringContainsString($readonly_to . '/plain.php', $caught->getMessage(), 'The refusal names the path the copy owned.');

            /*
             * The happy-path control: the identical source into a
             * writable target lands every byte — the owned returns
             * changed nothing about the green landing.
             */
            $writable_to = $holder . '/writable-dst';
            WpHarness::copyTree($from_nested, $writable_to);
            $this->assertSame('nested bytes', (string) file_get_contents($writable_to . '/sub/file.php'), 'The happy path lands the nested file — both owned returns stay green over a writable landing.');
            WpHarness::copyTree($from_flat, $writable_to . '/flat');
            $this->assertSame('plain bytes', (string) file_get_contents($writable_to . '/flat/plain.php'), 'The happy path lands the flat file.');
        } finally {
            // The permission shapes must relax BEFORE the removal owner
            // walks them (rrmdir cannot write through 0555/0444 bits).
            @chmod($locked_to, 0755);
            @chmod($readonly_to . '/plain.php', 0644);
            WpHarness::releaseScratch($holder);
        }
    }

    /**
     * OCR-round-7 pin (t31-ocr7-4; mechanism narrative corrected in
     * t31-ocr7-8 over the refutation lens's driven probes — the guard
     * stands, the first justification did not): preconditions and
     * self-containment. A missing or FILE source once reached the SPL
     * iterator constructor, whose UnexpectedValueException is another
     * library's vocabulary — the harness policy is the LOUD
     * RuntimeException naming the path. A target that IS the source is
     * a silent NO-OP success on this engine (probed: copy($f, $f)
     * returns false with the bytes intact, and pre-round
     * copyTree(src, src) returned normally having copied nothing), and
     * a target INSIDE the source writes the copy into the tree it is
     * reading (the SPL iterator does not re-enumerate the created
     * target — one self-polluting duplication, driven: 5,000 files
     * became exactly 10,000). All four shapes refuse before a single
     * byte moves, one containment check, and the source tree survives
     * the refusal intact.
     */
    public function testPreconditionAndSelfContainmentShapesRefuseBeforeIterating(): void
    {
        /*
         * The platform gate (OCR round 28, t31-ocr28-8): the control
         * legs' '..'-woven alias spellings and '/'-rooted chains ride
         * POSIX separator/root resolution (the t31-ocr11-2 doctrine
         * the root-anchored battery's own legs carry), and the
         * relativize refusals name the '/'-joined prefix. On a host
         * whose platform separator is not the POSIX one the shapes
         * resolve through a different root and the legs would misjudge
         * through no defect of the contract they pin — the same
         * premise the t31-ocr22-2 split gated its battery for.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The precondition control legs ride POSIX root and separator resolution (the t31-ocr11-2 doctrine) — this host\'s platform separator is not the POSIX one, and the shapes would judge a different root than the one they pin.');
        }

        $from = sys_get_temp_dir() . '/wpct-copytree-guard-' . uniqid('', true);
        mkdir($from . '/src', 0755, true);
        $this->stage($from . '/src/file.php', 'original bytes');
        $file_source = $from . '/plain.txt';
        $this->stage($file_source, 'a file, not a tree');

        try {
            // The verdict rides the ONE refusal owner (WpHarness::refusalOf(),
            // t31-ocr15-7): the old fail()-inside-try shape was swallowed by
            // the very catch meant for copyTree() — fail() throws
            // AssertionFailedError, which EXTENDS RuntimeException
            // (t31-ocr5-3) — and the family that original catch declared
            // rides the owner's third parameter (t31-ocr9-3).
            $refuses = function (string $f, string $t, string $naming) use ($from): void {
                $caught = WpHarness::refusalOf(
                    fn() => WpHarness::copyTree($f, $t),
                    $naming,
                    RuntimeException::class
                );
                $this->assertStringContainsString('WpHarness::copyTree() refuses', $caught->getMessage(), 'The policy exception, never the SPL iterator\'s vocabulary.');
                $this->assertStringContainsString($f, $caught->getMessage(), $naming);
            };

            // (a) A FILE source: not a tree, refuses naming the path.
            $refuses($file_source, $from . '/dst-file', 'A FILE source must refuse with the policy exception, never the SPL iterator surprise.');

            // (a) A MISSING source: same verdict path.
            $refuses($from . '/no-such-tree', $from . '/dst-missing', 'A MISSING source must refuse with the policy exception.');

            /*
             * (a-unreadable) An EXISTING but UNLISTABLE source (OCR
             * round 32, t31-ocr32-9): the gate checked kind but not
             * readability, so a mode-0000 directory passed is_dir()
             * and died in the SPL constructor's
             * UnexpectedValueException — another library's
             * vocabulary answering a harness refusal (red at HEAD:
             * the SPL exception reaches the family match, and the
             * 'WpHarness::copyTree() refuses' fragment redds). The
             * gate probes readability now (opendir, the exact
             * capability the iterator's own construction needs) and
             * answers the policy refusal naming the path. Gated to
             * hosts where the shape applies (the t31-ocr4-1 root
             * doctrine: uid 0 opens chmod-0000 directories through
             * the DAC override, so the leg skips there).
             */
            $locked = $from . '/locked-src';
            mkdir($locked . '/inner', 0755, true);
            $this->stage($locked . '/inner/x.txt', 'bytes');
            chmod($locked, 0000);
            $locked_probe = @opendir($locked);
            if (false !== $locked_probe) {
                closedir($locked_probe);
                chmod($locked, 0755);
                /*
                 * The leg gates ITSELF, the battery continues (OCR
                 * round 38, t31-ocr38-5): this branch once fired
                 * markTestSkipped MID-TEST, and a skip aborts the
                 * ENTIRE remaining battery — so on the root-runner
                 * shape (the ocr4-1 uid-0 class runningAsRootRunner()
                 * exists to name, the primary CI runner) the
                 * self-copy, nested, mirror, alias, file-in-chain,
                 * dangling-link, and symlink legs plus the
                 * source-intact pins below NEVER RAN: copyTree
                 * containment coverage going dark exactly where CI
                 * rides. The sibling legs' own shape (canSymlink
                 * below) gates the leg instead, and the r35-8
                 * loudness doctrine survives WITHOUT the abort — the
                 * skip says so on STDERR (the release guard's own
                 * channel), never a silent gate, never a vacuous
                 * green wearing the leg's pass.
                 */
                /*
                 * The notice rides the ONE stream-resolving writer
                 * (t31-ocr39-6): this line was the suite's last bare
                 * STDERR-constant writer executing in the test process
                 * — the CLI-only constant this same update's ocr38-4
                 * doctrine removed from the release guard, one site
                 * over, inconsistent beside it.
                 */
                WpHarness::stderrNotice('unlistable-source leg skipped: this host opens chmod-0000 directories (uid 0 / DAC override — t31-ocr4-1), so the ocr32-9 refusal pin is unconstructible here; the battery\'s remaining legs continue (t31-ocr38-5)' . "\n");
            } else {
                $refuses($locked, $from . '/dst-locked', 'An EXISTING but unlistable source must refuse with the policy exception, never the SPL iterator\'s surprise.');
            }

            // (b) The self-copy: the target IS the source — a silent
            // no-op success pre-round (the engine's same-file mercy,
            // probed, never a contract).
            $refuses($from . '/src', $from . '/src', 'A self-copy must refuse — pre-round it returned normally having copied nothing, a silent wrong outcome.');

            // (b) The nested target: the destination sits inside the
            // source the lazy iterator is walking — the copy lands in
            // the tree under test.
            $refuses($from . '/src', $from . '/src/inside', 'A target inside the source must refuse — the copy would land inside the very tree it reads.');

            /*
             * (b-mirror) The MIRROR relation (t31-ocr9-2): the target
             * CONTAINS the source — pre-fix the guard passed it, and a
             * nested same-name segment resolved the copy INSIDE the
             * tree being read (driven: src/src/nested.php landed at
             * src/nested.php, plus collateral in the containing
             * parent). Same containment owner, symmetric direction.
             */
            mkdir($from . '/src/src', 0755, true);
            $this->stage($from . '/src/src/nested.php', 'nested bytes');
            $refuses($from . '/src', $from, 'A target that CONTAINS the source must refuse — the mirror of the nested-target refusal.');
            $this->assertFileDoesNotExist($from . '/src/nested.php', 'The mis-nested landing (the nested segment resolving inside the tree being read) never happens.');
            $this->assertFileDoesNotExist($from . '/file.php', 'No collateral lands in the containing parent either.');

            /*
             * The alias spellings of (b) (t31-ocr8-3, over the ocr7-8
             * named-alias class): the not-yet-created target was judged
             * purely lexically, and both aliases hid the physical
             * landing — a '..'-woven target and a target reached
             * through a SYMLINKED ancestor ride a spelling the lexical
             * prefix check cannot see through. The nearest EXISTING
             * ancestor decides now; both refuse, and both share (b)'s
             * physical landing spot.
             */
            $refuses($from . '/src', $from . '/decoy/../src/inside', 'A \'..\'-woven target that lands inside the source must refuse — the spelling is not the location.');
            /*
             * (c) The FILE-in-chain target (t31-ocr10-10): the ancestor
             * walk once stepped PAST a regular file in the chain (not a
             * dir, not a link — exactly its walk-on conditions), judged
             * containment against an ancestor ABOVE it, passed, and the
             * copy died later in mkdir() as a raw E_WARNING instead of
             * the policy exception the @throws contract promises. The
             * walk stops at any existing component now.
             */
            $refuses($from . '/src', $from . '/plain.txt/inside', 'A target whose chain crosses a regular FILE must refuse with the policy exception naming the crossing — never a raw mkdir() warning from the byte work.');
            // The linked-ancestor legs ride the CAPABILITY probe
            // (t31-ocr10-14): function_exists('symlink') is true on
            // hosts that cannot use it, and the bare call fatals the
            // battery mid-test — the probe gates the legs instead.
            if (WpHarness::canSymlink()) {
                symlink($from . '/src', $from . '/ancestor-link');
                $refuses($from . '/src', $from . '/ancestor-link/inside', 'A target reached through a SYMLINKED ancestor of the source must refuse — the link is not a door.');

                /*
                 * The DANGLING twin (OCR round 16, t31-ocr16-6, the
                 * t31-ocr10-10 vocabulary-leak class reopened one
                 * shape deeper): the walk stops at a link, is_file()
                 * FOLLOWS it (false for a dangling one), realpath()
                 * answers false, and the lexical containment fallback
                 * once let the landing die THROUGH the link in raw
                 * engine warnings ('mkdir(): No such file or
                 * directory') with copyTree() RETURNING NORMALLY
                 * having moved nothing (driven at HEAD). A link
                 * resolving to nothing is a malformed chain exactly
                 * like the regular-file crossing: the sibling
                 * vocabulary refuses it, before any byte moves.
                 */
                symlink($from . '/no-such-target', $from . '/dangling-link');
                $refuses($from . '/src', $from . '/dangling-link/inside', 'A target whose chain crosses a DANGLING symlink must refuse — the link resolves to nothing, no directory can be created through it, and the landing would die in the engine\'s vocabulary, never the policy\'s.');

                /*
                 * The POP-ABOVE-ANCHOR shapes (OCR round 17's verifier
                 * refutation, t31-ocr17-9, closed in-round): a '..' in
                 * the remainder pops the lexical collapse ABOVE the
                 * anchor the walk resolved, and the post-pop descent
                 * crosses a link nothing resolved — driven at the
                 * round's own HEAD, the dir-link spelling RETURNED
                 * NORMALLY with the copy landed INSIDE the source
                 * (the plain spelling of the same landing refuses),
                 * and the FILE-link variant died in raw mkdir()/copy()
                 * warnings. The collapsed resolution walks the same
                 * judgment now: the dir-link shape refuses through
                 * containment's own vocabulary, the file-link shape
                 * through the crossing gate.
                 */
                mkdir($from . '/pop-anchor', 0755, true);
                symlink($from . '/src', $from . '/pop-link');
                symlink($file_source, $from . '/pop-file-link');
                $refuses($from . '/src', $from . '/pop-anchor/b/../../pop-link/dst', 'A \'..\' that pops the collapse above its anchor is judged where the chain RESOLVES — a descent crossing a link into the source is the nested target, whatever the spelling.');
                $this->assertFileDoesNotExist($from . '/src/dst', 'Nothing lands inside the source through a pop-above-anchor descent (driven at the round\'s HEAD as a normal return with the copy inside the very tree it read).');
                $refuses($from . '/src', $from . '/pop-anchor/c/../../pop-file-link/dst', 'A pop-above-anchor descent crossing a link to a FILE refuses through the crossing gate — never raw mkdir() warnings.');

                // The control: the same ancestor walk keeps judging a
                // NORMAL disjoint target by its own (existing or
                // created-fresh) location — the copy still lands.
                WpHarness::copyTree($from . '/src', $from . '/fresh-outside');
                $this->assertFileExists($from . '/fresh-outside/file.php', 'A normal disjoint target still copies through the ancestor-resolved containment check.');
            }

            // The refusal precedes the byte work: the source tree is
            // intact after every shape (the pre-fix nested copy is the
            // self-pollution this leg guards against).
            $this->assertSame('original bytes', (string) file_get_contents($from . '/src/file.php'), 'The source tree survives every refusal untouched.');
            $this->assertFileDoesNotExist($from . '/src/inside', 'The nested target was never created.');
        } finally {
            // The unreadable-source leg's tree opens back up before
            // the teardown walk owns it (t31-ocr32-9's residue
            // vocabulary: the caller's finally restores).
            @chmod($from . '/locked-src', 0755);
            WpHarness::releaseScratch($from);
        }
    }

    /**
     * OCR round 35 (t31-ocr35-5): the containment verdicts speak the
     * CASE vocabulary the HOST speaks — derived, never assumed.
     *
     * macOS is a POSIX host passing every isPosixHost() gate while its
     * filesystem (and class loading through it) resolves a case-variant
     * target ('/SRC' beside a source '/src') to the SAME tree, so the
     * byte-wise verdicts passed the exact self-copy shape the guard
     * exists to kill. The host's behavior is DERIVED through the
     * harness's probe owner (isCaseInsensitivePathHost(), planted in
     * temp — the canSymlink shape), and this leg expects the
     * HOST-CORRECT verdict on both arms: the refusal where the variant
     * resolves to the source, the real copy where it names a different
     * tree. ENGINE-PREMISE NOTE (the r34-3 discipline): this runner is
     * Linux — case-SENSITIVE (driven: the probe answers false) — so
     * the proceeding arm is what runs here, the copy landing a REAL
     * second tree with the source intact; the refusal's red lives only
     * on a case-insensitive host, and the leg pins the contract
     * green-both-sides exactly the way the '//' super-root leg does.
     * ROUND 36 (t31-ocr36-6): the refusal arm's landing pin now proves
     * the RESOLUTION (the variant spelling answers the staged file)
     * instead of a "nothing lands" verdict that was GUARANTEED RED on
     * the arm's own motivating host — the first fix-induced high, the
     * r35 derivation's own leg turning on it.
     */
    public function testTheContainmentVerdictSpeaksTheHostsPathCaseVocabulary(): void
    {
        /*
         * The platform gate (OCR round 39, t31-ocr39-5 — the
         * t31-ocr28-8 doctrine this file's sibling legs carry, the
         * battery's one copy leg that rode ungated): the leg joins
         * its spellings through '/' onto a sys_get_temp_dir() base,
         * and on a host whose platform separator is not the POSIX
         * one the base answers drive-letter-spelled — $from.'/SRC'
         * starts with the drive letter, passes into the relative
         * arm, and hits the Windows-absolute-target platform refusal
         * (the t31-ocr28-3 gate) BEFORE the containment verdict this
         * leg judges ever runs: the leg would die in a seam it does
         * not name, never judging its own subject.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The case-vocabulary leg joins \'/\'-spelled variant targets onto a temp base — on this host the drive-letter-spelled base sends the variant into the Windows-absolute platform refusal before the containment verdict the leg judges ever runs (the t31-ocr28-8 doctrine).');
        }

        $from = sys_get_temp_dir() . '/wpct-copytree-case-' . uniqid('', true);
        mkdir($from . '/src', 0755, true);
        $this->stage($from . '/src/file.php', 'original bytes');

        try {
            if (WpHarness::isCaseInsensitivePathHost()) {
                $caught = WpHarness::refusalOf(
                    fn() => WpHarness::copyTree($from . '/src', $from . '/SRC'),
                    'A case-variant target the host resolves to the source itself must refuse — the byte-wise verdict is the wrong verdict on a case-insensitive host.', \RuntimeException::class
                );
                $this->assertStringContainsString('refuses a target that is the source itself', $caught->getMessage(), 'The case-variant self-copy refuses through the containment vocabulary, never a silent same-tree no-op.');
                /*
                 * The RESOLUTION premise, proven — not a "nothing
                 * lands" pin over the variant spelling (OCR round 36,
                 * t31-ocr36-6 — the round's first FIX-INDUCED high):
                 * on this host the spelling {$from}/SRC/file.php
                 * RESOLVES to the staged {$from}/src/file.php — the
                 * same file answers both spellings — so the r35 leg's
                 * assertFileDoesNotExist over the variant was a
                 * GUARANTEED RED on exactly the motivating host class
                 * (macOS default APFS). The leg proves what is true on
                 * each host: the resolution (the file answers the
                 * variant — the premise the refusal rides on) here,
                 * the nothing-lands verdict on the case-sensitive arm
                 * below through a name staged nowhere — absent under
                 * every spelling, the refusal having moved no bytes.
                 */
                $this->assertFileExists($from . '/SRC/file.php', 'The case-variant spelling RESOLVES to the staged source on this host — the premise the self-copy refusal rides on (the same file answers both spellings).');
                $this->assertFileDoesNotExist($from . '/SRC/inside', 'Nothing lands through the case-variant spelling — a name staged nowhere stays absent under every spelling.');
            } else {
                // The case-SENSITIVE arm: the variant spelling names a
                // DIFFERENT tree, the byte-wise verdict is the correct
                // one, and the copy proceeds — a real second tree with
                // the source intact.
                WpHarness::copyTree($from . '/src', $from . '/SRC');
                $this->assertFileExists($from . '/SRC/file.php', 'On a case-sensitive host the case-variant target is a distinct tree and the copy lands.');
                $this->assertSame('original bytes', (string) file_get_contents($from . '/SRC/file.php'), 'The copy carries the source bytes.');
                $this->assertSame('original bytes', (string) file_get_contents($from . '/src/file.php'), 'The source tree rides the copy untouched.');
            }
        } finally {
            WpHarness::releaseScratch($from);
        }
    }

    /**
     * OCR-round-38 pin (t31-ocr38-2): a FAILED probe plant is never a
     * MEASURED answer. caseProbeAnswer() caches per volume for the
     * whole process, and when the plant failed (a read-only probe
     * base, ENOSPC, quota) the unmeasured false rode into the cache —
     * on a case-INSENSITIVE volume (the exact host class the r35/r36
     * fold machinery serves) one failed plant poisoned every later
     * derivation on that volume for the rest of the process. The
     * failed plant answers the conservative case-sensitive verdict
     * UNMEASURED now and the cache holds only measured answers — a
     * later call with a plantable base re-measures.
     *
     * OCR round 39 (t31-ocr39-7): the r38 pin never drove its OWN
     * subject. caseProbeAnswer() short-circuits on the per-volume
     * cache BEFORE any plant is attempted, and the pin's holder sat
     * under sys_get_temp_dir() on the very volume the battery's
     * earlier legs already measured — in suite order the probe
     * answered the CACHED entry, the planted-fail arm never ran, and
     * the pin passed vacuously over the code it existed to judge;
     * worse, on a case-insensitive host the cached TRUE reddened the
     * planted-fail expectation spuriously, the pin failing on exactly
     * the host class it serves. The pin drives the REAL subject now:
     * the seam unsets the volume's cache key through the same
     * ReflectionProperty the snapshot rode (the one test-visible
     * spelling that forces the plant path — with the key absent, the
     * short-circuit cannot answer, so the plant path is the only
     * path), and the CONTROL below re-measures and RESTORES the host
     * truth the unset set aside, so the cache's own doctrine — host
     * truth, never test state, re-measurement deterministic —
     * survives the seam bit-for-bit. The expectations are
     * host-correct on BOTH classes: the failed plant answers the
     * conservative false everywhere (the plant path RAN), and the
     * cache-hit path — the very path whose unchecked ride made the
     * r38 pin vacuous — is pinned beside the plant path it starved.
     */
    public function testAFailedCaseProbePlantIsNeverAMeasuredCachedAnswer(): void
    {
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The planted-fail shape premises POSIX permission bits — this host\'s platform separator is not the POSIX one.');
        }

        $holder = sys_get_temp_dir() . '/wpct-caseprobe-fail-' . uniqid('', true);
        $this->assertTrue(@mkdir($holder, 0755, true), "Staging {$holder} must land — a failed stage is the leg's own verdict, never a misleading downstream one.");

        /*
         * The write-denial probe (the t31-ocr4-1 doctrine, this file's
         * own uid-0 idiom): a process that writes through 0555 cannot
         * construct the planted-fail shape — skip visibly, never a
         * vacuous green.
         */
        chmod($holder, 0555);
        $denied = false === @file_put_contents($holder . '/denial-probe', 'x');
        chmod($holder, 0755);
        if (! $denied) {
            WpHarness::releaseScratch($holder);
            $this->markTestSkipped('This host writes through 0555 permission bits (root-shaped, t31-ocr4-1); the planted-fail shape is unconstructible here, so the ocr38-2 cache-purity pin cannot run on this runner.');
        }

        try {
            $probe = new \ReflectionMethod(WpHarness::class, 'caseProbeAnswer');
            $cache = new \ReflectionProperty(WpHarness::class, 'case_insensitive_volumes');
            $volume = (string) stat($holder)['dev'];
            /*
             * THE SEAM (t31-ocr39-7): the volume's cache key — put
             * there legally by whatever earlier leg measured the temp
             * volume, absent in an isolation run — is UNSET before the
             * planted-fail call, forcing the plant path the pin judges
             * (the cache short-circuit is the only other path, and
             * with the key absent it cannot answer; this is the exact
             * unchecked ride that made the r38 pin vacuous in suite
             * order). The prior entry, when one exists, is held for
             * the control's restore pin below; the unset is undone by
             * RE-MEASUREMENT, never by writing an answer back, so no
             * test-simulated truth ever enters the cache.
             */
            $before = $cache->getValue(null);
            $warmed = $before[ $volume ] ?? null;
            $forced = $before;
            unset($forced[ $volume ]);
            $cache->setValue(null, $forced);

            chmod($holder, 0555);
            $answer = $probe->invoke(null, $holder);
            $this->assertFalse($answer, 'A failed plant answers the CONSERVATIVE case-sensitive verdict — the containment verdicts err byte-wise wherever the volume goes unmeasured, and on this host class the answer rode the plant path the seam forced (red as a PIN at HEAD: the r38 shape answered the cache instead, the plant never attempted).');
            $this->assertArrayNotHasKey($volume, $cache->getValue(null), 'A failed plant writes NOTHING to the per-volume cache — no new key, no overwritten value: one failed plant must not poison a case-insensitive volume\'s derivations for the whole process.');

            // The control: a PLANTABLE base on the same volume still
            // measures and caches — the machinery answers, only the
            // unmeasured shape stays out, and the re-measurement
            // RESTORES the host truth the seam's unset set aside.
            chmod($holder, 0755);
            $measured = $probe->invoke(null, $holder);
            $restored = $cache->getValue(null);
            $this->assertArrayHasKey($volume, $restored, 'A MEASURED answer still enters the cache — the control proves the failed-plant arm silenced only itself, never the probe.');
            $this->assertSame($measured, $restored[ $volume ], 'The cached measured answer is the probe\'s own verdict.');
            if (null !== $warmed) {
                $this->assertSame($warmed, $measured, 'The control RESTORES the entry the seam set aside — re-measurement is deterministic, and the cache holds host truth on every exit path this pin can take, never test state.');
            }

            // The cache-hit belt: the path whose unchecked ride made
            // the r38 pin vacuous, pinned beside the plant path it
            // starved — the next call answers the measured truth.
            $this->assertSame($measured, $probe->invoke(null, $holder), 'The cache-hit path answers the measured truth for the rest of the process — the very ride that starved the r38 pin, now an assertion of its own.');
        } finally {
            @chmod($holder, 0755);
            WpHarness::releaseScratch($holder);
        }
    }

    /**
     * OCR-round-40 pin (t31-ocr40-5): the case probe plants its probe
     * file in the volume's SCRATCH representative, never in the
     * judged tree itself. caseProbeAnswer() planted wpct-pathcase-*
     * directly into whichever directory the derivation first judged —
     * for the suite's copyTree shapes that is routinely a
     * REPOSITORY-rooted source tree (the fixtures plugins the build,
     * seam-property, and unused-import suites copy from) — with only
     * a bare @unlink between the plant and the return: a probe whose
     * process died between the two (a killed run, a fatal one test
     * over) left its residue in the REPO tree, unreclaimed junk in
     * the checkout (driven red at HEAD: a killed child leaves a
     * wpct-pathcase-* file inside the fixtures tree).
     *
     * The crash shape is driven the FREEZE-KILL way because it is the
     * only deterministic shape the seam has: nothing between the plant
     * and the unlink can throw in-process (the measurement is
     * file_exists()), no finally survives a kill, and a bare kill
     * lands at ONE loop phase — measured over twenty trials the
     * mid-window residue lands ~60% of kills, one phase draw per
     * kill, never a certainty. SIGSTOP freezes the looping child
     * WHEREVER it stands: a child stopped inside its plant window
     * HOLDS the planted file, so the freeze observes the window
     * without racing its microseconds, and killing the frozen child
     * (SIGKILL terminates stopped processes) answers the exact crash
     * residue the pin judges. The freeze retries — each stop is
     * another phase draw, the CONT and the spawn jitter between
     * attempts re-randomizing it — so a regressed seam reddens with
     * ~1 - 0.4^10 certainty. The residual miss rides green BY
     * DESIGN (OCR round 48, t31-ocr48-4): the else arm asserts
     * the residue absence the fixed seam owes — on a plant-less
     * host (the r38-2 conservative arm, this runner's own shape)
     * it is the every-run path, per the r40-5 record's own
     * both-arms-green fact — never the loud staging-shaped
     * failure this docblock claimed from round 40 (the branch
     * never carried it; the inline comment at the arm always
     * told the true contract). The child loops the probe over
     * the repo-rooted base with the per-volume cache key unset each
     * iteration (the t31-ocr39-7 spelling — the cache short-circuit
     * is the only other path); at HEAD the residue lands in the
     * fixtures tree, post-fix in the scratch representative (or no
     * plant at all on a host whose temp root sits off the judged
     * volume — the r38-2 conservative unmeasured arm, residue-free
     * the same way).
     *
     * OCR round 44 (t31-ocr44-6): the window is watched through BOTH
     * lenses now — the fixtures tree (a REGRESSED seam's plant) and
     * the temp root's child-pid spelling (a FIXED seam's plant, the
     * scratch representative the ocr40-5 seam owns) — so the freeze
     * catches the window on either seam state. The finally's sweep
     * answers BOTH spellings (this process's pid and the test's OWN
     * dead child's pid — the ocr43-10 boundary intact: no foreign
     * process's probe is ever another runner's to unlink), and the
     * sweep's own pin is DRIVEN DETERMINISTICALLY: the real strand is
     * phase- and host-conditional (the kill must land inside the
     * plant window, and on a host whose temp root sits off the judged
     * volume — this runner's tmpfs /tmp beside the ext4 repo, probed
     * — the r38-2 conservative arm plants NOTHING, so no phase can
     * strand one), so the leg stages the killed child's stranded
     * SPELLING itself once the child is dead — a probe under the
     * child's pid, the exact bytes a mid-window kill leaves — and the
     * guarded post-sweep assertion (a flag the verdict-return sets,
     * the t31-ocr42-8 doctrine: no assertion fires while a verdict is
     * in flight) holds the temp root free of it. Red at HEAD: the
     * own-pid-only glob left the child's stranded probe in the shared
     * temp root.
     */
    public function testACrashedCaseProbeLeavesItsResidueInScratchNeverInTheJudgedRepoTree(): void
    {
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The crash sim premises POSIX process semantics (a shell job-control kill of a backgrounded child) and the \'/\'-joined containment vocabulary — this host\'s platform separator is not the POSIX one.');
        }
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the killed-child crash sim cannot run (the t31-ocr16-12 doctrine).');
        }

        /*
         * The harness and fixtures paths are asserted resolved BEFORE
         * the child embeds them (the ocr25-8 class census): a
         * realpath() false once embedded `require false;` into the
         * child — the fatal then read as the harness's own defect, an
         * environment problem wearing the pin's subject.
         */
        $harnessPath = realpath(__DIR__ . '/harness/WpHarness.php');
        $this->assertNotFalse($harnessPath, 'The harness path must resolve before the child embed — a realpath() false is an environment problem, never the harness defect the child would fatal as.');
        $fixturesReal = realpath(__DIR__ . '/fixtures/plugins');
        $this->assertNotFalse($fixturesReal, 'The fixtures plugins tree must resolve before the child judges it — the crash sim\'s judged base is this repository-rooted tree.');
        $fixtures = (string) $fixturesReal;
        $judge = $fixtures . '/example-connector';
        $this->assertDirectoryExists($judge, 'The example-connector fixture tree is the crash sim\'s judged base — the exact shape the build suites copyTree() from.');

        $heartbeat = sys_get_temp_dir() . '/wpct-pathcase-crash-' . uniqid('', true) . '.log';
        /*
         * The child owns its OWN termination (OCR round 47, t31-ocr47-8):
         * its only lifecycle owner was the finally's kill — a CI
         * cancel/timeout, an OOM fatal, or a Ctrl-C skips finally and
         * once orphaned a busy-looping child (reflection + stat + glob
         * per iteration) kept running beside a dead test. The child
         * embeds the OWNING test process's pid and probes it every
         * iteration — the getppid() drift is useless here (the spawn
         * shell exits at once, the child is re-parented at birth), so
         * the liveness probe is the build's own processIsAlive
         * doctrine spelled inline (bin/build.php — the child requires
         * only WpHarness), with its branches REORDERED by a driven
         * counter-proof: the doctrine's is_dir-first /proc branch
         * CANNOT serve a hot loop — PHP's stat cache pins the first
         * verdict per path string, and an entry that read alive at
         * loop start kept answering alive over 20 MILLION iterations
         * past the owner's death (driven in /tmp, a 3s stall and
         * climbing). The signal-0 probe leads — posix_kill is
         * cache-free and deterministic in both directions (EPERM =
         * exists, not ours to signal; ESRCH = gone) — and the /proc
         * is_dir rides only as the posix-less fallback, behind its
         * own clearstatcache() (the per-call bust the hot loop
         * needs). A dead owner answers the loud orphan exit — the
         * heartbeat log names the death — within one iteration of the
         * reaping (a zombie owner reads alive until its reaper takes
         * it, seconds at most; the never-kill direction stays the
         * failure mode: a pid-reuse false-alive keeps the child
         * looping, exactly today's behavior, never a live test's
         * child dead). The CENSUS (t31-ocr47-9, folded — this is the
         * file's only site): every other spawned child in the suite
         * runs FOREGROUND over a finite script (exec owns its
         * lifecycle; it cannot outlive the test by orphaning), and
         * the harness's own two while (true) walks are bounded
         * fixpoint loops, not children — the backgrounded crash-sim
         * child is the only unbounded child the suite spawns.
         */
        $script = 'require ' . var_export($harnessPath, true) . ';'
            . ' $owner = ' . (int) getmypid() . ';'
            . ' $alive = static function (int $pid): bool {'
            . '     if (function_exists("posix_kill")) { return @posix_kill($pid, 0) || 1 === posix_get_last_error(); }'
            . '     clearstatcache();'
            . '     return is_dir("/proc/" . $pid);'
            . ' };'
            . ' $p = new ReflectionMethod("WpHarness", "caseProbeAnswer");'
            . ' $c = new ReflectionProperty("WpHarness", "case_insensitive_volumes");'
            . ' $dev = (string) stat(' . var_export($judge, true) . ')["dev"];'
            . ' fwrite(STDOUT, "looping\n");'
            . ' while (true) {'
            . '     if (! $alive($owner)) { fwrite(STDERR, "orphaned: the owning test process is gone, the crash-sim child exits on its own\n"); exit(71); }'
            . '     $cache = $c->getValue(null); unset($cache[$dev]); $c->setValue(null, $cache); $p->invoke(null, ' . var_export($judge, true) . '); }';

        // The residue lens: every wpct-pathcase-* file under the
        // judged tree (the assertion's subject) — and the same sweep
        // as the finally's cleanup, so the pin leaves the repository
        // exactly as it found it whatever the verdict (the at-HEAD
        // residue this pin reddens over is reclaimed here too).
        /*
         * The sweep fences its walk (OCR round 41, t31-ocr41-3 — the
         * exact shape both production owners fence, rrmdir
         * t31-ocr33-6 and copyTree t31-ocr34-2): an unreadable
         * subtree under the judged tree (a stranded chmod, an FS/AV
         * lock) aborted the descent in the SPL iterator's own
         * UnexpectedValueException — another library's vocabulary
         * dying inside the test's own diagnostic helper. The fence
         * answers the test's failure vocabulary, naming the path.
         */
        $residueOf = function (string $root): array {
            $residue = array();
            try {
                $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                foreach ($walk as $file) {
                    if ($file->isFile() && 0 === strpos($file->getFilename(), 'wpct-pathcase-')) {
                        $residue[] = $file->getPathname();
                    }
                }
            } catch (UnexpectedValueException $walk_refusal) {
                $this->fail('The residue sweep cannot list ' . $root . ' — an unreadable subtree under the judged fixtures tree is an environment verdict this pin names, never the SPL iterator\'s own vocabulary (the t31-ocr33-6/ocr34-2 fence, the diagnostic twin): ' . $walk_refusal->getMessage());
            }

            return $residue;
        };

        $childPid = 0;
        /*
         * The verdict-return flag (t31-ocr44-6): the finally's own
         * assertion may fire ONLY when no verdict is in flight —
         * PHP REPLACES an in-flight exception with one thrown from
         * finally (the t31-ocr42-8 census), so the flag is set by the
         * try body's LAST statement and the pin below stays silent on
         * every abort, staging failure, and skip path, where the
         * body's own verdict outranks it.
         */
        $verdict_returned = false;
        try {
            /*
             * The spawn (the backgrounded command is the php process
             * alone, so $! names IT — a compound command would name
             * the subshell and the kill below would orphan the child
             * mid-loop): the heartbeat lands before the loop starts.
             */
            $spawn = array();
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' >' . escapeshellarg($heartbeat) . ' 2>&1 & echo $!', $spawn, $spawnExit);
            $childPid = (int) trim((string) ($spawn[0] ?? ''));
            $this->assertGreaterThan(0, $childPid, 'The spawn must answer the child pid — a failed spawn is a staging failure, never a residue verdict (the t31-ocr27-9 doctrine).');
            /*
             * Staging (the t31-ocr27-9 doctrine): the heartbeat proves
             * the child REACHED its loop — a child that fataled at the
             * embed or the reflection setup would otherwise leave a
             * vacuous green over a residue-less freeze.
             */
            $beat = '';
            for ($wait = 0; $wait < 40 && "looping\n" !== $beat; ++$wait) {
                usleep(50000);
                $beat = (string) @file_get_contents($heartbeat);
            }
            $this->assertSame("looping\n", $beat, 'The child must reach its probe loop — a fatal at the embed or the reflection setup is a staging failure, never a residue verdict.');

            /*
             * THE FREEZE: stop the looping child wherever it stands,
             * look for the planted file, resume it when the window
             * was closed. A child stopped inside the plant window
             * HOLDS its probe file; killing the frozen child then
             * answers the crash residue — the file no unlink will
             * reclaim, exactly the checkout junk the pin refuses.
             */
            $frozenResidue = null;
            for ($attempt = 0; $attempt < 10; ++$attempt) {
                exec('kill -STOP ' . $childPid . ' 2>/dev/null');
                usleep(15000);
                $held = $residueOf($fixtures);
                /*
                 * The SCRATCH lens (t31-ocr44-6): the frozen child's
                 * own pid-prefixed probe under the temp root is the
                 * FIXED seam's window held open — the ocr40-5 plant
                 * lands in the scratch representative, so the fixtures
                 * lens above sees a regressed seam only. Either lens
                 * firing means the stopped child stands inside its
                 * plant window.
                 */
                /*
                 * The lens owns the glob contract (OCR round 45,
                 * t31-ocr45-9): glob() answers FALSE on error, and
                 * `array() !== false` read the refusal as the window
                 * HELD — a diagnostic failure fabricating the
                 * window-open verdict, silently degrading the
                 * detection guarantee this lens exists to carry. The
                 * false answers the site's own loud failure naming
                 * the environment verdict, never a verdict over data
                 * the glob never returned.
                 */
                $child_held = glob(sys_get_temp_dir() . '/wpct-pathcase-' . $childPid . '-*');
                if (false === $child_held) {
                    $this->fail('The scratch lens cannot list the shared temp root — glob() answered false, an environment verdict this pin names, never a fabricated window-open verdict read over the false.');
                }
                if ($held !== array() || array() !== $child_held) {
                    // The crash itself: SIGKILL reaches a stopped
                    // process, and no code of ours runs after it.
                    exec('kill -9 ' . $childPid . ' 2>/dev/null');
                    usleep(10000);
                    $frozenResidue = $held;
                    break;
                }
                exec('kill -CONT ' . $childPid . ' 2>/dev/null');
                usleep(30000);
            }
            if (null !== $frozenResidue) {
                // The window opened and the frozen child died inside
                // it: whatever survives the kill IS the crash residue.
                $survivors = $residueOf($fixtures);
                $this->assertSame(array(), $survivors, 'A crashed probe leaves its residue in SCRATCH (the volume\'s temp representative or nowhere), never in the judged repository tree — the planted file the killed child never unlinked must not sit in the fixtures tree (red at HEAD: the plant went directly into the judged base, with only a bare @unlink between).');
            } else {
                // The window never opened across every attempt — the
                // ~0.4^10 tail on EITHER seam state now that the
                // scratch lens watches the fixed seam's plant too (a
                // regressed seam misses through the fixtures lens, a
                // fixed one through the temp root), and the verdict
                // below still holds — the residue absence it asserts
                // is what the fixed seam owes, with the tail
                // documented in the docblock.
                $this->assertSame(array(), $residueOf($fixtures), 'No freeze may ever observe a planted probe file inside the judged repository tree — the plant belongs in scratch or nowhere.');
            }

            /*
             * The stranded SPELLING, staged deterministic (t31-ocr44-6):
             * the child is dead now, and whatever its kill may have
             * stranded is phase- and host-conditional — on this
             * runner the r38-2 arm plants nothing at all (tmpfs temp
             * beside the ext4 repo), so no phase draw could strand
             * one. The staged probe spells the DEAD child's own pid
             * in the harness's own plant spelling — the exact residue
             * a mid-window kill leaves — and the sweep below owns it
             * exactly as it would own the real one.
             */
            $this->stage(sys_get_temp_dir() . '/wpct-pathcase-' . $childPid . '-' . bin2hex(random_bytes(4)) . 'AbC.probe', 'case probe');

            // The body's verdict has returned — the finally's own pin
            // may speak now (see the flag's census above).
            $verdict_returned = true;
        } finally {
            // The child dies stopped or running, never left looping.
            if ($childPid > 0) {
                exec('kill -9 ' . $childPid . ' 2>/dev/null');
            }
            /*
             * The finally's sweep NEVER throws (OCR round 42,
             * t31-ocr42-8 — the t31-ocr33-7 class this file's own
             * docblock cites): PHP's finally-throws semantics REPLACE
             * an in-flight verdict, and $residueOf() carries the
             * ocr41-3 fence's own $this->fail() — an unreadable subtree
             * surfacing HERE would mask the very verdict the pin just
             * returned with the diagnostic's environment failure
             * instead. The sweep is this finally's DIAGNOSTIC arm, not
             * its verdict arm: a refusal from the lens degrades to a
             * swallowed notice (the junk reclaim skips a tree it
             * cannot list; the residue stays for the checkout's owner),
             * while the assertion arms above keep the fence's loud
             * spelling. Verdicts never ride a finally.
             */
            $repo_junk = array();
            try {
                $repo_junk = $residueOf($fixtures);
            } catch (\Throwable $environment_verdict) {
                /*
                 * Swallowed WITH NOTICE (OCR round 49, t31-ocr49-12):
                 * the census above and the r42-8 ledger record both
                 * promise a degraded stderrNotice() here — the catch
                 * was empty, an environmental failure in the repo-junk
                 * sweep disappearing with no diagnostic at all. The
                 * notice rides the suite's ONE STDERR writer (the
                 * ocr38-5 site's idiom, php://stderr in every SAPI,
                 * an unopenable stream degrading silently), the
                 * in-flight verdict still outranking the diagnostic.
                 */
                WpHarness::stderrNotice('repo-junk sweep degraded: the residue lens refused (' . get_class($environment_verdict) . '): ' . $environment_verdict->getMessage() . "\n");
            }
            foreach ($repo_junk as $junk) {
                @unlink($junk);
            }
            /*
             * The temp sweep is scoped to the spellings THIS test owns
             * (OCR round 43, t31-ocr43-10; the child's half joined in
             * round 44, t31-ocr44-6): the probe name carries its own
             * pid (wpct-pathcase-<pid>-<hex>, the harness's own
             * spelling at the plant seam), and the bare class glob
             * matched EVERY process's in-flight case probe — under
             * parallel CI runners sharing the temp root this finally
             * could unlink another LIVE process's probe
             * mid-measurement. This process's spelling and the test's
             * OWN DEAD CHILD's are this test's to reclaim (the child
             * is killed above — no window of its survives the sweep,
             * and no foreign process's probe is ever another runner's
             * to unlink, the ocr43-10 boundary); the child's stranded
             * probe once belonged in scratch or nowhere (the ocr40-5
             * doctrine) — it belongs RECLAIMED now, never left as the
             * shared temp root's residue. The heartbeat rides its own
             * explicit unlink below (its 'crash'-stemmed spelling never
             * matched the pid-prefixed pattern).
             *
             * EVERY glob in this finally owns the glob contract (OCR
             * round 45, t31-ocr45-9 — the diagnostics-degrade half):
             * glob() answers FALSE on error, and a false kept in
             * $temp_junk raised a TypeError from the foreach that
             * REPLACED the in-flight verdict (the t31-ocr42-8 class
             * this finally's own census names). The false answers the
             * EMPTY ARRAY here, one swallowed degrade beside the lens
             * above — diagnostics degrade, verdicts never.
             */
            $temp_junk = glob(sys_get_temp_dir() . '/wpct-pathcase-' . getmypid() . '-*') ?: array();
            if ($childPid > 0) {
                $child_junk = glob(sys_get_temp_dir() . '/wpct-pathcase-' . $childPid . '-*') ?: array();
                $temp_junk = array_merge($temp_junk, $child_junk);
            }
            foreach ($temp_junk as $junk) {
                @unlink($junk);
            }
            @unlink($heartbeat);
            /*
             * The sweep's OWN pin (t31-ocr44-6, guarded by the
             * verdict-return flag — never a verdict replacer): the
             * staged stranded spelling under the dead child's pid —
             * and any REAL strand the kill may have left beside it,
             * wherever the host's plant arm allows one — must be gone
             * once the sweep above has run. The probe spells THIS
             * test's own dead child's pid, no live process's window,
             * so the ownership is the test's by construction.
             */
            if ($verdict_returned && $childPid > 0) {
                $stranded = glob(sys_get_temp_dir() . '/wpct-pathcase-' . $childPid . '-*') ?: array();
                $this->assertSame(array(), $stranded, 'The sweep reclaims this test\'s own killed child\'s stranded probe — the spelling under the dead child\'s pid is a dead process\'s residue this sweep owns, staged or real alike (red at HEAD: the own-pid-only glob left it in the shared temp root).');
            }
        }
    }

    /**
     * OCR-round-22 split (t31-ocr22-2): every root-ANCHORED leg of
     * the precondition battery above rode spellings whose premise is
     * POSIX root resolution — the t31-ocr11-2 doctrine the legs' own
     * docblocks carry: POSIX resolves '.' and '..' AT the root to the
     * root itself on every host, and the guards spell their root
     * clauses '/'. composer declares php >= 8.2 with no platform
     * constraint, and the harness gates its host variance through
     * capability probes (canSymlink(), canSpawnChildren()) — but
     * nothing gated THIS class: on a non-POSIX host the spellings
     * resolve through a different root and the legs would misjudge
     * (or walk) through no defect of the contract they pin. The
     * platform probe gates the battery visibly; on a POSIX host
     * every leg is exact, moved byte-identical from the battery it
     * grew in (with its own round docblocks).
     */
    public function testRootAnchoredSpellingsRefuseBeforeIteratingOnPosixHosts(): void
    {
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The root-anchor spellings ride POSIX root resolution (the t31-ocr11-2 doctrine) — this host\'s platform separator is not the POSIX one, and the legs would judge a different root than the one they pin.');
        }
        /*
         * PROBE-BEFORE-FIRE (OCR round 25, t31-ocr25-4 — the copyTree
         * twin of the removal battery's doctrine): the degenerate,
         * sentinel-chain, first-level, and mirror legs below fire
         * copyTree(src, <a landing at or beneath the filesystem
         * root>), and while the REFUSAL is the verdict, a REGRESSED
         * guard would walk into real mkdir()/copy() writes at the
         * root's first level (the legs' own driven history: real
         * first-level writes as uid 0). The battery runs only where
         * those writes are IMPOSSIBLE — a process that cannot WRITE
         * the root directory cannot create the first-level components
         * the landing names, whatever the guard does (the landing
         * legs' writes are first-level by construction, so the gate
         * is exact for them). is_writable('/') is the capability
         * probe (the t31-ocr10-14 doctrine: the ANSWER is the
         * signal); the root runner skips visibly (the t31-ocr4-1
         * DAC-override premise), the chmod-0000 skip's shape one
         * finding over. Honest sibling note (the round's verifier):
         * the SOURCE-side legs (copyTree('/', dst)) READ-walk the
         * whole root on a regressed guard — a copy explosion into
         * scratch, destructive of nothing; that read-side residual
         * rides the same ledger line as the removal battery's
         * deep-walk boundary.
         */
        if (is_writable('/')) {
            $this->markTestSkipped('The root-landing legs need a process that CANNOT write the filesystem root — this runner writes it (uid 0 / DAC override, the t31-ocr4-1 premise), and a regressed guard would land real writes at the root\'s first level mid-test (t31-ocr25-4 probe-before-fire).');
        }

        $from = sys_get_temp_dir() . '/wpct-copytree-root-' . uniqid('', true);
        mkdir($from . '/src', 0755, true);
        $this->stage($from . '/src/file.php', 'original bytes');

        try {
            // The same refusal owner the parent battery rides
            // (t31-ocr15-7): the family the original catch declared
            // rides the third parameter.
            $refuses = function (string $f, string $t, string $naming) use ($from): void {
                $caught = WpHarness::refusalOf(
                    fn() => WpHarness::copyTree($f, $t),
                    $naming,
                    RuntimeException::class
                );
                $this->assertStringContainsString('WpHarness::copyTree() refuses', $caught->getMessage(), 'The policy exception, never the SPL iterator\'s vocabulary.');
                $this->assertStringContainsString($f, $caught->getMessage(), $naming);
            };

            /*
             * (a-root) The SOURCE-side root collapse (t31-ocr12-3, the
             * THIRD symmetry: rrmdir() refuses '/', the target side
             * refuses a root-collapsed landing — the source side now
             * too): a spelling that resolves to '/' passed every guard
             * pre-fix and walked THE WHOLE ROOT TREE (driven red at
             * HEAD on a scratch target). ROOT-ANCHORED spellings only
             * (the t31-ocr11-2 doctrine this file already carries):
             * POSIX resolves '.' and '..' AT the root to the root
             * itself on every host, while a temp-parent '..'
             * collapses to '/' only where the temp dir sits directly
             * beneath it — deep-temp hosts resolve it to a REAL
             * parent and the leg would walk it. Both spellings
             * refuse before the iterator is even constructed — no
             * byte of the root tree is read, nothing lands.
             */
            /*
             * The bare '/' leg pins the DISTINCTIVE vocabulary (OCR
             * round 28, t31-ocr28-8): the generic $refuses closure
             * asserts the policy prefix plus $f, and with $f === '/'
             * the second pin rides INSIDE the first (every refusal
             * message starts 'WpHarness::copyTree() refuses', a string
             * containing '/') — a check that can never fail, the leg's
             * own verdict vacuous. The leg asserts the source-side
             * root-collapse sentence instead, a substring only that
             * refusal carries; the '/..' twin keeps the closure (its
             * $f spells the distinctive '/..' the message names).
             */
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree('/', $from . '/dst-root-src'),
                'A source collapsed to the filesystem ROOT must refuse — the universal container is not a copyable tree.',
                RuntimeException::class
            );
            $this->assertStringContainsString('refuses a source collapsed to the filesystem ROOT', $caught->getMessage(), 'The leg\'s distinctive pin: the source-side root-collapse vocabulary — the generic policy-prefix check cannot fail, and a contains-\'/\' check rides it vacuously.');
            $this->assertStringContainsString(': /', $caught->getMessage(), 'The refusal names the caller\'s bare root spelling itself.');
            $refuses('/..', $from . '/dst-root-src', 'A \'/..\'-spelled source resolves to the filesystem ROOT on every POSIX host — the same refusal.');
            /*
             * The SUPER-ROOT spelling (OCR round 34, t31-ocr34-3): the
             * exactly-two-leading-slashes spelling is the ONE member
             * of the root class POSIX leaves implementation-defined —
             * the SysV-lineage libcs preserve realpath('//') as '//',
             * and there the predicate's '/' compare alone let the
             * resolved answer past, the root walking as a copyable
             * source. The predicate DERIVES the host's own probe
             * answer now (the r29-6 doctrine — the same
             * assertContains(['/', '//']) acknowledgment the
             * precondition below carries, answered by the guard
             * itself). Driven reality on THIS engine: realpath
             * collapses the probe to '/', so the refusal here rides
             * the '/' arm and the leg is green either side of the fix
             * — the pin's charge is the PRESERVING host, where the
             * red at HEAD lives; the derived arm is that host's belt,
             * inert here exactly the way the 'C:/' spelling is
             * (construction-evident, the ocr28-3 boundary).
             */
            $refuses('//', $from . '/dst-root-src', 'A \'//\'-spelled source resolves to the filesystem ROOT on every POSIX host — whichever spelling the host\'s realpath preserves for the super-root, the same refusal.');
            $this->assertFileDoesNotExist($from . '/dst-root-src', 'The root-source refusal moved no byte — the target was never created, never populated.');

            /*
             * (b-empty) The degenerate targets (t31-ocr11-22, the
             * round-11 verifier lens): '' and root-separator-only
             * spellings once reached the copy loop and attempted
             * FILESYSTEM-ROOT writes (driven red at HEAD: copy() over
             * '/<relative>' with permission-denied warnings — real
             * writes as uid 0). The ocr11-5 absolute walk removed the
             * lexical accident that used to refuse the empty spelling;
             * the guard is explicit now.
             */
            /*
             * The safety net BEFORE the destructive legs (OCR round
             * 17, t31-ocr17-3): their safety rests entirely on the
             * production refusal — a regressed guard would attempt
             * filesystem-ROOT writes as this very test runs. The pin
             * holds the REFUSAL PRECONDITION itself: each degenerate
             * spelling must resolve to the root or to nothing, never
             * to a work dir, so a spelling that resolved somewhere
             * real fails the pin loudly BEFORE the copy is ever
             * attempted.
             */
            foreach (array('/', '//') as $rootOnly) {
                /*
                 * The precondition DERIVES the host's shape (OCR
                 * round 29, t31-ocr29-6): POSIX leaves EXACTLY two
                 * leading slashes implementation-defined — libcs are
                 * free to preserve the super-root spelling (realpath
                 * ('//') answering '//') — and the former pin of
                 * assertSame('/', realpath('//')) reddened on those
                 * hosts while the leg's subject never rode the
                 * resolution: the production guard is LEXICAL
                 * (rtrim('//', '/') === '' — the ocr11-22
                 * root-separators clause), the refusal firing on
                 * every host regardless of the libc's answer. What
                 * the leg needs is weaker and true on both shapes:
                 * the spelling resolves to the filesystem ROOT
                 * ITSELF, in either spelling — never to a real work
                 * tree (driven on this host: '/' for both, the /tmp
                 * probe recorded in the commit).
                 */
                $this->assertContains(
                    realpath($rootOnly),
                    array('/', '//'),
                    'The leg\'s own precondition: the root-separator spelling resolves to the filesystem ROOT itself — the two-slash spelling is preserved on super-root hosts and either spelling names the root the lexical guard refuses; a spelling resolving to a real tree would point this leg\'s landing at it.'
                );
            }
            /*
             * The empty spelling is pinned the way its own refusal
             * judges it — lexically, because the RESOLUTION probe
             * disagrees with the landing rule here (driven while
             * writing this pin: realpath('') answers the CWD on this
             * engine, a work dir); is_dir('') is the engine's own
             * "names no directory", the fact the ocr11-22 refusal
             * stands on.
             */
            $this->assertFalse(is_dir(''), 'The leg\'s own precondition: the EMPTY spelling names no directory the engine would walk — its landing would be the filesystem root, never a resolved work dir.');
            $refuses($from . '/src', '', 'An EMPTY target must refuse — the landing would be the filesystem root.');
            $refuses($from . '/src', '/', 'A root-only target must refuse — the landing would be the filesystem root.');
            $refuses($from . '/src', '//', 'A root-separators-only target must refuse — the landing would be the filesystem root.');
            /*
             * (b-root-sentinel) The ancestor walk's '/' SENTINEL (OCR
             * round 16, t31-ocr16-5): the degenerate guards above own
             * the spellings that NAME the root; this leg owns the
             * chain that WALKS to it — a target whose every component
             * is nonexistent bottoms the walk out at '/' (the loop
             * stops at the sentinel without consulting the
             * dir/link/file gates every other stop rides), and at
             * HEAD the copy sailed past every guard into raw
             * mkdir()/copy() warnings at the ROOT's first level
             * (driven: '/<all-nonexistent>/dest' — permission-denied
             * unprivileged, REAL first-level writes as uid 0) and
             * RETURNED NORMALLY having moved nothing. The sentinel
             * refuses like its siblings now, naming the chain. The
             * leg's precondition rides the same safety net (the
             * t31-ocr17-3 class): the chain's first component must
             * NOT exist before the leg runs, or the refusal is
             * testing a landing that is not first-level-beneath-root.
             */
            $sentinelChain = '/wpct-ocr16-root-sentinel-' . uniqid('', true) . '/dest';
            $this->assertFileDoesNotExist(dirname($sentinelChain), 'The leg\'s own precondition: the sentinel chain\'s first component does not exist — the landing the refusal governs is first-level-beneath-root.');
            $refuses($from . '/src', $sentinelChain, 'A target whose chain has NO existing component must refuse — the walk bottomed out at the filesystem ROOT sentinel, and the landing would create the first component directly beneath it.');
            /*
             * (b-landing-sentinel) The landing policy judges the
             * RESOLUTION (OCR round 17, t31-ocr17-1, the round's
             * substantive close): the sentinel leg above owns the
             * SPELLING'S chain — a '..'-woven target can anchor the
             * ancestor walk at an EXISTING component while the
             * collapse resolves elsewhere, and driven red at HEAD this
             * spelling anchored at the existing '/..', collapsed to a
             * FIRST-LEVEL nonexistent target, passed every containment
             * clause, and sailed into raw mkdir()/copy() warnings at
             * the root's first level (permission-denied
             * unprivileged — real first-level writes as uid 0) before
             * RETURNING NORMALLY. The collapsed chain walks the
             * sentinel's own rule now. The spelling is ROOT-ANCHORED
             * (the t31-ocr11-2 doctrine): '/..' resolves to the root
             * on every POSIX host, so the leg needs no temp-parent
             * assumptions.
             */
            $firstLevel = '/wpct-ocr17-landing-' . uniqid('', true);
            $refuses($from . '/src', '/../' . $firstLevel, 'A \'..\'-woven target whose RESOLUTION has no existing component must refuse — the anchor the walk found is not the landing the collapse names.');
            $this->assertFileDoesNotExist($firstLevel, 'No byte lands at the root\'s first level — the refusal precedes the byte work (driven at HEAD as real first-level writes).');

            /*
             * (b-mirror-root) The ROOT collapse (t31-ocr9-9, the
             * verifier's refutation lens over the round's own mirror
             * code): a target whose existing ancestor resolves to '/'
             * built the prefix '$target_real . '/' as '//' — a string
             * no normalized path contains — so the filesystem root, an
             * ancestor of EVERY source, passed BOTH containment guards
             * and the copy attempted '/<relative>' writes (driven
             * pre-fix). The root is the universal container; it
             * refuses like any other containing target. The spelling is
             * ROOT-ANCHORED, never a temp-parent '..' (OCR round 11,
             * t31-ocr11-2): POSIX resolves '.' and '..' AT the root to
             * the root itself on every host, while
             * sys_get_temp_dir().'/..' collapses to '/' only where the
             * temp dir sits directly beneath it — on hosts whose temp
             * tree is deep (macOS TMPDIR), the spelling resolved to a
             * REAL parent and the leg turned hostile to its own host.
             */
            $refuses($from . '/src', '/..', 'A target collapsed to the filesystem ROOT contains every source — it must refuse like any other container.');

            // The refusal precedes the byte work: the source tree is
            // intact after every shape.
            $this->assertSame('original bytes', (string) file_get_contents($from . '/src/file.php'), 'The source tree survives every refusal untouched.');
        } finally {
            WpHarness::releaseScratch($from);
        }
    }

    /**
     * OCR-round-4 pin (t31-ocr4-3): both symlink shapes ride ONE
     * verdict path now, the copy twin of rrmdir()'s no-symlinks
     * doctrine (t31-ocr1-11). Pre-fix the shapes split: copy() FOLLOWED
     * a linked file (content duplicated), the iterator silently SKIPPED
     * a linked directory — neither is a copy a test can trust, so a
     * link at the source root, inside the tree (file shape), or inside
     * the tree (directory shape) refuses loudly naming the link.
     */
    public function testBothSymlinkShapesRefuseTheCopyLoudly(): void
    {
        /*
         * The platform gate (OCR round 30, t31-ocr30-7, the
         * t31-ocr28-8 doctrine — this file's other filesystem
         * batteries carry it; this one rode ungated): the legs pin the
         * '/'-joined landing vocabulary ('$from . '/' through '$to .
         * '/' . $relative'), and on a host whose platform separator is
         * not the POSIX one the iterator's pathnames join through the
         * native separator — they never meet the prefix, and the
         * nothing-landed pins would judge a seam the legs never named.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The symlink-shape legs ride the POSIX separator join of the landing prefix — this host\'s platform separator is not the POSIX one, and the iterator pathnames would never meet the prefix the legs pin.');
        }

        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }

        $plain = sys_get_temp_dir() . '/wpct-copytree-link-' . uniqid('', true);
        mkdir($plain . '/src', 0755, true);
        $this->stage($plain . '/src/real.php', 'real bytes');

        try {
            /*
             * Per-leg targets (OCR round 22, t31-ocr22-1): the in-tree
             * link refusals fire at the first link the ITERATOR
             * reaches (the t31-ocr4-8 note below), so entries yielded
             * before the link legitimately land — over the battery's
             * formerly SHARED $to one leg's landed residue sat waiting
             * for the next leg's nothing-landed pin (the mid-path
             * leg's assertFileDoesNotExist($to/real.php) judged the
             * file-shape leg's leftovers, a red through no defect on
             * every host whose readdir order yields the real file
             * first). Each leg owns a FRESH target: the premise the
             * nothing-landed pins stand on — nothing pre-existing at
             * the target — holds by construction.
             */
            $freshTo = fn(): string => $plain . '/dst-' . uniqid('', true);

            /*
             * The verdict rides the ONE refusal owner (t31-ocr15-7,
             * replacing the t31-ocr5-3 inline shape this closure
             * hand-rolled): the family the original catch declared
             * (RuntimeException) rides the third parameter, and the
             * no-throw case fails outside any catch.
             */
            $refuses = function (string $from, string $linkName, string $expectation) use ($freshTo): void {
                $to = $freshTo();
                $caught = WpHarness::refusalOf(
                    fn() => WpHarness::copyTree($from, $to),
                    $expectation,
                    RuntimeException::class
                );
                $this->assertStringContainsString($linkName, $caught->getMessage());
            };

            // File shape inside the tree.
            symlink($plain . '/src/real.php', $plain . '/src/linked.php');
            $refuses($plain . '/src', 'linked.php', 'A symlinked FILE inside the source tree must refuse the copy, never duplicate the target content.');

            // Directory shape inside the tree — the same verdict path.
            unlink($plain . '/src/linked.php');
            mkdir($plain . '/target-tree', 0755, true);
            symlink($plain . '/target-tree', $plain . '/src/linked-dir');
            $refuses($plain . '/src', 'linked-dir', 'A symlinked DIRECTORY inside the source tree must refuse the copy too, never skip silently.');

            // The source root itself a link: rrmdir()'s root guard, mirrored.
            unlink($plain . '/src/linked-dir');
            symlink($plain . '/src', $plain . '/root-link');
            $refuses($plain . '/root-link', 'root-link', 'A symlinked SOURCE ROOT must refuse the copy — the copy twin of rrmdir()\'s link-at-root guard.');

            /*
             * The trailing-slash spelling (t31-ocr8-2): is_link()
             * resolves THROUGH a trailing slash, so the pre-fix root
             * guard passed — the only refusal left standing was the
             * INCIDENTAL relativize verdict of the ocr6-4 arm (an
             * unrelated class naming a doubled slash, not the link).
             * The probe reads the slash-stripped spelling now: the
             * verdict names the LINK class, never the disguise.
             */
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($plain . '/root-link/', $freshTo()),
                'A TRAILING-SLASH symlinked SOURCE ROOT must refuse the copy — a slash is not a disguise.',
                RuntimeException::class
            );
            $this->assertStringContainsString('symlinked source tree', $caught->getMessage(), 'The verdict names the LINK class (the slash-stripped probe), never the incidental relativize refusal that fired pre-fix.');
            $this->assertStringContainsString('root-link', $caught->getMessage());

            /*
             * The '/.' spelling (t31-ocr8-13): stat resolves through
             * it exactly as through the slash — pre-fix copyTree()
             * COPIED the target tree through the link by this
             * spelling (driven). The probe spelling strips it; and
             * the CONTROL keeps its behavior: a '/.'-spelled REAL
             * source still copies (the iterator normalizes it — the
             * probe is the only thing that changed).
             */
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($plain . '/root-link/.', $freshTo()),
                'A \'/.\'-spelled symlinked SOURCE ROOT must refuse the copy — a dot is not a disguise either.',
                RuntimeException::class
            );
            $this->assertStringContainsString('symlinked source tree', $caught->getMessage(), 'The verdict names the LINK class for the \'/.\' spelling too.');
            $this->assertStringContainsString('root-link', $caught->getMessage());

            WpHarness::copyTree($plain . '/src/.', $plain . '/dst-dot-control');
            $this->assertFileExists($plain . '/dst-dot-control/real.php', 'A \'/.\'-spelled REAL source keeps copying — the probe is the only judgment that changed.');

            /*
             * The '/..' spelling (t31-ocr9-1, the third stat-transparent
             * tail): the parent it names is the LINK TARGET'S parent —
             * pre-fix copyTree() COPIED that parent tree through the
             * link by this spelling (driven). The probe strips it; the
             * verdict names the LINK class. The CONTROL keeps its
             * behavior: a '/..'-spelled REAL source still copies the
             * tree it names (the iterator resolves it) — only the
             * link judgment changed.
             */
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($plain . '/root-link/..', $freshTo()),
                'A \'/..\'-spelled symlinked SOURCE ROOT must refuse the copy — the parent it names is the TARGET\'S parent, a larger blast radius than the target.',
                RuntimeException::class
            );
            $this->assertStringContainsString('symlinked source tree', $caught->getMessage(), 'The verdict names the LINK class for the \'/..\' spelling too.');
            $this->assertStringContainsString('root-link', $caught->getMessage());

            /*
             * The MULTI-TAIL adjudication (OCR round 47, t31-ocr47-4):
             * the probe's '/..' strip loop pops NOTHING — '/a/b/../..'
             * probes '/a/b', never the semantic '/' — and that landing
             * is the doctrine for every tail count (the ocr9-1
             * no-pop split, never adjudicated past the single tail
             * before this round). A popping "correction" would hide
             * the link's own spelling from the probe chain: this leg
             * rides RED under it (the landing collapses to '/' , the
             * probe sees no link, and the copy walks through the link
             * into the target's GRANDPARENT — the ocr9-1 blast
             * radius, multi-tail edition). The REAL control keeps its
             * semantics: the WALK (never the probe) resolves the
             * spelling, so a real multi-tail source copies the tree
             * it semantically names.
             */
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($plain . '/root-link/../..', $freshTo()),
                'A MULTI-TAIL \'/../..\'-spelled symlinked SOURCE ROOT must refuse the copy — the no-pop landing keeps the link\'s own spelling in the probe chain.',
                RuntimeException::class
            );
            $this->assertStringContainsString('symlinked source tree', $caught->getMessage(), 'The verdict names the LINK class for the multi-tail spelling too.');
            $this->assertStringContainsString('root-link', $caught->getMessage());

            mkdir($plain . '/multi-tail/a/b', 0755, true);
            file_put_contents($plain . '/multi-tail/a/b/deep.php', '<?php // deep');
            $multi_to = $freshTo();
            WpHarness::copyTree($plain . '/multi-tail/a/b/../..', $multi_to);
            $this->assertFileExists($multi_to . '/a/b/deep.php', 'A REAL multi-tail source keeps copying the tree it semantically names — the walk resolves, only the probe strips.');

            /*
             * The MID-PATH twin (OCR round 17, t31-ocr17-2): the probe
             * once stripped only the TRAILING tails, so a source
             * spelled THROUGH a linked ancestor passed is_link() (stat
             * followed the link to the real directory) and the copy
             * FOLLOWED the link — silently duplicating the target
             * tree's bytes, the exact shape the root guard exists to
             * stop, one component deeper. The probe resolves the FULL
             * component chain now: a link wherever it sits names the
             * link class, never the tree behind it.
             */
            symlink($plain, $plain . '/parent-link');
            $to = $freshTo();
            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($plain . '/parent-link/src', $to),
                'A source spelled through a SYMLINKED ANCESTOR must refuse the copy — the walk never routes through a link, wherever in the chain it sits.',
                RuntimeException::class
            );
            $this->assertStringContainsString('symlinked source tree', $caught->getMessage(), 'The verdict names the LINK class for the mid-path spelling too.');
            $this->assertStringContainsString('parent-link', $caught->getMessage());
            $this->assertFileDoesNotExist($to . '/real.php', 'Nothing lands through a linked ancestor — the refusal precedes the byte work.');
            unlink($plain . '/parent-link');

            /*
             * The pair rides the file's try/finally discipline
             * (t31-ocr15-8, the t31-r3-11 scratch-hygiene shape): the
             * cleanups were inline AFTER the assertion, so an
             * assertFileExists() failure between creation and cleanup
             * leaked BOTH temp trees into /tmp. Every exit path from
             * creation on removes them now.
             */
            $dotdotHolder = sys_get_temp_dir() . '/wpct-copytree-dotdot-' . uniqid('', true);
            $dotdotOut = sys_get_temp_dir() . '/wpct-copytree-dotdot-out-' . uniqid('', true);
            try {
                mkdir($dotdotHolder . '/tree', 0755, true);
                $this->stage($dotdotHolder . '/tree/real.php', 'real bytes');
                WpHarness::copyTree($dotdotHolder . '/tree/..', $dotdotOut);
                $this->assertFileExists($dotdotOut . '/tree/real.php', 'A \'/..\'-spelled REAL source keeps copying the tree it names — the probe is the only judgment that changed.');
            } finally {
                WpHarness::releaseScratch($dotdotHolder, $dotdotOut);
            }

            /*
             * No nothing-landed assertion here (verifier round t31-ocr4-8):
             * copyTree() refuses at the first link the ITERATOR REACHES,
             * and yield order is the filesystem's (ext4 readdir order put
             * the plain file before the link, tmpfs after — the original
             * pin passed only via this host's tmpfs ordering); entries
             * yielded before the link legitimately land, and that is not
             * a property of the refusal.
             */
        } finally {
            WpHarness::releaseScratch($plain);
        }
    }

    /**
     * OCR-round-45 pin (t31-ocr45-12): the resolution loop owns a
     * CYCLE bound. The ocr17-9 termination argument ("the anchor
     * resolves every link it stops at") leaned on links resolving —
     * for a link CHAIN THAT RETURNS TO ITSELF nothing resolves (stat
     * ELOOPs, is_dir/is_file answer false), and the loop's
     * construction-evidence was the screens' accident, never its own.
     * On this engine the two-link cycle meets the DANGLING arm (the
     * probe: is_dir false through the loop, realpath answering the
     * intermediate spelling) and refuses under the DANGLING
     * vocabulary — "the link resolves to nothing," a sentence that
     * mis-names a chain resolving forever; the hang itself is
     * unconstructible through the dangling screen here, and the bound
     * plus the named verdict are what the loop owes BY CONSTRUCTION
     * (never an infinite walk, whatever screen ordering a future edit
     * lands). Driven red at HEAD: the cycle answers the dangling
     * sentence, naming no cycle.
     */
    public function testATwoLinkTargetCycleAnswersTheNamedCycleRefusal(): void
    {
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The cycle leg rides POSIX symlink semantics — this host\'s platform separator is not the POSIX one.');
        }
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the two-link cycle cannot be staged.');
        }

        $base = sys_get_temp_dir() . '/wpct-copytree-cycle-' . uniqid('', true);
        mkdir($base . '/a', 0755, true);
        mkdir($base . '/src', 0755, true);
        $this->stage($base . '/src/real.php', 'real bytes');
        try {
            // The finding's own shape: /a/link -> /b, /b -> /a/link.
            symlink($base . '/b', $base . '/a/link');
            symlink($base . '/a/link', $base . '/b');

            $caught = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($base . '/src', $base . '/a/link/x'),
                'A target whose chain crosses a SYMLINK CYCLE must refuse the copy — the links resolve into each other, so no directory can ever be created through them.',
                RuntimeException::class
            );
            $this->assertStringContainsString('SYMLINK CYCLE', $caught->getMessage(), 'The refusal names the cycle class — never the dangling sentence that mis-names a chain resolving forever (red at HEAD: the dangling vocabulary answered).');
            $this->assertStringContainsString($base . '/a/link', $caught->getMessage(), 'The refusal names the crossing component.');
            $this->assertFileDoesNotExist($base . '/a/link/x/real.php', 'Nothing lands through the cycle — the refusal precedes the byte work.');
        } finally {
            WpHarness::releaseScratch($base);
        }
    }

    /**
     * OCR-round-34 pin (t31-ocr34-2): the COPY walk fences its
     * RECURSION BOUNDARY — the twin of the t31-ocr33-6 fence the
     * removal walk gained, the one owner round 33's residual ledger
     * line named. hasChildren() passes on stat alone, so an
     * unreadable SUBDIRECTORY mid-tree (a chmod-000 child) was
     * reached by the descent — RecursiveIteratorIterator's
     * getChildren() opens it — and the walk died in the SPL
     * iterator's own UnexpectedValueException (driven red at HEAD:
     * the family pin reddened, the SPL exception standing where the
     * policy's does), while the ocr32-9 opendir gate probes only the
     * SOURCE ROOT's readability. The fence converts the abort to the
     * harness's refusal (the SPL message riding parenthetically — it
     * is what names the path), and every landing already made stands
     * for the caller's finally.
     */
    public function testTheCopyWalkFencesTheRecursionBoundary(): void
    {
        $from = sys_get_temp_dir() . '/wpct-copytree-unlistable-' . uniqid('', true);
        $to = $from . '-dst';
        mkdir($from . '/open', 0755, true);
        $this->stage($from . '/open/x.txt', 'bytes');
        mkdir($from . '/locked/inner', 0755, true);
        $this->stage($from . '/locked/inner/y.txt', 'bytes');
        chmod($from . '/locked', 0000);
        // The unlistable-shape probe (the t31-ocr4-1 root doctrine):
        // a host whose process opens chmod-0000 directories cannot
        // construct the shape — skip visibly, never a vacuous green.
        $probe = @opendir($from . '/locked');
        if (false !== $probe) {
            closedir($probe);
            chmod($from . '/locked', 0755);
            WpHarness::releaseScratch($from);
            $this->markTestSkipped('This host opens chmod-0000 directories (uid 0 — t31-ocr4-1); the mid-tree unlistable shape is unconstructible here.');
        }

        try {
            $refusal = WpHarness::refusalOf(
                fn() => WpHarness::copyTree($from, $to),
                'An unlistable SUBDIRECTORY of the source must answer the harness\'s own refusal, never the SPL iterator\'s vocabulary.',
                RuntimeException::class
            );
            $this->assertStringContainsString('WpHarness::copyTree()', $refusal->getMessage(), 'The refusal speaks the harness policy\'s own vocabulary.');
            $this->assertStringContainsString('cannot be listed', $refusal->getMessage(), 'The refusal names the class the fence owns.');
            $this->assertStringContainsString('locked', $refusal->getMessage(), 'The refusal names the path — the SPL message parenthetical carries it.');
            $this->assertStringContainsString('Failed to open directory', $refusal->getMessage(), 'The parenthetical carries the engine\'s own diagnostic for the path — named, never laundered silent.');
        } finally {
            chmod($from . '/locked', 0755);
            WpHarness::releaseScratch($from, $to);
        }
    }

    /**
     * OCR-round-19 pin (t31-ocr19-2): the probe anchors at the TEMP
     * ROOT, never at '/'. The ocr17-2 full-chain walk judged every
     * component of the passed chain, and on a host whose temp spelling
     * itself resolves through a system-layout link (macOS: /var →
     * private/var inside TMPDIR, /tmp → private/tmp on the fallback)
     * the FIRST link found was the host's own spelling — the probe
     * named it for every temp-rooted path, rrmdir silently SKIPPED
     * cleanup of every legal scratch tree, and copyTree refused every
     * legal source (the round-17 ledger's portability note, driven
     * red at HEAD here). The sim rides a CHILD process: TMPDIR
     * redirection makes sys_get_temp_dir() return a SYMLINKED
     * spelling (the macOS layout one level deeper), but the engine
     * caches the temp dir per process — the parent's cache is already
     * warm, so the redirect only answers inside a fresh engine, with
     * the putenv BEFORE the first read. Below the anchor nothing
     * changed: a PLANTED link inside the scratch tree still names the
     * link class (the ocr17-2 doctrine keeps its full reach beneath
     * the root the harness owns).
     */
    public function testASymlinkedTempRootIsHostSpellingWhilePlantedLinksBelowItStillRefuse(): void
    {
        /*
         * The platform gate (OCR round 23, t31-ocr23-5 — the POSIX
         * premise the sim rode ungated, its sibling battery the
         * t31-ocr22-2 split already gating): the child's whole
         * premise is a FRESH engine whose sys_get_temp_dir() honors
         * the TMPDIR spelling — POSIX temp resolution. A host whose
         * platform separator is not the POSIX one resolves the temp
         * dir through its own vocabulary (TMP/TEMP, not TMPDIR), the
         * putenv would redirect nothing, and the sim's verdicts would
         * ride an engine that never read the variable. The constant
         * rides the ONE owner this round's own census threshold
         * hoisted (t31-ocr23-6: the third consumer).
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The redirected-TMPDIR sim premises POSIX temp resolution (a fresh engine honoring the TMPDIR spelling) — this host\'s platform separator is not the POSIX one.');
        }
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }
        /*
         * The exec-capability guard (t31-ocr18-2, the t31-ocr16-12
         * doctrine over this child-process consumer): the sim's whole
         * premise is a FRESH engine reading TMPDIR before anything
         * caches it, and on a disable_functions host the spawn was an
         * undefined-function \Error instead of the visible skip.
         *
         * The guard declares the TRIPLE (OCR round 22, t31-ocr22-4):
         * the child script's premise-critical FIRST statement is a
         * putenv() — the TMPDIR redirect must land before any temp-dir
         * read warms the engine's cache — so on a host with putenv in
         * disable_functions the child fatals before its first read and
         * the sim's premise dies as an exit-code verdict, never the
         * visible skip. The variadic names the extra the way the
         * t31-ocr21-4 owner's own doctrine spells it: the spawn pair
         * is the floor, a consumer that spawns through more declares
         * its own.
         */
        if (! WpHarness::canSpawnChildren('putenv')) {
            $this->markTestSkipped('This host has exec/escapeshellarg/putenv in disable_functions — the redirected-TMPDIR sim cannot run (the anchor verdicts ride a child process whose first statement is a putenv).');
        }

        $base = sys_get_temp_dir() . '/wpct-anchor-' . uniqid('', true);
        mkdir($base . '/real/scratch/sub', 0755, true);
        $this->stage($base . '/real/scratch/sub/x.txt', 'bytes');
        mkdir($base . '/real/copy-src', 0755, true);
        $this->stage($base . '/real/copy-src/f.php', 'copy bytes');
        mkdir($base . '/real/victim', 0755, true);
        $this->stage($base . '/real/victim/keep.txt', 'survivor');
        symlink($base . '/real', $base . '/anchor-link');
        symlink($base . '/real/victim', $base . '/real/planted-link');

        try {
            /*
             * The child: putenv FIRST (before any temp-dir read can
             * warm the cache), then the harness, then both consumers
             * through the redirected root — the removal and the copy
             * of LEGAL trees (pre-fix: both name the anchor link), the
             * removal of a planted link (skips, both ways), and the
             * copy of one (refuses, both ways — the caught message
             * rides STDOUT for the parent's fragment pins).
             */
            /*
             * The harness path is asserted resolved BEFORE the embed
             * (t31-ocr25 rd-1, the ocr25-8 class census): a realpath()
             * false once embedded `require false;` into the child —
             * the fatal then read as the harness's own defect, an
             * environment problem wearing the pin's subject.
             */
            $harnessPath = realpath(__DIR__ . '/harness/WpHarness.php');
            $this->assertNotFalse($harnessPath, 'The harness path must resolve before the child embed — a realpath() false is an environment problem (a broken checkout, an open_basedir wall), never the harness defect the child would fatal as.');

            $script = 'putenv("TMPDIR=" . ' . var_export($base . '/anchor-link', true) . ');'
                . ' require ' . var_export($harnessPath, true) . ';'
                . ' $t = sys_get_temp_dir();'
                . ' WpHarness::rrmdir($t . "/scratch");'
                . ' WpHarness::copyTree($t . "/copy-src", ' . var_export($base . '/copy-dst', true) . ');'
                . ' WpHarness::rrmdir($t . "/planted-link");'
                . ' try { WpHarness::copyTree($t . "/planted-link", ' . var_export($base . '/copy-dst-2', true) . '); fwrite(STDERR, "planted copy returned normally"); exit(3); }'
                . ' catch (RuntimeException $e) { echo $e->getMessage(), "\n"; }';
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
            $child = implode("\n", $output);

            // (a) CLEANUP works through the symlinked temp root: the
            // walk removed the legal scratch tree (pre-fix: the probe
            // named the anchor link and rrmdir silently skipped —
            // driven red at HEAD, the exit carries the copy twin's
            // refusal).
            $this->assertSame(0, $exit, "The child must run clean through the symlinked temp root (the macOS shape) — it said: {$child}");
            $this->assertDirectoryDoesNotExist($base . '/real/scratch', 'Cleanup through a symlinked temp root still removes the scratch tree — the anchor link is the host\'s own spelling, never the planted-link class.');

            // (b) The COPY twin: a legal source under the symlinked
            // temp root copied (pre-fix: false refusal naming the
            // anchor link, driven red at HEAD).
            $this->assertFileExists($base . '/copy-dst/f.php', 'A legal source under the symlinked temp root still copies — never a false refusal.');

            // (c) Below the anchor the doctrine keeps its full reach:
            // the PLANTED link skipped removal and refused the copy —
            // the victim survives, the link stands, the verdict names
            // the link class.
            $this->assertFileExists($base . '/real/victim/keep.txt', 'A planted link below the anchor never drags its target into the removal — the victim survives.');
            $this->assertTrue(is_link($base . '/real/planted-link'), 'The planted link stands exactly where it is.');
            $this->assertStringContainsString('symlinked source tree', $child, 'The planted copy refusal still names the LINK class below the anchor.');
            $this->assertStringContainsString('planted-link', $child);
        } finally {
            WpHarness::releaseScratch($base);
        }
    }

    /**
     * OCR-round-23 pin (t31-ocr23-6): the probe guards the harness's
     * OWN TERRITORY — the chains beneath the temp spelling and the
     * repository root — and every component outside both anchors is
     * the host's layout. The r19 anchor exempted only the components
     * of the temp spelling itself, so every other absolute chain kept
     * the ocr17-2 full-chain walk from '/': a host-layout link ABOVE a
     * source root outside the temp tree fired the planted-link
     * verdict on a legal tree — copyTree refused the source, rrmdir
     * skipped the cleanup (the r19 false-refusal class, one territory
     * over). The sim rides the redirected-TMPDIR child like its r19
     * sibling, one level deeper: the child's temp root is a REAL deep
     * directory, and the source roots are spelled through a link the
     * parent planted BESIDE it — scratch-rooted, above the source
     * root, outside BOTH of the child's anchors (not beneath its temp
     * spelling, not beneath the repository root). Pre-fix both
     * consumers name the layout link (driven red at HEAD); post-fix
     * the layout spelling is legal while the PLANTED control beneath
     * the child's temp root keeps refusing — the ocr17-2 doctrine
     * holds exactly where it lives.
     */
    public function testAHostLayoutLinkAboveAForeignSourceRootProbesLegalWhilePlantedLinksBelowTheTempRootKeepRefusing(): void
    {
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The redirected-TMPDIR sim premises POSIX temp resolution (a fresh engine honoring the TMPDIR spelling) — this host\'s platform separator is not the POSIX one.');
        }
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }
        /*
         * The exec-capability guard, the pair plus the premise-critical
         * putenv (the t31-ocr22-4 triple the r19 sibling above rides):
         * the child's FIRST statement redirects TMPDIR, and the
         * redirect must land before any temp-dir read warms the
         * engine's cache.
         */
        if (! WpHarness::canSpawnChildren('putenv')) {
            $this->markTestSkipped('This host has exec/escapeshellarg/putenv in disable_functions — the redirected-TMPDIR sim cannot run (the anchor verdicts ride a child process whose first statement is a putenv).');
        }

        $base = sys_get_temp_dir() . '/wpct-anchor6-' . uniqid('', true);
        mkdir($base . '/real/src', 0755, true);
        $this->stage($base . '/real/src/f.php', 'layout bytes');
        mkdir($base . '/real/gone', 0755, true);
        $this->stage($base . '/real/gone/x.txt', 'bytes');
        mkdir($base . '/real/deep', 0755, true);
        mkdir($base . '/real/victim', 0755, true);
        $this->stage($base . '/real/victim/keep.txt', 'survivor');
        symlink($base . '/real', $base . '/layout-link');
        symlink($base . '/real/victim', $base . '/real/deep/planted-link');

        try {
            /*
             * The child: putenv FIRST (the fresh-engine premise), then
             * both consumers through the LAYOUT spelling (the copy of
             * a legal source above the child's temp root, the cleanup
             * of a tree spelled through the same link — pre-fix: both
             * name the layout link), then the planted controls BENEATH
             * the child's temp root (the removal skips, the copy
             * refuses, the refusal message on STDOUT for the parent's
             * fragment pins).
             */
            /*
             * The harness path is asserted resolved BEFORE the embed
             * (t31-ocr25 rd-1, the ocr25-8 class census): a realpath()
             * false once embedded `require false;` into the child —
             * the fatal then read as the harness's own defect, an
             * environment problem wearing the pin's subject.
             */
            $harnessPath = realpath(__DIR__ . '/harness/WpHarness.php');
            $this->assertNotFalse($harnessPath, 'The harness path must resolve before the child embed — a realpath() false is an environment problem (a broken checkout, an open_basedir wall), never the harness defect the child would fatal as.');

            $script = 'putenv("TMPDIR=" . ' . var_export($base . '/real/deep', true) . ');'
                . ' require ' . var_export($harnessPath, true) . ';'
                . ' $t = sys_get_temp_dir();'
                . ' WpHarness::copyTree(' . var_export($base . '/layout-link/src', true) . ', ' . var_export($base . '/copy-dst', true) . ');'
                . ' WpHarness::rrmdir(' . var_export($base . '/layout-link/gone', true) . ');'
                . ' WpHarness::rrmdir($t . "/planted-link");'
                . ' try { WpHarness::copyTree($t . "/planted-link", ' . var_export($base . '/copy-dst-2', true) . '); fwrite(STDERR, "planted copy returned normally"); exit(3); }'
                . ' catch (RuntimeException $e) { echo $e->getMessage(), "\n"; }';
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
            $child = implode("\n", $output);

            // (a) The child runs clean through the layout spelling
            // (pre-fix: the copy leg THROWS the link refusal naming
            // the layout link — driven red at HEAD, the exit carries
            // it).
            $this->assertSame(0, $exit, "The child must run clean through the host-layout spelling — it said: {$child}");
            $this->assertFileExists($base . '/copy-dst/f.php', 'A legal source spelled through a layout link above the temp root still copies — never a false refusal (red at HEAD: the probe named the layout link).');
            $this->assertDirectoryDoesNotExist($base . '/real/gone', 'Cleanup spelled through the layout link still removes the tree (red at HEAD: the probe fired and rrmdir silently skipped).');

            // (b) Below the child's temp root the doctrine keeps its
            // full reach: the PLANTED link skipped removal and refused
            // the copy — the victim survives, the link stands, the
            // verdict names the link class.
            $this->assertFileExists($base . '/real/victim/keep.txt', 'A planted link beneath the temp root never drags its target into the removal — the victim survives.');
            $this->assertTrue(is_link($base . '/real/deep/planted-link'), 'The planted link stands exactly where it is.');
            $this->assertStringContainsString('symlinked source tree', $child, 'The planted copy refusal still names the LINK class beneath the temp root.');
            $this->assertStringContainsString('planted-link', $child);
        } finally {
            WpHarness::releaseScratch($base);
        }
    }

    /**
     * OCR-round-19 pin (t31-ocr19-4): the refusal owner's
     * family-mismatch verdict CHAINS the original exception. The
     * mismatch branch is where an unexpected exception IS the signal —
     * pre-fix the verdict named the pinned family and the caught class
     * but constructed the AssertionFailedError WITHOUT the previous
     * argument, so the original's real message and stack trace were
     * discarded at the exact seam where diagnosis matters most. The
     * owner cannot collect its own verdict (it throws where its
     * callers' guarded calls refuse), so the leg hand-rolls the one
     * try/catch this owner's own regression needs: a planted
     * TypeError outside the pinned family fails the verdict with the
     * chain intact — the SAME instance rides getPrevious() (driven
     * once with the planted exception: PHPUnit 9's own __toString
     * strips the previous chain from the rendered string, so the
     * chain-bearing construction is the pin, and any renderer that
     * walks the chain reaches the real message and stack).
     */
    public function testTheFamilyMismatchVerdictCarriesTheOriginalException(): void
    {
        $planted = new \TypeError('planted: the real message and stack are the diagnosis');

        try {
            WpHarness::refusalOf(
                fn(): \TypeError => throw $planted,
                'The planted TypeError must fail the family verdict, never return.',
                RuntimeException::class
            );
            $this->fail('A class outside the pinned family must fail the verdict.');
        } catch (\PHPUnit\Framework\AssertionFailedError $verdict) {
            $this->assertStringContainsString('RuntimeException', $verdict->getMessage(), 'The verdict names the pinned family.');
            $this->assertStringContainsString('TypeError', $verdict->getMessage(), 'The verdict names the caught class.');
            $this->assertSame($planted, $verdict->getPrevious(), 'The original exception rides the chain — never discarded at the diagnosis seam (this engine\'s PHPUnit 9 __toString strips the previous chain from the rendered string, so the CHAIN ITSELF is the pin: getPrevious() is the planted instance, and any renderer that walks it reaches the real message and stack).');
        }
    }

    /**
     * OCR-round-25 pin (t31-ocr25-9): refusalOf()'s verdicts
     * hard-referenced \PHPUnit\Framework\AssertionFailedError at throw
     * time while this file's redirected-TMPDIR siblings require
     * WpHarness.php into PHPUnit-less child engines — lazily safe only
     * for as long as no child leg called a throwing path. The child
     * below drives the no-throw verdict in a bare `php -r`: the
     * message answers (never a class-not-found fatal), and the verdict
     * class is the base Exception — outside the RuntimeException
     * family the guarded calls themselves throw, so a child's own
     * catch can never conflate a verdict with a refusal.
     */
    public function testTheRefusalVerdictResolvesInAPhpUnitLessChildEngine(): void
    {
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the PHPUnit-less child engine sim cannot run.');
        }

        /*
         * The harness path is asserted resolved BEFORE the embed
         * (t31-ocr25 rd-1, the ocr25-8 class census): a realpath()
         * false once embedded `require false;` into the child —
         * the fatal then read as the harness's own defect, an
         * environment problem wearing the pin's subject.
         */
        $harnessPath = realpath(__DIR__ . '/harness/WpHarness.php');
        $this->assertNotFalse($harnessPath, 'The harness path must resolve before the child embed — a realpath() false is an environment problem (a broken checkout, an open_basedir wall), never the harness defect the child would fatal as.');

        $script = 'require ' . var_export($harnessPath, true) . ';'
            . ' try { WpHarness::refusalOf(static function (): void {}, "the no-throw verdict message", RuntimeException::class); fwrite(STDERR, "verdict returned normally"); exit(3); }'
            . ' catch (\Throwable $verdict) { echo get_class($verdict), "\n", $verdict->getMessage(), "\n"; }';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
        $child = implode("\n", $output);

        $this->assertSame(0, $exit, "The PHPUnit-less child must answer the verdict, never fatal — it said: {$child}");
        $this->assertStringNotContainsString('not found', $child, 'No class-not-found fatal: the verdict resolves without PHPUnit loaded (red at HEAD: the bare engine fataled on the AssertionFailedError reference).');
        $this->assertStringContainsString('the no-throw verdict message', $child, 'The bare-engine verdict carries the expectation message.');
        $this->assertStringContainsString('Exception', $child, 'The bare-engine verdict is the base Exception — outside the RuntimeException family the guarded calls throw, never conflatable with a refusal.');
    }

    /**
     * OCR round 11 (t31-ocr11-5): the ancestor walk mangled a
     * not-yet-existing RELATIVE target — dirname('dst') === '.' is a
     * ONE-BYTE ancestor whose strlen ate the first byte of the
     * remainder ('dst' -> 'st', 'sub/dst' -> 'ub/dst'), so
     * target_real rode realpath('.') . 'st', a tree the caller never
     * named, and the containment verdicts judged (and refused —
     * driven red at HEAD) against a path that is not the target. The
     * walk judges an ABSOLUTE spelling now (cwd-prepended); the copy
     * keeps the caller's spelling and lands exactly where it always
     * landed.
     */
    public function testARelativeTargetIsJudgedAndLandsThroughItsTrueTree(): void
    {
        /*
         * The platform gate (OCR round 28, t31-ocr28-8): the whole
         * battery rides the cwd-prepend arm's premise — "a target not
         * starting with '/' is relative" is POSIX spelling, the same
         * premise the production owner now gates (t31-ocr28-3). On a
         * host whose platform separator is not the POSIX one the arm
         * would cwd-prepend the host's own absolute spellings into
         * garbage, and the legs would judge a mangled tree through no
         * defect of the contract they pin.
         */
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('The relative-target legs ride the cwd-prepend arm\'s POSIX spelling premise (the t31-ocr28-3 gate\'s own) — this host\'s platform separator is not the POSIX one.');
        }

        $base = sys_get_temp_dir() . '/wpct-copytree-relative-' . uniqid('', true);
        $w = $base . '/w';
        $from = $base . '/src';
        mkdir($w, 0755, true);
        mkdir($from . '/sub', 0755, true);
        $this->stage($from . '/sub/file.php', "SRC BYTES\n");

        $previous_cwd = (string) getcwd();
        try {
            // (a) THE WRONG-REFUSAL REPRO (red at HEAD): from a cwd of
            // …/w, the mangled target_real read …/w . 'st' = …/wst — a
            // source tree AT exactly that spelling made the MIRROR
            // guard refuse a legal copy naming a containment that
            // does not exist.
            mkdir($base . '/wst/src', 0755, true);
            $this->stage($base . '/wst/src/wst.php', "WST BYTES\n");
            chdir($w);
            WpHarness::copyTree($base . '/wst/src', 'dst');
            $this->assertFileExists($w . '/dst/wst.php', 'A relative target is judged through its TRUE tree — the first-byte-eaten spelling never refuses a legal copy.');

            // (b) The landing contract, one segment and deep: the copy
            // keeps the caller's spelling and lands byte-exact — the
            // 'st'/'ub' spellings the walk once computed never land.
            chdir($base);
            WpHarness::copyTree($from, 'dst');
            $this->assertSame('SRC BYTES', rtrim((string) file_get_contents($base . '/dst/sub/file.php')), 'A relative one-segment target lands at ./dst byte-exact.');
            WpHarness::copyTree($from, 'deep/dst');
            $this->assertFileExists($base . '/deep/dst/sub/file.php', 'A relative deep target lands at ./deep/dst byte-exact.');
            $this->assertFileDoesNotExist($base . '/st', 'The first-byte-eaten spelling never lands.');
            $this->assertFileDoesNotExist($base . '/ub/dst', 'The deep-eaten spelling never lands.');

            /*
             * The POSIX exactness of the cwd-prepend arm's platform gate
             * (OCR round 28, t31-ocr28-3, the driven half a POSIX host
             * CAN see): a Windows-absolute spelling ('C:\Temp\dst') is
             * NOT absolute here — no leading '/' — and the arm's
             * judgment for it is the RELATIVE one: cwd-prepended, judged
             * through its true tree, landed under the cwd. The gate
             * refuses that spelling only on the host whose platform
             * separator makes it absolute (isPosixHost() false, the
             * construction-evident half); here the leg pins that the
             * gate does NOT over-refuse — the arm stays exact for every
             * spelling its premise actually covers.
             */
            WpHarness::copyTree($from, 'C:\\Temp\\dst');
            $this->assertFileExists($base . '/C:\\Temp\\dst/sub/file.php', 'A drive-letter spelling is a LEGAL relative target on the POSIX host — cwd-prepended and landed, never a false platform refusal.');
        } finally {
            chdir($previous_cwd);
            WpHarness::releaseScratch($base);
        }
    }

    /**
     * OCR-round-33 pin (t31-ocr33-7): the guarded scratch release
     * never replaces the verdict in flight. PHP REPLACES — never
     * chains — an in-flight exception when finally throws, so an
     * environmental teardown failure (NFS/quota/antivirus lock,
     * stranded permission bits) once superseded the test's REAL
     * verdict at the finally line. The planted shape drives the
     * guard itself: the release throws the deterministic root
     * refusal (rrmdir over '/' refuses loudly on every host) while a
     * verdict is in flight — the guarded release surfaces it on
     * STDERR and the VERDICT is what surfaces to the catch. The
     * guard lives at the ONE shared owner now (t31-ocr34-4 —
     * WpHarness::releaseScratch(), this battery's former private
     * twin deleted in the same sweep); this leg drives that owner.
     */
    public function testTheGuardedReleaseNeverReplacesTheVerdictInFlight(): void
    {
        try {
            try {
                throw new RuntimeException('the real verdict');
            } finally {
                WpHarness::releaseScratch('/');
            }
        } catch (\Throwable $surfaced) {
            $this->assertSame('the real verdict', $surfaced->getMessage(), 'The guarded release surfaces the environmental failure on STDERR and never replaces the verdict in flight (unguarded, PHP would surface the rrmdir refusal instead).');

            return;
        }
        $this->fail('The planted in-flight verdict must surface.');
    }

    /**
     * OCR-round-38 pin (t31-ocr38-4): the release guard's diagnostic
     * resolves the STREAM, never the CLI constant. STDERR is defined
     * by the CLI SAPI only; in any other SAPI (cgi, fpm, a worker)
     * the bare fwrite raised an undefined-constant Error from inside
     * the very catch that exists to guarantee the guard never throws
     * — the t31-ocr33-7 verdict-replacement defect re-opened by the
     * guard's own diagnostic. Driven through the harness's own
     * subprocess idiom under the cgi SAPI beside the engine's own
     * binary (driven red at HEAD: the child fataled 'Undefined
     * constant "STDERR"' from inside the catch).
     *
     * OCR round 44 (t31-ocr44-7): the child once read its script
     * path from $argv — an ini premise, register_argc_argv being a
     * setting the CGI SAPI does NOT force, so on an install with it
     * off $argv was undefined, the child fataled at the require, and
     * the leg failed through assertSame(0, $exit) as an
     * environment-looking defect. The path rides the ENVIRONMENT now
     * (putenv before the spawn, getenv inside the child — the real
     * OS environment, read in every SAPI whatever variables_order
     * says); the leg's subject is the harness guard, never argv.
     */
    public function testTheReleaseGuardSpeaksASapiIndependentStreamVocabulary(): void
    {
        /*
         * The declaring TRIPLE (OCR round 46, t31-ocr46-7 — the
         * t31-ocr22-4 doctrine at this site): the guard named only
         * the spawn pair while the leg's first statement past the
         * probe is a putenv (the child's harness path rides the
         * ENVIRONMENT, t31-ocr44-7) — on a host with putenv in
         * disable_functions the call fataled before the child could
         * ever spawn, the redirected-TMPDIR siblings' own idiom
         * (canSpawnChildren('putenv')) the whole way.
         */
        if (! WpHarness::canSpawnChildren('putenv')) {
            $this->markTestSkipped('This host has exec/escapeshellarg/putenv in disable_functions — the non-CLI SAPI child sim cannot run (the child\'s harness path rides the environment through a parent-side putenv, so the declaring triple is the premise).');
        }
        $harnessPath = realpath(__DIR__ . '/harness/WpHarness.php');
        $this->assertNotFalse($harnessPath, 'The harness path must resolve before the child embed — an environment problem, never the harness defect the child would fatal as.');
        /*
         * The cgi SAPI beside the engine's own binary, probed by
         * SPAWNING it (the t31-ocr10-14 doctrine: the ANSWER is the
         * signal, never a file-check guess) — an install without it
         * cannot construct the non-CLI shape and skips visibly.
         */
        $cgi = dirname(PHP_BINARY) . '/php-cgi';
        exec(escapeshellarg($cgi) . ' -q -v', $probe_output, $probe_exit);
        if (0 !== $probe_exit) {
            $this->markTestSkipped('No php-cgi SAPI beside ' . PHP_BINARY . ' — the non-CLI shape is unconstructible on this install, so the t31-ocr38-4 SAPI pin cannot run on this runner.');
        }

        $child = sys_get_temp_dir() . '/wpct-release-cgi-' . uniqid('', true) . '.php';
        $this->stage($child, '<?php
require getenv("WPCT_RELEASE_CGI_HARNESS");
try {
    WpHarness::releaseScratch("/");
} catch (\Throwable $guard_threw) {
    echo "GUARD-THREW ", get_class($guard_threw), ": ", $guard_threw->getMessage(), "\n";
    exit(4);
}
echo "RETURNED\n";
');
        try {
            putenv('WPCT_RELEASE_CGI_HARNESS=' . $harnessPath);
            exec(escapeshellarg($cgi) . ' -q ' . escapeshellarg($child) . ' 2>&1', $output, $exit);
            $rendered = implode("\n", $output);
            $this->assertSame(0, $exit, "The guard never throws from inside its own catch in ANY SAPI — the child said: {$rendered}");
            $this->assertStringNotContainsString('GUARD-THREW', $rendered, 'The diagnostic must not throw the undefined-constant Error a non-CLI SAPI raises over the STDERR constant (red at HEAD: GUARD-THREW Error: Undefined constant "STDERR").');
            $this->assertStringContainsString('scratch release failed for /:', $rendered, 'The environmental diagnostic still surfaces — php://stderr answers in every SAPI, the refusal named, the verdict riding untouched.');
            $this->assertStringContainsString('RETURNED', $rendered, 'The release call returns normally behind the guard — the ocr33-7 contract holds in every SAPI.');
        } finally {
            putenv('WPCT_RELEASE_CGI_HARNESS');
            @unlink($child);
        }
    }

    /**
     * The battery's asserted stage write (OCR round 33, t31-ocr33-8,
     * the t31-ocr29-10 doctrine swept whole-file): a staging write
     * whose return rode unchecked surfaced as a misleading DOWNSTREAM
     * verdict — 'cannot relativize' over an empty tree, a missing-
     * file refusal over an absent stage, an ER_NOENT-shaped refusal
     * — instead of the staging error that actually happened. Every
     * leg stages through this one owner, the failure naming its own
     * path.
     */
    private function stage(string $path, string $bytes): void
    {
        $this->assertNotFalse(file_put_contents($path, $bytes), "Staging {$path} must land — a failed stage is the leg's own verdict, never a misleading downstream one.");
    }
}
