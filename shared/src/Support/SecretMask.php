<?php
/**
 * Secret-masking policy for safe debug rendering (Task 3.1).
 *
 * Single owner of the three facts every safe debug form depends on: what a
 * masked secret looks like (an ellipsis plus the last four characters —
 * enough to correlate a value across log lines, never enough to use it),
 * which HTTP header names always count as secret-bearing regardless
 * of the value they carry, and — since t31-r8-6 — how a verbatim value's
 * invalid-UTF-8 bytes render (percent-encoded: the r4-13 doctrine's
 * OUTCOME at the header render seam, where obs-text values must not
 * reject).
 *
 * Pure PHP, UTF-8-aware at the byte level (review round t31-r1): the
 * visible tail is the last four CHARACTERS — complete sequences, never
 * a partial one. A byte-wise tail split a multibyte character
 * mid-sequence and returned invalid UTF-8, which made json_encode()
 * drop or mangle the redacted log line. Binary (non-UTF-8) values
 * degrade to whichever trailing bytes form valid UTF-8, and to the
 * bare mask when none do: mask() NEVER returns invalid UTF-8.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Support;

use Deicod\WpConnectors\Shared\Http\HeaderMap;

/**
 * Masks secret values and identifies secret-bearing header names.
 *
 * @since 0.1.0
 */
final class SecretMask {

