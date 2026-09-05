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
	 *
	 * GLM10 #14: the seven owner class=>file pairs, previously stated
	 * THREE times over (the file-existence list, the seven literal
	 * class_exists+require_once ifs, the class re-check list), ride ONE
	 * map now — adding or renaming an owner edits one line, not three
	 * lockstep listings (the exact silent-strand drift the file's own
	 * GLM8 #11/GLM9 #8 comments document for drifted name formulas).
	 * The two phases preserve the fatal-avoidance ordering exactly:
	 * every file is verified BEFORE anything is required, then only
	 * not-yet-declared classes are required (a require onto a declared
	 * name is a redeclare fatal) and every class is re-checked after
	 * its load attempt. The require targets stay literal so the
	 * plugin's self-containment contract keeps holding provably.
	 */
	$zai_connector_owner_chain = array(
		'Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings' => __DIR__ . '/src/Settings/AbstractPlanRegionSettings.php',
		'Deicod\WpConnectors\Zai\Settings\PlanRegionSettings' => __DIR__ . '/src/Settings/PlanRegionSettings.php',
		'Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings' => __DIR__ . '/src/Settings/ZaiAnthropicPlanRegionSettings.php',
		'Deicod\WpConnectors\Zai\Endpoints\AbstractZaiEndpoint' => __DIR__ . '/src/Endpoints/AbstractZaiEndpoint.php',
		'Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint' => __DIR__ . '/src/Endpoints/ZaiEndpoint.php',
		'Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint' => __DIR__ . '/src/Endpoints/ZaiAnthropicEndpoint.php',
		'Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache' => __DIR__ . '/src/Metadata/ZaiDiscoveryCache.php',
	);

	$zai_connector_owner_ready = true;
	foreach ( $zai_connector_owner_chain as $zai_connector_owner_file ) {
		if ( ! is_file( $zai_connector_owner_file ) ) {
			$zai_connector_owner_ready = false;
			break;
		}
	}

	if ( $zai_connector_owner_ready ) {
		foreach ( $zai_connector_owner_chain as $zai_connector_owner_class => $zai_connector_owner_file ) {
			if ( ! class_exists( $zai_connector_owner_class, false ) ) {
				require_once $zai_connector_owner_file;
			}

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
	 *
	 * GLM9 #8: the composition comes from the ONE owner — the settings
	 * layer's probe_miss_transient_ids(), the same formula the
	 * availability writer's binding() delegates to. This file used to
	 * hand-mirror the whole thing (the sha256 over source|cache-key|key,
	 * the four-label source set, the state-option constants), and a
	 * composition change without the lockstep edit left the hashed
	 * markers surviving uninstall on object-cache installs — GLM5 #11's
	 * label split already forced one such edit here. Gated on the owner
	 * chain like the discovery sweep above: when it cannot load, only the
	 * class-free prefix enumeration below runs, and the markers are 60s
	 * transients, so the residual window is bounded.
	 */
	if ( $zai_connector_owner_ready ) {
		$zai_connector_settings_classes = array(
			\Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::class,
			\Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::class,
		);

		foreach ( $zai_connector_settings_classes as $zai_connector_settings_class ) {
			/*
			 * glm13-15: the env/constant credential collection rides the
			 * settings owner's ONE env_constant_ladder() — this file's
			 * hand-rolled getenv()/defined() rungs were the fourth copy of
			 * the ladder body (the very duplication that function was
			 * extracted to remove), inside a branch where the owner class
			 * is already loaded and already being called. When the ladder
			 * learns a rule in the owner, this sweep keeps deriving the
			 * same names instead of silently keeping the old semantics —
			 * the failure mode that stranded hashed markers here twice
			 * (GLM5 #11, GLM9 #8). The database rung stays local: it is
			 * core-owned option state, not ladder resolution, and the key
			 * option is deliberately still readable at uninstall time.
			 */
			$zai_connector_current_keys = array();

			foreach ( $zai_connector_settings_class::env_constant_ladder() as $zai_connector_ladder_key ) {
				$zai_connector_current_keys[] = $zai_connector_ladder_key;
			}

			$zai_connector_stored_value = get_option( $zai_connector_settings_class::KEY_OPTION, '' );
			if ( \is_string( $zai_connector_stored_value ) && '' !== $zai_connector_stored_value ) {
				$zai_connector_current_keys[] = $zai_connector_stored_value;
			}

			foreach ( \array_unique( $zai_connector_current_keys ) as $zai_connector_current_key ) {
				foreach ( $zai_connector_settings_class::probe_miss_transient_ids( $zai_connector_current_key ) as $zai_connector_probe_name ) {
					delete_transient( $zai_connector_probe_name );
				}
			}
		}
	}

	global $wpdb;

	/*
	 * glm15-9: the marker-name prefixes ride the settings owner's ONE
	 * export (probe_miss_transient_prefix()) whenever the owner chain
	 * loaded above — this file's two hand-mirrored literals were the
	 * same mirror class that stranded hashed markers twice (GLM5 #11,
	 * GLM9 #8): a STATE_OPTION rename or a third surface's prefix added
	 * to settings but missed here left 60s credential-binding transients
	 * surviving uninstall silently. On the broken install (chain did
	 * not load) the historical literals remain as the bounded fallback,
	 * exactly like the discovery sweep's degradation above: the
	 * deterministic deletions already ran through the owner or not at
	 * all, and the residual window is the marker's own 60s TTL.
	 */
	$zai_connector_probe_prefixes = $zai_connector_owner_ready
		? array()
		: array(
			'_transient_zai_connector_zai_key_state_probe_',
			'_transient_zai_connector_zai_anthropic_key_state_probe_',
		);

	if ( $zai_connector_owner_ready ) {
		foreach (
			array(
				\Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::class,
				\Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::class,
			) as $zai_connector_settings_class
		) {
			$zai_connector_probe_prefixes[] = '_transient_' . $zai_connector_settings_class::probe_miss_transient_prefix();
		}
	}

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
