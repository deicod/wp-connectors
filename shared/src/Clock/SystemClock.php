<?php
/**
 * System clock (Task 3.1).
 *
 * The production ClockInterface implementation: reads the host system
 * clock, UTC-explicit. Pure PHP — no host application access — so the same
 * class serves every binding. Tests never use it for time-sensitive
 * behavior; the harness owns a deterministic implementation instead.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Wall clock backed by the system time source.
 *
 * @since 0.1.0
 */
final class SystemClock implements ClockInterface {

	/**
	 * Current UTC reading.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}
