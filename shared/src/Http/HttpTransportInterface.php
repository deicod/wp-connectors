<?php
/**
 * Provider-neutral HTTP transport port (Task 3.1).
 *
 * The ONE seam through which the shared OAuth runtime performs HTTP:
 * send a request value object, receive a response value object, or fail
 * with the typed transport exception. Everything binding-specific —
 * timeouts, accepted content types, bounded response sizes, TLS
 * defaults, redirect policy, and the underlying HTTP client — is the
 * implementation's contract, not the port's.
 *
 * Two invariants every implementation must uphold:
 *
 * - Redirects are disabled (or every redirect target is revalidated
 *   against the approved provider origin without forwarding headers or
 *   body) for EVERY credential-bearing request — a cross-origin 3xx
 *   must never replay the Authorization header or the request body at
 *   an attacker-controlled location.
 * - Failures surface as the shared typed exceptions, never as raw
 *   binding errors; provider error payloads (429 bodies, OAuth error
 *   JSON) are normalized above this port.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Http;

use Deicod\WpConnectors\Shared\Exception\OAuthTransportException;

/**
 * Contract for the OAuth HTTP transport.
 *
 * @since 0.1.0
 */
interface HttpTransportInterface {

	/**
	 * Sends one HTTP request.
	 *
	 * Returns the response whatever its status — a throttle or error
	 * status is a RESPONSE at this layer; promoting it to a typed
	 * exception happens above the port.
	 *
	 * @since 0.1.0
	 *
	 * @param HttpRequest $request The request to send.
	 * @return HttpResponse The received response.
	 * @throws OAuthTransportException On transport-level failure (network, timeout, TLS).
	 */
	public function send( HttpRequest $request ): HttpResponse;
}
