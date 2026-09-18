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

use Deicod\WpConnectors\Shared\Support\AsciiFold;
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
	 * The whole URL must be VALID UTF-8 first (review round t31-r4-13):
	 * the control-byte screen below bans the UTF-8 SPELLINGS of the
	 * C1/bidi vocabulary, but a lone RAW byte (0x85/0x9B) is invalid
	 * UTF-8 the pattern cannot see — it rode parse_url verbatim into the
	 * safe debug forms, and json_encode() of the log line then returned
	 * false (the t31-r1-6 failure mode: the line is dropped, not
	 * degraded). With the whole URL valid UTF-8, the raw and the encoded
	 * spellings collapse — a raw C1 byte cannot appear outside a
	 * multibyte sequence, and every multibyte spelling the vocabulary
	 * can ride is banned by the shared pattern.
	 *
	 * The whole URL surface is screened against the shared control-byte
	 * vocabulary SECOND (review round t31-r2-1): parse_url accepts
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
	 * @throws InvalidArgumentException When the URL is not valid UTF-8, carries control bytes, or is not absolute http(s) with a host and valid port.
	 */
	public static function parse_validated( string $url ): array {
		/*
		 * Valid UTF-8 for the whole URL (t31-r4-13). The empty pattern
		 * with the /u modifier is the cheap total probe: it matches
		 * every valid UTF-8 subject and returns false (PCRE's
		 * bad-UTF-8 error) on any invalid one — 1 !== covers both the
		 * no-match and the abort, the abort-as-reject rule (glm36-8).
		 */
		if ( 1 !== preg_match( '//u', $url ) ) {
			throw new InvalidArgumentException( 'The URL must be valid UTF-8 — a raw control byte rides parse_url verbatim into the safe debug forms and makes their json_encode fail outright (the log line is dropped, not degraded).' );
		}

		/*
		 * The screen rides HeaderMap's own callable (t31-ocr18-4): the
		 * predicate and its rejection sentence had grown here as a
		 * verbatim twin of assert_no_control_bytes() — the drift seam
		 * the one-owner doctrine exists to close. The URL surface
		 * passes its field label; the abort-as-reject rule (glm36-8)
		 * lives in the owner.
		 */
		HeaderMap::assert_no_control_bytes( $url, 'The URL' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the WordPress helper does not exist in this provider-neutral source (WordPress is reached only through ports); parse_url's shape is adequate for constructor validation.
		$parts = parse_url( $url );

		/*
		 * The empty-host spelling is refused EXPLICITLY (OCR round 1,
		 * t31-ocr1-2): parse_url()'s answer for 'http://:8080/' is
		 * build-dependent — some builds in the supported floor return
		 * host => '' (the key PRESENT but empty), where isset() passes
		 * and a hostless authority would construct ('http://user@:8080/'
		 * likewise); this build returns false outright. The explicit ''
		 * leg refuses the spelling on every build — a host of zero
		 * bytes is no host, whatever spelling the engine hands back.
		 */
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			throw new InvalidArgumentException( 'The URL must be absolute with a scheme and host.' );
		}
		// The scheme fold is the LOCALE-INDEPENDENT byte table's (OCR
		// round 1, t31-ocr1-4): the ONE fold owner every case-insensitive
		// surface rides — never the engine strtolower(), whose byte
		// mapping is a question about the engine and the process locale
		// (glibc's tr_TR.ISO-8859-9 maps tolower('I') to the dotless ı,
		// 0xFD — probed at the libc level on this host), while the byte
		// table has no locale to consult and is identical everywhere by
		// construction.
		$scheme = AsciiFold::lower( (string) $parts['scheme'] );
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

		/*
		 * The scheme separator is probed before it is used (OCR round 4,
		 * t31-ocr4-4): every sibling position probe in this file is
		 * false !== first — this one coerced, and (int) false is 0, so a
		 * schemeless spelling would have judged the authority math from
		 * the string's first byte instead of refusing. The arm is
		 * unreachable by construction (the scheme check above passed,
		 * and parse_url() yields a scheme only for the 'scheme://'
		 * spelling), but the file's own doctrine (t31-ocr1-2) refuses
		 * to lean on build-dependent invariants the surrounding code
		 * does not re-establish — so the invariant is named here, not
		 * assumed.
		 */
		$scheme_separator = strpos( $url, '://' );
		if ( false === $scheme_separator ) {
			throw new InvalidArgumentException( 'The URL must be absolute with a scheme and host.' );
		}
		$after_scheme = (string) substr( $url, $scheme_separator + 3 );
		$authority    = (string) substr( $after_scheme, 0, strcspn( $after_scheme, '/?#' ) );
		$at           = strrpos( $authority, '@' );
		$host_port    = false === $at ? $authority : (string) substr( $authority, $at + 1 );
		$bracket_end  = strrpos( $host_port, ']' );

		/*
		 * The PAIR leg of the bracket screen (review round t31-r12-2,
		 * tightened by its verifier round t31-r12-18): the glued-bracket
		 * rule below trusts the last ']' as the IPv6 closer without ever
		 * asking whether an OPENER exists — and parse_url() misreads
		 * every bracket-bearing authority it should not accept.
		 * Reproduced: 'http://host:44x]/p' was accepted with authority
		 * 'host:44' (the port TRUNCATED at the raw ']');
		 * 'http://example.com:8080]/x' constructed with url() carrying
		 * ':8080]' while the redacted form dropped the bracket — a value
		 * object internally inconsistent; bare 'a]' and ']]]' passed the
		 * bracket verbatim into the authority. The rule is the RFC 3986
		 * host grammar: an IP-literal bracket pair WRAPS THE WHOLE HOST —
		 * exactly one '[' at the authority-host's first byte, exactly one
		 * ']' closing a NON-EMPTY literal (the verifier round closed the
		 * under-enforcement: the empty literal '[]' and mid-host pairs
		 * 'a[b]'/'x[y]:8080' constructed while the refusal claimed the
		 * IPv6-literal shape). Anything else refuses loudly, per the same
		 * doctrine as the glued leg: a malformed bracket authority is a
		 * shape no client means to send, and the URL string and the
		 * rebuilt authority must agree.
		 */
		$bracket_opens            = substr_count( $host_port, '[' );
		$bracket_closes           = substr_count( $host_port, ']' );
		$bracket_open             = strpos( $host_port, '[' );
		$well_formed_bracket_pair = 1 === $bracket_opens
			&& 1 === $bracket_closes
			&& 0 === $bracket_open
			&& false !== $bracket_end
			&& $bracket_end > 1;
		if ( ( $bracket_opens + $bracket_closes ) > 0 && ! $well_formed_bracket_pair ) {
			throw new InvalidArgumentException( 'The URL authority may carry brackets only as one well-formed IP literal wrapping the whole host ("[::1]:443") — a "]" without its matching "[", a second bracket of either kind, an empty literal ("[]"), or brackets around part of the host ("a[b]") is a malformed authority parse_url() misreads (a raw "]" truncated "http://host:44x]/p" to port 44) while the URL string carries the raw text, and the two must agree.' );
		}

		/*
		 * The CONTENT leg of the same screen (OCR round 2, t31-ocr2-5):
		 * the pair leg validates placement and pairing, never the
		 * literal itself — 'http://[abc]/x' passed every screen,
		 * parse_url() returned host '[abc]', and the value object
		 * constructed with an authority that is NOT an IPv6 literal,
		 * contradicting the refusal message's own claim ("one
		 * well-formed IP literal"). The rule enforces its own words
		 * now: the bracketed literal must be a well-formed IPv6
		 * address (the engine's FILTER_VALIDATE_IP probe on the inner
		 * literal — brackets off), so a bracket spelling that merely
		 * looks like an IP literal refuses exactly like its malformed
		 * siblings.
		 */
		if ( $well_formed_bracket_pair && false === filter_var( (string) substr( $host_port, 1, (int) $bracket_end - 1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			throw new InvalidArgumentException( 'A bracketed host must be a well-formed IPv6 address ("[2001:db8::1]") — the bracket shape alone does not make an IP literal, and an authority like "[abc]" is a malformed host no client means to send.' );
		}

		/*
		 * The glued-authority leg of the same screen (verifier round
		 * t31-r11-3, generalized by t31-r11-11): the colon search
		 * starts AFTER the closing ']', so anything glued straight to
		 * the bracket — 'http://[::1]80/' first, then (one
		 * character-class away, verifier-reproduced) ']' plus a letter,
		 * a space, punctuation: 159 visible-ASCII spellings — never
		 * meets the digit check, and parse_url() misreads every one of
		 * them identically (host '[:', port 1), so the rebuilt or
		 * redacted authority diverges from the raw URL exactly the way
		 * t31-r4-12 chartered this screen to kill. The rule is the
		 * authority grammar whole: after the last ']' comes a ':' or
		 * the end of the authority — read as the allow form (1 !==,
		 * abort-refusing per glm36-8), so anything else rejects. The
		 * spelling is malformed (a bracket authority carries its port
		 * only after a ':'), and reject is the safer doctrine for a
		 * shape no client means to send.
		 */
		if ( false !== $bracket_end && 1 !== preg_match( '/\A\](?::|\z)/', substr( $host_port, (int) $bracket_end ) ) ) {
			throw new InvalidArgumentException( 'A bracketed host must be followed by a colon port ("[::1]:8080") or the end of the authority — anything glued to the closing bracket ("[::1]8080", "[::1]x") is a malformed authority parse_url() misreads, and the URL string and the rebuilt authority must agree.' );
		}
		$colon = strpos( $host_port, ':', false === $bracket_end ? 0 : (int) $bracket_end + 1 );
		if ( false !== $colon ) {
			$raw_port = (string) substr( $host_port, $colon + 1 );
			if ( 1 !== preg_match( '/\A[0-9]+\z/', $raw_port ) ) {
				throw new InvalidArgumentException( 'The URL port must be digits — parse_url() truncates a malformed port silently (":443x" reads as 443) while the URL string carries the raw text, and the two must agree.' );
			}
		}

		/*
		 * The ONE split (OCR round 25, t31-ocr25-1): userinfo ends at
		 * the LAST '@' — the WHATWG/curl split the raw screen above
		 * already derives ($host_port) — and the REBUILT authority now
		 * rides that same derivation, never parse_url()'s host/port
		 * answers. The two paths could split a multi-'@' authority
		 * differently on an engine whose parse_url() ends userinfo at
		 * the FIRST '@' (the finding's premise), and the rebuilt
		 * authority would then name a host the transport never
		 * contacts. Probed on this engine (PHP 8.5.10, zend_memrchr):
		 * parse_url() is itself a last-'@' splitter and the two
		 * derivations agreed over a 30,000-shape battery with zero
		 * divergence — but per this file's own t31-ocr1-2 doctrine a
		 * build-dependent parse_url() answer is never leaned on where
		 * the code can re-establish the invariant itself: the rebuild
		 * derives from the raw segment the screens already judged, so
		 * raw and rebuilt agree BY CONSTRUCTION, whatever the engine.
		 * An empty derivation (a userinfo-only authority — the
		 * 'user@@host-less' collapse) refuses with the entry screen's
		 * own sentence: a host of zero bytes is no host on every build.
		 */
		$raw_host = false !== $bracket_end
			? (string) substr( $host_port, 0, (int) $bracket_end + 1 )
			: ( false !== $colon ? (string) substr( $host_port, 0, $colon ) : $host_port );
		if ( '' === $raw_host ) {
			throw new InvalidArgumentException( 'The URL must be absolute with a scheme and host.' );
		}
		$raw_port_int = null;
		if ( false !== $colon ) {
			$raw_port_int = (int) $raw_port;
		}
		if ( null !== $raw_port_int && ( $raw_port_int < 1 || $raw_port_int > 65535 ) ) {
			throw new InvalidArgumentException( 'The URL port is out of range.' );
		}

		// The host fold rides the same ONE owner (t31-ocr1-4): a host
		// folds by the ASCII byte table in every locale, and the rebuilt
		// authority below re-checks that nothing between the parse and
		// this fold mangled the bytes.
		$authority = AsciiFold::lower( $raw_host );
		if ( null !== $raw_port_int ) {
			$authority .= ':' . $raw_port_int;
		}

		self::assert_authority_still_valid_utf8( $authority );

		return array(
			'scheme'    => $scheme,
			'authority' => $authority,
			'path'      => isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/',
		);
	}

	/**
	 * Post-parse re-validation of the rebuilt authority (review round
	 * t31-r12-8, the noted-class hardening that kills the class for
	 * three lines).
	 *
	 * The whole-URL UTF-8 probe at entry guarantees the INPUT bytes;
	 * this re-check guarantees the OUTPUT side — the parsed host plus
	 * the case fold — never mangles them. The fold is AsciiFold's byte
	 * table (t31-ocr1-4): identical in every locale BY CONSTRUCTION —
	 * no engine mapping and no process locale to consult, so no
	 * spelling reaches here mangled by the fold. The 8-bit-LC_CTYPE
	 * mangler the screen guards against is real C-library behavior
	 * (verified on this host: ctype_lower(0xE3) flips under a
	 * manufactured tr_TR.ISO-8859-9 — ctype consults the live locale —
	 * and glibc's tolower('I') maps to the dotless ı under the same
	 * locale, the exact per-locale mapping class that would break a
	 * byte if any fold ever consulted it), and any future
	 * transformation between entry and the rebuilt authority meets the
	 * abort-as-reject probe instead of flowing into the
	 * json_encode-false log-drop class the r4-13 entry gate exists to
	 * kill.
	 *
	 * @since 0.1.0
	 *
	 * @param string $authority The rebuilt (lowercased host[:port]) authority.
	 * @return void
	 * @throws InvalidArgumentException When the rebuilt authority is not valid UTF-8.
	 */
	private static function assert_authority_still_valid_utf8( string $authority ): void {
		if ( 1 !== preg_match( '//u', $authority ) ) {
			throw new InvalidArgumentException( 'The URL authority must stay valid UTF-8 after parsing — a host the parse or the case fold mangled refuses loudly instead of flowing into log lines whose json_encode then fails outright (the line is dropped, not degraded).' );
		}
	}
}
