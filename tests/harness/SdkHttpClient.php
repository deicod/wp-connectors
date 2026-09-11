<?php
/**
 * PSR-18 HTTP client that blocks all SDK-level network access in tests.
 *
 * The PHP AI Client SDK transports requests through a PSR-18 client, not
 * through wp_remote_*. Installing this client into
 * AiClient::defaultRegistry() guarantees the SDK can never perform a real
 * network call: every request is recorded, queued mock responses are served
 * in FIFO order, and anything else fails as a network exception.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class SdkHttpClient implements ClientInterface
{
    /**
     * Records the request and serves a queued mock, or fails closed.
     *
     * @param RequestInterface $request PSR-7 request.
     * @return ResponseInterface
     * @throws SdkHttpBlocked When no mock response is queued.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $mocked = count(WpHarness::$sdk_mock_queue) > 0;
        WpHarness::$sdk_http_attempts[] = array(
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $request->getHeaders(),
            'body' => (string) $request->getBody(),
            'mocked' => $mocked,
        );

        if ($mocked) {
            return array_shift(WpHarness::$sdk_mock_queue);
        }

        throw new SdkHttpBlocked($request);
    }
}

/**
 * Thrown when SDK transport is attempted without a queued mock response.
 */
final class SdkHttpBlocked extends Exception implements ClientExceptionInterface, NetworkExceptionInterface
{
    /**
     * The blocked request.
     *
     * @var RequestInterface
     */
    private $request;

    public function __construct(RequestInterface $request)
    {
        parent::__construct(
            'SDK HTTP transport is blocked by the wp-connectors test harness. Queue a mock response first.'
        );
        $this->request = $request;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

/**
 * PSR-18 client that flips a WordPress option on its FIRST request before
 * delegating, simulating a concurrent settings save that lands between the
 * request build and the response processing (GLM10 #1).
 */
final class MidFlightOptionFlipClient implements ClientInterface
{
    /**
     * @var ClientInterface
     */
    private $inner;

    /**
     * @var string
     */
    private $option;

    /**
     * @var mixed
     */
    private $value;

    /**
     * @var bool
     */
    private $armed = true;

    /**
     * @param ClientInterface $inner  The delegating client (the harness SdkHttpClient).
     * @param string          $option Option name to write mid-flight.
     * @param mixed           $value  Option value to write mid-flight.
     */
    public function __construct(ClientInterface $inner, string $option, $value)
    {
        $this->inner = $inner;
        $this->option = $option;
        $this->value = $value;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->armed) {
            $this->armed = false;
            update_option($this->option, $this->value);
        }

        return $this->inner->sendRequest($request);
    }
}
