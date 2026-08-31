<?php
/**
 * Immutable z.ai OpenAI-compatible endpoint resolver.
 *
 * Encapsulates the SPEC §3.1 endpoint matrix (plan × region) and nothing
 * else. Instances are immutable value objects; the active endpoint is
 * resolved per request via for_current_settings(), which reads the options at
 * call time, so changing the settings retargets the very next request without
 * rebuilding the provider registry.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Endpoints;

use InvalidArgumentException;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

/**
 * Immutable endpoint value object for one plan × region combination.
 *
 * @since 0.1.0
 */
final class ZaiEndpoint {

	/**
	 * The full plan × region matrix (SPEC §3.1, OpenAI-compatible rows).
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, string>>
	 */
	const MATRIX = array(
		'coding'  => array(
			'intl' => 'https://api.z.ai/api/coding/paas/v4',
			'cn'   => 'https://open.bigmodel.cn/api/coding/paas/v4',
		),
		'general' => array(
			'intl' => 'https://api.z.ai/api/paas/v4',
			'cn'   => 'https://open.bigmodel.cn/api/paas/v4',
		),
	);

	/**
	 * The canonical base URL: international region, general plan.
	 *
	 * Required by the SDK's AbstractApiProvider::baseUrl(), which stays fixed
	 * regardless of the active plan/region (SPEC §3.3).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CANONICAL_BASE_URL = 'https://api.z.ai/api/paas/v4';

	/**
	 * The API plan of this endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $plan;

	/**
	 * The account region of this endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $region;

	/**
	 * The base URL of this endpoint (no trailing slash).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor. Use for() or for_current_settings() instead.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plan     API plan.
	 * @param string $region   Account region.
	 * @param string $base_url Base URL.
	 */
	private function __construct( string $plan, string $region, string $base_url ) {
		$this->plan     = $plan;
		$this->region   = $region;
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * Returns the endpoint for an explicit plan × region combination.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plan   One of PlanRegionSettings::PLANS.
	 * @param string $region One of PlanRegionSettings::REGIONS.
	 * @return self
	 * @throws InvalidArgumentException When the combination is not part of the matrix.
	 */
	public static function for( string $plan, string $region ): self {
		if ( ! \is_string( self::MATRIX[ $plan ][ $region ] ?? null ) ) {
			throw new InvalidArgumentException(
				'Unknown z.ai endpoint for plan ' . wp_json_encode( $plan ) . ' and region ' . wp_json_encode( $region )
			);
		}

		return new self( $plan, $region, self::MATRIX[ $plan ][ $region ] );
	}

	/**
	 * Returns the endpoint for the currently stored settings.
	 *
	 * Reads the plan/region options at call time (with the documented
	 * defaults and corrupt-value fallback), so the result always matches the
	 * settings as of this request.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function for_current_settings(): self {
		return self::for( PlanRegionSettings::get_plan(), PlanRegionSettings::get_region() );
	}

	/**
	 * The API plan.
	 *
	 * @since 0.1.0
	 *
	 * @return string 'coding' or 'general'.
	 */
	public function plan(): string {
		return $this->plan;
	}

	/**
	 * The account region.
	 *
	 * @since 0.1.0
	 *
	 * @return string 'intl' or 'cn'.
	 */
	public function region(): string {
		return $this->region;
	}

	/**
	 * The base URL of this endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @return string Base URL without trailing slash.
	 */
	public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * The model-list URL of this endpoint.
	 *
	 * Shared surface with ZaiAnthropicEndpoint so the provider-agnostic
	 * availability base can probe either surface uniformly.
	 *
	 * @since 0.2.0
	 *
	 * @return string Full URL of the /models route.
	 */
	public function models_url(): string {
		return $this->api_url( 'models' );
	}

	/**
	 * Builds a request URL by appending a path with a single slash.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @return string e.g. 'zai|coding|intl'.
	 */
	public function cache_key(): string {
		return 'zai|' . $this->plan . '|' . $this->region;
	}
}
