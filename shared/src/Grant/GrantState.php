<?php
/**
 * Persisted grant state (Task 3.1).
 *
 * The lifecycle states a stored grant carries. Transient failures are
 * deliberately NOT a state: a grant under cooldown, throttle, or outage
 * remains Connected — retryability is the refresh policy's business and
 * must never be persisted as a lifecycle change. The model is one LIVE
 * state (Connected, listed first) plus three distinct TERMINAL classes,
 * each terminal class with a different admin outcome (OCR round 6,
 * t31-ocr6-9, doc-only: the old header said "three distinct classes"
 * over a four-item list whose first item was the non-terminal one):
 *
 * - Connected — usable token set present (the live state).
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
