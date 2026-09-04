<?php
/**
 * Shared z.ai endpoint value-object skeleton for both surfaces
 * (round 8 cleanup, GLM8 #10).
 *
 * The plan × region resolution — the matrix lookup, the unknown-
 * combination rejection, the current-settings resolution, the immutable
 * plan/region/base-url accessors, api_url(), the cache-key identity,
 * and the base-URL normalization with its double-append guard — was
 * re-implemented by ZaiAnthropicEndpoint from ZaiEndpoint wholesale
 * (api_url() byte-identical; cache_key() a scope string apart), with
 * live drift already demonstrated: the normalize_base_url() guard
 * existed only in the Anthropic copy, so a URL-building fix landing on
 * one surface produced different URLs per surface for the same matrix
 * typo. One base owns the skeleton now; each child declares its matrix
 * and identifiers (the GLM6 #12 child-owned-constant layout: a missing
 * declaration fails LOUDLY on the first use, pinned by reflection
 * tests, instead of silently inheriting the sibling's values).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Endpoints;

use Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache;
use InvalidArgumentException;

/**
 * Base endpoint value object: the shared plan × region skeleton.
 *
 * The child-owned constants this base reads through static:: (the
 * GLM6 #12 layout — this base carries no surface's defaults):
 *
 * - MATRIX: the plan × region base-URL map;
 * - MODELS_ROUTE: the surface's model-list path ('models' / 'v1/models');
 * - ENDPOINT_SUFFIXES: the paths the surface appends to a base URL
 *   (normalize_base_url()'s double-append guard);
 * - CACHE_SCOPE: the cache-key scope string, aliased from the surface's
 *   settings class ('zai' / 'zai_anthropic');
 * - CACHE_PREFIX: the discovery transient prefix, aliased from the
 *   surface's settings class;
 * - SETTINGS_CLASS: the surface's settings class (the plan/region
 *   getters for_current_settings() reads);
 * - UNKNOWN_ENDPOINT_LABEL: the surface label in the unknown-
 *   combination message ('z.ai' / 'z.ai Anthropic').
 *
 * @since 0.2.0
 */
abstract class AbstractZaiEndpoint {

	/**
	 * The API plan of this endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $plan;

	/**
	 * The account region of this endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $region;

	/**
	 * The base URL of this endpoint (no trailing slash, no endpoint suffix).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor. Use for() or for_current_settings() instead.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plan     API plan.
	 * @param string $region   Account region.
	 * @param string $base_url Base URL.
	 */
	final protected function __construct( string $plan, string $region, string $base_url ) {
		$this->plan     = $plan;
		$this->region   = $region;
		$this->base_url = self::normalize_base_url( $base_url );
	}

	/**
	 * Strips trailing slashes and any already-appended endpoint suffix.
	 *
	 * The double-append guard (GLM8 #10: on BOTH surfaces now — it lived
	 * only in the Anthropic copy while ZaiEndpoint ran a bare rtrim, the
	 * live drift this shared base exists to stop): the surface's URL
	 * builders always append their suffix exactly once, so a base URL
	 * that already ends with one (a matrix edit, a hand-built value)
	 * must lose it first.
	 *
	 * @since 0.2.0
	 *
	 * @param string $base_url Raw base URL.
	 * @return string Normalized base URL.
	 */
	final public static function normalize_base_url( string $base_url ): string {
		$trimmed = rtrim( $base_url, '/' );

		foreach ( static::ENDPOINT_SUFFIXES as $suffix ) {
			if ( substr( $trimmed, -\strlen( $suffix ) ) === $suffix ) {
				$trimmed = substr( $trimmed, 0, -\strlen( $suffix ) );
			}
		}

		return $trimmed;
	}

