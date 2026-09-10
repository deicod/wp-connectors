<?php
/**
 * SDK cache-layer neutralization for both z.ai model directories (glm37-6).
 *
 * The SDK base wraps the models consult in WithDataCachingTrait — an
 * in-memory local cache plus any PSR-16 cache configured through
 * AiClient::setCache(), 24h TTL, per-class base key by default. Both
 * surfaces bypass that layer WHOLESALE: the WordPress transient (12h TTL,
 * endpoint-scoped, deleted on settings changes and uninstall) is the
 * single discovery cache, and an outer layer that outlives it would
 * defeat the advertised TTL and survive every invalidation. The trio
 * lived as near-verbatim twins on the two directories with pins on the
 * zai side only (glm36-6 verified the anthropic port empirically and
 * pinned nothing), so a neutralization change landing on one surface
 * was the twin-drift class the extraction exists to stop. One trait
 * composes the rule now; the per-surface pins run through the shared
 * directory test base.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\AiClient;

/**
 * Neutralizes the SDK's own model-metadata cache layer.
 *
 * Composed by both model directories; the one per-surface fact is the
 * endpoint class hook (the base key's endpoint scoping).
 *
 * @since 0.2.0
 */
trait NeutralizesSdkModelCache {

	/**
	 * The endpoint class whose current-settings identity scopes the SDK
	 * cache key — the ONE per-surface fact of the neutralization.
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	abstract protected static function discovery_endpoint_class(): string;

	/**
	 * Scopes the SDK-level cache key to the CURRENT endpoint.
	 *
	 * The layer below never serves (hasCache()) and never stores
	 * (setCache()), but the key stays endpoint-scoped so entries written
	 * by any OTHER path (a foreign directory instance, the base's
	 * invalidateCaches() clears) can never cross endpoints — the glm36-6
	 * verifier's poison-entry probe pinned this exact shape on the
	 * zai_anthropic surface. self::class inside the trait resolves to the
	 * COMPOSING class, so each surface's key stays its own.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	protected function getBaseCacheKey(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method override.
		$endpoint_class = static::discovery_endpoint_class();

		return 'ai_client_' . AiClient::VERSION . '_' . md5( self::class . '|' . $endpoint_class::for_current_settings()->cache_key() );
	}

	/**
	 * Never serves from the SDK cache layer (in-memory local or PSR-16).
	 *
	 * The plugin transient is the ONLY discovery cache: outer layers
	 * retain values for 24h, which would defeat the advertised 12h TTL
	 * and survive the transient deletion on settings changes/uninstall.
	 * Reporting "never cached" forces every consult through the
	 * surface's own build, which applies the plugin's TTL and
	 * invalidation rules (the per-consult option reads glm15-6 pins stay
	 * per-consult).
	 *
	 * @since 0.2.0
	 *
	 * @param string $key Cache key suffix.
	 * @return bool Always false.
	 */
	protected function hasCache( string $key ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Generic.CodeAnalysis.UnusedFunctionParameter -- SDK trait method override; the parameter is the contract's shape, deliberately unread.
		return false;
	}

	/**
	 * Never persists anything in the SDK cache layer (in-memory or PSR-16).
	 *
	 * Successful discoveries are persisted as the plugin transient inside
	 * the surface's own build with DISCOVERY_TTL; fallbacks are cached at
	 * most as the 60s negative marker (never here — see GLM1 #6). Storing
	 * here as well would leave warmed entries behind after transient
	 * invalidation.
	 *
	 * @since 0.2.0
	 *
	 * @param string                 $key   Cache key suffix.
	 * @param mixed                  $value Value to cache (ignored).
	 * @param int|\DateInterval|null $ttl   TTL (ignored).
	 * @return bool Always true (pretend success; store nothing).
	 */
	protected function setCache( string $key, $value, $ttl = null ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Generic.CodeAnalysis.UnusedFunctionParameter -- SDK trait method override; the parameters are the contract's shape, deliberately unread.
		return true; // Pretend success; store nothing.
	}
}
