<?php
/**
 * Marker for the uncarriable-credential rejection (glm26-1).
 *
 * The glm16-13 pre-transport rejection — credential material containing
 * control characters or a comma cannot ride the Authorization header —
 * keeps everything that made it a RuntimeException binding-failure
 * family member (ErrorMapper's 500 zai_error mapping, the fixed
 * key-free message, the redaction contract): this subclass is
 * byte-identical to the vendor RuntimeException on the wire and
 * distinguishable only BY TYPE, the FixedMessageResponseException
 * pattern. The type exists so the availability PROBE can tell this one
 * rejection from every other pre-transport failure: an uncarriable key
 * can never authenticate on ANY request, which is definitive evidence
 * about the credential itself, not a transport hiccup. Before glm26-1
 * the probe's blanket catch(Throwable) converted it into an
 * INCONCLUSIVE verdict — a key pasted with a trailing newline saved as
 * connected and every generation then 500'd with no persisted verdict
 * (round 26 finding 1). Only
 * ZaiAnthropicRequestAuthentication::reject_uncarriable_credential()
 * throws it; nothing else may.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Authentication;

use WordPress\AiClient\Common\Exception\RuntimeException;

/**
 * Thrown when credential material cannot ride this surface's Authorization header.
 *
 * @since 0.2.0
 */
final class UncarriableCredentialException extends RuntimeException {

}