	/**
	 * Returns the endpoint for an explicit plan × region combination.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plan   One of the surface's plans.
	 * @param string $region One of the surface's regions.
	 * @return static The concrete surface's endpoint.
	 * @throws InvalidArgumentException When the combination is not part of the matrix.
	 */
	final public static function for( string $plan, string $region ): self {
		if ( ! \is_string( static::MATRIX[ $plan ][ $region ] ?? null ) ) {
			throw new InvalidArgumentException(
				'Unknown ' . static::UNKNOWN_ENDPOINT_LABEL . ' endpoint for plan ' . wp_json_encode( $plan ) . ' and region ' . wp_json_encode( $region ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (the child-owned label plus the wp_json_encode'd values; GLM1 #5); escaping belongs to the display layer.
			);
		}

		return new static( $plan, $region, static::MATRIX[ $plan ][ $region ] );
	}

	/**
	 * Returns the endpoint for the currently stored settings.
	 *
	 * Reads the surface's plan/region options at call time (with the
	 * documented defaults and corrupt-value fallback), so the result
	 * always matches the settings as of this request.
	 *
	 * @since 0.2.0
	 *
	 * @return static The concrete surface's endpoint.
	 */
	final public static function for_current_settings(): self {
		$settings = static::SETTINGS_CLASS;

		return static::for( $settings::get_plan(), $settings::get_region() );
	}

	/**
	 * The API plan.
	 *
	 * @since 0.2.0
	 *
	 * @return string The plan ('coding' or 'general').
	 */
	final public function plan(): string {
		return $this->plan;
	}

	/**
	 * The account region.
	 *
	 * @since 0.2.0
	 *
	 * @return string The region ('intl' or 'cn').
	 */
	final public function region(): string {
		return $this->region;
	}

	/**
	 * The base URL of this endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return string Base URL without trailing slash or endpoint suffix.
	 */
	final public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * Builds a request URL by appending a path with a single slash.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Path relative to the base URL (leading slash tolerated).
	 * @return string Full URL.
	 */
	final public function api_url( string $path = '' ): string {
		if ( '' === $path ) {
			return $this->base_url;
		}

		return $this->base_url . '/' . ltrim( $path, '/' );
	}

	/**
	 * The model-list URL of this endpoint.
	 *
	 * Shared surface shape (the path differs, MODELS_ROUTE) so the
	 * provider-agnostic availability base can probe either surface
	 * uniformly.
	 *
	 * @since 0.2.0
	 *
	 * @return string Full URL of the model-list route.
	 */
	final public function models_url(): string {
		return $this->api_url( static::MODELS_ROUTE );
	}

	/**
	 * Cache-key-safe identity of this endpoint (provider, plan, region).
	 *
	 * Distinct per surface (the CACHE_SCOPE string), so availability
	 * bindings and discovery caches can never collide across the two
	 * providers.
	 *
	 * @since 0.2.0
	 *
	 * @return string e.g. 'zai|coding|intl'.
	 */
	final public function cache_key(): string {
		return static::CACHE_SCOPE . '|' . $this->plan . '|' . $this->region;
	}

	/**
	 * The discovery transient id for one plan × region combination of
	 * this surface (GLM8 #11: the ONE owner of the composition).
	 *
	 * The formula — the surface's transient prefix, then the md5 of the
	 * endpoint identity — was hand-composed in five places (the settings
	 * invalidation, both metadata directories, uninstall.php's fully
	 * literal copy, and the live probe), three of them appending the
	 * negative-cache '_miss' suffix literally instead of through
	 * ZaiDiscoveryCache's exported constant: any change to the endpoint
	 * identity composition silently stranded stale 12h transients on
	 * every mirror that missed the lockstep edit — invalidation and
	 * uninstall then cleared keys nobody writes. The endpoint layer owns
	 * the identity, so it owns the id; every consumer composes through
	 * this method (or discovery_transient_ids()) now.
	 *
	 * SDK-free loadable by construction (the settings invalidation and
	 * uninstall.php call this without the SDK plugin): this base, the
	 * children, and ZaiDiscoveryCache declare no SDK types.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plan   One of the surface's plans.
	 * @param string $region One of the surface's regions.
	 * @return string The positive discovery transient id.
	 */
	final public static function discovery_cache_id( string $plan, string $region ): string {
		return static::CACHE_PREFIX . md5( static::CACHE_SCOPE . '|' . $plan . '|' . $region );
	}

	/**
	 * The discovery transient ids (positive cache plus negative marker)
	 * for one plan × region combination of this surface (GLM8 #11).
	 *
	 * The '_miss' suffix rides ZaiDiscoveryCache's exported constant —
	 * the marker this surface's own negative caching writes — so the
	 * invalidation paths can never clear (or miss) a name the cache
	 * layer stopped writing.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plan   One of the surface's plans.
	 * @param string $region One of the surface's regions.
	 * @return list<string> The positive and the negative-marker transient ids.
	 */
	final public static function discovery_transient_ids( string $plan, string $region ): array {
		$cache_id = static::discovery_cache_id( $plan, $region );

		return array(
			$cache_id,
			$cache_id . ZaiDiscoveryCache::NEGATIVE_CACHE_SUFFIX,
		);
	}
}
