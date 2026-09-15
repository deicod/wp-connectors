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
use Deicod\WpConnectors\Shared\Support\AsciiFold;
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
     * Fix-round pin (t31-r4-12): parse_url() silently truncates a
     * malformed raw port — 'https://host.example:443x/' parses as port
     * 443 (reproduced) — while url() still carries ':443x': a
     * port/authority divergence INSIDE the value object, distinct from
     * the ledgered host-charset acceptance (no divergence there). The
     * raw port substring from the authority must be fully digits before
     * parse_url's port is trusted; the userinfo colon is not a port,
     * and digit spellings (a leading zero included — parse_url's int
     * value is the authority's) stay legal.
     */
    public function testAMalformedRawPortIsRejectedInsteadOfTruncated(): void
    {
        $hostile_urls = array(
            'truncated tail' => 'https://host.example:443x/token',
            'alpha port' => 'https://host.example:8a/',
            'empty port' => 'https://host.example:/token',
            'userinfo does not hide it' => 'https://user:pw@host.example:443x/',
            'float port' => 'https://host.example:44.3/',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A malformed raw port (%s) must be rejected by the shared URL owner, never truncated to parse_url\'s prefix.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('port must be digits', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A malformed raw port (%s) must be rejected by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('port must be digits', $e->getMessage());
            }
        }

        // The divergence the fix closes, pinned as the pre-fix behavior:
        // ':443x' used to construct with authority 'host.example:443'
        // while url() carried the raw ':443x'. Digits stay legal, and
        // the userinfo colon is not a port.
        $userinfo = new HttpRequest('GET', 'https://user:pw@host.example/token');
        $this->assertSame('host.example', Url::parse_validated('https://user:pw@host.example/token')['authority'], 'A colon in userinfo is not a port.');
        $this->assertSame('https://host.example/token', $userinfo->redacted_url(), 'Userinfo drops from the redacted form; the host carries no port.');

        $ported = new HttpRequest('GET', 'https://host.example:8443/token');
        $this->assertSame('https://host.example:8443/token', $ported->redacted_url(), 'A digit port keeps flowing into the authority.');

        $leading_zero = Url::parse_validated('https://host.example:0443/');
        $this->assertSame('host.example:443', $leading_zero['authority'], 'A leading-zero port is digits: accepted, spelled by its int value.');
    }

    /**
     * Fix-round pin (t31-r4-13): the C1 screen banned only the UTF-8
     * SPELLINGS of the control vocabulary, so a lone RAW byte (0x85
     * NEL, 0x9B CSI lead) — invalid UTF-8 — passed parse_url verbatim
     * into the safe debug forms, and json_encode() of the log line
     * returned false (the t31-r1-6 failure mode: the line is dropped,
     * not degraded). The whole URL must be valid UTF-8 now (the raw and
     * the encoded spellings collapse: a raw C1 byte cannot appear
     * outside a multibyte sequence, and the multibyte spellings are the
     * shared pattern's), and every accepted URL's debug form must
     * json_encode to a string, never false.
     */
    public function testARawControlByteInAUrlRejectsAndTheDebugFormStaysJsonEncodable(): void
    {
        $hostile_urls = array(
            'raw NEL in path' => "https://api.example/cb\x85tail",
            'raw CSI lead in path' => "https://api.example/cb\x9Btail",
            'raw NEL in host' => "https://api\x85.evil/callback",
            'truncated two-byte lead' => "https://api.example/cb\xC2",
            'truncated three-byte lead' => "https://api.example/cb\xE2\x80",
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A URL carrying %s must be rejected by the shared URL owner.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('valid UTF-8', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A URL carrying %s must be rejected by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('valid UTF-8', $e->getMessage());
            }
        }

        // The round trip the gate exists to keep: every accepted URL's
        // debug form encodes — json_encode never returns false (the
        // pre-fix raw-0x85 shape constructed and then DROPPED its log
        // line at the encoder).
        $vo = new HttpRequest('GET', 'https://api.example/callback?next=%2Fx', array('Accept' => 'application/json'));
        $this->assertNotFalse(json_encode((string) $vo), 'The safe debug form of an accepted URL must json_encode.');
        $this->assertNotFalse(json_encode($vo->redacted_url()), 'The redacted URL of an accepted request must json_encode.');
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

    /**
     * Fix-round pin (t31-r2-4), SUPERSEDING the old non-string-key pin
     * at this site: PHP coerces a canonical digit-string array key
     * ('123') to an int before any loop sees it, so the is_string gate
     * rejected legal all-digit RFC 7230 tokens ('123' => 'x' — digits
     * are tchars) with a misleading non-string message. Digit names
     * are accepted in their canonical string spelling now, the token
     * grammar deciding as ever (an int key whose spelling is off-
     * grammar would still reject through the grammar gate); the
     * empty-string key stays rejected.
     */
    public function testDigitStringHeaderNamesAreAcceptedNotCoercedAway(): void
    {
        $map = new HeaderMap(array('123' => 'x'));

        $this->assertSame('x', $map->header('123'));
        $this->assertSame(array('123: x'), $map->rendered_lines());

        // headers() carries the name's PHP-canonical array spelling —
        // the integer key for an all-digit name (the engine re-coerces
        // the digit string on every store; same name, canonical form).
        $this->assertSame(array(123 => 'x'), $map->headers());

        // Through the request VO, same story: lookup, render, and the
        // canonical key in headers().
        $request = new HttpRequest('POST', 'https://host.example/', array('456' => 'y'));
        $this->assertSame('y', $request->header('456'));
        $this->assertSame(array(456 => 'y'), $request->headers());
        $this->assertStringContainsString('456: y', (string) $request);

        // And the response VO (t31-r10-5): both headers() docblocks state
        // the integer-key caveat — identical wording, matching the
        // HeaderMap owner — and this is the execution they describe.
        $response = new HttpResponse(200, array('789' => 'z'));
        $this->assertSame('z', $response->header('789'));
        $this->assertSame(array(789 => 'z'), $response->headers());
        $this->assertStringContainsString('789: z', (string) $response);

        // The grammar still owns the boundary: the empty key rejects,
        // and a spelling a digit key cannot produce still rejects.
        try {
            new HeaderMap(array('' => 'value'));
            $this->fail('The empty header name must stay rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty', $e->getMessage());
        }
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
     * Fix-round pin (t31-r2-6): the control-byte rejection did not
     * cover the bidi/override controls — U+202E RLO in a provider
     * response header value rendered with the tail REORDERED
     * (character-spoofing: 'ok<U+202E>evac' reads as the mirrored run
     * 'cave ko' in a bidi-rendering viewer), the sibling of the forged
     * line t31-r1-19 closed. The whole class joins the shared banned
     * vocabulary: the U+202A-U+202E embeddings/overrides and the
     * U+2066-U+2069 isolates, zero-width all, legal in no header value
     * and (one vocabulary) in no URL either.
     */
    public function testBidiOverrideControlsAreRejectedInHeaderValuesInBothVos(): void
    {
        $hostile_values = array(
            'RLO right-to-left override' => "ok\xE2\x80\xAEevac",
            'LRO left-to-right override' => "ok\xE2\x80\xADevac",
            'LRE left-to-right embedding' => "ok\xE2\x80\xAAevac",
            'RLE right-to-left embedding' => "ok\xE2\x80\xABevac",
            'PDF pop directional formatting' => "ok\xE2\x80\xACevac",
            'LRI left-to-right isolate' => "ok\xE2\x81\xA6evac",
            'RLI right-to-left isolate' => "ok\xE2\x81\xA7evac",
            'FSI first strong isolate' => "ok\xE2\x81\xA8evac",
            'PDI pop directional isolate' => "ok\xE2\x81\xA9evac",
            /*
             * Fix-round extension (t31-r8-5): the direction MARKS —
             * zero-width reorder/mirror material spelled BELOW the
             * U+2028-U+202E block (LRM/RLM) and outside the U+2xxx run
             * entirely (ALM) — passed the r2-6 ranges and rendered
             * provider-controlled values reordered/mirrored in the safe
             * debug forms.
             *
             * Fix-round correction (t31-r9-1): the ALM arm carries the
             * REAL bytes now. U+061C ARABIC LETTER MARK is code point
             * 0x061C, whose two-byte UTF-8 encoding is \xD8\x9C; the
             * r8-5 fix spelled the arm \xD9\x9C — the encoding of
             * U+065C (0x065C) — so the reorder-spoof channel the round
             * claimed closed stayed OPEN while a legitimate Arabic
             * vowel was falsely refused (the byte-swap pin is in
             * testTheArabicVowelSignStaysLegalObsTextWhileTheRealAlmRefuses).
             */
            'LRM left-to-right mark (t31-r8-5)' => "ok\xE2\x80\x8Eevac",
            'RLM right-to-left mark (t31-r8-5)' => "ok\xE2\x80\x8Fevac",
            'ALM arabic letter mark (t31-r8-5, corrected t31-r9-1)' => "ok\xD8\x9Cevac",
        );

        foreach ($hostile_values as $label => $value) {
            try {
                new HttpResponse(429, array('Retry-After' => $value));
                $this->fail(sprintf('A response header value carrying %s must be rejected.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }

            try {
                new HttpRequest('POST', 'https://host.example/', array('X-Test' => $value));
                $this->fail(sprintf('A request header value carrying %s must be rejected.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    /**
     * One vocabulary, two surfaces (the t31-r2-1 sharing made this the
     * same constant): the bidi class rides out of the URL position too.
     */
    public function testBidiControlsAreRejectedInTheUrlSurfaceToo(): void
    {
        try {
            new HttpRequest('GET', "https://api.example/cb\xE2\x80\xAEevac");
            $this->fail('A URL carrying RLO must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('control characters', $e->getMessage());
        }

        /*
         * Fix-round extension (t31-r8-5): the direction MARKS join the
         * one vocabulary on the URL surface with no second pattern to
         * drift — the constant is the single owner both surfaces read.
         * ALM corrected to its real bytes (t31-r9-1): \xD8\x9C.
         */
        foreach (array(
            'LRM' => "https://api.example/cb\xE2\x80\x8Eevac",
            'RLM' => "https://api.example/cb\xE2\x80\x8Fevac",
            'ALM' => "https://api.example/cb\xD8\x9Cevac",
        ) as $label => $url) {
            try {
                new HttpRequest('GET', $url);
                $this->fail("A URL carrying {$label} must be rejected (one vocabulary, two surfaces).");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    /**
     * Verifier-round pin (t31-r9-10, the round-9 two-lens verifier pass,
     * adversarially confirmed with end-to-end repros): the r9-1 docblock
     * claim "only format controls are banned" was FALSE as coverage —
     * the invisible bidi-ACTIVE Cf siblings of the banned marks passed
     * on both surfaces: U+070F SYRIAC ABBREVIATION MARK (bidi AL: an
     * invisible STRONG-RTL character — the exact resolution mechanism
     * of the banned ALM/RLM; reproduced rendering verbatim through
     * rendered_lines() and the URL safe-debug form), U+110BD/U+110CD/
     * U+13430-U+1343F (bidi L: invisible strong-LTR, the LRM
     * mechanism), U+0600-U+0605/U+06DD/U+0890/U+0891/U+08E2 (invisible
     * AN, bidi-active in number runs), plus the invisible-neutral
     * homograph class: U+200B-U+200D ZWSP/ZWNJ/ZWJ (ZWJ/ZWNJ alter
     * Arabic glyph joining — invisible bytes changing visible
     * rendering), U+FEFF, and U+00AD. All refused now; the byte
     * spellings below are derived from the code points (verified
     * against the Unicode character database).
     */
    public function testTheInvisibleBidiActiveAndJoinerSiblingsRefuseOnBothSurfaces(): void
    {
        $hostile = array(
            // The confirmed repro headliner: invisible strong-RTL.
            'U+070F Syriac abbreviation mark (strong RTL)' => "ok\xDC\x8Fevac",
            // Invisible strong-LTR (the LRM mechanism), 4-byte arms.
            'U+110BD Kaithi number sign (strong LTR)' => "ok\xF0\x91\x82\xBDevac",
            'U+110CD (strong LTR)' => "ok\xF0\x91\x83\x8Devac",
            'U+13430 Egyptian format control range start' => "ok\xF0\x93\x90\xB0evac",
            'U+1343F Egyptian format control range end' => "ok\xF0\x93\x90\xBFevac",
            // Invisible Arabic number-context marks (bidi AN).
            'U+0600 Arabic number sign (AN)' => "ok\xD8\x80evac",
            'U+0605 (AN, range end)' => "ok\xD8\x85evac",
            'U+06DD end of ayah (AN)' => "ok\xDB\x9Devac",
            'U+0890 (AN)' => "ok\xE0\xA2\x90evac",
            'U+08E2 (AN)' => "ok\xE0\xA3\xA2evac",
            // Invisible-neutral homograph class.
            'U+200B ZWSP' => "ok\xE2\x80\x8Bevac",
            'U+200D ZWJ (glyph joining)' => "ok\xE2\x80\x8Devac",
            'U+FEFF zero-width no-break space' => "ok\xEF\xBB\xBFevac",
            'U+00AD soft hyphen' => "ok\xC2\xADevac",
        );

        foreach ($hostile as $label => $value) {
            try {
                new HeaderMap(array('X-Test' => $value));
                $this->fail("A header value carrying the invisible format character {$label} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }

            try {
                new HttpRequest('GET', "https://api.example/cb{$value}");
                $this->fail("A URL carrying the invisible format character {$label} must be rejected (one vocabulary, two surfaces).");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }

        /*
         * The counter-pin (the curation is about INVISIBILITY and
         * direction/joining effect, never about script): visible Arabic
         * CONTENT stays legal obs-text — a real letter (U+0627 ALEF,
         * bytes \xD8\xA7 — one byte-tail away from the banned
         * \xD8[\x80-\x85] number-sign range) constructs and renders
         * verbatim, exactly like the U+065C vowel sign beside it.
         */
        $visible_content = "ok\xD8\xA7\xD9\x9Cevac"; // ALEF + vowel-sign dot below.
        $response = new HttpResponse(429, array('Retry-After' => $visible_content));
        $this->assertSame($visible_content, $response->header('retry-after'));
        $this->assertStringContainsString("Retry-After: {$visible_content}", (string) $response);
    }

    /**
     * Fix-round pin (t31-r9-1, the byte-swap half of the r8-5 decision):
     * the r8-5 ALM arm banned \xD9\x9C — the UTF-8 encoding of U+065C
     * ARABIC VOWEL SIGN DOT BELOW, a VISIBLE combining vowel sign
     * (Unicode category Mn, bidi class NSM: it decorates a letter, it
     * reorders nothing) — while the documented mark, U+061C ARABIC
     * LETTER MARK (category Cf, bidi class AL: a zero-width format
     * control in the LRM/RLM family), encodes to \xD8\x9C and PASSED
     * (verified against the Unicode character database: both spellings
     * derived from the code points — 0x061C → 0xD8 0x9C, 0x065C →
     * 0xD9 0x9C under the two-byte UTF-8 scheme 110xxxxx 10xxxxxx).
     * The decision the r8-5 round made is "ban ALM", so the vowel sign
     * returns to ALLOWED: it is legitimate content in an Arabic
     * provider-controlled value, not reorder material, and the r1-19
     * obs-text hospitality covers it exactly as it covers every other
     * high byte. Pinned on both surfaces the one vocabulary owns.
     */
    public function testTheArabicVowelSignStaysLegalObsTextWhileTheRealAlmRefuses(): void
    {
        $vowel_sign = "ok\xD9\x9Cevac"; // U+065C, Mn — visible, legal.
        $real_alm = "ok\xD8\x9Cevac"; // U+061C, Cf — zero-width, banned.

        // The vowel sign constructs on the header surface and renders
        // VERBATIM through the safe debug forms (valid UTF-8 obs text,
        // the t31-r8-6 render doctrine: no mask, no percent-encode).
        $response = new HttpResponse(429, array('Retry-After' => $vowel_sign));
        $this->assertSame($vowel_sign, $response->header('retry-after'));
        $this->assertStringContainsString("Retry-After: {$vowel_sign}", (string) $response);

        // And on the URL surface (one vocabulary: what the constant does
        // not ban, neither surface refuses).
        $request = new HttpRequest('GET', "https://api.example/cb\xD9\x9Cevac");
        $this->assertSame("https://api.example/cb\xD9\x9Cevac", (string) $request->url());

        // The REAL mark refuses on both surfaces — the channel r8-5
        // claimed closed and was not (the swapped bytes passed while the
        // vowel sign refused; both directions pinned above and in the
        // two t31-r8-5 tests).
        try {
            new HttpResponse(429, array('Retry-After' => $real_alm));
            $this->fail('The real ALM (U+061C, \\xD8\\x9C) must be refused in a header value.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('control characters', $e->getMessage());
        }

        try {
            new HttpRequest('GET', "https://api.example/cb\xD8\x9Cevac");
            $this->fail('The real ALM (U+061C, \\xD8\\x9C) must be refused in a URL.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('control characters', $e->getMessage());
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
        // the control-byte class replaced the line-break-only check;
        // the 'strtolower' fragment by t31-r2-14 when the fold became
        // the locale-independent AsciiFold::lower.)
        $owner = (string) file_get_contents((new \ReflectionClass(HeaderMap::class))->getFileName());
        foreach (array('must not contain control characters', 'AsciiFold::lower', 'is_sensitive_header_name', 'rendered_lines') as $fragment) {
            $this->assertStringContainsString($fragment, $owner, 'HeaderMap must own the ' . $fragment . ' half.');
        }
    }

    /**
     * Fix-round pin (t31-r2-14), class-closure hardening: every
     * header-name fold site — the duplicate fence, the folded-index
     * lookup, the SecretMask vocabulary match — rides the ONE
     * locale-independent fold. strtolower() consults LC_CTYPE; in a
     * Turkish locale the ASCII capital I does not fold (its lowercase
     * is the two-byte dotless ı), so the fence, the lookup, and the
     * masking vocabulary would silently disagree — the divergence is
     * argued from the fold tables (no tr_* locale is generated on
     * this host), which is why this is hardening, not a reproduced
     * defect.
     */
    public function testHeaderNameFoldingIsLocaleIndependentEverywhere(): void
    {
        $this->assertSame('authorization', AsciiFold::lower('AUTHORIZATION'));

        // Equivalence with the C-locale fold over a hostile corpus —
        // identical bytes here, structurally locale-free everywhere.
        foreach (array(
            'authorization',
            'X-Custom_1~.+^`|!*#$%&-',
            "MixedCase-With-Digits-0123",
            "utf8-\xE2\x82\xAC-\xC3\x9CMLAUT",
            "\xB1\xC3binary\x7F",
            '',
        ) as $value) {
            $this->assertSame(strtolower($value), AsciiFold::lower($value), 'The ASCII fold must match the C-locale fold on: ' . addcslashes($value, "\x00..\xFF"));
        }

        // Class closure: no fold site may spell the locale-sensitive
        // function; both folding classes carry the shared owner's call.
        foreach (array(HeaderMap::class, SecretMask::class) as $class) {
            $source = (string) file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString('strtolower', $source, $class . ' must not spell the locale-sensitive fold.');
            $this->assertStringContainsString('AsciiFold::lower', $source, $class . ' must ride the shared ASCII fold.');
        }

        /*
         * Fix-round extension (t31-r3-10), same class-closure framing:
         * the method token's normalization is the one case-RAISING
         * surface, and it rode the locale-sensitive strtoupper() — the
         * Turkish dotted-I rule maps ASCII 'i' to the two-byte 'İ',
         * bytes the ASCII-only method grammar would then REJECT, so
         * 'post' would stop being a method in exactly the processes
         * whose locale folds it (argued from the fold tables, like the
         * lower() half: no tr_* locale on this host). The upper fold
         * rides the same owner.
         */
        $this->assertSame('POST', AsciiFold::upper('post'));
        foreach (array('post', 'g.e.t', "MixedCase-\xE2\x82\xAC-0123", '') as $value) {
            $this->assertSame(strtoupper($value), AsciiFold::upper($value), 'The ASCII upper fold must match the C-locale fold on: ' . addcslashes($value, "\x00..\xFF"));
        }
        $request = (string) file_get_contents((new \ReflectionClass(HttpRequest::class))->getFileName());
        $this->assertStringNotContainsString('strtoupper', $request, 'HttpRequest must not spell the locale-sensitive upper fold.');
        $this->assertStringContainsString('AsciiFold::upper', $request, 'HttpRequest method normalization must ride the shared ASCII upper fold.');
        $this->assertSame('POST', (new HttpRequest('post', 'https://host.example/'))->method());
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

    /**
     * Fix-round pin (t31-r8-6): rendered_lines() is always VALID UTF-8
     * — the r4-13 doctrine's outcome on the header surface. A header
     * value legally carries RFC 7230 obs-text (t31-r1-19), and a
     * Latin-1 value is obs-text whose bytes are invalid UTF-8:
     * json_encode() of the rendered line returned FALSE — the log line
     * dropped, not degraded — exactly the failure mode r4-13 killed on
     * the URL surface by rejecting the input. The render seam owes the
     * same outcome without rejecting the value: well-formed sequences
     * render verbatim, invalid bytes render percent-encoded.
     */
    public function testRenderedLinesAreAlwaysValidUtf8AndJsonEncodeWhole(): void
    {
        // THE REPRO: a Latin-1 (obs-text) value — legal at construction,
        // invalid as UTF-8, json_encode of the line FALSE pre-fix.
        $map = new HeaderMap(array('X-Note' => "caf\xE9"));
        $lines = $map->rendered_lines();
        $this->assertSame(array('X-Note: caf%E9'), $lines, 'The invalid byte renders percent-encoded — encoded, never dropped.');
        $encoded = json_encode($lines[0]);
        $this->assertNotFalse($encoded, 'A Latin-1 header value must never make json_encode of the rendered line fail.');
        $this->assertSame('X-Note: caf%E9', json_decode($encoded), 'The line round-trips through json_encode.');

        // The value the caller holds is untouched — the gate is at the
        // RENDER seam only (obs-text stays legal at construction).
        $this->assertSame("caf\xE9", $map->header('x-note'));

        // Valid-UTF-8 obs-text renders VERBATIM (the t31-r1-19 pin,
        // restated through the gate): a multibyte sequence is never
        // encoded, beside encoded invalid bytes in the same value.
        $verbatim = new HeaderMap(array('X-Note' => "caf\xC3\xA9 \xE9 \xF0\x9F\x98"));
        $this->assertSame(array("X-Note: caf\xC3\xA9 %E9 %F0%9F%98"), $verbatim->rendered_lines());
        foreach ($verbatim->rendered_lines() as $line) {
            $this->assertNotFalse(json_encode($line), 'Every rendered line json_encodes.');
        }

        // The masked branch holds the same gate by construction
        // (mask() never returns invalid UTF-8) — pinned belt-and-braces:
        // a binary secret's masked line still encodes whole.
        $masked = new HeaderMap(array('Authorization' => "Bearer caf\xE9caf\xE9caf\xE9"));
        foreach ($masked->rendered_lines() as $line) {
            $this->assertNotFalse(json_encode($line), 'A masked line stays valid UTF-8.');
        }

        // End to end through the debug form that embeds the render: the
        // whole safe debug form json_encodes with a Latin-1 value aboard.
        $request = new HttpRequest('POST', 'https://host.example/', array('X-Note' => "caf\xE9"));
        $this->assertNotFalse(json_encode((string) $request), 'The request debug form json_encodes with an obs-text value aboard.');
        $this->assertStringContainsString('X-Note: caf%E9', (string) $request, 'The encoded spelling is what the debug form shows.');
    }

    /**
     * Fix-round pin (t31-r2-11), the grammar-identity pin:
     * METHOD_TOKEN_PATTERN was a second verbatim copy of the RFC 7230
     * tchar grammar — t31-r1-16 declared HeaderMap the single owner
     * for NAMES, and the copy's anchor had already diverged (^ vs \A,
     * behaviorally twin spellings without /m, but the drift direction
     * itself was the finding). The method pattern is a
     * constant-expression alias of the owner now: identical by
     * construction, and a future grammar tightening cannot split the
     * name surface from the method surface.
     */
    public function testTheMethodTokenPatternIsTheHeaderNameGrammar(): void
    {
        $this->assertSame(HeaderMap::NAME_TOKEN_PATTERN, HttpRequest::METHOD_TOKEN_PATTERN);
        $this->assertSame(
            (new \ReflectionClass(HeaderMap::class))->getConstant('NAME_TOKEN_PATTERN'),
            (new \ReflectionClass(HttpRequest::class))->getConstant('METHOD_TOKEN_PATTERN'),
            'The method grammar must be the single-owner spelling, never a re-typed copy.'
        );

        // And the shared grammar still judges both surfaces: every
        // tchar spelling is a legal method, every off-grammar spelling
        // is not (the invalid-method provider already pins the rejections).
        $this->assertSame('G.E.T', (new HttpRequest('g.e.t', 'https://host.example/'))->method());
    }

    /**
     * Fix-round pin (t31-r2-10): the constructor computes each
     * lowercase name for the duplicate fence and then threw the index
     * away — every header() re-scanned the whole map with two
     * strtolower per entry. The index is kept now (name-as-given and
     * value, folded-keyed) and the lookup is one isset probe.
     * Structural half: the index exists and carries what the fence
     * already computed; behavioral half: every case spelling of a
     * name resolves identically through the probe.
     */
    public function testHeaderLookupRidesTheConstructorBuiltFoldedIndex(): void
    {
        $map = new HeaderMap(array('Retry-After' => '60', 'Content-Type' => 'application/json'));

        $index = (new \ReflectionProperty(HeaderMap::class, 'headers_by_lowercase'))->getValue($map);
        $this->assertSame(
            array(
                'retry-after' => array('Retry-After', '60'),
                'content-type' => array('Content-Type', 'application/json'),
            ),
            $index
        );

        foreach (array('retry-after', 'Retry-After', 'RETRY-AFTER', 'rEtRy-aFtEr') as $spelling) {
            $this->assertSame('60', $map->header($spelling), 'The folded-index lookup must resolve ' . $spelling . ' identically.');
        }
        $this->assertNull($map->header('retry-afterx'));

        // The lookup is the probe, never a rescan: the owner spells the
        // isset over the folded index inside header().
        $owner = (string) file_get_contents((new \ReflectionClass(HeaderMap::class))->getFileName());
        $this->assertStringContainsString('isset( $this->headers_by_lowercase[ $folded ] )', $owner);
    }
}
