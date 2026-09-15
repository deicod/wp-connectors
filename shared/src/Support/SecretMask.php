<?php
/**
 * Secret-masking policy for safe debug rendering (Task 3.1).
 *
 * Single owner of the three facts every safe debug form depends on: what a
 * masked secret looks like (an ellipsis plus the last four characters —
 * enough to correlate a value across log lines, never enough to use it),
 * which HTTP header names always count as secret-bearing regardless
 * of the value they carry, and — since t31-r8-6 — how a verbatim value's
 * invalid-UTF-8 bytes render (percent-encoded: the r4-13 doctrine's
 * OUTCOME at the header render seam, where obs-text values must not
 * reject).
 *
 * Pure PHP, UTF-8-aware at the byte level (review round t31-r1): the
 * visible tail is the last four CHARACTERS — complete sequences, never
 * a partial one. A byte-wise tail split a multibyte character
 * mid-sequence and returned invalid UTF-8, which made json_encode()
 * drop or mangle the redacted log line. Binary (non-UTF-8) values
 * degrade to whichever trailing bytes form valid UTF-8, and to the
 * bare mask when none do: mask() NEVER returns invalid UTF-8.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Support;

/**
 * Masks secret values and identifies secret-bearing header names.
 *
 * @since 0.1.0
 */
final class SecretMask {

	/**
	 * The mask marker (horizontal ellipsis).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const MASK = '…';

	/**
	 * How many trailing characters of a masked secret stay visible.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const VISIBLE_TAIL = 4;

	/**
	 * Shortest secret whose tail is shown at all.
	 *
	 * At or below this length, four visible characters would reveal half
	 * the secret or more, so the mask shows nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MIN_LENGTH_FOR_VISIBLE_TAIL = 8;

	/**
	 * Header names (lowercase) whose values are always masked.
	 *
	 * 'location' joined with vendor-doc proof, not a hypothetical (review
	 * round t31-r12-4, driver adjudication): RFC 6749 section 4.1.2
	 * mandates the authorization code in the redirect's Location query
	 * — a 302's Location IS a credential-bearing surface by
	 * specification, and it rendered verbatim through every safe debug
	 * form while the request side masked its own credential headers
	 * (reproduced: 'Location: https://client/cb?code=…' in full in the
	 * string cast, the dump, and print_r). One owner: this catalog is
	 * the single spelling of what a sensitive header name is.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const SENSITIVE_HEADER_NAMES = array( 'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'location' );

	/**
	 * One well-formed UTF-8 sequence (the canonical byte grammar).
	 *
	 * Used to prove a candidate tail is standalone-valid UTF-8; no /u
	 * modifier, so an arbitrary byte string is simply matched, never
	 * rejected by the engine itself.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UTF8_SEQUENCE_PATTERN = '/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{3})*\z/';

	/**
	 * Masks a secret value: ellipsis plus the last four characters.
	 *
	 * Null and short values (at or below the minimum length, counted in
	 * characters) show the ellipsis only. The visible tail is always
	 * valid UTF-8: complete sequences for well-formed values, the
	 * longest valid trailing run for binary ones, the bare mask when no
	 * trailing bytes form a valid sequence.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $value The secret, or null.
	 * @return string The masked rendering (never invalid UTF-8).
	 */
	public static function mask( ?string $value ): string {
		if ( null === $value || self::count_characters( $value ) <= self::MIN_LENGTH_FOR_VISIBLE_TAIL ) {
			return self::MASK;
		}

		// The last VISIBLE_TAIL characters, then shed leading characters
		// until what remains is standalone-valid UTF-8 (a slice starting
		// mid-sequence, or carrying one, is not); empty means the bare
		// mask — never a partial character.
		$tail = self::tail_bytes_of_last_characters( $value, self::VISIBLE_TAIL );
		while ( '' !== $tail && 1 !== preg_match( self::UTF8_SEQUENCE_PATTERN, $tail ) ) {
			$tail = self::without_leading_character( $tail );
		}

		return '' === $tail ? self::MASK : self::MASK . $tail;
	}

	/**
	 * Whether a header name is secret-bearing (case-insensitive).
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name.
	 * @return bool True when the header's value must always be masked.
	 */
	public static function is_sensitive_header_name( string $name ): bool {
		return \in_array( AsciiFold::lower( $name ), self::SENSITIVE_HEADER_NAMES, true );
	}

