<?php
/**
 * Persisted grant state (Task 3.1).
 *
 * The lifecycle states a stored grant carries. Transient failures are
 * deliberately NOT a state: a grant under cooldown, throttle, or outage
 * remains Connected — retryability is the refresh policy's business and
 * must never be persisted as a lifecycle change. The model is one LIVE
 * state (Connected, listed first) plus three distinct TERMINAL-INTENT
 * classes, each with a different admin outcome (OCR round 6,
 * t31-ocr6-9, doc-only: the old header said "three distinct classes"
 * over a four-item list whose first item was the non-terminal one):
 * terminal-INTENT, not terminal ENFORCEMENT — the value object is
 * transition-PERMISSIVE by design (StoredGrant::with_state() permits
 * LEAVING every one of these classes at the same generation; the
 * t31-r2-3 adjudication: a same-generation state change is
 * fence-neutral, and whether a terminal-intent class may actually be
 * left is the Task 3.3 coordinator's POLICY decision, not the VO's), so
 * a cross-file reader must not read this enum as the thing that makes
 * a grant undead (OCR round 9, t31-ocr9-5, doc-only):
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
