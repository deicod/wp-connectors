<?php
/**
 * Plan and region settings for the zai provider (OpenAI-compatible surface).
 *
 * All behavior lives in AbstractPlanRegionSettings; this child binds it to
 * the zai provider's option names and identifiers. The zai provider IS the
 * base class's default identity, so no constant is redeclared here
 * (code-review GLM1 #11: the ten previous redeclarations were identical to
 * the base defaults — the zai_anthropic child overrides what differs).
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
}