	/**
	 * The mask marker (horizontal ellipsis).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const MASK = '…';

	/**
	 * How many trailing characters of a masked secret stay visible.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const VISIBLE_TAIL = 4;

	/**
	 * Shortest secret whose tail is shown at all.
	 *
	 * At or below this length, four visible characters would reveal a
	 * third of the secret or more, so the mask shows nothing.
	 *
	 * OCR round 6 (t31-ocr6-1, the first shared/src security finding
	 * since round 3 — a tail-length policy gap, not a new channel): the
	 * canonical RFC 8628 user code ('BCJK-3502', nine characters with
	 * its separator) sat one character above the old threshold of 8 and
	 * rendered '…3502' — half the code's entropy on screen, for a value
	 * that is ITSELF a short-lived credential a dump should never help
	 * use. The visible-tail policy must never expose a tail of a value
	 * that short: OTP-class values (user codes, device codes of 12
	 * characters or fewer) render the bare mask; longer values keep the
	 * correlation tail. The policy is one threshold at this owner —
	 * every credential-bearing consumer (the flow VOs' codes, the token
	 * set, masked headers) rides it, none re-decides it.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MIN_LENGTH_FOR_VISIBLE_TAIL = 12;

	/**
	 * Header names (lowercase) whose values are always masked.
	 *
	 * 'location' joined with vendor-doc proof, not a hypothetical (review
	 * round t31-r12-4, driver adjudication): RFC 6749 section 4.1.2
	 * mandates the authorization code in the redirect's Location query
	 * — a 302's Location IS a credential-bearing surface by
	 * specification, and it rendered verbatim through every safe debug
	 * form while the request side masked its own credential headers
	 * (reproduced: 'Location: https://client/cb?code=…' in full in the
	 * string cast, the dump, and print_r). One owner: this catalog and
	 * the suffix class below are together the single spelling of what
	 * a sensitive header name is.
	 *
	 * The catalog carries only the names the class rule CANNOT spell
	 * (OCR round 33, t31-ocr33-3, the t31-ocr29-5 subsumption doctrine
	 * over the catalog's own entries): 'authorization' IS a member of
	 * the suffix class, and 'proxy-authorization'/'x-api-key' end in
	 * '-authorization'/'-api-key' — all three rode the class
	 * identically and were behaviorally dead weight implying the
	 * catalog needed them. The battery pins the three spellings green
	 * through the class by construction (drop 'authorization' or
	 * 'api-key' from the suffixes and they redden).
	 *
	 * OCR round 38 (t31-ocr38-1, security — the first shared/src
	 * finding since round 24): the REQUEST-SIDE TWIN of the r12-4
	 * channel. 'location' masks because the RFC 6749 section 4.1.2
	 * redirect query carries the authorization code — and a Referer
	 * value is that SAME query echoed by a caller's outbound
	 * navigation (the response-side leak's request-side spelling),
	 * yet it rendered verbatim through every safe debug form. Beside
	 * it the RFC 7615 authentication-exchange headers
	 * ('authentication-info'/'proxy-authentication-info' — the
	 * 401-protection twins of the masked 'proxy-authorization'
	 * class, credential material by specification): none of the three
	 * composes through the suffix class (each final hyphen-token —
	 * 'referer', 'info' — names no credential suffix), so all three
	 * ride the catalog.
	 *
	 * OCR round 49 (t31-ocr49-5, security — the UNDELIMITED
	 * generation, the single-token twin of the r12-4 leak class the
	 * delimiter census of ocr43-1/ocr44-2 closed for every
	 * SEPARATED spelling): the fold normalizes every tchar delimiter
	 * to the hyphen, but a credential name spelled with NO delimiter
	 * at all — a header named exactly 'apikey', 'accesstoken' —
	 * folds to a judged name that equals no catalog entry and whose
	 * final segment is the WHOLE token, ending in no suffix
	 * ('accesstoken' does not end in '-token'): both screens
	 * answered false and the secret rendered verbatim through every
	 * safe debug form (driven at HEAD). The flattened family rode
	 * THIS catalog for one round — and OCR round 50 (t31-ocr50-1)
	 * moved it to the SUFFIX CLASS below: the catalog is consulted
	 * by exact match only, so 'X-ApiKey' — the equally real vendor
	 * spelling, flattened twin of covered 'X-Api-Key' — folded to
	 * 'x-apikey' and matched neither screen; the class's boundary
	 * owns the hyphen-tail now, and the catalog entries were
	 * behaviorally dead weight the t31-ocr33-3 subsumption doctrine
	 * refuses. A glue with no recognized credential name behind it
	 * stays outside ('apitoken' is no vendor's spelling, the r24
	 * boundary example — the boundary still refuses suffix bytes
	 * SPANNING a separator, 'x-api-keychain' over every delimiter).
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const SENSITIVE_HEADER_NAMES = array( 'cookie', 'set-cookie', 'location', 'referer', 'authentication-info', 'proxy-authentication-info' );

	/**
	 * Credential-bearing name suffixes (lowercase): any folded header
	 * name that IS one of these, or whose final hyphen-token is one,
	 * counts as secret-bearing — the CLASS rule over the catalog.
	 *
	 * OCR round 15 (t31-ocr15-1, security): the closed catalog above
	 * could never grow as fast as vendors mint key-bearing header
	 * names. 'x-api-key' was covered while 'api-key' — the documented
	 * authentication header of a whole class of cloud AI vendors and
	 * an archetypal Task-3.7 transport binding — rendered its full
	 * secret verbatim
	 * through every safe debug form: the r12-4 leak class reopened
	 * under another vendor-documented spelling, and per-spelling
	 * catalog additions were a queue a leak had to reproduce first.
	 * The rule closes the class instead: each suffix below is a
	 * vendor-documented credential token ('api-key' covers the
	 * bare cloud-AI spelling and every '…-api-key' derivative;
	 * 'subscription-key' covers the APIM gateway's
	 * 'Ocp-Apim-Subscription-Key'; 'auth' the token-bearing
	 * spellings), and the boundary is
	 * the HYPHEN — a name merely ending in the suffix bytes
	 * ('x-api-keychain') is not the class, because vendor spellings
	 * are hyphenated tokens. No extension seam exists yet by
	 * adjudication: Task 3.7's transport binding decides whether a
	 * provider contributes spellings of its own, and the ledger holds
	 * that decision — a seam added before a second config source
	 * would be speculative reach.
	 *
	 * OCR round 24 (t31-ocr24-1, security — the first shared/src
	 * finding since round 14): the class did not cover the credential
	 * suffixes its own rule statement implies. 'token', 'secret', and
	 * 'authorization' are vendor-documented credential tokens in the
	 * rule's own sense — AWS STS signs with 'X-Amz-Security-Token',
	 * Shopify's REST API with 'X-Shopify-Access-Token', OAuth client
	 * credentials ride 'X-Client-Secret'/'X-Shared-Secret', and
	 * 'X-Authorization' is the prefixed bearer spelling — and every
	 * one rendered its full secret verbatim through every safe debug
	 * form while the catalog's exact 'authorization' spelling sat
	 * covered: the r12-4/ocr15-1 leak class under the class rule's
	 * own implied vocabulary. They join the list. Over-masking a
	 * non-credential '-token' header in DEBUG output errs safe (a
	 * correlation tail is lost, never a secret); the hyphen boundary
	 * is unaffected — the judged token stays the whole final
	 * hyphen-segment ('x-api-keychain' remains outside).
	 *
	 * OCR round 29 (t31-ocr29-5, maintainability): the round-15 entry
	 * 'auth-token' is SUBSUMED by 'token' and is gone — every name
	 * the entry matched (the bare 'auth-token' spelling, every
	 * '…-auth-token' derivative) ends in '-token' and rode 'token'
	 * identically, so the entry was behaviorally dead weight implying
	 * the class needed it. The battery's auth-token spellings
	 * ('x-auth-token', 'Auth-Token') ride 'token' green, by
	 * construction.
	 *
	 * OCR round 50 (t31-ocr50-1, security — the HYPHEN-TAIL twin of
	 * the r49-5 undelimited generation): the flattened family rides
	 * HERE, never the catalog. The r49-5 fix consulted the catalog by
	 * exact match only, so a name whose FINAL hyphen-token is one of
	 * the flattened spellings escaped both screens — 'X-ApiKey' (the
	 * equally real vendor spelling, flattened twin of covered
	 * 'X-Api-Key') folds to judged 'x-apikey': no exact catalog hit,
	 * no suffix hit, the value verbatim (driven at HEAD). One
	 * predicate owns the whole family shape: the fold normalizes
	 * underscore, hyphen, and dot segment tails to the hyphen once,
	 * so the one boundary below speaks every delimiter's segment tail
	 * plus the bare token ('x-apikey', 'x_accesstoken',
	 * 'x.accesstoken' all judge the same final segment), and the
	 * boundary still refuses suffix bytes SPANNING a separator
	 * ('x-apikeychain' stays outside, over every delimiter).
	 *
	 * OCR round 52 (t31-ocr52-2, security — the generic 'key' token,
	 * one census over the family): the class carried 'api-key' and
	 * 'subscription-key' while missing the generic token both END in
	 * — and the final-token judgment turned that into an internal
	 * inconsistency: 'X-Client-Secret' and 'X-Api-Key' masked while
	 * 'X-Secret-Key' (composed of the class's own 'secret' beside the
	 * 'key' every key-bearing member spells) and 'X-Access-Key' (the
	 * object-storage/S3-compatible auth spelling) rendered verbatim
	 * (driven at HEAD). The shape chosen is the GENERIC token, the
	 * same tier 'token'/'secret'/'auth' already occupy (the r24
	 * adjudication: those are vendor-documented credential tokens,
	 * and over-masking a non-credential '-key' name in DEBUG output
	 * errs safe — a correlation tail lost, never a secret, the exact
	 * trade 'X-Multi-Token' already rides): 'key' joins, and the
	 * hyphenated compounds it subsumes ('api-key',
	 * 'subscription-key' — every '…-api-key' ends '-key') leave per
	 * the ocr33-3 subsumption doctrine, their FLATTENED twins staying
	 * (no separator, the generic tail cannot reach them) beside the
	 * round's own twins 'secretkey'/'accesskey' (the r49-5 doctrine:
	 * a recognized credential name's undelimited spelling is the
	 * name). The alternative shape — an any-credential-token-in-the-
	 * name rule — was rejected for consistency: it leaves
	 * 'X-Access-Key' verbatim ('access' names no classed token), the
	 * exact inconsistency the round exists to close. The boundary is
	 * unchanged: 'x-keychain' and 'x-monkey' stay outside — the
	 * suffix bytes never span the segment the class judges.
	 *
	 * OCR round 55 (t31-ocr55-1, security — the suffix tier's own
	 * sibling): 'authorization' rode the class since round 24 while
	 * 'authentication' — the SAME tier's sibling spelling, the final
	 * token of 'X-Authentication'/'Proxy-Authentication'/
	 * 'Client-Authentication' — matched neither the catalog (which
	 * carries only the '-info' exchange spellings) nor the suffix
	 * screens ('x-authentication' ends in no listed suffix), so the
	 * credential value rendered verbatim through every safe debug
	 * form, the r12-4/ocr15-1 leak class under a vendor spelling the
	 * file's own doctrine treats as credential material. One member
	 * speaks every delimiter spelling per the r50-1 boundary doctrine
	 * (the fold normalizes the whole tchar delimiter class to the
	 * hyphen once, so the bare token and every segment tail judge the
	 * same); the catalog's 'authentication-info'/
	 * 'proxy-authentication-info' entries stay exact-match arms of
	 * their own (their final token is 'info', never subsumed). No
	 * non-credential '-authentication' neighbor is known to exist —
	 * every header carrying the suffix names an authentication
	 * credential — and the boundary is unchanged: a name whose final
	 * token merely precedes it ('x-authentication-scheme') stays
	 * verbatim, the suffix bytes never spanning the segment.
	 *
	 * OCR round 57 (t31-ocr57-2, security — the flattened 'csrftoken'
	 * twin): 'X-CSRFToken' — Django's canonical CSRF header spelling
	 * (CSRF_HEADER_NAME, documented in Django's own CSRF chapter;
	 * the cookie default is the bare 'csrftoken') — folds to judged
	 * 'x-csrftoken', which matched no catalog entry and no suffix
	 * ('csrftoken' ends in no listed suffix — its final segment is
	 * the whole flattened token), so the session credential rendered
	 * verbatim through every safe debug form while the hyphenated
	 * twin 'X-Csrf-Token' masked via 'token' (driven at HEAD): the
	 * exact covered-hyphenated/verbatim-flattened inconsistency
	 * t31-ocr50-1 closed for 'X-ApiKey'. One member speaks every
	 * delimiter spelling per the r50-1 boundary doctrine (the bare
	 * token and every segment tail judge the same). The round's
	 * sweep for other vendor-canonical flattened spellings found no
	 * second member that meets the vendor-documented bar: the .NET
	 * twin was considered and SKIPPED as spelled — 'antiforgerytoken'
	 * is no vendor's header name, and the spelling .NET documents
	 * ('__RequestVerificationToken') carries delimiters whose fold
	 * judges the 'token' segment, already covered.
	 *
	 * OCR round 61 (t31-ocr61-2, security — the HMAC-material
	 * suffix): 'signature' is the final token of the webhooks' own
	 * credential-material headers — Stripe's 'Stripe-Signature'
	 * (webhook signing), GitHub's 'X-Hub-Signature' and its SHA-256
	 * variant 'X-Hub-Signature-256', the generic 'X-Signature',
	 * Google's 'X-Goog-Signature' — and it matched neither catalog
	 * nor suffix, so the credential-derived HMAC material rendered
	 * verbatim through every safe debug form (driven at HEAD): the
	 * r12-4/ocr15-1 leak class under spellings the vendors document
	 * themselves. One member speaks every delimiter spelling per the
	 * r50-1 boundary doctrine (the bare token and every segment tail
	 * judge the same); the 'signature-key' shapes need no member of
	 * their own (every '…-signature-key' ends '-key', the generic
	 * tier owns them); no flattened glued twin joins (no vendor
	 * spells 'XSignature' — every motivating header hyphenates, the
	 * r55-1 'authentication' treatment). The '-256' VARIANT rides
	 * its own two-token entry: its fold leaves the judged final
	 * segment '256', a token no credential name spells, so the tail
	 * WITH the variant is judged whole — the same final-segment
	 * boundary, one entry longer. The round's sweep for adjacent
	 * vendor-documented credential material considered the
	 * HTTP-signatures 'Digest' twin and SKIPPED it: RFC 3230's
	 * value is an integrity digest of the body it rides WITH
	 * (computable from that body, secret-free), not
	 * credential-DERIVED material — the bar every member above
	 * meets. No non-credential '-signature' final token is known
	 * (a name-final token PRECEDING the member — 'x-signature-count'
	 * — stays verbatim by the boundary, the suffix bytes never
	 * spanning the segment).
	 *
	 * OCR round 66 (t31-ocr66-5, security — the credential-material
	 * suffix tier's own 'password' member): 'password' is the final
	 * token of vendor-documented credential headers — 'X-Password'
	 * and the composed 'X-Api-Password'/'X-User-Password' family —
	 * and it matched neither catalog nor suffix (driven at HEAD: the
	 * value rendered verbatim through every safe debug form), the
	 * r12-4/ocr15-1 leak class the tier exists to close, the same
	 * tier doctrine as round 24 ('token'/'secret'/'authorization'),
	 * round 52 ('key'), and round 55 ('authentication'). One member
	 * speaks every delimiter spelling per the r50-1 boundary doctrine
	 * (the fold normalizes the whole tchar delimiter class, so
	 * 'X_Password'/'X.Password' judge the same); no flattened glued
	 * twin joins ('xpassword' — no vendor spells it, the r55-1
	 * 'authentication' treatment), and the boundary is unchanged: a
	 * name whose final token merely precedes it
	 * ('x-password-policy', 'x-passport') stays verbatim, the suffix
	 * bytes never spanning the segment.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const SENSITIVE_HEADER_NAME_SUFFIXES = array( 'auth', 'authorization', 'authentication', 'token', 'secret', 'key', 'password', 'apikey', 'subscriptionkey', 'secretkey', 'accesskey', 'accesstoken', 'refreshtoken', 'clientsecret', 'securitytoken', 'sharedsecret', 'csrftoken', 'signature', 'signature-256' );

	/**
	 * Masks a secret value: ellipsis plus the last four characters.
	 *
	 * Null and short values (at or below the minimum length, counted in
	 * characters) show the ellipsis only. The visible tail is always
	 * valid UTF-8: complete sequences for well-formed values, the
	 * longest valid trailing run for binary ones, the bare mask when no
	 * trailing bytes form a valid sequence.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $value The secret, or null.
	 * @return string The masked rendering (never invalid UTF-8).
	 */
	public static function mask( ?string $value ): string {
		if ( null === $value || self::count_characters( $value ) <= self::MIN_LENGTH_FOR_VISIBLE_TAIL ) {
			return self::MASK;
		}

		// The last VISIBLE_TAIL characters, then shed leading characters
		// until what remains is standalone-valid UTF-8 (a slice starting
		// mid-sequence, or carrying one, is not); empty means the bare
		// mask — never a partial character.
		$tail = self::tail_bytes_of_last_characters( $value, self::VISIBLE_TAIL );
		while ( '' !== $tail && ! self::is_standalone_valid_utf8( $tail ) ) {
			$tail = self::without_leading_character( $tail );
		}

		return '' === $tail ? self::MASK : self::MASK . $tail;
	}

