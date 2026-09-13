<?php
/**
 * Provider-neutral clock port (Task 3.1).
 *
 * Everything time-related in the shared OAuth runtime depends on this
 * port, never on a direct clock function: token expiry math, refresh
 * windows, cooldowns, and grant aging all read their "now" through it.
 * The WordPress binding adapts to whatever time source the host offers;
 * the test harness supplies a deterministic implementation.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Clock;

use DateTimeImmutable;

/**
 * Contract for a readable wall clock.
 *
 * @since 0.1.0
 */
interface ClockInterface {

	/**
	 * The current reading.
	 *
	 * Implementations must return a fresh instant per call representing the
	 * current time — monotonicity is the wall clock's, not the port's.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable;
}
