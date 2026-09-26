<?php
/**
 * Terminal authorization failure — reconnect required (Task 3.1).
 *
 * The definitive authorization class ONLY: the provider returned
 * `invalid_grant` or an equivalent revoked-grant signal. The grant is
 * dead; the admin surface shows "Re-connect required" and connector
 * availability is false. Classification is deliberately restricted to
 * these definitive signals — throttling and transient failures are
 * different types and must never be laundered into this one.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

/**
 * A terminal (reconnect-required) authorization failure.
 *
 * @since 0.1.0
 */
final class OAuthTerminalAuthException extends OAuthRuntimeException {

}
