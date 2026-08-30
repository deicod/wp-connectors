<?php
/**
 * Minimal PSR-18 client over the curl extension — LIVE-TEST TOOLING ONLY.
 *
 * The offline suite never needs a real HTTP client (the harness installs a
 * blocking one); this class exists solely for the opt-in live smoke test
 * (Task 1.9) and bin/zai-live-probe.php. It is test infrastructure and is
 * never shipped in a plugin artifact.
 *
 * Redirects are disabled deliberately: credential-bearing requests must not
 * be replayed at a cross-origin Location (plan Task 3.7 anticipates this for
 * every authenticated surface; this client enforces it from day one).
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CurlPsr18Client implements ClientInterface
{
    /**
     * Sends the request with curl; redirects disabled, TLS verification on.
     *
     * @param RequestInterface $request PSR-7 request.
     * @return ResponseInterface
     * @throws CurlPsr18Exception On transport failure or disabled redirect.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init((string) $request->getUri());
        if (false === $handle) {
            throw new CurlPsr18Exception('curl init failed');
        }

        $headers = array();
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $options = array(
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
        );

        $body = (string) $request->getBody();
        if ('' !== $body) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);

        if (false === $raw) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            throw new CurlPsr18Exception("curl error {$errno}: {$error}");
        }

        // PHPStan's curl signature follows the analyzed PHP 7.4 platform
        // (resource) while the runtime uses CurlHandle; the handle is
        // validated non-false above.
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); // @phpstan-ignore argument.type
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE); // @phpstan-ignore argument.type

        $headerBlock = substr((string) $raw, 0, $headerSize);
        $bodyOut = substr((string) $raw, $headerSize);

        $headers = array();
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            list($name, $value) = explode(':', $line, 2);
            $headers[trim($name)][] = trim($value);
        }

        return new Nyholm\Psr7\Response($status, $headers, $bodyOut);
    }
}

/**
 * Transport failure surfaced as a PSR-18 client exception.
 */
final class CurlPsr18Exception extends Exception implements ClientExceptionInterface
{
}
