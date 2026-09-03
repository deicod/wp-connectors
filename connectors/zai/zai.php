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

// The slug-derived version constant required by docs/CONVENTIONS.md
// (ZAI_VERSION); the prefix sniff rejects the short "zai" prefix by design.
define( 'ZAI_VERSION', '0.1.0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

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
 * @since 0.1.0
 *
 * @return void
 */
function boot(): void {
	// Priority 5: before core's _wp_connectors_init() at init 15, so the
	// auto-discovered Connectors card sees the provider in the same request.
	add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );

	add_action( 'admin_notices', array( Plugin::class, 'render_dependency_notice' ) );

	// Plan/region settings (Tasks 1.2/2.1) + debug logging (Task 1.8). The
	// guards run at priority 20, AFTER every register_settings call (10):
	// the guard enumerates the whole option group from the registration
	// registry (code-review GLM1 #10), which is only complete once every
	// registration has run.
	add_action( 'admin_init', array( PlanRegionSettings::class, 'register_settings' ) );
	add_action( 'admin_init', array( ZaiAnthropicPlanRegionSettings::class, 'register_settings' ) );
	add_action( 'admin_init', array( DebugSettings::class, 'register_settings' ) );
	add_action( 'admin_init', array( PlanRegionSettings::class, 'guard_settings_save' ), 20 );
	add_action( 'admin_init', array( ZaiAnthropicPlanRegionSettings::class, 'guard_settings_save' ), 20 );
	add_action( 'admin_menu', array( PlanRegionSettings::class, 'register_page' ) );
	add_action( 'admin_menu', array( ZaiAnthropicPlanRegionSettings::class, 'register_section' ), 20 );
	add_action( 'admin_menu', array( DebugSettings::class, 'register_fields' ), 20 );
	add_action( 'update_option_' . PlanRegionSettings::OPTION_PLAN, array( PlanRegionSettings::class, 'handle_settings_change' ), 10, 2 );
	add_action( 'update_option_' . PlanRegionSettings::OPTION_REGION, array( PlanRegionSettings::class, 'handle_region_change' ), 10, 2 );
	add_action( 'update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_PLAN, array( ZaiAnthropicPlanRegionSettings::class, 'handle_settings_change' ), 10, 2 );
	add_action( 'update_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, array( ZaiAnthropicPlanRegionSettings::class, 'handle_region_change' ), 10, 2 );
	// Fresh-install companions: while no option row exists, core's
	// update_option() delegates to add_option(), which fires
	// add_option_{$option} instead of the update hook — without these
	// registrations the FIRST persisted plan/region change would skip
	// every invalidation the later updates perform.
	add_action( 'add_option_' . PlanRegionSettings::OPTION_PLAN, array( PlanRegionSettings::class, 'handle_plan_add' ), 10, 2 );
	add_action( 'add_option_' . PlanRegionSettings::OPTION_REGION, array( PlanRegionSettings::class, 'handle_region_add' ), 10, 2 );
	add_action( 'add_option_' . ZaiAnthropicPlanRegionSettings::OPTION_PLAN, array( ZaiAnthropicPlanRegionSettings::class, 'handle_plan_add' ), 10, 2 );
	add_action( 'add_option_' . ZaiAnthropicPlanRegionSettings::OPTION_REGION, array( ZaiAnthropicPlanRegionSettings::class, 'handle_region_add' ), 10, 2 );
	add_action( 'update_option_' . DebugLogger::OPTION_ENABLED, array( DebugSettings::class, 'handle_enabled_change' ), 10, 2 );
	// Fresh-install companion for the debug flag too (GLM5 #14): while no
	// option row exists, the first persisted save fires
	// add_option_{option} instead of the update hook — without this
	// registration the log clear the later updates perform was skipped.
	add_action( 'add_option_' . DebugLogger::OPTION_ENABLED, array( DebugSettings::class, 'handle_enabled_add' ), 10, 2 );

	// Plugin-row Settings link (Task 1.8).
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( Plugin::class, 'action_links' ) );
}

boot();
