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
	 * An int SUPPLIED BY THE CALLER, already parsed to seconds (both
	 * provider forms — seconds and HTTP-date — are the CALLER's parse;
	 * no Retry-After parsing lives in shared/src, and the HTTP/
	 * provider layer that will construct this exception is Task 3.2
	 * scope); null when the provider sent none. A NEGATIVE delta is
	 * meaningless but a parser can emit one (a header spelling the
	 * past, an HTTP-date behind the reading),
	 * so the constructor CLAMPS it to zero (t31-ocr2-7 — the same
	 * reality RefreshPolicy::capped_retry_after_seconds() documents and
	 * tests: negative clamps to zero, one truth on both sides); zero
	 * reads "retry immediately", null reads "the provider sent none" —
	 * the two facts stay distinct. Never trusted unbounded: the policy
	 * caps what any consumer derives from it.
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
	 * @param string         $message             Safe, fixed message (no token material, no raw provider body).
	 * @param int            $code                Exception code.
	 * @param Throwable|null $previous            Previous exception, if any.
	 * @param int|null       $retry_after_seconds Retry-After in seconds as supplied by the caller (already parsed; no parsing lives here), or null when the provider supplied none; a negative value clamps to zero (a parser can emit one, and "retry immediately" is its meaning).
	 */
	public function __construct( string $message = '', int $code = 0, ?Throwable $previous = null, ?int $retry_after_seconds = null ) {
		parent::__construct( $message, $code, $previous );

		$this->retry_after_seconds = null === $retry_after_seconds ? null : max( 0, $retry_after_seconds );
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
