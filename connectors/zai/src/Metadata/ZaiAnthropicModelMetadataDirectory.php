<?php
/**
 * Zai_anthropic model metadata directory: custom (non-OpenAI-compat)
 * directory with a plan-partitioned static GLM fallback and optional
 * cached /v1/models discovery.
 *
 * This is a CUSTOM directory (SPEC §2 layer table): the Anthropic surface's
 * model-list route and framing differ from the OpenAI-compat abstract's
 * assumptions, so the class implements ModelMetadataDirectoryInterface
 * directly. The NEUTRAL GLM catalog DATA (IDs, capability/option metadata,
 * newest-first sorting, chat-support evidence) is shared with the zai
 * provider's ZaiModelCatalog — data reuse, not adapter coupling: the two
 * protocol adapters never call each other.
 *
 * The fallback is plan-partitioned exactly like the zai provider's (SPEC
 * §3.3): coding subscriptions expose a restricted, coding-suitable model
 * set; the general pay-as-you-go API exposes the full catalog. The
 * Anthropic surface of O1 (does /v1/models answer with a valid key?) is
 * unprobed, so the static fallback is authoritative until evidence lands
 * (Task 2.4/2.7).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Deicod\WpConnectors\Zai\Authentication\ZaiAnthropicRequestAuthentication;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

/**
 * Model metadata directory for zai_anthropic.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicModelMetadataDirectory implements ModelMetadataDirectoryInterface, WithHttpTransporterInterface, WithRequestAuthenticationInterface {

	// The aliases keep the traits' originals reachable for the wrapping
	// overrides below (traits have no parent:: chain inside the using
	// class itself).
	use WithHttpTransporterTrait {
		setHttpTransporter as trait_set_transporter; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias.
	}
	use WithRequestAuthenticationTrait {
		getRequestAuthentication as trait_get_request_authentication; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias.
	}

	/**
	 * Transient prefix for discovery results; completed with md5(cache_key()).
	 *
	 * Distinct from the zai provider's prefix, so the two providers' caches
	 * can never collide.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'zai_connector_zai_anthropic_models_';

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.2.0
	 *
	 * @param HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		if ( ! $http_transporter instanceof LoggingHttpTransporter ) {
			$http_transporter = new LoggingHttpTransporter( $http_transporter );
		}

		$this->trait_set_transporter( $http_transporter );
	}

	/**
	 * Returns the wired authentication, protocol-wrapped for this surface.
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	public function getRequestAuthentication(): RequestAuthenticationInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		return ZaiAnthropicRequestAuthentication::wrap( $this->trait_get_request_authentication() );
	}

	/**
	 * Lists all available model metadata for the current endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return list<ModelMetadata> Array of model metadata.
	 */
	public function listModelMetadata(): array {
		return array_values( $this->models_map() );
	}

	/**
	 * Checks if metadata exists for a specific model.
	 *
	 * @since 0.2.0
	 *
	 * @param string $model_id Model identifier.
	 * @return bool True if metadata exists, false otherwise.
	 */
	public function hasModelMetadata( string $model_id ): bool {
		$models = $this->models_map();

		return isset( $models[ $model_id ] );
	}

	/**
	 * Gets metadata for a specific model.
	 *
	 * @since 0.2.0
	 *
	 * @param string $model_id Model identifier.
	 * @return ModelMetadata Model metadata.
	 * @throws InvalidArgumentException If model metadata not found.
	 */
	public function getModelMetadata( string $model_id ): ModelMetadata {
		$models = $this->models_map();

		if ( ! isset( $models[ $model_id ] ) ) {
			throw new InvalidArgumentException(
				'No model with ID ' . wp_json_encode( $model_id ) . ' was found in the provider'
			);
		}

		return $models[ $model_id ];
	}

	/**
	 * Returns the model map for the CURRENT endpoint: the plan-specific
	 * static fallback.
	 *
	 * The plan/region options are read at call time, so a settings change
	 * swaps the catalog on the very next lookup.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	private function models_map(): array {
		$endpoint = ZaiAnthropicEndpoint::for_current_settings();

		return $this->map_from_ids( ZaiModelCatalog::ids_for_plan( $endpoint->plan() ) );
	}

	/**
	 * Builds the sorted metadata map for a list of model IDs.
	 *
	 * IDs without known chat support are dropped: advertising them would
	 * route them to /v1/messages where they cannot work (chat-support
	 * evidence lives in the shared ZaiModelCatalog).
	 *
	 * @since 0.2.0
	 *
	 * @param array $ids Model IDs (fallback or discovered).
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	private function map_from_ids( array $ids ): array {
		$models = array();
		foreach ( $ids as $id ) {
			if ( \is_string( $id ) && '' !== $id && ZaiModelCatalog::is_chat_model( $id ) ) {
				$models[ $id ] = ZaiModelCatalog::metadata_for( $id );
			}
		}

		uasort( $models, array( ZaiModelCatalog::class, 'sort_callback' ) );

		return $models;
	}
}
