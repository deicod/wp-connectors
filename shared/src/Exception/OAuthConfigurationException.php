<?php
/**
 * Deterministic client/configuration failure — update required (Task 3.1).
 *
 * The THIRD terminal class: the provider returned `invalid_client` /
 * `unauthorized_client` — the extracted client identifier is rotated or
 * disabled. This is not a reconnection problem (the user's grant is not
 * at fault) and not transient (no retry will fix a dead client id): the
 * state suppresses retries until the connector's configuration changes
 * and surfaces as "update required".
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

/**
 * A permanent configuration (update-required) failure.
 *
 * @since 0.1.0
 */
final class OAuthConfigurationException extends OAuthRuntimeException {

}
