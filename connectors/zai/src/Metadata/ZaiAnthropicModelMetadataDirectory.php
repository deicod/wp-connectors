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
 * set; the general pay-as-you-go API exposes the full catalog.
 *
 * O1 (Anthropic surface): GET {base}/v1/models exists (existence
 * probe-verified 401-with-dummy-token, SPEC §3.1) but its behavior with a
 * VALID key is UNPROBED, so the tested static fallback is authoritative;
 * discovery is attempted opportunistically (a 2xx with a list of chat IDs
 * wins and is cached), and every failure shape — 401/404/5xx, malformed
 * body, no usable chat IDs, transport — falls back without poisoning the
 * POSITIVE cache: failures are negatively cached for NEGATIVE_TTL (60)
 * seconds only (GLM1 #6), so a later valid key can still discover. The
 * Task 2.7 live probe records the credentialed outcome.
 *
 * The discovery cache is a WordPress transient scoped to the endpoint
 * identity (provider + plan + region, via ZaiAnthropicEndpoint::cache_key()),
 * so a warm cache can never serve another endpoint's catalog after a
 * settings change. Successful discovery is additionally intersected with
 * the ACTIVE plan's catalog before caching (Codex R3 #4): the coding plan
 * advertises only its restricted model set even though the live route
 * returns the full list. There is no other cache layer: this class
 * implements the SDK interface directly and keeps no in-memory state.
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
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Deicod\WpConnectors\Zai\Authentication\ZaiAnthropicRequestAuthentication;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;
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
	public const CACHE_PREFIX = ZaiAnthropicPlanRegionSettings::CACHE_PREFIX;

	/**
	 * Seconds a successful discovery response stays cached per endpoint.
	 *
	 * GLM4 #10: single-sourced from the shared ZaiDiscoveryCache (both
	 * directories alias the same values, so their TTLs can never drift).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const DISCOVERY_TTL = ZaiDiscoveryCache::DISCOVERY_TTL;

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
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const NEGATIVE_TTL = ZaiDiscoveryCache::NEGATIVE_TTL;

	/**
	 * Suffix marking the negative (miss) cache entry for an endpoint key.
	 *
	 * Mirrored literally by the SDK-free settings invalidation and
	 * uninstall.php (neither can autoload this class); tests pin the
	 * mirror.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NEGATIVE_CACHE_SUFFIX = ZaiDiscoveryCache::NEGATIVE_CACHE_SUFFIX;

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
	 * Returns the model map for the CURRENT endpoint: cached discovery,
	 * discovery, or the plan-specific static fallback.
	 *
	 * The plan/region options are read at call time, so a settings change
	 * swaps the cache identity and catalog on the very next lookup — a warm
	 * cache can never serve another endpoint's models.
	 *
	 * GLM4 #10: the cache orchestration (positive/negative transients,
	 * TTLs, plan fallback) lives once in the shared ZaiDiscoveryCache —
	 * the zai surface's directory runs the identical flow through it, so
	 * a caching-rule change can never land on one surface only.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	private function models_map(): array {
		$endpoint = ZaiAnthropicEndpoint::for_current_settings();

		$ids = ZaiDiscoveryCache::cached_ids(
			self::CACHE_PREFIX . md5( $endpoint->cache_key() ),
			$endpoint->plan(),
			function () use ( $endpoint ): array {
				return $this->discover_model_ids( $endpoint );
			}
		);

		return ZaiDiscoveryCache::map_from_ids( $ids );
	}

	/**
	 * Discovers chat-capable model IDs from the endpoint's /v1/models route.
	 *
	 * Both common list shapes carry data[].id entries, so the parser accepts
	 * the Anthropic shape (data + display_name/created_at) and the OpenAI
	 * shape (data + object/created/owned_by) alike. Any malformed shape, a
	 * non-2xx status, or a list with no usable chat IDs throws, which
	 * models_map() turns into the plan fallback.
	 *
	 * @since 0.2.0
	 *
	 * @param ZaiAnthropicEndpoint $endpoint The endpoint to discover from.
	 * @return list<string> Chat-capable model IDs, unsorted.
	 * @throws ResponseException On any non-usable discovery response.
	 */
	private function discover_model_ids( ZaiAnthropicEndpoint $endpoint ): array {
		/*
		 * R20 (inline 3907008518): an env/constant credential that survives
		 * an intl/cn switch is region-pending (or carries a definitive
		 * invalid verdict) and must not be reused against the other region —
		 * the generation path already refuses it (R19), but enumeration
		 * still authenticated with it here, disclosing the old-region key to
		 * the newly selected endpoint. The SAME availability gate is
		 * consulted (reused, not duplicated — GLM4 #9: through the shared
		 * generation_refusal_for_wired_authentication() predicate the
		 * models and the zai directory also use): while refused, the
		 * authenticated request never happens and discovery degrades to the
		 * static plan fallback via models_map()'s catch — never fatal,
		 * cached at most as the 60s negative marker (GLM1 #6), so a later
		 * definitive verdict can discover again.
		 */
		if ( null !== ( new ZaiAnthropicProviderAvailability() )->generation_refusal_for_wired_authentication( $this->getRequestAuthentication() ) ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'data', 'Discovery skipped: the credential is pending revalidation or was rejected for this endpoint.' );
		}

		$request = new Request( HttpMethodEnum::GET(), $endpoint->models_url() );
		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		$response = $this->getHttpTransporter()->send( $request );

		if ( ! $response->isSuccessful() ) {
			throw ResponseException::fromMissingData( 'z.ai', 'data' );
		}

		/*
		 * GLM1 #11: the list parsing (shape checks, has_more rejection,
		 * chat filter, plan intersection) is SHARED with the zai surface's
		 * directory via ZaiModelListParser — the two copies had already
		 * drifted twice (the has_more rejection and the plan intersection
		 * existed only here).
		 */
		return ZaiModelListParser::parse_chat_ids( $response, $endpoint->plan() );
	}
}
