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
	 * the check below). Additionally, a
	 * FINITE INTEGRAL float beyond the platform int range is rejected: it
	 * can only be a wire integer json_decode() could not keep exact, so
	 * replay would ship a silently altered value in e-notation.
	 *
	 * Accepted residual, documented: the integers in the ~2048-wide window
	 * immediately above PHP_INT_MAX all decode to the SAME boundary float
	 * and are indistinguishable after the decode; every distinguishably
	 * out-of-range value is rejected.
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
	 * Whether the tree carries an integral float beyond the platform int.
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
			 * only finite floats. An integral float beyond PHP_INT_MAX is a
			 * decoded wire integer the platform int could not hold (a
			 * legitimate non-integral float of that magnitude is
			 * astronomically pathological for tool arguments and equally
			 * unrepresentable on replay).
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
