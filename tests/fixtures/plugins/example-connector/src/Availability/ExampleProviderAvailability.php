<?php
/**
 * Key-presence availability for the example provider (test fixture).
 *
 * Deliberately offline: real providers must validate keys with an
 * authenticated probe (Task 1.4); the fixture only proves the wiring.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare(strict_types=1);

namespace Deicod\WpConnectors\ExampleConnector\Availability;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

final class ExampleProviderAvailability implements ProviderAvailabilityInterface
{
	public function isConfigured(): bool
	{
		$key = get_option( 'connectors_ai_example_api_key', '' );

		return is_string( $key ) && '' !== $key;
	}
}
