<?php
/**
 * Zai provider availability (OpenAI-compatible surface).
 *
 * All behavior lives in AbstractZaiProviderAvailability; this child binds it
 * to the zai provider's option names (state, region-pending flag, core-owned
 * key option, env/constant name) and the OpenAI-surface endpoint resolver.
 * See the base class for the validation, binding, and region-switch
 * distrust semantics.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Availability;

use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;

/**
 * Provider availability for the zai provider.
 *
 * @since 0.1.0
 */
final class ZaiProviderAvailability extends AbstractZaiProviderAvailability {

	// The four identifier constants below mirror the SDK-free settings
	// layer (PlanRegionSettings), which owns them so settings invalidation
	// never autoloads this SDK-dependent class (Codex R2 #3); a
	// consistency test pins the mirror.

	/**
	 * Plugin-owned option persisting the last validated state.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATE_OPTION = PlanRegionSettings::STATE_OPTION;

	/**
	 * Plugin-owned option marking an env/constant credential as pending
	 * DEFINITIVE validation after a region switch.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REGION_PENDING_OPTION = PlanRegionSettings::REGION_PENDING_OPTION;

	/**
	 * The core-owned option holding the zai provider's API key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_OPTION = PlanRegionSettings::KEY_OPTION;

	/**
	 * Environment variable / constant name core advertises for the key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_ENV_NAME = PlanRegionSettings::KEY_ENV_NAME;

	/**
	 * This provider's label in the shared refusal wording (GLM5 #17).
	 *
	 * GLM6 #12: declared here since the base stopped carrying the zai
	 * provider's identifier defaults.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUSAL_LABEL = PlanRegionSettings::CACHE_SCOPE; // glm15-23: one surface-identity owner (see the settings layer).

	/**
	 * The provider's endpoint resolver class.
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	protected static function endpoint_class(): string {
		return ZaiEndpoint::class;
	}

	/**
	 * The provider's SDK-free settings class (Codex R2 #3).
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	protected static function settings_class(): string {
		return PlanRegionSettings::class;
	}
}
