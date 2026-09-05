<?php
/**
 * Zai_anthropic provider availability (Anthropic-compatible surface).
 *
 * All behavior lives in AbstractZaiProviderAvailability; this child binds it
 * to the zai_anthropic provider's OWN option names (state, region-pending
 * flag, core-owned key option, env/constant name) and the Anthropic-surface
 * endpoint resolver. Availability is validated INDEPENDENTLY: the state
 * option and the endpoint-scoped binding are distinct from the zai
 * provider's, so a validated zai key can never establish this provider's
 * status and vice versa.
 *
 * Requests this class makes (the probe) authenticate with the Anthropic
 * surface's protocol headers via ZaiAnthropicRequestAuthentication — Bearer
 * plus anthropic-version, never x-api-key.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Availability;

use Deicod\WpConnectors\Zai\Authentication\SpeaksAnthropicMessagesProtocol;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

/**
 * Provider availability for the zai_anthropic provider.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicProviderAvailability extends AbstractZaiProviderAvailability {
	use SpeaksAnthropicMessagesProtocol;

	// The four identifier constants below mirror the SDK-free settings
	// layer (ZaiAnthropicPlanRegionSettings), which owns them so settings
	// invalidation never autoloads this SDK-dependent class (Codex R2 #3);
	// a consistency test pins the mirror.

	/**
	 * Plugin-owned option persisting the last validated state.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STATE_OPTION = ZaiAnthropicPlanRegionSettings::STATE_OPTION;

	/**
	 * Plugin-owned option marking an env/constant credential as pending
	 * DEFINITIVE validation after a region switch.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REGION_PENDING_OPTION = ZaiAnthropicPlanRegionSettings::REGION_PENDING_OPTION;

	/**
	 * The core-owned option holding the zai_anthropic provider's API key
	 * (derived by core from the provider ID).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY_OPTION = ZaiAnthropicPlanRegionSettings::KEY_OPTION;

	/**
	 * Environment variable / constant name core advertises for the key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY_ENV_NAME = ZaiAnthropicPlanRegionSettings::KEY_ENV_NAME;

	/**
	 * This provider's label in the shared refusal wording (GLM5 #17).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUSAL_LABEL = ZaiAnthropicPlanRegionSettings::CACHE_SCOPE; // glm15-23: one surface-identity owner (see the settings layer).

	/**
	 * The provider's endpoint resolver class.
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	protected static function endpoint_class(): string {
		return ZaiAnthropicEndpoint::class;
	}

	/**
	 * The provider's SDK-free settings class (Codex R2 #3).
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	protected static function settings_class(): string {
		return ZaiAnthropicPlanRegionSettings::class;
	}

	/*
	 * glm16-1: the SpeaksAnthropicMessagesProtocol trait's
	 * raw_request_authentication() hook is the INHERITED availability-base
	 * implementation (the aliased SDK trait getter) — the former local
	 * override (parent::getRequestAuthentication()) returned the identical
	 * instance, so the base hook satisfies the trait abstract once for
	 * every availability surface.
	 */
}
