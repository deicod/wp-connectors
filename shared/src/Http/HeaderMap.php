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
use RuntimeException;

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
	 * Arabic vowel was falsely refused. U+065C stays legal obs-text
	 * like every other high byte.
	 *
	 * Verifier round t31-r9-10 (the round-9 verifier pass, adversarially
	 * confirmed): the r9-1 docblock's 'only format controls are banned'
	 * claim was FALSE as coverage — 158 of 170 Cf code points passed,
	 * and among them the invisible bidi-ACTIVE siblings of the banned
	 * marks: U+070F SYRIAC ABBREVIATION MARK (bidi class AL — an
	 * invisible STRONG-RTL character, the exact resolution mechanism of
	 * the banned ALM/RLM; reproduced rendering verbatim through both
	 * safe-debug surfaces), U+110BD/U+110CD/U+13430-U+1343F (bidi class
	 * L — invisible strong-LTR, the LRM mechanism), and
	 * U+0600-U+0605/U+06DD/U+0890/U+0891/U+08E2 (invisible AN,
	 * bidi-active in number runs) — plus the invisible-neutral
	 * homograph class the same pass confirmed: U+200B-U+200D
	 * ZWSP/ZWNJ/ZWJ (ZWJ/ZWNJ alter Arabic GLYPH JOINING — invisible
	 * bytes that change visible rendering, the character-spoofing
	 * channel r2-6 named), U+FEFF, and U+00AD. All banned now.
	 *
	 * The vocabulary is a CURATED BYTE LIST of invisible
	 * direction/joining material — deliberately NOT a Unicode-category
	 * derivation (the r9-1 lesson: category claims drift from bytes).
	 * Visible content stays legal obs-text (verifier round t31-r1-19):
	 * letters, and the visible Mn vowel signs. Invisible material
	 * OUTSIDE the curated list — variation selectors (Mn, glyph-
	 * choosing), the word-joiner/math-invisible run U+2060-U+2064,
	 * the deprecated direction-PROCESSING controls U+206A-U+206F,
	 * U+180E, tag characters, interlinear annotation marks — is a
	 * LEDGERED curation decision (the t31-r2-7 spelling-curated
	 * posture), not covered coverage: do not read this constant as
	 * banning every invisible code point.
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
	const VALUE_CONTROL_BYTE_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F\xAD]|\xE2\x80[\x8B-\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xA9]|\xD8\x9C|\xD8[\x80-\x85]|\xDB\x9D|\xDC\x8F|\xE0\xA2[\x90-\x91]|\xE0\xA3\xA2|\xF0\x91\x82\xBD|\xF0\x91\x83\x8D|\xF0\x93\x90[\xB0-\xBF]|\xEF\xBB\xBF/';

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
	 * The folded-name index (lowercased name => [name as given, value]),
	 * in construction order — the ONE structure (review round
	 * t31-r3-14). An all-digit name maps by its OWN spelling — a digit
	 * string has no case to fold, so its fold is the name itself —
	 * landing under the engine's canonical integer key (t31-ocr14-7:
	 * the ocr10-11 contract the public returns carry, stated at the
	 * property it describes).
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
	 * @var array<int|string, array{0: string, 1: string}> Lowercased name => [name as given, value]; an all-digit name maps by its own spelling (no case-fold exists for digits) under its PHP-canonical integer key.
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
		$by_lowercase = array();
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
			//
			// The fence probes THE ONE FOLDED INDEX ITSELF (t31-r10-7):
			// $seen_lowercase used to parallel $by_lowercase's keys —
			// two copies of one fact, and a future one-sided edit would
			// have silently weakened the fence. The probe is exact: an
			// isset with a canonical digit-string key ('123') coerces
			// to the same int slot the store below lands in, so an
			// all-digit name's fence is the same fence by construction.
			$lowercase_name = AsciiFold::lower( $name );
			if ( isset( $by_lowercase[ $lowercase_name ] ) ) {
				throw new InvalidArgumentException( 'Header names must be unique case-insensitively — two spellings of one name make the lookup order-dependent.' );
			}
			$by_lowercase[ $lowercase_name ] = array( $name, $value );
		}

		// The folded index is the ONE structure (t31-r3-14): the
		// name-as-given spelling rides each pair, and an all-digit name
		// re-emerges under its PHP-canonical integer key wherever a PHP
		// array lands it (headers() below rebuilds through array_column,
		// the engine coercing exactly as the old parallel structure did).
		$this->headers_by_lowercase = $by_lowercase;
	}

	/**
	 * Rejects a value carrying the control-byte vocabulary — the ONE
	 * shared guard the provider-supplied string surfaces ride (review
	 * round t31-r12-5).
	 *
	 * The vocabulary above is public beyond the header map itself
	 * ("one vocabulary, two surfaces" — Url screens the request
	 * surface), and the surfaces kept growing a spelling at a time:
	 * the device-flow codes construct with a raw CRLF and print_r()
	 * forges lines in the MASKED debug tail (the mask keeps the last
	 * four characters, controls included — reproduced), the exact
	 * forged-log-line channel the header-value rule (t31-r1-19), the
	 * URL rule (t31-r2-1), and the dump channel (t31-r11-5) closed
	 * everywhere else. The guard reads the ban-pattern abort-as-reject
	 * (glm36-8): a PCRE failure refuses the value, never passes it.
	 * No new vocabulary — the same curated byte list, one callable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The provider-supplied value about to be stored.
	 * @param string $what  Field label for the rejection ('The device code').
	 * @return void
	 * @throws InvalidArgumentException When the value carries any byte of the control vocabulary.
	 */
	public static function assert_no_control_bytes( string $value, string $what ): void {
		if ( 0 !== preg_match( self::VALUE_CONTROL_BYTE_PATTERN, $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $what is the CALLER's compile-time field label ('The device code'), never provider data; escaping belongs to the display layer (the same posture the response VO's status rejection carries).
			throw new InvalidArgumentException( sprintf( '%s must not contain control characters or line breaks — including their UTF-8 spellings (U+2028/U+2029, C1 controls), which forge log lines in the safe debug forms.', $what ) );
		}
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
	 * @return array<int|string, string> All-digit names surface under their PHP-canonical integer key — the prose above names the behavior; the machine-readable shape now matches it (t31-ocr10-11).
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
			$lines[] = $name . ': ' . $this->rendered_value( $name, $value );
		}

		return $lines;
	}

	/**
	 * The masked map form of the same render — name => safe value, in
	 * construction order (verifier round t31-r11-5).
	 *
	 * The value side is rendered_lines()'s own, by the same owner:
	 * sensitive names (the SecretMask vocabulary) masked, every other
	 * value verbatim-but-safe-for-render. This is the shape the
	 * serialization channel (__debugInfo()) carries — a MAP keys a
	 * debugger view, where rendered_lines()'s 'Name: value' lines serve
	 * the string forms.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int|string, string> Name (as constructed) => safe value, never containing secrets, always valid UTF-8. All-digit names surface under their PHP-canonical integer key (t31-ocr10-11).
	 */
	public function masked_headers(): array {
		$masked = array();
		foreach ( $this->headers_by_lowercase as [ $name, $value ] ) {
			$masked[ $name ] = $this->rendered_value( $name, $value );
		}

		return $masked;
	}

	/**
	 * Safe debug rendering for the serialization channel — print_r(),
	 * var_dump(), and every debugger that walks object properties
	 * (verifier round t31-r11-5).
	 *
	 * The r1 redaction contract enumerated three vectors — the string
	 * cast, the redacted URL, the masked header lines — but not this
	 * one: without __debugInfo() the engine dumps the raw property
	 * tree, and an Authorization value (or a session cookie) renders in
	 * full. The dump mirrors the masked map (masked_headers(), the same
	 * render owner as rendered_lines()), so the two channels cannot
	 * drift.
	 *
	 * Rides the same masked view as __serialize() below (OCR round 2,
	 * t31-ocr2-2) — one vocabulary owner, both channels.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked map, wrapped under 'headers'.
	 */
	public function __debugInfo(): array {
		return $this->masked_view();
	}

	/**
	 * The serialize() channel rides the same masked view (OCR round 2,
	 * t31-ocr2-2).
	 *
	 * Un-hooked, serialize() bypasses __debugInfo() by engine design and
	 * dumps $headers_by_lowercase with the FULL Authorization/Cookie
	 * values — the exact cleartext the dump channel's own doctrine ("the
	 * dump mirrors the masked map so the two channels cannot drift")
	 * forbids, one engine spelling away. The payload is byte-identical
	 * in vocabulary to __debugInfo() above (one view, both hooks), and
	 * the masked view is a SNAPSHOT, not a round-trip payload:
	 * __unserialize() below refuses it — a header map reconstructs
	 * through its constructor, never from a serialization of its own
	 * safe form. The HTTP value objects that EMBED a map delegate their
	 * own __serialize() masking to this owner (HasMaskedHeaders rides
	 * masked_headers(); t31-ocr1-8 / t31-ocr2-2, one doctrine).
	 *
	 * var_export() stays the one channel EXCLUDED by engine design (no
	 * hook exists — the raw dump is display material); its
	 * reconstruction channel, __set_state(), refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked map, wrapped under 'headers'.
	 */
	public function __serialize(): array {
		return $this->masked_view();
	}

	/**
	 * A masked payload is not a reconstruction source — it refuses.
	 *
	 * The safe forms are lossy by design (sensitive values are masked,
	 * so nothing can rebuild a header map from them); unserialize() on
	 * the __serialize() payload throws instead of half-initializing
	 * typed properties against masked fields.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data The masked payload (never a source of truth).
	 * @return never
	 * @throws RuntimeException Always — the masked snapshot is not a round-trip payload.
	 */
	public function __unserialize( array $data ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the payload to the hook; the refusal is the contract, the payload is not read.
		throw new RuntimeException( 'A masked header map is a snapshot, not a round-trip payload — reconstruct through the constructor, never from a serialization of its own safe form.' );
	}

	/**
	 * The var_export() eval channel refuses the same way.
	 *
	 * The var_export() call itself dumps the raw property tree through
	 * no hook (engine design — the one channel the masking contract
	 * cannot ride, named as excluded in this class's docblocks), but the
	 * dump it produces is executable code: evaluating it calls
	 * __set_state(), which refuses — an exported header map never
	 * reconstructs from its own raw dump.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $properties The exported property tree.
	 * @return never
	 * @throws RuntimeException Always — the raw dump is not a reconstruction source.
	 */
	public static function __set_state( array $properties ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the export to the hook; the refusal is the contract, the tree is not read.
		throw new RuntimeException( 'A masked header map cannot be reconstructed from an exported property tree — the raw dump is display material, never a payload.' );
	}

	/**
	 * The masked snapshot the dump and serialize channels render — the
	 * ONE view both hooks ride (OCR round 2, t31-ocr2-2), so the two
	 * channels cannot drift. The value side is masked_headers()'s own
	 * (the one header-render owner).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked map, wrapped under 'headers'.
	 */
	private function masked_view(): array {
		return array( 'headers' => $this->masked_headers() );
	}

	/**
	 * One header value in its safe rendered form — the ONE render
	 * decision both header surfaces ride (verifier round t31-r11-5:
	 * the line form and the map form were one inline expression apart,
	 * a future edit away from drifting).
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Header name (as constructed).
	 * @param string $value Header value (raw).
	 * @return string Masked when the name is sensitive, else verbatim — always valid UTF-8, never a secret.
	 */
	private function rendered_value( string $name, string $value ): string {
		$rendered = SecretMask::is_sensitive_header_name( $name ) ? SecretMask::mask( $value ) : $value;

		return SecretMask::utf8_for_safe_render( $rendered );
	}
}
