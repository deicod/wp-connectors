<?php
/**
 * Availability evaluation context (Task 3.1).
 *
 * WHY the caller is asking decides what the implementation may do:
 *
 * - Render — a GET render (provider admin page, host connectors
 *   screen). STRICTLY read-only: the implementation reports from cached
 *   grant state only — no HTTP, no token rotation, no event creation
 *   during the render. A stale expiry reports temporarily unavailable;
 *   any needed refresh event must already exist or be created from
 *   POST/scheduled paths.
 * - Maintenance — a POST or scheduled context, where refreshing,
 *   probing, and event creation are allowed.
 *
 * The contract is fixed here; its enforcement (and the deterministic
 * per-context tests) land with the availability and admin tasks.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Availability;

/**
 * Which execution context an availability answer is for.
 *
 * @since 0.1.0
 */
enum AvailabilityContext: string {

	/**
	 * GET-render context: read-only, cached-state answers only.
	 */
	case Render = 'render';

	/**
	 * POST/scheduled context: refreshes, probes, and events allowed.
	 */
	case Maintenance = 'maintenance';
}
