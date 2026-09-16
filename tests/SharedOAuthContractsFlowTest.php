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

    /**
     * Review-round pin (t31-r12-5): both device-flow codes are
     * PROVIDER-SUPPLIED strings, and with only the non-empty screen a
     * raw CRLF constructed — print_r() then forged lines in the MASKED
     * debug tail (the mask keeps the last four characters, controls
     * included — reproduced), the forged-log-line channel
     * r1-19/r2-1/r11-5 closed on the URL and header surfaces but not
     * on the code positions. The ONE control-byte guard (HeaderMap's
     * own vocabulary, one callable) refuses them loudly; the pending
     * authorization's provider-supplied label takes the same guard,
     * and the exact legal session spellings stay green.
     */
    public function testControlBytesInProviderSuppliedCodesRefuseLoudly(): void
    {
        $legal_code = 'wpct_fixture_dc_' . bin2hex(random_bytes(8));

        $hostile_codes = array(
            'device code with CRLF' => $legal_code . "\r\nAuthorization: Bearer x",
            'device code with NUL' => "wpct\x00dc",
            'device code with C1 NEL spelling' => "wpct\xC2\x85dc",
            'user code with CRLF' => "ABCD-1234\r\n",
            'user code with NUL' => "AB\x00CD",
            'user code with U+2028' => "ABCD\xE2\x80\xA81234",
        );
        foreach ($hostile_codes as $label => $code) {
            try {
                new DeviceAuthorizationSession($code, 'ABCD-1234', 'https://auth.example.test/device', 5, new \DateTimeImmutable());
                $this->fail("A control-byte-bearing device code ({$label}) must refuse.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not contain control characters', $e->getMessage());
            }
            try {
                new DeviceAuthorizationSession(FakeSecrets::deviceCode(), $code, 'https://auth.example.test/device', 5, new \DateTimeImmutable());
                $this->fail("A control-byte-bearing user code ({$label}) must refuse.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not contain control characters', $e->getMessage());
            }
        }

        // The pending authorization's provider-supplied label rides the
        // same guard (its flow payloads gate themselves: the PKCE pair by
        // grammar, the device session by the gates above).
        try {
            PendingAuthorization::for_device(1, "zai\r\ninjected", $this->deviceSession(), new \DateTimeImmutable());
            $this->fail('A control-byte-bearing provider id must refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must not contain control characters', $e->getMessage());
        }

        // The exact legal session fixtures stay green: the factory code,
        // the typed human spelling, and a dotted provider label.
        $session = new DeviceAuthorizationSession($legal_code, 'ABCD-1234', 'https://auth.example.test/device', 5, new \DateTimeImmutable());
        $this->assertSame($legal_code, $session->device_code());
        $this->assertSame('ABCD-1234', $session->user_code());
        $this->assertStringNotContainsString("\r", print_r($session, true), 'The legal dump carries no carriage return — no forged line material.');
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

    /**
     * OCR-round-2 pin (t31-ocr2-8): the constructor verifies the
     * binding this VO exists to carry. Only S256 is supported and
     * from_verifier() is the sole fresh producer, yet a hand-built or
     * corrupted pair whose challenge != BASE64URL(SHA-256(verifier))
     * was perfectly representable — grammar-clean, both halves in
     * bounds — and failed far away at the provider as an opaque
     * invalid_grant. The grammar-clean mismatch refuses AT
     * CONSTRUCTION now (one derivation owner, constant-time compare);
     * every fixture produced via from_verifier() stays green by
     * construction (the only hand-built pair in this suite is the
     * grammar reject above).
     */
    public function testAMismatchedChallengeRefusesAtConstruction(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        // The challenge of a DIFFERENT verifier: grammar-clean, wrong.
        $other = PkceCodePair::from_verifier('aBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');
        try {
            new PkceCodePair($verifier, $other->code_challenge());
            $this->fail('A grammar-clean but mismatched challenge must refuse at construction.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('BASE64URL(SHA-256(code_verifier))', $e->getMessage());
        }

        // A one-character tamper of the true challenge: still
        // grammar-clean, still wrong — the binding, not the charset,
        // refuses it.
        $true_challenge = PkceCodePair::from_verifier($verifier)->code_challenge();
        $tampered = ('E' === $true_challenge[0] ? 'e' : 'E') . substr($true_challenge, 1);
        try {
            new PkceCodePair($verifier, $tampered);
            $this->fail('A one-character tamper of the true challenge must refuse too.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('BASE64URL(SHA-256(code_verifier))', $e->getMessage());
        }

        // The RFC 7636 appendix-B pair reconstructs exactly (the
        // rehydration shape the constructor serves).
        $pair = new PkceCodePair($verifier, $true_challenge);
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $pair->code_challenge());
        $this->assertSame($verifier, $pair->code_verifier());
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

    /**
     * Verifier-round pin (t31-r11-5): the SERIALIZATION channel rides
     * the redaction contract. Without __debugInfo() the engine dumped
     * the raw property tree — the PKCE verifier (RFC 7636's
     * confidential half) and both device-flow codes (the poll
     * credential and the pairing capability) rendered in full
     * (reproduced pre-fix). Each masks through the one vocabulary
     * (SecretMask), the public halves (challenge, verification URI)
     * dump as themselves, and the nesting carrier (PendingAuthorization)
     * needs no mask of its own — the engine applies the payload's
     * __debugInfo at every level.
     */
    public function testTheSerializationChannelDumpsMaskedFlowCredentials(): void
    {
        $verifier = FakeSecrets::codeVerifier();
        $pair = PkceCodePair::from_verifier($verifier);

        $dumped = print_r($pair, true);
        $this->assertStringNotContainsString($verifier, $dumped, 'The dump must never carry the confidential verifier.');
        $this->assertStringContainsString((string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask($verifier), $dumped, 'The verifier dumps in its masked form.');
        $this->assertStringContainsString($pair->code_challenge(), $dumped, 'The public challenge half dumps as itself — it travels in the authorization request.');

        $device_code = FakeSecrets::deviceCode();
        $user_code = 'BCJK-3502';
        $session = new DeviceAuthorizationSession($device_code, $user_code, 'https://example.com/device', 5, new \DateTimeImmutable('+10 minutes'));

        $dumped = print_r($session, true);
        $this->assertStringNotContainsString($device_code, $dumped, 'The dump must never carry the device code (the poll credential).');
        $this->assertStringNotContainsString($user_code, $dumped, 'The user code masks too — the pairing capability is not dump material.');
        $this->assertStringContainsString((string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask($device_code), $dumped, 'The device code dumps in its masked form.');
        $this->assertStringContainsString('https://example.com/device', $dumped, 'The public verification URI dumps as itself.');

        $pending = PendingAuthorization::for_pkce(7, 'fixture-provider', $pair, new \DateTimeImmutable());
        $this->assertStringNotContainsString($verifier, print_r($pending, true), 'A nesting carrier reaches its payload only through the payload\'s own masked dump.');
    }

    /**
     * OCR-round-3 pin (t31-ocr3-1): the serialize() channel — the
     * direct follow-on of the ocr2-1 doctrine (the HTTP value objects
     * closed it in t31-ocr1-8, the token carrier and grant in
     * t31-ocr2-1) on the LAST credential-bearing VOs still open: the
     * pair (the RFC 7636 confidential half), the device session (both
     * device-flow codes), and the nesting carrier that holds either
     * by value. serialize() bypasses __debugInfo() by engine design,
     * so the r11-5 dump hooks alone left the ENGINE serialization
     * emitting the raw property tree into every persistence or queue
     * payload built from the value. __serialize() rides the SAME
     * masked view as the dump (the carrier's payload rides as the
     * OBJECT, so the nested hook applies), the reconstruction
     * channels refuse, and var_export() stays the one NAMED-EXCLUDED
     * channel (no engine hook exists — pinned exactly as excluded, a
     * future engine hook tightens the contract instead of silently
     * understating it; the t31-ocr1-8 shape).
     */
    public function testTheSerializeChannelRendersTheFlowCredentialsMaskedAndRefusesToRebuild(): void
    {
        $verifier = FakeSecrets::codeVerifier();
        $pair = PkceCodePair::from_verifier($verifier);

        $payload = serialize($pair);
        $this->assertStringNotContainsString($verifier, $payload, 'serialize() must never carry the confidential verifier.');
        $this->assertStringContainsString((string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask($verifier), $payload, 'The verifier rides its masked form.');
        $this->assertStringContainsString($pair->code_challenge(), $payload, 'The public challenge half rides the payload as itself.');

        $device_code = FakeSecrets::deviceCode();
        $user_code = 'BCJK-3502';
        $session = new DeviceAuthorizationSession($device_code, $user_code, 'https://example.com/device', 5, new \DateTimeImmutable('+10 minutes'));

        $payload = serialize($session);
        $this->assertStringNotContainsString($device_code, $payload, 'serialize() must never carry the device code (the poll credential).');
        $this->assertStringNotContainsString($user_code, $payload, 'serialize() must never carry the user code (the pairing capability).');
        $this->assertStringContainsString((string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask($device_code), $payload, 'The device code rides its masked form.');
        $this->assertStringContainsString('https://example.com/device', $payload, 'The public verification URI rides the payload as itself.');

        // The nesting carrier composes: its own facts render as
        // themselves, and the payload rides as the OBJECT — the
        // engine applies the payload's own __serialize() at that
        // level, so the carrier delegates, it does not re-decide the
        // mask.
        $pendingDevice = PendingAuthorization::for_device(7, 'fixture-provider', $session, new \DateTimeImmutable());
        $payload = serialize($pendingDevice);
        $this->assertStringNotContainsString($device_code, $payload, 'serialize() of the carrier must never carry the raw device code.');
        $this->assertStringNotContainsString($user_code, $payload, 'serialize() of the carrier must never carry the raw user code.');
        $this->assertStringContainsString((string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask($device_code), $payload, 'The nested session rides its OWN masked serialize form.');

        $pendingPkce = PendingAuthorization::for_pkce(7, 'fixture-provider', $pair, new \DateTimeImmutable());
        $payload = serialize($pendingPkce);
        $this->assertStringNotContainsString($verifier, $payload, 'serialize() of the carrier must never carry the raw verifier.');

        // A container holding each shape (the queue/cache form) rides
        // the same hooks through the graph.
        $container = serialize(array('pkce' => $pair, 'device' => $session, 'pending' => $pendingDevice));
        $this->assertStringNotContainsString($verifier, $container);
        $this->assertStringNotContainsString($device_code, $container);
        $this->assertStringNotContainsString($user_code, $container);

        // The masked snapshots are not round-trip payloads: rebuilding refuses.
        foreach (array($pair, $session, $pendingDevice, $pendingPkce) as $safe) {
            try {
                unserialize(serialize($safe));
                $this->fail('A masked flow VO must never reconstruct from its own safe form (' . get_class($safe) . ').');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not a round-trip payload', $e->getMessage());
            }
        }

        // The eval channel refuses typed directly.
        foreach (array(PkceCodePair::class, DeviceAuthorizationSession::class, PendingAuthorization::class) as $vo) {
            try {
                $vo::__set_state(array('code_verifier' => 'raw'));
                $this->fail("__set_state() must refuse the raw export as a reconstruction source ({$vo}).");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('never a payload', $e->getMessage());
            }
        }

        // The one EXCLUDED channel, pinned exactly: var_export() dumps
        // the raw property tree through no engine hook (the nested
        // payload dumps raw too), and evaluating the dump never
        // reconstructs — by the engine's own parse of the raw tree or
        // by the __set_state() refusal above.
        $export = var_export($pair, true);
        $this->assertStringContainsString($verifier, $export, 'The documented exclusion is exact: var_export() dumps the raw tree through no hook — which is precisely why its reconstruction channel refuses.');
    }
}
