<?php
/**
 * Uninstall cleanup for Connectors: z.ai.
 *
 * Removes plugin-owned options and transients only (architecture record
 * 0004, rule 4). The core-owned API key option (connectors_ai_zai_api_key)
 * is deliberately left for core/the user. Deactivation retains everything.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

delete_option( 'zai_connector_zai_plan' );
delete_option( 'zai_connector_zai_region' );
delete_option( 'zai_connector_zai_debug' );
delete_option( 'zai_connector_zai_debug_log' );
delete_option( 'zai_connector_zai_key_state' );

// Discovery cache transients for every endpoint combination.
foreach ( array( 'coding', 'general' ) as $zai_connector_plan ) {
	foreach ( array( 'intl', 'cn' ) as $zai_connector_region ) {
		delete_transient( 'zai_connector_zai_models_' . md5( 'zai|' . $zai_connector_plan . '|' . $zai_connector_region ) );
	}
}
