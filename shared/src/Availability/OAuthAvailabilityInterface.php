<?php
/**
 * Provider-neutral OAuth availability port (Task 3.1).
 *
 * The seam the admin and connector surfaces ask through. The CONTEXT
 * parameter is the contract: a Render answer is computed from cached
 * grant state only — no HTTP, no token rotation, no cron/event
 * creation during a GET render — while a Maintenance answer may
 * refresh, probe, and schedule. Implementations that cannot honor the
 * Render contract must fail closed (report, never act).
 *
 * Availability computation itself (grant presence, decryptability,
 * expiry/refresh outcome, cheap provider probe) is the
 * availability-semantics task; this interface fixes the vocabulary it
 * must speak.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Availability;

/**
 * Contract for answering OAuth provider availability.
 *
 * @since 0.1.0
 */
interface OAuthAvailabilityInterface {

	/**
	 * The provider's availability in the given execution context.
	 *
	 * @since 0.1.0
	 *
	 * @param AvailabilityContext $context Render (read-only, cached answers) or Maintenance (actions allowed).
	 * @return AvailabilityState
	 */
	public function availability( AvailabilityContext $context ): AvailabilityState;
}
