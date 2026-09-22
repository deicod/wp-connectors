<?php
/**
 * WpHarness::lockForDenialProbe() pins (the ONE lock+probe+restore
 * choreography owner, glm24-9 — its own regressions live beside its
 * own subject).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HarnessDenialProbeTest extends TestCase
{
    /**
     * glm25-3: the lock and restore chmods ride the @-suppressed
     * spelling (the ocr42-8 idiom) — under the suite's
     * warning-to-exception regime an UNSUPPRESSED chmod that FAILS
     * (the staged directory not this process's to chmod — the not-owned
     * stat-able shape) threw the E_WARNING as an exception before the
     * probe logic ran, so the documented contract never lived: a failed
     * lock chmod must answer the probe HONESTLY (the directory never
     * locked, opendir() walks it open, the owner answers false, the
     * site's own skip fires), and the paired restore over the
     * never-locked directory must stay quiet the same way. Driven over
     * the filesystem root — stat-able by every user, chmod-refused for
     * every non-root one (the pre-flight flips a host whose user owns
     * the target to a named skip, root-shaped hosts included, so the
     * leg never mutates a directory it could actually lock).
     */
    public function testAFailedLockChmodAnswersTheProbeHonestly(): void
    {
        $refusal = '/';
        $current = fileperms($refusal) & 07777;
        if (@chmod($refusal, $current)) {
            $this->markTestSkipped('This process owns the probe target (it chmods its own current mode) — the chmod-refusal shape cannot be driven here.');
        }

        $this->assertFalse(
            WpHarness::lockForDenialProbe($refusal),
            'A failed lock chmod answers the probe honestly — the directory never locked, opendir() walks it open, the site\'s own skip fires (red at HEAD: the unsuppressed E_WARNING converted to an exception before the probe ran).'
        );
        $this->assertSame($current, fileperms($refusal) & 07777, 'The refused target is untouched — neither chmod changed a byte it could not.');
    }

    /**
     * glm25-5: the restore is the probed shape's OWN pre-state down to
     * the bits above 0777 — the mask is 07777 (setgid/setsticky ride
     * with the rwx family on the hosts that carry them; a 0777 mask
     * silently dropped them at the restore), and the 0755 fallback is
     * gone (its false arm was dead — fileperms()'s own warning fires
     * under the regime before the ternary could take it — and a
     * fabricated 0755 is never the shape's own). The restore path
     * itself only runs where the lock fails to deny this process
     * (uid-0/DAC-override hosts), so the pin rides the owner's own
     * spelling — the structural evidence, exactly-once.
     */
    public function testTheRestoreMaskKeepsTheShapesOwnPreStateBits(): void
    {
        $source = file_get_contents(__DIR__ . '/harness/WpHarness.php');
        $this->assertNotFalse($source, 'staging: the harness source must read — a staging failure fails as staging, never as the structural verdict.');

        $this->assertSame(
            1,
            substr_count($source, 'fileperms($dir) & 07777'),
            'The pre-state capture masks 07777 — the shape\'s OWN bits including the setgid/setsticky family above 0777 (a 0777 mask silently dropped them at the restore).'
        );
        $this->assertSame(
            0,
            substr_count($source, '? 0755 :'),
            'The dead 0755 fallback is gone — the false arm never ran (fileperms\'s own warning fires first) and a fabricated mode is never the shape\'s own pre-state.'
        );
    }
}
