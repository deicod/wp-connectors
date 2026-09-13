<?php
/**
 * Malformed provider response (Task 3.1).
 *
 * The provider answered, but the payload cannot be parsed into its
 * expected shape (token response without an access token, non-JSON body
 * where JSON was required, wrong envelope). Deliberately carries no raw
 * payload: the message names the expected shape, and upstream text is
 * left to the redacting error catalog.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Exception;

/**
 * A malformed-response failure (unparseable provider payload).
 *
 * @since 0.1.0
 */
final class OAuthMalformedResponseException extends OAuthRuntimeException {

}
