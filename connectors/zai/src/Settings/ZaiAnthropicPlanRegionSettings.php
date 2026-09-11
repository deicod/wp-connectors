<?php
/**
 * Plan and region settings for the zai_anthropic provider (Anthropic-compatible surface).
 *
 * All behavior lives in AbstractPlanRegionSettings; this child binds it to
 * the zai_anthropic provider's own option names and classes. The options are
 * distinct from the zai provider's, so the two providers' endpoint
 * selections are fully independent — saving one never retargets the other.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Settings;

/**
 * Plan/region settings store and Settings API wiring for the zai_anthropic provider.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicPlanRegionSettings extends AbstractPlanRegionSettings {

	/**
	 * Option name: API plan (coding subscription or general pay-as-you-go).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPTION_PLAN = 'zai_connector_zai_anthropic_plan';

	/**
	 * Option name: account region (international or China).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPTION_REGION = 'zai_connector_zai_anthropic_region';

	/**
	 * Settings section ID for this provider's fields.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SECTION_ID = 'zai_connector_zai_anthropic';

	/**
	 * Display label of the provider this section configures.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PROVIDER_LABEL = 'z.ai (Anthropic API)';

	/**
	 * Default plan: GENERAL, not coding.
	 *
	 * Live-evidence amendment (architecture record 0007, 2026-08-31): the
	 * coding-surface Messages routes cannot generate (both /messages and
	 * /v1/messages answer with wrapped 404s for every model and auth shape
	 * probed), while the general-surface Messages endpoint works — and is
	 * the production-proven path for Coding-Plan keys on the Anthropic
	 * protocol (~/.local/bin/claude-glm). The coding base stays selectable;
	 * it serves /v1/models only as of the probe date. SPEC §3.3 was updated
	 * together with this change.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DEFAULT_PLAN = 'general';


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
	public const STATE_OPTION = 'zai_connector_zai_anthropic_key_state';

	/**
	 * Region-pending flag option (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REGION_PENDING_OPTION = 'zai_connector_zai_anthropic_region_pending';

	/**
	 * Core-owned key option (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY_OPTION = 'connectors_ai_zai_anthropic_api_key';

	/**
	 * Env/constant name (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY_ENV_NAME = 'ZAI_ANTHROPIC_API_KEY';

	/**
	 * Discovery-cache transient prefix (SDK-free invalidation identifier).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CACHE_PREFIX = 'zai_connector_zai_anthropic_models_';

	/**
	 * Endpoint identity scope (SDK-free invalidation identifier):
	 * ZaiAnthropicEndpoint aliases THIS constant for its cache_key() and
	 * discovery ids (GLM8 #11 — the invalidation composes through the
	 * endpoint layer, never a mirrored formula).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CACHE_SCOPE = 'zai_anthropic';

	/**
	 * The endpoint class that owns this surface's discovery cache-id
	 * composition (GLM8 #11): the invalidation below clears the
	 * transients the endpoint layer names, never a private re-composition.
	 *
	 * The endpoint classes are SDK-free loadable (no SDK parent, lazy
	 * imports only), so consulting them keeps the SDK-absent guarantee
	 * (see STATE_OPTION) while removing the mirrored formula.
	 *
	 * @since 0.2.0
	 *
	 * @var class-string
	 */
	public const ENDPOINT_CLASS = \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::class;
}
