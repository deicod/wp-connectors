<?php
/**
 * Plugin orchestration for Connectors: z.ai.
 *
 * Owns provider registration and the missing-SDK dependency notice. Kept free
 * of WordPress globals where possible so the registration logic can be tested
 * against any registry instance.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;

/**
 * Plugin orchestrator.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * The provider ID registered by this plugin.
	 *
	 * Core derives the connector card and the API-key option name
	 * (connectors_ai_zai_api_key) from it (architecture record 0001).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const PROVIDER_ID = 'zai';

	/**
	 * Whether the PHP AI Client SDK is available.
	 *
	 * On WordPress 7.0+ the SDK always ships in core; on 6.9 it is provided by
	 * the standalone PHP AI Client plugin (architecture record 0003).
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if the SDK class exists.
	 */
	public static function sdk_available(): bool {
		return \class_exists( AiClient::class );
	}

	/**
	 * Registers the z.ai provider with the given registry.
	 *
	 * Idempotent: a provider already registered under the same class name (or
	 * the provider ID) is left untouched, so repeated `init` executions are
	 * harmless.
	 *
	 * @since 0.1.0
	 *
	 * @param ProviderRegistry $registry Registry to register with.
	 * @return void
	 */
	public static function register( ProviderRegistry $registry ): void {
		if ( $registry->hasProvider( ZaiProvider::class ) ) {
			return;
		}

		$registry->registerProvider( ZaiProvider::class );
	}

	/**
	 * Renders the admin notice shown when the PHP AI Client SDK is missing.
	 *
	 * No-op when the SDK is available (the notice self-heals if the SDK
	 * appears or disappears between requests).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_dependency_notice(): void {
		if ( self::sdk_available() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%1$s</p><p>%2$s</p></div>',
			esc_html__( 'Connectors: z.ai requires the PHP AI Client SDK, which was not found.', 'zai' ),
			esc_html__( 'WordPress 7.0 and newer ship the SDK in core. On WordPress 6.9, install and activate the standalone PHP AI Client plugin.', 'zai' )
		);
	}
}
