<?php
/**
 * Contract tests: shared HTTP transport port (Task 3.1).
 *
 * Request/response VO shape, validation, and the redaction pins: casting
 * either VO to string never reveals an Authorization header, a
 * token-bearing URL query/userinfo, or any body content — secrets show
 * as an ellipsis plus the last four characters at most.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Http\HeaderMap;
use Deicod\WpConnectors\Shared\Http\HttpRequest;
use Deicod\WpConnectors\Shared\Http\HttpResponse;
use Deicod\WpConnectors\Shared\Http\HttpTransportInterface;
use Deicod\WpConnectors\Shared\Http\Url;
use Deicod\WpConnectors\Shared\Support\SecretMask;

final class SharedOAuthContractsHttpTest extends WpConnectorsTestCase
{
    /* ---------------------------------------------------------------
     * Request shape and validation.
     * ---------------------------------------------------------------
     */

    public function testValidRequestCarriesItsFacts(): void
    {
        $request = new HttpRequest(
            'post',
            'https://token-endpoint.example/oauth2/token',
            array('Content-Type' => 'application/x-www-form-urlencoded'),
            'grant_type=refresh_token'
        );

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://token-endpoint.example/oauth2/token', $request->url());
        $this->assertSame('application/x-www-form-urlencoded', $request->header('content-type'));
        $this->assertSame('grant_type=refresh_token', $request->body());
    }

    public function testNullBodyIsDistinctFromEmptyBody(): void
    {
        $none = new HttpRequest('GET', 'https://host.example/path');
        $empty = new HttpRequest('POST', 'https://host.example/path', array(), '');

        $this->assertNull($none->body());
        $this->assertSame('', $empty->body());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function invalidMethodProvider(): array
    {
        return array(
            'empty' => array('', 'method token'),
            'space inside' => array('GET /path', 'method token'),
            'newline' => array("GET\r\nX-Injected: 1", 'method token'),
            'trailing newline' => array("GET\n", 'method token'),
            'trailing carriage return' => array("GET\r", 'method token'),
        );
    }

    /**
     * @dataProvider invalidMethodProvider
     */
    public function testInvalidMethodsAreRejected(string $method, string $fragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($fragment);

        new HttpRequest($method, 'https://host.example/');
    }

    /**
     * @return list<array{0: string}>
     */
    public function invalidUrlProvider(): array
    {
        return array(
            'relative path' => array('/oauth2/token'),
            'scheme-less host' => array('token-endpoint.example/oauth2/token'),
            'non-http scheme' => array('ftp://token-endpoint.example/token'),
            'no host' => array('https:///token'),
            'port out of range' => array('https://host.example:99999/token'),
            'garbage' => array('https://@@@'),
        );
    }

    /**
     * @dataProvider invalidUrlProvider
     */
    public function testInvalidUrlsAreRejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpRequest('POST', $url);
    }

    /**
     * Fix-round pin (t31-r2-1): parse_url passed U+2028/U+2029, the
     * C1 controls riding as valid UTF-8, and the C0 range through to
     * redacted_url()/__toString() verbatim — the forged-log-line class
     * t31-r1-19 rejected in header VALUES, reopened in the URL
     * position ('https://api.example/callback<U+2028>Authorization:
     * Bearer ***' constructed and rendered). The whole URL surface is
     * screened with the SAME vocabulary (owned by HeaderMap, so the
     * header rule and the URL rule cannot drift), in the path and the
     * host positions alike, at the shared owner and through the VO.
     */
    public function testControlBytesInUrlsAreRejectedInPathAndHostPositions(): void
    {
        $hostile_urls = array(
            'U+2028 line separator in path' => "https://api.example/callback\xE2\x80\xA8Authorization: Bearer ***",
            'U+2029 paragraph separator in path' => "https://api.example/cb\xE2\x80\xA9forged",
            'NEL in path' => "https://api.example/cb\xC2\x85nel",
            'line feed in path' => "https://api.example/c\nb",
            'U+2028 line separator in host' => "https://api.example\xE2\x80\xA8.evil/callback",
            'carriage return in host' => "https://api.example\r.evil/callback",
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A URL carrying %s must be rejected by the shared URL owner.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A URL carrying %s must be rejected by the request VO.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    /**
     * The tolerance boundary, pinned honestly: a space in a host still
     * constructs and renders (the round-1 host-charset adjudication —
     * parse_url's lenient host charset is below-the-bar for constructor
     * validation; the byte renders oddly but forges no line). Widening
     * or narrowing this tolerance is a supersession of that
     * adjudication, not a drive-by. (A TAB in the host normalizes to an
     * underscore inside modern parse_url — no tab byte reaches the
     * debug form to tolerate.)
     */
    public function testSpaceStaysLegalInUrlHostsForNow(): void
    {
        $spaced = new HttpRequest('GET', 'https://host.example well/path');
        $this->assertSame('https://host.example well/path', $spaced->redacted_url());
    }

    public function testNonStringHeaderKeysAndValuesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Header names');

        new HttpRequest('POST', 'https://host.example/', array(0 => 'value'));
    }

    public function testNonStringHeaderValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Header values');

        new HttpRequest('POST', 'https://host.example/', array('Accept' => 1));
    }

    /**
     * Review-round pin (CRLF injection): a line break inside a header
     * NAME forges a header line in any rendered form, so it never gets
     * past the constructor — the masked-when-sensitive debug line can
     * only ever describe real, single-line headers.
     */
    public function testCrlfInHeaderNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('line breaks');

        new HttpRequest(
            'POST',
            'https://host.example/',
            array("X-Foo\r\nAuthorization" => 'Bearer ' . FakeSecrets::accessToken())
        );
    }

    public function testCrlfInHeaderValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('line breaks');

        new HttpRequest(
            'POST',
            'https://host.example/',
            array('Content-Type' => "application/json\r\nAuthorization: Bearer " . FakeSecrets::accessToken())
        );
    }

    public function testBareCarriageReturnInHeaderValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpRequest('POST', 'https://host.example/', array('Accept' => "application/json\rnope"));
    }

    public function testResponseRejectsCrlfInHeaderNameToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('line breaks');

        new HttpResponse(200, array("X-Foo\r\nSet-Cookie" => 'session=' . FakeSecrets::accessToken()));
    }

    public function testResponseRejectsCrlfInHeaderValueToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpResponse(200, array('Content-Type' => "application/json\nSet-Cookie: x"));
    }

    /**
     * Review-round pin (t31-r1-9): the boundary rejected only \r and \n
     * — ANSI escapes, NUL, vertical tab, and DEL passed and rendered
     * verbatim into the safe debug forms (provider-side response
     * values too). The whole control-byte class is rejected now; a
     * horizontal tab stays legal in a VALUE (RFC 7230 field-value).
     */
    public function testControlBytesInHeaderValuesAreRejectedInBothVos(): void
    {
        $hostile_values = array(
            'NUL' => "token\x00suffix",
            'vertical tab' => "token\x0Bsuffix",
            'escape sequence' => "ok\x1B[2Jok",
            'bell' => "ok\x07",
            'DEL' => "ok\x7F",
            'bare carriage return' => "ok\x0Dnope",
            'bare line feed' => "ok\x0Anope",
        );

        foreach ($hostile_values as $label => $value) {
            try {
                new HttpRequest('POST', 'https://host.example/', array('X-Test' => $value));
                $this->fail(sprintf('A header value carrying %s must be rejected by the request VO.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }

            try {
                new HttpResponse(200, array('X-Test' => $value));
                $this->fail(sprintf('A header value carrying %s must be rejected by the response VO.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    public function testControlBytesInHeaderNamesAreRejectedToo(): void
    {
        foreach (array("X-Foo\x00", "X\x1B[F", "X\x7F", "X\tY") as $name) {
            try {
                new HttpRequest('POST', 'https://host.example/', array($name => 'value'));
                $this->fail('A header name carrying a control byte (tab included) must be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    /**
     * Verifier-round pin (t31-r1-16, the security lens's redaction
     * hole): a header name outside the RFC 7230 token grammar dodged
     * the SecretMask vocabulary — 'Authorization ' (trailing space,
     * and the leading-space, semicolon, NBSP, and homoglyph siblings)
     * rendered its full Bearer secret UNMASKED through __toString,
     * falsifying the documented redaction contract, and re-opened the
     * t31-r1-10 order-dependence ('Retry-After' beside 'retry-after '
     * coexisted). Names are tokens now; nothing off-grammar
     * constructs in either VO.
     */
    public function testOffGrammarHeaderNamesAreRejectedInBothVos(): void
    {
        $token = FakeSecrets::accessToken();
        $hostile_names = array(
            'Authorization ',
            ' Authorization',
            'Authorization;',
            " Authorization",
            'Author ization',
            'гetry-after',
            'ｒetry-after',
            "Authorization\r\nX-Other",
            "X-Foo\x00",
            "X\tY",
        );

        foreach ($hostile_names as $name) {
            try {
                new HttpRequest('POST', 'https://host.example/', array($name => 'Bearer ' . $token));
                $this->fail(sprintf('An off-grammar header name (%s) must be rejected by the request VO.', addcslashes($name, "\x00..\xFF")));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('RFC 7230 tokens', $e->getMessage());
            }

            try {
                new HttpResponse(200, array($name => 'session=' . $token));
                $this->fail(sprintf('An off-grammar header name (%s) must be rejected by the response VO.', addcslashes($name, "\x00..\xFF")));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('RFC 7230 tokens', $e->getMessage());
            }
        }

        // The order-dependence door is closed with it: a space-suffixed
        // duplicate can no longer coexist with the canonical spelling.
        $this->expectException(\InvalidArgumentException::class);
        new HttpResponse(429, array('Retry-After' => '60', 'retry-after ' => '2'));
    }

    public function testEveryTokenCharacterClassSpellingIsALegalName(): void
    {
        // The full RFC 7230 tchar alphabet ( specials, digits, letters,
        // and the hyphen ) constructs and renders.
        $name = "X-Custom_1~.+^`|!*#\$%&-";
        $request = new HttpRequest('POST', 'https://host.example/', array($name => 'value'));

        $this->assertSame('value', $request->header($name));
        $this->assertStringContainsString($name . ': value', (string) $request);
    }

    public function testHorizontalTabStaysLegalInHeaderValues(): void
    {
        // RFC 7230 field-value: HTAB is legal (and rendered as-is).
        $request = new HttpRequest('POST', 'https://host.example/', array('X-Test' => "a\tb"));

        $this->assertSame("a\tb", $request->header('x-test'));
        $this->assertStringContainsString("X-Test: a\tb", (string) $request);
    }

    public function testObsTextHighBytesStayLegalInHeaderValues(): void
    {
        $request = new HttpRequest('POST', 'https://host.example/', array('X-Test' => "café"));

        $this->assertSame("café", $request->header('x-test'));
        $this->assertStringContainsString('X-Test: café', (string) $request);
    }

    /**
     * Verifier-round pin (t31-r1-19): the control-byte rejection was
     * byte-scoped (C0 minus tab, plus DEL), so the C1 control code
     * points riding as VALID UTF-8 — U+009B CSI (the ANSI escape
     * introducer), U+0085 NEL — and the Unicode line separators
     * U+2028/U+2029 passed in values and rendered verbatim into the
     * safe debug forms (terminal-injection and log-line-forging
     * material in the obs-text-legal encoding). The same class also
     * cannot reach a masked line through the tail of a binary secret
     * anymore: such values reject at construction now.
     */
    public function testUtf8SpelledControlsAreRejectedInHeaderValuesInBothVos(): void
    {
        $hostile_values = array(
            'CSI escape sequence' => "ok\xE2\x80\x94ok\xC2\x9B[31mred\xC2\x9B[0m",
            'NEL' => "ok\xC2\x85newline",
            'U+2028 line separator' => "ok\xE2\x80\xA8forged",
            'U+2029 paragraph separator' => "ok\xE2\x80\xA9forged",
        );

        foreach ($hostile_values as $label => $value) {
            try {
                new HttpRequest('POST', 'https://host.example/', array('X-Test' => $value));
                $this->fail(sprintf('A header value carrying %s must be rejected by the request VO.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }

            try {
                new HttpResponse(200, array('X-Test' => $value));
                $this->fail(sprintf('A header value carrying %s must be rejected by the response VO.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    /**
     * Review-round pin (t31-r1-10): the case-insensitive lookup
     * returned the FIRST match with no duplicate detection — two
     * case-variant spellings made the answer depend on map order (the
     * round's repro: retry-after 2 vs Retry-After 60). Construction
     * rejects the collision in both VOs; distinct names are unaffected.
     */
    public function testCaseVariantDuplicateHeaderNamesAreRejectedInBothVos(): void
    {
        try {
            new HttpRequest('POST', 'https://host.example/', array('retry-after' => '2', 'Retry-After' => '60'));
            $this->fail('Case-variant duplicate header names must be rejected by the request VO.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('case-insensitively', $e->getMessage());
        }

        try {
            new HttpResponse(429, array('Retry-After' => '60', 'RETRY-AFTER' => '2'));
            $this->fail('Case-variant duplicate header names must be rejected by the response VO.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('case-insensitively', $e->getMessage());
        }

        // Case-DISTINCT names coexist as ever; the lookup stays exact.
        $response = new HttpResponse(429, array('Retry-After' => '60', 'X-RateLimit-Remaining' => '42'));
        $this->assertSame('60', $response->header('retry-after'));
        $this->assertSame('42', $response->header('x-ratelimit-remaining'));
    }

    public function testEmptyHeaderNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpRequest('POST', 'https://host.example/', array('' => 'value'));
    }

    /* ---------------------------------------------------------------
     * Redaction pins (request).
     * ---------------------------------------------------------------
     */

    public function testAuthorizationHeaderNeverAppearsInStringForm(): void
    {
        $token = FakeSecrets::accessToken();
        $request = new HttpRequest(
            'POST',
            'https://token-endpoint.example/oauth2/token',
            array('Authorization' => 'Bearer ' . $token)
        );

        $rendered = (string) $request;

        $this->assertStringNotContainsString($token, $rendered);
        $this->assertStringNotContainsString('Bearer ', $rendered);
        // The mask shows the ellipsis plus the last four characters only.
        $this->assertStringContainsString(SecretMask::MASK . substr($token, -4), $rendered);
        $this->assertStringNotContainsString(substr($token, 0, 8), $rendered);
    }

    public function testTokenBearingUrlQueryNeverAppearsInStringForm(): void
    {
        $token = FakeSecrets::accessToken();
        $request = new HttpRequest(
            'GET',
            'https://api.example/v1?access_token=' . $token . '&extra=1'
        );

        $rendered = (string) $request;

        $this->assertStringNotContainsString($token, $rendered);
        $this->assertStringNotContainsString('access_token', $rendered);
        $this->assertStringNotContainsString('extra=1', $rendered);
        $this->assertStringContainsString('GET https://api.example/v1', $rendered);
    }

    public function testUrlUserinfoIsDroppedInRedactedForm(): void
    {
        $request = new HttpRequest('GET', 'https://user:secret-part@example.com/path');

        $this->assertSame('https://example.com/path', $request->redacted_url());
        $this->assertStringNotContainsString('user:secret-part', (string) $request);
    }

    public function testNonDefaultPortSurvivesRedaction(): void
    {
        $request = new HttpRequest('GET', 'https://host.example:8443/path?q=1');

        $this->assertSame('https://host.example:8443/path', $request->redacted_url());
    }

    public function testTokenBearingBodyNeverAppearsInStringForm(): void
    {
        $token = FakeSecrets::refreshToken();
        $request = new HttpRequest(
            'POST',
            'https://token-endpoint.example/oauth2/token',
            array('Content-Type' => 'application/x-www-form-urlencoded'),
            'grant_type=refresh_token&refresh_token=' . $token
        );

        $rendered = (string) $request;

        $this->assertStringNotContainsString($token, $rendered);
        $this->assertStringNotContainsString('grant_type', $rendered);
        $this->assertStringContainsString('[body omitted]', $rendered);
    }

    public function testNonSensitiveHeaderValuesStayVisible(): void
    {
        $request = new HttpRequest(
            'POST',
            'https://token-endpoint.example/',
            array('Content-Type' => 'application/json', 'Accept' => 'application/json')
        );

        $rendered = (string) $request;

        // Masking is selective: harmless headers remain useful in debug forms.
        $this->assertStringContainsString('Content-Type: application/json', $rendered);
        $this->assertStringContainsString('Accept: application/json', $rendered);
    }

    public function testCookieHeaderIsMaskedLikeAuthorization(): void
    {
        $session = 'wpct_fixture_session_' . bin2hex(random_bytes(8));
        $request = new HttpRequest('GET', 'https://host.example/', array('Cookie' => 'session=' . $session));

        $rendered = (string) $request;

        $this->assertStringNotContainsString($session, $rendered);
    }

    /* ---------------------------------------------------------------
     * Response shape and validation.
     * ---------------------------------------------------------------
     */

    public function testValidResponseCarriesItsFacts(): void
    {
        $response = new HttpResponse(429, array('Retry-After' => '2'), 'throttled');

        $this->assertSame(429, $response->status());
        $this->assertSame('2', $response->header('retry-after'));
        $this->assertSame('throttled', $response->body());
    }

    /**
     * @return list<array{0: int}>
     */
    public function invalidStatusProvider(): array
    {
        return array(
            'informational' => array(101),
            'too low' => array(99),
            'too high' => array(600),
        );
    }

    /**
     * @dataProvider invalidStatusProvider
     */
    public function testNonFinalStatusesAreRejected(int $status): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HttpResponse($status);
    }

    public function testResponseStringFormMasksSensitiveHeadersAndOmitsBody(): void
    {
        $token = FakeSecrets::accessToken();
        $response = new HttpResponse(
            200,
            array('Set-Cookie' => 'session=' . $token, 'Content-Type' => 'application/json'),
            '{"access_token": "' . $token . '"}'
        );

        $rendered = (string) $response;

        $this->assertStringNotContainsString($token, $rendered);
        $this->assertStringContainsString('HTTP 200', $rendered);
        $this->assertStringContainsString('Content-Type: application/json', $rendered);
        $this->assertStringContainsString('[body omitted]', $rendered);
    }

    /* ---------------------------------------------------------------
     * Port shape.
     * ---------------------------------------------------------------
     */

    public function testTransportPortShapeIsFixed(): void
    {
        $this->assertTrue(interface_exists(HttpTransportInterface::class));
        $method = new \ReflectionMethod(HttpTransportInterface::class, 'send');

        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame(HttpRequest::class, (string) $method->getParameters()[0]->getType());
        $this->assertSame(HttpResponse::class, (string) $method->getReturnType());
    }

    public function testSecretMaskShortValuesShowEllipsisOnly(): void
    {
        $this->assertSame('…', SecretMask::mask(null));
        $this->assertSame('…', SecretMask::mask(''));
        $this->assertSame('…', SecretMask::mask('short'));
        $this->assertSame('…wxyz', SecretMask::mask('abcdefghijklmnopwxyz'));
    }

    /**
     * Review-round pin (t31-r1-6): the visible tail is the last four
     * CHARACTERS — the byte-wise substr(-4) split a multibyte character
     * mid-sequence, and the masked value was invalid UTF-8.
     */
    public function testMaskOfMultibyteSecretsNeverSplitsACharacter(): void
    {
        // 13 characters, 17 bytes; the four-character tail is entirely
        // two-byte sequences.
        $this->assertSame('…öööö', SecretMask::mask('aaaaaaaaöööö'));

        // Four-byte sequences (emoji): the tail covers four complete
        // characters, up to sixteen bytes.
        $this->assertSame('…😀😀😀😀', SecretMask::mask('😀😀😀😀😀😀😀😀😀'));

        // Fewer than eight CHARACTERS shows nothing, however many bytes
        // the value carries (the old byte threshold split this shape).
        $this->assertSame('…', SecretMask::mask('ööö'));
    }

    /**
     * The reproduced failure mode: an invalid-UTF-8 masked value made
     * json_encode() return false, so the redacted log line was dropped
     * or mangled. Every masked rendering must survive the round trip.
     */
    public function testMaskedRenderingsAlwaysSurviveJsonEncoding(): void
    {
        foreach (array(
            'xxxxxxxxé',                      // byte tail split the final character
            'aaaaaaaaöööö',                   // multibyte tail
            '😀😀😀😀😀😀😀😀😀',             // four-byte sequences
            "xxxxxxxx\xB1",                   // binary continuation byte
            "xxxxxxxxx\xC3",                  // dangling lead byte
            "abcdefghijklmnopwxyz",           // plain ASCII
        ) as $secret) {
            $masked = SecretMask::mask($secret);

            $this->assertNotFalse(json_encode($masked), sprintf('mask() of %s must be valid UTF-8.', addcslashes($secret, "\x00..\xFF")));
            $this->assertSame($masked, json_decode((string) json_encode($masked)));
            $this->assertStringNotContainsString($secret, $masked);
            // The leading bytes stay hidden (byte-wise for pure-ASCII
            // secrets; multibyte tails legitimately repeat characters).
            if (1 === preg_match('/\A[\x00-\x7F]*\z/', $secret)) {
                $this->assertStringNotContainsString(substr($secret, 0, 4), $masked);
            }
        }
    }

    public function testMaskOfBinaryValuesDegradesToValidTrailingBytesOnly(): void
    {
        // A dangling lead byte can never head a valid sequence: the tail
        // sheds characters until nothing valid remains — the bare mask.
        $this->assertSame('…', SecretMask::mask("xxxxxxxxx\xC3"));

        // A trailing continuation byte counts as part of the preceding
        // ASCII character, so this binary value has eight apparent
        // characters — at the threshold, nothing is shown (the old
        // byte threshold split this shape and emitted the raw byte).
        $this->assertSame('…', SecretMask::mask("xxxxxxxx\xB1"));

        // Nine ASCII characters plus a stray trailing byte: the stray
        // byte rides inside every candidate slice, so no slice ever
        // validates — the bare mask, never the raw byte.
        $this->assertSame('…', SecretMask::mask("xxxxxxxxx\xB1"));
    }

    public function testMaskedMultibyteSecretRendersThroughTheRequestForm(): void
    {
        $token = 'xxxxxxxxé';
        $rendered = (string) new HttpRequest(
            'POST',
            'https://token-endpoint.example/',
            array('Authorization' => 'Bearer ' . $token)
        );

        $this->assertNotFalse(json_encode(array('debug' => $rendered)));
        $this->assertStringNotContainsString('Bearer ', $rendered);
        $this->assertStringNotContainsString('xxxxxxxx', $rendered);
        $this->assertStringContainsString('…xxxé', $rendered);
    }

    public function testRequestAndResponseVosAreImmutableWithNoSetters(): void
    {
        foreach (array(HttpRequest::class, HttpResponse::class, HeaderMap::class) as $class) {
            $reflection = new \ReflectionClass($class);
            $this->assertTrue($reflection->isFinal());
            foreach ($reflection->getProperties() as $property) {
                $this->assertTrue($property->isReadOnly(), $class . '::' . $property->getName() . ' must be readonly.');
                $this->assertFalse($property->isStatic());
            }
        }
    }

    /**
     * Review-round lockstep pin (t31-r1-5): the header-map logic — the
     * validation loop, the case-insensitive lookup, and the masked
     * render — was maintained near-verbatim in both VOs. All three live
     * once on HeaderMap now; neither VO may reintroduce a hand-rolled
     * copy (a drift there would reopen exactly the divergence class
     * this round closed).
     */
    public function testBothVosRideTheSharedHeaderMapOwner(): void
    {
        foreach (array(HttpRequest::class, HttpResponse::class) as $class) {
            $source = (string) file_get_contents((new \ReflectionClass($class))->getFileName());

            $this->assertStringContainsString('new HeaderMap(', $source, $class . ' must embed the shared header-map owner.');
            $this->assertStringNotContainsString('must not contain line breaks', $source, $class . ' must not hand-roll header validation.');
            $this->assertStringNotContainsString('strtolower', $source, $class . ' must not hand-roll the case-insensitive lookup.');
            $this->assertStringNotContainsString('is_sensitive_header_name', $source, $class . ' must not hand-roll the masked render.');
        }

        // Non-vacuity: the owner really owns all three halves. (The
        // rejection-message fragment was superseded by t31-r1-9 when
        // the control-byte class replaced the line-break-only check.)
        $owner = (string) file_get_contents((new \ReflectionClass(HeaderMap::class))->getFileName());
        foreach (array('must not contain control characters', 'strtolower', 'is_sensitive_header_name', 'rendered_lines') as $fragment) {
            $this->assertStringContainsString($fragment, $owner, 'HeaderMap must own the ' . $fragment . ' half.');
        }
    }

    public function testHeaderMapRendersMaskedSensitiveAndVerbatimOtherLines(): void
    {
        $token = FakeSecrets::accessToken();
        $map = new HeaderMap(array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'));

        $this->assertSame(
            array('Authorization: ' . SecretMask::MASK . substr($token, -4), 'Content-Type: application/json'),
            $map->rendered_lines()
        );
        $this->assertSame('Bearer ' . $token, $map->header('authorization'));
        $this->assertNull($map->header('absent-header'));
    }
}
