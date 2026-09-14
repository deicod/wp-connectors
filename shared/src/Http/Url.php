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
	 * The whole URL surface is screened against the shared control-byte
	 * vocabulary FIRST (review round t31-r2-1): parse_url accepts
	 * U+2028/U+2029, the C1 controls riding as valid UTF-8, and the C0
	 * range verbatim, and those bytes reached redacted_url()/__toString()
	 * unfiltered — reopening in the URL position exactly the forged
	 * log-line class the header-value pattern (t31-r1-19) rejects. The
	 * vocabulary is owned once, by HeaderMap (the header-value rule and
	 * the URL rule must not drift); horizontal tab and space stay legal
	 * in a host per the round-1 host-charset adjudication — they render
	 * oddly but forge nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url URL.
	 * @return array{scheme: string, authority: string, path: string} Lower-cased scheme and authority; path defaults to '/'.
	 * @throws InvalidArgumentException When the URL carries control bytes, or is not absolute http(s) with a host and valid port.
	 */
	public static function parse_validated( string $url ): array {
		// Abort-as-reject (glm36-8): a PCRE failure refuses the URL,
		// never passes it.
		if ( 0 !== preg_match( HeaderMap::VALUE_CONTROL_BYTE_PATTERN, $url ) ) {
			throw new InvalidArgumentException( 'The URL must not contain control characters or line breaks — including their UTF-8 spellings (U+2028/U+2029, C1 controls), which forge log lines in the safe debug forms.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the WordPress helper does not exist in this provider-neutral source (WordPress is reached only through ports); parse_url's shape is adequate for constructor validation.
		$parts = parse_url( $url );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw new InvalidArgumentException( 'The URL must be absolute with a scheme and host.' );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			throw new InvalidArgumentException( 'The URL scheme must be http or https.' );
		}

		/*
		 * Review round t31-r4-12: the RAW port segment must be fully
		 * digits before parse_url's port is trusted. parse_url() silently
		 * truncates a malformed port — 'https://host:443x/' parses as
		 * port 443 (reproduced) — while url() still carries ':443x', a
		 * port/authority divergence INSIDE the value object: the debug
		 * forms report :443, the caller holds the raw string. The raw
		 * substring from the authority is validated instead (userinfo
		 * stripped after the last '@'; the port colon is the first ':'
		 * after any IPv6 ']'), and anything not fully digits — ':443x',
		 * ':8a', and the empty ':/' — rejects: the built authority and
		 * the URL the caller holds must agree. The abort-as-reject rule
		 * (glm36-8) rides the same check: a PCRE failure refuses the
		 * URL, never passes it.
		 */
		$after_scheme = (string) substr( $url, (int) strpos( $url, '://' ) + 3 );
		$authority    = (string) substr( $after_scheme, 0, strcspn( $after_scheme, '/?#' ) );
		$at           = strrpos( $authority, '@' );
		$host_port    = false === $at ? $authority : (string) substr( $authority, $at + 1 );
		$bracket_end  = strrpos( $host_port, ']' );
		$colon        = strpos( $host_port, ':', false === $bracket_end ? 0 : (int) $bracket_end + 1 );
		if ( false !== $colon ) {
			$raw_port = (string) substr( $host_port, $colon + 1 );
			if ( 1 !== preg_match( '/\A[0-9]+\z/', $raw_port ) ) {
				throw new InvalidArgumentException( 'The URL port must be digits — parse_url() truncates a malformed port silently (":443x" reads as 443) while the URL string carries the raw text, and the two must agree.' );
			}
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
