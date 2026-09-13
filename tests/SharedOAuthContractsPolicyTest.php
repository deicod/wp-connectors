<?php
/**
 * Contract tests: shared refresh policy VO (Task 3.1).
 *
 * Bound validation (negative/absurd caps rejected) and the two pure
 * rules the policy owns: expiry-minus-skew and the Retry-After cap that
 * governs both provider-supplied forms.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Policy\RefreshPolicy;
use Deicod\WpConnectors\Shared\Token\AccessTokenSet;

final class SharedOAuthContractsPolicyTest extends WpConnectorsTestCase
{
    private function tokenSetExpiringIn(int $expires_in): AccessTokenSet
    {
        return new AccessTokenSet(
            FakeSecrets::accessToken(),
            null,
            $expires_in,
            new \DateTimeImmutable('2026-09-13T10:00:00+00:00')
        );
    }

    public function testValidPolicyCarriesItsBounds(): void
    {
        $policy = new RefreshPolicy(120, 2, 60);

        $this->assertSame(120, $policy->refresh_skew_seconds());
        $this->assertSame(2, $policy->backoff_initial_seconds());
        $this->assertSame(60, $policy->cooldown_cap_seconds());
    }

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: string}>
     */
    public function invalidBoundsProvider(): array
    {
        return array(
            'negative skew' => array(-1, 2, 60, 'skew must be non-negative'),
            'zero initial backoff' => array(0, 0, 60, 'initial backoff must be positive'),
            'negative initial backoff' => array(0, -2, 60, 'initial backoff must be positive'),
            'zero cap' => array(120, 2, 0, 'cap must be positive'),
            'negative cap' => array(120, 2, -60, 'cap must be positive'),
            'cap below initial backoff' => array(120, 30, 29, 'cap must be at least the initial backoff'),
        );
    }

    /**
     * @dataProvider invalidBoundsProvider
     *
     * @param int    $skew    Refresh skew seconds.
     * @param int    $initial Initial backoff seconds.
     * @param int    $cap     Cooldown cap seconds.
     * @param string $fragment Expected message fragment.
     */
    public function testAbsurdOrNegativeBoundsAreRejected(int $skew, int $initial, int $cap, string $fragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($fragment);

        new RefreshPolicy($skew, $initial, $cap);
    }

    public function testZeroSkewIsAcceptable(): void
    {
        $this->assertSame(0, (new RefreshPolicy(0, 1, 1))->refresh_skew_seconds());
    }

    public function testCapEqualToInitialBackoffIsAcceptable(): void
    {
        $this->assertSame(5, (new RefreshPolicy(0, 5, 5))->cooldown_cap_seconds());
    }

    /* ---------------------------------------------------------------
     * Expiry-minus-skew rule.
     * ---------------------------------------------------------------
     */

    public function testRefreshIsDueAtTheSkewBoundary(): void
    {
        $policy = new RefreshPolicy(120, 2, 60);
        $clock = new DeterministicClock(new \DateTimeImmutable('2026-09-13T10:00:00+00:00'));
        $set = $this->tokenSetExpiringIn(3600); // expires 11:00:00, threshold 10:58:00

        $clock->advanceBy(3479);
        $this->assertFalse($policy->should_refresh($set, $clock->now()));

        $clock->advanceBy(1); // exactly expiry minus skew
        $this->assertTrue($policy->should_refresh($set, $clock->now()));
    }

    public function testRefreshIsDueAfterExpiryRegardlessOfSkew(): void
    {
        $policy = new RefreshPolicy(3600, 2, 60);
        $set = $this->tokenSetExpiringIn(60);

        $this->assertTrue(
            $policy->should_refresh($set, new \DateTimeImmutable('2026-09-13T10:05:00+00:00'))
        );
    }

    public function testZeroSkewRefreshesOnlyAtExpiry(): void
    {
        $policy = new RefreshPolicy(0, 2, 60);
        $set = $this->tokenSetExpiringIn(60);

        $this->assertFalse($policy->should_refresh($set, new \DateTimeImmutable('2026-09-13T10:00:59+00:00')));
        $this->assertTrue($policy->should_refresh($set, new \DateTimeImmutable('2026-09-13T10:01:00+00:00')));
    }

    public function testLargeSkewRefreshesEarly(): void
    {
        // A documented provider shape: tokens lasting hours, refreshed
        // up to an hour early to keep scheduled usage warm.
        $policy = new RefreshPolicy(3600, 2, 60);
        $set = $this->tokenSetExpiringIn(21600); // 6h token, threshold at 5h

        $this->assertFalse($policy->should_refresh($set, new \DateTimeImmutable('2026-09-13T14:59:59+00:00')));
        $this->assertTrue($policy->should_refresh($set, new \DateTimeImmutable('2026-09-13T15:00:00+00:00')));
    }

    /**
     * Review-round pin (t31-r1-3): the expiry-minus-skew threshold is
     * absolute elapsed time. The spring-forward set's expiry renders in
     * the post-transition offset; a wall-clock subtraction from it lands
     * inside the skipped hour and normalizes an hour late, opening the
     * refresh window late. The flip point is obtained + expires_in -
     * skew, exactly, on both sides of every transition in the window.
     */
    public function testRefreshThresholdIsAbsoluteSecondsAcrossDstTransitions(): void
    {
        $obtained = new \DateTimeImmutable('2026-03-29 01:30:00', new \DateTimeZone('Europe/Berlin')); // 00:30Z, pre-gap
        $expires_in = 7200; // Expiry at 02:30Z, rendered 04:30+02:00 — the window crosses the gap.
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, $expires_in, $obtained);

        foreach (array(0, 1800, 7200) as $skew) {
            $policy = new RefreshPolicy($skew, 1, 60);
            $threshold_ts = $obtained->getTimestamp() + $expires_in - $skew;

            $this->assertFalse(
                $policy->should_refresh($set, new \DateTimeImmutable('@' . ($threshold_ts - 1))),
                sprintf('Skew %d: one second before the absolute threshold must not refresh.', $skew)
            );
            $this->assertTrue(
                $policy->should_refresh($set, new \DateTimeImmutable('@' . $threshold_ts)),
                sprintf('Skew %d: the absolute threshold itself must refresh (boundary readings refresh).', $skew)
            );
        }
    }

    /* ---------------------------------------------------------------
     * Retry-After capping (both provider forms share the cap).
     * ---------------------------------------------------------------
     */

    public function testRetryAfterBelowTheCapIsHonoredAsIs(): void
    {
        $policy = new RefreshPolicy(120, 2, 60);

        $this->assertSame(2, $policy->capped_retry_after_seconds(2));
        $this->assertSame(59, $policy->capped_retry_after_seconds(59));
        $this->assertSame(0, $policy->capped_retry_after_seconds(0));
    }

    public function testOversizedRetryAfterIsCapped(): void
    {
        // An oversized throttle (either form — seconds or HTTP-date
        // parsed to seconds) must not suppress refreshes far past the
        // outage.
        $policy = new RefreshPolicy(120, 2, 60);

        $this->assertSame(60, $policy->capped_retry_after_seconds(61));
        $this->assertSame(60, $policy->capped_retry_after_seconds(86400));
        $this->assertSame(60, $policy->capped_retry_after_seconds(PHP_INT_MAX));
    }

    public function testNegativeRetryAfterClampsToZero(): void
    {
        $this->assertSame(0, (new RefreshPolicy(0, 1, 60))->capped_retry_after_seconds(-5));
    }
}
