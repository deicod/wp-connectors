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
     * eaten, the nested file landing at the collapsed path. The
     * reproducer replays the ENTIRE source path as literal nested
     * directory names under vendor/ (a relative source root, or any
     * checkout whose full path repeats inside itself, is the same
     * shape); the prefix strip is positional now — position 0, exactly
     * once.
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
            $this->assertFileDoesNotExist($to . '/vendor/nested.php', 'The pre-fix str_replace() target (every occurrence stripped) must not appear.');
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
        if (! symlink('/usr/bin/true', $probe)) {
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
