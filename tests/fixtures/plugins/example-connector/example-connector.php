<?php
/**
 * Plugin Name:       Example Connector (Test Fixture)
 * Plugin URI:        https://github.com/deicod/wp-connectors
 * Description:       Minimal production-shaped plugin used by the wp-connectors test suite and artifact-builder tests. Not a real connector.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            deicod
 * Author URI:        https://github.com/deicod
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       example-connector
 *
 * @package wp-connectors
 */

declare(strict_types=1);

namespace Deicod\WpConnectors\ExampleConnector;

use WordPress\AiClient\AiClient;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

define( 'EXAMPLE_CONNECTOR_VERSION', '0.1.0' );

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the example provider with the AI Client registry.
 *
 * Idempotent, and a safe no-op when the PHP AI Client SDK is unavailable
 * (WP < 7.0 without the standalone SDK plugin).
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if ( $registry->hasProvider( Provider\ExampleProvider::class ) ) {
		return;
	}

	$registry->registerProvider( Provider\ExampleProvider::class );
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
}

boot();
