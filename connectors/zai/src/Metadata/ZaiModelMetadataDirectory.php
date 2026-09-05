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
 * negatively cached for ZaiDiscoveryCache::NEGATIVE_TTL (60) seconds only
 * (GLM1 #6), so a later valid key can still discover.
 *
 * The discovery cache is a WordPress transient scoped to the endpoint
 * identity (provider + plan + region, via ZaiEndpoint::cache_key()), so a
 * warm cache can never serve another endpoint's catalog after a settings
 * change. The SDK's own cache layer (in-memory plus any PSR-16 cache, 24h
 * TTL) is BYPASSED entirely — see hasCache()/setCache() — so the transient
 * is the single source of caching: discovery expires after
 * ZaiDiscoveryCache::DISCOVERY_TTL and invalidates together with the plugin
 * cache on settings/uninstall transient deletion, in every layer.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

/**
 * Model metadata directory for z.ai.
 *
 * Deleted in glm19-10: this class once carried four cache alias constants
 * (DISCOVERY_TTL, NEGATIVE_TTL, NEGATIVE_CACHE_SUFFIX, CACHE_PREFIX)
 * that no production code read — all cache composition goes through
 * ZaiDiscoveryCache and the endpoint classes (discovery_transient_
 * ids()), and only tests referenced the directory aliases. The
 * docblocked mirrors suggested the directory owned TTL/prefix
 * behavior it does not have; deleted rather than kept as drift
 * surface. The real owners: ZaiDiscoveryCache (TTLs, suffix),
 * PlanRegionSettings (prefix).
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */
final class ZaiModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.1.0
	 *
	 * @param HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		parent::setHttpTransporter( LoggingHttpTransporter::wrap( $http_transporter ) );
	}

	/**
	 * The endpoint captured at discovery REQUEST time (GLM3 #10).
	 *
	 * Set by sendListModelsRequest() immediately before the SDK's
	 * synchronous request/parse call and cleared after; read by
	 * createRequest() and parseResponseToModelMetadataList() so a
	 * concurrent plan/region save during the HTTP round-trip cannot
	 * retarget the URL or the plan filter out from under the response
	 * — matching the zai_anthropic twin, whose own HTTP flow passes the
	 * captured $endpoint->plan() explicitly.
	 *
	 * @since 0.2.0
	 *
	 * @var ZaiEndpoint|null
	 */
	private $discovery_endpoint;

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
		/*
		 * GLM3 #10: while a discovery request is in flight, the endpoint
		 * CAPTURED at request time is authoritative — the current-settings
		 * read stays for any defensive direct call outside the discovery
		 * flow.
		 */
		$endpoint = $this->discovery_endpoint ?? ZaiEndpoint::for_current_settings();

		return new Request(
			$method,
			$endpoint->api_url( $path ),
			$headers,
			$data
		);
	}

	/**
	 * Lists the models for the current endpoint: cached discovery, discovery,
	 * or the plan-specific static fallback.
	 *
	 * GLM4 #10: the cache orchestration (positive/negative transients,
	 * TTLs, plan fallback) lives once in the shared ZaiDiscoveryCache —
	 * the zai_anthropic surface's directory runs the identical flow
	 * through it, so a caching-rule change can never land on one surface
	 * only. This directory owns just its surface's discovery attempt
	 * (discover_model_ids_via_sdk()).
	 *
	 * GLM8 #9 memoized the map rebuild per transient CONTENT (the cache
	 * id plus a digest of the resolved IDs — with hasCache() hard-wired
	 * false, every list/has/get lookup re-ran the full map_from_ids()
	 * rebuild plus sort of constant data, twice or more per AI request).
	 * GLM9 #10 moved that memo into the shared cache
	 * (ZaiDiscoveryCache::memoized_map()), where the twin's GLM7 #13
	 * copy had lived beside it as a verbatim twin. The transient is
	 * still read on every call, so a settings change, a cross-process
	 * cache write, or a TTL expiry swaps the content digest and the
	 * next call rebuilds.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 * @throws ResponseException When the credential gate refuses enumeration
	 *                           (caught by the shared cache; never escapes
	 *                           this method).
	 */
	protected function sendListModelsRequest(): array {
		$endpoint = ZaiEndpoint::for_current_settings();
		$cache_id = ZaiEndpoint::discovery_cache_id( $endpoint->plan(), $endpoint->region() );

		$ids = ZaiDiscoveryCache::cached_ids(
			$cache_id,
			$endpoint->plan(),
			function () use ( $endpoint ): array {
				return $this->discover_model_ids_via_sdk( $endpoint );
			}
		);

		/*
		 * GLM9 #10: the per-content map memo lives once in the shared
		 * ZaiDiscoveryCache (memoized_map()) — this surface's GLM8 #9
		 * fields were a verbatim copy of the twin's GLM7 #13 pair, the
		 * drift pattern the shared cache class exists to stop.
		 *
		 * glm15-22: on a COLD discovery the vendor parent's parse has
		 * already built the full metadata list (the stash
		 * parseResponseToModelMetadataList() left behind) — it rides
		 * along as the memo's prebuilt map instead of being discarded
		 * for the IDs alone and rebuilt identically by map_from_ids().
		 */
		return ZaiDiscoveryCache::memoized_map( $cache_id, $ids, $this->take_discovery_built_map( $ids ) );
	}

	/**
	 * The metadata list the vendor parent's discovery parse built, or
	 * null — the cold-discovery hand-off to the map memo (glm15-22).
	 *
	 * The parent's sendListModelsRequest() REQUIRES a full
	 * parseResponseToModelMetadataList() build (metadata construction
	 * plus sort per discovered ID) whose result this directory reduced
	 * to array_keys(); ZaiDiscoveryCache::map_from_ids() then rebuilt
	 * the identical metadata for the same IDs — two full builds per
	 * cold-window discovery, at build sites that had already diverged
	 * (the cache's rebuild re-applies the chat filter, the parse-side
	 * build did not). The parse stashes its built list;
	 * take_discovery_built_map() converts a matching stash into the
	 * id-keyed, chat-filtered map the memo wants, and a non-matching
	 * stash (defensive: the list belongs to another request or
	 * response) is ignored in favor of the rebuild.
	 *
	 * @since 0.2.0
	 *
	 * @var list<ModelMetadata>|null
	 */
	private $discovery_built_metadata = null;

	/**
	 * Pops the parse-built metadata list as the memo's prebuilt map, or
	 * null when no usable stash exists (glm15-22).
	 *
	 * @param array $ids The IDs the cache resolved (list of string; the digest key).
	 * @return array<string, ModelMetadata>|null The id-keyed, chat-filtered map.
	 */
	private function take_discovery_built_map( array $ids ): ?array {
		$built                          = $this->discovery_built_metadata;
		$this->discovery_built_metadata = null;

		if ( null === $built ) {
			return null;
		}

		/*
		 * map_from_ids()' semantics exactly (the ONE build rule now):
		 * chat models only, keyed by ID. The sort is irrelevant to the
		 * map form; the stash's own build already sorted it.
		 */
		$map = array();
		foreach ( $built as $metadata ) {
			if ( ZaiModelCatalog::is_chat_model( $metadata->getId() ) ) {
				$map[ $metadata->getId() ] = $metadata;
			}
		}

		$map_keys = \array_keys( $map );
		$expected = \array_values( $ids );
		\sort( $map_keys );
		\sort( $expected );

		if ( $map_keys !== $expected ) {
			// The stash does not describe these IDs (a different request's
			// parse): fall back to the cache's own rebuild.
			return null;
		}

		/*
		 * The map keeps the stash's newest-first order (the parse's own
		 * usort) — exactly what map_from_ids()' uasort produces, which
		 * array_values() of the memo iterates downstream.
		 */
		return $map;
	}

	/**
	 * Makes this surface's discovery attempt through the SDK parent.
	 *
	 * Runs inside the shared cache's try: every failure shape — a
	 * refused credential (below), a non-2xx response, a malformed body,
	 * a transport error thrown by the parent — propagates to the shared
	 * catch, which marks the short negative cache and serves the plan
	 * fallback.
	 *
	 * @since 0.2.0
	 *
	 * @param ZaiEndpoint $endpoint The endpoint captured at request time.
	 * @return list<string> The discovered model IDs.
	 * @throws ResponseException When the credential gate refuses enumeration.
	 */
	private function discover_model_ids_via_sdk( ZaiEndpoint $endpoint ): array {
		/*
		 * Code review GLM1 #1 (sibling of the zai_anthropic surface's
		 * R20 gate): an env/constant credential that survives an
		 * intl/cn switch is region-pending (or carries a definitive
		 * invalid verdict) and must not be reused against the other
		 * region — the generation path refuses it (R19), but
		 * enumeration still authenticated with it here, disclosing the
		 * old-region key to the newly selected endpoint. The SAME
		 * availability gate is consulted (reused, not duplicated —
		 * GLM4 #9: the shared predicate; GLM5 #17: the shared
		 * refuse_discovery() wrapper the other credential consumers
		 * also use): while refused, the authenticated request never
		 * happens and discovery degrades to the static plan fallback
		 * via the shared cache's catch — never fatal, cached at most
		 * as the 60s negative marker (GLM1 #6), so a later definitive
		 * verdict can discover again.
		 */
		( new ZaiProviderAvailability() )->refuse_discovery(
			function () {
				return $this->getRequestAuthentication();
			}
		);

		/*
		 * GLM3 #10: capture the endpoint at REQUEST time. The SDK's
		 * synchronous call below builds the request (createRequest())
		 * and parses the response (parseResponseToModelMetadataList());
		 * a concurrent settings save during the HTTP round-trip must
		 * not make either judge by the NEW plan/region — the response
		 * is the OLD endpoint's, and the result is cached under the
		 * OLD endpoint's key by the shared cache.
		 */
		$this->discovery_endpoint = $endpoint;

		try {
			// Parent performs the HTTP request (against the resolved
			// endpoint), throws on non-2xx, and parses via
			// parseResponseToModelMetadataList().
			$discovered = parent::sendListModelsRequest();
		} finally {
			// GLM3 #10: the capture scopes to this one request/parse cycle.
			$this->discovery_endpoint = null;
		}

		return array_keys( $discovered );
	}

	/**
	 * Records the definitive invalid verdict a credential-rejecting
	 * discovery response represents, then defers the throw to the SDK
	 * parent (GLM9 #5).
	 *
	 * The zai_anthropic twin records a 401/403 on its models route
	 * through the availability layer's persist path (GLM7 #12) — the
	 * route ANSWERED and rejected the credential itself, the same
	 * definitive evidence the probe persists an invalid verdict for.
	 * This surface delegates its HTTP flow to the SDK parent's
	 * sendListModelsRequest(), so the overridable post-response hook is
	 * the one place the status is visible; without it the rejection
	 * landed in the shared cache's catch (Throwable) as a plain failure
	 * (the silent 60s '_miss' marker plus plan fallback), no verdict
	 * was persisted, and a key revoked server-side kept passing
	 * isConfigured() on zai — with raw 401s instead of the connector's
	 * typed refusal — for up to the 300s STATE_TTL.
	 *
	 * The recording follows the twin's shape exactly: the credential
	 * the rejecting request authenticated with (the wired instance;
	 * null resolves the effective key), the endpoint captured at
	 * REQUEST time (GLM10 #1), the probe's own persist path,
	 * never fatal — the parent's throw still happens, and the shared
	 * cache keeps discovery degrading to the plan fallback.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The discovery response to check.
	 * @return void
	 * @throws ResponseException As the SDK parent does, for non-2xx responses.
	 */
	protected function throwIfNotSuccessful( Response $response ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-mandated overridable hook name.
		$status = $response->getStatusCode();

		/*
		 * GLM10 #1: this hook runs INSIDE the GLM3 #10 capture
		 * window ($this->discovery_endpoint, set by
		 * discover_model_ids_via_sdk() before the SDK parent's
		 * request/parse cycle), so the rejection is recorded against
		 * the endpoint the rejecting request actually hit — not the
		 * endpoint the settings resolve to by response time. The
		 * current-settings fallback covers any defensive direct call
		 * outside the discovery flow.
		 *
		 * GLM10 #8: the status set and the recording ride the
		 * availability base's one helper (non-definitive statuses record
		 * nothing, the GLM7 #12 guard) — the hand-copied block this
		 * surface and the anthropic twin each carried is gone.
		 */
		$endpoint = $this->discovery_endpoint ?? ZaiEndpoint::for_current_settings();

		( new ZaiProviderAvailability() )->record_rejection_for_status(
			$status,
			function () {
				return $this->getRequestAuthentication();
			},
			$endpoint->cache_key()
		);

		parent::throwIfNotSuccessful( $response );
	}

	/**
	 * Parses an OpenAI-shape model-list response into metadata.
	 *
	 * GLM1 #11: the list parsing is SHARED with the zai_anthropic surface's
	 * directory via ZaiModelListParser — the two copies had drifted twice
	 * (the R15 has_more rejection and the R3 #4 plan intersection existed
	 * only on the Anthropic side, so this surface advertised general-only
	 * models on the coding plan and accepted an incomplete page as a
	 * catalog). Malformed shapes, incomplete pages, and lists with no
	 * usable in-plan chat IDs throw, which sendListModelsRequest() turns
	 * into the fallback.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The /models response.
	 * @return list<ModelMetadata> Sorted metadata list.
	 * @throws ResponseException When the response shape is malformed.
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-mandated abstract method name.

		/*
		 * GLM3 #10: the plan filter uses the endpoint CAPTURED at request
		 * time (sendListModelsRequest() sets it; the zai_anthropic twin
		 * passes its captured $endpoint->plan() explicitly through its own
		 * HTTP flow). Re-resolving the current settings HERE let a
		 * concurrent plan save during the HTTP round-trip filter the old
		 * endpoint's response with the NEW plan's catalog and cache the
		 * wrong list under the old endpoint's key for the 12h TTL. The
		 * current-settings fallback covers any defensive direct call
		 * outside the discovery flow.
		 */
		$endpoint = $this->discovery_endpoint ?? ZaiEndpoint::for_current_settings();

		$ids = ZaiModelListParser::parse_chat_ids( $response, $endpoint->plan(), ZaiProviderAvailability::REFUSAL_LABEL );

		$models = array();
		foreach ( $ids as $id ) {
			$models[] = ZaiModelCatalog::metadata_for( $id );
		}

		usort( $models, array( ZaiModelCatalog::class, 'sort_callback' ) );

		/*
		 * glm15-22: the vendor parent forces this full build; the
		 * directory's cold-discovery flow takes the stash as the map
		 * memo's prebuilt seed (same build, once) instead of reducing it
		 * to IDs for map_from_ids() to rebuild identically.
		 */
		$this->discovery_built_metadata = $models;

		return $models;
	}
}
