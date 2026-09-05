<?php
/**
 * A ResponseException whose fixed message this plugin owns (glm13-7).
 *
 * The zai surface's parse pipeline re-wraps SDK parse failures so no
 * upstream field can reach an exception message — but that blanket catch
 * also ate THIS plugin's own precise rejections (the tool-arguments
 * diagnostics of GLM6 #1/GLM5 #2/GLM12 #8), degrading them to the one
 * generic string. This subclass is byte-identical to the SDK's
 * ResponseException on the wire (same message shape, same instanceof
 * everywhere) and distinguishable only BY TYPE, so a sanitizing catch
 * can pass the plugin's fixed messages through and rewrite just the
 * genuinely unmapped failures.
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
	 * @since 0.2.0
	 *
	 * @param string $api_name   The provider label (the surface's REFUSAL_LABEL).
	 * @param string $field_name The rejected wire member.
	 * @param string $message    The fixed, safe rejection message.
	 * @return self
	 */
	public static function fixed( string $api_name, string $field_name, string $message ): self {
		return new self( sprintf( 'Unexpected %s API response: Invalid "%s" key: %s', $api_name, $field_name, $message ) );
	}
}
