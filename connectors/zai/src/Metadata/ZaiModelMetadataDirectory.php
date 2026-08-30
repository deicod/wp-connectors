<?php
/**
 * Z.ai model metadata directory: dynamic /models discovery with a
 * plan-partitioned static fallback.
 *
 * O1 (record 0006, resolved for intl): GET {base}/models returns 200 with the
 * OpenAI list shape on both international bases, so discovery is the primary
 * path. The cn region is unprobed — the tested static fallback stays
 * authoritative there and on every discovery failure (401/404/malformed/
 * transport), which never poisons the cache: a later valid key can still
 * discover.
 *
 * The discovery cache is a WordPress transient scoped to the endpoint
 * identity (provider + plan + region, via ZaiEndpoint::cache_key()), so a
 * warm cache can never serve another endpoint's catalog after a settings
 * change.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use Throwable;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

/**
 * Model metadata directory for z.ai.
 *
 * @since 0.1.0
 */
final class ZaiModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * Seconds a successful discovery response stays cached per endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DISCOVERY_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Transient prefix for discovery results; completed with md5(cache_key()).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'zai_connector_zai_models_';

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.1.0
	 *
	 * @param HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		if ( ! $http_transporter instanceof LoggingHttpTransporter ) {
			$http_transporter = new LoggingHttpTransporter( $http_transporter );
		}

		parent::setHttpTransporter( $http_transporter );
	}

	/**
	 * Builds the request against the CURRENT plan/region endpoint.
	 *
	 * The option read happens here, at request-build time — never at
	 * construction time — so a settings change retargets the very next
	 * request (Task 1.3).
	 *
	 * @since 0.1.0
	 *
	 * @param HttpMethodEnum                     $method  HTTP method.
	 * @param string                             $path    API path relative to the base URL.
	 * @param array<string, string|list<string>> $headers Request headers.
	 * @param string|array<string, mixed>|null   $data    Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			ZaiEndpoint::for_current_settings()->api_url( $path ),
			$headers,
			$data
		);
	}

	/**
	 * Lists the models for the current endpoint: cached discovery, discovery,
	 * or the plan-specific static fallback.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	protected function sendListModelsRequest(): array {
		$endpoint = ZaiEndpoint::for_current_settings();
		$cache_id = self::CACHE_PREFIX . md5( $endpoint->cache_key() );

		$cached_ids = get_transient( $cache_id );
		if ( \is_array( $cached_ids ) ) {
			return $this->map_from_ids( $cached_ids );
		}

		try {
			// Parent performs the HTTP request (against the resolved endpoint),
			// throws on non-2xx, and parses via parse_response_to_model_metadata_list().
			$discovered = parent::sendListModelsRequest();
		} catch ( Throwable $e ) {
			// Discovery failure is never fatal and never cached: the
			// plan-partitioned static fallback keeps the provider usable.
			return $this->map_from_ids( ZaiModelCatalog::ids_for_plan( $endpoint->plan() ) );
		}

		$ids = array_keys( $discovered );
		set_transient( $cache_id, $ids, self::DISCOVERY_TTL );

		return $this->map_from_ids( $ids );
	}

	/**
	 * Parses an OpenAI-shape model-list response into metadata.
	 *
	 * Any malformed shape (missing/non-list `data`, entries without string
	 * IDs) throws, which sendListModelsRequest() turns into the fallback.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The /models response.
	 * @return list<ModelMetadata> Sorted metadata list.
	 * @throws ResponseException When the response shape is malformed.
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-mandated abstract method name.
		$data = $response->getData();

		if ( ! \is_array( $data ) || ! isset( $data['data'] ) || ! \is_array( $data['data'] ) ) {
			throw ResponseException::fromMissingData( 'z.ai', 'data' );
		}

		$ids = array();
		foreach ( $data['data'] as $entry ) {
			if ( ! \is_array( $entry ) || ! isset( $entry['id'] ) || ! \is_string( $entry['id'] ) || '' === $entry['id'] ) {
				throw ResponseException::fromInvalidData( 'z.ai', 'data', 'Every entry must carry a non-empty string "id".' );
			}

			$ids[] = $entry['id'];
		}

		if ( array() === $ids ) {
			throw ResponseException::fromMissingData( 'z.ai', 'data' );
		}

		$models = array();
		foreach ( $ids as $id ) {
			$models[] = ZaiModelCatalog::metadata_for( $id );
		}

		usort( $models, array( ZaiModelCatalog::class, 'sort_callback' ) );

		return $models;
	}

	/**
	 * Builds the sorted metadata map for a list of model IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param array $ids   Model IDs (discovered, cached, or fallback).
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	private function map_from_ids( array $ids ): array {
		$models = array();
		foreach ( $ids as $id ) {
			if ( ! \is_string( $id ) || '' === $id ) {
				continue;
			}

			$models[ $id ] = ZaiModelCatalog::metadata_for( $id );
		}

		uasort( $models, array( ZaiModelCatalog::class, 'sort_callback' ) );

		return $models;
	}
}
