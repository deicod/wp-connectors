<?php
/**
 * glm19-4 — PreDecodedResponse forwarding tests.
 *
 * The pre-decoded hand-off shim must not hollow out the Response it
 * wraps: headers, body, and the array view ride along from the ORIGINAL
 * response so a reader beyond getData() (a future SDK release reading
 * Content-Type, a new caller wanting the raw stream text) sees the real
 * response, not a silent empty view.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\Providers\Http\DTO\Response;
use Deicod\WpConnectors\Zai\Support\PreDecodedResponse;

final class PreDecodedResponseTest extends WpConnectorsTestCase
{
    public function testTheWrappedResponseForwardsHeadersBodyAndStatus()
    {
        $original = new Response(
            200,
            array('Content-Type' => array('text/event-stream'), 'X-Request-Id' => array('req_7')),
            "data: {\"choices\":[]}\n\n"
        );

        $wrapped = new PreDecodedResponse($original, array('id' => 'chatcmpl-x', 'choices' => array()));

        $this->assertSame(200, $wrapped->getStatusCode(), 'The status code forwards.');
        $this->assertSame($original->getHeaders(), $wrapped->getHeaders(), 'The full header map forwards.');
        $this->assertSame(array('text/event-stream'), $wrapped->getHeader('Content-Type'), 'Single-header reads forward.');
        $this->assertSame('text/event-stream', $wrapped->getHeaderAsString('Content-Type'), 'String-header reads forward.');
        $this->assertSame('req_7', $wrapped->getHeaderAsString('X-Request-Id'), 'Unrelated headers forward too.');
        $this->assertTrue($wrapped->hasHeader('X-Request-Id'), 'Header presence forwards.');
        $this->assertSame("data: {\"choices\":[]}\n\n", $wrapped->getBody(), 'The original body forwards (the raw stream text, not a synthetic empty view).');
        $this->assertSame($original->toArray(), $wrapped->toArray(), 'The array view carries the forwarded members.');
        $this->assertSame(array('id' => 'chatcmpl-x', 'choices' => array()), $wrapped->getData(), 'getData() still returns the pre-decoded payload without a body decode.');
    }
}
