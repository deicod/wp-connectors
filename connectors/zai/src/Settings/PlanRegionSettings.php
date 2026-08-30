<?php
/**
 * Plan and region settings for the z.ai provider.
 *
 * Settings API implementation: two enum options (plan: coding|general,
 * region: intl|cn) on a dedicated Settings submenu page, sanitized against a
 * strict whitelist with a safe fallback for corrupt values.
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
 * key; coding and general share one account). Deleting the core-owned key
 * option here is the sanctioned region-switch exception to "plugins never
 * write core-owned options" (architecture record 0004, region-switch
 * implication; plan Task 1.2).
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Settings;

use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;

/**
 * Plan/region settings store and Settings API wiring.
 *
 * @since 0.1.0
 */
final class PlanRegionSettings {

	/**
	 * Option name: API plan (coding subscription or general pay-as-you-go).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_PLAN = 'zai_connector_zai_plan';

	/**
	 * Option name: account region (international or China).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_REGION = 'zai_connector_zai_region';

	/**
	 * Settings option group (also the options.php nonce action suffix).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_GROUP = 'zai_connector';

	/**
	 * Settings page slug used with add_options_page().
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'zai-connector';

	/**
	 * Valid plan values, default first.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const PLANS = array( 'coding', 'general' );

	/**
	 * Valid region values, default first.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const REGIONS = array( 'intl', 'cn' );

	/**
	 * Default plan.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_PLAN = 'coding';

	/**
	 * Default region.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_REGION = 'intl';

	/**
	 * Registers the two options with the Settings API.
	 *
	 * Hooked on `admin_init`. Sanitization is shared with every other write
	 * path (REST, CLI): values outside the enum fall back to the currently
	 * stored value, or the documented default when nothing valid is stored.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_PLAN,
			array(
				'type'              => 'string',
				'default'           => self::DEFAULT_PLAN,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_plan' ),
			)
		);
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_REGION,
			array(
				'type'              => 'string',
				'default'           => self::DEFAULT_REGION,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_region' ),
			)
		);
	}

	/**
	 * Registers the settings page under Settings and its section/fields.
	 *
	 * Hooked on `admin_menu`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_options_page(
			esc_html__( 'z.ai Connector', 'zai' ),
			esc_html__( 'z.ai', 'zai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_settings_section(
			self::OPTION_GROUP,
			esc_html__( 'z.ai endpoint selection', 'zai' ),
			array( __CLASS__, 'render_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_PLAN,
			esc_html__( 'API plan', 'zai' ),
			array( __CLASS__, 'render_plan_field' ),
			self::PAGE_SLUG,
			self::OPTION_GROUP
		);
		add_settings_field(
			self::OPTION_REGION,
			esc_html__( 'Account region', 'zai' ),
			array( __CLASS__, 'render_region_field' ),
			self::PAGE_SLUG,
			self::OPTION_GROUP
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'z.ai Connector', 'zai' ) . '</h1>';
		echo '<p>' . esc_html__( 'The z.ai API key itself is managed on the Connectors screen (Settings → Connectors); this page only selects which z.ai endpoint that key is used against.', 'zai' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save Changes', 'zai' ) . '</button></p>';
		echo '</form>';
		DebugSettings::render_log();
		echo '</div>';
	}

	/**
	 * Renders the section description (billing and account distinctions).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_section_description(): void {
		echo '<p>' . esc_html__( 'Coding Plan (default): subscription-backed z.ai plan with cheaper or included usage, restricted to coding-suitable GLM models. General API: pay-as-you-go with the full model catalog.', 'zai' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Regions use separate accounts and separate API keys.', 'zai' ) . '</strong> ' . esc_html__( 'International keys (z.ai) do not work on the China endpoint (open.bigmodel.cn) and vice versa. After switching the region the connector disconnects until you save a key for the new region on the Connectors screen.', 'zai' ) . '</p>';
	}

	/**
	 * Renders the plan dropdown.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_plan_field(): void {
		$current = self::get_plan();
		echo '<select name="' . esc_attr( self::OPTION_PLAN ) . '" id="' . esc_attr( self::OPTION_PLAN ) . '">';
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
	 * Renders the region dropdown.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_region_field(): void {
		$current = self::get_region();
		echo '<select name="' . esc_attr( self::OPTION_REGION ) . '" id="' . esc_attr( self::OPTION_REGION ) . '">';
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
	 *   any other write path in the same request.
	 * - NONCE failure: core's check_admin_referer() terminates the request
	 *   (wp_nonce_ays → wp_die) and never returns, so the nonce is left for
	 *   core to enforce (this guard only verifies it AFTER the capability
	 *   check passed, to keep the strip path reachable).
	 *
	 * Hooked on `admin_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function guard_settings_save(): void {
		$option_page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
		if ( self::OPTION_GROUP !== $option_page ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			unset( $_POST[ self::OPTION_PLAN ], $_POST[ self::OPTION_REGION ] );

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
	 * Invalidates plugin-owned credential-derived state after an endpoint switch.
	 *
	 * Hooked on `update_option_zai_connector_zai_plan` (old value, new value);
	 * the region option is handled by handle_region_change(), which also clears
	 * the stored key. The core-owned key option is deliberately NOT touched
	 * here: plan changes stay on the same account, and the validated state is
	 * bound to the endpoint (Task 1.4), so removing it already forces a fresh
	 * authenticated probe against the new endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public static function handle_settings_change( $old_value, $new_value ): void {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}

		delete_option( 'zai_connector_zai_key_state' );

		// The discovery caches are endpoint-scoped by key (and the SDK-level
		// cache key is endpoint-scoped too), but clear the transients anyway
		// so no stale entry survives an unexpected cache-key collision.
		foreach ( self::PLANS as $plan ) {
			foreach ( self::REGIONS as $region ) {
				delete_transient( 'zai_connector_zai_models_' . md5( 'zai|' . $plan . '|' . $region ) );
			}
		}
	}

	/**
	 * Region-change handler: shared invalidation PLUS the stored-key clear.
	 *
	 * Hooked on `update_option_zai_connector_zai_region` (old value, new
	 * value). Deleting the stored key is required by Task 1.2 ("a region
	 * change MUST NOT silently reuse the previous region's credential"):
	 * clearing only the validated-state verdict is not enough, because an
	 * inconclusive probe against the new region (cn /models 404) reports
	 * configured-pending — the connector would then appear connected and
	 * send the old region's key against the new endpoint indefinitely.
	 * Env/constant sources are deliberately untouched; a definitive
	 * rejection of those still arrives through the revalidation probe.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old_value Previous region.
	 * @param mixed $new_value New region.
	 * @return void
	 */
	public static function handle_region_change( $old_value, $new_value ): void {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}

