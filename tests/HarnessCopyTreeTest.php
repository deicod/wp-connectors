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

            // (b) The self-copy: the target IS the source — a silent
            // no-op success pre-round (the engine's same-file mercy,
            // probed, never a contract).
            $refuses($from . '/src', $from . '/src', 'A self-copy must refuse — pre-round it returned normally having copied nothing, a silent wrong outcome.');

            // (b) The nested target: the destination sits inside the
            // source the lazy iterator is walking — the copy lands in
            // the tree under test.
            $refuses($from . '/src', $from . '/src/inside', 'A target inside the source must refuse — the copy would land inside the very tree it reads.');

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
        $probe = sys_get_temp_dir() . '/wpct-copytree-probe-' . uniqid('', true);
        // @-suppressed (t31-ocr6-14): a failing symlink() raises
        // E_WARNING and the suite's warning conversion errors the test
        // at the call line, never reaching this skip — the false
        // return is the probe's signal, the diagnostic is not.
        if (! @symlink('/usr/bin/true', $probe)) {
            $this->markTestSkipped('This host cannot create symlinks.');
        }
        unlink($probe);

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
}
