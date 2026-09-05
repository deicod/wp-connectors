<?php
/**
 * Z.ai provider (OpenAI-compatible surface).
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Provider;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

/**
 * Provider definition for the z.ai OpenAI-compatible API.
 *
 * The base URL stays fixed to the canonical international general URL as
 * required by the SPEC (§3.3); the plan/region endpoint actually used per
 * request is resolved at request time in the model/directory layer.
 *
 * @since 0.1.0
 */
final class ZaiProvider extends AbstractZaiProvider {

	/**
	 * Connector ID used by core (connectors_ai_zai_api_key option name).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	// glm15-23: the surface identity slug has ONE owner per surface — the
	// SDK-free settings layer's CACHE_SCOPE, loadable on every site (Codex
	// R2 #3); this SDK-dependent registration constant and the availability
	// layer's REFUSAL_LABEL alias it, so a rename changes the three together.
	public const PROVIDER_ID = PlanRegionSettings::CACHE_SCOPE;

	/**
	 * The canonical base URL: international region, general plan.
	 *
	 * Fixed by contract (SPEC §3.3): the plan/region endpoint used for actual
	 * requests is resolved at request time via ZaiEndpoint (Task 1.3), so this
	 * value never changes with the settings.
	 *
	 * @since 0.1.0
	 *
	 * @return string Base URL.
	 */
	protected static function baseUrl(): string {
		return ZaiEndpoint::CANONICAL_BASE_URL;
	}

	/**
	 * The model class this provider instantiates for supported metadata
	 * (GLM8 #12: the shared capability walk lives on the base).
	 *
	 * @since 0.2.0
	 *
	 * @return class-string The concrete model class.
	 */
	protected static function model_class(): string {
		return ZaiTextGenerationModel::class;
	}

	/**
	 * The provider's card display name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Display name.
	 */
	protected static function provider_display_name(): string {
		return 'z.ai';
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
	 * @since 0.1.0
	 *
	 * @return string Description.
	 */
	protected static function provider_description(): string {
		if ( \function_exists( '__' ) ) {
			return __( 'GLM text generation via the z.ai OpenAI-compatible API.', 'zai' );
		}

		return 'GLM text generation via the z.ai OpenAI-compatible API.';
	}

	/**
	 * The currently selected region of the zai provider's settings.
	 *
	 * @since 0.1.0
	 *
	 * @return string 'intl' or 'cn'.
	 */
	protected static function selected_region(): string {
		return PlanRegionSettings::get_region();
	}

	/**
	 * Creates the provider availability.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderAvailabilityInterface Provider availability.
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ZaiProviderAvailability();
	}

	/**
	 * Creates the model metadata directory.
	 *
	 * @since 0.1.0
	 *
	 * @return ModelMetadataDirectoryInterface Model metadata directory.
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new ZaiModelMetadataDirectory();
	}
}
