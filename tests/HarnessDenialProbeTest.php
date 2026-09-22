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
}
