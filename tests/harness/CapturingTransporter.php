<?php
/**
 * The request-capturing HTTP transporter double (glm29-12).
 *
 * The byte-identical anonymous HttpTransporterInterface double (public
 * $captured_request; send() stores the request and answers a factory
 * success body) was spelled twice across the two request-mapping suites
 * — the exact consolidation shape OpaqueAuthentication.php ended for
 * the auth side: an SDK signature change to send() would break N
 * hand-maintained spellings instead of one harness-owned file, and a
 * future copy could silently differ from the capture contract the ride
 * pins assume. One parameterized double now; the success body is the
 * caller's argument.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

final class CapturingTransporter implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface
{
    /**
     * The request send() captured (the double's whole observation surface).
     *
     * @var \WordPress\AiClient\Providers\Http\DTO\Request|null
     */
    public $captured_request;

    /**
     * The success body send() answers with.
     *
     * @var string
     */
    private $body;

    public function __construct(string $body)
    {
        $this->body = $body;
    }

    public function send(\WordPress\AiClient\Providers\Http\DTO\Request $request, ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null): \WordPress\AiClient\Providers\Http\DTO\Response
    {
        $this->captured_request = $request;

        return new \WordPress\AiClient\Providers\Http\DTO\Response(200, array(), $this->body);
    }
}
