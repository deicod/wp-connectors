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
 * explicitly. The negative-cache transients whose names embed a
 * credential-binding hash (the availability probe-miss markers, GLM1 #6)
 * are enumerated by option-name prefix rather than derived (GLM2 #7) —
 * PLUS deleted directly for every name derivable from the still-readable
 * credentials, so a persistent object cache cannot hide them (GLM5 #12).
 * The core-owned API key options
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
	/*
	 * The class-free deletions run FIRST, before any plugin class is
	 * loaded: GLM8 #11's verifier round reproduced this file fataling
	 * with ZERO cleanup calls when the owner chain below could not load
	 * (a quarantined or missing src file on a partially-updated install,
	 * or a same-namespace class already declared by other loaded code) —
	 * every Delete retry aborted identically and the plugin-owned
	 * options survived. The options are deletable in ANY file state, so
	 * nothing may run before them.
	 */
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

	/*
	 * GLM8 #11: the discovery transient ids come from the endpoint
	 * layer's one owner (discovery_transient_ids()) — this file used to
	 * mirror the whole formula literally (prefix + md5(scope|plan|
	 * region) plus the miss suffix), the copy that silently strands
	 * stale transients whenever the composition changes. Every required
	 * file is SDK-free loadable (no SDK parent, lazy imports only), so
	 * requiring them here keeps the uninstall context free of the SDK
	 * plugin; the settings classes come first because the endpoint
	 * children's aliased constants link against them.
	 *
	 * GLM8 #15 (verifier round on that change): the chain loads only
	 * when every file is present and no same-namespace class is already
	 * declared — a require onto a missing file or a declared name is a
	 * fatal. When it cannot load, only the class-derived discovery
	 * sweep below is skipped (those entries are 12h transients); the
	 * probe-miss sweeps further down never need a plugin class.
	 */
	$zai_connector_owner_files = array(
		__DIR__ . '/src/Settings/AbstractPlanRegionSettings.php',
		__DIR__ . '/src/Settings/PlanRegionSettings.php',
		__DIR__ . '/src/Settings/ZaiAnthropicPlanRegionSettings.php',
		__DIR__ . '/src/Endpoints/AbstractZaiEndpoint.php',
		__DIR__ . '/src/Endpoints/ZaiEndpoint.php',
		__DIR__ . '/src/Endpoints/ZaiAnthropicEndpoint.php',
		__DIR__ . '/src/Metadata/ZaiDiscoveryCache.php',
	);

	$zai_connector_owner_classes = array(
		'Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings',
		'Deicod\WpConnectors\Zai\Settings\PlanRegionSettings',
		'Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings',
		'Deicod\WpConnectors\Zai\Endpoints\AbstractZaiEndpoint',
		'Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint',
		'Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint',
		'Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache',
	);

	$zai_connector_owner_ready = true;
	foreach ( $zai_connector_owner_files as $zai_connector_owner_file ) {
		if ( ! is_file( $zai_connector_owner_file ) ) {
			$zai_connector_owner_ready = false;
			break;
		}
	}

	if ( $zai_connector_owner_ready ) {
		/*
		 * Only files whose class is not already declared are required —
		 * a require onto an already-declared name is a redeclare fatal —
		 * and the sweep below runs only when every class of the chain
		 * exists afterwards. The require targets stay literal so the
		 * plugin's self-containment contract keeps holding provably.
		 */
		if ( ! class_exists( 'Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings', false ) ) {
			require_once __DIR__ . '/src/Settings/AbstractPlanRegionSettings.php';
		}
		if ( ! class_exists( 'Deicod\WpConnectors\Zai\Settings\PlanRegionSettings', false ) ) {
			require_once __DIR__ . '/src/Settings/PlanRegionSettings.php';
		}
		if ( ! class_exists( 'Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings', false ) ) {
			require_once __DIR__ . '/src/Settings/ZaiAnthropicPlanRegionSettings.php';
		}
		if ( ! class_exists( 'Deicod\WpConnectors\Zai\Endpoints\AbstractZaiEndpoint', false ) ) {
			require_once __DIR__ . '/src/Endpoints/AbstractZaiEndpoint.php';
		}
		if ( ! class_exists( 'Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint', false ) ) {
			require_once __DIR__ . '/src/Endpoints/ZaiEndpoint.php';
		}
		if ( ! class_exists( 'Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint', false ) ) {
			require_once __DIR__ . '/src/Endpoints/ZaiAnthropicEndpoint.php';
		}
		if ( ! class_exists( 'Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache', false ) ) {
			require_once __DIR__ . '/src/Metadata/ZaiDiscoveryCache.php';
		}

		foreach ( $zai_connector_owner_classes as $zai_connector_owner_class ) {
			if ( ! class_exists( $zai_connector_owner_class, false ) ) {
				$zai_connector_owner_ready = false;
				break;
			}
		}
	}

	// Discovery cache transients for every endpoint combination of both
	// surfaces, including the '_miss' negative-cache markers (GLM1 #6).
	// This file is global-namespace (uninstall context), so the endpoint
	// classes are addressed by their fully-qualified names; skipped
	// entirely when the owner chain above could not load.
	if ( $zai_connector_owner_ready ) {
		$zai_connector_endpoint_class           = \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::class;
		$zai_connector_anthropic_endpoint_class = \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::class;

		foreach ( array( 'coding', 'general' ) as $zai_connector_plan ) {
			foreach ( array( 'intl', 'cn' ) as $zai_connector_region ) {
				$zai_connector_cache_id_pairs = array(
					$zai_connector_endpoint_class::discovery_transient_ids( $zai_connector_plan, $zai_connector_region ),
					$zai_connector_anthropic_endpoint_class::discovery_transient_ids( $zai_connector_plan, $zai_connector_region ),
				);
				foreach ( $zai_connector_cache_id_pairs as $zai_connector_cache_id_pair ) {
					foreach ( $zai_connector_cache_id_pair as $zai_connector_cache_id ) {
						delete_transient( $zai_connector_cache_id );
					}
				}
			}
		}
	}

	/*
	 * Availability probe-miss transients (GLM1 #6): each name embeds an
	 * md5 of the sha256 credential+endpoint BINDING, so the exact keys are
	 * unknowable at uninstall time without the historical API keys — the
	 * rows are ENUMERATED by their option-name prefix instead (GLM2 #7;
	 * the discovery markers above can be derived, these structurally
	 * cannot). delete_transient() also removes each name's matching
	 * _transient_timeout_ row.
	 *
	 * GLM5 #12: the names DERIVABLE at uninstall time are ALSO deleted
	 * directly, through the transients API — the row enumeration below
	 * scans wp_options, which sees nothing when a persistent object cache
	 * (Redis/Memcached) backs transients, so the deterministic markers
	 * survived uninstall with no path that deleted them. Derivable means
	 * the CURRENT credential values still readable here (the env var, the
	 * constant, and the core-owned stored key option — deliberately left
	 * below, hence readable) under every source label that can have
	 * planted a marker, across every plan x region endpoint of both
	 * surfaces; 'runtime' covers rows written before GLM5 #11 normalized
	 * that label into 'database'. Historical keys' markers remain
	 * enumerable only by the option-name sweep.
	 */
	$zai_connector_key_specs = array(
		'zai'           => array(
			'state_option' => 'zai_connector_zai_key_state',
			'scope'        => 'zai',
			'key_option'   => 'connectors_ai_zai_api_key',
			'key_env_name' => 'ZAI_API_KEY',
		),
		'zai_anthropic' => array(
			'state_option' => 'zai_connector_zai_anthropic_key_state',
			'scope'        => 'zai_anthropic',
			'key_option'   => 'connectors_ai_zai_anthropic_api_key',
			'key_env_name' => 'ZAI_ANTHROPIC_API_KEY',
		),
	);

	foreach ( $zai_connector_key_specs as $zai_connector_key_spec ) {
		$zai_connector_current_keys = array();

		$zai_connector_env_value = getenv( $zai_connector_key_spec['key_env_name'] );
		if ( \is_string( $zai_connector_env_value ) && '' !== $zai_connector_env_value ) {
			$zai_connector_current_keys[] = $zai_connector_env_value;
		}

		if ( \defined( $zai_connector_key_spec['key_env_name'] ) ) {
			$zai_connector_constant_value = \constant( $zai_connector_key_spec['key_env_name'] );
			if ( \is_string( $zai_connector_constant_value ) && '' !== $zai_connector_constant_value ) {
				$zai_connector_current_keys[] = $zai_connector_constant_value;
			}
		}

		$zai_connector_stored_value = get_option( $zai_connector_key_spec['key_option'], '' );
		if ( \is_string( $zai_connector_stored_value ) && '' !== $zai_connector_stored_value ) {
			$zai_connector_current_keys[] = $zai_connector_stored_value;
		}

		if ( array() === $zai_connector_current_keys ) {
			continue;
		}

		foreach ( \array_unique( $zai_connector_current_keys ) as $zai_connector_current_key ) {
			foreach ( array( 'coding', 'general' ) as $zai_connector_probe_plan ) {
				foreach ( array( 'intl', 'cn' ) as $zai_connector_probe_region ) {
					$zai_connector_probe_cache_key = $zai_connector_key_spec['scope'] . '|' . $zai_connector_probe_plan . '|' . $zai_connector_probe_region;

					foreach ( array( 'env', 'constant', 'database', 'runtime' ) as $zai_connector_probe_source ) {
						$zai_connector_probe_binding = hash( 'sha256', $zai_connector_probe_source . '|' . $zai_connector_probe_cache_key . '|' . $zai_connector_current_key );
						delete_transient( $zai_connector_key_spec['state_option'] . '_probe_' . md5( $zai_connector_probe_binding ) );
					}
				}
			}
		}
	}

	global $wpdb;

	$zai_connector_probe_prefixes = array(
		'_transient_zai_connector_zai_key_state_probe_',
		'_transient_zai_connector_zai_anthropic_key_state_probe_',
	);

	foreach ( $zai_connector_probe_prefixes as $zai_connector_probe_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot uninstall sweep over rows whose names embed a credential-binding hash (unknowable, hence enumerated); there is no object cache to consult on uninstall and nothing persistent is introduced.
		$zai_connector_probe_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $zai_connector_probe_prefix ) . '%'
			)
		);

		/*
			 * GLM5 #13: real wpdb::get_col() returns NULL (not an empty
			 * array) on a database error — a foreach over null emitted a
			 * warning and silently skipped the probe-miss cleanup while
			 * uninstall reported success. The null coalesce keeps every
			 * OTHER deletion in this uninstall running.
			 */
		foreach ( $zai_connector_probe_names ?? array() as $zai_connector_probe_name ) {
			delete_transient( substr( (string) $zai_connector_probe_name, strlen( '_transient_' ) ) );
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
