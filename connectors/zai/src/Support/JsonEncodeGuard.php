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
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

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
	 * Rejects a non-string or empty stop-sequence entry, then guards each
	 * entry's encodability (GLM9 #12).
	 *
	 * The per-entry loop was hand-duplicated between the two model
	 * classes and had already cost one drift: GLM3 #3 landed the
	 * non-empty-string rule on zai_anthropic only, and GLM7 #7 had to
	 * re-land it on zai. The SDK setter checks only list-ness, so a
	 * non-string or empty entry ([0], ['']) otherwise ships verbatim to
	 * an upstream 400 surfaced as the generic misattributed
	 * client-error message. One guard owns the rule now, parameterized
	 * by the provider label.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $stop_sequences The configured stop sequences (list).
	 * @param string $provider_label The consuming provider's name ('zai' or 'zai_anthropic').
	 * @return void
	 * @throws InvalidArgumentException When an entry is not a non-empty
	 *                                  string or cannot encode.
	 */
	public static function must_encode_stop_sequences( array $stop_sequences, string $provider_label ): void {
		foreach ( $stop_sequences as $sequence ) {
			if ( ! \is_string( $sequence ) || '' === $sequence ) {
				throw new InvalidArgumentException(
					sprintf( 'The %s provider requires every stop sequence to be a non-empty string.', $provider_label ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				);
			}

			self::must_encode( $sequence, 'a stop sequence', $provider_label );
		}
	}

	/**
	 * Rejects a tool-call identity that is null or empty on either
	 * member, then guards both strings' encodability (GLM9 #12).
	 *
	 * GLM7 #7's rule on the zai surface and Codex R9 #3's on the
	 * zai_anthropic surface — identical twins one label apart: the id
	 * and name ride the wire verbatim (the OpenAI parent's
	 * tool_calls member, the Messages tool_use block), so a null or
	 * empty identity ships to an upstream 400 unless rejected typed
	 * before transport.
	 *
	 * @since 0.2.0
	 *
	 * @param FunctionCall|null $function_call  The tool call (null already rejects).
	 * @param string            $provider_label The consuming provider's name.
	 * @return void
	 * @throws InvalidArgumentException When either identity member is
	 *                                  null/empty or cannot encode.
	 */
	public static function must_encode_tool_call_identity( ?FunctionCall $function_call, string $provider_label ): void {
		if ( null === $function_call
			|| null === $function_call->getId() || '' === $function_call->getId()
			|| null === $function_call->getName() || '' === $function_call->getName() ) {
			throw new InvalidArgumentException(
				sprintf( 'The %s provider requires every function-call part to carry a non-empty id and name.', $provider_label ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
			);
		}

		self::must_encode( $function_call->getId(), 'a tool call id', $provider_label );
		self::must_encode( $function_call->getName(), 'a tool call name', $provider_label );
	}

	/**
	 * Rejects a tool-result identity that is null or empty, then guards
	 * the id's encodability (GLM9 #12).
	 *
	 * The zai surface's GLM9 #4 guard and the zai_anthropic surface's
	 * tool-result identity rule — the same contract, one protocol-name
	 * apart: the id answers the tool call (the OpenAI parent's
	 * tool_call_id, the Messages tool_use_id) and ships verbatim, so a
	 * null or empty id fails upstream with the generic rejected-request
	 * message unless rejected typed before transport.
	 *
	 * @since 0.2.0
	 *
	 * @param FunctionResponse|null $function_response The tool result (null already rejects).
	 * @param string                $id_name           What the id is called on
	 *                                                  this protocol ('tool call id'
	 *                                                  or 'tool_use id'), for the
	 *                                                  rejection message.
	 * @param string                $id_subject        The encodability subject for
	 *                                                  the id.
	 * @param string                $provider_label    The consuming provider's name.
	 * @return void
	 * @throws InvalidArgumentException When the id is null/empty or
	 *                                  cannot encode.
	 */
	public static function must_encode_tool_result_identity( ?FunctionResponse $function_response, string $id_name, string $id_subject, string $provider_label ): void {
		if ( null === $function_response || null === $function_response->getId() || '' === $function_response->getId() ) {
			throw new InvalidArgumentException(
				sprintf( 'The %s provider requires every function-response part to carry the non-empty %s it answers.', $provider_label, $id_name ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
			);
		}

		self::must_encode( $function_response->getId(), $id_subject, $provider_label );
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
