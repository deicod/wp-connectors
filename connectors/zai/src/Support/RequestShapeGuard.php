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
}
