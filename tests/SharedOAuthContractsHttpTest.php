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

        // The legal mirrors stay constructible: the round-1 space/tab
        // adjudication keeps its verdict (those bytes render oddly but
        // forge nothing — no consumer re-splits the authority on them),
        // and the rebuilt authority carries the raw host byte verbatim.
        $tab_url = "http://h\tst.example:8080/token";
        $this->assertSame("h\tst.example:8080", Url::parse_validated($tab_url)['authority'], 'The space/tab adjudication stands — those bytes forge nothing, the backslash did.');
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
         * The raw-derivation fold pin (the round's verifier, rd-1): a
         * host TAB is legal per the round-1 host-charset adjudication,
         * and this engine's parse_url() REWRITES it to '_' — the old
         * rebuild rode that rewrite ('h_st.example'), the raw
         * derivation carries the byte verbatim. The pin holds the
         * agreement the one split exists for: authority() spells
         * exactly the bytes url() carries, whatever the engine's own
         * host spelling would be.
         */
        $tabUrl = "https://h\tst.example/token";
        $this->assertSame("h\tst.example", Url::parse_validated($tabUrl)['authority'], 'The rebuilt authority carries the raw host byte verbatim — never the engine parse’s own host rewrite (the one-split agreement, pinned).');
        $this->assertSame("https://h\tst.example/token", (new HttpRequest('GET', $tabUrl))->redacted_url(), 'The redacted form and the raw parse answer one verdict over the tab-bearing host.');
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
            'zone id spelling' => 'http://[fe80::1%25eth0]/x',
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
     * Review-round pin (t31-r12-8): the post-parse host re-check. The
     * whole-URL UTF-8 probe at entry guarantees the INPUT bytes; the
     * rebuilt authority (parsed host + case fold) is re-validated on
     * the way out, so a mangling fold refuses loudly instead of
     * flowing into the json_encode-false log-drop class. The mangler
     * the screen guards against is real C-library behavior — on an
     * 8-bit LC_CTYPE, tolower(0xC3)=0xE3 breaks the second byte of a
     * UTF-8 host — so the pin MANUFACTURES tr_TR.ISO-8859-9 (localedef
     * into a private LOCPATH, per the r11-6 attempt-and-restore shape)
     * and proves the invariant under pressure: the multibyte host
     * validates byte-identically (the fold rides AsciiFold's byte
     * tables — t31-ocr1-4 — no locale to consult, identical by
     * construction), and the guard itself fires on the exact
     * mangled spelling a byte-mapping fold produces (driven through
     * the private probe, the closeArchiveOrThrow precedent). A host
     * that cannot manufacture the locale skips VISIBLY (t31-ocr6-12):
     * the spelling pins above the skip still ran, the pressure half is
     * named as not-run — never silently green under a name claiming
     * pressure was applied.
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

        // The legal repro pinned once under the C fold for the
        // byte-identity comparison below.
        $utf8_url = 'https://münchen.example/token';
        $c_locale_authority = Url::parse_validated($utf8_url)['authority'];

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

            // The invariant: the multibyte host validates and the
            // rebuilt authority is byte-identical to the C-locale
            // parse — the fold stayed UTF-8-clean under pressure,
            // and the re-check is the guard that keeps it so.
            $parts = Url::parse_validated($utf8_url);
            $this->assertSame($c_locale_authority, $parts['authority'], 'The multibyte authority is byte-identical under the 8-bit LC_CTYPE.');
            $this->assertSame('https://münchen.example/token', (new HttpRequest('GET', $utf8_url))->redacted_url());

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

        // The r4-13 outcome holds on the safe-debug side regardless of
        // the locale: every accepted multibyte host's debug form
        // json_encodes to a string, never false.
        $vo = new HttpRequest('GET', $utf8_url);
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
        foreach (array('apitoken', 'clientsecret', 'x-authorization-scheme', 'www-authenticate') as $spelling) {
            $this->assertFalse(SecretMask::is_sensitive_header_name($spelling), "The '{$spelling}' spelling is not credential-bearing — the boundary stays the hyphen token, and 'www-authenticate' carries a challenge-scheme list, never a credential.");
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
