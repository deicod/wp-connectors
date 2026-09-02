<?php
/**
 * Z.ai model metadata directory: dynamic /models discovery with a
 * plan-partitioned static fallback.
 *
 * O1 (record 0006, resolved for intl): GET {base}/models returns 200 with the
 * OpenAI list shape on both international bases, so discovery is the primary
 * path. The cn region is unprobed — the tested static fallback stays
 * authoritative there and on every discovery failure (401/404/malformed/
 * transport), which never poisons the POSITIVE cache: failures are
 * negatively cached for NEGATIVE_TTL (60) seconds only (GLM1 #6), so a
 * later valid key can still discover.
 *
 * The discovery cache is a WordPress transient scoped to the endpoint
 * identity (provider + plan + region, via ZaiEndpoint::cache_key()), so a
 * warm cache can never serve another endpoint's catalog after a settings
 * change. The SDK's own cache layer (in-memory plus any PSR-16 cache, 24h
 * TTL) is BYPASSED entirely — see hasCache()/setCache() — so the transient
 * is the single source of caching: discovery expires after DISCOVERY_TTL and
 * invalidates together with the plugin cache on settings/uninstall
 * transient deletion, in every layer.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
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
	 * Seconds a FAILED discovery suppresses repeat remote attempts
	 * (code-review GLM1 #6).
	 *
	 * Failure is still never fatal — the plan fallback serves meanwhile —
	 * and still retryable: after this short TTL the endpoint is probed
	 * again, so a later valid key (or a recovered route) rediscovers
	 * within a minute. Without it, every metadata lookup re-issued a
	 * blocking doomed remote GET (the cn-region 404 shape on every
	 * request). The marker lives at the endpoint-scoped key with the
	 * NEGATIVE_CACHE_SUFFIX appended and is cleared by the same
	 * invalidation paths as the positive cache.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const NEGATIVE_TTL = 60;

	/**
	 * Suffix marking the negative (miss) cache entry for an endpoint key.
	 *
	 * Mirrored literally by the SDK-free settings invalidation and
	 * uninstall.php (neither can autoload this class); tests pin the
	 * mirror.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NEGATIVE_CACHE_SUFFIX = '_miss';

	/**
	 * Transient prefix for discovery results; completed with md5(cache_key()).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = PlanRegionSettings::CACHE_PREFIX;

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
	 * Scopes the SDK-level cache key to the CURRENT endpoint.
	 *
	 * The SDK wraps sendListModelsRequest() in its own cache
	 * (WithDataCachingTrait, 24h TTL, per-class key by default — including a
	 * persistent PSR-16 cache when one is configured via AiClient::setCache()).
	 * That layer is bypassed wholesale here (see hasCache()/setCache()), but
	 * the key stays endpoint-scoped so entries written by any other path
	 * (a foreign directory instance, invalidateCaches() clears) can never
	 * cross endpoints.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function getBaseCacheKey(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method override.
		return 'ai_client_' . AiClient::VERSION . '_' . md5( self::class . '|' . ZaiEndpoint::for_current_settings()->cache_key() );
	}

	/**
	 * Never serves from the SDK cache layer (in-memory local or PSR-16).
	 *
	 * The plugin transient is the ONLY discovery cache: outer layers retain
	 * values for 24h, which would defeat the advertised 12h TTL and survive
	 * the transient deletion on settings changes/uninstall. Reporting
	 * "never cached" forces every lookup through sendListModelsRequest(),
	 * which applies the plugin's own TTL and invalidation rules.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Cache key suffix.
	 * @return bool Always false.
	 */
	protected function hasCache( string $key ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method override.
		return false;
	}

	/**
	 * Never persists anything in the SDK cache layer (in-memory or PSR-16).
	 *
	 * Successful discoveries are persisted as the plugin transient inside
	 * sendListModelsRequest() with DISCOVERY_TTL; fallbacks are cached at
	 * most as the 60s negative marker (never here — see GLM1 #6). Storing
	 * here as well would leave warmed entries behind after transient
	 * invalidation.
	 *
	 * @since 0.1.0
	 *
	 * @param string                 $key   Cache key suffix.
	 * @param mixed                  $value Value to cache (ignored).
	 * @param int|\DateInterval|null $ttl   TTL (ignored).
	 * @return bool Always true (pretend success; store nothing).
	 */
	protected function setCache( string $key, $value, $ttl = null ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method override.
		return true; // Pretend success; store nothing.
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
	 * @throws ResponseException When the credential gate refuses enumeration
	 *                           (caught below; never escapes this method).
	 */
	protected function sendListModelsRequest(): array {
		$endpoint = ZaiEndpoint::for_current_settings();
		$cache_id = self::CACHE_PREFIX . md5( $endpoint->cache_key() );

		$cached_ids = get_transient( $cache_id );
		if ( \is_array( $cached_ids ) ) {
			return $this->map_from_ids( $cached_ids );
		}

		/*
		 * GLM1 #6: a recent discovery failure serves the fallback WITHOUT
		 * another doomed remote attempt (a 60s miss marker — see
		 * NEGATIVE_TTL; retryability is preserved after expiry).
		 */
		if ( get_transient( $cache_id . self::NEGATIVE_CACHE_SUFFIX ) ) {
			return $this->map_from_ids( ZaiModelCatalog::ids_for_plan( $endpoint->plan() ) );
		}

		try {
			/*
			 * Code review GLM1 #1 (sibling of the zai_anthropic surface's
			 * R20 gate): an env/constant credential that survives an
			 * intl/cn switch is region-pending (or carries a definitive
			 * invalid verdict) and must not be reused against the other
			 * region — the generation path refuses it (R19), but
			 * enumeration still authenticated with it here, disclosing the
			 * old-region key to the newly selected endpoint. The SAME
			 * availability gate is consulted (reused, not duplicated):
			 * while refused, the authenticated request never happens and
			 * discovery degrades to the static plan fallback via the catch
			 * below — never fatal, cached at most as the 60s negative
			 * marker (GLM1 #6), so a later definitive verdict can discover
			 * again.
			 */
			$authentication = $this->getRequestAuthentication();
			if ( $authentication instanceof ApiKeyRequestAuthentication
				&& null !== ( new ZaiProviderAvailability() )->generation_refusal_reason( $authentication ) ) {
				throw ResponseException::fromInvalidData( 'z.ai', 'data', 'Discovery skipped: the credential is pending revalidation or was rejected for this endpoint.' );
			}

			// Parent performs the HTTP request (against the resolved endpoint),
			// throws on non-2xx, and parses via parseResponseToModelMetadataList().
			$discovered = parent::sendListModelsRequest();
		} catch ( Throwable $e ) {
			/*
			 * Discovery failure is never fatal (in any layer — see
			 * setCache()/hasCache()): the plan-partitioned static fallback
			 * keeps the provider usable. It is cached only as the short
			 * negative marker (GLM1 #6) so a later valid key can still
			 * discover — after at most NEGATIVE_TTL seconds.
			 */
			set_transient( $cache_id . self::NEGATIVE_CACHE_SUFFIX, true, self::NEGATIVE_TTL );

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
	 * IDs without known chat support (e.g. a future embedding/image model
	 * on the general endpoint) are dropped BEFORE metadata is assigned —
	 * advertising them with full chat capabilities would only route them to
	 * /chat/completions where they cannot work; a list with no usable chat
	 * IDs left also throws, yielding the plan fallback.
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

		/*
		 * R14 verifier twin (of Codex R14 #4, fixed on the anthropic
		 * surface in the same round): the catalog must be a JSON LIST. The
		 * associative decode collapses an object-shaped
		 * {"data":{"only":{"id":...}}} into a PHP array that passes
		 * is_array(), and the foreach then iterates the object's VALUES as
		 * entries — a malformed catalog was treated as successful live
		 * discovery and cached. Object-ness oracle (R3 #1 pattern): only a
		 * JSON array decodes to a PHP list; an object decodes to stdClass.
		 */
		$raw_body = json_decode( (string) $response->getBody() );
		if ( ! \is_object( $raw_body ) || ! isset( $raw_body->data ) || ! \is_array( $raw_body->data ) ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'data', 'The discovered model list must be a JSON list.' );
		}

		$ids = array();
		foreach ( $data['data'] as $entry ) {
			if ( ! \is_array( $entry ) || ! isset( $entry['id'] ) || ! \is_string( $entry['id'] ) || '' === $entry['id'] ) {
				throw ResponseException::fromInvalidData( 'z.ai', 'data', 'Every entry must carry a non-empty string "id".' );
			}

			$ids[] = $entry['id'];
		}

		$ids = $this->filter_chat_ids( $ids );

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
	 * IDs without known chat support are dropped, so a transient warmed by
	 * an older version can never resurface a non-chat model either.
	 *
	 * @since 0.1.0
	 *
	 * @param array $ids   Model IDs (discovered, cached, or fallback).
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	private function map_from_ids( array $ids ): array {
		$models = array();
		foreach ( $this->filter_chat_ids( $ids ) as $id ) {
			$models[ $id ] = ZaiModelCatalog::metadata_for( $id );
		}

		uasort( $models, array( ZaiModelCatalog::class, 'sort_callback' ) );

		return $models;
	}

	/**
	 * Keeps only IDs with known chat support.
	 *
	 * @since 0.1.0
	 *
	 * @param array $ids Model IDs (any source; non-strings dropped).
	 * @return list<string> Chat-capable model IDs.
	 */
	private function filter_chat_ids( array $ids ): array {
		$chat_ids = array();
		foreach ( $ids as $id ) {
			if ( \is_string( $id ) && '' !== $id && ZaiModelCatalog::is_chat_model( $id ) ) {
				$chat_ids[] = $id;
			}
		}

		return $chat_ids;
	}
}
