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
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel;

/**
 * Provider definition for the z.ai OpenAI-compatible API.
 *
 * The base URL stays fixed to the canonical international general URL as
 * required by the SPEC (§3.3); the plan/region endpoint actually used per
 * request is resolved at request time in the model/directory layer.
 *
 * @since 0.1.0
 */
final class ZaiProvider extends AbstractApiProvider {

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
	 * @since 0.1.0
	 *
	 * @return string Base URL.
	 */
	protected static function baseUrl(): string {
		// Replaced by the endpoint resolver in Task 1.3; canonical value here.
		return 'https://api.z.ai/api/paas/v4';
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
	 * Creates the provider metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderMetadata Provider metadata.
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			self::PROVIDER_ID,
			'z.ai',
			ProviderTypeEnum::cloud(),
			'https://z.ai/manage/apikey/apikey',
			RequestAuthenticationMethod::apiKey()
		);
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
