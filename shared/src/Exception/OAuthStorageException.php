<?php
/**
 * Encrypted token storage failure (Task 3.1).
 *
 * Raised by the storage binding: key derivation unusable, envelope
 * tamper/corruption detected, missing crypto support, or a read/write
 * failure. Storage failures fail closed — the provider becomes
 * unavailable with a clear admin surface, never a partial-plaintext
 * fallback.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

/**
 * An encrypted-storage failure (fails closed).
 *
 * @since 0.1.0
 */
final class OAuthStorageException extends OAuthRuntimeException {

}
