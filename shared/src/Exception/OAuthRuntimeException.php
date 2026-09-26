<?php
/**
 * Base type for every shared OAuth runtime failure (Task 3.1).
 *
 * The typed-error taxonomy the OAuth runtime throws: each failure class
 * that requires a distinct caller reaction owns its own TYPE, so callers
 * (refresh coordination, retry wrapper, admin surfaces) branch on the
 * exception type alone and never on message parsing. The concrete types:
 *
 * - OAuthTransportException — transient (network, timeout, binding failure).
 * - OAuthRateLimitException — transient (throttled; Retry-After aware).
 * - OAuthTerminalAuthException — reconnect required (definitive
 *   authorization failure: invalid grant / revoked grant).
 * - OAuthConfigurationException — update required (deterministic client/
 *   configuration failure: invalid or unauthorized client).
 * - OAuthStorageException — encrypted-storage failure (envelope, key
 *   derivation, corruption).
 * - OAuthMalformedResponseException — a provider response that cannot be
 *   parsed into its expected shape.
 *
 * Message contract: messages are safe for admin display — no token
 * material, no credentials, no raw provider bodies. Redaction of upstream
 * error payloads belongs to the HTTP/error utilities task and its
 * plugin-owned catalog; these types never CARRY secrets in stringifiable
 * state, so an accidentally-verbose handler cannot leak one.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

use RuntimeException;

/**
 * Abstract base of the OAuth runtime exception family.
 *
 * Abstract by design: code always throws the most specific type, so the
 * base exists only to be caught.
 *
 * @since 0.1.0
 */
abstract class OAuthRuntimeException extends RuntimeException {

}
