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

        // Non-vacuity: the owner really owns all three halves.
        $owner = (string) file_get_contents((new \ReflectionClass(HeaderMap::class))->getFileName());
        foreach (array('must not contain line breaks', 'strtolower', 'is_sensitive_header_name', 'rendered_lines') as $fragment) {
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
