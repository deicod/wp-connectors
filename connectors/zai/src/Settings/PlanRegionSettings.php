<?php
/**
 * Plan and region settings for the zai provider (OpenAI-compatible surface).
 *
 * All behavior lives in AbstractPlanRegionSettings; this child declares the
 * zai provider's OWN identifiers (option names, section id, label, and the
 * SDK-free invalidation identifiers the availability and directory layers
 * mirror). GLM6 #12 moved them here from inheritable base defaults: the
 * base's values were the zai provider's all along, and a future child
 * forgetting one override would have silently read and written THIS
 * provider's options (see the base class docblock).
 * See the base class for the sanitization, guard, and region-switch
 * semantics.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Settings;

/**
 * Plan/region settings store and Settings API wiring for the zai provider.
 *
 * @since 0.1.0
 */
final class PlanRegionSettings extends AbstractPlanRegionSettings {

	/**
	 * Option name: this provider's API plan (coding or general).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const OPTION_PLAN = 'zai_connector_zai_plan';

	/**
	 * Option name: this provider's account region (international or China).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const OPTION_REGION = 'zai_connector_zai_region';

	/**
	 * Settings section ID for this provider's fields.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const SECTION_ID = 'zai_connector_zai';

	/**
	 * Display label of the provider this section configures.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const PROVIDER_LABEL = 'z.ai';

	/**
	 * Invalidation identifiers, part 1: the availability layer's option
	 * names this settings layer must be able to clear.
	 *
	 * These are deliberately DUPLICATED here (Codex R2 #3): on WordPress
	 * 6.9 without the optional PHP AI Client plugin the settings UI still
	 * boots (dependency notice), and a plan/region save must invalidate
	 * credential-derived state WITHOUT autoloading the availability class —
	 * which implements missing SDK interfaces and would fatal. The
	 * availability classes mirror THESE constants as their single source of
	 * truth, and a consistency test pins the mirror.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const STATE_OPTION = 'zai_connector_zai_key_state';

	/**
	 * Invalidation identifiers: the availability layer's region-pending flag
	 * option (see STATE_OPTION for why this lives here SDK-free).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const REGION_PENDING_OPTION = 'zai_connector_zai_region_pending';

	/**
	 * Invalidation identifiers: the core-owned key option name deleted on a
	 * region switch (see STATE_OPTION for why this lives here SDK-free).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const KEY_OPTION = 'connectors_ai_zai_api_key';

	/**
	 * Invalidation identifiers: the env/constant name consulted by
	 * mark_region_switch_pending() (see STATE_OPTION for why this lives
	 * here SDK-free).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const KEY_ENV_NAME = 'ZAI_API_KEY';

	/**
	 * Invalidation identifiers: the directory layer's discovery-cache
	 * transient prefix (see STATE_OPTION for why this lives here
	 * SDK-free).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'zai_connector_zai_models_';

	/**
	 * Invalidation identifiers: the endpoint identity scope the discovery
	 * cache keys embed ('{scope}|{plan}|{region}' — see ZaiEndpoint /
	 * ZaiAnthropicEndpoint cache_key()). Composing the key inline keeps the
	 * invalidation free of the endpoint classes' autoload (see
	 * STATE_OPTION for the SDK-absent rationale); a consistency test pins
	 * the composition to the endpoint classes' own format.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CACHE_SCOPE = 'zai';
}
