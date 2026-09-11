<?php
/**
 * A ResponseException whose fixed message this plugin owns (glm13-7).
 *
 * The surfaces' parse pipelines re-wrap SDK parse failures so no upstream
 * field can reach an exception message — but those blanket catches also
 * ate THIS plugin's own precise rejections (the zai surface's
 * tool-arguments diagnostics of GLM6 #1/GLM5 #2/GLM12 #8), degrading them
 * to the one generic string. This subclass is byte-identical to the SDK's
 * ResponseException on the wire (same message shape, same instanceof
 * everywhere) and distinguishable only BY TYPE, so a sanitizing catch can
 * pass the plugin's fixed messages through and rewrite just the genuinely
 * unmapped failures. glm14-2 extended the family to the zai_anthropic
 * surface's tool_use input rejections and to both surfaces' mislabeled-
 * JSON fallbacks, whose null-out catches are the same sanitizing shape.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Providers\Http\Exception\ResponseException;

/**
 * Marks the plugin's own fixed-message response rejections.
 *
 * @since 0.2.0
 */
final class FixedMessageResponseException extends ResponseException {

	/**
	 * Builds a fixed-message rejection in the SDK's fromInvalidData()
	 * message shape.
	 *
	 * GLM14-8: the byte-identical promise holds BY CONSTRUCTION — the
	 * message is the parent factory's own product, not a hand-rolled
	 * sprintf copy of its format string. An SDK release that rewords
	 * fromInvalidData() updates this subclass's messages with it, so the
	 * wire contract the class exists to uphold (the SDK's shape,
	 * everywhere, always) cannot split between SDK-thrown and
	 * plugin-thrown exceptions.
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_name   The provider label (the surface's REFUSAL_LABEL).
	 * @param string $field_name The rejected wire member.
	 * @param string $message    The fixed, safe rejection message.
	 * @return self
	 */
	public static function fixed( string $api_name, string $field_name, string $message ): self {
		return new self(
			ResponseException::fromInvalidData( $api_name, $field_name, $message )->getMessage()
		);
	}
}
