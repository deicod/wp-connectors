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

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
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
	public const PROVIDER_ID = 'zai';

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
	 * Creates the text generation model.
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata    $model_metadata    Model metadata.
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
				return new ZaiTextGenerationModel( $model_metadata, $provider_metadata );
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
	 * @since 0.1.0
	 *
	 * @return string Display name.
	 */
	protected static function provider_display_name(): string {
		return 'z.ai';
	}

	/**
	 * The provider's description text (shared base translates it).
	 *
	 * @since 0.1.0
	 *
	 * @return string Description.
	 */
	protected static function provider_description(): string {
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
	 * Creates the provider metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderMetadata Provider metadata.
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( ...self::provider_metadata_args() );
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
