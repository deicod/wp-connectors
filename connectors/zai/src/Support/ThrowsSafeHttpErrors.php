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
use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;

/**
 * Throws SAFE, typed SDK exceptions for non-2xx responses.
 *
 * @since 0.2.0
 */
trait ThrowsSafeHttpErrors {

	/**
	 * Records the definitive invalid verdict a credential-rejecting
	 * response on this surface's GENERATION route represents (glm13-6).
	 *
	 * Declared here so the shared throw hook below can fire it before the
	 * typed exception; each surface implements it through its own
	 * availability owner, wired authentication reader, and request-time
	 * endpoint capture (the directories' discovery recording already had
	 * exactly this discipline — the generation route was the one route a
	 * 401/403 learned nothing from).
	 *
	 * @since 0.2.0
	 *
	 * @param int $status The definitive-rejection status the route answered.
	 * @return void
	 */
	abstract protected function record_generation_route_rejection( int $status ): void;

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

		/*
		 * glm13-6: a 401/403 on the GENERATION route is the endpoint
		 * rejecting the credential itself — the same definitive evidence
		 * the probe persists an invalid verdict for and the discovery
		 * routes (both metadata directories) already record. Without the
		 * recording, a key revoked server-side kept the connector
		 * reporting connected — and re-transmitting the dead credential
		 * on every generation — for the whole 300s STATE_TTL. The
		 * recording follows the glm13-1 binding discipline (an empty
		 * wired credential records nothing) and must never mask the typed
		 * throw below it feeds.
		 */
		if ( AbstractZaiProviderAvailability::is_definitive_rejection( $status ) ) {
			$this->record_generation_route_rejection( $status );
		}

		if ( $status >= 500 ) {
			throw new ServerException( ErrorMapper::safe_http_message( $status ), absint( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
		}

		if ( $status >= 400 ) {
			throw new ClientException( ErrorMapper::safe_http_message( $status ), absint( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
		}

		throw new RedirectException( ErrorMapper::safe_http_message( $status ), absint( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
	}
}
