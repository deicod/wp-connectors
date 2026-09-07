<?php
/**
 * Shared typed pre-transport request-member rules for both z.ai surfaces
 * (glm19-5).
 *
 * Five validation rules lived as near-verbatim twins across the two model
 * classes, differing only in the provider-label string — and the branch's
 * own CHANGELOG records five one-surface-late incidents of exactly that
 * drift shape (GLM12 #5, glm13-8→glm18-6, glm13-9, glm16-11, glm18-3):
 * the same rule rejected typed pre-transport on one surface while riding
 * to the misattributed upstream 400 on the other. The rules live here
 * once now, label-parameterized — the AdvertisedOptionGuard /
 * AdvertisedUsageGuard / JsonEncodeGuard pattern — so the next rule or
 * bound tweak lands on both surfaces in one edit.
 *
 * The WHY of each rule stays documented at the call sites (the decision
 * history is surface-specific); this class owns the RULE and its message.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Rejects misshapen request members both z.ai surfaces share.
 *
 * @since 0.2.0
 */
final class RequestShapeGuard {

	/**
	 * Rejects a declared tool function with an empty name.
	 *
	 * '' is the only constructible empty identity (the FunctionDeclaration
	 * constructor coerces the name to a string); Messages and the
	 * OpenAI-compatible surface both require a non-empty identity, and the
	 * declaration path must not be the bypass that sends it upstream.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name           The declared function's name.
	 * @param string $provider_label Provider name for the message (the
	 *                               surface's PROVIDER_LABEL).
	 * @return void
	 * @throws InvalidArgumentException When the name is empty.
	 */
	public static function reject_empty_tool_name( string $name, string $provider_label ): void {
		if ( '' === $name ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider requires declared tool functions to carry a non-empty name.', $provider_label ) );
		}
	}

