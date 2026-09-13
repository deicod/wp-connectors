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
	 * The RFC 7230 token grammar every header NAME must satisfy
	 * (verifier round t31-r1-16): visible ASCII token characters only.
	 * The masking vocabulary and the duplicate fence both key on the
	 * exact lowercased name, so an off-grammar spelling — a trailing
	 * space, punctuation, a homoglyph — dodges the vocabulary and its
	 * value rendered UNMASKED into the safe debug forms (the security
	 * lens reproduced a full Bearer secret rendering verbatim through
	 * 'Authorization '). The grammar closes the whole class: no C0
	 * controls, no DEL, no C1-as-UTF-8, no separators, no non-ASCII.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_TOKEN_PATTERN = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';

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
			// A header NAME must be an RFC 7230 token: the masking
			// vocabulary and the case-insensitive duplicate fence both
			// key on the exact lowercased name, so any off-grammar
			// spelling would dodge them. Control bytes in a VALUE are
			// injection material — a line break forges header lines,
			// NUL/vertical tab/ESC/DEL render verbatim into the safe
			// debug forms. Both gates read abort-as-reject (glm36-8):
			// the allow-pattern as `1 !==` (no-match or abort refuses),
			// the ban-pattern as `0 !==` (abort refuses).
			if ( 1 !== preg_match( self::NAME_TOKEN_PATTERN, $name ) || 0 !== preg_match( self::VALUE_CONTROL_BYTE_PATTERN, $value ) ) {
				throw new InvalidArgumentException( 'Header names must be RFC 7230 tokens (control characters, whitespace, separators, and non-ASCII spellings are rejected); header values must not contain control characters or line breaks (a horizontal tab is legal in a value).' );
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
