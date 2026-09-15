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

use Deicod\WpConnectors\Shared\Support\AsciiFold;
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
	 * DEL, plus the UTF-8 spellings of the C1 control code points
	 * (U+0080-U+009F: CSI/NEL terminal-injection material that rides
	 * as VALID UTF-8, indistinguishable from obs-text at the byte-class
	 * level), the Unicode line separators U+2028/U+2029 (log viewers
	 * treat them as line breaks — forged log lines), and the whole
	 * bidi/override control class U+202A-U+202E (LRE/RLE/PDF/LRO/RLO)
	 * and U+2066-U+2069 (LRI/RLI/FSI/PDI) — zero-width direction
	 * controls that render provider-controlled values with reordered
	 * or mirrored appearance, the character-spoofing sibling of the
	 * forged line (review round t31-r2-6) — plus the direction MARKS
	 * U+200E LRM, U+200F RLM, and U+061C ALM (verifier round
	 * t31-r8-5): the same zero-width reorder/mirror material, spelled
	 * BELOW the U+2028-U+202E block (and, for ALM, outside the
	 * U+2xxx plane run entirely), so the r2-6 ranges never saw them.
	 * The ALM arm carries the CORRECT bytes (review round t31-r9-1):
	 * U+061C encodes to UTF-8 as \xD8\x9C, and the r8-5 fix spelled
	 * the arm \xD9\x9C — the encoding of U+065C ARABIC VOWEL SIGN
	 * DOT BELOW, a VISIBLE combining vowel sign (category Mn), not a
	 * bidi control — so the real mark passed while a legitimate
	 * Arabic vowel was falsely refused. Only format controls (Cf)
	 * are banned: U+065C stays legal obs-text like every other
	 * high byte. Other high bytes stay legal as RFC 7230 obs-text
	 * (verifier round t31-r1-19).
	 *
	 * PUBLIC and single-owner beyond the header map itself (review
	 * round t31-r2-1): this constant names the control vocabulary no
	 * SAFE DEBUG FORM may ever render, so Url screens the request
	 * surface against the same bytes — one vocabulary, two surfaces,
	 * no drift between the header-value rule and the URL rule.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const VALUE_CONTROL_BYTE_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\x8E\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xA9]|\xD8\x9C/';

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
	 * PUBLIC and the SINGLE OWNER of the tchar grammar (review round
	 * t31-r2-11): an HTTP method is a token over the same RFC 7230
	 * alphabet, and HttpRequest's METHOD_TOKEN_PATTERN had drifted
	 * into a second verbatim copy with its own anchor spelling (^ vs
	 * \A). The method pattern is a constant-expression alias of this
	 * one now — the grammar lives once, and a future tightening (an
	 * anchor, a character class) cannot diverge between the surfaces.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const NAME_TOKEN_PATTERN = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';

	/**
	 * The folded-name index (lowercase name => [name as given, value]),
	 * in construction order — the ONE structure (review round
	 * t31-r3-14).
	 *
	 * The constructor computes each lowercase name for the
	 * case-insensitive duplicate fence anyway (review round t31-r2-10):
	 * keeping it makes header() one isset probe instead of a rescan
	 * folding every entry again on every lookup (t31-r2-14: the fold
	 * itself is the shared locale-independent AsciiFold). A second,
	 * parallel name-as-given structure used to carry the same pairs —
	 * two readonly copies of one fact, and every future field would
	 * have had to land in both; headers() and rendered_lines() derive
	 * from this index alone (array_column / pair iteration), in
	 * construction order, with the all-digit name's PHP-canonical
	 * integer key emerging exactly as it did from the old structure.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private readonly array $headers_by_lowercase;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $headers Header map; non-empty string keys (an all-digit spelling arrives here as a PHP integer key — the engine coerces canonical digit strings before this loop — and is restored to its string form, the token grammar deciding as ever), string values free of control bytes (a horizontal tab is legal in a value).
	 * @throws InvalidArgumentException When the header map violates the contract.
	 */
	public function __construct( array $headers = array() ) {
		$seen_lowercase = array();
		$by_lowercase   = array();
		foreach ( $headers as $name => $value ) {
			// PHP coerces a canonical digit-string array key ('123') to
			// an int before the loop body sees it — and '0' through '9'
			// are legal RFC 7230 token characters, so an all-digit header
			// name is a legal name the old is_string gate rejected with a
			// misleading message (review round t31-r2-4). The integer is
			// restored to its canonical string spelling and the NAME
			// GRAMMAR decides — nothing about the actual grammar loosens.
			// An array key is only ever int or string, so after the
			// restoration the name is a string; the empty spelling is
			// the one left to reject here.
			if ( is_int( $name ) ) {
				$name = (string) $name;
			}
			if ( '' === $name ) {
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
			$lowercase_name = AsciiFold::lower( $name );
			if ( isset( $seen_lowercase[ $lowercase_name ] ) ) {
				throw new InvalidArgumentException( 'Header names must be unique case-insensitively — two spellings of one name make the lookup order-dependent.' );
			}
			$seen_lowercase[ $lowercase_name ] = $name;
			$by_lowercase[ $lowercase_name ]   = array( $name, $value );
		}

		// The folded index is the ONE structure (t31-r3-14): the
		// name-as-given spelling rides each pair, and an all-digit name
		// re-emerges under its PHP-canonical integer key wherever a PHP
		// array lands it (headers() below rebuilds through array_column,
		// the engine coercing exactly as the old parallel structure did).
		$this->headers_by_lowercase = $by_lowercase;
	}

	/**
	 * The header map as constructed.
	 *
	 * An all-digit name appears under its PHP-canonical integer key (the
	 * engine's array spelling of the same name — unavoidable in a PHP
	 * array, invisible to lookup and render, which both fold through
	 * (string)). Derived from the folded index (t31-r3-14) — one
	 * structure, construction order preserved.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return array_column( $this->headers_by_lowercase, 1, 0 );
	}

	/**
	 * One header value, looked up case-insensitively.
	 *
	 * An isset probe over the folded index the constructor builds
	 * anyway (the duplicate fence's index, kept — review round
	 * t31-r2-10): one fold of the requested name, no rescan of the
	 * map with a fold per entry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name (any case).
	 * @return string|null The value, or null when absent.
	 */
	public function header( string $name ): ?string {
		$folded = AsciiFold::lower( $name );

		return isset( $this->headers_by_lowercase[ $folded ] ) ? $this->headers_by_lowercase[ $folded ][1] : null;
	}

	/**
	 * Safe debug rendering of every header line, in construction order.
	 *
	 * Sensitive header names (the SecretMask vocabulary) render masked;
	 * every other value renders verbatim. This is the ONE render of a
	 * header map — the request and response debug forms embed it, so
	 * the redaction surface cannot drift between them.
	 *
	 * The render is also always VALID UTF-8 (verifier round t31-r8-6,
	 * the r4-13 doctrine on the header surface): a header value legally
	 * carries RFC 7230 obs-text (t31-r1-19), and a Latin-1 value is
	 * obs-text whose bytes are invalid UTF-8 — json_encode() of the
	 * rendered line returned FALSE, the log line dropped rather than
	 * degraded, the exact failure mode r4-13 killed on the URL surface
	 * by rejecting the input. The render seam owes the same outcome
	 * without rejecting the value: well-formed sequences render
	 * verbatim, invalid bytes render percent-encoded
	 * (SecretMask::utf8_for_safe_render(), the render vocabulary's one
	 * owner).
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Rendered 'Name: value' lines, never containing secrets, always valid UTF-8.
	 */
	public function rendered_lines(): array {
		$lines = array();
		foreach ( $this->headers_by_lowercase as [ $name, $value ] ) {
			$rendered = SecretMask::is_sensitive_header_name( $name ) ? SecretMask::mask( $value ) : $value;
			$lines[]  = $name . ': ' . SecretMask::utf8_for_safe_render( $rendered );
		}

		return $lines;
	}
}
