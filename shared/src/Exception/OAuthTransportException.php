<?php
/**
 * Transient transport failure (Task 3.1).
 *
 * Network-level failure of an OAuth HTTP exchange: DNS, connection,
 * timeout, TLS. Thrown by the HTTP transport binding. Retryable under the
 * refresh policy — a transport failure must never mark a grant dead.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

/**
 * A transport-level OAuth failure (transient).
 *
 * @since 0.1.0
 */
final class OAuthTransportException extends OAuthRuntimeException implements OAuthTransientException {

}
