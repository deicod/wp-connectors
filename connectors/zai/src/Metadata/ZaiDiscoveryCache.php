<?php
/**
 * Shared discovery-cache orchestration for both z.ai directories
 * (code-review GLM4 #10).
 *
 * The discovery flow — cache-id build, positive transient read, negative
 * (miss) marker read, try/discover/catch-to-marker-plus-plan-fallback,
 * positive set, and the chat-filtered metadata map — was duplicated
 * line-for-line between ZaiModelMetadataDirectory::sendListModelsRequest()
 * and ZaiAnthropicModelMetadataDirectory::models_map(), so every
 * discovery-caching change in this PR alone (the GLM1 #6 negative cache,
 * the GLM3 #10 endpoint capture) had to land twice, and each surface was
 * only tested against its own copy. One orchestration serves both
 * directories now; the directories own only what genuinely differs —
 * HOW a discovery request is made and parsed on their surface.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use Throwable;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Cached model-ID discovery with the plan-partitioned static fallback.
 *
 * @since 0.2.0
 */
final class ZaiDiscoveryCache {

	/**
	 * Seconds a successful discovery response stays cached per endpoint.
	 *
	 * The directory classes alias this as their public constant (tests
	 * and external mirrors pin those), so the TTL has one source.
	 *
	 * @since 0.2.0
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
	 * request).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const NEGATIVE_TTL = 60;

	/**
	 * Suffix marking the negative (miss) cache entry for an endpoint key.
	 *
	 * GLM8 #11: every consumer that needs the marker name — the
	 * settings invalidation, uninstall.php, and the live probe, via the
	 * endpoint layer's discovery_transient_ids() — reads THIS constant;
	 * no mirror composes '_miss' literally anymore. The class stays
	 * SDK-free loadable (no SDK parent, lazy imports only) so those
	 * callers can use it without the SDK plugin.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NEGATIVE_CACHE_SUFFIX = '_miss';

	/**
	 * The per-cache-id memoized metadata maps (GLM9 #10).
	 *
	 * One entry per endpoint cache id — the LAST content seen there,
	 * keyed by a digest of its resolved IDs — so the map (metadata
	 * construction plus the newest-first sort, a pure function of the
	 * ID list) is rebuilt only when the transient CONTENT changes. The
	 * bound is the number of distinct cache ids (two surfaces × plans ×
	 * regions); the per-instance single-entry memo this replaces had
	 * the same per-endpoint semantics but thrashed whenever both
	 * directories were consulted alternately.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{digest: string, map: array<string, ModelMetadata>}>
	 */
	private static $memoized_maps = array();

	/**
	 * Resolves the model IDs for one endpoint: cached discovery,
	 * discovery, or the plan-specific static fallback.
	 *
	 * The cache is a WordPress transient scoped by the caller's cache id
	 * (endpoint identity — provider + plan + region), so a warm cache can
	 * never serve another endpoint's catalog after a settings change.
	 * A discovery attempt (the $discover callback, which throws on any
	 * failure shape — refused credential, non-2xx, malformed body,
	 * transport) is negatively cached for NEGATIVE_TTL seconds only, so
	 * a later valid key can still discover; the plan-partitioned static
	 * fallback keeps the provider usable meanwhile.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $cache_id Endpoint-scoped transient key.
	 * @param string   $plan     Plan for the static fallback ('coding' or 'general').
	 * @param callable $discover Makes the discovery request; returns the
	 *                           discovered model IDs (list of string) or
	 *                           throws.
	 * @return array The resolved model IDs (cached, discovered, or fallback).
	 */
	public static function cached_ids( string $cache_id, string $plan, callable $discover ): array {
		$cached_ids = get_transient( $cache_id );
		if ( \is_array( $cached_ids ) ) {
			return $cached_ids;
		}

		/*
		 * GLM1 #6: a recent discovery failure serves the fallback WITHOUT
		 * another doomed remote attempt (a 60s miss marker — see
		 * NEGATIVE_TTL; retryability is preserved after expiry).
		 */
		if ( get_transient( $cache_id . self::NEGATIVE_CACHE_SUFFIX ) ) {
			return ZaiModelCatalog::ids_for_plan( $plan );
		}

		try {
			$ids = $discover();
		} catch ( Throwable $e ) {
			/*
			 * Discovery failure is never fatal: the plan-partitioned static
			 * fallback keeps the provider usable. It is cached only as the
			 * short negative marker (GLM1 #6) so a later valid key can still
			 * discover — after at most NEGATIVE_TTL seconds.
			 */
			set_transient( $cache_id . self::NEGATIVE_CACHE_SUFFIX, true, self::NEGATIVE_TTL );

			return ZaiModelCatalog::ids_for_plan( $plan );
		}

		set_transient( $cache_id, $ids, self::DISCOVERY_TTL );

		return $ids;
	}

