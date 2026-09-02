<?php
/**
 * Uninstall cleanup for Connectors: z.ai.
 *
 * Removes plugin-owned options and transients only (architecture record
 * 0004, rule 4) — for BOTH providers the plugin registers (zai and
 * zai_anthropic), each with its own option names — on multisite from EVERY
 * site: options and transients are per-site (record 0004, rule 2), and a
 * network-activated uninstall runs once in the current site's context, so
 * every other blog keeps its data unless the cleanup switches to it
 * explicitly. The core-owned API key options
 * (connectors_ai_zai_api_key, connectors_ai_zai_anthropic_api_key) are
 * deliberately left for core/the user. Deactivation retains everything.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

/**
 * Removes plugin-owned data from the CURRENT site.
 *
 * @since 0.1.0
 *
 * @return void
 */
function zai_connector_zai_uninstall_site() {
	delete_option( 'zai_connector_zai_plan' );
	delete_option( 'zai_connector_zai_region' );
	delete_option( 'zai_connector_zai_debug' );
	delete_option( 'zai_connector_zai_debug_log' );
	delete_option( 'zai_connector_zai_key_state' );
	delete_option( 'zai_connector_zai_region_pending' );

	delete_option( 'zai_connector_zai_anthropic_plan' );
	delete_option( 'zai_connector_zai_anthropic_region' );
	delete_option( 'zai_connector_zai_anthropic_key_state' );
	delete_option( 'zai_connector_zai_anthropic_region_pending' );

	// Discovery cache transients for every endpoint combination of both
	// surfaces, including the '_miss' negative-cache markers (GLM1 #6).
	foreach ( array( 'coding', 'general' ) as $zai_connector_plan ) {
		foreach ( array( 'intl', 'cn' ) as $zai_connector_region ) {
			$zai_connector_cache_ids = array(
				'zai_connector_zai_models_' . md5( 'zai|' . $zai_connector_plan . '|' . $zai_connector_region ),
				'zai_connector_zai_anthropic_models_' . md5( 'zai_anthropic|' . $zai_connector_plan . '|' . $zai_connector_region ),
			);
			foreach ( $zai_connector_cache_ids as $zai_connector_cache_id ) {
				delete_transient( $zai_connector_cache_id );
				delete_transient( $zai_connector_cache_id . '_miss' );
			}
		}
	}
}

/**
 * Removes plugin-owned data from every site of a multisite network.
 *
 * No-op on single-site installs. Sites are iterated in batches of 100 so a
 * large network is handled the same way, one bounded batch at a time (the
 * deletes are idempotent, so re-reaching the current site after its own
 * cleanup above is harmless). The batches advance through the SUPPORTED
 * `offset` argument (offset = 0, 100, 200, …): WP_Site_Query/get_sites()
 * has no `paged` argument, so paging the query would re-return the first
 * batch forever — later sites would never be cleaned and the loop would
 * never end. The previous blog context is restored after each site.
 *
 * @since 0.1.0
 *
 * @return void
 */
function zai_connector_zai_uninstall_network() {
	if ( ! is_multisite() ) {
		return;
	}

	$zai_connector_batch_size = 100;
	$zai_connector_offset     = 0;
	$zai_connector_found      = 0;

	do {
		$zai_connector_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $zai_connector_batch_size,
				'offset' => $zai_connector_offset,
			)
		);
		$zai_connector_offset  += $zai_connector_batch_size;
		$zai_connector_found    = count( $zai_connector_site_ids );

		foreach ( $zai_connector_site_ids as $zai_connector_site_id ) {
			switch_to_blog( (int) $zai_connector_site_id );
			zai_connector_zai_uninstall_site();
			restore_current_blog();
		}
	} while ( $zai_connector_found === $zai_connector_batch_size );
}

zai_connector_zai_uninstall_site();
zai_connector_zai_uninstall_network();
