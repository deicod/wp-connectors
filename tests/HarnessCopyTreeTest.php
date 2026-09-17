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
            $caught = null;
            try {
                WpHarness::copyTree($from . '/', $to);
            } catch (RuntimeException $e) {
                $caught = $e;
            }
            if (null === $caught) {
                $this->fail('A trailing-slash source must refuse the copy loudly, never nest every file under the target.');
            }
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
            // The verdict is asserted OUTSIDE the catch (t31-ocr5-3):
            // fail() throws AssertionFailedError, which EXTENDS
            // RuntimeException, and the old fail()-inside-try was
            // swallowed by the very catch meant for copyTree().
            $refuses = function (string $f, string $t, string $naming) use ($from): void {
                $caught = null;
                try {
                    WpHarness::copyTree($f, $t);
                } catch (RuntimeException $e) {
                    $caught = $e;
                }
                if (null === $caught) {
                    $this->fail($naming);
                }
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
            $refuses($from . '/src', '', 'An EMPTY target must refuse — the landing would be the filesystem root.');
            $refuses($from . '/src', '/', 'A root-only target must refuse — the landing would be the filesystem root.');
            $refuses($from . '/src', '//', 'A root-separators-only target must refuse — the landing would be the filesystem root.');

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
             * The verdict is asserted OUTSIDE the catch (t31-ocr5-3):
             * fail() throws AssertionFailedError, which EXTENDS
             * RuntimeException — the old fail()-inside-try was swallowed
             * by the very catch meant for copyTree(), so a no-throw
             * regression still failed the test but as a confusing
             * re-fail over the failure message, never the intended
             * expectation.
             */
            $refuses = function (string $from, string $linkName, string $expectation) use ($to): void {
                $caught = null;
                try {
                    WpHarness::copyTree($from, $to);
                } catch (RuntimeException $e) {
                    $caught = $e;
                }
                if (null === $caught) {
                    $this->fail($expectation);
                }
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
            $caught = null;
            try {
                WpHarness::copyTree($plain . '/root-link/', $to);
            } catch (RuntimeException $e) {
                $caught = $e;
            }
            if (null === $caught) {
                $this->fail('A TRAILING-SLASH symlinked SOURCE ROOT must refuse the copy — a slash is not a disguise.');
            }
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
            $caught = null;
            try {
                WpHarness::copyTree($plain . '/root-link/.', $to);
            } catch (RuntimeException $e) {
                $caught = $e;
            }
            if (null === $caught) {
                $this->fail('A \'/.\'-spelled symlinked SOURCE ROOT must refuse the copy — a dot is not a disguise either.');
            }
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
            $caught = null;
            try {
                WpHarness::copyTree($plain . '/root-link/..', $to);
            } catch (RuntimeException $e) {
                $caught = $e;
            }
            if (null === $caught) {
                $this->fail('A \'/..\'-spelled symlinked SOURCE ROOT must refuse the copy — the parent it names is the TARGET\'S parent, a larger blast radius than the target.');
            }
            $this->assertStringContainsString('symlinked source tree', $caught->getMessage(), 'The verdict names the LINK class for the \'/..\' spelling too.');
            $this->assertStringContainsString('root-link', $caught->getMessage());

            $dotdotHolder = sys_get_temp_dir() . '/wpct-copytree-dotdot-' . uniqid('', true);
            $dotdotOut = sys_get_temp_dir() . '/wpct-copytree-dotdot-out-' . uniqid('', true);
            mkdir($dotdotHolder . '/tree', 0755, true);
            file_put_contents($dotdotHolder . '/tree/real.php', 'real bytes');
            WpHarness::copyTree($dotdotHolder . '/tree/..', $dotdotOut);
            $this->assertFileExists($dotdotOut . '/tree/real.php', 'A \'/..\'-spelled REAL source keeps copying the tree it names — the probe is the only judgment that changed.');
            WpHarness::rrmdir($dotdotHolder);
            WpHarness::rrmdir($dotdotOut);

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
