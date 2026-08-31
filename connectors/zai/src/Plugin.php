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
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;

/**
 * Plugin orchestrator.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * The provider ID of the OpenAI-compatible surface.
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
	 * The provider classes this plugin registers, in registration order.
	 *
	 * Each registration is individually guarded (see register()): the
	 * providers have distinct IDs, so neither can replace the other, and a
	 * failed registration of the second class leaves the first registered.
	 *
	 * @since 0.2.0
	 *
	 * @var list<class-string>
	 */
	const PROVIDER_CLASSES = array(
		ZaiProvider::class,
		ZaiAnthropicProvider::class,
	);

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
	 * Registers the plugin's providers with the given registry.
	 *
	 * Idempotent: a provider already registered under the same class name (or
	 * the provider ID) is left untouched, so repeated `init` executions are
	 * harmless. Providers are registered one at a time in a fixed order —
	 * the zai provider first — and each registration is independent, so a
	 * failing or skipped later registration can neither prevent nor damage an
	 * earlier one. The provider ID is additionally guarded: the SDK's
	 * registerProvider() silently overwrites an ID already held by a
	 * DIFFERENT class, so this method refuses to register onto a foreign
	 * registration instead of replacing it (Task 2.1).
	 *
	 * @since 0.1.0
	 *
	 * @param ProviderRegistry $registry Registry to register with.
	 * @return void
	 */
	public static function register( ProviderRegistry $registry ): void {
		foreach ( self::PROVIDER_CLASSES as $provider_class ) {
			if ( $registry->hasProvider( $provider_class ) ) {
				continue;
			}

			if ( $registry->hasProvider( $provider_class::metadata()->getId() ) ) {
				// Another class already owns this provider ID: never
				// replace a foreign registration.
				continue;
			}

			$registry->registerProvider( $provider_class );
		}
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

	/**
	 * Adds the Settings link to the plugin row.
	 *
	 * Hooked on `plugin_action_links_{plugin-basename}`. The API key itself
	 * lives on the core Connectors screen; this link leads to the plan/region
	 * selection and debug logging.
	 *
	 * @since 0.1.0
	 *
	 * @param array $links Existing action links.
	 * @return array Links with Settings prepended.
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . Settings\PlanRegionSettings::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'zai' ) . '</a>'
		);

		return $links;
	}
}
