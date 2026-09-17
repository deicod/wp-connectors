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
        $device_code = FakeSecrets::deviceCode();
        $session = new DeviceAuthorizationSession(
            $device_code,
            'ABCD-1234',
            'https://auth.example.test/device',
            5,
            $expires
        );

        /*
         * OCR-round-19 pin (t31-ocr19-3): the primary poll credential
         * round-trips like its siblings — the fake's deviceCode() is
         * random per call, so the pin needs the capture; without it
         * every property of the session was asserted except the one
         * the device flow exists to carry.
         */
        $this->assertSame($device_code, $session->device_code());
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

        /*
         * OCR-round-6 pin (t31-ocr6-1), derived since t31-ocr7-6: a
         * nine-character user code renders the BARE mask in the dump
         * channel — the old threshold of 8 showed '…3502', half the
         * code's entropy. The pin rides the DERIVED pair above (the
         * raw codes never ride, the device code rides exactly the mask
         * computed from the SAME code) because the channel literal it
         * once used (not-contains '3502') collided with the mask's own
         * output: the device code's random 4-hex tail spells '3502'
         * once per 65,536 runs and failed the pin spuriously. The
         * bare-mask shape itself is pinned deterministically at the
         * mask owner (SharedOAuthContractsHttpTest, t31-ocr6-1's
         * threshold leg) — a threshold regression fails THERE, never
         * here on a coin flip.
         */

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

        /*
         * The serialize channel rides the same derived pin (t31-ocr6-1,
         * t31-ocr7-6): the nine-character user code is bare-masked here
         * too — the tail never rides a snapshot payload. Same
         * derivation as the dump channel above: the derived pair (raw
         * never rides, masked form computed from the SAME code rides)
         * carries the invariant; the '3502' literal is gone from this
         * channel too for the same 1/65,536 collision with the device
         * code's own random mask tail, and the threshold shape lives at
         * the mask owner (SharedOAuthContractsHttpTest).
         */

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
            $refusal = $this->refusalOf(
                fn() => unserialize(serialize($safe)),
                'A masked flow VO must never reconstruct from its own safe form (' . get_class($safe) . ').', \RuntimeException::class
            );
            $this->assertStringContainsString('not a round-trip payload', $refusal->getMessage());
        }

        // The eval channel refuses typed directly.
        foreach (array(PkceCodePair::class, DeviceAuthorizationSession::class, PendingAuthorization::class) as $vo) {
            $refusal = $this->refusalOf(
                fn() => $vo::__set_state(array('code_verifier' => 'raw')),
                "__set_state() must refuse the raw export as a reconstruction source ({$vo}).", \RuntimeException::class
            );
            $this->assertStringContainsString('never a payload', $refusal->getMessage());
        }

        // The one EXCLUDED channel, pinned exactly: var_export() dumps
        // the raw property tree through no engine hook (the nested
        // payload dumps raw too), and evaluating the dump never
        // reconstructs — by the engine's own parse of the raw tree or
        // by the __set_state() refusal above.
        $export = var_export($pair, true);
        $this->assertStringContainsString($verifier, $export, 'The documented exclusion is exact: var_export() dumps the raw tree through no hook — which is precisely why its reconstruction channel refuses.');
    }

    /**
     * OCR-round-14 pin (t31-ocr14-1): the verification URI renders in
     * its REDACTED shape in both masked channels — the scheme://host[:port]
     * /path rebuild Url::parse_validated() feeds HttpRequest's
     * redacted_url(). A credential-carrying URI (userinfo per the HTTP
     * contract's own constructible pin, a one-time token in the query
     * the way throttled providers embed them) rendered its credentials
     * in cleartext into every masked view before — the exact class the
     * VO's masking exists to prevent. The RAW property stays intact:
     * the authorization redirect needs the full URI, only the view is
     * masked.
     */
    public function testACredentialBearingVerificationUriRendersRedactedInTheMaskedChannels(): void
    {
        $rawUri = 'https://user:pw@example.com:8443/device?user_code=BCJK-3502#frag';
        $session = new DeviceAuthorizationSession(FakeSecrets::deviceCode(), 'BCJK-3502', $rawUri, 5, new \DateTimeImmutable('+10 minutes'));

        foreach (array('dump' => print_r($session, true), 'serialize' => serialize($session)) as $channel => $rendered) {
            $this->assertStringNotContainsString('user:pw', $rendered, "The userinfo never rides the masked {$channel} view.");
            $this->assertStringNotContainsString('BCJK-3502', $rendered, "The query's token material (and the masked code's own tail) never rides the {$channel} view.");
            $this->assertStringNotContainsString('?user_code', $rendered, "The query never rides the {$channel} view.");
            $this->assertStringNotContainsString('#frag', $rendered, "The fragment never rides the {$channel} view.");
            $this->assertStringContainsString('https://example.com:8443/device', $rendered, "The {$channel} view carries the URI's redacted shape (scheme, authority, path).");
        }

        // The redirect channel keeps the FULL URI — the browser needs it.
        $this->assertSame($rawUri, $session->verification_uri(), 'The raw property is intact for the authorization redirect; only the view is masked.');
    }

    /**
     * OCR-round-6 pin (t31-ocr6-3): the carrier's label renders through
     * SecretMask::utf8_for_safe_render() — the same one rendering owner
     * StoredGrant's label leg rides (the r4-13/r8-6 doctrine at the VO
     * seams). A lone 0xE9 passes the constructor's control-byte screen
     * by design (a config label is opaque), but verbatim it made the
     * dump and serialize forms invalid UTF-8 — the json_encode()-false
     * log-drop class. Escaped in both channels, stored bytes unchanged.
     */
    public function testAnInvalidUtf8ProviderLabelRendersEscapedInThePendingSafeForms(): void
    {
        $session = new DeviceAuthorizationSession(FakeSecrets::deviceCode(), 'BCJK-3502', 'https://example.com/device', 5, new \DateTimeImmutable('+10 minutes'));
        $pending = PendingAuthorization::for_device(7, "fixture\xE9provider", $session, new \DateTimeImmutable());

        foreach (array('dump' => print_r($pending, true), 'serialize' => serialize($pending)) as $channel => $rendered) {
            $this->assertStringNotContainsString("\xE9", $rendered, "The raw invalid byte never rides the {$channel} form.");
            $this->assertStringContainsString('fixture%E9provider', $rendered, "The label escapes exactly like the established safe-debug forms in the {$channel} channel.");
            $this->assertNotFalse(json_encode($rendered), "The {$channel} form always json_encodes.");
        }
        $this->assertSame("fixture\xE9provider", $pending->provider_id(), 'The stored bytes are unchanged — the escape is render-only.');

        $clean = PendingAuthorization::for_device(7, 'fixture-provider', $session, new \DateTimeImmutable());
        $this->assertStringContainsString('fixture-provider', print_r($clean, true), 'A normal label renders as itself.');
    }

    /**
     * OCR-round-3 pin (t31-ocr3-2): the dump and serialize channels
     * cannot drift. The suite pinned print_r() alone since t31-r11-5,
     * so a future edit that re-decided ONE channel's mask (a hand-tailored
     * __serialize() here, a diverging __debugInfo() there) would have
     * passed green with the two engine channels disagreeing about what
     * a credential renders as. Both hooks are public and both ride the
     * ONE masked_view() by doctrine — so the strongest pin is direct:
     * same object, both hooks, identical arrays; and the masked
     * rendering print_r() shows is byte-present in serialize() while
     * the credential itself is byte-absent from BOTH channels.
     */
    public function testTheDumpAndSerializeChannelsCannotDrift(): void
    {
        $verifier = FakeSecrets::codeVerifier();
        $masked = \Deicod\WpConnectors\Shared\Support\SecretMask::mask($verifier);
        $pair = PkceCodePair::from_verifier($verifier);
        $this->assertSame($pair->__debugInfo(), $pair->__serialize(), 'The pair\'s two render hooks are the ONE masked view.');

        $device_code = FakeSecrets::deviceCode();
        $session = new DeviceAuthorizationSession($device_code, 'BCJK-3502', 'https://example.com/device', 5, new \DateTimeImmutable('+10 minutes'));
        $this->assertSame($session->__debugInfo(), $session->__serialize(), 'The session\'s two render hooks are the ONE masked view.');

        $pendingDevice = PendingAuthorization::for_device(7, 'fixture-provider', $session, new \DateTimeImmutable());
        $this->assertSame($pendingDevice->__debugInfo(), $pendingDevice->__serialize(), 'The carrier\'s two render hooks are the ONE masked view.');
        $pendingPkce = PendingAuthorization::for_pkce(7, 'fixture-provider', $pair, new \DateTimeImmutable());
        $this->assertSame($pendingPkce->__debugInfo(), $pendingPkce->__serialize());

        // The composition the hooks promise, at the byte level, on both
        // engine channels: each VO's OWN masked spelling appears in
        // print_r() AND in serialize(), and the credential appears in
        // neither channel.
        foreach (array(
            'PKCE pair' => array($pair, $masked, $verifier),
            'device session' => array($session, \Deicod\WpConnectors\Shared\Support\SecretMask::mask($device_code), $device_code),
            'pending device flow' => array($pendingDevice, \Deicod\WpConnectors\Shared\Support\SecretMask::mask($device_code), $device_code),
            'pending PKCE flow' => array($pendingPkce, $masked, $verifier),
        ) as $label => $case) {
            foreach (array('print_r' => print_r($case[0], true), 'serialize' => serialize($case[0])) as $channel => $rendered) {
                $this->assertStringContainsString($case[1], $rendered, "The masked credential spelling rides the {$channel} channel of the {$label}.");
                $this->assertStringNotContainsString($case[2], $rendered, "The credential never rides the {$channel} channel of the {$label}.");
            }
        }
    }
}
