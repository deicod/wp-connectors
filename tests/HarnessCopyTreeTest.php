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
        file_put_contents($from . '/plain.php', 'plain bytes');
        file_put_contents($nested . '/nested.php', 'nested bytes');

        try {
            WpHarness::copyTree($from, $to);

            $this->assertFileExists($to . '/plain.php');
            $this->assertSame('nested bytes', (string) file_get_contents($to . '/vendor' . $from . '/nested.php'), 'The nested same-name path keeps its exact position — only the SOURCE prefix strips, never a nested repetition.');
            $this->assertFileDoesNotExist($to . '/vendornested.php', 'The pre-fix str_replace() glue target (every occurrence stripped, the halves fused) must not appear — this leg is the regression detector for a return to str_replace().');
        } finally {
            WpHarness::rrmdir($holder);
            WpHarness::rrmdir($to);
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
        file_put_contents($from . '/src/file.php', 'bytes');

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
            WpHarness::rrmdir($from);
            WpHarness::rrmdir($to);
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
        file_put_contents($from . '/src/file.php', 'original bytes');
        $file_source = $from . '/plain.txt';
        file_put_contents($file_source, 'a file, not a tree');

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
            file_put_contents($from . '/src/src/nested.php', 'nested bytes');
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
            WpHarness::rrmdir($from);
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
        file_put_contents($from . '/src/file.php', 'original bytes');

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
            WpHarness::rrmdir($from);
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
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }

        $plain = sys_get_temp_dir() . '/wpct-copytree-link-' . uniqid('', true);
        mkdir($plain . '/src', 0755, true);
        file_put_contents($plain . '/src/real.php', 'real bytes');

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
                file_put_contents($dotdotHolder . '/tree/real.php', 'real bytes');
                WpHarness::copyTree($dotdotHolder . '/tree/..', $dotdotOut);
                $this->assertFileExists($dotdotOut . '/tree/real.php', 'A \'/..\'-spelled REAL source keeps copying the tree it names — the probe is the only judgment that changed.');
            } finally {
                WpHarness::rrmdir($dotdotHolder);
                WpHarness::rrmdir($dotdotOut);
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
            WpHarness::rrmdir($plain);
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
        file_put_contents($base . '/real/scratch/sub/x.txt', 'bytes');
        mkdir($base . '/real/copy-src', 0755, true);
        file_put_contents($base . '/real/copy-src/f.php', 'copy bytes');
        mkdir($base . '/real/victim', 0755, true);
        file_put_contents($base . '/real/victim/keep.txt', 'survivor');
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
            WpHarness::rrmdir($base);
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
        file_put_contents($base . '/real/src/f.php', 'layout bytes');
        mkdir($base . '/real/gone', 0755, true);
        file_put_contents($base . '/real/gone/x.txt', 'bytes');
        mkdir($base . '/real/deep', 0755, true);
        mkdir($base . '/real/victim', 0755, true);
        file_put_contents($base . '/real/victim/keep.txt', 'survivor');
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
            WpHarness::rrmdir($base);
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
        file_put_contents($from . '/sub/file.php', "SRC BYTES\n");

        $previous_cwd = (string) getcwd();
        try {
            // (a) THE WRONG-REFUSAL REPRO (red at HEAD): from a cwd of
            // …/w, the mangled target_real read …/w . 'st' = …/wst — a
            // source tree AT exactly that spelling made the MIRROR
            // guard refuse a legal copy naming a containment that
            // does not exist.
            mkdir($base . '/wst/src', 0755, true);
            file_put_contents($base . '/wst/src/wst.php', "WST BYTES\n");
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
            WpHarness::rrmdir($base);
        }
    }
}
