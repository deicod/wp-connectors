<?php
/**
 * Shared Messages usage validator (code-review GLM4 #11).
 *
 * The four-member usage validation (input_tokens /
 * cache_creation_input_tokens / cache_read_input_tokens / output_tokens
 * as non-negative integers, plus the object-ness oracle with the
 * sequential-key fallback) existed twice — the non-streaming parser's
 * inline block and the aggregator's streamed_usage_is_valid() — hand
 * maintained in two layers until Codex R15 #1 had to fix both in
 * lockstep once. One validator serves both paths now, so streaming and
 * non-streaming generations of the same provider can never validate
 * usage differently again; it also owns the overflow-checked totals
 * (GLM4 #5), for the same single-source reason.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Validates and totals Anthropic Messages usage members.
 *
 * @since 0.2.0
 */
final class AnthropicUsageValidator {

	/**
	 * Failure reason: the usage member is not a JSON object.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REASON_NOT_OBJECT = 'not_object';

	/**
	 * Failure reason: a supplied token count is not a non-negative int.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REASON_BAD_MEMBER = 'bad_member';

	/**
	 * The token-count members the Messages usage object carries.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const MEMBERS = array(
		'input_tokens',
		'cache_creation_input_tokens',
		'cache_read_input_tokens',
		'output_tokens',
	);

	/**
	 * Validates a usage member BEFORE any cast stores it (Codex R15 #1).
	 *
	 * A present usage must be an object-shaped array (a JSON list [1,2]
	 * passes is_array() after the associative decode, and its named
	 * members are then absent) whose SUPPLIED known token members are
	 * non-negative integers. Absent members stay tolerated (the
	 * default-zero tolerance is documented); an explicitly-null member is
	 * PRESENT (array_key_exists) and therefore rejected. Object-ness
	 * comes from the non-associative decode (the Codex R3 #1 oracle),
	 * with the sequential-key test as fallback when the oracle value is
	 * unavailable.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $usage     The associatively decoded usage member.
	 * @param mixed $raw_usage The same member from the non-associative
	 *                         decode, or null when unavailable.
	 * @return string|null Null when valid; REASON_NOT_OBJECT or
	 *                     REASON_BAD_MEMBER when the caller must reject.
	 */
	public static function failure_reason( $usage, $raw_usage ): ?string {
		if ( ! \is_array( $usage ) ) {
			// Present but scalar or null — not a usage object.
			return self::REASON_NOT_OBJECT;
		}

		if ( null === $raw_usage ) {
			// Oracle unavailable: the sequential-key list test.
			$is_object = array() === $usage
				|| \array_keys( $usage ) !== \range( 0, \count( $usage ) - 1 );
		} else {
			$is_object = \is_object( $raw_usage );
		}

		if ( ! $is_object ) {
			return self::REASON_NOT_OBJECT;
		}

		foreach ( self::MEMBERS as $member ) {
			if ( \array_key_exists( $member, $usage ) && ( ! \is_int( $usage[ $member ] ) || $usage[ $member ] < 0 ) ) {
				return self::REASON_BAD_MEMBER;
			}
		}

		return null;
	}

	/**
	 * Sums all four usage members with exact overflow detection
	 * (GLM4 #5).
	 *
	 * Every member is a validated non-negative int, but their sum can
	 * exceed PHP_INT_MAX — PHP silently promotes an overflowing integer
	 * sum to float, which detonates in TokenUsage's int-typed constructor
	 * as an uncaught TypeError (generic 500) instead of the typed
	 * malformed-usage rejection. The addition runs with an explicit
	 * bound check per member so no intermediate ever promotes; the
	 * boundary total PHP_INT_MAX itself stays representable.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $usage Validated usage member.
	 * @return int|null The four-member total, or null when it exceeds PHP_INT_MAX.
	 */
	public static function total( array $usage ): ?int {
		return self::sum_members( $usage, self::MEMBERS );
	}

	/**
	 * Sums the three input-side members with exact overflow detection.
	 *
	 * The aggregator's message_start usage carries only the prompt side;
	 * the same overflow guarantee applies to its stored input total.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $usage Validated usage member.
	 * @return int|null The input-side total, or null when it exceeds PHP_INT_MAX.
	 */
	public static function input_total( array $usage ): ?int {
		return self::sum_members(
			$usage,
			array( 'input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens' )
		);
	}

	/**
	 * Adds the named validated members without ever promoting to float.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $usage    Validated usage member.
	 * @param string[]             $members  Members to sum.
	 * @return int|null The sum, or null when it exceeds PHP_INT_MAX.
	 */
	private static function sum_members( array $usage, array $members ): ?int {
		$total = 0;

		foreach ( $members as $member ) {
			$count = (int) ( $usage[ $member ] ?? 0 );

			if ( $count > PHP_INT_MAX - $total ) {
				return null;
			}

			$total += $count;
		}

		return $total;
	}
}
