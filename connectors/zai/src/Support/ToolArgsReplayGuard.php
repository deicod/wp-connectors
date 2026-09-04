<?php
/**
 * Replay guard for inbound tool arguments (code-review GLM4 #2).
 *
 * A tool call's arguments re-enter the OUTBOUND request the moment the
 * caller's tool loop replays the assistant turn — so a decoded value the
 * adapter cannot losslessly re-encode cannot be a generation: it would
 * poison every later request of that conversation at the transport's
 * whole-request json_encode (an untyped JsonException surfaced as the
 * generic 500), the exact contract GLM3 #1 established for translatable
 * parts. json_decode() produces two such value classes from legal JSON:
 *
 * - 1e999 decodes to INF (and NAN is representable via weird floats) —
 *   json_encode() fails on both, so the turn cannot replay at all;
 * - an integer literal beyond PHP_INT_MAX decodes to a float, silently
 *   altering the value at decode time (…809 became …808) and re-encoding
 *   in e-notation on replay.
 *
 * Shared by the non-streaming parser (typed ResponseException) and both
 * SSE acceptance points (malformed_tool_input flag) so the two transports
 * of one generation can never diverge on what replays.
 *
 * GLM12 #8 splits the out-of-range rule by what the call site can see: a
 * path holding the RAW wire string decides PRECISELY
 * (wire_arguments_are_replayable(): an integer literal beyond
 * PHP_INT_MAX survives only when the platform decode keeps it EXACT —
 * 1e20, 2^63 — and a genuinely lossy literal …789 collapsing to …808
 * rejects); a path holding only the DECODED value cannot tell an exact
 * big float from a lossy one (both re-encode stably), so its walker
 * stays CONSERVATIVE and rejects every finite integral float beyond the
 * int range. The old blanket justification — "a legitimate non-integral
 * float of that magnitude is equally unrepresentable" — was false: every
 * double >= 2^63 is integral (the spacing there is >= 2048), so the
 * rejection is undecidability-driven conservatism, never
 * representability.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Rejects decoded tool arguments that cannot replay onto the wire.
 *
 * @since 0.2.0
 */
final class ToolArgsReplayGuard {

