<?php
/**
 * The plugin's surface registry — the ONE owner of the surface set
 * (glm20-4).
 *
 * The (zai, zai_anthropic) pair was hand-enumerated in four independent
 * lists that had to be edited in lockstep — Plugin::PROVIDER_CLASSES,
 * zai.php's surface settings list, uninstall.php's surface registry
 * (glm16-14's one-file consolidation of ITS three copies), and
 * bin/zai-live-probe.php's probe surface map — and the repo's own
 * comments record the silent-strand drift class that already happened
 * twice when one listing missed an edit. This class is the single
 * cross-file owner of the SDK-FREE facts (which settings class and
 * which endpoint class constitute a surface, in registration order);
 * the SDK-dependent columns (the provider classes, their availability
 * writers) stay owned by the SDK-dependent sites that need them and
 * are pinned to this registry by tests/Zai/ZaiSurfaceLockstepTest.php
 * instead of referenced from here — the glm15-23 alias direction is
 * fixed: SDK-dependent facts alias the SDK-free owners, never the
 * reverse.
 *
 * A surface's SLUG is not restated here either: the settings layer's
 * CACHE_SCOPE owns it (glm15-23), and every consumer derives it as
 * $row['settings']::CACHE_SCOPE at runtime. This file must stay
 * SDK-free loadable and self-contained: uninstall.php requires it
 * through its owner chain with no autoloader (Codex R2 #3, GLM8 #11),
 * so it imports nothing and its constant references only compile-time
 * ::class strings.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * The plugin's connector surfaces, in registration order.
 *
 * @since 0.2.0
 */
final class ZaiSurfaces {

	/**
	 * The surface set: per surface, its SDK-free settings class (plan/
	 * region options, cache scope, probe-miss names) and endpoint class
	 * (discovery cache ids), in registration order — the first entry
	 * owns the shared settings page (see zai.php's boot()).
	 *
	 * Bare ::class strings autoload nothing, so the constant is safe to
	 * reference in a context that has not loaded the listed classes.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{settings: class-string, endpoint: class-string}>
	 */
	const SURFACES = array(
		array(
			'settings' => \Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::class,
			'endpoint' => \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::class,
		),
		array(
			'settings' => \Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::class,
			'endpoint' => \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::class,
		),
	);

	/**
	 * The surfaces' settings classes, in registration order.
	 *
	 * The list zai.php's boot() wires its per-surface hooks through
	 * (glm15-13): a third surface is one SURFACES row, not a second
	 * hand-enumerated list in the bootstrap file.
	 *
	 * @since 0.2.0
	 *
	 * @return list<class-string> Settings class names in registration order.
	 */
	public static function settings_classes(): array {
		$classes = array();
		foreach ( self::SURFACES as $surface ) {
			$classes[] = $surface['settings'];
		}

		return $classes;
	}
}
