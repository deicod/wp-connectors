<?php
/**
 * Provider throttling (Task 3.1).
 *
 * The provider answered with a throttle response (HTTP 429 family).
 * Retryable: the refresh coordination honors the provider-supplied
 * Retry-After when present, under the policy's cap — an oversized
 * throttle must not suppress refreshes far past the outage, and the
 * absence of a Retry-After falls back to bounded exponential backoff.
 * A grant SURVIVES rate limiting; it is never marked dead.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

use Throwable;

/**
 * A rate-limit (throttle) failure (transient, Retry-After aware).
 *
 * @since 0.1.0
 */
final class OAuthRateLimitException extends OAuthRuntimeException implements OAuthTransientException {

	/**
	 * Provider-supplied Retry-After in seconds, when known.
	 *
	 * Already-parsed by the HTTP utilities (both provider forms — seconds
	 * and HTTP-date — land here as seconds); null when the provider sent
	 * none. Never trusted unbounded: the policy caps what any consumer
	 * derives from it.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private readonly ?int $retry_after_seconds;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $message            Safe, fixed message (no token material, no raw provider body).
	 * @param int            $code               Exception code.
	 * @param Throwable|null $previous           Previous exception, if any.
	 * @param int|null       $retry_after_seconds Parsed Retry-After in seconds, or null when the provider supplied none.
	 * @throws \InvalidArgumentException When the Retry-After value is negative.
	 */
	public function __construct( string $message = '', int $code = 0, ?Throwable $previous = null, ?int $retry_after_seconds = null ) {
		if ( null !== $retry_after_seconds && $retry_after_seconds < 0 ) {
			throw new \InvalidArgumentException( 'The Retry-After seconds must be null or non-negative.' );
		}

		parent::__construct( $message, $code, $previous );

		$this->retry_after_seconds = $retry_after_seconds;
	}

	/**
	 * Provider-supplied Retry-After in seconds, or null when none was supplied.
	 *
	 * ADJUDICATION (review round t31-r1-15): this accessor hands out
	 * the RAW provider number by design — the cap is applied by the
	 * consumer through RefreshPolicy::capped_retry_after_seconds(),
	 * never here (clamping inside the exception would couple it to the
	 * policy and to per-provider config it cannot know). Task 3.3's
	 * cooldown consumer is the ONE intended reader and lands with its
	 * own MUST-test for the clamp. Re-open only if a SECOND consumer
	 * reads the raw accessor — then misuse becomes structural and the
	 * coupling question is re-litigated.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null
	 */
	public function retry_after_seconds(): ?int {
		return $this->retry_after_seconds;
	}
}
