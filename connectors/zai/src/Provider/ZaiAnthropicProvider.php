<?php
/**
 * Zai_anthropic provider (Anthropic-compatible surface).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Provider;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

/**
 * Provider definition for the z.ai Anthropic-compatible API.
 *
 * The base URL stays fixed to the canonical international general URL as
 * required by the SPEC (§3.3); the plan/region endpoint actually used per
 * request is resolved at request time in the model/directory layer. The
 * canonical value is PER-SURFACE: this provider's differs from the zai
 * (OpenAI-compatible) provider's.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicProvider extends AbstractZaiProvider {

	/**
	 * Connector ID used by core (connectors_ai_zai_anthropic_api_key option
	 * name, ZAI_ANTHROPIC_API_KEY env/constant).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROVIDER_ID = 'zai_anthropic';

	/**
	 * Card display name: distinguishes this provider's Connectors card from
	 * the zai provider's card on the same screen.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROVIDER_NAME = 'z.ai (Anthropic API)';

	/**
	 * The canonical base URL: international region, general plan.
	 *
	 * Fixed by contract (SPEC §3.3): the plan/region endpoint used for actual
	 * requests is resolved at request time via ZaiAnthropicEndpoint, so this
	 * value never changes with the settings.
	 *
	 * @since 0.2.0
	 *
	 * @return string Base URL.
	 */
	protected static function baseUrl(): string {
		return ZaiAnthropicEndpoint::CANONICAL_BASE_URL;
	}

	/**
	 * Creates the text generation model.
	 *
	 * @since 0.2.0
	 *
	 * @param ModelMetadata    $model_metadata     Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 * @return ModelInterface The model instance.
	 * @throws RuntimeException When the model capabilities are unsupported.
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new ZaiAnthropicTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		$capability_names = array();
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			$capability_names[] = (string) $capability;
		}

		throw new RuntimeException(
			'Unsupported model capabilities: ' . wp_json_encode( $capability_names )
		);
	}

	/**
	 * The provider's card display name.
	 *
	 * @since 0.2.0
	 *
	 * @return string Display name.
	 */
	protected static function provider_display_name(): string {
		return self::PROVIDER_NAME;
	}

	/**
	 * The provider's description text, translated.
	 *
	 * GLM6 #10: the literal sits INSIDE the __() call so i18n extraction
	 * (wp i18n make-pot and friends) can find it — the shared-base
	 * indirection (__( static::provider_description() )) was invisible
	 * to literal-scanning extractors and POT regeneration dropped the
	 * msgid. The untranslated fallback covers SDK-context use without
	 * WordPress loaded.
	 *
	 * @since 0.2.0
	 *
	 * @return string Description.
	 */
	protected static function provider_description(): string {
		if ( \function_exists( '__' ) ) {
			return __( 'GLM text generation via the z.ai Anthropic-compatible API.', 'zai' );
		}

		return 'GLM text generation via the z.ai Anthropic-compatible API.';
	}

	/**
	 * The currently selected region of THIS provider's settings (the zai
	 * provider's region selection is never consulted).
	 *
	 * @since 0.2.0
	 *
	 * @return string 'intl' or 'cn'.
	 */
	protected static function selected_region(): string {
		return ZaiAnthropicPlanRegionSettings::get_region();
	}

	/**
	 * Creates the provider metadata.
	 *
	 * @since 0.2.0
	 *
	 * @return ProviderMetadata Provider metadata.
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( ...self::provider_metadata_args() );
	}

	/**
	 * Creates the provider availability.
	 *
	 * @since 0.2.0
	 *
	 * @return ProviderAvailabilityInterface Provider availability.
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ZaiAnthropicProviderAvailability();
	}

	/**
	 * Creates the model metadata directory.
	 *
	 * @since 0.2.0
	 *
	 * @return ModelMetadataDirectoryInterface Model metadata directory.
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new ZaiAnthropicModelMetadataDirectory();
	}
}
