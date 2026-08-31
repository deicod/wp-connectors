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

/**
 * Provider availability for the zai provider.
 *
 * @since 0.1.0
 */
final class ZaiProviderAvailability extends AbstractZaiProviderAvailability {

	/**
	 * Plugin-owned option persisting the last validated state.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'zai_connector_zai_key_state';

	/**
	 * Plugin-owned option marking an env/constant credential as pending
	 * DEFINITIVE validation after a region switch.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REGION_PENDING_OPTION = 'zai_connector_zai_region_pending';

	/**
	 * The core-owned option holding the zai provider's API key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_OPTION = 'connectors_ai_zai_api_key';

	/**
	 * Environment variable / constant name core advertises for the key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_ENV_NAME = 'ZAI_API_KEY';

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
}
