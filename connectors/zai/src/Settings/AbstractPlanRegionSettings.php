<?php
/**
 * Plan and region settings shared by the two z.ai providers.
 *
 * Settings API implementation backing one section per provider on the
 * plugin's shared settings page: two enum options (plan: coding|general,
 * region: intl|cn) per provider, sanitized against a strict whitelist with a
 * safe fallback for corrupt values. All behavior lives here and is
 * parameterized by the per-provider constants and class methods each concrete
 * child overrides; the zai and zai_anthropic selections are therefore
 * structurally independent — a write to one provider's options can never
 * change the other's.
 *
 * Region switch semantics (SPEC §3.3): the international (z.ai) and China
 * (bigmodel.cn) accounts are separate, with separate API keys. Switching the
 * region therefore invalidates every piece of plugin-owned credential-derived
 * state (the persisted key-validation state and the model-discovery cache)
 * AND deletes the stored key: the validated-state binding alone cannot gate
 * the connector when the new endpoint's probe is inconclusive (the China
 * /models route is unprobed and 404s — configured-pending), which would send
 * the OLD region's key against the NEW endpoint indefinitely. After the
 * switch no key is stored, so the connector stays not-connected until an
 * admin supplies a key for the new region (plan changes never touch the
 * key; coding and general share one account). Env/constant credentials —
 * which no plugin code can delete — are instead marked pending a
 * DEFINITIVE validation for the new region (see the availability class).
 * Deleting the core-owned key option here is the sanctioned
 * region-switch exception to "plugins never
 * write core-owned options" (architecture record 0004, region-switch
 * implication; plan Tasks 1.2 and 2.1).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Settings;

/**
 * Plan/region settings store and Settings API wiring, per provider.
 *
 * GLM6 #12: every IDENTIFIER constant (option names, section id, label,
 * the SDK-free invalidation identifiers, the cache prefix/scope) is
 * DECLARED BY THE CHILD — this base carries no provider's defaults.
 * A future child that forgets one gets an immediate undefined-constant
 * fatal at its first use (loud), never a silent read/write of the zai
 * provider's options (the runtime-dead-default trap this layout
 * replaced). Only genuinely shared structure lives here: the option
 * group, page slug, enum lists, and the documented defaults.
 *
 * @since 0.2.0
 */
abstract class AbstractPlanRegionSettings {

	/**
	 * Settings option group (also the options.php nonce action suffix),
	 * shared by every provider's section on the plugin's settings page.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'zai_connector';

	/**
	 * Settings page slug used with add_options_page().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'zai-connector';

	/**
	 * Valid plan values (shared order for both providers' dropdowns; the
	 * selected default is marked at render time).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const PLANS = array( 'coding', 'general' );

	/**
	 * Valid region values, default first.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const REGIONS = array( 'intl', 'cn' );

	/**
	 * Default plan.
	 *
	 * Overridden per provider child where live evidence demands it: the
	 * zai_anthropic provider defaults to 'general' (record 0007 — the
	 * coding-surface Messages routes cannot generate as of 2026-08-31).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const DEFAULT_PLAN = 'coding';

	/**
	 * Default region.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const DEFAULT_REGION = 'intl';

	/**
	 * Registers this provider's two options with the Settings API.
	 *
	 * Hooked on `admin_init` per provider. Sanitization is shared with every
	 * other write path (REST, CLI): values outside the enum fall back to the
	 * currently stored value, or the documented default when nothing valid is
	 * stored.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			static::OPTION_PLAN,
			array(
				'type'              => 'string',
				'default'           => static::DEFAULT_PLAN,
				'show_in_rest'      => false,
				'sanitize_callback' => array( static::class, 'sanitize_plan' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			static::OPTION_REGION,
			array(
				'type'              => 'string',
				'default'           => static::DEFAULT_REGION,
				'show_in_rest'      => false,
				'sanitize_callback' => array( static::class, 'sanitize_region' ),
			)
		);
	}

	/**
	 * Registers the settings page under Settings (once) and this provider's
	 * section on it.
	 *
	 * Hooked on `admin_menu` for the provider that owns the page.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_options_page(
			esc_html__( 'z.ai Connector', 'zai' ),
			esc_html__( 'z.ai', 'zai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( static::class, 'render_page' )
		);

		static::register_section();
	}

	/**
	 * Registers this provider's section and fields on the shared page.
	 *
	 * Hooked on `admin_menu` (priority 20 for non-owning providers, so the
	 * owning provider's add_options_page() has run — harmless either way,
	 * since add_settings_section() only records state keyed by page slug).
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function register_section(): void {
		add_settings_section(
			static::SECTION_ID,
			esc_html( static::section_title() ),
			array( static::class, 'render_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			static::OPTION_PLAN,
			esc_html__( 'API plan', 'zai' ),
			array( static::class, 'render_plan_field' ),
			self::PAGE_SLUG,
			static::SECTION_ID
		);
		add_settings_field(
			static::OPTION_REGION,
			esc_html__( 'Account region', 'zai' ),
			array( static::class, 'render_region_field' ),
			self::PAGE_SLUG,
			static::SECTION_ID
		);
	}

	/**
	 * The translated section heading for this provider.
	 *
	 * @since 0.2.0
	 *
	 * @return string Section title.
	 */
	protected static function section_title(): string {
		return sprintf(
			/* translators: %s: provider display label, e.g. 'z.ai'. */
			__( '%s endpoint selection', 'zai' ),
			static::PROVIDER_LABEL
		);
	}

