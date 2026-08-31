<?php
/**
 * Immutable z.ai Anthropic-compatible endpoint resolver.
 *
 * Encapsulates the SPEC §3.1 endpoint matrix (plan × region) for the
 * Anthropic-compatible surface and nothing else. Instances are immutable
 * value objects; the active endpoint is resolved per request via
 * for_current_settings(), which reads the zai_anthropic options at call
 * time, so changing the settings retargets the very next request without
 * rebuilding the provider registry.
 *
 * Messages: {base}/v1/messages — models: {base}/v1/models. Both suffixes are
 * appended exactly once: normalize_base_url() strips them from a base URL
 * that already carries one, so a future matrix edit (or a hand-built value)
 * can never produce {base}/v1/messages/v1/messages.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Endpoints;

use InvalidArgumentException;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

/**
 * Immutable endpoint value object for one plan × region combination.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicEndpoint {

	/**
	 * The full plan × region matrix (SPEC §3.1, Anthropic-compatible rows).
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array<string, string>>
	 */
	const MATRIX = array(
		'coding'  => array(
			'intl' => 'https://api.z.ai/api/coding/anthropic',
			'cn'   => 'https://open.bigmodel.cn/api/coding/anthropic',
		),
		'general' => array(
			'intl' => 'https://api.z.ai/api/anthropic',
			'cn'   => 'https://open.bigmodel.cn/api/anthropic',
		),
	);

	/**
	 * The canonical base URL: international region, general plan.
	 *
	 * Required by the SDK's AbstractApiProvider::baseUrl(), which stays fixed
	 * regardless of the active plan/region (SPEC §3.3) and is per-surface:
	 * this is the Anthropic surface's canonical value, distinct from the
	 * OpenAI surface's ZaiEndpoint::CANONICAL_BASE_URL.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const CANONICAL_BASE_URL = 'https://api.z.ai/api/anthropic';

	/**
	 * Path suffixes this surface appends to the base URL.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const ENDPOINT_SUFFIXES = array( '/v1/messages', '/v1/models' );

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
	private function __construct( string $plan, string $region, string $base_url ) {
		$this->plan     = $plan;
		$this->region   = $region;
		$this->base_url = self::normalize_base_url( $base_url );
	}

	/**
	 * Strips trailing slashes and any already-appended endpoint suffix.
	 *
	 * The double-append guard: messages_url() and models_url() always append
	 * their suffix exactly once, so a base URL that already ends with one
	 * (a matrix typo, a hand-built value) must lose it first.
	 *
	 * @since 0.2.0
	 *
	 * @param string $base_url Raw base URL.
	 * @return string Normalized base URL.
	 */
	public static function normalize_base_url( string $base_url ): string {
		$trimmed = rtrim( $base_url, '/' );

		foreach ( self::ENDPOINT_SUFFIXES as $suffix ) {
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
	 * @param string $plan   One of ZaiAnthropicPlanRegionSettings::PLANS.
	 * @param string $region One of ZaiAnthropicPlanRegionSettings::REGIONS.
	 * @return self
	 * @throws InvalidArgumentException When the combination is not part of the matrix.
	 */
	public static function for( string $plan, string $region ): self {
		if ( ! \is_string( self::MATRIX[ $plan ][ $region ] ?? null ) ) {
			throw new InvalidArgumentException(
				'Unknown z.ai Anthropic endpoint for plan ' . wp_json_encode( $plan ) . ' and region ' . wp_json_encode( $region )
			);
		}

		return new self( $plan, $region, self::MATRIX[ $plan ][ $region ] );
	}

	/**
	 * Returns the endpoint for the currently stored settings.
	 *
	 * Reads the zai_anthropic plan/region options at call time (with the
	 * documented defaults and corrupt-value fallback), so the result always
	 * matches the settings as of this request. The zai provider's options are
	 * never consulted: the two providers select their endpoints independently.
	 *
	 * @since 0.2.0
	 *
	 * @return self
	 */
	public static function for_current_settings(): self {
		return self::for( ZaiAnthropicPlanRegionSettings::get_plan(), ZaiAnthropicPlanRegionSettings::get_region() );
	}

	/**
	 * The API plan.
	 *
	 * @since 0.2.0
	 *
	 * @return string 'coding' or 'general'.
	 */
	public function plan(): string {
		return $this->plan;
	}

	/**
	 * The account region.
	 *
	 * @since 0.2.0
	 *
	 * @return string 'intl' or 'cn'.
	 */
	public function region(): string {
		return $this->region;
	}

	/**
	 * The base URL of this endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return string Base URL without trailing slash or endpoint suffix.
	 */
	public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * The Messages API URL of this endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return string Full URL of the /v1/messages route.
	 */
	public function messages_url(): string {
		return $this->base_url . '/v1/messages';
	}

	/**
	 * The model-list URL of this endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @return string Full URL of the /v1/models route.
	 */
	public function models_url(): string {
		return $this->base_url . '/v1/models';
	}

	/**
	 * Builds a request URL by appending a path with a single slash.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Path relative to the base URL (leading slash tolerated).
	 * @return string Full URL.
	 */
	public function api_url( string $path = '' ): string {
		if ( '' === $path ) {
			return $this->base_url;
		}

		return $this->base_url . '/' . ltrim( $path, '/' );
	}

	/**
	 * Cache-key-safe identity of this endpoint (provider, plan, region).
	 *
	 * Distinct from the OpenAI surface's ZaiEndpoint::cache_key() values, so
	 * availability bindings and discovery caches can never collide across the
	 * two providers.
	 *
	 * @since 0.2.0
	 *
	 * @return string e.g. 'zai_anthropic|coding|intl'.
	 */
	public function cache_key(): string {
		return 'zai_anthropic|' . $this->plan . '|' . $this->region;
	}
}
