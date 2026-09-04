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
 * Messages route per plan (coding {base}/messages, general {base}/v1/messages)
 * — models: {base}/v1/models. The suffixes are appended exactly once:
 * normalize_base_url() strips them from a base URL that already carries one,
 * so a future matrix edit (or a hand-built value) can never produce
 * {base}/v1/messages/v1/messages.
 *
 * GLM8 #10: the plan × region skeleton (matrix lookup, unknown-
 * combination rejection, current-settings resolution, accessors,
 * api_url(), cache_key(), and this class's own base-URL normalization)
 * lives on the shared AbstractZaiEndpoint base — this class declares
 * its matrix, routes, and identifiers only, so the two surfaces'
 * URL-building rules can never drift apart again.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Endpoints;

use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

/**
 * Immutable endpoint value object for one plan × region combination.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicEndpoint extends AbstractZaiEndpoint {

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
	 * The Messages API path per plan.
	 *
	 * Verified live 2026-08-31 with a valid Coding-Plan key (architecture
	 * record 0007): the two plans' Anthropic surfaces route Messages
	 * DIFFERENTLY — the coding surface serves {base}/messages and answers
	 * {base}/v1/messages with a wrapped 404, the general surface is the
	 * exact mirror ({base}/v1/messages works, {base}/messages 404s). The
	 * SPEC originally said "/v1/messages" for both; the live behavior and
	 * the SPEC were updated together (plan: change both in one change).
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	const MESSAGES_ROUTE_BY_PLAN = array(
		'coding'  => '/messages',
		'general' => '/v1/messages',
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
	 * The model-list route this surface serves at the base URL.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const MODELS_ROUTE = 'v1/models';

	/**
	 * Path suffixes this surface appends to the base URL.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const ENDPOINT_SUFFIXES = array( '/v1/messages', '/messages', '/v1/models' );

	/**
	 * Cache-key-safe scope of this surface: the settings layer's own
	 * scope string, aliased so the two can never drift (GLM8 #10).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const CACHE_SCOPE = ZaiAnthropicPlanRegionSettings::CACHE_SCOPE;

	/**
	 * Discovery transient prefix of this surface: the settings layer's
	 * own prefix, aliased so the two can never drift (GLM8 #11).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const CACHE_PREFIX = ZaiAnthropicPlanRegionSettings::CACHE_PREFIX;

	/**
	 * The settings class whose plan/region getters resolve the current
	 * endpoint (the zai provider's settings are never consulted).
	 *
	 * @since 0.2.0
	 *
	 * @var class-string
	 */
	const SETTINGS_CLASS = ZaiAnthropicPlanRegionSettings::class;

	/**
	 * The surface label for the unknown-combination rejection.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const UNKNOWN_ENDPOINT_LABEL = 'z.ai Anthropic';

	/**
	 * The Messages API URL of this endpoint.
	 *
	 * The path follows the plan's route (see MESSAGES_ROUTE_BY_PLAN): coding
	 * serves {base}/messages, general {base}/v1/messages.
	 *
	 * @since 0.2.0
	 *
	 * @return string Full URL of the Messages route.
	 */
	public function messages_url(): string {
		return $this->base_url() . self::MESSAGES_ROUTE_BY_PLAN[ $this->plan() ];
	}
}
