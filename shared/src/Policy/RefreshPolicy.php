<?php
/**
 * Provider-neutral refresh policy (Task 3.1).
 *
 * The numbers the OAuth refresh machinery runs on: how early a token is
 * refreshed (expiry minus skew), and how a retry cooldown is bounded.
 * The POLICY is neutral — the VALUES are per-provider configuration
 * supplied by each plugin's provider config (documented provider skews
 * live in the SPEC's shared-runtime section); nothing provider-specific
 * is named or defaulted here.
 *
 * Cooldown bounding rule: a provider-supplied Retry-After (in EITHER
 * form — seconds or HTTP-date, parsed to seconds by the HTTP utilities)
 * and the exponential-backoff fallback share ONE cap, so an oversized
 * throttle cannot suppress refreshes far past the outage. The
 * cap-bounded clamp lives here as pure math; the sequencing (persisted
 * cooldown, attempt counting, scheduling) is the refresh coordination's
 * job.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Policy;

use DateTimeImmutable;
use Deicod\WpConnectors\Shared\Token\AccessTokenSet;
use InvalidArgumentException;

/**
 * Immutable, constructor-validated refresh policy.
 *
 * @since 0.1.0
 */
final class RefreshPolicy {

	/**
	 * Seconds before expiry at which a refresh happens (expiry minus skew).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $refresh_skew_seconds;

	/**
	 * First exponential-backoff cooldown (also the policy's floor).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $backoff_initial_seconds;

	/**
	 * The one cap every cooldown form is bounded by.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $cooldown_cap_seconds;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int $refresh_skew_seconds    Refresh this many seconds before expiry (non-negative).
	 * @param int $backoff_initial_seconds First fallback backoff cooldown in seconds (positive).
	 * @param int $cooldown_cap_seconds    Cap for BOTH Retry-After forms and the backoff fallback (positive, at least the initial backoff).
	 * @throws InvalidArgumentException When any bound violates the contract above.
	 */
	public function __construct( int $refresh_skew_seconds, int $backoff_initial_seconds, int $cooldown_cap_seconds ) {
		if ( $refresh_skew_seconds < 0 ) {
			throw new InvalidArgumentException( 'The refresh skew must be non-negative seconds.' );
		}
		if ( $backoff_initial_seconds <= 0 ) {
			throw new InvalidArgumentException( 'The initial backoff must be positive seconds.' );
		}
		if ( $cooldown_cap_seconds <= 0 ) {
			throw new InvalidArgumentException( 'The cooldown cap must be positive seconds.' );
		}
		if ( $cooldown_cap_seconds < $backoff_initial_seconds ) {
			throw new InvalidArgumentException( 'The cooldown cap must be at least the initial backoff.' );
		}

		$this->refresh_skew_seconds    = $refresh_skew_seconds;
		$this->backoff_initial_seconds = $backoff_initial_seconds;
		$this->cooldown_cap_seconds    = $cooldown_cap_seconds;
	}

	/**
	 * Refresh skew in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function refresh_skew_seconds(): int {
		return $this->refresh_skew_seconds;
	}

	/**
	 * Initial backoff cooldown in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function backoff_initial_seconds(): int {
		return $this->backoff_initial_seconds;
	}

	/**
	 * Cooldown cap in seconds (governs every cooldown form).
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function cooldown_cap_seconds(): int {
		return $this->cooldown_cap_seconds;
	}

	/**
	 * Whether the token set should be refreshed at the given reading.
	 *
	 * The expiry-minus-skew rule: true once the reading reaches
	 * expiry minus skew (inclusive) — boundary readings refresh.
	 *
	 * @since 0.1.0
	 *
	 * @param AccessTokenSet    $token_set The token set to judge.
	 * @param DateTimeImmutable $now       Current clock reading.
	 * @return bool True when a refresh is due.
	 */
	public function should_refresh( AccessTokenSet $token_set, DateTimeImmutable $now ): bool {
		$threshold = $token_set->expires_at()->modify( sprintf( '-%d seconds', $this->refresh_skew_seconds ) );

		return $now >= $threshold;
	}

	/**
	 * Clamps a provider-supplied Retry-After (already parsed to seconds,
	 * either form) under the policy's cap.
	 *
	 * @since 0.1.0
	 *
	 * @param int $retry_after_seconds Parsed Retry-After seconds (negative values clamp to zero).
	 * @return int The bounded cooldown in seconds.
	 */
	public function capped_retry_after_seconds( int $retry_after_seconds ): int {
		return max( 0, min( $retry_after_seconds, $this->cooldown_cap_seconds ) );
	}
}
