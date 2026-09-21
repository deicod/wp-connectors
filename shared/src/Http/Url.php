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
	 * The ONE malformed-port sentence, shared by the entry screen's
	 * glued-tail arm and the raw-port screen below (glm15-6).
	 *
	 * The parse_url() engine mishandles a glued port tail BOTH ways — truncating
	 * ':443x' to port 443 when the digit run is four digits or fewer,
	 * failing the parse outright when it is five digits or more — so the
	 * two screens the two sub-shapes land on (the raw screen for the
	 * truncated parse, the entry for the failed one) must answer the
	 * SAME sentence: one malformed class, one verdict, never one class
	 * in two sentences the way ':443x' and ':65534x' answered before
	 * (driven: the five-digit glue wore the scheme/host sentence).
	 */
	private const PORT_MUST_BE_DIGITS_MESSAGE = 'The URL port must be digits — parse_url() mishandles a glued tail (":443x" truncates to 443, ":65534x" fails the parse outright) while the URL string carries the raw text, and the two must agree.';

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
	 * vocabulary THIRD — past the §4.1 step-1 edge strip, the
	 * Standard's own first verdict over the input (review round
	 * t31-r2-1; the order made real at t31-ocr60-6): parse_url accepts
	 * U+2028/U+2029, the C1 controls riding as valid UTF-8, and the C0
	 * range verbatim, and those bytes reached redacted_url()/__toString()
	 * unfiltered — reopening in the URL position exactly the forged
	 * log-line class the header-value pattern (t31-r1-19) rejects. The
	 * vocabulary is owned once, by HeaderMap (the header-value rule and
	 * the URL rule must not drift); space stays legal in a host per the
	 * round-1 host-charset adjudication (it renders oddly and forges
	 * nothing), while the adjudication's TAB half fell to the WHATWG
	 * strip-set screen at the entry — the whole input, path and query
	 * included (t31-ocr44-1, widened by t31-ocr45-2): a browser
	 * strips the byte BEFORE parsing, so it forges a different URL.
	 * The percent-ENCODED host answers the same family (t31-ocr45-1):
	 * the WHATWG host parser decodes %XX in a special-scheme host
	 * before resolving it, so a browser contacts a host this parse
	 * never names — the encoded spelling refuses at the host region.
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
		 * §4.1 STEP 1 FIRST (OCR round 56, t31-ocr56-2; riding BEFORE
		 * every refusal screen since OCR round 60, t31-ocr60-6): the
		 * Standard strips leading/trailing C0-control-or-space from
		 * the WHOLE input before anything else it does to it, so the
		 * strip runs before the control-byte screen below — an edge
		 * \r, \x0B, \x0C, or NUL once refused there before the strip
		 * could remove it, the strip degrading to trim(" \t") against
		 * the docblock's own promise ("an edge byte names nothing …
		 * both readings agree on one URL", "Both steps ride the
		 * Standard's order") and the two URL consumers rendering
		 * OPPOSITE verdicts for byte-identical input. The edge STRIPS
		 * where the interior REFUSES: an edge byte names nothing (no
		 * client means to send it — the browser's own verdict strips
		 * it), and every interior byte keeps its verdict at the
		 * screens below. The strip set derives the class exactly —
		 * U+0000–U+001F + U+0020, the contiguous byte run trim()'s
		 * list enumerates — byte-wise and locale-free, no PCRE abort
		 * to guard. The strip rides its ONE owner since OCR round 57
		 * (t31-ocr57-4): strip_edge_control_or_space() below, so
		 * HttpRequest stores the SAME validated spelling the parse
		 * judges — the stored bytes and every derived surface answer
		 * one verdict.
		 */
		$url = self::strip_edge_control_or_space( $url );

		/*
		 * The screen rides HeaderMap's own callable (t31-ocr18-4): the
		 * predicate and its rejection sentence had grown here as a
		 * verbatim twin of assert_no_control_bytes() — the drift seam
		 * the one-owner doctrine exists to close. The URL surface
		 * passes its field label; the abort-as-reject rule (glm36-8)
		 * lives in the owner. Judged over the STRIPPED spelling (the
		 * §4.1 order, t31-ocr60-6): the edge C0 bytes the Standard
		 * strips are gone before this screen runs, and every interior
		 * control byte still refuses.
		 */
		HeaderMap::assert_no_control_bytes( $url, 'The URL' );

		/*
		 * The WHATWG STRIP-SET screen (OCR round 44, t31-ocr44-1,
		 * widened to the whole input by OCR round 45, t31-ocr45-2 —
		 * the r28-6 doctrine's own class): the URL Standard removes
		 * ALL ASCII tabs and newlines from the input BEFORE parsing,
		 * so the byte mutates what a browser sees — 'https://id<TAB>
		 * p.example/device' sends a WHATWG consumer to idp.example
		 * (driven) while this parse keeps the tab in the authority
		 * verbatim and the engine's own parse_url() rewrites it to a
		 * THIRD spelling ('id_p.example' — probed), and a tab in the
		 * PATH or QUERY rides the same strip ('/verify?code=abcd'
		 * there, the query's tab rewritten 'code=ab_cd' by the engine
		 * — probed) while this parse kept the tabbed spelling green:
		 * URLs named by one input, the raw/redacted agreement this
		 * screen's family exists to kill, over the same
		 * browser-facing channel (the device-flow verification URI,
		 * passed through raw) the backslash screen closed. The probe
		 * rides the ENTRY — the whole input, never the authority
		 * slice the r44-1 spelling judged — and WHATWG-differential
		 * bytes are REFUSED from derivation, never naively stripped
		 * (the strip would hide a shape no client means to send).
		 * The newline half of the set (LF/CR) is already refused at
		 * the control screen above (the control vocabulary); the tab
		 * is the byte that reached every surface past it. This
		 * SUPERSEDES the round-1 adjudication's tab half ("they
		 * render oddly but forge nothing") — the tab forges; the
		 * SPACE keeps its verdict (a WHATWG consumer does not strip
		 * a space: a space-bearing host FAILS validation there
		 * rather than contacting another host, so the two consumers
		 * never name DIFFERENT hosts over it). An edge TAB already
		 * stripped at §4.1 step 1 above (a browser never sees it
		 * either); the interior tab refuses here.
		 */
		if ( false !== strpbrk( $url, "\t\n\r" ) ) {
			throw new InvalidArgumentException( 'The URL must not carry tabs or newlines — the URL Standard strips those bytes from the whole input before parsing, so a browser sees a different URL ("https://id<TAB>p.example" reaches idp.example there; a tab in the path or query rides stripped) while this parse keeps them verbatim, and the two must agree: write the URL without them.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the WordPress helper does not exist in this provider-neutral source (WordPress is reached only through ports); parse_url's shape is adequate for constructor validation.
		$parts = parse_url( $url );

		/*
		 * The authority derivation rides BEFORE the entry refusal
		 * (glm15-7): the entry's port probes judge the FIRST
		 * authority's own port region, and the success path below
		 * reuses the same derivation — ONE spelling, never a twin. The
		 * scheme separator is probed before it is used (OCR round 4,
		 * t31-ocr4-4): every sibling position probe in this file is
		 * false !== first — this one coerced, and (int) false is 0, so
		 * a schemeless spelling would have judged the authority math
		 * from the string's first byte instead of refusing. The arm is
		 * unreachable by construction on the success path (the scheme
		 * check below passed, and parse_url() yields a scheme only for
		 * the 'scheme://' spelling), but the file's own doctrine
		 * (t31-ocr1-2) refuses to lean on build-dependent invariants
		 * the surrounding code does not re-establish — so the
		 * invariant is named here, not assumed.
		 */
		$scheme_separator = strpos( $url, '://' );
		if ( false === $scheme_separator ) {
			throw new InvalidArgumentException( 'The URL must be absolute with a scheme and host.' );
		}
		$after_scheme = (string) substr( $url, $scheme_separator + 3 );
		$authority    = (string) substr( $after_scheme, 0, strcspn( $after_scheme, '/?#' ) );

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
			/*
			 * glm14-9: parse_url() returns FALSE outright for an
			 * authority whose port exceeds 65535 (the engine's own
			 * range check), so the out-of-range port died HERE wearing
			 * the scheme/host sentence on every supported build — the
			 * operator with a misconfigured provider port got a
			 * message directing them at the wrong screen (driven:
			 * 'https://idp:70000/token' answered 'must be absolute
			 * with a scheme and host', never the port sentence), and
			 * the port block's > 65535 arm below was dead code no
			 * input could reach. The entry owns the distinction now: a
			 * digits-only port beyond the range in a FAILED parse
			 * answers the port sentence, and the port block keeps the
			 * < 1 arm, the half this build can still reach (':0'
			 * parses). glm15-6 widened the entry to the GLUED class
			 * (five-digit-or-longer glue fails the parse; short glue
			 * parses truncated and the raw screen answers it).
			 *
			 * glm15-7: the probes are ANCHORED and DERIVED, never the
			 * unanchored regex that restarted at every '://' and
			 * scanned into the query — 'https://?redirect=https://
			 * evil.example:70000' answered the PORT sentence from a
			 * match entirely inside the QUERY while the real failure
			 * was the empty host (driven). The judgment rides the
			 * FIRST authority's own port region, derived exactly the
			 * way the success path derives it below: userinfo stripped
			 * after the last '@' (so a ':digits@' userinfo shape keeps
			 * the generic refusal its parse fails for anyway), the
			 * port colon the first ':' after any IPv6 ']' — the query
			 * never feeds the verdict.
			 */
			if ( false === $parts ) {
				$entry_at        = strrpos( $authority, '@' );
				$entry_host_port = false === $entry_at ? $authority : (string) substr( $authority, $entry_at + 1 );
				$entry_bracket   = strrpos( $entry_host_port, ']' );
				$entry_colon     = strpos( $entry_host_port, ':', false === $entry_bracket ? 0 : (int) $entry_bracket + 1 );
				$entry_port      = false === $entry_colon ? '' : (string) substr( $entry_host_port, $entry_colon + 1 );
				if ( '' !== $entry_port && 1 === preg_match( '/\A([0-9]+)/', $entry_port, $entry_digits ) ) {
					$entry_tail = (string) substr( $entry_port, strlen( $entry_digits[1] ) );
					if ( '' === $entry_tail && (int) $entry_digits[1] > 65535 ) {
						throw new InvalidArgumentException( 'The URL port is out of range — an authority port must be 1–65535, and the engine cannot parse one beyond it.' );
					}
					if ( '' !== $entry_tail ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the const is this file's own compile-time sentence, never provider data.
						throw new InvalidArgumentException( self::PORT_MUST_BE_DIGITS_MESSAGE );
					}
				}
			}
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
		 * The BACKSLASH screen (OCR round 28, t31-ocr28-6, DERIVED
		 * FIRST then fixed): the authority-termination set above is
		 * '/?#' only, so a backslash rode the authority verbatim on
		 * the PHP side (parse_url() keeps it in the host/userinfo —
		 * driven: 'host.example\evil' and 'user\@evil' both parse with
		 * the byte intact, and the rebuilt authority carries it too,
		 * raw and rebuilt agreeing) while a WHATWG consumer — the one
		 * browser-facing channel this VO feeds is the device-flow
		 * verification URI, passed through RAW to the authorization
		 * redirect — treats '\' at this position as an authority
		 * TERMINATOR: 'https://evil.example\@idp.example/' sends the
		 * browser to evil.example while this parse, the rebuilt
		 * authority, every redacted log form, and the PHP-side
		 * transport (which rides the engine's own parse_url
		 * semantics) all name idp.example —
		 * the host-forgery seam the round-1 space/tab adjudication
		 * spared those bytes from ("they render oddly but forge
		 * nothing"; the backslash re-splits the authority in a
		 * consumer that renders it). The RFC 3986 authority grammar
		 * carries no backslash in host, userinfo, or port either, so
		 * the refusal rejects nothing legal — the bracket screens' own
		 * doctrine: a malformed authority is a shape no client means
		 * to send, and reject is the safer verdict.
		 *
		 * The screen owns the WHOLE INPUT since OCR round 46
		 * (t31-ocr46-5 — the r28-6 refusal doctrine, full input; the
		 * r45-2 tab strip's sibling): the URL Standard's path state
		 * treats U+005C as a segment SEPARATOR for special schemes,
		 * so a browser consuming 'https://host/device\page' requests
		 * '/device/page' while this parse kept the byte verbatim in
		 * the path — url(), redacted_url(), and every derived surface
		 * naming a different path than the browser consumes, the same
		 * WHATWG-differential divergence one position past the
		 * authority. Every scheme this constructor admits is a
		 * special scheme (http/https only, two lines above), so the
		 * whole input is the screen's territory, and RFC 3986 admits
		 * no raw backslash in path, query, or fragment either: the
		 * refusal still rejects nothing legal.
		 */
		if ( false !== strpos( $url, '\\' ) ) {
			throw new InvalidArgumentException( 'The URL must not carry a backslash — WHATWG consumers treat "\" as a path-segment separator for http/https URLs ("https://host/device\page" reaches /device/page there while this parse keeps the byte) and as an authority terminator ("https://evil.example\@host/" sends a browser to evil.example while this parse and every redacted form name host), and this parse keeps the byte verbatim, so the two must agree: write the URL with "/" separators, never "\".' );
		}

		$at          = strrpos( $authority, '@' );
		$host_port   = false === $at ? $authority : (string) substr( $authority, $at + 1 );
		$bracket_end = strrpos( $host_port, ']' );

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
		$inner_literal = (string) substr( $host_port, 1, (int) $bracket_end - 1 );

		/*
		 * The ZONE-ID half of the content leg (OCR round 45,
		 * t31-ocr45-3): 'https://[fe80::1%25eth0]/' is an RFC 6874
		 * spelling the WHATWG parser accepts — but the engine's
		 * FILTER_VALIDATE_IP, the ONE IP-literal validator this
		 * screen charters, rejects the %25-spelled literal, and the
		 * PHP-side transport cannot resolve it. The spelling keeps
		 * its refusal (accepting it would hand-roll a second
		 * IP-literal grammar beside the engine's own validator — the
		 * one-validator doctrine — for a link-local-scoped host no
		 * http(s) endpoint client means to send) under its OWN name:
		 * the generic malformed-host sentence below lied about the
		 * class, naming no zone id. The percent probe rides FIRST so
		 * every percent-bearing literal — the %25 spelling and the
		 * bare percent alike — answers the named verdict.
		 */
		if ( $well_formed_bracket_pair && false !== strpos( $inner_literal, '%' ) ) {
			throw new InvalidArgumentException( 'A bracketed host must not carry an RFC 6874 zone identifier ("[fe80::1%25eth0]") — the engine IP validator this screen rides rejects the %25-spelled literal and the PHP-side transport cannot resolve it, and accepting it would hand-roll a second IP-literal grammar beside that validator for a link-local-scoped host no http(s) endpoint client means to send: write the address without the zone id, never inside the host literal.' );
		}
		if ( $well_formed_bracket_pair && false === filter_var( $inner_literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
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
		$colon        = strpos( $host_port, ':', false === $bracket_end ? 0 : (int) $bracket_end + 1 );
		$raw_port_int = null;
		if ( false !== $colon ) {
			$raw_port = (string) substr( $host_port, $colon + 1 );
			if ( 1 !== preg_match( '/\A[0-9]+\z/', $raw_port ) ) {
				/*
				 * glm15-6: the ONE malformed-port sentence (the const
				 * above) — the truncated-parse sub-shape of the same
				 * glued class.
				 */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the const is this file's own compile-time sentence, never provider data.
				throw new InvalidArgumentException( self::PORT_MUST_BE_DIGITS_MESSAGE );
			}
			$raw_port_int = (int) $raw_port;

			/*
			 * glm14-9: only the < 1 arm lives here — parse_url()
			 * returns false for any port beyond 65535, so a URL
			 * reaching this block carries an engine-accepted port and
			 * the > 65535 arm was dead code (the entry screen's
			 * out-of-range probe owns that class, naming the port
			 * sentence where the failed parse actually lands).
			 */
			if ( $raw_port_int < 1 ) {
				throw new InvalidArgumentException( 'The URL port is out of range.' );
			}

			/*
			 * The CANONICAL spelling (OCR round 25, t31-ocr25-3): a
			 * leading-zero port is digits, so the digit screen passed
			 * it — but parse_url() normalizes ':0443' to 443 while the
			 * URL string the caller holds keeps ':0443' verbatim: the
			 * value object carried url() with ':0443' against an
			 * authority spelling ':443', the exact raw/redacted
			 * divergence this screen exists to kill (the r4-12 class
			 * one spelling over). The raw string cannot be rewritten
			 * (url() holds the caller's bytes exactly), so agreement
			 * means REFUSING the non-canonical spelling — write ':443'.
			 * The RANGE screen rides FIRST (the round's verifier close:
			 * rd-1): a zero-valued spelling ':000' has no canonical
			 * form to write — complying lands on the range refusal —
			 * so it must wear the range sentence, never a dead-end
			 * remediation.
			 */
			if ( strlen( $raw_port ) > 1 && '0' === $raw_port[0] ) {
				throw new InvalidArgumentException( 'The URL port must be spelled without leading zeros — ":0443" reads as 443 while the URL string keeps the raw spelling, and the two must agree (write ":443").' );
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

		/*
		 * The PERCENT-ENCODED host screen (OCR round 45, t31-ocr45-1 —
		 * the r28-6/r44-1 WHATWG-differential class, one generation
		 * over): the URL Standard's host parser PERCENT-DECODES a
		 * special-scheme host before domain-to-ASCII (§6.4), so a
		 * browser loading 'https://id%70.example/' contacts
		 * idp.example while this parse, the rebuilt authority, and
		 * every redacted form name 'id%70.example' verbatim — two
		 * hosts named by one URL over the same browser-facing channel
		 * (the device-flow verification URI, passed through raw) the
		 * backslash and strip-set screens closed for their own bytes.
		 * http(s) are special schemes on every spelling this VO
		 * accepts, so any '%' in the host answers the refusal —
		 * REFUSED from derivation, never decoded (the ocr44-1
		 * doctrine: the decode would silently accept a shape no
		 * client means to send; write the host decoded). The screen
		 * judges the HOST REGION alone: percent-encoding stays legal
		 * in userinfo and in the path/query, where no consumer's
		 * reading decodes it into the host, and a bracket literal's
		 * own percent spelling (the RFC 6874 zone id) is owned by
		 * the bracket content screen above, which every bracket
		 * spelling answers first.
		 */
		if ( false === $bracket_end && false !== strpos( $raw_host, '%' ) ) {
			throw new InvalidArgumentException( 'The URL host must not carry percent-encoded bytes — the URL Standard percent-DECODES a special-scheme host before resolving it ("https://id%70.example/" contacts idp.example in a browser) while this parse and every redacted form keep the encoded spelling verbatim, and the two must agree: write the host decoded, never percent-encoded.' );
		}

		/*
		 * The IPv4-AMBIGUOUS host screen (OCR round 49, t31-ocr49-4 —
		 * the r28-6/r44-1/r45-1 WHATWG-differential class, one
		 * generation over): the URL Standard parses any
		 * special-scheme host whose last label "ends in a number" as
		 * an IPv4 ADDRESS (§5.3 — '010.1.1.1' is 8.1.1.1 under the
		 * leading-zero octal, '0x62.0x90.0.1' hex, the bare
		 * '2130706433' is 127.0.0.1), while this parse kept such
		 * spellings as OPAQUE HOSTNAMES — two hosts named by one URL
		 * over the same browser-facing channel (the device-flow
		 * verification URI, passed through raw) the percent,
		 * backslash, and strip-set screens closed for their own
		 * bytes. http(s) are special schemes on every spelling this
		 * VO accepts, so the ambiguity refuses — REFUSED from
		 * derivation, never coerced to the IPv4 reading (the ocr44-1
		 * doctrine): the one spelling that passes is the CANONICAL
		 * dotted quad ('8.8.8.8', four decimal octets 0-255, no
		 * leading zeros), where the browser's IPv4 reading and this
		 * parse's hostname agree byte for byte. A last label that is
		 * not a number leaves the host a domain whatever the earlier
		 * labels carry ('idp2.example' stays a DNS name) — the
		 * URL Standard's own predicate, spelled by the helper.
		 */
		if ( false === $bracket_end && 1 !== preg_match( '/\A(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}\z/', $raw_host ) && self::host_ends_in_a_number( $raw_host ) ) {
			throw new InvalidArgumentException( 'The URL host must not use an IPv4-ambiguous spelling — the URL Standard parses any special-scheme host whose last label ends in a number as an IPv4 address ("https://010.1.1.1/" contacts 8.1.1.1, the bare "https://2130706433/" contacts 127.0.0.1) while this parse keeps the spelling as a hostname, and the two must agree: write the canonical dotted-quad IPv4 literal, never an octal, hex, or bare-number spelling.' );
		}

		/*
		 * The NON-ASCII (IDN) host screen (OCR round 50, t31-ocr50-4 —
		 * the r28-6/r44-1/r45-1/r49-4 WHATWG-differential class, one
		 * generation over): the URL Standard runs domain-to-ASCII over
		 * a special-scheme host before resolving it (§6.4), so a
		 * browser loading 'https://bücher.example/' contacts
		 * 'xn--bcher-kva.example' while this parse, the rebuilt
		 * authority, and every redacted form keep the raw UTF-8 host
		 * bytes — two hosts named by one URL over the same
		 * browser-facing channel (the device-flow verification URI,
		 * passed through raw) the earlier screens closed for their own
		 * bytes. The non-ASCII host REFUSES — never punycode-converted
		 * (the ocr44-1 doctrine: the conversion would silently accept
		 * a spelling the caller never wrote, a second host derived by
		 * us); write the host in its punycode (xn--) spelling, where
		 * the browser's resolution and this parse's hostname agree
		 * byte for byte. The screen judges the HOST REGION alone
		 * (non-ASCII stays legal in the path and query, where no
		 * consumer's reading resolves it into a host), and the bracket
		 * literals ride their own screen above — an IPv6 address is
		 * pure ASCII by grammar.
		 *
		 * The probe itself reads abort-as-reject (glm36-8,
		 * t31-ocr53-2): `0 !==` — a bare byte-class scan carries no
		 * backtracking to exhaust, so PCRE answering false is
		 * unreachable in practice, but the spelling refuses one
		 * anyway, never passes it — the same rule the port digit
		 * screen, the glued-bracket allow form, and both //u probes
		 * spell (this was the one ban probe in the file still spelled
		 * `1 ===`, the r53-2 fail-open shape: an abort turned into a
		 * silent pass of a non-ASCII host).
		 */
		if ( false === $bracket_end && 0 !== preg_match( '/[\x80-\xFF]/', $raw_host ) ) {
			throw new InvalidArgumentException( 'The URL host must be ASCII — the URL Standard runs domain-to-ASCII over a special-scheme host before resolving it ("https://bücher.example/" contacts xn--bcher-kva.example in a browser) while this parse and every redacted form keep the raw UTF-8 spelling, and the two must agree: write the host in its punycode (xn--) spelling, never raw UTF-8.' );
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

		/*
		 * The DOT-SEGMENT screen (OCR round 57, t31-ocr57-1 — the
		 * r28-6/r44-1/r45-1/r49-4/r50-4 WHATWG-differential class,
		 * the path-state member the r46-5 whole-input backslash
		 * pass's own census claim ('every derived surface naming a
		 * different path than the browser consumes') never covered):
		 * the URL Standard resolves single-dot and double-dot path
		 * segments in its path state (§4.4, over the §4.1 segment
		 * definitions) — a browser loading
		 * 'https://device.example/a/../b' requests '/b' while this
		 * parse, url(), redacted_url(), and every derived surface
		 * keep '/a/../b' verbatim (driven: every shape below
		 * constructed green at HEAD): two paths named by one URL over
		 * the same browser-facing channel (the device-flow
		 * verification URI, passed through raw) the tab, backslash,
		 * percent, IPv4, and IDN screens closed for their own bytes.
		 * The Standard's exact algorithm was verified at the source
		 * this round: a single-dot segment ('.' or its ASCII
		 * case-insensitive '%2e' spelling) is dropped; a double-dot
		 * segment ('..', '.%2e', '%2e.', '%2e%2e') shortens the path
		 * by one segment, clamping at the root (shorten-a-url's-path
		 * removes the last item 'if any' — '..' past the root removes
		 * nothing); a TRAILING dot segment appends the empty string
		 * ('/a/b/..' is '/a/b/', never '/a/b' — the Standard's own
		 * '/usr/..' note); a dot segment right before '?' or '#'
		 * resolves there too, while the query and fragment regions
		 * themselves never resolve — parse_url()'s own path answer,
		 * the region this screen judges, is exactly that territory.
		 * Opaque paths never resolve (the opaque path state carries
		 * no dot handling) and are UNREACHABLE here: every scheme
		 * this VO admits is special (http/https only, two screens
		 * above), and 'a special URL's path is always a list, i.e.,
		 * it is never opaque' — the screen owns the whole path
		 * surface with no carve-out to name. Dot segments REFUSE —
		 * never resolved (the ocr44-1 doctrine: the resolution would
		 * silently rewrite the caller's URL into a second path
		 * derived by us): write the resolved path, where the
		 * browser's request and this parse agree byte for byte.
		 * Dots INSIDE segments are not dot segments ('/a.b/c' is
		 * legal and untouched — the Standard resolves whole
		 * segments, never bytes inside them).
		 */
		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( self::path_has_dot_segments( $path ) ) {
			throw new InvalidArgumentException( 'The URL path must not carry dot segments ("." or "..", including their %2e spellings) — the URL Standard resolves them in every WHATWG consumer ("https://device.example/a/../b" requests /b there, a ".." past the root clamps at the root) while this parse keeps them verbatim, and the two must agree: write the resolved path, never a dot segment.' );
		}

		return array(
			'scheme'    => $scheme,
			'authority' => $authority,
			'path'      => $path,
		);
	}

	/**
	 * Parses and validates, then renders the redaction shape both consumers show.
	 *
	 * The scheme://authority/path rebuild — HttpRequest's redacted_url()
	 * and the device-flow session's masked view — is glued by ONE owner
	 * (OCR round 31, t31-ocr31-6): the two constructions were hand-twins
	 * with their sameness asserted only by docblock prose, the drift seam
	 * the one-owner doctrine exists to close. Validation and redaction
	 * still derive from the one parse.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url URL.
	 * @return string The URL reduced to scheme://host[:port]/path.
	 * @throws InvalidArgumentException When the URL is not absolute http(s) with a host and valid port.
	 */
	public static function redacted( string $url ): string {
		$parts = self::parse_validated( $url );

		return $parts['scheme'] . '://' . $parts['authority'] . $parts['path'];
	}

	/**
	 * The URL Standard §4.1 step-1 edge strip (OCR round 56,
	 * t31-ocr56-2; the ONE owner since OCR round 57, t31-ocr57-4):
	 * removes leading and trailing C0-control-or-space bytes from the
	 * WHOLE input — the browser's own first verdict over the URL
	 * surface, before the tab/newline pass and every screen below.
	 * parse_validated() rides it at the entry, and HttpRequest rides
	 * it BEFORE storing, so url() names exactly the bytes the screens
	 * validated — the stored/derived agreement doctrine (the r25-3
	 * raw/redacted rule, the spelling side of it).
	 *
	 * The strip set is the Standard's own class, U+0000–U+001F plus
	 * U+0020 — the contiguous byte run trim()'s list enumerates —
	 * byte-wise and locale-free, no PCRE abort to guard.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url URL.
	 * @return string The URL with leading/trailing C0-control-or-space bytes stripped.
	 */
	public static function strip_edge_control_or_space( string $url ): string {
		return trim( $url, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20" );
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

	/**
	 * The URL Standard's "ends in a number" host predicate (§5.3,
	 * t31-ocr49-4; the digit-only arm aligned by t31-ocr50-6, the
	 * radix table CORRECTED by t31-ocr53-1 — the round's driven
	 * refutation of the r52-1 completion): strictly split the host
	 * on '.', drop ONE trailing empty part, and ask whether the LAST
	 * part is a number — the DIGIT-ONLY arm first (§5.3 step 4: a
	 * non-empty part of only ASCII digits is a number, BEFORE any
	 * radix parse, so '09' IS a number to every WHATWG consumer —
	 * the browser routes it to IPv4 parsing, where the leading-zero
	 * validation then fails it), then the IPv4 radix arm: the
	 * Standard's IPv4 number parser recognizes exactly TWO prefix
	 * spellings — 0x/0X radix 16 (empty after the prefix parses as
	 * 0, the spec's own step, so '0x' alone IS a number), and the
	 * legacy single leading '0' radix 8, which every all-digits
	 * spelling already answered at the digit arm. '0o'/'0b' prefixes
	 * DO NOT EXIST in the URL Standard — they are ECMAScript
	 * numeric-literal spellings; for a last label '0b1'/'0o7' the
	 * parser strips only the leading '0', leaving 'b1'/'o7', which
	 * fail the radix-digit check — the label stays a number's
	 * negation and the host an opaque domain, exactly like the
	 * '0o9'/'0b2' controls (the Standard's own note: the radix arm
	 * is equivalent to '0x' followed by zero or more hex digits).
	 * The r52-1 arm read those ECMAScript spellings as URL-Standard
	 * prefixes and refused '0b1.example'-shaped hosts — over-refusal
	 * of a legal domain, reverted. A host that ends in a number is
	 * IPv4 territory to every WHATWG consumer on a special scheme;
	 * a last label that is not a number leaves the host a domain
	 * whatever the earlier labels carry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $host The raw (pre-fold) host spelling.
	 * @return bool True when the URL Standard reads the host as ending in a number.
	 */
	private static function host_ends_in_a_number( string $host ): bool {
		$parts = explode( '.', $host );
		if ( count( $parts ) > 1 && '' === $parts[ count( $parts ) - 1 ] ) {
			array_pop( $parts );
		}
		$last = (string) $parts[ count( $parts ) - 1 ];

		$probe = preg_match( '/\A(?:[0-9]+|0[xX][0-9A-Fa-f]*)\z/', $last );

		/*
		 * Abort-as-reject (glm36-8, t31-ocr53-2): a PCRE abort
		 * cannot prove the label is not a number, so it answers the
		 * refusing arm — the sole caller's screen refuses the URL,
		 * never passes it, the rule every probe in this file already
		 * spells. The r53-2 shape read `1 === false` as "not a
		 * number" and an IPv4-ambiguous spelling constructed as an
		 * opaque domain behind the abort.
		 */
		if ( false === $probe ) {
			return true;
		}

		return 1 === $probe;
	}

	/**
	 * Whether the path region carries a URL Standard single- or
	 * double-dot segment (t31-ocr57-1): '.', '..' plus their ASCII
	 * case-insensitive percent spellings '%2e', '.%2e', '%2e.',
	 * '%2e%2e' — the §4.1 segment definitions the path state of every
	 * WHATWG consumer resolves. The fold rides the ONE ASCII owner
	 * (t31-ocr1-4): the byte table, never the engine's
	 * locale-consulting strtolower.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The path region (parse_url()'s own path answer).
	 * @return bool True when any whole segment is a dot segment.
	 */
	private static function path_has_dot_segments( string $path ): bool {
		foreach ( explode( '/', $path ) as $segment ) {
			if ( \in_array( AsciiFold::lower( $segment ), array( '.', '..', '%2e', '.%2e', '%2e.', '%2e%2e' ), true ) ) {
				return true;
			}
		}

		return false;
	}
}
