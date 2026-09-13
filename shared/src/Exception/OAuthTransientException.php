<?php
/**
 * Marker for transient OAuth failures (Task 3.1).
 *
 * Implemented by the transport and rate-limit exceptions. Transient
 * failures are retryable under the refresh policy's bounded cooldown —
 * they must never mark a grant dead or force a reconnection. Terminal
 * (reconnect-required), configuration (update-required), storage, and
 * malformed-response failures deliberately do NOT implement it, so
 * "is this retryable?" is answered by type alone.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

/**
 * Marks the transient (retryable) half of the OAuth failure taxonomy.
 *
 * @since 0.1.0
 */
interface OAuthTransientException {

}
