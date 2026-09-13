<?php
/**
 * Persisted grant state (Task 3.1).
 *
 * The lifecycle states a stored grant carries. Transient failures are
 * deliberately NOT a state: a grant under cooldown, throttle, or outage
 * remains Connected — retryability is the refresh policy's business and
 * must never be persisted as a lifecycle change. The terminal model has
 * three distinct classes, each with a different admin outcome:
 *
 * - Connected — usable token set present.
 * - ReconnectRequired — dead grant (the definitive authorization class:
 *   invalid grant / revoked grant); the admin surface shows
 *   "Re-connect required".
 * - ConfigurationError — permanent client/configuration failure
 *   (invalid or unauthorized client): retries are suppressed until the
 *   connector's configuration changes; the admin surface shows
 *   "update required".
 * - Revoked — tombstone after explicit revocation: no tokens, advanced
 *   generation; exists to fence refreshes and exchanges that return
 *   after the revoke.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Grant;

/**
 * Lifecycle state of a stored grant.
 *
 * @since 0.1.0
 */
enum GrantState: string {

	/**
	 * Usable token set present.
	 */
	case Connected = 'connected';

	/**
	 * Dead grant: definitive authorization failure (re-connect required).
	 */
	case ReconnectRequired = 'reconnect_required';

	/**
	 * Permanent configuration failure (update required).
	 */
	case ConfigurationError = 'configuration_error';

	/**
	 * Revocation tombstone: no tokens, advanced generation.
	 */
	case Revoked = 'revoked';
}
