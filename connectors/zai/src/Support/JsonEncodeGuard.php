<?php
/**
 * Shared raw-json_encode() encodability oracle (GLM3 #4/GLM4 #1's
 * primitive, single-sourced by code-review GLM5 #16).
 *
 * An unencodable value — NAN, INF, a resource, invalid UTF-8, a
 * recursive structure — that reaches the transport detonates in its
 * whole-request json_encode(..., JSON_THROW_ON_ERROR) as an untyped
 * JsonException surfaced as the generic 500 (zai_error). Every wire
 * value the adapter accepts is therefore guarded BEFORE transport with
 * the RAW json_encode() oracle, never wp_json_encode(): core's
 * _wp_json_sanity_check() rescue loop lossily substitutes or strips
 * invalid UTF-8 and returns a SUCCESSFUL encoding, so wp_json_encode()
 * never returns false for a string in production and a guard on it was
 * dead code outside the deliberately stricter test stub (empirically
 * confirmed against core by the GLM3 #4 verifier round).
 *
 * The oracle was inlined at seven call sites with per-site messages
 * that had already drifted (three fix rounds touched them in lockstep);
 * one guard owns the oracle, the phpcs exemption, and the ONE message
 * template now — call sites name only the VALUE under guard and the
 * PROVIDER whose request it was going to ride (GLM6 #5: the guard serves
 * both surfaces, so a hardcoded label would misattribute rejections on
 * one of them).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Rejects values the transport's whole-request encode cannot serialize.
 *
 * @since 0.2.0
 */
final class JsonEncodeGuard {

	/**
	 * Encodes with the RAW oracle, rejecting unencodable values typed.
	 *
	 * For call sites that need the encoded string itself (the output
	 * schema embedded in the JSON guidance, the tool-result wire
	 * member).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $value          The wire value to encode.
	 * @param string $subject        What the value is, for the rejection message
	 *                                (e.g. 'a stop sequence').
	 * @param string $provider_label The consuming provider's name, for the
	 *                                rejection message ('zai' or
	 *                                'zai_anthropic'; GLM6 #5 — the guard is
	 *                                shared by both surfaces, so the label
	 *                                belongs to the call site).
	 * @return string The JSON encoding.
	 * @throws InvalidArgumentException When the value cannot encode.
	 */
	public static function encode( $value, string $subject, string $provider_label ): string {
		$encoded = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM3 #4 verifier round).

		if ( false === $encoded ) {
			throw new InvalidArgumentException( self::message( $provider_label, $subject ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		return $encoded;
	}

	/**
	 * Rejects unencodable values typed, discarding the encoding.
	 *
	 * For call sites that only guard (the value travels to the wire
	 * untouched inside the request params).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $value          The wire value to guard.
	 * @param string $subject        What the value is, for the rejection message.
	 * @param string $provider_label The consuming provider's name, for the
	 *                                rejection message ('zai' or
	 *                                'zai_anthropic'; GLM6 #5).
	 * @return void
	 * @throws InvalidArgumentException When the value cannot encode.
	 */
	public static function must_encode( $value, string $subject, string $provider_label ): void {
		if ( false === json_encode( $value ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM3 #4 verifier round).
			throw new InvalidArgumentException( self::message( $provider_label, $subject ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}
	}

	/**
	 * The one rejection message template for every guard site.
	 *
	 * @since 0.2.0
	 *
	 * @param string $provider_label The consuming provider's name.
	 * @param string $subject        What the value is (e.g. 'the system instruction').
	 * @return string The fixed, safe message.
	 */
	private static function message( string $provider_label, string $subject ): string {
		return sprintf(
			'The %s provider could not JSON-encode %s (unencodable value such as NAN, invalid UTF-8, or a recursive structure).',
			$provider_label,
			$subject
		);
	}
}
