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

use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory;

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
	 * The provider's availability class (option-name holder).
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	protected static function availability_class(): string {
		return ZaiAnthropicProviderAvailability::class;
	}

	/**
	 * The provider's model metadata directory class (cache-prefix holder).
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	protected static function directory_class(): string {
		return ZaiAnthropicModelMetadataDirectory::class;
	}

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
}