	/**
	 * Renders the settings page (shared by both providers).
	 *
	 * Every provider section registered for the page renders inside the one
	 * form; each provider's selection saves independently.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'z.ai Connector', 'zai' ) . '</h1>';
		echo '<p>' . esc_html__( 'The z.ai API keys themselves are managed on the Connectors screen (Settings → Connectors), one per provider card; this page only selects which z.ai endpoint each provider\'s key is used against.', 'zai' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save Changes', 'zai' ) . '</button></p>';
		echo '</form>';
		DebugSettings::render_log();
		echo '</div>';
	}

	/**
	 * Renders this provider's section description (billing and account distinctions).
	 *
	 * The "(default)" marker follows the provider's own default plan, which
	 * differs between the two providers (coding for zai, general for
	 * zai_anthropic — record 0007).
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function render_section_description(): void {
		$coding  = 'coding' === static::DEFAULT_PLAN
			? __( 'Coding Plan (default): subscription-backed z.ai plan with cheaper or included usage, restricted to coding-suitable GLM models.', 'zai' )
			: __( 'Coding Plan: subscription-backed z.ai plan with cheaper or included usage, restricted to coding-suitable GLM models.', 'zai' );
		$general = 'general' === static::DEFAULT_PLAN
			? __( 'General API (default): pay-as-you-go with the full model catalog.', 'zai' )
			: __( 'General API: pay-as-you-go with the full model catalog.', 'zai' );

		echo '<p>' . esc_html( $coding . ' ' . $general ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Regions use separate accounts and separate API keys.', 'zai' ) . '</strong> ' . esc_html__( 'International keys (z.ai) do not work on the China endpoint (open.bigmodel.cn) and vice versa. After switching the region the connector disconnects until you save a key for the new region on the Connectors screen.', 'zai' ) . '</p>';
	}

	/**
	 * Renders this provider's plan dropdown.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function render_plan_field(): void {
		$current = static::get_plan();
		echo '<select name="' . esc_attr( static::OPTION_PLAN ) . '" id="' . esc_attr( static::OPTION_PLAN ) . '">';
		foreach ( self::PLANS as $plan ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $plan ),
				selected( $plan, $current, false ),
				esc_html( self::plan_label( $plan ) )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders this provider's region dropdown.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function render_region_field(): void {
		$current = static::get_region();
		echo '<select name="' . esc_attr( static::OPTION_REGION ) . '" id="' . esc_attr( static::OPTION_REGION ) . '">';
		foreach ( self::REGIONS as $region ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $region ),
				selected( $region, $current, false ),
				esc_html( self::region_label( $region ) )
			);
		}
		echo '</select>';
	}

	/**
	 * Defense-in-depth guard for saves of this option group.
	 *
	 * Core's options.php already enforces `manage_options` (via the page) and
	 * the group nonce. Two different failure shapes exist in real WordPress:
	 *
	 * - CAPABILITY failure: options.php would wp_die() on its own check, but
	 *   admin_init runs first — this guard strips the plugin's option keys
	 *   from the request immediately, so nothing of ours can be persisted by
	 *   any other write path in the same request. Every provider class hooks
	 *   its own guard, so all providers' keys are stripped together; the
	 *   unauthorized notice is emitted idempotently (GLM2 #8) — the shared
	 *   group means the per-provider guards would otherwise append
	 *   byte-identical errors for one unauthorized save.
	 * - NONCE failure: core's check_admin_referer() terminates the request
	 *   (wp_nonce_ays → wp_die) and never returns, so the nonce is left for
	 *   core to enforce (this guard only verifies it AFTER the capability
	 *   check passed, to keep the strip path reachable).
	 *
	 * Hooked on `admin_init` per provider.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function guard_settings_save(): void {
		/*
		 * GLM3 #8: a form-mangled array POST ('option_page[]=x' from any
		 * logged-in user) must not reach sanitize_key() — core returns ''
		 * for non-scalars there (its is_scalar() guard), but the explicit
		 * is_string() check keeps this guard's own contract independent
		 * of sanitize_key's coercion semantics and of harness stubs (the
		 * test stub previously (string)-casted the value, masking array
		 * inputs entirely). An array option_page is not our settings
		 * form; the early return below ignores the request.
		 */
		$option_page = isset( $_POST['option_page'] ) && \is_string( $_POST['option_page'] )
			? sanitize_key( wp_unslash( $_POST['option_page'] ) )
			: '';
		if ( self::OPTION_GROUP !== $option_page ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			/*
			 * GLM1 #10: strip EVERY option registered under the group, not
			 * just this class's plan/region pair — the plugin registers
			 * more keys to the same group (the shared debug-logging flag),
			 * and any member left in the request could be persisted by
			 * another write path in this same request. The names come from
			 * the group REGISTRATION (the guard hooks run after every
			 * register_settings call), with this class's own pair as a
			 * defensive floor for degenerate early-invocation contexts.
			 */
			$strip = array_merge(
				array( static::OPTION_PLAN, static::OPTION_REGION ),
				self::registered_group_option_names()
			);

			foreach ( array_unique( $strip ) as $option_name ) {
				unset( $_POST[ $option_name ] );
			}

			/*
			 * GLM2 #8: every provider class hooks its own guard on the
			 * SHARED option group, so one unauthorized save reaches this
			 * emission once per provider — appending byte-identical
			 * 'zai_connector_unauthorized' errors. The strip above is
			 * idempotent (unsetting twice is a no-op); the emission checks
			 * for an existing error of the same code and stays silent, so
			 * any path that renders settings errors prints the notice once.
			 *
			 * Verifier round: the read uses get_settings_errors() — the
			 * core GETTER. settings_errors() is a display function that
			 * ECHOES the rendered notices and returns void, so consulting
			 * it here would emit stray markup mid-request and never
			 * deduplicate anything on a real install.
			 */
			foreach ( get_settings_errors( self::OPTION_GROUP ) as $existing_error ) {
				if ( \is_array( $existing_error ) && 'zai_connector_unauthorized' === ( $existing_error['code'] ?? null ) ) {
					return;
				}
			}

			add_settings_error(
				self::OPTION_GROUP,
				'zai_connector_unauthorized',
				__( 'The z.ai endpoint selection was not saved: this form requires an administrator session.', 'zai' )
			);

			return;
		}

