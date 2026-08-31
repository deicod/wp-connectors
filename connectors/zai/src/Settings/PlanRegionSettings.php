<?php
/**
 * Plan and region settings for the zai provider (OpenAI-compatible surface).
 *
 * All behavior lives in AbstractPlanRegionSettings; this child binds it to
 * the zai provider's option names and identifiers. See the base class for
 * the sanitization, guard, and region-switch semantics.
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
	 * Option name: API plan (coding subscription or general pay-as-you-go).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_PLAN = 'zai_connector_zai_plan';

	/**
	 * Option name: account region (international or China).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_REGION = 'zai_connector_zai_region';

	/**
	 * Settings section ID for this provider's fields.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SECTION_ID = 'zai_connector_zai';

	/**
	 * Display label of the provider this section configures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROVIDER_LABEL = 'z.ai';

	/**
	 * SDK-free invalidation identifiers for this provider (see the base
	 * STATE_OPTION docblock): the availability layer's option names, the
	 * core-owned key option, the env/constant name, the discovery-cache
	 * prefix, and the endpoint identity scope.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'zai_connector_zai_key_state';

	/**
	 * Region-pending flag option (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REGION_PENDING_OPTION = 'zai_connector_zai_region_pending';

	/**
	 * Core-owned key option (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY_OPTION = 'connectors_ai_zai_api_key';

	/**
	 * Env/constant name (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY_ENV_NAME = 'ZAI_API_KEY';

	/**
	 * Discovery-cache transient prefix (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'zai_connector_zai_models_';

	/**
	 * Endpoint identity scope (SDK-free invalidation identifier): matches
	 * ZaiEndpoint::cache_key()'s prefix.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CACHE_SCOPE = 'zai';
}