	/**
	 * Rejects a declared tool function whose name an earlier declaration
	 * already carries.
	 *
	 * A returned tool_call identifies the selected declaration ONLY by
	 * name, so two declarations sharing one make that identification
	 * ambiguous and a name-keyed consumer dispatches against the wrong
	 * tool.
	 *
	 * @since 0.2.0
	 *
	 * @param string              $name           The declared function's name.
	 * @param array<string, true> $declared_names Names seen so far in this
	 *                                      walk (the caller records the
	 *                                      name after this check passes).
	 * @param string              $provider_label Provider name for the message.
	 * @return void
	 * @throws InvalidArgumentException When the name is a duplicate.
	 */
	public static function reject_duplicate_tool_name( string $name, array $declared_names, string $provider_label ): void {
		if ( isset( $declared_names[ $name ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider requires declared tool functions to carry unique names.', $provider_label ) );
		}
	}

	/**
	 * Rejects a LIST-root tool parameter schema (a non-empty list only).
	 *
	 * A non-empty sequential schema serializes as a JSON list — never a
	 * valid schema root — and rode the wire unvalidated to the endpoint's
	 * generic misattributed 400. Null and [] keep their per-surface
	 * pass-through/normalization semantics: only a NON-EMPTY list rejects
	 * (the boundary both surfaces settled on).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $schema         The declaration's parameter schema.
	 * @param string $provider_label Provider name for the message.
	 * @return void
	 * @throws InvalidArgumentException When the schema is a non-empty list.
	 */
	public static function reject_list_root_parameter_schema( $schema, string $provider_label ): void {
		if ( \is_array( $schema ) && array() !== $schema && JsonShape::is_list( $schema ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider requires tool parameter schemas to be a JSON object (a non-empty list was given).', $provider_label ) );
		}
	}

	/**
	 * Rejects a LIST-root configured output schema (the empty list
	 * included).
	 *
	 * A JSON list is never a valid schema root; the SDK setter accepts
	 * any array, so ['a','b'] and [] both encode fine and previously rode
	 * to the endpoint's generic error (or embedded as meaningless
	 * guidance) instead of the typed pre-transport rejection.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $schema         The configured output schema.
	 * @param string $provider_label Provider name for the message.
	 * @return void
	 * @throws InvalidArgumentException When the schema is a list.
	 */
	public static function reject_list_root_output_schema( $schema, string $provider_label ): void {
		if ( \is_array( $schema ) && JsonShape::is_list( $schema ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider requires the configured output schema to be a JSON object (a list was given).', $provider_label ) );
		}
	}

	/**
	 * Rejects an explicitly non-positive maxTokens value.
	 *
	 * The SDK's setMaxTokens() is a bare assignment, so a zero/negative
	 * value rode the wire verbatim to the endpoint's generic misattributed
	 * upstream 400 instead of a typed pre-transport rejection naming the
	 * member. Null stays "not set".
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $max_tokens     The configured value.
	 * @param string   $provider_label Provider name for the message.
	 * @return void
	 * @throws InvalidArgumentException When the value is set and < 1.
	 */
	public static function reject_non_positive_max_tokens( $max_tokens, string $provider_label ): void {
		if ( null !== $max_tokens && 1 > $max_tokens ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider requires maxTokens to be a positive number.', $provider_label ) );
		}
	}

	/**
	 * Rejects an empty prompt before any transport work (glm29-5).
	 *
	 * The zai surface shipped the vendor parent's assembled
	 * "messages": [] verbatim although the chat-completions schema
	 * requires minItems 1 — a filter loop that removes every message
	 * made the round trip, answered 400, and surfaced the generic
	 * misattributed upstream rejection after the wasted request, while
	 * the zai_anthropic twin rejected the identical input typed
	 * pre-transport (glm18-3's maxTokens-parity class, the member that
	 * round left open). One rule on the shared guard serves both
	 * surfaces now; the twin's inline copy rides it too, byte-identical
	 * in message and channel.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $prompt         Prompt messages (list of Message).
	 * @param string $provider_label Provider name for the message.
	 * @return void
	 * @throws InvalidArgumentException When the prompt carries no message.
	 */
	public static function reject_empty_prompt( array $prompt, string $provider_label ): void {
		if ( array() === $prompt ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider requires at least one message.', $provider_label ) );
		}
	}

	/**
	 * Rejects a configured unit-interval member outside the CLOSED
	 * interval 0..1, or NAN (glm21-6).
	 *
	 * The Messages protocol bounds temperature and top_p to [0, 1] —
	 * values the SDK and the OpenAI-compatible surface accept
	 * (temperature up to 2.0) are protocol violations on the Anthropic
	 * surface that surfaced only as the upstream 400's generic
	 * misattributed message. Explicit comparisons keep 0.0 legal
	 * (it is falsy), and NAN is checked by name because it compares
	 * false against BOTH bounds (INF already fails the > 1 test, -INF
	 * the < 0 one); the unencodable float otherwise detonates as a raw
	 * JsonException in the transport's whole-request encode. The rule
	 * was a copy twin at the two Messages call sites before this class
	 * took it — a bound tweak edited on one member only would validate
	 * temperature and top_p against different ranges in one request.
	 *
	 * glm23-15 (review round 23, finding 15): the [0, 1] BOUND and its
	 * message naming are ONE-PROTOCOL facts — the Anthropic Messages
	 * range — while this guard's contract is protocol-neutral rules for
	 * both surfaces. The range phrase is the caller's parameter now
	 * (the PROVIDER_LABEL pattern), so a future zai/OpenAI-surface
	 * caller cannot reach for this helper and reject legal values with
	 * a message naming the wrong protocol: the Messages-only rule stays
	 * correct only where its caller says Messages.
	 *
	 * @since 0.2.0
	 *
	 * @param float|null $value          The configured member value.
	 * @param string     $member         The member name for the message
	 *                                   ('temperature', 'top_p').
	 * @param string     $provider_label Provider name for the message.
	 * @param string     $range_phrase   The bound's owner for the message
	 *                                   (e.g. 'the Anthropic Messages
	 *                                   protocol range').
	 * @return void
	 * @throws InvalidArgumentException When the value is set and NAN or
	 *                                  outside [0, 1].
	 */
	public static function reject_out_of_unit_interval( $value, string $member, string $provider_label, string $range_phrase ): void {
		if ( null !== $value && ( \is_nan( $value ) || $value < 0 || $value > 1 ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider requires %s between 0 and 1 (%s).', $provider_label, $member, $range_phrase ) );
		}
	}
}
