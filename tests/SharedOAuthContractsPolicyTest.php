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
use Deicod\WpConnectors\Shared\Support\InstantArithmetic;
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

    /**
     * Review-round pin (t31-r1-1..3 follow-up, self-review): the
     * shared arithmetic helper's first form still rode modify() in its
     * UTC projection, and modify() SATURATES silently far inside the
     * int domain — a ~trillion-second offset applied NO shift at all
     * (and one near PHP_INT_MAX clamped to ~1372 years), so a giant
     * but constructible skew handed should_refresh() a confidently
     * wrong threshold. The arithmetic runs on raw integer timestamps
     * now; the flip point is exact at every magnitude, and the one
     * unrepresentable edge (int-domain overflow) rejects loudly.
     */
    public function testRefreshThresholdStaysExactAtSaturationScaleSkews(): void
    {
        $obtained = new \DateTimeImmutable('2026-09-13T10:00:00+00:00');
        $expires_in = 3600;
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, $expires_in, $obtained);

        foreach (array(31536000000 /* 1000 years */, 10000000000000 /* the old saturation shape */, PHP_INT_MAX) as $skew) {
            $policy = new RefreshPolicy($skew, 1, 60);
            $threshold_ts = $obtained->getTimestamp() + $expires_in - $skew;

            $this->assertFalse(
                $policy->should_refresh($set, new \DateTimeImmutable('@' . ($threshold_ts - 1))),
                sprintf('Skew %d: one second before the true far-past threshold must not refresh.', $skew)
            );
            $this->assertTrue(
                $policy->should_refresh($set, new \DateTimeImmutable('@' . $threshold_ts)),
                sprintf('Skew %d: the true threshold itself must refresh.', $skew)
            );
        }
    }

    /**
     * Fix-round pin (t31-r3-3): the predicate is TOTAL — it returns
     * bool for every constructible policy × token set. The extreme
     * corners combine legally (verified by execution at HEAD): a
     * year-0000-floor obtained-at reading with a PHP_INT_MAX skew
     * drove expiry-minus-skew below the representable instant range,
     * and a global InvalidArgumentException escaped the bool predicate
     * (outside the OAuth family, no @throws — Task 3.3's coordinator
     * would crash on an unhandled type). The corner is decided inside
     * the predicate now (a threshold before every representable
     * instant is a threshold every reading has reached), and the
     * largest skew whose threshold STAYS representable keeps the exact
     * flip point.
     */
    public function testThePredicateIsTotalAtTheExtremeCorners(): void
    {
        // Direction one: the unrepresentable threshold. Obtained-at at
        // the year-0000 serialization floor (the earliest constructible
        // reading) with a PHP_INT_MAX skew — the arithmetic's overflow
        // guard would throw; the predicate answers deterministically.
        $floorTimestamp = -62167219200;
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, new \DateTimeImmutable('@' . $floorTimestamp));
        $policy = new RefreshPolicy(PHP_INT_MAX, 1, 60);

        $this->assertTrue(
            $policy->should_refresh($set, new \DateTimeImmutable('@' . $floorTimestamp)),
            'A threshold below every representable instant is reached by every reading — refresh due, never a throw.'
        );
        $this->assertTrue($policy->should_refresh($set, new \DateTimeImmutable('2026-09-14T00:00:00+00:00')));

        // The same floor with a REPRESENTABLE threshold keeps the exact
        // rule (the corner decision must not widen past its boundary).
        $zeroSkew = new RefreshPolicy(0, 1, 60);
        $this->assertFalse($zeroSkew->should_refresh($set, new \DateTimeImmutable('@' . ($floorTimestamp + 3599))));
        $this->assertTrue($zeroSkew->should_refresh($set, new \DateTimeImmutable('@' . ($floorTimestamp + 3600))));

        // Direction two: the largest skew whose threshold stays
        // representable from the LATEST legal expiry (obtained-at so
        // the derived expiry lands exactly on the year-9999 ceiling) —
        // the flip point is exact, boundary readings refresh.
        $latestObtained = 253402300799 - 3600;
        $latest = new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, new \DateTimeImmutable('@' . $latestObtained));
        $edgePolicy = new RefreshPolicy(PHP_INT_MAX, 1, 60);
        $thresholdTimestamp = $latestObtained + 3600 - PHP_INT_MAX;

        $this->assertFalse(
            $edgePolicy->should_refresh($latest, new \DateTimeImmutable('@' . ($thresholdTimestamp - 1))),
            'One second before the true far-past threshold must not refresh.'
        );
        $this->assertTrue(
            $edgePolicy->should_refresh($latest, new \DateTimeImmutable('@' . $thresholdTimestamp)),
            'The true threshold itself must refresh (boundary readings refresh).'
        );
    }

    /**
     * OCR-round-2 pin (t31-ocr2-6): the totality guard consumes the
     * arithmetic's OWN named predicate now
     * (InstantArithmetic::offset_would_underflow()) — the guard's lower
     * leg and the pre-check are one condition with one owner, never
     * two hand-spelled inequalities free to drift. The EXACT boundary —
     * expires_ts == PHP_INT_MIN + skew, the largest constructible
     * spelling (a year-0000-floor reading) — pins identical behavior:
     * the predicate answers false there (the threshold is exactly
     * representable), the normal path computes it and lands on
     * PHP_INT_MIN itself, one second of skew further out flips to the
     * corner branch, and the POLICY answers the same on both sides.
     */
    public function testTheTotalityBoundaryRidesTheNamedUnderflowPredicate(): void
    {
        // The earliest constructible expiry: obtained-at at the
        // year-0000 serialization floor, one-hour lifetime.
        $expiry = -62167219200 + 3600;
        $boundary_skew = $expiry - PHP_INT_MIN; // threshold == PHP_INT_MIN exactly.
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, new \DateTimeImmutable('@' . (-62167219200)));

        // The predicate flips exactly at the boundary the guard owns.
        $this->assertFalse(
            InstantArithmetic::offset_would_underflow($expiry, -$boundary_skew),
            'At threshold == PHP_INT_MIN the shift is still representable — no underflow.'
        );
        $this->assertTrue(
            InstantArithmetic::offset_would_underflow($expiry, -($boundary_skew + 1)),
            'One second of skew further out, the shift leaves the int-timestamp domain.'
        );

        // The normal path computes the boundary threshold exactly.
        $this->assertSame(
            PHP_INT_MIN,
            InstantArithmetic::minus_seconds(new \DateTimeImmutable('@' . $expiry), $boundary_skew)->getTimestamp(),
            'The boundary threshold is PHP_INT_MIN itself, computed by the guarded arithmetic.'
        );

        // The policy answers identically on both sides of the boundary.
        $at = new RefreshPolicy($boundary_skew, 1, 60);
        $this->assertTrue(
            $at->should_refresh($set, new \DateTimeImmutable('@' . (-62167219200))),
            'At the boundary every representable reading is at/past the PHP_INT_MIN threshold — refresh due, through the normal path.'
        );
        $beyond = new RefreshPolicy($boundary_skew + 1, 1, 60);
        $this->assertTrue(
            $beyond->should_refresh($set, new \DateTimeImmutable('@' . (-62167219200))),
            'One skew further out, the corner branch answers the same.'
        );
        $this->assertTrue($beyond->should_refresh($set, new \DateTimeImmutable('2026-09-14T00:00:00+00:00')));
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
