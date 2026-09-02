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
	 * Mirrored literally by the SDK-free settings invalidation and
	 * uninstall.php (neither can autoload this class); tests pin the
	 * mirror.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NEGATIVE_CACHE_SUFFIX = '_miss';

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
