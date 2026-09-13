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
	 * Control bytes a header VALUE may not carry: the whole C0 range
	 * except horizontal tab — legal in field values per RFC 7230 — plus
	 * DEL. ANSI escapes, NUL, and vertical tab rendered verbatim into
	 * the safe debug forms otherwise (terminal-injection material).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const VALUE_CONTROL_BYTE_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/';

	/**
	 * Control bytes a header NAME may not carry: the whole C0 range
	 * (tabs are not legal in a token) plus DEL.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_CONTROL_BYTE_PATTERN = '/[\x00-\x1F\x7F]/';

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
	 * @param array<string, mixed> $headers Header map; non-empty string keys, string values free of control bytes (a horizontal tab is legal in a value).
	 * @throws InvalidArgumentException When the header map violates the contract.
	 */
	public function __construct( array $headers = array() ) {
		$seen_lowercase = array();
		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw new InvalidArgumentException( 'Header names must be non-empty strings.' );
			}
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Header values must be strings.' );
			}
			// Control bytes in a header line are injection material: a
			// line break in a NAME forges extra header lines and in a
			// VALUE does the same from the second line on; the remaining
			// control bytes (NUL, vertical tab, ESC, DEL) render verbatim
			// into the safe debug forms. The whole class is rejected at
			// the boundary, so the debug form can never carry it.
			if ( 1 === preg_match( self::NAME_CONTROL_BYTE_PATTERN, $name ) || 1 === preg_match( self::VALUE_CONTROL_BYTE_PATTERN, $value ) ) {
				throw new InvalidArgumentException( 'Header names and values must not contain control characters or line breaks (a horizontal tab is legal in a value).' );
			}
			// Case-variant spellings of one name make every
			// case-insensitive lookup order-dependent — whichever came
			// first wins silently (a 2-second vs 60-second Retry-After
			// diverges on map order). Rejected at construction; a PHP
			// array cannot carry the exact-same-case duplicate at all.
			$lowercase_name = strtolower( $name );
			if ( isset( $seen_lowercase[ $lowercase_name ] ) ) {
				throw new InvalidArgumentException( 'Header names must be unique case-insensitively — two spellings of one name make the lookup order-dependent.' );
			}
			$seen_lowercase[ $lowercase_name ] = $name;
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
