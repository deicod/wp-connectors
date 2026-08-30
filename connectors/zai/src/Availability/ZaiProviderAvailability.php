<?php
/**
 * Z.ai provider availability (scaffold).
 *
 * Placeholder until Task 1.4 implements the authenticated probe with a
 * persisted validated state bound to the complete key hash and key source.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Availability;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Provider availability for z.ai.
 *
 * @since 0.1.0
 */
final class ZaiProviderAvailability implements ProviderAvailabilityInterface {

	/**
	 * The core-owned option holding the z.ai API key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_OPTION = 'connectors_ai_zai_api_key';

	/**
	 * Reports whether a key is present.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a non-empty key value is stored.
	 */
	public function isConfigured(): bool {
		$key = get_option( self::KEY_OPTION, '' );

		return \is_string( $key ) && '' !== $key;
	}
}
