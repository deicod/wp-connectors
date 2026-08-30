<?php
/**
 * Option-gated debug logger for z.ai provider traffic.
 *
 * OFF by default (SPEC §6.2). When enabled, records EXACTLY four facts per
 * request — method, redacted URL, status, duration — plus a timestamp. The
 * API shape itself makes leaks impossible: there is no parameter for headers,
 * keys, prompt bodies, schemas, or response bodies, and the URL is stripped
 * of its query string before storage.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Debug logger with a bounded ring buffer.
 *
 * @since 0.1.0
 */
final class DebugLogger {

	/**
	 * Option name of the on/off switch ('1'/'0', default off).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'zai_connector_zai_debug';

	/**
	 * Option name of the log ring buffer (never autoloaded).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const OPTION_LOG = 'zai_connector_zai_debug_log';

	/**
	 * Maximum entries kept.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 50;

	/**
	 * Status recorded for transport failures (no HTTP response exists).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const STATUS_TRANSPORT_ERROR = 0;

	/**
	 * Whether debug logging is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the option is set to '1'.
	 */
	public static function enabled(): bool {
		return '1' === get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Records one request, if logging is enabled.
	 *
	 * The URL query string is stripped before storage; nothing else about the
	 * request or response is accepted by this API.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method       HTTP method.
	 * @param string $url          Full request URL (its query is discarded).
	 * @param int    $status       Response status code, or 0 for transport failures.
	 * @param float  $duration_ms  Round-trip duration in milliseconds.
	 * @return void
	 */
	public static function log( string $method, string $url, int $status, float $duration_ms ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$entries   = self::entries();
		$entries[] = array(
			'method'      => strtoupper( $method ),
			'url'         => self::redact_url( $url ),
			'status'      => $status,
			'duration_ms' => round( $duration_ms, 1 ),
			'at'          => (int) current_time( 'timestamp' ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		);

		if ( \count( $entries ) > self::MAX_ENTRIES ) {
			$entries = \array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_LOG, $entries, false );
	}

	/**
	 * The stored entries.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{method: string, url: string, status: int, duration_ms: float, at: int}>
	 */
	public static function entries(): array {
		$entries = get_option( self::OPTION_LOG, array() );

		return \is_array( $entries ) ? $entries : array();
	}

	/**
	 * Removes all stored entries.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION_LOG );
	}

	/**
	 * Strips everything after the first '?' of a URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Raw URL.
	 * @return string URL without query parameters.
	 */
	private static function redact_url( string $url ): string {
		$without_query = (string) preg_replace( '/\?.*$/s', '', $url );

		// Defense in depth: if anything credential-shaped still slips into the
		// path, replace it before storage.
		return (string) preg_replace( '/(bearer|token|key|secret|password)=/i', 'redacted=', $without_query );
	}
}
