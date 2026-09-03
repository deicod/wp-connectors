<?php
/**
 * Shared usage validator for both z.ai surfaces (code-review GLM4 #11,
 * generalized to both member sets in GLM5 #3).
 *
 * The usage validation (object-ness oracle with the sequential-key
 * fallback, plus non-negative-integer members) existed twice on the
 * zai_anthropic surface — the non-streaming parser's inline block and the
 * aggregator's streamed_usage_is_valid() — hand maintained in two layers
 * until Codex R15 #1 had to fix both in lockstep once. One validator
 * served both of those paths since GLM4 #11; GLM5 #3 parameterized the
 * member list and wired the zai (OpenAI chat.completions) surface's
 * transports through the same source, so a usage-rule change can never
 * land on one surface only. It also owns the overflow-checked totals
 * (GLM4 #5) and the fixed rejection messages, for the same single-source
 * reason.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Validates and totals usage members for both wire protocols.
 *
 * @since 0.2.0
 */
final class UsageValidator {

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
	 * The token-count members the Anthropic Messages usage object carries.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ANTHROPIC_MEMBERS = array(
		'input_tokens',
		'cache_creation_input_tokens',
		'cache_read_input_tokens',
		'output_tokens',
	);

	/**
	 * The token-count members the OpenAI chat.completions usage object
	 * carries.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const OPENAI_MEMBERS = array(
		'prompt_tokens',
		'completion_tokens',
		'total_tokens',
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
	 * @param mixed    $usage     The associatively decoded usage member.
	 * @param mixed    $raw_usage The same member from the non-associative
	 *                            decode, or null when unavailable.
	 * @param string[] $members   The protocol's known token members
	 *                            (ANTHROPIC_MEMBERS or OPENAI_MEMBERS).
	 * @return string|null Null when valid; REASON_NOT_OBJECT or
	 *                     REASON_BAD_MEMBER when the caller must reject.
	 */
	public static function failure_reason( $usage, $raw_usage, array $members = self::ANTHROPIC_MEMBERS ): ?string {
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

		foreach ( $members as $member ) {
			if ( \array_key_exists( $member, $usage ) && ( ! \is_int( $usage[ $member ] ) || $usage[ $member ] < 0 ) ) {
				return self::REASON_BAD_MEMBER;
			}
		}

		return null;
	}

	/**
	 * The fixed rejection message for a failure reason (GLM5 #3).
	 *
	 * Both surfaces build these strings from the one source, so the
	 * wording cannot drift between the transports the way the underlying
	 * rules once did.
	 *
	 * @since 0.2.0
	 *
	 * @param string $reason A reason returned by failure_reason().
	 * @return string The fixed, safe message.
	 */
	public static function message_for_reason( string $reason ): string {
		return self::REASON_NOT_OBJECT === $reason
			? 'The usage member must be a JSON object.'
			: 'Token counts must be non-negative integers.';
	}

	/**
	 * Sums all four Anthropic usage members with exact overflow detection
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
		return self::sum_members( $usage, self::ANTHROPIC_MEMBERS );
	}

	/**
	 * Sums the three Anthropic input-side members with exact overflow
	 * detection.
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
	 * @param array<string, mixed> $usage   Validated usage member.
	 * @param string[]             $members Members to sum.
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
