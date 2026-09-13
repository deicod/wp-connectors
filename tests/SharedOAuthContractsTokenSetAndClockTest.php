<?php
/**
 * Contract tests: shared token-set value object and clock port (Task 3.1).
 *
 * Validation matrix, serialization round trip, immutability pins, and the
 * clock port's two implementations (system sanity + deterministic control).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Clock\ClockInterface;
use Deicod\WpConnectors\Shared\Clock\SystemClock;
use Deicod\WpConnectors\Shared\Support\InstantArithmetic;
use Deicod\WpConnectors\Shared\Token\AccessTokenSet;

final class SharedOAuthContractsTokenSetAndClockTest extends WpConnectorsTestCase
{
    private function obtainedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-13T10:00:00.000000+00:00');
    }

    /* ---------------------------------------------------------------
     * Validation matrix.
     * ---------------------------------------------------------------
     */

    public function testEmptyAccessTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessTokenSet('', null, 3600, $this->obtainedAt());
    }

    public function testWhitespaceOnlyAccessTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessTokenSet("  \t\n ", null, 3600, $this->obtainedAt());
    }

    public function testZeroExpiresInIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessTokenSet(FakeSecrets::accessToken(), null, 0, $this->obtainedAt());
    }

    public function testNegativeExpiresInIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessTokenSet(FakeSecrets::accessToken(), null, -1, $this->obtainedAt());
    }

    /**
     * Review-round pin (t31-r1-4): expires_in had no upper bound, and an
     * astronomically large int saturated modify() silently to a zero
     * delta — an expiry equal to the reading itself, stored and
     * round-tripped with every gate green. The bound is the derived
     * expiry's serializability: the canonical rendering carries a
     * four-digit year, so obtained-at plus expires_in must stay within
     * the last UTC second of year 9999. The boundary below is exact:
     * 253402300799 seconds from the 1970 epoch reading IS that second.
     */
    public function testExpiresInDerivingPastTheSerializableCeilingIsRejected(): void
    {
        $at = new \DateTimeImmutable('1970-01-01T00:00:00.000000+00:00');

        // One second past the ceiling: rejected with the documented
        // rejection (never a silent saturation).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serializable range');
        new AccessTokenSet(FakeSecrets::accessToken(), null, 253402300800, $at);
    }

    public function testExpiresInDerivingExactlyTheSerializableCeilingIsAcceptedAndRoundTrips(): void
    {
        $at = new \DateTimeImmutable('1970-01-01T00:00:00.000000+00:00');
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 253402300799, $at);

        $this->assertSame('9999-12-31T23:59:59.000000+00:00', $set->expires_at()->format(AccessTokenSet::SERIAL_INSTANT_FORMAT));
        $this->assertSame($set->to_array(), AccessTokenSet::from_array($set->to_array())->to_array());

        // The finding's shape: an int so large the old derivation
        // saturated to a zero delta. Also rejected — as is any lifetime
        // past the ceiling from a far-future reading.
        foreach (array(10000000000000, PHP_INT_MAX) as $oversized) {
            try {
                new AccessTokenSet(FakeSecrets::accessToken(), null, $oversized, $at);
                $this->fail(sprintf('An expires_in of %d must be rejected before the derivation saturates.', $oversized));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('serializable range', $e->getMessage());
            }
        }

        try {
            new AccessTokenSet(FakeSecrets::accessToken(), null, 1, new \DateTimeImmutable('9999-12-31T23:59:59.000000+00:00'));
            $this->fail('A lifetime crossing the ceiling from a far-future reading must be rejected too.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('serializable range', $e->getMessage());
        }
    }

    /**
     * Verifier-round pin (t31-r1-20): the serializability bound was
     * ceiling-only — a BCE obtained-at reading (which 64-bit DateTime
     * represents) constructed fine, to_array() rendered a SIGNED year
     * ('-1199-02-15T...'), and from_array() rejected its own payload:
     * a grant that saves but is permanently unloadable, the exact
     * fail-closed-unavailability shape t31-r1-2 closed for DST. Year
     * 0000 renders a legal four-digit spelling and stays inside.
     */
    public function testBceObtainedAtReadingsAreRejectedAtConstruction(): void
    {
        $floor = new \DateTimeImmutable('@-62167219200'); // 0000-01-01T00:00:00Z
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, $floor);

        $this->assertSame('0000-01-01T01:00:00.000000+00:00', $set->expires_at()->format(AccessTokenSet::SERIAL_INSTANT_FORMAT));
        $this->assertSame($set->to_array(), AccessTokenSet::from_array($set->to_array())->to_array());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serializable range');
        new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, new \DateTimeImmutable('@-62167219201'));
    }

    public function testEmptyStringRefreshTokenIsRejectedDistinctFromNull(): void
    {
        // '' is NOT the "no replacement token" spelling — null is.
        $this->expectException(\InvalidArgumentException::class);
        new AccessTokenSet(FakeSecrets::accessToken(), '', 3600, $this->obtainedAt());
    }

    public function testWhitespaceRefreshTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessTokenSet(FakeSecrets::accessToken(), '   ', 3600, $this->obtainedAt());
    }

    public function testValidSetCarriesItsFacts(): void
    {
        $access = FakeSecrets::accessToken();
        $refresh = FakeSecrets::refreshToken();
        $at = $this->obtainedAt();

        $set = new AccessTokenSet($access, $refresh, 3600, $at);

        $this->assertSame($access, $set->access_token());
        $this->assertSame($refresh, $set->refresh_token());
        $this->assertTrue($set->has_refresh_token());
        $this->assertSame(3600, $set->expires_in());
        $this->assertSame($at, $set->obtained_at());
    }

    public function testNullRefreshTokenIsADistinctModelledState(): void
    {
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, $this->obtainedAt());

        $this->assertNull($set->refresh_token());
        $this->assertFalse($set->has_refresh_token());
    }

    public function testExpiryIsDerivedFromObtainedAtPlusExpiresIn(): void
    {
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 120, $this->obtainedAt());

        $this->assertSame('2026-09-13T10:02:00.000000+00:00', $set->expires_at()->format(AccessTokenSet::SERIAL_INSTANT_FORMAT));
        // Derived once at construction: repeated reads return the same instant.
        $this->assertSame($set->expires_at(), $set->expires_at());
    }

    public function testSubSecondObtainedAtSurvivesExpiryDerivation(): void
    {
        $at = new \DateTimeImmutable('2026-09-13T10:00:00.250000+00:00');
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 1, $at);

        $this->assertSame('2026-09-13T10:00:01.250000+00:00', $set->expires_at()->format(AccessTokenSet::SERIAL_INSTANT_FORMAT));
    }

    /* ---------------------------------------------------------------
     * DST-proof derivation (review round t31-r1).
     * ---------------------------------------------------------------
     */

    /**
     * Review-round pin (t31-r1-1 + t31-r1-2): named-timezone readings
     * whose lifetime crosses a DST transition — spring-forward AND
     * fall-back, northern AND southern zones. Wall-clock arithmetic in
     * the reading's zone drifts by the transition delta (a spring
     * 7200-second lifetime really spans 3600 absolute seconds, so a
     * modify()-derived expiry is an hour early and the payload's own
     * round trip then disagrees with its re-derived expiry); the
     * derivation is absolute elapsed time, and the serialized spelling
     * is canonical UTC so the strict round trip never depends on zone
     * context a payload cannot carry.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: string}>
     */
    public function dstTransitionProvider(): array
    {
        return array(
            'berlin spring-forward' => array('Europe/Berlin', '2026-03-29 01:30:00.250000', 7200, '2026-03-29T00:30:00.250000+00:00', '2026-03-29T02:30:00.250000+00:00'),
            'berlin fall-back' => array('Europe/Berlin', '2026-10-25 01:00:00', 3600, '2026-10-24T23:00:00.000000+00:00', '2026-10-25T00:00:00.000000+00:00'),
            'new york spring-forward' => array('America/New_York', '2026-03-08 01:30:00', 7200, '2026-03-08T06:30:00.000000+00:00', '2026-03-08T08:30:00.000000+00:00'),
            'new york fall-back' => array('America/New_York', '2026-11-01 00:30:00', 3600, '2026-11-01T04:30:00.000000+00:00', '2026-11-01T05:30:00.000000+00:00'),
            'sydney spring-forward' => array('Australia/Sydney', '2026-10-04 01:30:00', 7200, '2026-10-03T15:30:00.000000+00:00', '2026-10-03T17:30:00.000000+00:00'),
            'sydney fall-back' => array('Australia/Sydney', '2026-04-05 01:30:00', 3600, '2026-04-04T14:30:00.000000+00:00', '2026-04-04T15:30:00.000000+00:00'),
        );
    }

    /**
     * @dataProvider dstTransitionProvider
     *
     * @param string $zone                 Named timezone of the reading.
     * @param string $wall_reading         Unambiguous local wall time before the transition.
     * @param int    $expires_in           Lifetime crossing the transition.
     * @param string $expected_obtained_utc Canonical UTC serialization of the reading.
     * @param string $expected_expiry_utc  Canonical UTC serialization of the derived expiry.
     */
    public function testExpiryAcrossDstTransitionsIsAbsoluteElapsedSecondsAndRoundTrips(
        string $zone,
        string $wall_reading,
        int $expires_in,
        string $expected_obtained_utc,
        string $expected_expiry_utc
    ): void {
        $obtained_at = new \DateTimeImmutable($wall_reading, new \DateTimeZone($zone));
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, $expires_in, $obtained_at);

        // Absolute elapsed seconds, not wall-clock: the delta is exactly
        // expires_in even though the window crosses a transition.
        $this->assertSame(
            $expires_in,
            $set->expires_at()->getTimestamp() - $set->obtained_at()->getTimestamp(),
            'The derived expiry must be exactly expires_in absolute seconds after the reading.'
        );

        // The reading's timezone stays attached to the derived expiry.
        $this->assertSame($zone, $set->expires_at()->getTimezone()->getName());

        // The serialization is canonical UTC — DST-unambiguous spellings.
        $data = $set->to_array();
        $this->assertSame($expected_obtained_utc, $data['obtained_at']);
        $this->assertSame($expected_expiry_utc, $data['expires_at']);

        // Exact round trip: from_array() accepts (and re-derives to) the
        // payload to_array() produced across the transition.
        $this->assertSame($data, AccessTokenSet::from_array($data)->to_array());
    }

    /**
     * Review-round pin (t31-r1-2): a named-timezone set whose derivation
     * crossed a transition must load back from its own serialization —
     * the pre-fix from_array() rejected exactly this payload because the
     * offset-only re-parse re-derived a different instant.
     */
    public function testNamedTimezoneSetSurvivesItsOwnSerializationRoundTrip(): void
    {
        $set = new AccessTokenSet(
            FakeSecrets::accessToken(),
            FakeSecrets::refreshToken(),
            7200,
            new \DateTimeImmutable('2026-03-29 01:30:00', new \DateTimeZone('Europe/Berlin'))
        );

        $restored = AccessTokenSet::from_array($set->to_array());

        $this->assertSame($set->to_array(), $restored->to_array());
        $this->assertEquals($set->expires_at(), $restored->expires_at());
    }

    /**
     * Follow-up pin (self-review): pre-epoch readings (negative
     * timestamps) derive exactly and round-trip through the canonical
     * UTC spelling — the timestamp-based arithmetic carries no
     * epoch-origin assumption.
     */
    public function testPreEpochReadingDerivesExactlyAndRoundTrips(): void
    {
        $obtained = new \DateTimeImmutable('1900-01-01T00:00:00.250000+00:00');
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 86400, $obtained);

        $this->assertSame(86400, $set->expires_at()->getTimestamp() - $set->obtained_at()->getTimestamp());
        $this->assertSame('1900-01-02T00:00:00.250000+00:00', $set->expires_at()->format(AccessTokenSet::SERIAL_INSTANT_FORMAT));
        $this->assertSame($set->to_array(), AccessTokenSet::from_array($set->to_array())->to_array());
    }

    /* ---------------------------------------------------------------
     * Refresh-token merge semantics.
     * ---------------------------------------------------------------
     */

    public function testNullReplacementKeepsStoredRefreshToken(): void
    {
        $stored = FakeSecrets::refreshToken();
        $set = new AccessTokenSet(FakeSecrets::accessToken(), $stored, 3600, $this->obtainedAt());

        $merged = $set->with_replacement_refresh_token(null);

        $this->assertNotSame($set, $merged);
        $this->assertSame($stored, $merged->refresh_token());
        $this->assertSame($set->access_token(), $merged->access_token());
    }

    public function testNonEmptyReplacementReplacesRefreshToken(): void
    {
        $stored = FakeSecrets::refreshToken();
        $replacement = FakeSecrets::refreshToken();
        $set = new AccessTokenSet(FakeSecrets::accessToken(), $stored, 3600, $this->obtainedAt());

        $merged = $set->with_replacement_refresh_token($replacement);

        $this->assertSame($replacement, $merged->refresh_token());
        $this->assertNotSame($stored, $merged->refresh_token());
        // The original instance is unchanged.
        $this->assertSame($stored, $set->refresh_token());
    }

    public function testEmptyStringReplacementIsRejected(): void
    {
        $set = new AccessTokenSet(FakeSecrets::accessToken(), FakeSecrets::refreshToken(), 3600, $this->obtainedAt());

        $this->expectException(\InvalidArgumentException::class);
        $set->with_replacement_refresh_token('');
    }

    /* ---------------------------------------------------------------
     * Serialization round trip (strict).
     * ---------------------------------------------------------------
     */

    public function testToArrayFromArrayRoundTripIsExact(): void
    {
        $set = new AccessTokenSet(FakeSecrets::accessToken(), FakeSecrets::refreshToken(), 3600, $this->obtainedAt());

        $restored = AccessTokenSet::from_array($set->to_array());

        $this->assertSame($set->to_array(), $restored->to_array());
        $this->assertEquals($set->obtained_at(), $restored->obtained_at());
        $this->assertEquals($set->expires_at(), $restored->expires_at());
    }

    public function testRoundTripPreservesNullRefreshToken(): void
    {
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, $this->obtainedAt());

        $restored = AccessTokenSet::from_array($set->to_array());

        $this->assertNull($restored->refresh_token());
    }

    /**
     * @return list<array{0: mixed, 1: string}>
     */
    public function malformedPayloadProvider(): array
    {
        $valid = array(
            'access_token' => 'wpct_fixture_at_valid',
            'refresh_token' => 'wpct_fixture_rt_valid',
            'expires_in' => 3600,
            'obtained_at' => '2026-09-13T10:00:00.000000+00:00',
            'expires_at' => '2026-09-13T11:00:00.000000+00:00',
        );

        $with = static function (array $overrides) use ($valid): array {
            return array_merge($valid, $overrides);
        };
        $without = static function (string $key) use ($valid): array {
            unset($valid[$key]);

            return $valid;
        };

        return array(
            'not an array' => array('nope', 'must be an array'),
            // t31-r1-14: missing keys name the missing-key rejection;
            // EXTRA keys are no longer rejected — see the
            // forward-tolerance pin below (the superseded exact-key
            // entry pinned the old contract).
            'missing key' => array($without('expires_in'), 'missing: expires_in'),
            'non-string access token' => array($with(array('access_token' => 42)), 'access token must be a string'),
            'numeric-string expires_in' => array($with(array('expires_in' => '3600')), 'expires_in must be an int'),
            'float expires_in' => array($with(array('expires_in' => 3600.5)), 'expires_in must be an int'),
            'array refresh token' => array($with(array('refresh_token' => array())), 'refresh token must be a string or null'),
            'empty-string refresh token' => array($with(array('refresh_token' => '')), 'refresh token must be null'),
            // t31-r1-12: the shape is judged before parseability, so the
            // microsecond-less spelling now names the canonical-shape
            // rejection (the superseded 'serialization format' fragment
            // pinned the old parse-only check).
            'atom-instant without microseconds' => array($with(array('obtained_at' => '2026-09-13T10:00:00+00:00')), 'canonical UTC spelling'),
            'Z suffix' => array($with(array('expires_at' => '2026-09-13T11:00:00.000000Z')), 'canonical UTC spelling'),
            'lowercase z suffix' => array($with(array('expires_at' => '2026-09-13T11:00:00.000000z')), 'canonical UTC spelling'),
            'whitespace before offset' => array($with(array('expires_at' => '2026-09-13T11:00:00.000000 +00:00')), 'canonical UTC spelling'),
            'one-digit fraction' => array($with(array('expires_at' => '2026-09-13T11:00:00.5+00:00')), 'canonical UTC spelling'),
            'five-digit fraction' => array($with(array('expires_at' => '2026-09-13T11:00:00.12345+00:00')), 'canonical UTC spelling'),
            'seven-digit fraction' => array($with(array('expires_at' => '2026-09-13T11:00:00.1234567+00:00')), 'canonical UTC spelling'),
            'non-UTC offset' => array($with(array('expires_at' => '2026-09-13T13:00:00.000000+02:00')), 'canonical UTC spelling'),
            'calendar-invalid date' => array($with(array(
                'obtained_at' => '2026-02-30T10:00:00.000000+00:00',
                'expires_at' => '2026-02-30T11:00:00.000000+00:00',
            )), 'calendar-valid'),
            'non-string instant' => array($with(array('expires_at' => 1757757600)), 'instants must be strings'),
            'whitespace access token' => array($with(array('access_token' => '   ')), 'non-whitespace string'),
            'inconsistent expiry' => array(
                $with(array('expires_at' => '2026-09-13T12:00:00.000000+00:00')),
                'does not match',
            ),
        );
    }

    /**
     * @dataProvider malformedPayloadProvider
     *
     * @param mixed  $payload       Malformed serialized token set.
     * @param string $messageFragment Expected exception message fragment.
     */
    public function testFromArrayRejectsMalformedPayloads($payload, string $messageFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($messageFragment);

        AccessTokenSet::from_array($payload);
    }

    public function testFromArrayAcceptsAnyKeyOrder(): void
    {
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 60, $this->obtainedAt());
        $data = $set->to_array();
        $reordered = array(
            'expires_at' => $data['expires_at'],
            'obtained_at' => $data['obtained_at'],
            'expires_in' => $data['expires_in'],
            'refresh_token' => $data['refresh_token'],
            'access_token' => $data['access_token'],
        );

        $this->assertSame($data, AccessTokenSet::from_array($reordered)->to_array());
    }

    /**
     * Review-round pin (t31-r1-14, the Task-4.4 decision): reads are
     * FORWARD-TOLERANT — the five modelled keys are required and
     * strictly validated, but keys this version does not model are
     * ignored, so a payload written by a newer version (Task 4.4 adds
     * the id-token facts member) loads on an older reader instead of
     * fail-closing every stored grant into a forced re-connect on
     * upgrade. Honest cost, pinned: a load → save round trip through
     * THIS version drops the unmodelled keys. Format versioning stays
     * the envelope's job — the inner payload carries no version field.
     */
    public function testFromArrayIgnoresKeysThisVersionDoesNotModel(): void
    {
        $set = new AccessTokenSet(FakeSecrets::accessToken(), null, 3600, $this->obtainedAt());
        $future = $set->to_array() + array('id_token' => 'future-facts-member');

        $restored = AccessTokenSet::from_array($future);

        // The modelled facts survive...
        $this->assertSame($set->access_token(), $restored->access_token());
        $this->assertEquals($set->expires_at(), $restored->expires_at());
        // ...and the re-serialization is this version's shape: exactly
        // the five modelled keys, the future member dropped.
        $this->assertSame($set->to_array(), $restored->to_array());
        $this->assertSame(
            array('access_token', 'refresh_token', 'expires_in', 'obtained_at', 'expires_at'),
            array_keys($restored->to_array())
        );
    }

    /* ---------------------------------------------------------------
     * Immutability pins.
     * ---------------------------------------------------------------
     */

    public function testEveryPropertyIsReadOnlyAndNoStaticStateExists(): void
    {
        $reflection = new \ReflectionClass(AccessTokenSet::class);

        $this->assertTrue($reflection->isFinal());
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                'Property ' . $property->getName() . ' must be readonly.'
            );
            $this->assertFalse($property->isStatic(), 'Value objects carry no static state.');
        }
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith(
                'set',
                $method->getName(),
                'Value objects expose no setters.'
            );
        }
    }

    public function testReflectionWriteIntoInitializedPropertyFails(): void
    {
        $access = FakeSecrets::accessToken();
        $set = new AccessTokenSet($access, null, 3600, $this->obtainedAt());
        $property = new \ReflectionProperty(AccessTokenSet::class, 'access_token');

        try {
            $property->setValue($set, 'overwritten');
            $this->fail('Writing an initialized readonly property must fail on every supported runtime.');
        } catch (\Throwable $e) {
            // Error on newer runtimes, ReflectionException on older ones —
            // either refusal proves the immutability; the value pin below
            // carries the behavioral half.
        }

        $this->assertSame($access, $set->access_token());
    }

    /* ---------------------------------------------------------------
     * Clock port.
     * ---------------------------------------------------------------
     */

    public function testClockInterfaceShapeIsThePort(): void
    {
        $this->assertTrue(interface_exists(ClockInterface::class));
        $method = new \ReflectionMethod(ClockInterface::class, 'now');
        $this->assertSame(0, $method->getNumberOfParameters());
        $this->assertSame(\DateTimeImmutable::class, (string) $method->getReturnType());
    }

    public function testSystemClockReturnsMonotonicallySaneUtcNow(): void
    {
        $clock = new SystemClock();

        $first = $clock->now();
        $second = $clock->now();

        $this->assertInstanceOf(\DateTimeImmutable::class, $first);
        $this->assertGreaterThanOrEqual(time() - 5, $first->getTimestamp());
        $this->assertLessThanOrEqual(time() + 5, $first->getTimestamp());
        $this->assertGreaterThanOrEqual($first, $second);
        $this->assertSame('UTC', $first->getTimezone()->getName());
    }

    public function testDeterministicClockControlsAndAdvancesTheReading(): void
    {
        $clock = new DeterministicClock(new \DateTimeImmutable('2026-09-13T10:00:00+00:00'));
        $this->assertInstanceOf(ClockInterface::class, $clock);

        $this->assertSame('2026-09-13T10:00:00+00:00', $clock->now()->format('Y-m-d\TH:i:sP'));

        $clock->advanceBy(3599);
        $this->assertSame('2026-09-13T10:59:59+00:00', $clock->now()->format('Y-m-d\TH:i:sP'));
    }

    public function testDeterministicClockRefusesToRunBackwards(): void
    {
        $clock = new DeterministicClock(new \DateTimeImmutable('2026-09-13T10:00:00+00:00'));

        $this->expectException(\InvalidArgumentException::class);
        $clock->advanceBy(-1);
    }

    /* ---------------------------------------------------------------
     * Shared instant arithmetic (review round t31-r1).
     * ---------------------------------------------------------------
     */

    /**
     * Verifier-round pin (t31-r1-18): minus_seconds(PHP_INT_MIN)
     * negated its int argument before the shift — the negation
     * overflowed to float and the engine threw a strict-types TypeError
     * instead of the documented rejection (the same class t31-r1-11
     * counted for StoredGrant). The mirror plus_seconds(PHP_INT_MIN)
     * is representable and exact.
     */
    public function testMinusSecondsAtIntMinRejectsTypedInsteadOfOverflowing(): void
    {
        $instant = new \DateTimeImmutable('2026-09-13T10:00:00.000000+00:00');

        try {
            InstantArithmetic::minus_seconds($instant, PHP_INT_MIN);
            $this->fail('minus_seconds at PHP_INT_MIN must reject with the documented type, never overflow the negation to a float TypeError.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unrepresentable', $e->getMessage());
        }

        // The representable mirrors stay exact.
        $this->assertSame(
            PHP_INT_MIN,
            InstantArithmetic::plus_seconds(new \DateTimeImmutable('@0'), PHP_INT_MIN)->getTimestamp()
        );
        $this->assertSame(
            0,
            InstantArithmetic::minus_seconds(new \DateTimeImmutable('@0'), 0)->getTimestamp()
        );
    }
}
