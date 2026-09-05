<?php
/**
 * Plugin Name:       Connectors: z.ai
 * Plugin URI:        https://github.com/deicod/wp-connectors
 * Description:       z.ai GLM models for the WordPress AI Client via the OpenAI-compatible and Anthropic-compatible APIs. Coding or general plan, international or China region.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            deicod
 * Author URI:        https://github.com/deicod
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       zai
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai;

use WordPress\AiClient\AiClient;
use Deicod\WpConnectors\Zai\Settings\DebugSettings;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;
use Deicod\WpConnectors\Zai\Support\DebugLogger;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/*
 * The slug-derived version constant required by docs/CONVENTIONS.md
 * (ZAI_VERSION); the prefix sniff rejects the short "zai" prefix by design.
 *
 * GLM5 #15: the defined() guard keeps a foreign plugin (or theme) that
 * defines the same generic constant FIRST from emitting a "Constant
 * ZAI_VERSION already defined" notice on every request — and from this
 * plugin silently reporting the foreign value as its own version.
 */
if ( ! defined( 'ZAI_VERSION' ) ) {
	define( 'ZAI_VERSION', '0.1.0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the plugin's providers (zai, zai_anthropic) with the AI Client
 * default registry.
 *
 * Idempotent, and a safe no-op when the PHP AI Client SDK is unavailable
 * (WordPress below 7.0 without the standalone PHP AI Client plugin active).
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	Plugin::register( AiClient::defaultRegistry() );
}

/**
 * Installs the plugin's hooks.
 *
 * Idempotent (the harness dedupes identical registrations), so it is safe to
 * call repeatedly — including from tests that reset the hook registry.
 *
 * glm15-13: every per-surface hook rides the one SDK-free surface list
 * below — the hook block was copy-pasted per surface (~7 update/add
 * option hooks each plus the page/section asymmetry), and the file's
 * own comments (GLM5 #14) document that one missed copied line
 * produced exactly the stranded-invalidation bug class (the first
 * persisted plan/region change silently skipped every invalidation).
 * A third surface is one list entry, not ~15 edit sites.
 *
 * @since 0.1.0
 *
 * @return void
 */
function boot(): void {
	// Priority 5: before core's _wp_connectors_init() at init 15, so the
	// auto-discovered Connectors card sees the provider in the same request.
	add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );

	add_action( 'admin_notices', array( Plugin::class, 'render_dependency_notice' ) );

	// The plugin's surfaces, first entry owning the shared settings page.
	$surface_settings = array(
		PlanRegionSettings::class,
		ZaiAnthropicPlanRegionSettings::class,
	);

	// Plan/region settings (Tasks 1.2/2.1). The guards run at priority 20,
	// AFTER every register_settings call (10): the guard enumerates the
	// whole option group from the registration registry (code-review GLM1
	// #10), which is only complete once every registration has run.
	foreach ( $surface_settings as $settings_class ) {
		add_action( 'admin_init', array( $settings_class, 'register_settings' ) );
		add_action( 'admin_init', array( $settings_class, 'guard_settings_save' ), 20 );
	}
	add_action( 'admin_init', array( DebugSettings::class, 'register_settings' ) );

	// The FIRST surface owns the page (register_page: the page plus its
	// own section); every further surface rides that page's section at
	// 20 — after the owner's add_options_page(), harmless either way
	// since add_settings_section() only records state keyed by slug.
	add_action( 'admin_menu', array( $surface_settings[0], 'register_page' ) );
	foreach ( $surface_settings as $index => $settings_class ) {
		if ( 0 !== $index ) {
			add_action( 'admin_menu', array( $settings_class, 'register_section' ), 20 );
		}
	}
	add_action( 'admin_menu', array( DebugSettings::class, 'register_fields' ), 20 );

	foreach ( $surface_settings as $settings_class ) {
		add_action( 'update_option_' . $settings_class::OPTION_PLAN, array( $settings_class, 'handle_settings_change' ), 10, 2 );
		add_action( 'update_option_' . $settings_class::OPTION_REGION, array( $settings_class, 'handle_region_change' ), 10, 2 );

		/*
		 * Fresh-install companions (GLM5 #14): while no option row
		 * exists, core's update_option() delegates to add_option(),
		 * which fires add_option_{$option} instead of the update hook —
		 * without these registrations the FIRST persisted plan/region
		 * change would skip every invalidation the later updates
		 * perform.
		 */
		add_action( 'add_option_' . $settings_class::OPTION_PLAN, array( $settings_class, 'handle_plan_add' ), 10, 2 );
		add_action( 'add_option_' . $settings_class::OPTION_REGION, array( $settings_class, 'handle_region_add' ), 10, 2 );
	}

	// Debug logging (Task 1.8) with its own fresh-install companion.
	add_action( 'update_option_' . DebugLogger::OPTION_ENABLED, array( DebugSettings::class, 'handle_enabled_change' ), 10, 2 );
	add_action( 'add_option_' . DebugLogger::OPTION_ENABLED, array( DebugSettings::class, 'handle_enabled_add' ), 10, 2 );

	// Plugin-row Settings link (Task 1.8).
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( Plugin::class, 'action_links' ) );
}

boot();
