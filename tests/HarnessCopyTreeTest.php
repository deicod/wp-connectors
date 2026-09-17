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
            $refuses('/', $from . '/dst-root-src', 'A source collapsed to the filesystem ROOT must refuse — the universal container is not a copyable tree.');
            $refuses('/..', $from . '/dst-root-src', 'A \'/..\'-spelled source resolves to the filesystem ROOT on every POSIX host — the same refusal.');
            $this->assertFileDoesNotExist($from . '/dst-root-src', 'The root-source refusal moved no byte — the target was never created, never populated.');

            // (b) The self-copy: the target IS the source — a silent
            // no-op success pre-round (the engine's same-file mercy,
            // probed, never a contract).
            $refuses($from . '/src', $from . '/src', 'A self-copy must refuse — pre-round it returned normally having copied nothing, a silent wrong outcome.');

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
                $this->assertSame(
                    '/',
                    realpath($rootOnly),
                    'The leg\'s own precondition: the root-separator spelling resolves to the filesystem ROOT the guard refuses — a spelling resolving elsewhere would point this leg\'s landing at a real tree.'
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
        $to = $plain . '/dst';

        try {
            /*
             * The verdict rides the ONE refusal owner (t31-ocr15-7,
             * replacing the t31-ocr5-3 inline shape this closure
             * hand-rolled): the family the original catch declared
             * (RuntimeException) rides the third parameter, and the
             * no-throw case fails outside any catch.
             */
            $refuses = function (string $from, string $linkName, string $expectation) use ($to): void {
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
                fn() => WpHarness::copyTree($plain . '/root-link/', $to),
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
                fn() => WpHarness::copyTree($plain . '/root-link/.', $to),
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
                fn() => WpHarness::copyTree($plain . '/root-link/..', $to),
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
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }
        /*
         * The exec-capability guard (t31-ocr18-2, the t31-ocr16-12
         * doctrine over this child-process consumer): the sim's whole
         * premise is a FRESH engine reading TMPDIR before anything
         * caches it, and on a disable_functions host the spawn was an
         * undefined-function \Error instead of the visible skip.
         */
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the redirected-TMPDIR sim cannot run (the anchor verdicts ride a child process).');
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
            $script = 'putenv("TMPDIR=" . ' . var_export($base . '/anchor-link', true) . ');'
                . ' require ' . var_export(realpath(__DIR__ . '/harness/WpHarness.php'), true) . ';'
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
        } finally {
            chdir($previous_cwd);
            WpHarness::rrmdir($base);
        }
    }
}