	/**
	 * Whether a decoded tool-arguments value can re-enter a request as-is.
	 *
	 * The oracle is the RAW json_encode() (the GLM3 #4 primitive — core's
	 * wp_json_encode() lossily rescues invalid UTF-8 and would mask the
	 * failure): the value must encode, and the encoding must decode to a
	 * SEMANTICALLY equal value that re-encodes stably (the "encode →
	 * decode must reproduce a semantically equal value" contract; the
	 * one benign encoding instability, negative zero, is accepted — see
	 * the check below). Additionally, a FINITE INTEGRAL float beyond the
	 * platform int range is rejected — GLM12 #8: not because such a
	 * float loses anything on replay (a decode-origin finite double
	 * always re-encodes stably; every double >= 2^63 is integral), but
	 * because the DECODED value cannot prove it came from an exact
	 * literal. A call site holding the raw wire string decides precisely
	 * through wire_arguments_are_replayable() instead.
	 *
	 * Accepted residual, documented: the integers in the ~2048-wide window
	 * immediately above PHP_INT_MAX all decode to the SAME boundary float
	 * and are indistinguishable after the decode; on decoded-only paths
	 * every out-of-range integral float rejects (undecidable → reject),
	 * on raw-string paths exactly-representable literals accept.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value The decoded arguments tree (arrays, stdClass, scalars).
	 * @return bool True when the value replays losslessly.
	 */
	public static function is_replayable( $value ): bool {
		$encoded = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM3 #4 verifier round).

		if ( false === $encoded ) {
			// INF (the 1e999 decode), NAN, a resource, invalid UTF-8, or a
			// recursive structure: the turn cannot re-encode at all.
			return false;
		}

		$decoded   = json_decode( $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM3 #4 verifier round).
		$roundtrip = json_encode( $decoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM3 #4 verifier round).

		if ( false === $roundtrip ) {
			// The encoded form does not decode: the turn cannot replay.
			return false;
		}

		/*
		 * The encoding must be STABLE under a decode/re-encode round
		 * trip. The one BENIGN instability is negative zero (verifier
		 * round on GLM4 #2): json_encode(-0.0) is "-0", whose decode is
		 * the INT 0 — sign and float-ness both lost — re-encoding as "0".
		 * The value is numerically identical and replays fine, so when
		 * the encodings differ the two decoded trees are compared
		 * SEMANTICALLY: only a genuinely altered value fails.
		 */
		if ( $roundtrip !== $encoded && json_decode( $roundtrip ) != $decoded ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- the deliberate semantic-equality oracle (see the comment above).
			return false;
		}

		return ! self::has_out_of_range_integer_float( $value );
	}

	/**
	 * Whether a json_decode()-PRODUCED value can re-enter a request as-is
	 * (GLM9 #13: the structural fast path).
	 *
	 * The full oracle builds up to three strings (encode, decode,
	 * re-encode) per call — and the inbound acceptance points invoked it
	 * on already-validated immutable values at every layer: the parse,
	 * both SSE acceptance points, and per replayed historical tool call
	 * on every outbound request, O(K·S) serialization work that grows
	 * with the conversation even though the arguments never change after
	 * decode. For a value json_decode() PRODUCED, every hazard the
	 * string round trip detects is impossible by construction:
	 *
	 * - strings: json_decode() rejects invalid UTF-8 and malformed
	 *   surrogate escapes, so every string in the tree encodes;
	 * - NAN: JSON has no NAN literal, so no decode produces one (INF,
	 *   the 1e999 decode, is another matter — see below);
	 * - resources and recursive structures cannot survive a decode;
	 * - precision: PHP's json_encode uses the shortest-roundtrip float
	 *   representation, so every finite float re-decodes to itself and
	 *   the stability check provably passes.
	 *
	 * The two REAL hazards a decode can produce are exactly the ones the
	 * structural walker detects without building any string: INF
	 * (integral and beyond the int range — the walker's own branch) and
	 * an integral float beyond PHP_INT_MAX. The fast path is therefore
	 * the walker alone; it is semantically identical to the full oracle
	 * on every decode-origin value (whose encode/stability phases
	 * provably pass) at O(tree) with zero serialization. Callers handing
	 * the guard CALLER-built values (the outbound replay sites) must
	 * keep is_replayable() — those trees can carry NAN, invalid UTF-8,
	 * resources, and recursion the walker alone would miss.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value A tree produced by json_decode() (arrays,
	 *                     stdClass, scalars).
	 * @return bool True when the value replays losslessly.
	 */
	public static function is_replayable_decoded( $value ): bool {
		return ! self::has_out_of_range_integer_float( $value );
	}

	/**
	 * Whether a RAW wire arguments string replays losslessly — the
	 * PRECISE rule, available only where the original token is in hand
	 * (GLM12 #8).
	 *
	 * The decoded-only walker rejects every finite integral float beyond
	 * PHP_INT_MAX because post-decode it cannot distinguish an EXACT big
	 * literal (1e20, 2^63 — the double holds the literal's exact value
	 * and re-encodes stably) from a LOSSY one (…809 collapsing to the …808
	 * boundary double, every coarser magnitude losing digits). With the
	 * raw string the two ARE distinguishable: every integer literal
	 * beyond the platform int range is re-read through the platform's own
	 * exact decimal formatter (sprintf('%.0f') of the decoded float —
	 * %.0f emits no decimal separator, so the locale decimal-point rule
	 * cannot interfere), and a literal that does not come back identical
	 * is a true precision loss at decode time. An exponent-form giant
	 * ("1e999") decodes to INF and rejects through the encode oracle on
	 * the decoded tree; digit strings beyond ~1e309 decode to INF the
	 * same way.
	 *
	 * JSON string literals (values AND keys — "call 999…9 times" as a
	 * note, an id-shaped key) are stripped before the scan: their digits
	 * are data, not numeric literals, and a lossy-looking run inside a
	 * string replays verbatim. The caller must have established the
	 * string DECODES (the string-path acceptance sites already reject
	 * undecodable fragments before this rule runs).
	 *
	 * @since 0.2.0
	 *
	 * @param string $raw_arguments The raw arguments JSON string from the wire.
	 * @return bool True when every integer literal survives the decode exactly.
	 */
	public static function wire_arguments_are_replayable( string $raw_arguments ): bool {
		$decoded = json_decode( $raw_arguments ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle below is required: INF/NAN from exponent-form giants must fail, not be lossily rescued.

		if ( false === json_encode( $decoded ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle: INF (the 1e999 exponent decode) and NAN cannot encode, so the arguments cannot replay at all.
			return false;
		}

		/*
		 * Strip JSON string literals (values and keys): digits inside a
		 * string are data and replay verbatim, never a numeric literal.
		 *
		 * GLM12 verifier round: the star was originally a plain
		 * alternation group ('(?:[^"\\]|\\.)*'), which spends one engine
		 * frame per iteration — an escape-dense value (~20k escapes, or
		 * ~45k plain characters, both realistic for arguments embedding
		 * quoted JSON or code) exhausted the PCRE recursion limit,
		 * preg_replace() returned null, and the scan FAILED OPEN,
		 * silently accepting a lossy beyond-int literal in the same
		 * arguments. The unrolled-loop POSSESSIVE form below matches in
		 * linear time without per-character frames, and every engine
		 * failure now fails CLOSED through the conservative walker
		 * (reject undecidable) instead of returning true.
		 */
		$without_strings = preg_replace( '/"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"/', '""', $raw_arguments );

		if ( null === $without_strings ) {
			return self::is_replayable_decoded( $decoded );
		}

		$matches = preg_match_all( '/(?<![\d.eE-])-?\d+(?![\d.eE-])/', $without_strings, $literals );

		if ( false === $matches ) {
			// Engine failure on the scan itself: fail closed, exactly
			// like the stripper above.
			return self::is_replayable_decoded( $decoded );
		}

		if ( 0 === $matches ) {
			return true;
		}

		foreach ( $literals[0] as $literal ) {
			$negative = '-' === $literal[0];
			$digits   = $negative ? \substr( $literal, 1 ) : $literal;

			/*
			 * Within the platform int range the decode is exact (int), so
			 * only literals BEYOND PHP_INT_MAX need the exactness test.
			 * The bound compare is lexical on equal-length digit strings
			 * (numeric for same-length decimal digits).
			 */
			$bound = $negative ? '9223372036854775808' : '9223372036854775807';

			if ( \strlen( $digits ) < 19 || ( 19 === \strlen( $digits ) && $digits <= $bound ) ) {
				continue;
			}

			$value = (float) $literal;

			if ( \is_infinite( $value ) ) {
				// A digit string beyond ~1e309: the decode produces INF,
				// which cannot encode at all.
				return false;
			}

			if ( sprintf( '%.0f', $value ) !== $literal ) {
				// The nearest double is not this literal: true precision
				// loss at decode time (the ~2048-wide boundary window and
				// every coarser magnitude).
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the tree carries an integral float beyond the platform int.
	 *
	 * GLM12 #8: this is the CONSERVATIVE half of the split rule — every
	 * double >= 2^63 is integral (the spacing there is >= 2048), so this
	 * branch's finite floats are exactly-representable values that
	 * re-encode stably; rejecting them is undecidability-driven (post-
	 * decode, an exact big literal and a lossy one are the same double),
	 * not representability. Raw-string call sites use the precise
	 * wire_arguments_are_replayable() rule instead.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value The decoded arguments tree.
	 * @return bool True when a precision-loss float was found.
	 */
	private static function has_out_of_range_integer_float( $value ): bool {
		if ( \is_float( $value ) ) {
			/*
			 * INF/NAN already failed the encode above, so this branch sees
			 * only finite floats — all integral above 2^63, all
			 * stable-replaying doubles, rejected conservatively (see the
			 * docblock).
			 */
			return \floor( $value ) === $value && \abs( $value ) > PHP_INT_MAX;
		}

		if ( \is_array( $value ) ) {
			foreach ( $value as $member ) {
				if ( self::has_out_of_range_integer_float( $member ) ) {
					return true;
				}
			}

			return false;
		}

		if ( $value instanceof \stdClass ) {
			foreach ( \get_object_vars( $value ) as $member ) {
				if ( self::has_out_of_range_integer_float( $member ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