		self::handle_settings_change( $old_value, $new_value );

		delete_option( ZaiProviderAvailability::KEY_OPTION );
	}

	/**
	 * Returns the effective plan (corrupt values fall back to the default).
	 *
	 * @since 0.1.0
	 *
	 * @return string 'coding' or 'general'.
	 */
	public static function get_plan(): string {
		return self::sanitize_stored( get_option( self::OPTION_PLAN, self::DEFAULT_PLAN ), self::PLANS, self::DEFAULT_PLAN );
	}

	/**
	 * Returns the effective region (corrupt values fall back to the default).
	 *
	 * @since 0.1.0
	 *
	 * @return string 'intl' or 'cn'.
	 */
	public static function get_region(): string {
		return self::sanitize_stored( get_option( self::OPTION_REGION, self::DEFAULT_REGION ), self::REGIONS, self::DEFAULT_REGION );
	}

	/**
	 * Sanitizes a submitted plan value.
	 *
	 * Values outside the enum keep the currently stored plan (or the default
	 * when nothing valid is stored), so garbage input can never retarget the
	 * endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string Sanitized plan.
	 */
	public static function sanitize_plan( $value ): string {
		if ( \is_string( $value ) && \in_array( $value, self::PLANS, true ) ) {
			return $value;
		}

		return self::get_plan();
	}

	/**
	 * Sanitizes a submitted region value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string Sanitized region.
	 */
	public static function sanitize_region( $value ): string {
		if ( \is_string( $value ) && \in_array( $value, self::REGIONS, true ) ) {
			return $value;
		}

		return self::get_region();
	}

	/**
	 * Human-readable plan label.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @param mixed  $value         Stored value.
	 * @param array  $allowed_values Allowed values.
	 * @param string $fallback      Value used when the stored value is corrupt.
	 * @return string One of the allowed values.
	 */
	private static function sanitize_stored( $value, array $allowed_values, string $fallback ): string {
		if ( \is_string( $value ) && \in_array( $value, $allowed_values, true ) ) {
			return $value;
		}

		return $fallback;
	}
}
