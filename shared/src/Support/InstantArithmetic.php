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
use DateTimeZone;
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
	 * @throws InvalidArgumentException When the shift leaves the representable int-timestamp domain (offset_in_utc()'s range rejection — the documented behavior both public spellings ride).
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
	 * @throws InvalidArgumentException When the negation itself would overflow (PHP_INT_MIN — the shift is unrepresentable as an addition, never a silently wrong instant), or when the negated shift leaves the representable int-timestamp domain (offset_in_utc()'s range rejection — the documented behavior both public spellings ride).
	 */
	public static function minus_seconds( DateTimeImmutable $instant, int $seconds ): DateTimeImmutable {
		if ( PHP_INT_MIN === $seconds ) {
			throw new InvalidArgumentException( sprintf( 'A %d-second shift is unrepresentable — the request is misconfigured, and the alternative is a silently wrong instant.', $seconds ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}

		return self::offset_in_utc( $instant, -$seconds );
	}

	/**
	 * Whether a signed second offset would drive a timestamp below the
	 * representable range (OCR round 2, t31-ocr2-6).
	 *
	 * This is the LOWER leg of offset_in_utc()'s guard, lifted to a
	 * named predicate so every pre-check consumer shares the SAME
	 * condition the guard enforces — RefreshPolicy's totality corner
	 * used to spell the inequality by hand beside it, and nothing
	 * structural tied the two (the exact drift the finding names). The
	 * predicate takes the offset SIGNED, exactly as the guard sees it:
	 * a caller about to SUBTRACT $skew seconds asks about the offset
	 * -$skew. Note the asymmetry with the guard's callers:
	 * minus_seconds() rejects a PHP_INT_MIN shift outright (the
	 * negation itself overflows), so that one magnitude never reaches
	 * this predicate through the arithmetic — a direct caller asking
	 * about it gets the guard's own answer, not minus_seconds'
	 * negation rejection.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timestamp Whole-seconds timestamp of the base instant.
	 * @param int $seconds   Signed seconds of the contemplated offset.
	 * @return bool True when the offset would leave the representable int-timestamp domain below.
	 */
	public static function offset_would_underflow( int $timestamp, int $seconds ): bool {
		return $seconds < 0 && $timestamp < PHP_INT_MIN - $seconds;
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

		// The two legs of the representability guard: overflow above,
		// and the named underflow predicate below (t31-ocr2-6) — the
		// predicate is the SINGLE owner of the lower condition, shared
		// with every pre-check consumer, so the guard and the
		// pre-checks can never disagree.
		if ( ( $seconds > 0 && $timestamp > PHP_INT_MAX - $seconds ) || self::offset_would_underflow( $timestamp, $seconds ) ) {
			throw new InvalidArgumentException( sprintf( 'A %d-second shift leaves the representable instant range — the request is misconfigured, and the alternative is a silently wrong instant.', $seconds ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}

		return self::reconstruct( $timestamp + $seconds, (int) $instant->format( 'u' ), $timezone );
	}

	/**
	 * Rebuilds an instant from raw integer parts (timestamp + microseconds,
	 * original timezone re-attached).
	 *
	 * The reconstruction seam exists because its one failure mode is not
	 * drivable through the public arithmetic: the timestamp is guarded
	 * into the int domain before it arrives, and 'u' is always the
	 * engine's own six-digit spelling, so createFromFormat() succeeds on
	 * every build this project supports (the 64-bit int domain IS the
	 * DateTime domain). A build whose DateTime range is narrower than
	 * the int domain would hand back false all the same, and the
	 * documented failure shape is the rejection — never the engine
	 * Error an unchecked false->setTimezone() escapes as (OCR round 1,
	 * t31-ocr1-1; AccessTokenSet::parse_serialized_instant() guards its
	 * sibling for externally-spelled input). The probe seam lets the
	 * regression drive the guard with a spelling the internal
	 * derivation cannot produce.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $timestamp  Whole seconds since the epoch.
	 * @param int          $microseconds Microseconds (0-999999 by derivation).
	 * @param DateTimeZone $timezone  The zone to re-attach.
	 * @return DateTimeImmutable The rebuilt instant.
	 * @throws InvalidArgumentException When the engine refuses the derived spelling.
	 */
	private static function reconstruct( int $timestamp, int $microseconds, DateTimeZone $timezone ): DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat( 'U u', sprintf( '%d %06d', $timestamp, $microseconds ) );
		if ( false === $parsed ) {
			throw new InvalidArgumentException( sprintf( 'The derived instant spelling (timestamp %d, microseconds %d) was refused by the engine — the request is misconfigured, and the alternative is a silently wrong instant.', $timestamp, $microseconds ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- validated ints in a developer-facing rejection; escaping belongs to the display layer.
		}

		return $parsed->setTimezone( $timezone );
	}
}
