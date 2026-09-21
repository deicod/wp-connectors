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
     * @return list<array{0: string, 1: string}>
     */
    public function invalidUrlProvider(): array
    {
        return array(
            'relative path' => array('/oauth2/token', 'must be absolute with a scheme and host'),
            'scheme-less host' => array('token-endpoint.example/oauth2/token', 'must be absolute with a scheme and host'),
            'non-http scheme' => array('ftp://token-endpoint.example/token', 'scheme must be http or https'),
            'no host' => array('https:///token', 'must be absolute with a scheme and host'),
            'port out of range' => array('https://host.example:99999/token', 'port is out of range'),
            'port out of range, no path' => array('https://host.example:70000', 'port is out of range'),
            /*
             * glm15-6: parse_url() fails EVERY glued port whose digit run
             * is five digits or more — in-range digits included — so both
             * glue spellings landed at the entry wearing the scheme/host
             * sentence while the short ':443x' glue answers the digits
             * sentence below the entry (one malformed class, two
             * sentences). Both answer the digits sentence now.
             */
            'glued port beyond the range' => array('https://host.example:65536x/token', 'port must be digits'),
            'glued port in range' => array('https://host.example:65534x/token', 'port must be digits'),
            /*
             * glm15-7: the entry probes are anchored at the FIRST
             * authority — the query never feeds the verdict. The
             * unanchored regex restarted at every '://' and matched
             * ':70000' entirely inside the query, so the query-carried
             * spelling wore the port sentence while the real failure
             * was the empty host (driven red at HEAD).
             */
            'query-carried port answers the entry sentence' => array('https://?redirect=https://evil.example:70000', 'must be absolute with a scheme and host'),
            'query-carried glued port likewise' => array('https://?next=https://evil.example:65534x', 'must be absolute with a scheme and host'),
            /*
             * glm16-10: the port screen arms only after a NON-EMPTY
             * authority — the authority being absent is the PRIMARY
             * defect, so the empty-host-with-port-tail spellings wear
             * the host sentence, never the port sentence the glm14-9
             * entry once answered them in (driven red at HEAD:
             * 'https://:70000' -> 'port is out of range').
             */
            'empty host wins over the out-of-range port tail' => array('https://:70000', 'must be absolute with a scheme and host'),
            'empty host wins over the glued port tail' => array('https://:65536x', 'must be absolute with a scheme and host'),
            'empty host behind userinfo wins over the port tail' => array('https://user@:70000', 'must be absolute with a scheme and host'),
            /*
             * glm16-11: the backslash screen outranks the port probe on
             * failed parses — a backslash-bearing spelling answers the
             * backslash sentence whatever glued port tail rides beside
             * it (driven red at HEAD: the digits sentence), the
             * consistent class verdict the whole-input screen owns on
             * the success path. Clean glued ports keep glm15-6's
             * verdicts above.
             */
            'backslash beats the glued port tail on a failed parse' => array('https://evil.example\\host:65536x', 'must not carry a backslash'),
            'backslash beats the glued port tail after the port' => array('https://evil.example:65536x\\x', 'must not carry a backslash'),
            'garbage' => array('https://@@@', 'must be absolute with a scheme and host'),
        );
    }

    /**
     * glm14-9: every row asserts the MESSAGE, never the class alone —
     * the ':99999' leg once asserted only the exception class, so the
     * out-of-range port dying at the entry screen in the scheme/host
     * sentence (parse_url() returns false for ports beyond 65535, the
     * engine's own range check) was invisible to the suite while the
     * range screen below the entry sat dead (driven: ':70000' answered
     * 'must be absolute with a scheme and host'). The entry names the
     * out-of-range port now.
     *
     * @dataProvider invalidUrlProvider
     */
    public function testInvalidUrlsAreRejected(string $url, string $fragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($fragment);

        new HttpRequest('POST', $url);
    }

    /**
     * OCR-round-1 pin (t31-ocr1-2): the empty-host spellings
     * ('http://:8080/', 'http://user@:8080/') are refused EXPLICITLY,
     * not by engine accident. parse_url()'s answer for them is
     * build-dependent — some builds in the supported floor return
     * host => '' (key present, empty string), where isset() passed and
     * a hostless authority constructed; this build returns false
     * outright. The explicit '' leg refuses the spelling on every
     * build, and the legal-host mirrors stay constructible unchanged.
     */
    public function testAnEmptyHostSpellingIsRefusedExplicitlyOnEveryBuild(): void
    {
        $hostile_urls = array(
            'empty host with port' => 'http://:8080/',
            'empty host behind userinfo' => 'http://user@:8080/',
            'empty host no port' => 'http://:/path',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('An empty-host URL (%s) must be refused by the shared URL owner on every build.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('absolute with a scheme and host', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('An empty-host URL (%s) must be refused by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('absolute with a scheme and host', $e->getMessage());
            }
        }

        // The legal mirrors stay constructible, host and port intact.
        $this->assertSame('host.example:8080', Url::parse_validated('http://host.example:8080/')['authority']);
        $this->assertSame('host.example:8080', Url::parse_validated('http://user@host.example:8080/')['authority'], 'Userinfo does not change the host; an empty host behind userinfo is not a host.');
    }

    /**
     * Fix-round pin (t31-r4-12): parse_url() silently truncates a
     * malformed raw port — 'https://host.example:443x/' parses as port
     * 443 (reproduced) — while url() still carries ':443x': a
     * port/authority divergence INSIDE the value object, distinct from
     * the ledgered host-charset acceptance (no divergence there). The
     * raw port substring from the authority must be fully digits before
     * parse_url's port is trusted; the userinfo colon is not a port,
     * and canonically spelled digits stay legal (a leading zero does
     * NOT — t31-ocr25-3 closed that acceptance; its own pin below).
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
    }

    /**
     * OCR-round-28 pin (t31-ocr28-6, DERIVED FIRST then fixed): the
     * authority-termination set was '/?#' only, so a backslash rode the
     * authority verbatim — parse_url() keeps the byte in the host and
     * userinfo (driven: 'http://host.example\evil/x' parsed with host
     * 'host.example\evil', 'https://evil.example\@idp.example/' with
     * the backslash inside the userinfo), the rebuilt authority carried
     * it too, and the raw/redacted pair agreed on the PHP side — but a
     * WHATWG consumer treats '\' at this position as an authority
     * TERMINATOR, and the one browser-facing channel this VO feeds (the
     * device-flow verification URI, passed through raw to the
     * authorization redirect) would send the browser to evil.example
     * while this parse, the redacted forms, and the PHP-side transport
     * (WP_Http rides parse_url) all name idp.example — the host-forgery
     * seam the round-1 space/tab adjudication spared those bytes from
     * ("they render oddly but forge nothing"; the backslash re-splits
     * the authority in a consumer that renders it). RFC 3986's
     * authority grammar carries no backslash anywhere, so the refusal
     * rejects nothing legal (the bracket screens' own doctrine).
     */
    public function testABackslashInTheAuthorityRefusesInsteadOfForgingPastTheTerminationSet(): void
    {
        $hostile_urls = array(
            'backslash inside the host' => 'http://host.example\evil/token',
            'the WHATWG forging shape (backslash in userinfo)' => 'https://evil.example\@idp.example/device',
            'trailing backslash before the query' => 'http://host.example\?next=1',
            /*
             * OCR round 46 (t31-ocr46-5): the PATH half — the screen
             * once probed only $authority, but the URL Standard's
             * path state treats U+005C as a segment SEPARATOR for
             * special schemes, so a browser consuming
             * 'https://host/device\page' requests '/device/page'
             * while this parse kept the byte verbatim in the path:
             * url(), redacted_url(), and every derived surface named
             * a different path than the browser consumes (red at
             * HEAD: constructed). Every scheme this VO admits is a
             * special scheme (http/https), so the whole input is the
             * screen's territory — the r45-2 tab-strip sibling, full
             * input.
             */
            'backslash as a path separator' => 'https://host.example/device\page',
            'backslash inside a query value' => 'https://host.example/token?next=a\b',
            'backslash inside the fragment' => 'https://host.example/token#sec\tion',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A backslash-bearing authority (%s) must be refused by the shared URL owner — red at HEAD it constructed, the byte riding the host/userinfo verbatim.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry a backslash', $e->getMessage(), "The refusal names the backslash class ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A backslash-bearing authority (%s) must be refused by the request VO too — the redacted form would name a host no WHATWG consumer contacts.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry a backslash', $e->getMessage(), "The request VO answers the same refusal ({$label}).");
            }
        }

        // The legal mirrors stay constructible: the round-1 space
        // adjudication keeps its verdict (the byte renders oddly but
        // forges nothing — no consumer re-splits the authority on it),
        // and the rebuilt authority carries the raw host byte verbatim.
        // The adjudication's TAB half fell to the ocr44-1 strip-set
        // screen below — a browser strips the byte before parsing, so
        // it forges a host the parse never named.
        $space_url = 'http://h st.example:8080/token';
        $this->assertSame('h st.example:8080', Url::parse_validated($space_url)['authority'], 'The space half of the round-1 adjudication stands — it forges nothing; the tab and the backslash each did.');
    }

    /**
     * OCR-round-44 pin (t31-ocr44-1): the URL Standard strips ALL
     * ASCII tabs and newlines from the input BEFORE parsing, so the
     * byte mutates the host a browser contacts — "https://id<TAB>p
     * .example/device" sends a WHATWG consumer to idp.example while
     * this parse kept the tab in the authority verbatim and the
     * engine's own parse_url() rewrote it to a THIRD spelling
     * ('id_p.example', probed): one URL naming three hosts over the
     * same browser-facing channel (the device-flow verification URI)
     * the t31-ocr28-6 backslash screen closed — the same doctrine,
     * WHATWG-differential bytes REFUSED at the authority, never
     * stripped. The newline half of the strip set never reaches the
     * authority (the entry control screen refuses LF/CR first); the
     * tab is the byte the adjudication had to re-judge, superseding
     * its round-1 "forges nothing" verdict for that half alone.
     */
    public function testATabInTheAuthorityRefusesInsteadOfStrippingToAnotherHost(): void
    {
        $hostile_urls = array(
            'the WHATWG strip shape (tab in host)' => "https://id\tp.example/device",
            'tab inside the userinfo' => "https://us\ter@idp.example/device",
            'trailing tab before the query' => "https://idp.example\t?next=1",
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A tab-bearing authority (%s) must be refused by the shared URL owner — red at HEAD it constructed, the raw derivation carrying the byte a browser strips into a different host.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry tabs or newlines', $e->getMessage(), "The refusal names the strip-set class ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A tab-bearing authority (%s) must be refused by the request VO too — the redacted form would name a host no WHATWG consumer contacts.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry tabs or newlines', $e->getMessage(), "The request VO answers the same refusal ({$label}).");
            }
        }

        // The screen is byte-exact: the strip set is tabs and
        // newlines, and the SPACE the round-1 adjudication spared
        // stays constructible beside it (a WHATWG consumer fails a
        // space-bearing host rather than contacting another one —
        // no host divergence to refuse).
        $this->assertSame('h st.example', Url::parse_validated('https://h st.example/token')['authority'], 'A space in the host stays legal — the screen refuses the WHATWG strip set, never the adjudication\'s surviving half.');
    }

    /**
     * OCR-round-45 pin (t31-ocr45-1, the r28-6/r44-1 refusal class one
     * generation over): the URL Standard's host parser PERCENT-DECODES a
     * special-scheme host before domain-to-ASCII (§6.4), so a browser
     * loading 'https://id%70.example/' contacts idp.example while this
     * parse and every redacted form name 'id%70.example' verbatim — two
     * hosts named by one URL over the same browser-facing channel (the
     * device-flow verification URI) the backslash and strip-set screens
     * closed for their own bytes. http(s) are special schemes on every
     * spelling this VO accepts, so a '%' in the host answers the
     * refusal — refused from derivation, never decoded (the ocr44-1
     * doctrine: the decode would silently accept a shape no client
     * means to send).
     */
    public function testAPercentEncodedHostRefusesInsteadOfNamingTwoHosts(): void
    {
        $hostile_urls = array(
            'the WHATWG percent-decode shape' => 'https://id%70.example/ver',
            'percent inside a label' => 'https://ho%73t.example/',
            'the percent-encoding of a dot' => 'https://idp%2Eexample/',
            'userinfo does not hide it' => 'https://user@id%70.example/device',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A percent-encoded host (%s) must be refused by the shared URL owner — red at HEAD it constructed, this parse and every redacted form naming the encoded spelling a browser decodes into another host.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry percent-encoded bytes', $e->getMessage(), "The refusal names the percent-decode channel ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A percent-encoded host (%s) must be refused by the request VO too — the redacted form would name a host no browser consumer contacts.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry percent-encoded bytes', $e->getMessage(), "The request VO answers the same refusal ({$label}).");
            }
        }

        // The legal percent-encodings stay constructible: the PATH and
        // QUERY encode freely (no consumer decodes them into the host),
        // and the screen judges the host region alone, never the
        // userinfo's own percent spelling.
        $this->assertSame('host.example', Url::parse_validated('https://host.example/a%20b?q=%41x')['authority'], 'A percent-encoded PATH stays legal — no consumer decodes it into the host.');
        $this->assertSame('host.example', Url::parse_validated('https://us%40er@host.example/')['authority'], 'A percent-encoded USERINFO stays legal — the screen judges the host region alone.');
    }

    /**
     * OCR-round-49 pin (t31-ocr49-4, the WHATWG-differential class the
     * r28-6 backslash, r44-1 strip-set, and r45-1 percent screens close
     * for their own bytes): the URL Standard parses any special-scheme
     * host whose last label "ends in a number" as an IPv4 ADDRESS (§5.3
     * — '010.1.1.1' is 8.1.1.1 under the leading-zero octal, '0x62'
     * labels hex, the bare '2130706433' is 127.0.0.1), while this parse
     * kept such spellings as OPAQUE HOSTNAMES — two hosts named by one
     * URL over the same browser-facing channel the earlier screens
     * guard. The ambiguous class refuses; the one spelling that passes
     * is the canonical dotted quad, where both readings agree.
     */
    public function testAnIpv4AmbiguousHostRefusesInsteadOfNamingTwoHosts(): void
    {
        $hostile_urls = array(
            'leading-zero octal quad' => 'https://010.1.1.1/ver',
            'hex labels' => 'https://0x62.0x90.0.1/',
            'bare trailing-number host' => 'https://2130706433/',
            'leading-zero octet inside an otherwise canonical quad' => 'https://192.168.1.01/token',
            'the bare zero host' => 'https://0/',
            'userinfo does not hide it' => 'https://user@010.1.1.1/device',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('An IPv4-ambiguous host (%s) must be refused by the shared URL owner — red at HEAD it constructed, this parse naming a hostname a browser resolves as a different IPv4 address.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not use an IPv4-ambiguous spelling', $e->getMessage(), "The refusal names the IPv4-ambiguity channel ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('An IPv4-ambiguous host (%s) must be refused by the request VO too — the redacted form would name a host no browser consumer contacts.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not use an IPv4-ambiguous spelling', $e->getMessage(), "The request VO answers the same refusal ({$label}).");
            }
        }

        // The agreeing spellings stay constructible: a canonical
        // dotted quad parses identically on both sides of the
        // differential, and a last label that is not a number leaves
        // the host a DNS name whatever the earlier labels carry.
        $this->assertSame('8.8.8.8', Url::parse_validated('https://8.8.8.8/')['authority'], 'A canonical dotted quad stays legal — the browser\'s IPv4 reading and this parse agree byte for byte.');
        $this->assertSame('127.0.0.1:8080', Url::parse_validated('http://127.0.0.1:8080/callback')['authority'], 'A canonical quad with a port stays legal.');
        $this->assertSame('idp2.example', Url::parse_validated('https://idp2.example/')['authority'], 'A digit-bearing INTERIOR label stays a DNS name — the predicate judges the last label alone.');
        $this->assertSame('example.com.', Url::parse_validated('https://example.com./')['authority'], 'A trailing-dot host keeps its domain reading — the empty part drops before the last label is judged.');

        /*
         * OCR-round-50 legs (t31-ocr50-6, the DIGIT-ONLY fast arm of
         * this battery's own predicate): the Standard's "ends in a
         * number" check answers its digit-only arm (§5.3 step 4)
         * BEFORE the IPv4 radix parse, so '09' IS a number to every
         * WHATWG consumer (the browser routes it to IPv4 parsing,
         * where the leading-zero validation then fails) — while the
         * r49 predicate spelled the radix arm alone, read '09' as
         * octal-invalid, and the host parsed as an OPAQUE HOSTNAME
         * (red at HEAD: constructed) — the accepting direction of the
         * differential this screen exists to close.
         */
        try {
            Url::parse_validated('https://09/');
            $this->fail('A digit-only leading-zero host must be refused by the shared URL owner — red at HEAD it constructed, the radix arm alone misreading the Standard\'s digit-only arm.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must not use an IPv4-ambiguous spelling', $e->getMessage(), 'The refusal stays the IPv4-ambiguity channel — the digit-only arm is the same predicate, one arm over.');
        }
        try {
            new HttpRequest('GET', 'https://host.007/');
            $this->fail('A digit-only leading-zero last label must be refused by the request VO too.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must not use an IPv4-ambiguous spelling', $e->getMessage(), 'The request VO answers the same refusal.');
        }

        /*
         * OCR-round-53 legs (t31-ocr53-1 — the ledger's third driven
         * refutation, and the first against OUR OWN fix): the r52-1
         * "radix table completion" read '0o'/'0b' as URL-Standard
         * prefixes, but the Standard's IPv4 number parser (§5.3,
         * verified against the spec text this round) recognizes
         * exactly TWO prefix spellings — 0x/0X radix 16 and the
         * legacy single leading '0' radix 8; '0o'/'0b' are
         * ECMAScript numeric-literal spellings. For a last label
         * '0b1'/'0o7' the parser strips only the leading '0',
         * leaving 'b1'/'o7', which fail the octal-digit check — the
         * host stays an opaque DOMAIN for every WHATWG consumer, so
         * the r52-1 refusal of 'https://0b1/' was over-refusal of a
         * legal domain (the r52 legs' own '0o9'/'0b2' control lines
         * below — domains a few lines earlier in this same battery —
         * were the internal contradiction: the same prefix, judged
         * by its digits on one line and by its prefix on the other).
         * The predicate reverts to the Standard's two-arm shape; the
         * r52-1 refusal legs flip to construction legs, and the
         * still-refusing spellings ride the r49 hostile loop above
         * (driven there: '010.1.1.1', '0x62.0x90.0.1',
         * '2130706433' — those prefixes ARE Standard-true).
         */
        $this->assertSame('0b1', Url::parse_validated('https://0b1/')['authority'], 'A bare 0b-prefixed host is a DOMAIN — 0b is an ECMAScript spelling the URL Standard never parses, both consumers keep the opaque host.');
        $this->assertSame('0o7', Url::parse_validated('https://0o7/')['authority'], 'A bare 0o-prefixed host is a DOMAIN — the Standard strips only the leading 0 and the remaining o7 fails the octal-digit check.');
        $this->assertSame('example.0b1', Url::parse_validated('https://example.0b1/')['authority'], 'A 0b-prefixed LAST label is a domain on both sides — red at the r52 shape it answered the refusal.');
        $this->assertSame('https://0b1/', (new HttpRequest('GET', 'https://0b1/'))->redacted_url(), 'The request VO constructs the same opaque domain — both consumers agree on the non-number reading.');
        $this->assertSame('0xg', Url::parse_validated('https://0xg/')['authority'], 'A non-radix digit keeps the domain reading — the boundary is the radix\'s own digit class, exactly as at the hex arm.');
        $this->assertSame('0o9', Url::parse_validated('https://0o9/')['authority'], 'An octal prefix with a decimal digit stays a domain — both consumers read the same host.');
        $this->assertSame('0b2', Url::parse_validated('https://0b2/')['authority'], 'A binary prefix with a non-binary digit stays a domain — both consumers read the same host.');
        $this->assertSame('0b1.example', Url::parse_validated('https://0b1.example/')['authority'], 'A 0b label BESIDE a later domain label is a domain on both sides — the predicate judges the last label alone.');
        $this->assertSame('0o7.example', Url::parse_validated('https://0o7.example/')['authority'], 'A 0o label beside a later domain label is a domain on both sides — the last label \'example\' names no number.');
        try {
            Url::parse_validated('https://example.0x1/');
            $this->fail('A hex-prefixed LAST label must still be refused — the two-arm correction must not overcorrect into refusing nothing the Standard parses.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must not use an IPv4-ambiguous spelling', $e->getMessage(), 'The hex arm keeps the IPv4-ambiguity refusal — 0x IS a URL-Standard prefix.');
        }
    }

    /**
     * OCR-round-53 pin (t31-ocr53-2, the fail-open PCRE screens): the
     * ends-in-a-number predicate's probe read `1 === preg_match(...)`
     * — a PCRE abort answered false, `1 === false` was false, and an
     * IPv4-ambiguous spelling constructed as an opaque domain behind
     * the abort, the fail-open direction on exactly the
     * WHATWG-differential screen. The abort is DETERMINISTIC by the
     * same pinned-limit idiom the architecture gate's abort pin rides
     * (t31-ocr2-10): a long hex label exhausts a pinned
     * pcre.backtrack_limit inside the hex-star's backtrack, the limit
     * restored on every exit path. The non-ASCII twin (the byte-class
     * probe the same round re-spelled `0 !==`) is CONSTRUCTION-EVIDENT
     * and driven nowhere: a one-byte class scan carries no
     * backtracking to exhaust and no /u error channel, so PCRE
     * answering false over it is not an injectable state — the
     * spelling is the pin, per the finding's own census rule.
     */
    public function testAPcreAbortRefusesTheIpv4ScreenNeverPassesIt(): void
    {
        $label = '0x' . str_repeat('a', 3000) . 'g';
        $url = "https://{$label}/";
        $host_limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1024');
        try {
            try {
                Url::parse_validated($url);
                $this->fail('A PCRE abort inside the ends-in-a-number probe must REFUSE the URL — red at HEAD it constructed, the spelling an opaque domain behind the fail-open probe.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not use an IPv4-ambiguous spelling', $e->getMessage(), 'The abort rides the screen\'s own refusal sentence — the abort-as-reject idiom (glm36-8).');
            }
        } finally {
            ini_set('pcre.backtrack_limit', $host_limit);
        }

        // The clean direction at the restored default: the same
        // spelling is a DOMAIN, not an abort — the refusal above is
        // the abort, never the size.
        $this->assertSame($label, Url::parse_validated($url)['authority'], 'At the host default limit the same label answers its true reading — a non-radix-digit domain, not a number.');
    }

    /**
     * OCR-round-50 pin (t31-ocr50-4, the WHATWG-differential class
     * the r28-6 backslash, r44-1 strip-set, r45-1 percent, and r49-4
     * IPv4 screens close for their own bytes): the URL Standard runs
     * domain-to-ASCII over a special-scheme host before resolving it
     * (§6.4), so a browser loading 'https://bücher.example/' contacts
     * 'xn--bcher-kva.example' while this parse, the rebuilt
     * authority, and every redacted form kept the raw UTF-8 host
     * bytes — two hosts named by one URL over the same browser-facing
     * channel (the device-flow verification URI, passed through raw).
     * The non-ASCII host refuses — REFUSED from derivation, never
     * punycode-converted (the ocr44-1 doctrine): write the host in
     * its punycode (xn--) spelling, where both readings agree.
     */
    public function testANonAsciiHostRefusesInsteadOfNamingTwoHosts(): void
    {
        $hostile_urls = array(
            'the IDN spelling' => 'https://bücher.example/ver',
            'a non-ASCII label beside ASCII ones' => 'https://exämple.test/',
            'userinfo does not hide it' => 'https://user@bücher.example/device',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A non-ASCII (IDN) host (%s) must be refused by the shared URL owner — red at HEAD it constructed, this parse and every redacted form naming the raw UTF-8 spelling a browser resolves at the punycode host.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('host must be ASCII', $e->getMessage(), "The refusal names the domain-to-ASCII channel ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A non-ASCII (IDN) host (%s) must be refused by the request VO too — the redacted form would name a host no browser consumer contacts.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('host must be ASCII', $e->getMessage(), "The request VO answers the same refusal ({$label}).");
            }
        }

        // The agreeing spellings stay constructible: a pure-ASCII host
        // parses identically on both sides of the differential (the
        // punycode spelling itself IS the ASCII spelling a browser
        // resolves), and non-ASCII stays legal in the PATH, where no
        // consumer's reading resolves it into the host.
        $this->assertSame('xn--bcher-kva.example', Url::parse_validated('https://xn--bcher-kva.example/')['authority'], 'The punycode spelling stays legal — it is the ASCII spelling both readings name.');
        $this->assertSame('host.example', Url::parse_validated('https://host.example/bücher')['authority'], 'A non-ASCII PATH stays legal — the screen judges the host region alone.');
    }

    /**
     * OCR-round-45 pin (t31-ocr45-2, the r44-1 screen's whole-input
     * spelling): the URL Standard removes tabs and newlines from the
     * ENTIRE input before parsing — never the authority alone — so a
     * tab in the PATH or QUERY rode validation green while every
     * WHATWG consumer saw the stripped spelling ('/verify?code=abcd'),
     * and the engine's own parse_url() rewrote the query's tab to a
     * THIRD spelling ('code=ab_cd', probed): three URLs named by one
     * input. The strip-set probe rides the ENTRY (the whole input),
     * the same refusal doctrine as 45-1 — never a silent strip.
     */
    public function testATabAnywhereInTheUrlRefusesNotOnlyInTheAuthority(): void
    {
        $hostile_urls = array(
            'tab in the path' => "https://host.example/ver\tify",
            'tab in the query' => "https://host.example/verify?code=ab\tcd",
            'tab in the fragment' => "https://host.example/verify#frag\tment",
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A tab-bearing URL (%s) must be refused by the shared URL owner — red at HEAD it constructed, the caller holding the tabbed spelling a browser strips into a different URL.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry tabs or newlines', $e->getMessage(), "The refusal names the strip-set class over the whole input ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A tab-bearing URL (%s) must be refused by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry tabs or newlines', $e->getMessage(), "The request VO answers the same refusal ({$label}).");
            }
        }

        // The clean spelling beside every leg stays constructible (the
        // redaction drops the query by its own contract — the redacted
        // form carries scheme, authority, path only).
        $this->assertSame('https://host.example/verify', (new HttpRequest('GET', 'https://host.example/verify?code=abcd'))->redacted_url(), 'A tab-free URL keeps flowing through every screen.');
    }

    /**
     * OCR-round-56 pin (t31-ocr56-2, the §4.1 step 1 the r44-1/45-2
     * strip-set screen rode without): the URL Standard strips
     * leading/trailing C0-control-or-space from the WHOLE input
     * before the tab/newline pass — 'https://device.example/verify '
     * is '/verify' in every WHATWG consumer while this parse kept
     * the space verbatim (the trailing edge constructed green at
     * HEAD; the leading edge refused hostless, parse_url() handing
     * the space-stuck spelling back as a path), the same
     * two-consumers divergence class the tab byte's refusal exists
     * to kill. The edges strip to the browser's own spelling (an
     * edge byte names nothing — both readings agree on one URL);
     * the interior keeps its adjudicated verdicts: space legal,
     * tab/newline refused.
     */
    public function testEdgeWhitespaceStripsToTheWhatwgSpellingTheInteriorKeepsItsVerdicts(): void
    {
        $edge_spellings = array(
            'trailing space' => 'https://device.example/verify ',
            'leading space' => ' https://device.example/verify',
            "trailing tab (step 1 owns the tab's edge)" => "https://device.example/verify\t",
            "leading tab (step 1 owns the tab's edge)" => "\thttps://device.example/verify",
            'both edges at once' => " \thttps://device.example/verify\t ",
        );

        foreach ($edge_spellings as $label => $url) {
            $this->assertSame('/verify', Url::parse_validated($url)['path'], "The edge strip is the browser's own verdict — the parse names the path every WHATWG consumer requests ({$label}; red at HEAD: the space edges kept or refused the space-stuck spelling, the tab edges refused).");
            $this->assertSame('https://device.example/verify', (new HttpRequest('GET', $url))->redacted_url(), "The redacted form derives from the stripped parse ({$label}).");
        }

        // The interior keeps its verdicts — the boundary this round's
        // strip draws: edge strips, interior refuses. The interior
        // space stays legal (the adjudication's surviving half), the
        // interior tab still rides the ocr45-2 refusal.
        $this->assertSame('h st.example', Url::parse_validated('https://h st.example/token')['authority'], 'An interior space keeps its adjudicated verdict — the screen strips the Standard\'s edge set, never the interior.');
        try {
            Url::parse_validated("https://host.example/ver\tify");
            $this->fail('An interior tab still refuses — the edge strip never widens into the interior.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must not carry tabs or newlines', $e->getMessage(), 'The interior refusal keeps its strip-set class.');
        }

        /*
         * OCR-round-57 pin (t31-ocr57-4 — the STORED side of the
         * r56-2 strip): the request VO kept the caller's raw bytes
         * while Url::parse_validated() judged the stripped spelling,
         * so an accepted ' https://device.example/verify '
         * constructed with url() holding edge bytes no screen ever
         * judged and redacted_url() deriving from a DIFFERENT
         * spelling (driven at HEAD) — the raw/derived divergence the
         * agreement doctrine (r25-3) refuses. The stored spelling is
         * the validated spelling now (the §4.1 step-1 shape through
         * the ONE owner Url rides), and a URL with no edge bytes
         * stores byte-exact — the strip touches nothing else.
         */
        $edge_request = new HttpRequest('GET', " \thttps://device.example/verify\t ");
        $this->assertSame('https://device.example/verify', $edge_request->url(), 'url() names exactly the bytes the screens validated — the edge bytes are stripped BEFORE storing (red at HEAD: url() held them while redacted_url() derived from the stripped parse).');
        $this->assertSame('https://device.example/verify', $edge_request->redacted_url(), 'The redacted form derives from the same stored spelling — one verdict across every derived surface.');
        $clean = new HttpRequest('GET', 'https://device.example/verify?code=abcd');
        $this->assertSame('https://device.example/verify?code=abcd', $clean->url(), 'A URL with no edge bytes stores byte-exact — the query rides verbatim, the strip is the browser\'s own first verdict and touches nothing else.');
    }

    /**
     * OCR-round-60 pin (t31-ocr60-6 — the §4.1 order made real): the
     * entry control-byte screen ran BEFORE the §4.1 step-1 edge strip,
     * and it bans every C0 byte except tab at ANY position — so an
     * edge \r, \x0B, \x0C, or NUL refused before the strip could
     * remove it, the strip degrading to trim(" \t") against the
     * docblock's own promise ("an edge byte names nothing … both
     * readings agree on one URL", "Both steps ride the Standard's
     * order") and the two URL consumers rendering OPPOSITE verdicts
     * for byte-identical input. The strip runs BEFORE the screen now
     * (verified against the Standard first: §4.1 step 1 strips
     * C0-control-or-space at the edges — all of it): the edge C0
     * bytes parse to the stripped URL (red at HEAD: refused), the
     * interior control bytes still refusing beside them.
     */
    public function testTheSection41EdgeStripRunsBeforeTheControlByteScreen(): void
    {
        $edge_control_spellings = array(
            'leading CR, trailing VT' => "\r https://device.example/verify \x0B",
            'leading NUL and US' => "\x00\x1Fhttps://device.example/verify",
            'trailing form feed' => "https://device.example/verify\x0C",
        );

        foreach ($edge_control_spellings as $label => $url) {
            $this->assertSame('/verify', Url::parse_validated($url)['path'], "The edge strip is the Standard's own first verdict — an edge C0 byte names nothing, and the parse names the stripped path every WHATWG consumer requests ({$label}; red at HEAD: the control screen refused the byte before the strip could remove it).");
            $this->assertSame('https://device.example/verify', (new HttpRequest('GET', $url))->redacted_url(), "The redacted form derives from the stripped parse ({$label}).");
        }

        // The interior keeps its verdicts: the screen refuses every
        // interior C0 byte (tab excepted — the strip-set screen one
        // step below owns it), exactly as before the reorder.
        foreach (array(
            'interior CR' => "https://host.example/a\r b",
            'interior vertical tab' => "https://host.example/a\x0Bb",
            'interior form feed' => "https://host.example/a\x0Cb",
            'interior NEL (the C1 spelling)' => "https://host.example/cb\xC2\x85nel",
        ) as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail("An interior control byte ({$label}) must still refuse — the edge strip never widens into the interior.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage(), "The interior refusal keeps the control-screen class ({$label}).");
            }
        }
    }

    /**
     * OCR-round-57 pin (t31-ocr57-1 — the DOT-SEGMENT member the
     * WHATWG-differential screen family never owned): the Standard's
     * path state resolves '.' and '..' segments in every WHATWG
     * consumer while this parse stored them verbatim (driven: every
     * spelling below constructed green at HEAD), so '/a/./b' and
     * '/a/../b' named two paths over the same browser-facing channel
     * the tab, backslash, percent, IPv4, and IDN screens already
     * closed for their own bytes. The screen refuses the family's
     * whole census — the trailing shapes (a trailing '/.' or '/..'
     * appends '/' in a browser), the clamping shape ('..' past the
     * root clamps at the root per the Standard's shorten step), the
     * %2e spellings (the Standard's own §4.1 segment definitions are
     * ASCII case-insensitive over them), and a dot segment before
     * the query (the path state resolves it before '?' — the query
     * region itself never resolves). Dots INSIDE segments are legal
     * and untouched, and the opaque-path carve-out is unreachable
     * here by construction (every scheme this VO admits is special,
     * and a special URL's path is never opaque — the census names
     * it).
     */
    public function testADotSegmentPathRefusesInsteadOfNamingTwoPaths(): void
    {
        $hostile_urls = array(
            'single-dot mid-path' => 'https://device.example/a/./b',
            'double-dot mid-path' => 'https://device.example/a/../b',
            'trailing double-dot (a browser appends "/")' => 'https://device.example/a/b/..',
            'trailing single-dot (a browser appends "/")' => 'https://device.example/a/b/.',
            'the clamping shape (".." past the root clamps at the root)' => 'https://device.example/..',
            'the percent single-dot spelling (§4.1 is case-insensitive over it)' => 'https://device.example/a/%2E/b',
            'the percent double-dot spelling' => 'https://device.example/a/b/%2e%2e',
            'a dot segment right before the query still resolves there' => 'https://device.example/a/..?next=/x',
            'userinfo does not hide it' => 'https://user:pw@device.example/a/../b',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A dot-segment-bearing path (%s) must be refused by the shared URL owner — every WHATWG consumer resolves it to a different path while this parse stores it verbatim.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry dot segments', $e->getMessage(), "The refusal names the dot-segment class ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A dot-segment-bearing path (%s) must be refused by the request VO too — one verdict, no constructed VO ever carries the divergence.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not carry dot segments', $e->getMessage(), "The request VO rides the same screen ({$label}).");
            }
        }

        // Dots INSIDE segments are legal and untouched — the Standard
        // resolves whole segments, never bytes inside them; and the
        // no-path spelling defaults to '/' with nothing to resolve.
        $this->assertSame('/a.b/c', Url::parse_validated('https://device.example/a.b/c')['path'], 'A dot inside a segment is not a dot segment — the boundary is the whole segment.');
        $this->assertSame('/', Url::parse_validated('https://device.example')['path'], 'The no-path spelling defaults to "/" — nothing to resolve, nothing to refuse.');
    }

    /**
     * OCR-round-25 pin (t31-ocr25-3): the leading-zero port spelling
     * slips the raw digit screen — ':0443' IS digits — while
     * parse_url() normalizes the value to 443: the value object
     * carried url() with ':0443' against an authority spelling ':443',
     * the exact raw/redacted divergence the screen exists to kill (the
     * r4-12 class one spelling over). url() holds the caller's bytes
     * verbatim, so agreement cannot come from normalizing the raw side
     * — the non-canonical spelling REFUSES, the refusal naming the
     * canonical one (the r4-12 acceptance of the spelling — "spelled
     * by its int value" — is what this close reverses).
     */
    public function testALeadingZeroPortSpellingRefusesInsteadOfDiverging(): void
    {
        $hostile_urls = array(
            'the divergence repro' => 'https://host.example:0443/token',
            'double zero' => 'https://host.example:0080/',
            'userinfo does not hide it' => 'https://user:pw@host.example:0443/',
            'bracket host rides the same screen' => 'http://[::1]:0443/token',
            'multi-@ authority rides the same screen' => 'https://user@evil@host.example:0443/',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A leading-zero port spelling (%s) must be refused by the shared URL owner — url() would keep the raw zeros while the authority spells the int value.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('without leading zeros', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A leading-zero port spelling (%s) must be refused by the request VO too — one verdict, no constructed VO ever carries the divergence.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('without leading zeros', $e->getMessage());
            }
        }

        /*
         * ENGINE PREMISE, probed: the over-long spelling refuses one
         * screen EARLIER on this build — parse_url() itself answers
         * false for a port spelled with more than five digits
         * (':000443' probed false, ':00443' probed 443), so the entry
         * screen's own sentence fires before the leading-zero screen
         * ever sees the spelling. The class is closed either way (the
         * spelling never constructs); the belt for a build whose
         * parse_url() accepts it is the leading-zero screen above, per
         * the t31-ocr1-2 doctrine over build-dependent parse_url()
         * answers.
         */
        try {
            Url::parse_validated('https://host.example:000443/');
            $this->fail('An over-long leading-zero port must be refused by whichever screen fires first.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(
                false !== strpos($e->getMessage(), 'without leading zeros') || false !== strpos($e->getMessage(), 'absolute with a scheme and host'),
                'The over-long spelling refuses through this build\'s entry screen (parse_url() answers false past five port digits) or the leading-zero screen — never constructs.'
            );
        }

        // The canonical spellings stay green and agree with themselves:
        // url() carries ':8443', the authority and the redacted form
        // spell ':8443' — one verdict across every form.
        $ported = new HttpRequest('GET', 'https://host.example:8443/token');
        $this->assertSame('https://host.example:8443/token', $ported->url());
        $this->assertSame('host.example:8443', Url::parse_validated('https://host.example:8443/token')['authority']);
        $this->assertSame('https://host.example:8443/token', $ported->redacted_url());
    }

    /**
     * OCR-round-25 pin (t31-ocr25-1): a multi-'@' authority splits ONCE,
     * authority-wide — the LAST '@' is the userinfo boundary (the
     * WHATWG/curl split), and the raw screen and the rebuilt authority
     * ride the same derivation. Pre-fix the rebuild rode parse_url()'s
     * host/port answers while the screens judged the raw last-'@'
     * segment, so an engine whose parse_url() ends userinfo at the
     * FIRST '@' would have validated one host and named another — the
     * redacted authority naming a host the transport never contacts.
     * Probed on this engine (PHP 8.5.10, zend_memrchr): parse_url() is
     * itself a last-'@' splitter, so the legs below pin the INVARIANT
     * (same verdict raw and redacted, the last-'@' host named) rather
     * than a constructible divergence — the agreement holds by
     * construction now, never by engine accident (the t31-ocr1-2
     * doctrine over build-dependent parse_url() answers).
     */
    public function testAMultiAtAuthorityNamesTheLastAtHostOnEveryPath(): void
    {
        $shapes = array(
            'userinfo carrying a second @' => array('https://user:pw@evil@host.example/token', 'host.example', 'https://host.example/token'),
            'a colon inside the last userinfo segment is not a port' => array('https://user@evil:pw@host.example/token', 'host.example', 'https://host.example/token'),
            'the earlier @-bearing spelling loses to the last @' => array('https://user@h1.example:99@h2.example/token', 'h2.example', 'https://h2.example/token'),
            'a port rides the last @ host only' => array('https://a@b@host.example:8443/token', 'host.example:8443', 'https://host.example:8443/token'),
            'the host itself after one userinfo @' => array('https://host.example@evil.example/token', 'evil.example', 'https://evil.example/token'),
            'an empty userinfo is still a userinfo boundary' => array('https://@host.example/token', 'host.example', 'https://host.example/token'),
        );

        foreach ($shapes as $label => $spec) {
            list($url, $authority, $redacted) = $spec;
            $this->assertSame($authority, Url::parse_validated($url)['authority'], "The rebuilt authority names the LAST-'@' host — the ONE split ({$label}).");
            $request = new HttpRequest('GET', $url);
            $this->assertSame($redacted, $request->redacted_url(), "The redacted form and the raw parse answer one verdict — the authority never names a host the transport does not contact ({$label}).");
        }

        // A malformed port still refuses through the SAME split: the
        // ':99' of the losing userinfo spelling never reaches the digit
        // screen, but the last-'@' host's own malformed tail does.
        foreach (array(
            'a malformed port on the last @ host' => 'https://user@h1.example@h2.example:443x/token',
            'a malformed port behind a multi-@ userinfo' => 'https://u@v@w@host.example:8a/',
        ) as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A malformed port behind a multi-@ userinfo (%s) must be rejected through the one split.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('port must be digits', $e->getMessage());
            }
        }

        /*
         * The raw-derivation fold pin (the round's verifier, rd-1):
         * the agreement the one split exists for — authority() spells
         * exactly the bytes url() carries, whatever the engine's own
         * host spelling would be. Re-staged over the SPACE host
         * (t31-ocr44-1): the former tab staging rode the round-1
         * adjudication's tab half, whose supersession refuses that
         * spelling now; the space is the adjudication's surviving
         * byte, and this engine's parse_url() carries it verbatim —
         * the agreement pin holds over a legal host the raw
         * derivation still spells byte-for-byte.
         */
        $spaceHostUrl = 'https://host.example well/token';
        $this->assertSame('host.example well', Url::parse_validated($spaceHostUrl)['authority'], 'The rebuilt authority carries the raw host byte verbatim — never the engine parse’s own host spelling (the one-split agreement, pinned).');
        $this->assertSame('https://host.example well/token', (new HttpRequest('GET', $spaceHostUrl))->redacted_url(), 'The redacted form and the raw parse answer one verdict over the space-bearing host.');
    }

    /**
     * Verifier-round pin (t31-r11-3, generalized by t31-r11-11): the
     * port screen's colon search starts AFTER the last ']', so anything
     * GLUED to the closing bracket never met the digit check:
     * parse_url() misreads the whole class IDENTICALLY (host '[:',
     * port 1 — reproduced for digits, letters, spaces, punctuation:
     * 159 visible-ASCII spellings), and the rebuilt and redacted
     * authorities diverged from the raw URL, the exact class t31-r4-12
     * chartered the screen to kill. The rule is the authority grammar
     * whole now: after the last ']' comes a ':' or the end of the
     * authority (read as the allow form, abort-refusing), while every
     * legal bracket authority shape stays green.
     */
    public function testAGluedPortAfterABracketedHostIsRejected(): void
    {
        $hostile_urls = array(
            'the repro' => 'http://[::1]80/',
            'no path' => 'http://[::1]80',
            'https twin' => 'https://[::1]80/',
            'full IPv6 host' => 'http://[fe80::1]443/',
            'userinfo does not hide it' => 'http://user:pw@[::1]80/',
            'a letter glued (t31-r11-11)' => 'http://[::1]x/',
            'a space glued (t31-r11-11)' => 'http://[::1] 80/',
            'punctuation glued (t31-r11-11)' => 'http://[::1],/',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('Anything glued to the closing bracket (%s) must be rejected by the shared URL owner.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('bracketed host must be followed by a colon port', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('Anything glued to the closing bracket (%s) must be rejected by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('bracketed host must be followed by a colon port', $e->getMessage());
            }
        }

        // The legal bracket authorities stay green: bare, coloned port
        // (empty port spelled by the r4-12 digit check), and the
        // port's int value in the rebuilt authority.
        $this->assertSame('[::1]', Url::parse_validated('http://[::1]/token')['authority'], 'A bare bracketed host stays legal.');
        $this->assertSame('[::1]:8080', Url::parse_validated('http://[::1]:8080/token')['authority'], 'A bracketed host with a coloned port stays legal.');
        $this->assertSame('https://[::1]:8443/token', (new HttpRequest('GET', 'https://[::1]:8443/token'))->redacted_url(), 'The redacted form of a legal bracket authority keeps host and port.');
        try {
            Url::parse_validated('http://[::1]:/');
            $this->fail('An empty port after the bracket colon still rejects through the r4-12 digit check.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('port must be digits', $e->getMessage());
        }
    }

    /**
     * Review-round pin (t31-r12-2): the glued-bracket screen trusted the
     * last ']' as the IPv6 closer without asking whether a matching '['
     * exists — and parse_url() misread every opener-less spelling:
     * 'http://host:44x]/p' was ACCEPTED with authority 'host:44' (the
     * port truncated at the raw ']'), 'http://example.com:8080]/x'
     * constructed with url() carrying ':8080]' while the redacted form
     * dropped the bracket (a value object internally inconsistent), and
     * bare 'a]' / ']]]' passed the brackets verbatim into the authority.
     * A ']' is only legal as the IPv6 closer of ONE well-formed bracket
     * pair; the legal bracket authorities stay green beside it.
     */
    public function testALoneBracketInAnAuthorityIsRejected(): void
    {
        $hostile_urls = array(
            'the truncated-port repro' => 'http://host:44x]/p',
            'the VO-inconsistency repro' => 'http://example.com:8080]/x',
            'a bare bracket host' => 'http://a]/x',
            'three closers' => 'http://]]]/x',
            'closer with no port' => 'http://host]/token',
            'a second closer' => 'http://[::1]]/token',
            'a second opener' => 'http://[[::1]/token',
            'opener after closer' => 'http://]a[/token',
            'https twin' => 'https://host.example:8443]/token',
            'userinfo does not hide it' => 'http://user:pw@host.example:80]/x',
            'a lone opener' => 'http://[::1/token',
            'the empty literal (t31-r12-18)' => 'http://[]/x',
            'a mid-host pair (t31-r12-18)' => 'http://a[b]/x',
            'a mid-host pair with port (t31-r12-18)' => 'http://x[y]:8080/x',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A lone or doubled bracket in the authority (%s) must be rejected by the shared URL owner.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('one well-formed IP literal', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A lone or doubled bracket in the authority (%s) must be rejected by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('one well-formed IP literal', $e->getMessage());
            }
        }

        // The legal bracket authorities stay green beside the pins above.
        $this->assertSame('[::1]:443', Url::parse_validated('http://[::1]:443/token')['authority'], 'A well-formed bracket authority with a port stays legal.');
        $this->assertSame('[fe80::1]', Url::parse_validated('http://[fe80::1]/token')['authority'], 'A well-formed full-IPv6 authority stays legal.');
    }

    /**
     * OCR-round-2 pin (t31-ocr2-5), beside the r11-11/r12-2 bracket
     * pins: the screen validated placement and pairing, never the
     * literal's CONTENT — 'http://[abc]/x' passed every screen and the
     * VO constructed with an authority that is not an IPv6 literal,
     * contradicting the refusal message's own claim ("one well-formed
     * IP literal"). The literal itself is judged now (engine
     * FILTER_VALIDATE_IP, IPV6 flag, on the inner literal), the
     * garbage refuses, and the true literals stay green — including
     * the glued-garbage twin, which still refuses at ITS screen (the
     * inner literal '::1' is legal; the refusal is the glue's).
     */
    public function testABracketedHostThatIsNotAnIpv6LiteralIsRejected(): void
    {
        $hostile_urls = array(
            'the repro (letters)' => 'http://[abc]/x',
            'https twin' => 'https://[abc]/token',
            'bad hex digit' => 'http://[1234::g]/x',
            'five groups' => 'http://[1:2:3:4:5]/x',
            'dotted quad inside brackets' => 'http://[127.0.0.1]/x',
            'userinfo does not hide it' => 'http://user:pw@[abc]/x',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A bracketed host that is not an IPv6 literal (%s) must be rejected by the shared URL owner.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must be a well-formed IPv6 address', $e->getMessage());
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A bracketed host that is not an IPv6 literal (%s) must be rejected by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must be a well-formed IPv6 address', $e->getMessage());
            }
        }

        // Glued garbage with a LEGAL inner literal still refuses at its
        // own (glue) screen — the content leg does not swallow it.
        try {
            Url::parse_validated('http://[::1]x/');
            $this->fail('Garbage glued to a legal IPv6 literal must still refuse.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('bracketed host must be followed by a colon port', $e->getMessage());
        }

        // The true literals stay green: loopback, full form, and the
        // port-bearing shape with the rebuilt authority intact.
        $this->assertSame('[::1]', Url::parse_validated('http://[::1]/token')['authority']);
        $this->assertSame('[2001:db8::1]', Url::parse_validated('http://[2001:db8::1]/token')['authority']);
        $this->assertSame('[2001:db8::1]:8443', Url::parse_validated('http://[2001:db8::1]:8443/token')['authority']);
        $this->assertSame('[ffff::1]', Url::parse_validated('http://[FFFF::1]/token')['authority'], 'Uppercase hex is legal IPv6 (the rebuilt authority folds it, as every host folds).');
    }

    /**
     * OCR-round-45 pin (t31-ocr45-3, the ocr2-5 content leg's named
     * verdict): 'https://[fe80::1%25eth0]/' is an RFC 6874 spelling the
     * WHATWG parser accepts (the browser-facing channel this file
     * cites), but the engine's FILTER_VALIDATE_IP — the ONE IP-literal
     * validator the content screen charters — rejects the %25-spelled
     * literal, and the PHP-side transport cannot resolve it. The
     * spelling still refuses (no hand-rolled second IP grammar beside
     * the engine's validator for a link-local-scoped host no http(s)
     * endpoint client means to send) — but under its OWN name now,
     * never the generic malformed-host sentence that lied about the
     * class (red at HEAD: the ocr2-5 battery's zone-id row answered
     * 'must be a well-formed IPv6 address', which names no zone id).
     */
    public function testABracketedZoneIdentifierAnswersItsOwnNamedRefusal(): void
    {
        $hostile_urls = array(
            'the RFC 6874 repro' => 'https://[fe80::1%25eth0]/',
            'a zone id with a port' => 'http://[fe80::1%25eth0]:8443/token',
            'a zone id behind userinfo' => 'http://user:pw@[fe80::1%25eth0]/x',
            'the bare percent (no %25 spelling)' => 'http://[fe80::1%eth0]/x',
        );

        foreach ($hostile_urls as $label => $url) {
            try {
                Url::parse_validated($url);
                $this->fail(sprintf('A bracketed zone-identifier host (%s) must be refused by the shared URL owner.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('zone identifier', $e->getMessage(), "The refusal names the zone-id class, never the generic malformed-host sentence ({$label}).");
            }

            try {
                new HttpRequest('GET', $url);
                $this->fail(sprintf('A bracketed zone-identifier host (%s) must be refused by the request VO too.', $label));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('zone identifier', $e->getMessage(), "The request VO answers the same refusal ({$label}).");
            }
        }

        // Plain IPv6 literals ride the content leg unchanged — the
        // named verdict owns the percent spelling alone.
        $this->assertSame('[fe80::1]', Url::parse_validated('http://[fe80::1]/token')['authority'], 'A plain link-local literal stays legal — the screen refuses the zone-id spelling, never the address.');
    }

    /**
     * Review-round pin (t31-r12-8): the post-parse host re-check. The
     * whole-URL UTF-8 probe at entry guarantees the INPUT bytes; the
     * rebuilt authority (parsed host + case fold) is re-validated on
     * the way out, so a mangling fold refuses loudly instead of
     * flowing into the json_encode-false log-drop class. The mangler
     * the screen guards against is real C-library behavior — on an
     * 8-bit LC_CTYPE, tolower(0xC3)=0xE3 breaks the second byte of a
     * UTF-8 host — so the pin MANUFACTURES tr_TR.ISO-8859-9 (localedef
     * into a private LOCPATH, per the r11-6 attempt-and-restore shape)
     * and proves the invariant under pressure (the fold rides
     * AsciiFold's byte tables — t31-ocr1-4 — no locale to consult,
     * identical by construction), and the guard itself fires on the
     * exact mangled spelling a byte-mapping fold produces (driven
     * through the private probe, the closeArchiveOrThrow precedent).
     * A host that cannot manufacture the locale skips VISIBLY
     * (t31-ocr6-12): the spelling pins above the skip still ran, the
     * pressure half is named as not-run — never silently green under
     * a name claiming pressure was applied.
     *
     * OCR round 50 (t31-ocr50-4) superseded the multibyte-HOST half
     * of the premise: a non-ASCII host now refuses at the host screen
     * BEFORE the fold (the WHATWG-differential doctrine — a browser
     * resolves it at the punycode host), so the fold's
     * locale-independence rides the ASCII legs below and the direct
     * guard probe above, and the multibyte URL's role in this pin is
     * the refusal itself — byte-identical under the manufactured 8-bit
     * locale, the screen judging bytes, never a locale mapping.
     */
    public function testAPostParseMangledHostRefusesUnderManufacturedLocalePressure(): void
    {
        // The mangled spelling the screen kills: a UTF-8 host whose
        // second byte an 8-bit fold broke ('ü' 0xC3 0xBC -> 0xE3 0xBC).
        // (Private-method reflection needs no setAccessible() since
        // PHP 8.1, and the call deprecates on this runtime.)
        $probe = new \ReflectionMethod(Url::class, 'assert_authority_still_valid_utf8');
        try {
            $probe->invoke(null, 'm' . "\xE3\xBC" . 'nchen.example');
            $this->fail('A rebuilt authority that is not valid UTF-8 must refuse loudly.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must stay valid UTF-8', $e->getMessage());
        }
        $probe->invoke(null, 'münchen.example:8443');

        /*
         * The multibyte URL answers the IDN refusal (t31-ocr50-4),
         * pinned once under the C fold for the byte-identity
         * comparison below.
         */
        $utf8_url = 'https://münchen.example/token';
        try {
            Url::parse_validated($utf8_url);
            $this->fail('The multibyte host URL must answer the non-ASCII host refusal — the screen precedes the fold (t31-ocr50-4).');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('host must be ASCII', $e->getMessage(), 'The refusal is the domain-to-ASCII channel, never a malformed-host sentence.');
        }

        /*
         * Manufacture the 8-bit locale (localedef into a private
         * LOCPATH). Everything is attempted and restored (r11-6's
         * shape) — and a host that cannot manufacture it (no localedef
         * on a minimal CI image, no tr_TR source, Windows) now skips
         * VISIBLY (OCR round 6, t31-ocr6-12): the pressure half used
         * to fall through `if ($manufactured)` silently, the test
         * passing green under a name claiming pressure was applied.
         * The spelling pins above still ran; the skip names what did
         * not. The established idiom: markTestSkipped at the
         * manufacture failure, never a silent half — and the
         * capability probe BEFORE the open resource (t31-ocr13-4): a
         * host with exec or escapeshellarg in disable_functions
         * FATALS the leg with an undefined-function Error (@ cannot
         * suppress a missing function) instead of this visible skip.
         */
        /*
         * The guard declares the TRIPLE (OCR round 22's verifier
         * pass, rd-2 — the t31-ocr22-4 class over this consumer):
         * this leg putenv()s LOCPATH in the PARENT before the
         * locale installs, so a putenv-disabled host fataled at the
         * putenv (driven: 'Call to undefined function putenv()') —
         * an Error mid-test, never the visible skip.
         */
        if (! self::canSpawnChildren('putenv')) {
            $this->markTestSkipped('This host has exec/escapeshellarg/putenv in disable_functions — the localedef pressure locale cannot be manufactured (the pressure leg sets LOCPATH through putenv in this very process); the LC_CTYPE pressure half did not run (the spelling pins above this point already passed).');
        }
        $locpath = sys_get_temp_dir() . '/wpct-locale-' . getmypid();
        @mkdir($locpath, 0755, true);
        exec('localedef -i tr_TR -f ISO-8859-9 ' . escapeshellarg($locpath . '/tr_TR.ISO-8859-9') . ' 2>/dev/null', $localedefOutput, $localedefExit);
        if (0 !== $localedefExit) {
            WpHarness::releaseScratch($locpath);
            $this->markTestSkipped('The tr_TR.ISO-8859-9 pressure locale could not be manufactured on this host (localedef exit ' . $localedefExit . ': no localedef, or no tr_TR source) — the LC_CTYPE pressure half did not run; the spelling pins above this point already passed (t31-ocr6-12).');
        }

        // The '0' spelling QUERIES (t31-ocr1-6): null behaves like ""
        // and SETS from the environment, so the snapshot must not
        // itself mutate the process locale.
        $previous = setlocale(LC_CTYPE, '0');
        $previousLocpath = getenv('LOCPATH');
        try {
            putenv('LOCPATH=' . $locpath);
            $this->assertNotFalse(setlocale(LC_CTYPE, 'tr_TR.ISO-8859-9'), 'The manufactured locale must install.');

            // The locale is LIVE (ctype consults it) — the pressure
            // is real, not a setlocale that silently fell back.
            $this->assertTrue(ctype_lower("\xE3"), 'ctype consults the manufactured 8-bit LC_CTYPE (0xE3 is a lowercase letter in ISO-8859-9) — the pressure is live.');

            /*
             * The invariant under pressure (the multibyte half since
             * t31-ocr50-4): the non-ASCII host URL answers the SAME
             * refusal under the live 8-bit LC_CTYPE — the screen
             * judges bytes, and no locale mapping consults it. The
             * fold-invariant half rides the ASCII host legs below.
             */
            try {
                Url::parse_validated($utf8_url);
                $this->fail('The multibyte host URL must refuse under the manufactured locale too — the screen is byte-based, never locale-mapped.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('host must be ASCII', $e->getMessage(), 'The refusal is byte-identical under the 8-bit LC_CTYPE.');
            }

            /*
             * OCR-round-1 pin (t31-ocr1-4): the scheme and host
             * folds ride AsciiFold's byte tables, never the engine
             * strtolower() — whose byte mapping is a question about
             * the engine and the process locale: glibc's
             * tr_TR.ISO-8859-9 maps tolower('I') to the dotless ı
             * (0xFD — probed at the libc level on this host), so a
             * locale-consulting fold would rebuild 'SIMPLE-I' as
             * "s\xFDmple-\xFD". The byte table has no locale to
             * consult: the ASCII host folds identically under the
             * live Turkish locale and the C fold.
             */
            $ascii_parts = Url::parse_validated('HTTPS://SIMPLE-I.EXAMPLE:8443/TOKEN');
            $this->assertSame('https', $ascii_parts['scheme'], 'The scheme folds through the ASCII byte table under the Turkish locale — never a dotted-I spelling.');
            $this->assertSame('simple-i.example:8443', $ascii_parts['authority'], 'The host folds through the ASCII byte table under the Turkish locale — never a dotless-I spelling.');
            $this->assertSame('https://simple-i.example:8443/TOKEN', (new HttpRequest('GET', 'HTTPS://SIMPLE-I.EXAMPLE:8443/TOKEN'))->redacted_url());
        } finally {
            /*
             * LOCPATH is restored BEFORE the locale, and the locale
             * restore is CHECKED: glibc resolves the restore THROUGH
             * LOCPATH, and while LOCPATH pointed at the private locale
             * dir (which does not carry the original locale's name) the
             * restore of the original returned FALSE with the locale
             * left as the manufactured Turkish one — a leak that broke
             * every later test's case-insensitive matching in the same
             * process (the /i fold consults the active locale on this
             * runtime: 'DO_ACTION_REF_ARRAY' stopped matching under tr_*)
             * — a random-order flake, ~1 run in 3, caught by the round's
             * own verifier pass. The checked restore falls back to 'C'
             * rather than ever leaving the pressure behind.
             */
            putenv(false === $previousLocpath ? 'LOCPATH' : 'LOCPATH=' . $previousLocpath);
            if (false === setlocale(LC_CTYPE, $previous)) {
                setlocale(LC_CTYPE, 'C');
            }
            WpHarness::releaseScratch($locpath);
        }

        /*
         * The r4-13 outcome holds on the safe-debug side regardless of
         * the locale: every accepted multibyte-bearing URL's debug
         * form json_encodes to a string, never false. Since
         * t31-ocr50-4 the multibyte bytes ride the PATH (a non-ASCII
         * host refuses at the host screen); the outcome — the debug
         * forms never see an invalid-UTF-8 line — is the same.
         */
        $vo = new HttpRequest('GET', 'https://host.example/münchen');
        $this->assertNotFalse(json_encode((string) $vo));
        $this->assertNotFalse(json_encode($vo->redacted_url()));
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
     * adjudication, not a drive-by. (The adjudication's TAB half is
     * GONE — t31-ocr44-1: the URL Standard strips the byte before
     * parsing, so a browser contacts a different host and the
     * authority refuses the whole strip set; the space half this pin
     * rides is the surviving byte, with no WHATWG differential.)
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
     * OCR-round-69 pin (t31-ocr69-3): the rejection names the CLASSES
     * the pattern bans. VALUE_CONTROL_BYTE_PATTERN refuses far beyond
     * the control/line-break vocabulary the message once named — the
     * bidi-override/direction-mark classes (U+202A-E, U+2066-9,
     * U+200E/F, U+061C) and the zero-width joining/spoofing class
     * (U+200B-D, U+FEFF, U+00AD) — and an operator whose value died
     * on a ZWJ or a soft hyphen got no hint of the actual cause: the
     * generic 'control characters or line breaks' sentence named
     * neither, against the codebase's own precise-rejection doctrine
     * (the named zone-id and leading-zero-port sentences). The
     * message names the formatting CLASS now — one sentence, no
     * codepoint enumeration (the classes the pattern's own docblock
     * names) — pinned over the finding's own probes: a ZWJ and a soft
     * hyphen both reject with the formatting class in the sentence
     * (red at HEAD: the generic message, the class fragment absent).
     */
    public function testTheControlByteRejectionNamesTheInvisibleFormattingClass(): void
    {
        $hostile = array(
            'U+200D ZWJ (glyph joining)' => "ok\xE2\x80\x8Devac",
            'U+00AD soft hyphen' => "ok\xC2\xADevac",
        );
        foreach ($hostile as $label => $value) {
            try {
                HeaderMap::assert_no_control_bytes($value, 'The device code');
                $this->fail("A value carrying {$label} must be rejected by the ONE shared guard (every provider-supplied string surface rides it).");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must not contain control characters, line breaks, or invisible formatting (bidi/zero-width) bytes', $e->getMessage(), "The rejection must NAME the formatting class — an operator whose value dies on {$label} needs the actual cause, never the generic control vocabulary alone (the precise-rejection doctrine).");
            }
        }
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

        // Directly on the owner (t31-r10-7: the fence probes the ONE
        // folded index now — the $seen_lowercase parallel is gone, so
        // the pin drives the fence where it lives).
        try {
            new HeaderMap(array('accept' => 'a', 'ACCEPT' => 'b'));
            $this->fail('The duplicate fence must hold on the HeaderMap owner itself.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('case-insensitively', $e->getMessage());
        }
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

    /**
     * OCR-round-1 pin (t31-ocr1-8): the docblocks claimed the masked
     * contract for "the serialized form", but __debugInfo() only covers
     * print_r()/var_dump()/debugger views — serialize() and
     * var_export() bypass it by engine design and dumped the raw
     * property tree (the full URL with query and userinfo, the raw
     * Authorization/Cookie values, the body). serialize() rides the
     * SAME masked view through __serialize() now (byte-identical
     * vocabulary to the dump form), and neither channel reconstructs:
     * __unserialize() refuses the masked snapshot and __set_state()
     * refuses the raw export. var_export() itself stays the one
     * NAMED-EXCLUDED channel (no engine hook exists — the docblock
     * pins the exclusion instead of pretending coverage).
     */
    public function testTheSerializeChannelRendersMaskedAndRefusesToRebuild(): void
    {
        $token = FakeSecrets::accessToken();
        $session = 'wpct_fixture_session_' . bin2hex(random_bytes(8));
        $request = new HttpRequest(
            'POST',
            'https://host.example/callback?access_token=' . $token . '&extra=1',
            array('Authorization' => 'Bearer ' . $token, 'Cookie' => 'session=' . $session),
            'grant_type=refresh_token&refresh_token=' . $token
        );

        $payload = serialize($request);

        $this->assertStringNotContainsString($token, $payload, 'serialize() must never carry the raw bearer/refresh token.');
        $this->assertStringNotContainsString($session, $payload, 'serialize() must never carry the raw cookie.');
        $this->assertStringNotContainsString('access_token', $payload, 'The URL query is dropped in the serialized form.');
        $this->assertStringNotContainsString('grant_type', $payload, 'The body is omitted in the serialized form.');
        $this->assertStringContainsString('[body omitted]', $payload, 'The payload is the masked debug vocabulary.');

        // The masked snapshot is not a round-trip payload: rebuilding refuses.
        $refusal = $this->refusalOf(
            fn() => unserialize($payload),
            'A masked HTTP value object must never reconstruct from its own safe form.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a round-trip payload', $refusal->getMessage());

        // The one EXCLUDED channel, pinned exactly as the docblock
        // documents it: var_export() dumps the raw property tree
        // through no engine hook. The exclusion is engine design, and
        // the pin keeps the docblock honest — if a future engine ever
        // routes var_export() through __debugInfo()/__serialize(), it
        // fails here and the contract tightens instead of silently
        // understating its own coverage.
        $export = var_export($request, true);
        $this->assertStringContainsString($token, $export, 'The documented exclusion is exact: var_export() dumps the raw tree through no hook — which is precisely why its reconstruction channel refuses.');

        // The eval channel refuses: the raw dump is display material,
        // never executable reconstruction. (The nested HeaderMap export
        // evaluates first and dies on its own __set_state() refusal
        // before the outer one on this engine — any Throwable is
        // the pin: NO reconstruction, by whichever refusal fires.)
        // The refusal-verdict owner (t31-ocr8-12): the old
        // fail()-inside-try with a \Throwable catch was the masking
        // class in its widest spelling — a no-throw reconstruction
        // landed the fail() IN the catch and passed vacuously.
        $this->refusalOf(
            fn() => eval('return ' . $export . ';'),
            'Evaluating a var_export of a request VO must never reconstruct one.',
            \Throwable::class
        );
        $this->addToAssertionCount(1);

        // The trait's own refusal is pinned typed directly.
        $refusal = $this->refusalOf(
            fn() => HttpRequest::__set_state(array('method' => 'GET')),
            '__set_state() must refuse the raw export as a reconstruction source.', \RuntimeException::class
        );
        $this->assertStringContainsString('never a payload', $refusal->getMessage());

        // The response side rides the same trait channels.
        $response = new \Deicod\WpConnectors\Shared\Http\HttpResponse(302, array('Location' => 'https://client.example/cb?code=' . $token));
        $responsePayload = serialize($response);
        $this->assertStringNotContainsString($token, $responsePayload, 'The response serializes masked too — the Location query never rides the payload.');
        $this->assertStringContainsString('[body omitted]', $responsePayload);
        $refusal = $this->refusalOf(
            fn() => unserialize($responsePayload),
            'A masked response must never reconstruct from its own safe form either.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a round-trip payload', $refusal->getMessage());
    }

    /**
     * OCR-round-2 pin (t31-ocr2-2): the map itself — the ocr1-8 pass
     * hooked the serialize channel on the REQUEST and RESPONSE facades
     * (HasMaskedHeaders), but HeaderMap hooked only __debugInfo(), so a
     * bare serialize($map) still dumped $headers_by_lowercase with the
     * full Authorization/Cookie values, the exact cleartext the map's
     * own doctrine ("the dump mirrors the masked map so the two
     * channels cannot drift") forbids. __serialize() rides the SAME
     * masked view as the dump (one owner), the reconstruction channels
     * refuse, and serialize() and print_r() are pinned to AGREE — the
     * parity the doctrine names.
     */
    public function testTheHeaderMapItselfSerializesMaskedAndRefusesToRebuild(): void
    {
        $token = FakeSecrets::accessToken();
        $session = 'wpct_fixture_session_' . bin2hex(random_bytes(8));
        $map = new HeaderMap(array(
            'Authorization' => 'Bearer ' . $token,
            'Cookie' => 'session=' . $session,
            'Content-Type' => 'application/json',
        ));

        $payload = serialize($map);
        $this->assertStringNotContainsString($token, $payload, 'serialize() of the bare map must never carry the raw bearer token.');
        $this->assertStringNotContainsString($session, $payload, 'serialize() of the bare map must never carry the raw cookie.');
        $this->assertStringContainsString('Content-Type', $payload, 'Non-sensitive names ride the payload as themselves.');
        $this->assertStringContainsString('application/json', $payload, 'Non-sensitive values ride the payload as themselves.');

        // PARITY, the doctrine's own claim: serialize() and print_r()
        // agree on every value the map renders — the masked spelling
        // appears in BOTH channels, never the raw one in either.
        $dumped = print_r($map, true);
        $masked_authorization = (string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask('Bearer ' . $token);
        $masked_cookie = (string) \Deicod\WpConnectors\Shared\Support\SecretMask::mask('session=' . $session);
        $this->assertStringContainsString($masked_authorization, $payload, 'The serialize form carries the masked Authorization spelling.');
        $this->assertStringContainsString($masked_authorization, $dumped, 'The dump form carries the same masked spelling.');
        $this->assertStringContainsString($masked_cookie, $payload, 'The cookie value rides masked in the serialize form.');
        $this->assertStringContainsString($masked_cookie, $dumped, 'The cookie value rides masked in the dump form too — the two channels cannot drift.');

        // The masked snapshot is not a round-trip payload: rebuilding refuses.
        $refusal = $this->refusalOf(
            fn() => unserialize($payload),
            'A masked header map must never reconstruct from its own safe form.', \RuntimeException::class
        );
        $this->assertStringContainsString('not a round-trip payload', $refusal->getMessage());

        // The eval channel refuses typed directly.
        $refusal = $this->refusalOf(
            fn() => HeaderMap::__set_state(array('headers' => array())),
            '__set_state() must refuse the raw export as a reconstruction source.', \RuntimeException::class
        );
        $this->assertStringContainsString('never a payload', $refusal->getMessage());
    }

    /**
     * Review-round pin (t31-r12-4, driver adjudication on vendor-doc
     * proof): RFC 6749 section 4.1.2 mandates the authorization code in
     * the redirect's Location query — a 302's Location IS a
     * credential-bearing surface by specification, yet it rendered
     * verbatim through every safe debug form while the request side
     * masked its own credential headers (reproduced: the full
     * 'Location: https://client/cb?code=…' line in the string cast,
     * the dump, and print_r). 'location' joins the one catalog, and
     * the fold pins keep the spelling case-insensitive.
     */
    public function testARedirectLocationCarryingAnAuthorizationCodeMasksEverywhere(): void
    {
        $code = FakeSecrets::accessToken();
        $redirect = 'https://client.example/cb?code=' . $code . '&state=xyz';

        $this->assertTrue(SecretMask::is_sensitive_header_name('Location'), 'location is in the one sensitive-header catalog.');

        $response = new HttpResponse(302, array('Location' => $redirect), '');

        // The string form: the code and the query it rides never appear;
        // the masked rendering does.
        $rendered = (string) $response;
        $this->assertStringNotContainsString($code, $rendered);
        $this->assertStringNotContainsString('client.example/cb?code=', $rendered, 'The redirect query — where the code rides — does not render.');
        $this->assertStringContainsString((string) SecretMask::mask($redirect), $rendered);

        // The serialization channel agrees: print_r and the debug form.
        $dumped = print_r($response, true);
        $this->assertStringNotContainsString($code, $dumped);
        $this->assertStringContainsString((string) SecretMask::mask($redirect), $dumped);
        $this->assertNotFalse(json_encode($response->__debugInfo()), 'The masked dump stays encodable.');

        // Header-name folding (the r2-14 doctrine's own vocabulary site):
        // any casing of the name masks the value.
        foreach (array('LOCATION', 'location', 'LoCaTiOn') as $spelling) {
            $folded = new HttpResponse(302, array($spelling => $redirect), '');
            $this->assertStringNotContainsString($code, (string) $folded, "The '{$spelling}' spelling masks identically.");
        }
    }

    /**
     * OCR-round-38 pin (t31-ocr38-1, security — the first shared/src
     * finding since round 24): the REQUEST-SIDE TWIN of the r12-4
     * channel. 'location' masks because RFC 6749 section 4.1.2
     * mandates the authorization code in the redirect's Location
     * query — and a Referer value is that SAME redirect query echoed
     * by a caller's outbound navigation (the code-side channel
     * spelled on the request), yet it rendered verbatim through every
     * safe debug form while the response side masked its own
     * credential headers (red at HEAD: unmasked). Beside it the RFC
     * 7615 authentication-exchange headers
     * ('authentication-info'/'proxy-authentication-info') — the
     * 401-protection twins of the masked 'proxy-authorization' class,
     * credential material by specification — join it: none of the
     * three composes through the suffix class (each final
     * hyphen-token — 'referer', 'info' — names no credential suffix),
     * so all three ride the one catalog.
     */
    public function testTheRequestSideTwinOfTheRedirectCodeChannelMasksEverywhere(): void
    {
        $code = FakeSecrets::accessToken();
        $referer = 'https://client.example/cb?code=' . $code . '&state=xyz';

        foreach (array('Referer', 'referer', 'REFERER', 'authentication-info', 'Authentication-Info', 'proxy-authentication-info') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the one sensitive-header catalog — the request-side twin of the r12-4 location channel (red at HEAD: unmasked).");
        }

        // The render seam every safe debug form rides: the echoed
        // redirect query never renders; the masked rendering does —
        // both channels of the ONE masked-view owner.
        $map = new HeaderMap(array(
            'Referer' => $referer,
            'Proxy-Authentication-Info' => $code,
            'x-request-id' => 'req-38',
        ));
        foreach (array('dump' => print_r($map, true), 'serialize' => serialize($map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($code, $rendered, "The authorization code riding the Referer query never renders in the {$channel} channel — pre-fix the request side leaked what the response side masked.");
            $this->assertStringNotContainsString('client.example/cb?code=', $rendered, "The redirect query — where the code rides — does not render in the {$channel} channel.");
            $this->assertStringContainsString((string) SecretMask::mask($referer), $rendered, "The masked rendering is what renders in the {$channel} channel.");
            $this->assertStringContainsString('req-38', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }
    }

    /**
     * OCR-round-15 pin (t31-ocr15-1, security): the sensitive-name
     * catalog closed at six spellings could never grow as fast as
     * vendors mint key-bearing header names — 'api-key' (Azure OpenAI
     * / Azure AI, an archetypal Task-3.7 provider class) rendered its
     * FULL secret verbatim through every safe debug form while
     * 'x-api-key' sat covered in the same catalog: the r12-4 leak
     * class reopened under another vendor-documented spelling. The
     * policy judges the CLASS now, never the single spelling: any
     * folded name that IS one of the vendor-documented credential
     * suffixes, or whose final hyphen-token is one ('subscription-key'
     * closes Azure APIM's 'Ocp-Apim-Subscription-Key'), is sensitive —
     * so a future vendor spelling ('x-auth-token', an 'apim-…-api-key'
     * derivative) masks the day it appears, never after a leak
     * reproduces it. No extension seam exists yet: Task 3.7's
     * transport binding decides whether providers contribute
     * spellings of their own (ledgered decision, not added
     * speculatively here).
     */
    public function testTheCredentialSuffixClassMasksEveryKeyBearingNameSpelling(): void
    {
        $token = FakeSecrets::accessToken();
        $apim = FakeSecrets::accessToken();

        foreach (array('api-key', 'API-KEY', 'Api-Key', 'Ocp-Apim-Subscription-Key', 'subscription-key', 'x-auth-token', 'Auth-Token') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the suffix class — a key-bearing name is sensitive by what it names, never by its catalog spelling.");
        }

        // The catalog spellings stay covered (the rule ADDS, never replaces).
        foreach (array('cookie', 'set-cookie', 'location') as $catalogued) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($catalogued), "The catalogued '{$catalogued}' spelling stays sensitive.");
        }
        /*
         * The three former catalog entries ride the CLASS alone (OCR
         * round 33, t31-ocr33-3, the ocr29-5 subsumption doctrine):
         * 'authorization' IS a suffix member, 'proxy-authorization'
         * and 'x-api-key' end in '-authorization'/'-api-key' —
         * behaviorally dead as catalog entries, pinned green through
         * the class by construction (drop the suffix and they
         * redden).
         */
        foreach (array('authorization', 'proxy-authorization', 'x-api-key') as $subsumed) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($subsumed), "The '{$subsumed}' spelling stays sensitive through the suffix class alone — its catalog entry was behaviorally dead.");
        }

        // The render seam every safe debug form rides: the vendor spellings
        // mask, the non-sensitive names stay verbatim — the over-mask drift
        // the class rule must never take. Both channels of the seam (the
        // dump and the serialize form) ride the ONE masked-view owner.
        $map = new HeaderMap(array(
            'api-key' => $token,
            'Ocp-Apim-Subscription-Key' => $apim,
            'accept' => 'application/json',
            'x-request-id' => 'req-17',
        ));
        foreach (array('dump' => print_r($map, true), 'serialize' => serialize($map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($token, $rendered, "The bare 'api-key' spelling renders masked in the {$channel} channel — pre-fix it was the one vendor-documented key-bearing name outside the closed catalog, the r12-4 leak class reopened.");
            $this->assertStringNotContainsString($apim, $rendered, "The APIM gateway's 'Ocp-Apim-Subscription-Key' rides the same suffix class in the {$channel} channel.");
            $this->assertStringContainsString('application/json', $rendered, "The non-sensitive 'accept' value still renders verbatim in the {$channel} channel.");
            $this->assertStringContainsString('req-17', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }
        $this->assertStringContainsString((string) SecretMask::mask($token), print_r($map, true), 'The masked spelling is what renders.');

        // The boundary is the HYPHEN: a name merely ending in the suffix
        // bytes ('monkeykey'-shaped accidents) is not the class — the rule
        // is token-shaped because vendor spellings are hyphenated.
        $this->assertFalse(SecretMask::is_sensitive_header_name('x-api-keychain'), 'A name whose final token merely CONTAINS the suffix bytes is not credential-bearing — the boundary is the hyphen, the shape vendors spell.');
    }

    /**
     * OCR-round-24 pin (t31-ocr24-1, security — the first shared/src
     * finding since round 14): the suffix class did not cover the
     * credential suffixes its own rule statement implies. The rule
     * judges "vendor-documented credential tokens" — and 'token',
     * 'secret', and 'authorization' are exactly that: AWS STS signs
     * with 'X-Amz-Security-Token', Shopify's REST API with
     * 'X-Shopify-Access-Token', OAuth client credentials are spelled
     * 'X-Client-Secret'/'X-Shared-Secret' by vendor gateways, and
     * 'X-Authorization' is the prefixed bearer spelling several
     * mobile/API gateways document — every one rendered its FULL
     * secret verbatim through every safe debug form (HeaderMap's
     * rendered_value() is the sole masking gate), the exact r12-4 /
     * ocr15-1 leak class the rule exists to close. The suffixes join
     * the class; over-masking a non-credential '-token' header in
     * DEBUG output errs safe (a correlation tail is lost, never a
     * secret), and the hyphen boundary is unaffected — the judged
     * token is still the whole final hyphen-segment.
     */
    public function testTheCredentialSuffixClassOwnsTheTokenSecretAndAuthorizationSuffixes(): void
    {
        $sts = FakeSecrets::accessToken();
        $shopify = FakeSecrets::accessToken();

        // The named vendor spellings mask (red at HEAD: verbatim).
        foreach (array(
            'X-Amz-Security-Token', 'x-amz-security-token',
            'X-Shopify-Access-Token', 'X-Client-Secret', 'X-Shared-Secret',
            'X-Authorization', 'x-authorization', 'token', 'secret', 'authorization',
        ) as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the suffix class — a credential suffix the rule's own statement implies, never a per-vendor catalog addition.");
        }

        // The render seam every safe debug form rides: the vendor
        // spellings mask, the non-sensitive control stays verbatim —
        // both channels of the ONE masked-view owner.
        $map = new HeaderMap(array(
            'X-Amz-Security-Token' => $sts,
            'X-Shopify-Access-Token' => $shopify,
            'x-request-id' => 'req-24',
        ));
        foreach (array('dump' => print_r($map, true), 'serialize' => serialize($map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($sts, $rendered, "The AWS STS session token renders masked in the {$channel} channel — pre-fix the suffix class's own rule statement named the class and missed it.");
            $this->assertStringNotContainsString($shopify, $rendered, "The Shopify access token rides the same suffix class in the {$channel} channel.");
            $this->assertStringContainsString('req-24', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }

        // The boundary is still the HYPHEN, now for every new suffix:
        // a name merely CONTAINING the bytes — unhyphenated, or with
        // the suffix mid-name — is not the class (the existing
        // 'x-api-keychain' pin above rides unchanged beside these).
        // 'clientsecret' rode this row until OCR round 49 — the
        // undelimited generation below claims it as a recognized
        // credential name's single-token spelling, superseding the
        // row's example (the 'apitoken' glue keeps it: no vendor
        // spells it).
        foreach (array('apitoken', 'x-authorization-scheme', 'www-authenticate') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling is not credential-bearing — the boundary stays the hyphen token, and 'www-authenticate' carries a challenge-scheme list, never a credential.");
        }

        /*
         * OCR-round-49 pin (t31-ocr49-5 — the UNDELIMITED
         * generation, superseding the r24 row above): a credential
         * name spelled with NO delimiter at all — a header named
         * exactly 'apikey' or 'accesstoken' — folds to a judged name
         * that equals no catalog entry and whose final segment is
         * the whole token, ending in no suffix: both screens
         * answered false and the secret rendered verbatim through
         * every safe debug form (red at HEAD: unmasked). The
         * credential family's own single-token forms join the policy
         * — the flattened spellings of the class's hyphenated
         * members and of the vendor-documented names the class's own
         * record cites (the suffix class's own flattened members
         * since OCR round 50, t31-ocr50-1 — the exact-match catalog
         * arm answered only the bare token); the render seam rides
         * the same verdict, and the unrelated single tokens stay
         * verbatim.
         */
        $undelimited_secret = FakeSecrets::accessToken();
        foreach (array('apikey', 'ApiKey', 'accesstoken', 'refreshtoken', 'clientsecret', 'securitytoken', 'sharedsecret', 'subscriptionkey') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the class — a recognized credential name's undelimited spelling is the name, never a delimiter-shaped hole (red at HEAD: unmasked).");
        }
        $undelimited_map = new HeaderMap(array(
            'apikey' => $undelimited_secret,
            'accept' => 'text/plain',
        ));
        foreach (array('dump' => print_r($undelimited_map, true), 'serialize' => serialize($undelimited_map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($undelimited_secret, $rendered, "The undelimited 'apikey' name masks in the {$channel} channel — the class owns the single-token spelling.");
            $this->assertStringContainsString('text/plain', $rendered, "The unrelated single-token 'accept' value still renders verbatim in the {$channel} channel.");
        }
        foreach (array('host', 'accept') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The unrelated single token '{$spelling}' stays verbatim — the credential family alone grew a spelling.");
        }

        /*
         * OCR-round-50 pin (t31-ocr50-1 — the HYPHEN-TAIL twin of
         * the undelimited generation): the r49-5 flattened spellings
         * rode the catalog, consulted by exact match only, so a name
         * whose FINAL hyphen-token is one of them escaped both
         * screens — 'X-ApiKey' (the equally real vendor spelling,
         * flattened twin of covered 'X-Api-Key') folded to
         * 'x-apikey' and matched neither (red at HEAD: unmasked).
         * The flattened family rides the SUFFIX CLASS now — one
         * boundary speaking every delimiter's segment tail plus the
         * bare token (the fold normalizes '_' and '.' to the hyphen
         * once) — and the boundary still refuses suffix bytes
         * spanning a separator.
         */
        foreach (array('X-ApiKey', 'x-apikey', 'x.accesstoken', 'x_clientsecret') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the suffix class's flattened family — the boundary judges the final segment over every delimiter, never the catalog's exact match alone (red at HEAD: unmasked).");
        }
        foreach (array('X-Request-Id', 'x-apikeychain') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling stays verbatim — a non-credential tail and a name whose final token merely CONTAINS the flattened bytes are both outside the class.");
        }

        /*
         * OCR-round-43 pin (t31-ocr43-1): the UNDERSCORE twin. '_'
         * is a legal RFC 7230 tchar HeaderMap's NAME_TOKEN_PATTERN
         * admits beside '-', so 'x_api_key' is a legal header name —
         * and the suffix class keyed on hyphen boundaries only, so
         * every underscore spelling of a credential name (including
         * 'subscription_key', 'auth_token', 'client_secret' — the
         * very tokens the class names, re-spelled) matched neither
         * catalog nor class: the full secret rendered verbatim
         * through every safe debug form (red at HEAD: unmasked), the
         * r12-4 leak class over the grammar's own second separator.
         * The classifier normalizes '_' to '-' once at the fold; the
         * hyphen spellings above stay byte-identical, and the
         * boundary pin below keeps the suffix bytes from spanning
         * either separator.
         */
        foreach (array('x_api_key', 'X_Api_Key', 'subscription_key', 'auth_token', 'client_secret') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the suffix class — '_' is a tchar the header grammar admits, and the credential boundary speaks both separators (red at HEAD: unmasked).");
        }
        $this->assertFalse(SecretMask::is_sensitive_header_name('x_api_keychain'), 'The underscore twin of the boundary pin — a name whose final segment merely CONTAINS the suffix bytes is not credential-bearing, over either separator.');

        /*
         * OCR-round-44 pin (t31-ocr44-2): the class census COMPLETED.
         * The ocr43-1 rationale ("'_' is a tchar the grammar admits …
         * the delimiter is a CLASS") closed only the underscore twin —
         * but '.' is equally a legal tchar, and so is every other
         * non-alphanumeric byte in NAME_TOKEN_PATTERN's character
         * class: 'x.api.key' was a legal header name matching neither
         * catalog nor class (red at HEAD: unmasked), the same r12-4
         * leak class one delimiter over. The fold now normalizes the
         * WHOLE fourteen-member census (beside the hyphen itself) to
         * the hyphen once, derived from the grammar's own class; the
         * judged token stays the whole final segment over any of
         * them, and every hyphen spelling above judges
         * byte-identically (the underscore pins ride unchanged).
         */
        foreach (array('x.api.key', 'X.Api.Key', 'api.key', 'subscription.key', 'client.secret') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the suffix class — '.' is a tchar the header grammar admits, and the credential boundary speaks the whole delimiter census (red at HEAD: unmasked).");
        }
        $this->assertFalse(SecretMask::is_sensitive_header_name('x.api.keychain'), 'The dot twin of the boundary pin — a name whose final segment merely CONTAINS the suffix bytes is not credential-bearing, over any separator spelling.');

        /*
         * OCR-round-46 pin (t31-ocr46-4): the EDGE-DELIMITER twin —
         * the same r12-4 leak class the ocr43-1/ocr44-2 closures
         * claimed closed. 'Authorization.' is a legal RFC 7230 token
         * (the trailing '.' is a tchar), and its fold
         * 'authorization-' has an EMPTY final segment: the exact
         * match failed and str_ends_with('-authorization') failed
         * over the empty-segment shape, so the credential rendered
         * verbatim through every safe debug form (red at HEAD:
         * unmasked). The boundary segments BEFORE emptiness is
         * judged — an empty BOUND segment never disqualifies a
         * credential-bearing name, over either edge (the class is
         * symmetric: '.Authorization' and 'Authorization_' mask the
         * same), while an empty MID segment changes nothing the
         * boundary already owned.
         */
        foreach (array('Authorization.', '.Authorization', 'Authorization_', 'X_Api_Key.', 'x.api.key.') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the class — an empty BOUND segment never disqualifies a credential-bearing name (red at HEAD: unmasked).");
        }
        $this->assertFalse(SecretMask::is_sensitive_header_name('Request-Id.'), 'The boundary pin keeps its charge over the edge twin — bound delimiters on a non-credential name mask nothing.');
        $this->assertFalse(SecretMask::is_sensitive_header_name('x-api-keychain-'), 'The trailing-delimiter twin of the keychain pin — empty bound segments do not dissolve the segment boundary the class judges.');

        /*
         * OCR-round-52 pin (t31-ocr52-2 — the generic 'key' token,
         * the consistency gap in the masking boundary): the class
         * carried 'api-key'/'subscription-key' while missing the
         * generic token both end in, so the final-token judgment
         * masked 'X-Client-Secret' and 'X-Api-Key' while
         * 'X-Secret-Key' — the class's own 'secret' beside the 'key'
         * every key-bearing member spells — and 'X-Access-Key'
         * (the object-storage/S3-compatible auth spelling) rendered
         * verbatim through every safe debug form (red at HEAD:
         * unmasked). 'key' joins the generic tier 'token'/'secret'/
         * 'auth' occupy (the r24 over-masking-errs-safe doctrine —
         * a non-credential '-key' name costs a correlation tail,
         * never a secret), subsuming the hyphenated compounds
         * ('api-key', 'subscription-key' — the ocr33-3 doctrine)
         * while their flattened twins stay beside the round's own
         * 'secretkey'/'accesskey' (the r49-5 flattened-family
         * doctrine). The rejected shape — an
         * any-credential-token-in-the-name rule — leaves
         * 'X-Access-Key' verbatim ('access' names no classed
         * token): the exact inconsistency this round closes.
         */
        $key_tail_secret = FakeSecrets::accessToken();
        foreach (array('X-Secret-Key', 'secret-key', 'X-Access-Key', 'x_access_key', 'x.access.key', 'secretkey', 'accesskey', 'x-secretkey', 'api-key', 'x-api-key', 'apikey', 'subscription-key', 'Ocp-Apim-Subscription-Key') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the class — the generic 'key' token owns the key-bearing family and the subsumed hyphenated compounds stay sensitive through it (red at HEAD: 'X-Secret-Key' and its family unmasked).");
        }
        $key_tail_map = new HeaderMap(array(
            'X-Secret-Key' => $key_tail_secret,
            'x-request-id' => 'req-52',
        ));
        foreach (array('dump' => print_r($key_tail_map, true), 'serialize' => serialize($key_tail_map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($key_tail_secret, $rendered, "The 'X-Secret-Key' value renders masked in the {$channel} channel — pre-fix the class masked 'X-Client-Secret' and rendered this one verbatim, the inconsistency the generic tier closes.");
            $this->assertStringContainsString('req-52', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }
        foreach (array('x-keychain', 'x-monkey', 'x.keychain') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling stays verbatim — the suffix bytes never span the segment the class judges, the generic tier included.");
        }

        /*
         * OCR-round-55 pin (t31-ocr55-1 — the suffix tier's own
         * sibling): 'authorization' rode the class since round 24
         * while 'authentication' matched neither catalog (only the
         * '-info' exchange spellings) nor suffix screens — so
         * 'X-Authentication', 'Proxy-Authentication', and
         * 'Client-Authentication' rendered their credential verbatim
         * through every safe debug form (red at HEAD: unmasked), the
         * r12-4 leak class under the vendor spelling the doctrine
         * itself treats as credential material. One member speaks
         * every delimiter per the r50-1 fold; no non-credential
         * '-authentication' neighbor is known to exist (the census
         * names that), and the boundary twin keeps its charge: a
         * final token merely PRECEDING the suffix stays verbatim.
         */
        $authentication_secret = FakeSecrets::accessToken();
        foreach (array('X-Authentication', 'Proxy-Authentication', 'Client-Authentication', 'x-authentication', 'authentication', 'x_authentication', 'proxy.authentication') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the class — the 'authorization' tier's own sibling, over every delimiter spelling (red at HEAD: unmasked).");
        }
        $authentication_map = new HeaderMap(array(
            'X-Authentication' => $authentication_secret,
            'x-request-id' => 'req-55',
        ));
        foreach (array('dump' => print_r($authentication_map, true), 'serialize' => serialize($authentication_map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($authentication_secret, $rendered, "The 'X-Authentication' value renders masked in the {$channel} channel — its tier's sibling cannot be the one credential suffix outside the class.");
            $this->assertStringContainsString('req-55', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }
        $this->assertFalse(SecretMask::is_sensitive_header_name('x-authentication-scheme'), 'The boundary twin stays verbatim — the suffix bytes never span the segment the class judges, the sibling included.');

        /*
         * OCR-round-66 pin (t31-ocr66-5 — the tier's own 'password'
         * member): 'password' is the final token of vendor-documented
         * credential headers — 'X-Password' and the composed
         * 'X-Api-Password'/'X-User-Password' family — and it matched
         * neither catalog nor suffix, so the credential rendered
         * verbatim through every safe debug form (red at HEAD:
         * unmasked, driven), the r12-4 leak class under the plainest
         * credential word the tier had not named. One member speaks
         * every delimiter per the r50-1 fold; no flattened glued twin
         * joins (no vendor spells 'XPassword', the r55-1 treatment),
         * and the boundary twin keeps its charge: a final token
         * merely PRECEDING the suffix stays verbatim.
         */
        $password_secret = FakeSecrets::accessToken();
        foreach (array('X-Password', 'X-Api-Password', 'X-User-Password', 'x-password', 'password', 'x_password', 'x.api.password') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the class — the credential tier's own 'password' member, over every delimiter spelling (red at HEAD: unmasked).");
        }
        $password_map = new HeaderMap(array(
            'X-Password' => $password_secret,
            'X-Api-Password' => $password_secret,
            'x-request-id' => 'req-66',
        ));
        foreach (array('dump' => print_r($password_map, true), 'serialize' => serialize($password_map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($password_secret, $rendered, "The 'X-Password' and 'X-Api-Password' values render masked in the {$channel} channel — pre-fix the plainest credential word was the one suffix the tier had not named.");
            $this->assertStringContainsString('req-66', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }
        foreach (array('x-password-policy', 'x-passport', 'xpassword') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling stays verbatim — a policy tail, a lookalike word, and the unflattened glue are all outside the boundary the class judges.");
        }

        /*
         * OCR-round-57 pin (t31-ocr57-2 — the flattened 'csrftoken'
         * twin): 'X-CSRFToken' is Django's canonical CSRF header
         * spelling (CSRF_HEADER_NAME; the cookie default is the bare
         * 'csrftoken'), and it folded to judged 'x-csrftoken' — no
         * catalog entry, no suffix, the final segment the whole
         * flattened token — so the session credential rendered
         * verbatim through every safe debug form (red at HEAD)
         * while the hyphenated twin 'X-Csrf-Token' masked via
         * 'token': the exact inconsistency t31-ocr50-1 closed for
         * 'X-ApiKey'. One member speaks every delimiter spelling
         * (the r50-1 fold); the census names the .NET twin
         * considered-and-skipped ('antiforgerytoken' is no vendor's
         * header name; the documented '__RequestVerificationToken'
         * spelling judges the 'token' segment, already covered).
         */
        $csrf_secret = FakeSecrets::accessToken();
        foreach (array('X-CSRFToken', 'x-csrftoken', 'X_CsrfToken', 'x.csrftoken', 'CSRFToken', 'csrftoken') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the class — Django's own canonical CSRF header spelling, over every delimiter (red at HEAD: unmasked).");
        }
        $this->assertTrue(SecretMask::is_sensitive_header_name('X-Csrf-Token'), 'The hyphenated twin stays masked through the token suffix — the covered and flattened spellings answer one verdict now.');
        $csrf_map = new HeaderMap(array(
            'X-CSRFToken' => $csrf_secret,
            'x-request-id' => 'req-57',
        ));
        foreach (array('dump' => print_r($csrf_map, true), 'serialize' => serialize($csrf_map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($csrf_secret, $rendered, "Django's 'X-CSRFToken' renders masked in the {$channel} channel — the covered twin cannot be the one CSRF spelling that leaks.");
            $this->assertStringContainsString('req-57', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }
        foreach (array('X-Token-Count', 'x-csrftokenlog') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' neighbor stays verbatim — a non-credential tail and bytes spanning the segment boundary are both outside the class.");
        }

        /*
         * OCR-round-61 pin (t31-ocr61-2 — the HMAC-material suffix):
         * 'signature' is the final token of the webhooks' own
         * credential-material headers — Stripe's 'Stripe-Signature',
         * GitHub's 'X-Hub-Signature' and its SHA-256 variant
         * 'X-Hub-Signature-256', the generic 'X-Signature', Google's
         * 'X-Goog-Signature' — and it matched neither catalog nor
         * suffix, so the credential-derived HMAC material rendered
         * verbatim through every safe debug form (red at HEAD). One
         * member speaks every delimiter spelling (the r50-1 fold);
         * the '-256' variant rides its own two-token entry (its
         * judged final segment is '256', a token no credential name
         * spells — the tail-with-variant is judged whole, the same
         * final-segment boundary one entry longer); the round's sweep
         * considered the HTTP-signatures 'Digest' twin and SKIPPED it
         * (an integrity digest of the body it rides with is
         * secret-free — not credential-derived material, the bar
         * every member meets); no flattened glued twin joins (every
         * motivating header hyphenates); and the boundary keeps its
         * charge below.
         */
        $signature_secret = FakeSecrets::accessToken();
        foreach (array('Stripe-Signature', 'X-Hub-Signature', 'X-Hub-Signature-256', 'X-Signature', 'X-Goog-Signature', 'signature', 'x_signature', 'stripe.signature') as $spelling) {
            $this->assertTrue(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling rides the class — the webhooks' own documented HMAC-material headers, over every delimiter (red at HEAD: verbatim).");
        }
        $signature_map = new HeaderMap(array(
            'Stripe-Signature' => $signature_secret,
            'x-request-id' => 'req-61',
        ));
        foreach (array('dump' => print_r($signature_map, true), 'serialize' => serialize($signature_map)) as $channel => $rendered) {
            $this->assertStringNotContainsString($signature_secret, $rendered, "The 'Stripe-Signature' HMAC material renders masked in the {$channel} channel (red at HEAD: verbatim) — a webhook's signing material is credential-derived, the r12-4 leak class under the vendors' own spelling.");
            $this->assertStringContainsString('req-61', $rendered, "The non-sensitive 'x-request-id' value still renders verbatim in the {$channel} channel.");
        }
        foreach (array('x-signature-count', 'x-signature-timestamp') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' neighbor stays verbatim — a name-final non-credential token PRECEDES the member, never rides it, and the suffix bytes never span the segment the class judges.");
        }
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

        /*
         * OCR-round-6 pin (t31-ocr6-1): the canonical RFC 8628 user code
         * ('BCJK-3502', nine characters with its separator) sat one
         * character above the old threshold of 8 and rendered '…3502' —
         * half the code's entropy for a value that is itself a
         * short-lived credential. OTP-class values render the bare mask
         * now: the threshold boundary is pinned from both sides.
         */
        $this->assertSame('…', SecretMask::mask('BCJK-3502'), 'A nine-character user code renders the bare mask — never a visible tail on OTP-class values.');
        $this->assertSame('…', SecretMask::mask('abcdefghijkl'), 'A twelve-character value sits at the threshold: bare mask.');
        $this->assertSame('…jklm', SecretMask::mask('abcdefghijklm'), 'A thirteen-character value is the first to show the correlation tail.');
        $this->assertSame('…wxyz', SecretMask::mask('abcdefghijklmnopwxyz'));
    }

    /**
     * Review-round pin (t31-r1-6): the visible tail is the last four
     * CHARACTERS — the byte-wise substr(-4) split a multibyte character
     * mid-sequence, and the masked value was invalid UTF-8.
     */
    public function testMaskOfMultibyteSecretsNeverSplitsACharacter(): void
    {
        // 14 characters, 18 bytes; the four-character tail is entirely
        // two-byte sequences.
        $this->assertSame('…öööö', SecretMask::mask('aaaaaaaaaaöööö'));

        // Four-byte sequences (emoji): the tail covers four complete
        // characters, up to sixteen bytes.
        $this->assertSame('…😀😀😀😀', SecretMask::mask('😀😀😀😀😀😀😀😀😀😀😀😀😀'));

        /*
         * OCR-round-8 legs (t31-ocr8-8): collapsing the grammar's two
         * spellings drove their byte-equivalence (94,080 cases), and
         * the drive REFUTED the regex twin's F4 clause — its
         * quantifier demanded FIVE bytes (invalid, beyond U+10FFFF)
         * and rejected the valid four-byte F4 plane, so mask() could
         * ship an invalid-UTF-8 tail for the five-byte shape and shed
         * the valid plane's complete characters. The walk (the render
         * seam's table — engine-verified on both shapes) is the one
         * spelling now; both legs were red under the regex twin.
         */
        // The valid four-byte F4 character (U+100000): the tail shows
        // the COMPLETE character (the regex twin shed it to nothing).
        $f4_char = "\xF4\x80\x80\x80";
        $this->assertSame('…xxx' . $f4_char, SecretMask::mask('xxxxxxxxxxxx' . $f4_char), 'A U+100000 character is valid UTF-8 — its tail renders complete, never shed to the bare mask.');
        $this->assertNotFalse(json_encode(SecretMask::mask('xxxxxxxxxxxx' . $f4_char)));
        // The FIVE-byte F4 shape is invalid UTF-8 (beyond U+10FFFF):
        // the regex twin called it valid — mask() would have shipped
        // it raw. Every candidate slice carries it: the bare mask.
        $this->assertSame('…', SecretMask::mask("xxxxxxxxxxxx\xF4\x80\x80\x80\x80"), 'The five-byte F4 shape is beyond U+10FFFF — never shipped as a tail the regex mistook for valid.');

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
        $token = 'xxxxxxxxxxxxé';
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
            $source = $this->productionSource($class);

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
        $owner = $this->productionSource(HeaderMap::class);
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
            $source = $this->productionSource($class);
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
        $request = $this->productionSource(HttpRequest::class);
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
        $owner = $this->productionSource(HeaderMap::class);
        $this->assertStringContainsString('isset( $this->headers_by_lowercase[ $folded ] )', $owner);
    }

    /**
     * The production-source read behind the structural pins (OCR
     * round 45, t31-ocr45-8, one census over all five sites — the
     * round-56 straggler the docblock's claim already named): the
     * pins read through silent `(string) file_get_contents()` casts,
     * so a failed read degraded to '' and the fragment assertions
     * failed LATE with misleading messages — a needle mismatch over
     * an empty string, never the environment verdict it was. The
     * read owns its failure now: a named refusal carrying the class
     * and the file, the pin's own vocabulary.
     *
     * @param string $class The production class whose source a pin reads.
     * @return string The class's file bytes.
     */
    private function productionSource(string $class): string
    {
        $path = (new \ReflectionClass($class))->getFileName();
        $source = false === $path ? false : file_get_contents($path);
        if (false === $source) {
            $this->fail('The production source for ' . $class . ' cannot be read' . (false === $path ? ' (ReflectionClass::getFileName() answered false)' : ' (' . $path . ')') . ' — an environment verdict the structural pin names, never a late fragment mismatch over the empty string (t31-ocr45-8).');
        }

        return $source;
    }

    /**
     * Verifier-round pin (t31-r11-5): the SERIALIZATION channel rides
     * the redaction contract. The r1 contract enumerated three vectors
     * — the string cast, the redacted URL, the masked header lines —
     * but not print_r()/var_dump(): without __debugInfo() the engine
     * dumps the raw property tree, and an Authorization value, a
     * token-bearing query, and the body rendered in full (reproduced
     * pre-fix). Every secret-carrying VO's dump mirrors the masked
     * vocabulary now — one render owner for headers (the same decision
     * rendered_lines() rides), redacted URL, omitted body.
     */
    public function testTheSerializationChannelDumpsMaskedFormsNeverRawSecrets(): void
    {
        $token = FakeSecrets::accessToken();

        $request = new HttpRequest(
            'POST',
            'https://user:pw@host.example/token?client_secret=' . $token,
            array('Authorization' => 'Bearer ' . $token, 'X-Request-Id' => 'abc-123'),
            '{"client_secret":"' . $token . '"}'
        );
        $dumped = print_r($request, true);
        $this->assertStringNotContainsString($token, $dumped, 'The request dump must never carry the raw token — not via the URL, a header, or the body.');
        $this->assertStringNotContainsString('user:pw', $dumped, 'Userinfo is credentials by the contract\'s own doctrine.');
        $this->assertStringNotContainsString('client_secret=', $dumped, 'The query is dropped with the redacted URL.');
        $this->assertStringContainsString('[body omitted]', $dumped, 'The dump mirrors the string form\'s body vocabulary.');
        $this->assertStringContainsString((string) SecretMask::mask('Bearer ' . $token), $dumped, 'The Authorization value dumps in its masked form — the same render the string form carries.');
        $this->assertStringContainsString('abc-123', $dumped, 'A non-sensitive value dumps verbatim, same as it renders.');
        $this->assertStringContainsString('https://host.example/token', $dumped, 'The dump carries the REDACTED URL spelling.');

        $response = new HttpResponse(200, array('Set-Cookie' => 'session=' . $token), 'token=' . $token);
        $dumped = print_r($response, true);
        $this->assertStringNotContainsString($token, $dumped, 'The response dump must never carry the raw session secret — not via Set-Cookie nor the body.');
        $this->assertStringContainsString((string) SecretMask::mask('session=' . $token), $dumped, 'The Set-Cookie value dumps masked.');
        $this->assertStringContainsString('[body omitted]', $dumped);

        // The map owner's own dump is the same masked vocabulary, and
        // var_dump (the other engine dumper) honors it too.
        $map = new HeaderMap(array('X-Api-Key' => $token));
        $this->assertStringNotContainsString($token, print_r($map, true), 'The header map dump masks its sensitive values.');
        $this->assertStringNotContainsString($token, print_r(array($request, $response, $map), true), 'Nested dumps honor the mask — the engine applies __debugInfo at every level.');
        ob_start();
        var_dump($request);
        $var_dumped = (string) ob_get_clean();
        $this->assertStringNotContainsString($token, $var_dumped, 'var_dump rides the same masked dump.');
    }

    /**
     * Dedup pin (t31-r12-13): the header facade — headers()/header()
     * and the __toString()/__debugInfo() bodies — is ONE implementation
     * (the HasMaskedHeaders trait) both value objects consume. The two
     * VOs' string forms must therefore AGREE on the masking vocabulary
     * for an identical header set: byte-identical header lines and
     * byte-identical masked map values, with only the head line (the
     * request line vs the status line) and the debug head fields
     * differing. Before the trait, each side carried its own copy of
     * the plumbing — a future edit to one would have drifted the
     * other silently.
     */
    public function testBothValueObjectsStringFormsAgreeOnTheMaskingVocabulary(): void
    {
        $headers = array(
            'Authorization' => 'Bearer ' . FakeSecrets::accessToken(),
            'Set-Cookie' => 'session=' . FakeSecrets::accessToken(),
            'X-Request-Id' => 'abc-123',
        );
        $request = new HttpRequest('POST', 'https://host.example/token', $headers, '{"a":1}');
        $response = new HttpResponse(200, $headers, '{"a":1}');

        $request_lines = explode("\n", (string) $request);
        $response_lines = explode("\n", (string) $response);

        // Head and tail are the VO's own; every line between is the
        // shared masked header render.
        $this->assertSame('POST https://host.example/token', $request_lines[0]);
        $this->assertSame('HTTP 200', $response_lines[0]);
        $this->assertSame('[body omitted]', end($request_lines));
        $this->assertSame('[body omitted]', end($response_lines));
        $shared_request = array_slice($request_lines, 1, -1);
        $shared_response = array_slice($response_lines, 1, -1);
        $this->assertSame($shared_request, $shared_response, 'The header lines between head and body-omitted marker are the ONE shared render — identical bytes on both VOs.');

        // The debug form agrees the same way: identical masked map
        // values, only the head fields diverge.
        $request_debug = $request->__debugInfo();
        $response_debug = $response->__debugInfo();
        $this->assertSame($request_debug['headers'], $response_debug['headers'], 'The masked header map is the ONE shared render in the serialization channel too.');
        $this->assertSame('[body omitted]', $request_debug['body']);
        $this->assertSame('[body omitted]', $response_debug['body']);
        $this->assertSame(array('method', 'redacted_url', 'headers', 'body'), array_keys($request_debug), 'The request contributes exactly its head fields.');
        $this->assertSame(array('status', 'headers', 'body'), array_keys($response_debug), 'The response contributes exactly its head field.');

        // The facade twins delegate identically.
        $this->assertSame($request->headers(), $response->headers());
        $this->assertSame($request->header('x-request-id'), $response->header('X-Request-Id'));
        $this->assertNull($request->header('X-Other'), 'A name neither carries folds through the same owner.');
    }
}
