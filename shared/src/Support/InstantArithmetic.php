<?php
/**
 * Absolute-second arithmetic on instants (Task 3.1, review round t31-r1).
 *
 * Single owner of the rule every derived instant rides: second offsets
 * are ABSOLUTE elapsed time, never wall-clock arithmetic in the
 * instant's named timezone. DateTimeImmutable::modify( '+N seconds' )
 * in a DST-observing zone crosses transitions in wall time — an
 * expiry derived that way drifts by the transition delta (an hour in
 * most zones) — so every add/subtract first projects the instant into
 * UTC (fixed offset, no transitions of its own), applies the offset
 * there, and re-attaches the original timezone: same instant, same
 * zone, real elapsed seconds. Microseconds ride along untouched
 * (setTimezone() and modify() are instant-preserving where used).
 *
 * Pure stateless functions, no environment access.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * DST-proof second arithmetic for instants.
 *
 * @since 0.1.0
 */
final class InstantArithmetic {

	/**
	 * The projection zone: UTC has no DST transitions, so arithmetic
	 * there is absolute by construction.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UTC_ZONE_NAME = 'UTC';

	/**
	 * Adds absolute seconds to an instant (timezone attached unchanged).
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $instant The base instant.
	 * @param int               $seconds Seconds to add (absolute elapsed time).
	 * @return DateTimeImmutable The shifted instant in the original timezone.
	 */
	public static function plus_seconds( DateTimeImmutable $instant, int $seconds ): DateTimeImmutable {
		return self::offset_in_utc( $instant, $seconds );
	}

	/**
	 * Subtracts absolute seconds from an instant (timezone attached unchanged).
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $instant The base instant.
	 * @param int               $seconds Non-negative seconds to subtract.
	 * @return DateTimeImmutable The shifted instant in the original timezone.
	 */
	public static function minus_seconds( DateTimeImmutable $instant, int $seconds ): DateTimeImmutable {
		return self::offset_in_utc( $instant, -$seconds );
	}

	/**
	 * Applies the offset in UTC, then restores the original timezone.
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $instant The base instant.
	 * @param int               $seconds Signed seconds to apply in the UTC projection.
	 * @return DateTimeImmutable The shifted instant in the original timezone.
	 */
	private static function offset_in_utc( DateTimeImmutable $instant, int $seconds ): DateTimeImmutable {
		$timezone = $instant->getTimezone();

		return $instant
			->setTimezone( new DateTimeZone( self::UTC_ZONE_NAME ) )
			->modify( sprintf( '%+d seconds', $seconds ) )
			->setTimezone( $timezone );
	}
}
