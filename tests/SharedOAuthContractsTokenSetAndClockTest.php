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
            'missing key' => array($without('expires_in'), 'exactly the keys'),
            'extra key' => array($with(array('id_token' => 'x')), 'exactly the keys'),
            'non-string access token' => array($with(array('access_token' => 42)), 'access token must be a string'),
            'numeric-string expires_in' => array($with(array('expires_in' => '3600')), 'expires_in must be an int'),
            'float expires_in' => array($with(array('expires_in' => 3600.5)), 'expires_in must be an int'),
            'array refresh token' => array($with(array('refresh_token' => array())), 'refresh token must be a string or null'),
            'empty-string refresh token' => array($with(array('refresh_token' => '')), 'refresh token must be null'),
            'atom-instant without microseconds' => array($with(array('obtained_at' => '2026-09-13T10:00:00+00:00')), 'serialization format'),
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
}
