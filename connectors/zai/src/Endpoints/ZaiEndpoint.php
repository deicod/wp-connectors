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
 * GLM8 #10: the plan × region skeleton (matrix lookup, unknown-
 * combination rejection, current-settings resolution, accessors,
 * api_url(), cache_key(), and the base-URL normalization with its
 * double-append guard) lives on the shared AbstractZaiEndpoint base —
 * this class declares its matrix and identifiers only. The guard is NEW
 * here (the child previously ran a bare rtrim): a base URL that already
 * carries one of this surface's suffixes loses it exactly once, like
 * the Anthropic surface always has.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Endpoints;

use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

/**
 * Immutable endpoint value object for one plan × region combination.
 *
 * @since 0.1.0
 */
final class ZaiEndpoint extends AbstractZaiEndpoint {

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
	 * The model-list route this surface serves at the base URL.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const MODELS_ROUTE = 'models';

	/**
	 * The chat-completion route this surface serves at the base URL.
	 *
	 * GLM15-4: the vendor SDK's OpenAI-compat parent composes its
	 * generation requests against this path internally — the constant
	 * exists so evidence channels (the live probe's route line) name
	 * the URL through the endpoint layer instead of an inline literal,
	 * and the mapping suite pins it to the CAPTURED wire URL so a
	 * vendor route change breaks a test, not the evidence.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const GENERATION_ROUTE = 'chat/completions';

	/**
	 * Path suffixes this surface appends to the base URL.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const ENDPOINT_SUFFIXES = array( '/chat/completions', '/models' );

	/**
	 * Cache-key-safe scope of this surface: the settings layer's own
	 * scope string, aliased so the two can never drift (GLM8 #10).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const CACHE_SCOPE = PlanRegionSettings::CACHE_SCOPE;

	/**
	 * Discovery transient prefix of this surface: the settings layer's
	 * own prefix, aliased so the two can never drift (GLM8 #11).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const CACHE_PREFIX = PlanRegionSettings::CACHE_PREFIX;

	/**
	 * The settings class whose plan/region getters resolve the current
	 * endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @var class-string
	 */
	const SETTINGS_CLASS = PlanRegionSettings::class;

	/**
	 * The surface label for the unknown-combination rejection.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const UNKNOWN_ENDPOINT_LABEL = 'z.ai';

	/**
	 * The chat-completion URL of this endpoint (glm15-4).
	 *
	 * @since 0.2.0
	 *
	 * @return string Full URL of the generation route.
	 */
	public function generation_url(): string {
		return $this->api_url( self::GENERATION_ROUTE );
	}
}
