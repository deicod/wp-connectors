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

use WordPress\AiClient\AiClient;
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
	 * Key-management portal for the international region (z.ai).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INTL_CREDENTIALS_URL = 'https://z.ai/manage/apikey/apikey';

	/**
	 * Key-management portal for the China region (open.bigmodel.cn).
	 *
	 * Regions use separate accounts and separate API keys (SPEC §3.3), so
	 * the advertised link must follow the selected region — a China admin
	 * sent to the z.ai portal lands on an account their key can never
	 * live in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CN_CREDENTIALS_URL = 'https://open.bigmodel.cn/usercenter/apikeys';

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
	 * Builds the provider metadata constructor arguments for an SDK version.
	 *
	 * Description requires SDK >= 1.2.0 and the logo path SDK >= 1.3.0; both
	 * are appended only when the given SDK version supports them (the guard
	 * pattern from the official provider plugin, architecture record 0003).
	 * The parameter defaults to the detected SDK version and exists so tests
	 * can cover the minimum and newer metadata shapes.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $sdk_version SDK version to build for, or null for AiClient::VERSION.
	 * @return list<mixed> Positional ProviderMetadata constructor arguments.
	 */
	public static function provider_metadata_args( ?string $sdk_version = null ): array {
		$version = $sdk_version ?? AiClient::VERSION;

		$args = array(
			self::PROVIDER_ID,
			'z.ai',
			ProviderTypeEnum::cloud(),
			self::credentials_url(),
			RequestAuthenticationMethod::apiKey(),
		);

		if ( version_compare( $version, '1.2.0', '>=' ) ) {
			$args[] = self::translated_description();
		}

		if ( version_compare( $version, '1.3.0', '>=' ) ) {
			$args[] = dirname( __DIR__, 2 ) . '/assets/zai.svg';
		}

		return $args;
	}

	/**
	 * The key-management URL of the currently selected region's portal.
	 *
	 * The region option is read at metadata-build time (provider
	 * registration, once per request): like the rest of the provider
	 * metadata, a region change is reflected from the next request on.
	 *
	 * @since 0.1.0
	 *
	 * @return string Credentials portal URL for the current region.
	 */
	private static function credentials_url(): string {
		return 'cn' === PlanRegionSettings::get_region()
			? self::CN_CREDENTIALS_URL
			: self::INTL_CREDENTIALS_URL;
	}

	/**
	 * Returns the translated provider description.
	 *
	 * @since 0.1.0
	 *
	 * @return string Description text.
	 */
	private static function translated_description(): string {
		if ( \function_exists( '__' ) ) {
			return __( 'GLM text generation via the z.ai OpenAI-compatible API.', 'zai' );
		}

		return 'GLM text generation via the z.ai OpenAI-compatible API.';
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