	/**
	 * Returns the metadata map for one endpoint's resolved IDs, memoized
	 * per transient CONTENT (GLM9 #10).
	 *
	 * The map is a pure function of the ID list, but it was rebuilt (per-ID
	 * metadata construction plus the newest-first sort) on every
	 * listModelMetadata()/hasModelMetadata()/getModelMetadata() call —
	 * core resolution makes two or more per AI request. The memo the two
	 * directories each carried as private fields (GLM7 #13 on
	 * zai_anthropic, GLM8 #9 on zai — copy-pasted, the exact twin
	 * pattern this class exists to stop) lives here once now: a
	 * memo-rule change can never land on one surface only. The transient
	 * read stays per call at the directories (cache invalidation and TTL
	 * expiry remain authoritative); only the rebuild is skipped while
	 * the content is unchanged.
	 *
	 * glm15-22: an optional PREBUILT map may ride along — the zai
	 * surface's vendor parent forces a full metadata build inside its
	 * discovery parse (parseResponseToModelMetadataList()), which the
	 * directory used to discard for the IDs alone while this memo
	 * rebuilt the identical metadata from scratch. The prebuilt map must
	 * already carry map_from_ids()' semantics (the chat filter, the
	 * id-keyed map shape); callers without one (the anthropic surface,
	 * warm-cache reads) keep the rebuild.
	 *
	 * @since 0.2.0
	 *
	 * @param string                            $cache_id Endpoint-scoped transient key.
	 * @param array                             $ids      The resolved model IDs (list of string:
	 *                                                    fallback, cached, or discovered).
	 * @param array<string, ModelMetadata>|null $prebuilt An id-keyed map built from exactly
	 *                                                these IDs during discovery, or null.
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	public static function memoized_map( string $cache_id, array $ids, ?array $prebuilt = null ): array {
		/*
		 * glm18-9: the digest rides the same string-only view of the list
		 * map_from_ids() keeps — an md5() over the RAW list raised an
		 * Array-to-string warning (an ErrorException out of this
		 * documented never-throw path on hosts whose error handler
		 * throws) on every directory lookup for a transient row carrying
		 * a non-string entry (a foreign or corrupt write; no in-repo
		 * writer produces one) — for the transient's whole 12h TTL.
		 * Dropped entries cannot change the built map (map_from_ids()
		 * drops them from it too), so a digest over the string-only list
		 * is still a faithful memo key.
		 */
		$string_ids = array();
		foreach ( $ids as $id ) {
			if ( \is_string( $id ) && '' !== $id ) {
				$string_ids[] = $id;
			}
		}

		$digest = md5( implode( "\n", $string_ids ) );

		$memo = self::$memoized_maps[ $cache_id ] ?? null;

		if ( null === $memo || $memo['digest'] !== $digest ) {
			$memo = array(
				'digest' => $digest,
				'map'    => null !== $prebuilt ? $prebuilt : self::map_from_ids( $ids ),
			);

			self::$memoized_maps[ $cache_id ] = $memo;
		}

		return $memo['map'];
	}

	/**
	 * Builds the sorted metadata map for a list of model IDs.
	 *
	 * IDs without known chat support are dropped, so a transient warmed by
	 * an older version can never resurface a non-chat model either.
	 *
	 * @since 0.2.0
	 *
	 * @param array $ids Model IDs (fallback, cached, or discovered).
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	public static function map_from_ids( array $ids ): array {
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
