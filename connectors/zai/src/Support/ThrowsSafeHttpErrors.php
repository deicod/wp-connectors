<?php
/**
 * Safe HTTP error throwing for both z.ai model surfaces (GLM1 #11).
 *
 * The throwIfNotSuccessful() override was byte-identical between the two
 * model classes; it lives here once now.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\RedirectException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;

/**
 * Throws SAFE, typed SDK exceptions for non-2xx responses.
 *
 * @since 0.2.0
 */
trait ThrowsSafeHttpErrors {

	/**
	 * Throws a SAFE, typed SDK exception when the response is not successful.
	 *
	 * The SDK defaults embed the upstream error body in the exception message;
	 * z.ai error bodies can echo request material (up to and including
	 * credential fragments). This override builds the message from the shared
	 * ErrorMapper catalog instead, because this exception travels the real
	 * dispatch path: core's prompt builder converts it to WP_Error passing
	 * the message through VERBATIM (no filter on that path), so the redaction
	 * must already be complete here. The exception TYPES are the SDK's own,
	 * so core's fixed instanceof mapping keeps producing the right code and
	 * HTTP status.
	 *
	 * No retries in v1: a non-2xx response always throws exactly once.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The HTTP response to check.
	 * @return void
	 * @throws ClientException   For 4xx responses.
	 * @throws ServerException   For 5xx responses.
	 * @throws RedirectException For 3xx responses.
	 */
	protected function throwIfNotSuccessful( Response $response ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		if ( $response->isSuccessful() ) {
			return;
		}

		$status = absint( $response->getStatusCode() );

		if ( $status >= 500 ) {
			throw new ServerException( ErrorMapper::safe_http_message( $status ), absint( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
		}

		if ( $status >= 400 ) {
			throw new ClientException( ErrorMapper::safe_http_message( $status ), absint( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
		}

		throw new RedirectException( ErrorMapper::safe_http_message( $status ), absint( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
	}
}
