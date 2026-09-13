<?php
/**
 * Immutable HTTP header map (Task 3.1, review round t31-r1).
 *
 * Single owner of everything a header map does for both HTTP value
 * objects: the construction-time validation loop (name/value typing,
 * line-break injection rejection), the case-insensitive single-header
 * lookup, and the redaction-safe render of every line. The request and
 * response debug forms embed rendered_lines(), so the masking decision
 * for a rendered header line lives exactly here (WHAT is sensitive and
 * WHAT a mask looks like remains SecretMask's).
 *
 * Pure value object: constructor-validated, immutable, no environment
 * access.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Http;

use Deicod\WpConnectors\Shared\Support\SecretMask;
use InvalidArgumentException;

/**
 * Immutable, constructor-validated HTTP header map.
 *
 * @since 0.1.0
 */
final class HeaderMap {

	/**
	 * Header lines (name as given => value).
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private readonly array $headers;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $headers Header map; non-empty string keys, string values.
	 * @throws InvalidArgumentException When the header map violates the contract.
	 */
	public function __construct( array $headers = array() ) {
		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw new InvalidArgumentException( 'Header names must be non-empty strings.' );
			}
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Header values must be strings.' );
			}
			// Line breaks in a header line are injection material: in a
			// NAME they forge extra header lines; in a VALUE they do the
			// same from the second line on. Rejected at the boundary, so
			// the debug form can never render a forged line.
			if ( false !== strpos( $name . $value, "\r" ) || false !== strpos( $name . $value, "\n" ) ) {
				throw new InvalidArgumentException( 'Header names and values must not contain line breaks.' );
			}
		}

		$this->headers = $headers;
	}

	/**
	 * The header map exactly as constructed.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * One header value, looked up case-insensitively.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name (any case).
	 * @return string|null The value, or null when absent.
	 */
	public function header( string $name ): ?string {
		foreach ( $this->headers as $header_name => $value ) {
			if ( strtolower( (string) $header_name ) === strtolower( $name ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Safe debug rendering of every header line, in construction order.
	 *
	 * Sensitive header names (the SecretMask vocabulary) render masked;
	 * every other value renders verbatim. This is the ONE render of a
	 * header map — the request and response debug forms embed it, so
	 * the redaction surface cannot drift between them.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Rendered 'Name: value' lines, never containing secrets.
	 */
	public function rendered_lines(): array {
		$lines = array();
		foreach ( $this->headers as $name => $value ) {
			$rendered = SecretMask::is_sensitive_header_name( (string) $name ) ? SecretMask::mask( $value ) : $value;
			$lines[]  = $name . ': ' . $rendered;
		}

		return $lines;
	}
}