	/**
	 * Whether a header name is secret-bearing (case-insensitive).
	 *
	 * The catalog match first (the named spellings), then the class
	 * rule (t31-ocr15-1): a folded name that IS a credential suffix,
	 * or whose final separator-token is one, is secret-bearing the
	 * same way — see SENSITIVE_HEADER_NAME_SUFFIXES for the class and
	 * its boundary.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name.
	 * @return bool True when the header's value must always be masked.
	 */
	public static function is_sensitive_header_name( string $name ): bool {
		/*
		 * The delimiter is a legal-tchar CLASS, not the hyphen alone.
		 * HeaderMap's NAME_TOKEN_PATTERN (the single owner of the tchar
		 * grammar) admits fourteen non-alphanumeric tchars beside the
		 * letters and digits — ! # $ % & ' * + . ^ _ ` | ~ and the
		 * hyphen itself — so 'x_api_key' and 'x.api.key' are both
		 * legal header names, and the boundary once spoke neither
		 * (underscore: OCR round 43, t31-ocr43-1; the residual '.'
		 * slice and the rest of the census: t31-ocr44-2): the suffix
		 * class keyed on hyphen boundaries only, and every
		 * alternate-delimiter spelling of a credential name matched
		 * neither catalog nor class — the full secret rendered
		 * verbatim through every safe debug form, the r12-4 leak
		 * class over the grammar's own delimiter vocabulary. The
		 * classifier normalizes the WHOLE census to '-' ONCE, at the
		 * fold (the set structually tied to the pattern's own
		 * character class below — the one-owner spelling, never a
		 * hand-listed twin): the judged token
		 * stays the whole final segment over any delimiter spelling
		 * ('x-api-keychain', 'x_api_keychain', and 'x.api.keychain'
		 * all remain outside — the suffix bytes never span a
		 * separator), and hyphen-only names judge byte-identically.
		 *
		 * The census rides its ONE owner (OCR round 45, t31-ocr45-4):
		 * the class below was a second, hand-spelled copy of the
		 * grammar's own — the drift seam the t31-ocr8-8/t31-ocr40-3
		 * single-owner doctrine exists to close. The set IS
		 * HeaderMap::NAME_TOKEN_DELIMITER_CLASS now, the same named
		 * constant the grammar composes from: the two spellings
		 * cannot drift (the hyphen needs no mapping — it normalizes
		 * to itself, so the strtr spans the fourteen non-hyphen
		 * delimiters alone).
		 */
		$delimiters = HeaderMap::NAME_TOKEN_DELIMITER_CLASS;
		$folded     = strtr( AsciiFold::lower( $name ), $delimiters, str_repeat( '-', strlen( $delimiters ) ) );

		/*
		 * The boundary SEGMENTS before emptiness is judged (OCR round
		 * 46, t31-ocr46-4 — the EDGE-DELIMITER twin of the r12-4 leak
		 * class the ocr43-1/ocr44-2 closures claimed closed):
		 * 'Authorization.' is a legal RFC 7230 token (the trailing '.'
		 * is a tchar), and its fold 'authorization-' has an EMPTY
		 * final segment — the exact match failed and str_ends_with(
		 * '-authorization') failed over the empty-segment shape, so
		 * the credential rendered verbatim through every safe debug
		 * form. An empty BOUND segment never disqualifies a
		 * credential-bearing name: the judged name sheds its bound
		 * separators once at the fold (the class is symmetric — a
		 * leading '.Authorization' and a trailing 'Authorization_'
		 * mask the same), while an empty MID segment changes nothing
		 * the boundary already owned and a name of separators alone
		 * judges the empty string (no credential bytes).
		 */
		$judged = trim( $folded, '-' );
		if ( \in_array( $judged, self::SENSITIVE_HEADER_NAMES, true ) ) {
			return true;
		}

		foreach ( self::SENSITIVE_HEADER_NAME_SUFFIXES as $suffix ) {
			if ( $judged === $suffix || \str_ends_with( $judged, '-' . $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders a value so it is always VALID UTF-8 — the byte-level twin
	 * of mask()'s never-invalid contract, for values that render
	 * verbatim (verifier round t31-r8-6, the r4-13 doctrine on the
	 * header surface).
	 *
	 * A header value legally carries RFC 7230 obs-text (any high byte,
	 * t31-r1-19), and a Latin-1 value is obs-text the constructor must
	 * keep accepting — but its bytes are INVALID UTF-8, and
	 * json_encode() of the rendered line then returns FALSE: the log
	 * line is dropped, not degraded, the exact failure mode r4-13
	 * killed on the URL surface (by rejecting the input there — the
	 * URL constructor owes no obs-text hospitality). The render seam
	 * owes the same OUTCOME without rejecting the value: every byte of
	 * a well-formed sequence renders verbatim (the pinned obs-text
	 * rendering, e.g. a UTF-8 'café'), and every byte the canonical
	 * grammar cannot accept renders as its percent-encoded spelling
	 * ('%E9') — encoded, never destroyed, so the debug form stays
	 * diagnosable and the line always json_encodes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value about to render verbatim.
	 * @return string The same bytes when valid UTF-8, else invalid sequences percent-encoded.
	 */
	public static function utf8_for_safe_render( string $value ): string {
		/*
		 * The fast path rides the canonical walk (OCR round 40,
		 * t31-ocr40-3 — the t31-ocr8-8 doctrine this method's own
		 * body cites): the engine-level '//u' probe was a second
		 * spelling of UTF-8 validity beside the ONE validator, with
		 * no structural tie to it — a grammar fix landing on the
		 * walk silently drifted the fast path. One validator, both
		 * consumers: this early return and mask()'s standalone-tail
		 * proof ride the same walk.
		 */
		if ( self::is_standalone_valid_utf8( $value ) ) {
			return $value;
		}

		$rendered = '';
		$length   = \strlen( $value );
		for ( $i = 0; $i < $length; ) {
			$sequence = self::utf8_sequence_length_at( $value, $i );
			if ( $sequence > 0 ) {
				$rendered .= substr( $value, $i, $sequence );
				$i        += $sequence;
				continue;
			}

			/*
			 * A byte (or run) the canonical grammar cannot accept
			 * percent-encodes ONE byte at a time — the bytes after it
			 * get their own judgment.
			 */
			$rendered .= sprintf( '%%%02X', \ord( $value[ $i ] ) );
			++$i;
		}

		return $rendered;
	}

	/**
	 * The length of the well-formed UTF-8 sequence starting at $i, or 0
	 * when the byte there begins none — the canonical byte grammar's ONE
	 * spelling (OCR round 8, t31-ocr8-8: the regex table and the
	 * hand-rolled lead/continuation walk this file carried were two
	 * spellings of one grammar with no structural tie — a range fix
	 * landing on one silently drifted the other; both consumers ride
	 * this validator now, the regex twin is deleted).
	 *
	 * The lead byte's class fixes the sequence length and the
	 * first-continuation constraints that reject overlong and
	 * out-of-range spellings (C0/C1, E0 80-9F, ED A0-BF, F0 80-8F,
	 * F4 90-BF); later continuation bytes must be 80-BF; a sequence
	 * truncated by the string's end is not one. Byte-matched only,
	 * never the /u modifier, so an arbitrary byte string is simply
	 * judged, never rejected by the engine itself.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The bytes to judge.
	 * @param int    $i     The offset of the candidate lead byte.
	 * @return int The sequence's byte length (0 when no valid sequence starts at $i).
	 */
	private static function utf8_sequence_length_at( string $value, int $i ): int {
		$length = \strlen( $value );
		$lead   = \ord( $value[ $i ] );
		if ( $lead < 0x80 ) {
			return 1;
		}

		$sequence  = 0;
		$first_min = 0x80;
		$first_max = 0xBF;
		if ( $lead >= 0xC2 && $lead <= 0xDF ) {
			$sequence = 2;
		} elseif ( 0xE0 === $lead ) {
			$sequence  = 3;
			$first_min = 0xA0;
		} elseif ( ( $lead >= 0xE1 && $lead <= 0xEC ) || 0xEE === $lead || 0xEF === $lead ) {
			$sequence = 3;
		} elseif ( 0xED === $lead ) {
			$sequence  = 3;
			$first_max = 0x9F;
		} elseif ( 0xF0 === $lead ) {
			$sequence  = 4;
			$first_min = 0x90;
		} elseif ( $lead >= 0xF1 && $lead <= 0xF3 ) {
			$sequence = 4;
		} elseif ( 0xF4 === $lead ) {
			$sequence  = 4;
			$first_max = 0x8F;
		}
		if ( 0 === $sequence || $i + $sequence > $length ) {
			return 0;
		}

		$first = \ord( $value[ $i + 1 ] );
		if ( ( $first & 0xC0 ) !== 0x80 || $first < $first_min || $first > $first_max ) {
			return 0;
		}
		for ( $j = 2; $j < $sequence; $j++ ) {
			if ( ( \ord( $value[ $i + $j ] ) & 0xC0 ) !== 0x80 ) {
				return 0;
			}
		}

		return $sequence;
	}

	/**
	 * Whether every byte of the value belongs to exactly one
	 * well-formed sequence — mask()'s standalone-tail proof (the former
	 * regex twin's charge, riding the one spelling since t31-ocr8-8).
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The bytes to judge.
	 * @return bool True when the whole value is well-formed UTF-8.
	 */
	private static function is_standalone_valid_utf8( string $value ): bool {
		for ( $i = 0, $length = \strlen( $value ); $i < $length; ) {
			$sequence = self::utf8_sequence_length_at( $value, $i );
			if ( 0 === $sequence ) {
				return false;
			}
			$i += $sequence;
		}

		return true;
	}

	/**
	 * Counts characters (UTF-8 sequence starts) — a byte count that
	 * treats every continuation byte as part of its character.
	 *
	 * For well-formed UTF-8 this is the code-point count; for binary
	 * values it counts apparent sequence starts (a conservative
	 * approximation — the threshold errs toward masking).
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value to measure.
	 * @return int The character count.
	 */
	private static function count_characters( string $value ): int {
		$characters = 0;
		$length     = \strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( ( \ord( $value[ $i ] ) & 0xC0 ) !== 0x80 ) {
				++$characters;
			}
		}

		return $characters;
	}

	/**
	 * The byte range covering the last N characters (sequence starts).
	 *
	 * Walks back over continuation bytes to each sequence start; the
	 * slice may still begin mid-sequence when the value itself is not
	 * well-formed UTF-8 — the caller sheds leading characters until the
	 * remainder validates standalone.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value to slice.
	 * @param int    $count How many trailing characters to cover.
	 * @return string The byte slice covering the last $count characters.
	 */
	private static function tail_bytes_of_last_characters( string $value, int $count ): string {
		$found = 0;
		$start = 0;
		for ( $i = \strlen( $value ) - 1; $i >= 0 && $found < $count; $i-- ) {
			if ( ( \ord( $value[ $i ] ) & 0xC0 ) !== 0x80 ) {
				++$found;
				$start = $i;
			}
		}

		return substr( $value, $start );
	}

	/**
	 * The slice minus its leading character (sequence start plus its
	 * continuation bytes).
	 *
	 * @since 0.1.0
	 *
	 * @param string $tail The slice to shed from.
	 * @return string The remainder.
	 */
	private static function without_leading_character( string $tail ): string {
		$length = \strlen( $tail );
		$i      = 1;
		while ( $i < $length && ( \ord( $tail[ $i ] ) & 0xC0 ) === 0x80 ) {
			++$i;
		}

		return substr( $tail, $i );
	}
}
