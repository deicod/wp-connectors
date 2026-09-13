<?php
/**
 * Contract tests: shared grant-flow shapes (Task 3.1).
 *
 * Device-authorization session, PKCE pair (with the RFC 7636 appendix B
 * S256 vector), and the user-scoped pending-authorization carrier.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Flow\DeviceAuthorizationSession;
use Deicod\WpConnectors\Shared\Flow\PendingAuthorization;
use Deicod\WpConnectors\Shared\Flow\PkceCodePair;

final class SharedOAuthContractsFlowTest extends WpConnectorsTestCase
{
    /* ---------------------------------------------------------------
     * Device-authorization session.
     * ---------------------------------------------------------------
     */

    public function testValidDeviceSessionCarriesItsFacts(): void
    {
        $expires = new \DateTimeImmutable('2026-09-13T10:15:00+00:00');
        $session = new DeviceAuthorizationSession(
            FakeSecrets::deviceCode(),
            'ABCD-1234',
            'https://auth.example.test/device',
            5,
            $expires
        );

        $this->assertSame('ABCD-1234', $session->user_code());
        $this->assertSame('https://auth.example.test/device', $session->verification_uri());
        $this->assertSame(5, $session->interval_seconds());
        $this->assertSame($expires, $session->expires_at());
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: int, 4: string}>
     */
    public function invalidDeviceSessionProvider(): array
    {
        $code = 'wpct_fixture_dc_valid';
        $userCode = 'ABCD-1234';
        $validUri = 'https://auth.example.test/device';

        return array(
            'empty device code' => array('', $userCode, $validUri, 5, 'device code'),
            'whitespace user code' => array($code, '   ', $validUri, 5, 'user code'),
            'relative verification uri' => array($code, $userCode, '/device', 5, 'scheme and host'),
            'non-http verification uri' => array($code, $userCode, 'ftp://auth.example.test/device', 5, 'scheme must be http'),
            'zero interval' => array($code, $userCode, $validUri, 0, 'at least one second'),
        );
    }

    /**
     * @dataProvider invalidDeviceSessionProvider
     *
     * @param string $deviceCode      Device code under test.
     * @param string $userCode        User code under test.
     * @param string $uri             Verification URI under test.
     * @param int    $interval        Poll interval under test.
     * @param string $fragment        Expected exception message fragment.
     */
    public function testInvalidDeviceSessionsAreRejected(string $deviceCode, string $userCode, string $uri, int $interval, string $fragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($fragment);

        new DeviceAuthorizationSession($deviceCode, $userCode, $uri, $interval, new \DateTimeImmutable());
    }

    public function testIntervalOfOneSecondIsAcceptable(): void
    {
        $session = new DeviceAuthorizationSession(
            FakeSecrets::deviceCode(),
            'ABCD-1234',
            'https://auth.example.test/device',
            1,
            new \DateTimeImmutable()
        );

        $this->assertSame(1, $session->interval_seconds());
    }

    /* ---------------------------------------------------------------
     * PKCE pair.
     * ---------------------------------------------------------------
     */

    public function testFromVerifierDerivesTheRfc7636S256Vector(): void
    {
        // RFC 7636 appendix B: the reference verifier/challenge pair.
        $pair = PkceCodePair::from_verifier('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $pair->code_challenge());
    }

    public function testFromVerifierRoundTripsTheVerifier(): void
    {
        $verifier = FakeSecrets::codeVerifier();
        $pair = PkceCodePair::from_verifier($verifier);

        $this->assertSame($verifier, $pair->code_verifier());
        // S256 products are exactly 43 characters (32 bytes, unpadded).
        $this->assertSame(43, strlen($pair->code_challenge()));
    }

    /**
     * @return list<array{0: string}>
     */
    public function invalidVerifierProvider(): array
    {
        return array(
            'too short' => array('short'),
            'bad characters' => array(str_repeat('a!', 30)),
            'empty' => array(''),
            'trailing newline at lower bound' => array(str_repeat('a', 43) . "\n"),
            'trailing newline at upper bound' => array(str_repeat('a', 128) . "\n"),
        );
    }

    /**
     * @dataProvider invalidVerifierProvider
     */
    public function testInvalidVerifiersAreRejected(string $verifier): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PkceCodePair::from_verifier($verifier);
    }

    public function testConstructorValidatesTheChallengeToo(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('challenge');

        new PkceCodePair($verifier, 'not-a-valid-challenge!');
    }

    public function testPkcePairIsImmutableWithNoSetters(): void
    {
        $reflection = new \ReflectionClass(PkceCodePair::class);
        $this->assertTrue($reflection->isFinal());
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
            $this->assertFalse($property->isStatic());
        }
    }

    /* ---------------------------------------------------------------
     * Pending authorization.
     * ---------------------------------------------------------------
     */

    private function deviceSession(): DeviceAuthorizationSession
    {
        return new DeviceAuthorizationSession(
            FakeSecrets::deviceCode(),
            'ABCD-1234',
            'https://auth.example.test/device',
            5,
            new \DateTimeImmutable('2026-09-13T10:15:00+00:00')
        );
    }

    public function testForDeviceCarriesExactlyTheDeviceFlow(): void
    {
        $created = new \DateTimeImmutable('2026-09-13T10:00:00+00:00');
        $session = $this->deviceSession();

        $pending = PendingAuthorization::for_device(7, 'fixture-provider', $session, $created);

        $this->assertSame(7, $pending->user_id());
        $this->assertSame('fixture-provider', $pending->provider_id());
        $this->assertSame($created, $pending->created_at());
        $this->assertSame($session, $pending->device_session());
        $this->assertNull($pending->pkce_pair());
    }

    public function testForPkceCarriesExactlyThePkceFlow(): void
    {
        $pair = PkceCodePair::from_verifier(FakeSecrets::codeVerifier());

        $pending = PendingAuthorization::for_pkce(7, 'fixture-provider', $pair, new \DateTimeImmutable());

        $this->assertSame($pair, $pending->pkce_pair());
        $this->assertNull($pending->device_session());
    }

    public function testConstructorIsPrivateSoExactlyOnePayloadAlwaysHolds(): void
    {
        $constructor = new \ReflectionMethod(PendingAuthorization::class, '__construct');
        $this->assertTrue($constructor->isPrivate());
    }

    public function testZeroUserIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('user id');

        PendingAuthorization::for_device(0, 'fixture-provider', $this->deviceSession(), new \DateTimeImmutable());
    }

    public function testEmptyProviderIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PendingAuthorization::for_pkce(7, ' ', PkceCodePair::from_verifier(FakeSecrets::codeVerifier()), new \DateTimeImmutable());
    }
}
