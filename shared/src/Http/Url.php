<?php
/**
 * HTTP URL validation (Task 3.1).
 *
 * Single owner of "an absolute http(s) URL with a host and a valid port"
 * — the rule the request value object and the device-flow verification
 * URI share. Returns the parts the redacted request target needs, so
 * validation and redaction derive from one parse.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Http;

use InvalidArgumentException;

/**
 * Validates absolute http(s) URLs.
 *
 * @since 0.1.0
 */
final class Url {

	/**
	 * Parses and validates an absolute http(s) URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url URL.
	 * @return array{scheme: string, authority: string, path: string} Lower-cased scheme and authority; path defaults to '/'.
	 * @throws InvalidArgumentException When the URL is not absolute http(s) with a host and valid port.
	 */
	public static function parse_validated( string $url ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the WordPress helper does not exist in this provider-neutral source (WordPress is reached only through ports); parse_url's shape is adequate for constructor validation.
		$parts = parse_url( $url );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw new InvalidArgumentException( 'The URL must be absolute with a scheme and host.' );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			throw new InvalidArgumentException( 'The URL scheme must be http or https.' );
		}
		if ( isset( $parts['port'] ) && ( $parts['port'] < 1 || $parts['port'] > 65535 ) ) {
			throw new InvalidArgumentException( 'The URL port is out of range.' );
		}

		$authority = strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$authority .= ':' . (int) $parts['port'];
		}

		return array(
			'scheme'    => $scheme,
			'authority' => $authority,
			'path'      => isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/',
		);
	}
}
