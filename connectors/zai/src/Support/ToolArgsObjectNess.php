<?php
/**
 * Object-ness-preserving conversion of raw-decoded tool arguments
 * (code-review GLM1 #2, shared by both surfaces as of GLM5 #1).
 *
 * The associative json_decode() the SDK parsers use collapses the JSON
 * object {} and the JSON list [] into the same empty PHP array and turns a
 * numeric-keyed JSON object ({"0":"x"}) into a list — information the
 * outbound replay could not recover, so nested empty objects and
 * numeric-keyed objects silently re-encoded as JSON lists on the wire. This
 * walk starts from the RAW (non-associative) decode instead, keeping a PHP
 * array wherever an array re-encodes as an object unambiguously (any
 * non-sequential key set — consumer ergonomics unchanged for ordinary
 * arguments) and a stdClass wherever an array would re-encode as a list
 * (the empty object and the purely sequential-keyed object).
 *
 * Both surfaces' inbound tool-argument parsing routes through this ONE
 * conversion (the zai_anthropic parser since GLM1 #2, the zai
 * chat.completions parser since GLM5 #1), so a tool call replayed across
 * surfaces preserves identical shapes.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Converts raw-decoded tool inputs with JSON object-ness preserved.
 *
 * @since 0.2.0
 */
final class ToolArgsObjectNess {

	/**
	 * Converts a raw-decoded tool input into the stored arguments value,
	 * preserving JSON object-ness at EVERY level.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value A value from a non-associative json_decode() tree.
	 * @return mixed The same tree with object-ness preserved for encoding.
	 */
	public static function from_raw( $value ) {
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );

			$converted = array();
			foreach ( $properties as $key => $member ) {
				$converted[ $key ] = self::from_raw( $member );
			}

			// An empty object, or one whose keys would re-encode the array
			// as a JSON list, stays a stdClass (with converted members).
			if ( array() === $converted
				|| \array_keys( $converted ) === \range( 0, \count( $converted ) - 1 ) ) {
				$object = new \stdClass();
				foreach ( $converted as $key => $member ) {
					$object->{$key} = $member;
				}

				return $object;
			}

			return $converted;
		}

		if ( \is_array( $value ) ) {
			$converted = array();
			foreach ( $value as $key => $member ) {
				$converted[ $key ] = self::from_raw( $member );
			}

			return $converted;
		}

		return $value;
	}
}
