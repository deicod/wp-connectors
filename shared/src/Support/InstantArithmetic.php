<?php
/**
 * Absolute-second arithmetic on instants (Task 3.1, review round t31-r1).
 *
 * Single owner of the rule every derived instant rides: second offsets
 * are ABSOLUTE elapsed time, never wall-clock arithmetic in the
 * instant's named timezone. DateTimeImmutable::modify( '+N seconds' )
 * in a DST-observing zone crosses transitions in wall time — an
 * expiry derived that way drifts by the transition delta (an hour in
 * most zones) — and saturates silently for large offsets — so the
 * arithmetic runs on the raw integer timestamps instead and is
 * reconstructed with the original timezone and microseconds attached:
 * same zone, real elapsed seconds, faithful across the whole
 * representable range.
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
use InvalidArgumentException;

/**
 * DST-proof, saturation-proof second arithmetic for instants.
 *
 * @since 0.1.0
 */
final class InstantArithmetic {

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
	 * @param int               $seconds Seconds to subtract (absolute elapsed time).
	 * @return DateTimeImmutable The shifted instant in the original timezone.
	 * @throws InvalidArgumentException When the negation itself would overflow (PHP_INT_MIN — the shift is unrepresentable as an addition, never a silently wrong instant).
	 */
	public static function minus_seconds( DateTimeImmutable $instant, int $seconds ): DateTimeImmutable {
		if ( PHP_INT_MIN === $seconds ) {
			throw new InvalidArgumentException( sprintf( 'A %d-second shift is unrepresentable — the request is misconfigured, and the alternative is a silently wrong instant.', $seconds ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}

		return self::offset_in_utc( $instant, -$seconds );
	}

	/**
	 * Applies the offset to the raw timestamp, then restores the zone.
	 *
	 * The arithmetic is done on the INTEGER timestamp and reconstructed
	 * ('U u' carries the microseconds), never through modify():
	 * DateTime's relative arithmetic saturates SILENTLY far inside the
	 * int domain — an offset near a trillion seconds applies no shift
	 * at all, and one near PHP_INT_MAX clamps to ~1372 years — so a
	 * modify()-based shift would hand back a confidently wrong
	 * instant. A shift that would leave the int-timestamp domain
	 * altogether (int overflow to float) is rejected loudly instead.
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $instant The base instant.
	 * @param int               $seconds Signed seconds to apply.
	 * @return DateTimeImmutable The shifted instant in the original timezone.
	 * @throws InvalidArgumentException When the shift leaves the representable int-timestamp domain.
	 */
	private static function offset_in_utc( DateTimeImmutable $instant, int $seconds ): DateTimeImmutable {
		$timezone  = $instant->getTimezone();
		$timestamp = $instant->getTimestamp();

		if ( ( $seconds > 0 && $timestamp > PHP_INT_MAX - $seconds ) || ( $seconds < 0 && $timestamp < PHP_INT_MIN - $seconds ) ) {
			throw new InvalidArgumentException( sprintf( 'A %d-second shift leaves the representable instant range — the request is misconfigured, and the alternative is a silently wrong instant.', $seconds ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}

		return DateTimeImmutable::createFromFormat( 'U u', sprintf( '%d %06d', $timestamp + $seconds, (int) $instant->format( 'u' ) ) )
			->setTimezone( $timezone );
	}
}
