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
	 * At or below this length, four visible characters would reveal a
	 * third of the secret or more, so the mask shows nothing.
	 *
	 * OCR round 6 (t31-ocr6-1, the first shared/src security finding
	 * since round 3 — a tail-length policy gap, not a new channel): the
	 * canonical RFC 8628 user code ('BCJK-3502', nine characters with
	 * its separator) sat one character above the old threshold of 8 and
	 * rendered '…3502' — half the code's entropy on screen, for a value
	 * that is ITSELF a short-lived credential a dump should never help
	 * use. The visible-tail policy must never expose a tail of a value
	 * that short: OTP-class values (user codes, device codes of 12
	 * characters or fewer) render the bare mask; longer values keep the
	 * correlation tail. The policy is one threshold at this owner —
	 * every credential-bearing consumer (the flow VOs' codes, the token
	 * set, masked headers) rides it, none re-decides it.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MIN_LENGTH_FOR_VISIBLE_TAIL = 12;

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
	 * string cast, the dump, and print_r). One owner: this catalog and
	 * the suffix class below are together the single spelling of what
	 * a sensitive header name is.
	 *
	 * The catalog carries only the names the class rule CANNOT spell
	 * (OCR round 33, t31-ocr33-3, the t31-ocr29-5 subsumption doctrine
	 * over the catalog's own entries): 'authorization' IS a member of
	 * the suffix class, and 'proxy-authorization'/'x-api-key' end in
	 * '-authorization'/'-api-key' — all three rode the class
	 * identically and were behaviorally dead weight implying the
	 * catalog needed them. The battery pins the three spellings green
	 * through the class by construction (drop 'authorization' or
	 * 'api-key' from the suffixes and they redden).
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const SENSITIVE_HEADER_NAMES = array( 'cookie', 'set-cookie', 'location' );

	/**
	 * Credential-bearing name suffixes (lowercase): any folded header
	 * name that IS one of these, or whose final hyphen-token is one,
	 * counts as secret-bearing — the CLASS rule over the catalog.
	 *
	 * OCR round 15 (t31-ocr15-1, security): the closed catalog above
	 * could never grow as fast as vendors mint key-bearing header
	 * names. 'x-api-key' was covered while 'api-key' — the documented
	 * authentication header of a whole class of cloud AI vendors and
	 * an archetypal Task-3.7 transport binding — rendered its full
	 * secret verbatim
	 * through every safe debug form: the r12-4 leak class reopened
	 * under another vendor-documented spelling, and per-spelling
	 * catalog additions were a queue a leak had to reproduce first.
	 * The rule closes the class instead: each suffix below is a
	 * vendor-documented credential token ('api-key' covers the
	 * bare cloud-AI spelling and every '…-api-key' derivative;
	 * 'subscription-key' covers the APIM gateway's
	 * 'Ocp-Apim-Subscription-Key'; 'auth' the token-bearing
	 * spellings), and the boundary is
	 * the HYPHEN — a name merely ending in the suffix bytes
	 * ('x-api-keychain') is not the class, because vendor spellings
	 * are hyphenated tokens. No extension seam exists yet by
	 * adjudication: Task 3.7's transport binding decides whether a
	 * provider contributes spellings of its own, and the ledger holds
	 * that decision — a seam added before a second config source
	 * would be speculative reach.
	 *
	 * OCR round 24 (t31-ocr24-1, security — the first shared/src
	 * finding since round 14): the class did not cover the credential
	 * suffixes its own rule statement implies. 'token', 'secret', and
	 * 'authorization' are vendor-documented credential tokens in the
	 * rule's own sense — AWS STS signs with 'X-Amz-Security-Token',
	 * Shopify's REST API with 'X-Shopify-Access-Token', OAuth client
	 * credentials ride 'X-Client-Secret'/'X-Shared-Secret', and
	 * 'X-Authorization' is the prefixed bearer spelling — and every
	 * one rendered its full secret verbatim through every safe debug
	 * form while the catalog's exact 'authorization' spelling sat
	 * covered: the r12-4/ocr15-1 leak class under the class rule's
	 * own implied vocabulary. They join the list. Over-masking a
	 * non-credential '-token' header in DEBUG output errs safe (a
	 * correlation tail is lost, never a secret); the hyphen boundary
	 * is unaffected — the judged token stays the whole final
	 * hyphen-segment ('x-api-keychain' remains outside).
	 *
	 * OCR round 29 (t31-ocr29-5, maintainability): the round-15 entry
	 * 'auth-token' is SUBSUMED by 'token' and is gone — every name
	 * the entry matched (the bare 'auth-token' spelling, every
	 * '…-auth-token' derivative) ends in '-token' and rode 'token'
	 * identically, so the entry was behaviorally dead weight implying
	 * the class needed it. The battery's auth-token spellings
	 * ('x-auth-token', 'Auth-Token') ride 'token' green, by
	 * construction.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const SENSITIVE_HEADER_NAME_SUFFIXES = array( 'api-key', 'subscription-key', 'auth', 'authorization', 'token', 'secret' );

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
		while ( '' !== $tail && ! self::is_standalone_valid_utf8( $tail ) ) {
			$tail = self::without_leading_character( $tail );
		}

		return '' === $tail ? self::MASK : self::MASK . $tail;
	}

	/**
	 * Whether a header name is secret-bearing (case-insensitive).
	 *
	 * The catalog match first (the named spellings), then the class
	 * rule (t31-ocr15-1): a folded name that IS a credential suffix,
	 * or whose final hyphen-token is one, is secret-bearing the same
	 * way — see SENSITIVE_HEADER_NAME_SUFFIXES for the class and its
	 * boundary.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name.
	 * @return bool True when the header's value must always be masked.
	 */
	public static function is_sensitive_header_name( string $name ): bool {
		$folded = AsciiFold::lower( $name );
		if ( \in_array( $folded, self::SENSITIVE_HEADER_NAMES, true ) ) {
			return true;
		}

		foreach ( self::SENSITIVE_HEADER_NAME_SUFFIXES as $suffix ) {
			if ( $folded === $suffix || \str_ends_with( $folded, '-' . $suffix ) ) {
				return true;
			}
		}

		return false;
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
			$sequence = self::utf8_sequence_length_at( $value, $i );
			if ( $sequence > 0 ) {
				$rendered .= substr( $value, $i, $sequence );
				$i        += $sequence;
				continue;
			}

			/*
			 * A byte (or run) the canonical grammar cannot accept
			 * percent-encodes ONE byte at a time — the bytes after it
			 * get their own judgment.
			 */
			$rendered .= sprintf( '%%%02X', \ord( $value[ $i ] ) );
			++$i;
		}

		return $rendered;
	}

	/**
	 * The length of the well-formed UTF-8 sequence starting at $i, or 0
	 * when the byte there begins none — the canonical byte grammar's ONE
	 * spelling (OCR round 8, t31-ocr8-8: the regex table and the
	 * hand-rolled lead/continuation walk this file carried were two
	 * spellings of one grammar with no structural tie — a range fix
	 * landing on one silently drifted the other; both consumers ride
	 * this validator now, the regex twin is deleted).
	 *
	 * The lead byte's class fixes the sequence length and the
	 * first-continuation constraints that reject overlong and
	 * out-of-range spellings (C0/C1, E0 80-9F, ED A0-BF, F0 80-8F,
	 * F4 90-BF); later continuation bytes must be 80-BF; a sequence
	 * truncated by the string's end is not one. Byte-matched only,
	 * never the /u modifier, so an arbitrary byte string is simply
	 * judged, never rejected by the engine itself.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The bytes to judge.
	 * @param int    $i     The offset of the candidate lead byte.
	 * @return int The sequence's byte length (0 when no valid sequence starts at $i).
	 */
	private static function utf8_sequence_length_at( string $value, int $i ): int {
		$length = \strlen( $value );
		$lead   = \ord( $value[ $i ] );
		if ( $lead < 0x80 ) {
			return 1;
		}

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
		if ( 0 === $sequence || $i + $sequence > $length ) {
			return 0;
		}

		$first = \ord( $value[ $i + 1 ] );
		if ( ( $first & 0xC0 ) !== 0x80 || $first < $first_min || $first > $first_max ) {
			return 0;
		}
		for ( $j = 2; $j < $sequence; $j++ ) {
			if ( ( \ord( $value[ $i + $j ] ) & 0xC0 ) !== 0x80 ) {
				return 0;
			}
		}

		return $sequence;
	}

	/**
	 * Whether every byte of the value belongs to exactly one
	 * well-formed sequence — mask()'s standalone-tail proof (the former
	 * regex twin's charge, riding the one spelling since t31-ocr8-8).
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The bytes to judge.
	 * @return bool True when the whole value is well-formed UTF-8.
	 */
	private static function is_standalone_valid_utf8( string $value ): bool {
		for ( $i = 0, $length = \strlen( $value ); $i < $length; ) {
			$sequence = self::utf8_sequence_length_at( $value, $i );
			if ( 0 === $sequence ) {
				return false;
			}
			$i += $sequence;
		}

		return true;
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
