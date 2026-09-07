<?php
/**
 * Shared JSON shape predicates (round 8 cleanup, GLM8 #13).
 *
 * The exact sequential-key test — array_keys($value) === range(0,
 * count($value) - 1) — was hand-rolled four times over (the model's
 * tool-schema and tool-argument list rejections,
 * ToolArgsObjectNess's object-ness walk, and UsageValidator's
 * oracle-less fallback), each copy re-explaining the same json_encode()
 * key rule in its own comments: json_encode() emits a PHP array as a
 * JSON LIST only when its keys are exactly 0..N-1, the EMPTY array
 * included (it encodes as []). One predicate documents the rule once;
 * each call site keeps only its own empty-case policy, which
 * legitimately differs (an empty usage member is an object, an empty
 * tool-arguments array would re-encode as a list).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Object-vs-list shape decisions for decoded JSON values.
 *
 * @since 0.2.0
 */
final class JsonShape {

	/**
	 * Whether json_encode() would emit this array as a JSON list ([...])
	 * rather than an object ({...}).
	 *
	 * True exactly for the empty array and the 0-based sequential-keyed
	 * one; every non-sequential key set (mixed or string keys included)
	 * re-encodes as an object. The pathological numeric-KEYED JSON object
	 * ({"0":"x"}) collapses onto the true side after an associative
	 * decode — the documented limitation every former hand-rolled copy
	 * carried.
	 *
	 * glm31-9: the predicate rides the NATIVE array_is_list() now — an
	 * engine function on PHP 8.1+, the SDK's files-autoloaded polyfill
	 * (vendor/wordpress/php-ai-client/src/polyfills.php, wired through
	 * vendor/composer/autoload_files.php) on the composer-pinned 7.4
	 * floor. The SDK's own hot paths (PromptBuilder, the
	 * OpenAI-compatible parse the zai surface extends) already call it
	 * on every request, so every functional SDK installation provides
	 * it — JsonShape adds no new precondition. The hand-rolled
	 * range-over-count idiom is deleted; a second hand-maintained copy
	 * of platform semantics could silently diverge from the verdict the
	 * SDK itself applies to the same value (the GLM8 #13 extraction pin
	 * is superseded at its site to forbid the idiom everywhere).
	 *
	 * @since 0.2.0
	 *
	 * @param array $value The decoded array (of any key shape).
	 * @return bool True when the array encodes as a JSON list.
	 */
	public static function is_list( array $value ): bool {
		return \array_is_list( $value );
	}
}