	/**
	 * Renders a value so it is always VALID UTF-8 — the byte-level twin
	 * of mask()'s never-invalid contract, for values that render
	 * verbatim (verifier round t31-r8-6, the r4-13 doctrine on the
	 * header surface).
	 *
	 * A header value legally carries RFC 7230 obs-text (any high byte,
	 * t31-r1-19), and a Latin-1 value is obs-text the constructor must
	 * keep accepting — but its bytes are INVALID UTF-8, and
	 * json_encode() of the rendered line then returns FALSE: the log
	 * line is dropped, not degraded, the exact failure mode r4-13
	 * killed on the URL surface (by rejecting the input there — the
	 * URL constructor owes no obs-text hospitality). The render seam
	 * owes the same OUTCOME without rejecting the value: every byte of
	 * a well-formed sequence renders verbatim (the pinned obs-text
	 * rendering, e.g. a UTF-8 'café'), and every byte the canonical
	 * grammar cannot accept renders as its percent-encoded spelling
	 * ('%E9') — encoded, never destroyed, so the debug form stays
	 * diagnosable and the line always json_encodes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value about to render verbatim.
	 * @return string The same bytes when valid UTF-8, else invalid sequences percent-encoded.
	 */
	public static function utf8_for_safe_render( string $value ): string {
		if ( 1 === \preg_match( '//u', $value ) ) {
			return $value;
		}

		$rendered = '';
		$length   = \strlen( $value );
		for ( $i = 0; $i < $length; ) {
			$lead = \ord( $value[ $i ] );
			if ( $lead < 0x80 ) {
				$rendered .= $value[ $i ];
				++$i;
				continue;
			}

			/*
			 * The canonical grammar (the table UTF8_SEQUENCE_PATTERN
			 * spells as one regex): the lead byte's class fixes the
			 * sequence length and the constraints that reject overlong
			 * and out-of-range spellings. A byte (or run) the grammar
			 * cannot accept percent-encodes ONE byte at a time — the
			 * bytes after it get their own judgment.
			 */
			$sequence  = 0;
			$first_min = 0x80;
			$first_max = 0xBF;
			if ( $lead >= 0xC2 && $lead <= 0xDF ) {
				$sequence = 2;
			} elseif ( 0xE0 === $lead ) {
				$sequence  = 3;
				$first_min = 0xA0;
			} elseif ( ( $lead >= 0xE1 && $lead <= 0xEC ) || 0xEE === $lead || 0xEF === $lead ) {
				$sequence = 3;
			} elseif ( 0xED === $lead ) {
				$sequence  = 3;
				$first_max = 0x9F;
			} elseif ( 0xF0 === $lead ) {
				$sequence  = 4;
				$first_min = 0x90;
			} elseif ( $lead >= 0xF1 && $lead <= 0xF3 ) {
				$sequence = 4;
			} elseif ( 0xF4 === $lead ) {
				$sequence  = 4;
				$first_max = 0x8F;
			}
			$valid = $sequence > 0 && $i + $sequence <= $length;
			if ( $valid ) {
				$first = \ord( $value[ $i + 1 ] );
				$valid = ( $first & 0xC0 ) === 0x80 && $first >= $first_min && $first <= $first_max;
				for ( $j = 2; $valid && $j < $sequence; $j++ ) {
					$valid = ( \ord( $value[ $i + $j ] ) & 0xC0 ) === 0x80;
				}
			}
			if ( $valid ) {
				$rendered .= substr( $value, $i, $sequence );
				$i        += $sequence;
			} else {
				$rendered .= sprintf( '%%%02X', $lead );
				++$i;
			}
		}

		return $rendered;
	}

	/**
	 * Counts characters (UTF-8 sequence starts) — a byte count that
	 * treats every continuation byte as part of its character.
	 *
	 * For well-formed UTF-8 this is the code-point count; for binary
	 * values it counts apparent sequence starts (a conservative
	 * approximation — the threshold errs toward masking).
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value to measure.
	 * @return int The character count.
	 */
	private static function count_characters( string $value ): int {
		$characters = 0;
		$length     = \strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( ( \ord( $value[ $i ] ) & 0xC0 ) !== 0x80 ) {
				++$characters;
			}
		}

		return $characters;
	}

	/**
	 * The byte range covering the last N characters (sequence starts).
	 *
	 * Walks back over continuation bytes to each sequence start; the
	 * slice may still begin mid-sequence when the value itself is not
	 * well-formed UTF-8 — the caller sheds leading characters until the
	 * remainder validates standalone.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value to slice.
	 * @param int    $count How many trailing characters to cover.
	 * @return string The byte slice covering the last $count characters.
	 */
	private static function tail_bytes_of_last_characters( string $value, int $count ): string {
		$found = 0;
		$start = 0;
		for ( $i = \strlen( $value ) - 1; $i >= 0 && $found < $count; $i-- ) {
			if ( ( \ord( $value[ $i ] ) & 0xC0 ) !== 0x80 ) {
				++$found;
				$start = $i;
			}
		}

		return substr( $value, $start );
	}

	/**
	 * The slice minus its leading character (sequence start plus its
	 * continuation bytes).
	 *
	 * @since 0.1.0
	 *
	 * @param string $tail The slice to shed from.
	 * @return string The remainder.
	 */
	private static function without_leading_character( string $tail ): string {
		$length = \strlen( $tail );
		$i      = 1;
		while ( $i < $length && ( \ord( $tail[ $i ] ) & 0xC0 ) === 0x80 ) {
			++$i;
		}

		return substr( $tail, $i );
	}
}
