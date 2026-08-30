<?php
/**
 * Debug logging settings for the z.ai provider.
 *
 * An option-gated (default OFF) request logger: method, redacted URL,
 * status, duration only — see DebugLogger. This class owns the settings
 * surface: the checkbox on the plugin settings page and the log viewer.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Settings;

use Deicod\WpConnectors\Zai\Support\DebugLogger;

/**
 * Debug settings store and Settings API wiring.
 *
 * @since 0.1.0
 */
final class DebugSettings {

	/**
	 * Registers the debug option with the Settings API.
	 *
	 * Hooked on `admin_init`; shares the zai_connector option group with the
	 * plan/region settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			PlanRegionSettings::OPTION_GROUP,
			DebugLogger::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '0',
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_enabled' ),
			)
		);
	}

	/**
	 * Adds the debug field to the shared settings section.
	 *
	 * Hooked on `admin_menu` after PlanRegionSettings::register_page().
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_fields(): void {
		add_settings_field(
			DebugLogger::OPTION_ENABLED,
			esc_html__( 'Debug logging', 'zai' ),
			array( __CLASS__, 'render_enabled_field' ),
			PlanRegionSettings::PAGE_SLUG,
			PlanRegionSettings::OPTION_GROUP
		);
	}

	/**
	 * Sanitizes the enabled flag to '1' or '0'.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string '1' or '0'.
	 */
	public static function sanitize_enabled( $value ): string {
		return '1' === $value ? '1' : '0';
	}

	/**
	 * Renders the enable/disable checkbox.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_enabled_field(): void {
		printf(
			'<label><input type="checkbox" name="%1$s" id="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( DebugLogger::OPTION_ENABLED ),
			checked( DebugLogger::enabled(), true, false ),
			esc_html__( 'Record z.ai requests (method, endpoint, status, and duration only — never keys, prompts, or responses)', 'zai' )
		);
	}

	/**
	 * Renders the recent log entries (escaped), if any.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$entries = DebugLogger::entries();
		if ( array() === $entries ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Recent z.ai requests', 'zai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Newest last. Disable and save debug logging to clear this list.', 'zai' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'zai' ) . '</th><th>' . esc_html__( 'Method', 'zai' ) . '</th><th>' . esc_html__( 'Endpoint', 'zai' ) . '</th><th>' . esc_html__( 'Status', 'zai' ) . '</th><th>' . esc_html__( 'Duration (ms)', 'zai' ) . '</th></tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['at'] ) ),
				esc_html( (string) $entry['method'] ),
				esc_html( (string) $entry['url'] ),
				esc_html( (string) $entry['status'] ),
				esc_html( (string) $entry['duration_ms'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Clears the log whenever debug logging is saved as disabled.
	 *
	 * Hooked on `update_option_zai_connector_zai_debug`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public static function handle_enabled_change( $old_value, $new_value ): void {
		if ( '1' !== (string) $new_value ) {
			DebugLogger::clear();
		}
	}
}