		check_admin_referer( self::OPTION_GROUP . '-options' );
	}

	/**
	 * Returns every option name registered under the plugin's option group.
	 *
	 * Read from core's settings registry (`$wp_registered_settings`), so a
	 * future option added to the group is stripped by the guard without a
	 * second hardcoded list to keep in sync (code-review GLM1 #10).
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> Option names registered for OPTION_GROUP.
	 */
	private static function registered_group_option_names(): array {
		global $wp_registered_settings;

		if ( ! \is_array( $wp_registered_settings ) ) {
			return array();
		}

		$names = array();
		foreach ( $wp_registered_settings as $option_name => $registration ) {
			if ( \is_array( $registration ) && self::OPTION_GROUP === ( $registration['group'] ?? null ) ) {
				$names[] = (string) $option_name;
			}
		}

		return $names;
	}

	/**
	 * Invalidates provider-owned credential-derived state after an endpoint switch.
	 *
	 * Hooked on `update_option_{provider plan}` (old value, new value);
	 * the region option is handled by handle_region_change(), which also clears
	 * the stored key. The core-owned key option is deliberately NOT touched
	 * here: plan changes stay on the same account, and the validated state is
	 * bound to the endpoint (Task 1.4), so removing it already forces a fresh
	 * authenticated probe against the new endpoint. The invalidation uses
	 * ONLY this layer's own identifiers (see STATE_OPTION) so it stays
	 * loadable on sites without the SDK plugin.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public static function handle_settings_change( $old_value, $new_value ): void {
		if ( self::option_values_equal( $old_value, $new_value ) ) {
			return;
		}

		/*
		 * Codex R2 #3: every identifier below comes from THIS layer's
		 * constants — no availability or directory class is autoloaded. On
		 * WP 6.9 without the optional PHP AI Client plugin those classes
		 * cannot load at all (they implement missing SDK types) and a
		 * settings save here would otherwise fatal after the option write.
		 *
		 * GLM8 #11: the discovery-cache ids are no longer composed inline
		 * — the endpoint layer owns the formula
		 * (discovery_transient_ids()), and the endpoint classes are
		 * SDK-free loadable (no SDK parent, lazy imports only), so
		 * consulting ENDPOINT_CLASS keeps the SDK-absent guarantee while
		 * removing the mirror whose silent drift stranded stale
		 * transients. The miss suffix rides the owner too
		 * (ZaiDiscoveryCache's exported constant), never a literal.
		 *
		 * GLM8 #15 (verifier round on that change): if the owner chain
		 * cannot LOAD — a quarantined or missing src file on an
		 * otherwise-active install, where the autoloader finds nothing
		 * and the static call raises a class-not-found Error — the sweep
		 * is skipped, never fatal: the option write already happened,
		 * and the state-option deletion above already forces fresh
		 * probes and discovery (the transients are endpoint-scoped and
		 * defensive). Only load-family Errors are caught; a logic bug in
		 * the owner still surfaces loudly.
		 */
		delete_option( static::STATE_OPTION );

		$endpoint_class = static::ENDPOINT_CLASS;

		try {
			foreach ( self::PLANS as $plan ) {
				foreach ( self::REGIONS as $region ) {
					foreach ( $endpoint_class::discovery_transient_ids( $plan, $region ) as $cache_id ) {
						delete_transient( $cache_id );
					}
				}
			}
		} catch ( \Error $e ) {
			// See the GLM8 #15 note above: degrade to skip, never fatal.
			return;
		}
	}

	/**
	 * Whether two hook payloads represent the same option value (GLM5 #9).
	 *
	 * The previous (string) casts equated two DIFFERENT corrupt array
	 * values ('Array' === 'Array') while raising an Array-to-string
	 * conversion warning per side — silently skipping the
	 * state/discovery-cache invalidation a plan change must perform.
	 * Scalars keep the string-cast comparison (the normal payloads are
	 * the enum strings). Any pairing that is not scalar-on-SCALAR —
	 * arrays among them, but also null against '' (null is not a scalar,
	 * where the old cast equated them) — compares by STRICT identity, so
	 * a mixed or distinct pair reads CHANGED (invalidation runs — the
	 * safe direction; a needless invalidation in the degenerate null
	 * case is idempotent) without any string coercion.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $old_value Previous hook payload.
	 * @param mixed $new_value New hook payload.
	 * @return bool True when the values are equivalent.
	 */
	private static function option_values_equal( $old_value, $new_value ): bool {
		if ( \is_scalar( $old_value ) && \is_scalar( $new_value ) ) {
			return (string) $old_value === (string) $new_value;
		}

		return $old_value === $new_value;
	}

	/**
	 * Region-change handler: shared invalidation PLUS the stored-key clear
	 * and the env/constant pending-validation mark.
	 *
	 * Hooked on `update_option_{provider region}` (old value, new value).
	 * Deleting the stored key is required by Tasks 1.2/2.1 ("a region change
	 * MUST NOT silently reuse the previous region's credential"):
	 * clearing only the validated-state verdict is not enough, because an
	 * inconclusive probe against the new region (cn /models 404) reports
	 * configured-pending — the connector would then appear connected and
	 * send the old region's key against the new endpoint indefinitely.
	 *
	 * Env/constant sources cannot be deleted the way the database key can,
	 * so the SAME hole exists for them after the switch: the handler marks
	 * the riding credential pending a DEFINITIVE validation for the new
	 * region (the availability class's REGION_PENDING_OPTION). Until that
	 * exact credential is proven (authenticated 2xx) or rejected (401/403),
	 * availability reports DISCONNECTED — never configured-pending.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $old_value Previous region.
	 * @param mixed $new_value New region.
	 * @return void
	 */
	public static function handle_region_change( $old_value, $new_value ): void {
		/*
		 * Codex R11 #2: compare EFFECTIVE regions, not raw values. A
		 * corrupt stored value ('bogus', '', whitespace, wrong case)
		 * already routes to the default region for every real request —
		 * get_region() normalizes on read — so saving the displayed
		 * default on top of it is NOT a switch: the raw-vs-sanitized
		 * comparison would otherwise delete a perfectly valid credential
		 * whose effective endpoint never changed. Both sides run through
		 * the same normalization get_region() uses.
		 */
		if ( self::effective_region( $old_value ) === self::effective_region( $new_value ) ) {
			return;
		}

		self::handle_settings_change( $old_value, $new_value );

		delete_option( static::KEY_OPTION );

		// Corrupt hook payloads fall back to the sanitized stored region.
		static::mark_region_switch_pending(
			\is_string( $new_value ) && \in_array( $new_value, self::REGIONS, true )
				? $new_value
				: static::get_region()
		);
	}

	/**
	 * Normalizes a raw hook/stored value to its effective region.
	 *
	 * The same allowlist fallback get_region() applies on read: any value
	 * outside REGIONS (corrupt garbage, wrong type, wrong case) routes to
	 * the default region, because that is the endpoint every real request
	 * actually uses with that value stored.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Raw hook or stored option value.
	 * @return string The effective region ('intl' or 'cn').
	 */
	private static function effective_region( $value ): string {
		return self::sanitize_stored( $value, self::REGIONS, self::DEFAULT_REGION );
	}

	/**
	 * Initial region save: the add_option() path of a region change.
	 *
	 * On a fresh install no region row exists yet, so the first persisted
	 * save travels through core's add_option() (update_option() delegates to
	 * it when the row is missing), which fires
	 * `add_option_{provider region}` (option name, value) instead of the
	 * update hook. The effective previous region while no row exists is the
	 * registered default (intl), so the change is measured against it:
	 * persisting the default itself is a no-op, exactly like a same-value
	 * update, while any other value performs the full region-switch
	 * invalidation of handle_region_change().
	 *
	 * @since 0.2.0
	 *
	 * @param string $option Option name (hook contract; the handler knows its option).
	 * @param mixed  $value  New region.
	 * @return void
	 */
	public static function handle_region_add( $option, $value ): void {
		static::handle_region_change( static::DEFAULT_REGION, $value );
	}

	/**
	 * Initial plan save: the add_option() path of a plan change.
	 *
	 * Same mechanism as handle_region_add(), for the plan option: the first
	 * persisted plan save fires `add_option_{provider plan}` instead of the
	 * update hook. The effective previous plan is the provider's registered
	 * default, so the change is measured against that. The invalidation is
	 * defensive (the discovery cache and the availability binding are both
	 * endpoint-scoped, so no stale entry could be consumed anyway), but the
	 * symmetry keeps every first-persisted save on the same code path as
	 * later updates (review finding).
	 *
	 * @since 0.2.0
	 *
	 * @param string $option Option name (hook contract; the handler knows its option).
	 * @param mixed  $value  New plan.
	 * @return void
	 */
	public static function handle_plan_add( $option, $value ): void {
		static::handle_settings_change( static::DEFAULT_PLAN, $value );
	}

	/**
	 * The region-immutable credential ladder: env var, then constant
	 * (GLM7 #17).
	 *
	 * The env→constant resolution sequence existed three times over —
	 * this class's mark_region_switch_pending() and the availability
	 * base's effective_key()/key_source() each hand-rolled it — the
	 * exact duplication pattern that drifts silently when one copy
	 * learns a rule. ONE implementation lives here, in the SDK-free
	 * layer every consumer can reach (Codex R2 #3: the region switch
	 * fires on sites without the SDK plugin, where the availability
	 * class cannot be autoloaded at all).
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The non-empty ladder entries in
	 *                               resolution order ('env' and/or
	 *                               'constant', keyed by source label);
	 *                               empty when neither exists.
	 */
	public static function env_constant_ladder(): array {
		$ladder = array();

		$env_value = getenv( static::KEY_ENV_NAME );
		if ( \is_string( $env_value ) && '' !== $env_value ) {
			$ladder['env'] = $env_value;
		}

		if ( \defined( static::KEY_ENV_NAME ) ) {
			$constant_value = \constant( static::KEY_ENV_NAME );

			if ( \is_string( $constant_value ) && '' !== $constant_value ) {
				$ladder['constant'] = $constant_value;
			}
		}

		return $ladder;
	}

	/**
	 * Marks the region-immutable credential as pending definitive validation.
	 *
	 * Called on a region switch, AFTER the stored (database) key was
	 * deleted: the env var / constant are the sources the plugin cannot
	 * clear, so exactly they would otherwise ride configured-pending
	 * semantics onto the new endpoint. Stores the new region plus a SHA-256
	 * fingerprint of that credential (never the key); when no env/constant
	 * credential exists nothing can ride the switch and any stale flag is
	 * dropped.
	 *
	 * Lives in this SDK-free layer (Codex R2 #3): the region switch fires
	 * on sites without the SDK plugin too, where the availability class
	 * cannot be autoloaded. The availability base delegates here.
	 *
	 * @since 0.2.0
	 *
	 * @param string $region The newly selected region.
	 * @return void
	 */
	public static function mark_region_switch_pending( string $region ): void {
		/*
		 * GLM7 #17: the env→constant resolution rides the shared
		 * env_constant_ladder() (this was the third hand-rolled copy).
		 */
		$ladder     = static::env_constant_ladder();
		$credential = $ladder['env'] ?? $ladder['constant'] ?? '';

		if ( '' === $credential ) {
			delete_option( static::REGION_PENDING_OPTION );

			return;
		}

		update_option(
			static::REGION_PENDING_OPTION,
			array(
				'region'      => $region,
				'fingerprint' => hash( 'sha256', $credential ),
			),
			false
		);
	}

	/**
	 * Returns this provider's effective plan (corrupt values fall back to the default).
	 *
	 * @since 0.2.0
	 *
	 * @return string 'coding' or 'general'.
	 */
	public static function get_plan(): string {
		return self::sanitize_stored( get_option( static::OPTION_PLAN, static::DEFAULT_PLAN ), self::PLANS, static::DEFAULT_PLAN );
	}

	/**
	 * Returns this provider's effective region (corrupt values fall back to the default).
	 *
	 * @since 0.2.0
	 *
	 * @return string 'intl' or 'cn'.
	 */
	public static function get_region(): string {
		return self::sanitize_stored( get_option( static::OPTION_REGION, static::DEFAULT_REGION ), self::REGIONS, static::DEFAULT_REGION );
	}

	/**
	 * Sanitizes a submitted plan value.
	 *
	 * Values outside the enum keep the currently stored plan (or the default
	 * when nothing valid is stored), so garbage input can never retarget the
	 * endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string Sanitized plan.
	 */
	public static function sanitize_plan( $value ): string {
		if ( \is_string( $value ) && \in_array( $value, self::PLANS, true ) ) {
			return $value;
		}

		return static::get_plan();
	}

	/**
	 * Sanitizes a submitted region value.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string Sanitized region.
	 */
	public static function sanitize_region( $value ): string {
		if ( \is_string( $value ) && \in_array( $value, self::REGIONS, true ) ) {
			return $value;
		}

		return static::get_region();
	}

	/**
	 * Human-readable plan label.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plan Plan value.
	 * @return string Label (translatable).
	 */
	public static function plan_label( string $plan ): string {
		if ( 'general' === $plan ) {
			return __( 'General API (pay-as-you-go, full model catalog)', 'zai' );
		}

		return __( 'Coding Plan (subscription, coding-suitable GLM models)', 'zai' );
	}

	/**
	 * Human-readable region label.
	 *
	 * @since 0.2.0
	 *
	 * @param string $region Region value.
	 * @return string Label (translatable).
	 */
	public static function region_label( string $region ): string {
		if ( 'cn' === $region ) {
			return __( 'China (open.bigmodel.cn)', 'zai' );
		}

		return __( 'International (api.z.ai)', 'zai' );
	}

	/**
	 * Normalizes a stored option value against an allowlist with a fallback.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $value          Stored value.
	 * @param array  $allowed_values Allowed values.
	 * @param string $fallback       Value used when the stored value is corrupt.
	 * @return string One of the allowed values.
	 */
	private static function sanitize_stored( $value, array $allowed_values, string $fallback ): string {
		if ( \is_string( $value ) && \in_array( $value, $allowed_values, true ) ) {
			return $value;
		}

		return $fallback;
	}
}
