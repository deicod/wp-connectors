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
	 * judges the 'token' segment, already covered. (CORRECTED at
	 * round 21, t31-glm21-1: that skip's premise was FALSE — the
	 * delimiters in '__RequestVerificationToken' are LEADING
	 * underscores, shed at the fold's bound-segment strip;
	 * 'verification' and 'token' are GLUED, the judged name
	 * 'requestverificationtoken' matched no suffix, and the documented
	 * spelling rendered its credential verbatim, driven. The member
	 * rides the class now.)
	 *
	 * Round 21 (t31-glm21-1, security — the .NET glued twin, the
	 * ocr57-2 skip's re-open condition met): '__RequestVerificationToken'
	 * is the anti-forgery header .NET's own MVC documentation spells —
	 * the token the form field of the same name carries rides the
	 * request header for AJAX posts — and its fold judged
	 * 'requestverificationtoken': the whole glued token as the final
	 * segment, no catalog entry, no suffix, so the anti-forgery
	 * credential rendered verbatim through every safe debug form
	 * (driven at HEAD) while the delimiter-spelled twins
	 * ('X-Request-Verification-Token', '__request_verification_token')
	 * masked via 'token'. One member speaks every delimiter spelling
	 * per the r50-1 boundary doctrine (the .NET spelling's leading
	 * underscores fold and trim onto the judged name; every segment
	 * tail judges the same). The round's r57-2-shaped sweep found no
	 * second glued member that meets the named-vendor bar — every
	 * remaining vendor-canonical credential header (AWS
	 * 'X-Amz-Security-Token', Shopify 'X-Shopify-Access-Token', APIM
	 * 'Ocp-Apim-Subscription-Key') hyphenates or already rides a
	 * flattened member. The boundary is unchanged:
	 * 'x-requestverificationtokenlog' stays verbatim (the suffix bytes
	 * never span the segment), and glue with no separator before the
	 * member ('xrequestverificationtoken') stays outside as ever.
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
	 * The session identifier joins the class (t31-glm43-8, R43-13 —
	 * the review's PLAUSIBLE policy gap driven at HEAD): 'X-Session-
	 * Id'/'Session-Id' — the final segment 'id' matching no suffix —
	 * rendered its value verbatim through every masked render surface
	 * while the catalog's own cookie row masks the SAME credential
	 * material on the response side (OWASP's session-management
	 * guidance names the session identifier a secret; the file's own
	 * prose calls the CSRF token 'the session credential').
	 * 'session-id' rides the suffix class with its GLUED twin
	 * 'sessionid' (t31-glm44-2, R44-2 — the sole multi-token member
	 * that landed without one; JSESSIONID and ASP.NET_SessionId are
	 * canonical glued spellings of the same credential; JSESSIONID
	 * — the Servlet spec's own all-glued spelling, no delimiter to
	 * fold — rides as its own member, the spanning boundary intact)
	 * — the r24-4
	 * curation bar met
	 * the ocr57-2 way (the finding is the writer); the boundary
	 * unchanged ('x-session-idle', 'x-session-count' — tails that are
	 * not 'session-id' — stay verbatim). [t31-glm53-10: this session-id
	 * narration rode a SECOND stacked docblock whose presence stranded
	 * the 220-line history block and its @var above it — merged here
	 * so the tag travels with the const, the R48-14/R52-11 class in
	 * the very file round 52 fixed.]
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */

	/*
	 * t31-glm60-3 [R60-4, driven + vendor-doc]: GitHub's documented
	 * 'X-GitHub-OTP' 2FA request header (docs.github.com/en/rest —
	 * the one-time password sent in the header, octokit/go-github
	 * implementing it) folds to a final segment 'otp' matching
	 * neither catalog nor suffix — the one-time password rendered IN
	 * FULL through every safe debug form. One 'otp' member speaks
	 * every delimiter spelling (r50-1); the over-mask blessing rides
	 * the tier as ever (a non-credential '-otp' tail masked in
	 * DEBUG errs safe).
	 *
	 * t31-glm59-3 [R59-6, driven]: 'hmac-sha256'/'hmac-sha512' join
	 * as their own two-token entries (the 'signature-256' shape) —
	 * Shopify's documented webhook verification headers
	 * ('X-Shopify-Hmac-Sha256' and the -Sha512 sibling) carry the
	 * base64 HMAC of the body under the app's API secret key,
	 * credential-derived material meeting the r24-4/ocr61-2
	 * vendor-documented curation bar; the fold leaves the judged
	 * final segment 'sha256', a token no credential name spells, so
	 * the tail WITH the algorithm name is judged whole — the same
	 * final-segment boundary one entry longer.
	 */
	const SENSITIVE_HEADER_NAME_SUFFIXES = array( 'auth', 'authorization', 'authentication', 'token', 'secret', 'key', 'password', 'apikey', 'subscriptionkey', 'secretkey', 'accesskey', 'accesstoken', 'refreshtoken', 'clientsecret', 'securitytoken', 'sharedsecret', 'csrftoken', 'requestverificationtoken', 'signature', 'signature-256', 'hmac-sha256', 'hmac-sha512', 'otp', 'session-id', 'sessionid', 'jsessionid' );

	/**
	 * The percent-encoded delimiter triples (judged case-insensitively
	 * at every consult): query '%3f', fragment '%23', assignment '%3d'.
	 *
	 * One vocabulary, both seats (t31-glm54-1): the gate's layered
	 * arming probe and the boundary scan's opener/assignment resolution
	 * judge the SAME triples — a future delimiter widening lands at
	 * this list or nowhere (the indexed composition names each member's
	 * role at its seat, the HARNESS_MODEL_ID house idiom).
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const ENCODED_DELIMITER_TRIPLES = array( '%3f', '%23', '%3d' );

	/**
	 * The percent-encoded PAIR-SEPARATOR spellings (t31-glm60-2
	 * [R60-10]): the R56-1 separator family's encoded members — '%26'
	 * the query/cookie-pair separator, '%3b' the cookie/matrix one —
	 * ONE list beside the opener/assignment triples, the R54-1 'a
	 * delimiter widening lands at the list or nowhere' doctrine the
	 * file itself cites (the round-59 container arm spelled them
	 * inline).
	 *
	 * @since 0.1.0
	 */
	const ENCODED_PAIR_SEPARATORS = array( '%26', '%3b' );

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

		/*
		 * t31-glm48-5 [R48-5, driven — the visible tail judged the
		 * CONTAINER, never an embedded credential]: an OTP-class
		 * value riding at the end of a longer Location/Referer
		 * query ('https://client.example/cb?code=BCJK-3502')
		 * cleared the container-length threshold and showed four of
		 * the code's nine characters through every safe debug form —
		 * defeating the doctrine's own head clause (user codes and
		 * device codes of twelve characters or fewer never show a
		 * tail) through the one consumption that adjudication never
		 * considered: the whole-value masking the render seam
		 * applies to URL-shaped headers. A value carrying a query
		 * shape judges the FINAL parameter's value instead: a short
		 * trailing credential renders the bare mask (the standalone
		 * doctrine applied to the embedded code), a long one keeping
		 * the correlation tail (a long query-embedded token
		 * correlates the same way a long bare value does).
		 *
		 * t31-glm49-5 [R49-7, driven — the credential rides the
		 * FRAGMENT and the equals-less parameter too]: the round-48
		 * guard keyed on the LAST '?' plus a following '=', so the
		 * RFC 6749 implicit-flow fragment spelling
		 * ('…/cb#code=BCJK-3502') and an equals-less final parameter
		 * ('…/cb?BCJK-3502') still rendered four of the code's nine
		 * characters. The boundary is the LAST of '?' and '#'
		 * (whichever delimiter opens the tail), the credential run
		 * after the last '=' beyond it — or after the delimiter
		 * itself when no '=' follows: a short trailing run the bare
		 * mask, a long one keeping the tail.
		 *
		 * t31-glm50-5 [R50-10, driven — the percent-encoded
		 * delimiters]: the boundary recognized only the LITERAL
		 * '?'/'#'/'=' bytes, so an OAuth redirect_uri carrying its
		 * own callback QUERY percent-encoded inside the outer query
		 * ('…redirect_uri=https%3A%2F%2Fclient.example%2Fcb%3Fcode
		 * %3DBCJK-3502' — the RFC 6749 authorization-request shape
		 * the Location channel actually carries) defeated the
		 * final-parameter judgment and leaked four of the code's
		 * nine characters, the '%23'-spelled fragment and the
		 * '%3D'-spelled assignment the same. The delimiters are
		 * matched in their percent-encoded spellings too,
		 * case-insensitively — the run after the last
		 * delimiter-equivalent judging exactly as the literal one.
		 *
		 * t31-glm51-2 [R51-2+R51-4+R51-10+R51-15, driven — the
		 * boundary's four arms, one revision]: (1) the run was
		 * sliced at $credential_at + 1 regardless of the WINNING
		 * delimiter's spelling — a percent-encoded match spans
		 * THREE bytes, so the run swallowed the delimiter's
		 * leftover '3D' bytes and inflated by 2, an 11- or
		 * 12-character OTP-class credential behind an encoded
		 * delimiter clearing the threshold and rendering four
		 * characters while its literal-delimiter twin rendered the
		 * bare mask (the screen's own two arms answering opposite
		 * verdicts). (2) The encoding arms enumerated exactly ONE
		 * layer — a redirect_uri echoed DOUBLE-encoded
		 * ('%253F'/'%253D', a server that re-encodes an
		 * already-encoded parameter) leaked the same four
		 * characters; the judgment rides the once-DECODED view
		 * beside the raw now, one mechanism covering every layer
		 * by construction instead of a fourth enumerated spelling.
		 * (3) The assignment-bearing NON-QUERY container
		 * ('Cookie a=X', 'PHPSESSID=X') never entered the branch —
		 * the whole-value length threshold cleared and half an
		 * eight-character session id rendered through every safe
		 * debug surface; the gate admits any URL-shaped OR
		 * '='-bearing value (a bare opaque key carrying an '%3F'
		 * triple in its own bytes keeps its correlation tail — no
		 * URL shape, no assignment, the encoded arms never arming
		 * on it: the round-50 anywhere-stripos over-mask closing
		 * with the same gate). (4) The space-delimited scheme
		 * prefix ('Bearer X') stays OUTSIDE the boundary — no
		 * assignment byte to anchor on, and scheme-prefix
		 * knowledge is HeaderMap's vocabulary, not this owner's.
		 */

		/*
		 * The GATE rides the RAW view (an opaque key whose own bytes
		 * spell '%3F' decodes to a '?' — the decoded view alone must
		 * never arm the boundary on a value the raw spelling never
		 * shaped); within the gate, BOTH views judge, the decoded
		 * one resolving every encoding layer by construction.
		 *
		 * t31-glm52-5 [R52-6+R52-8+R52-10, driven — the gate's three
		 * further arms]: (1) the '=' conjunct armed on an '=' ANYWHERE
		 * — a long opaque base64 token's PADDING ('abcdefghijklmnopqrs=')
		 * carried its '=' at the very end, the empty run after it judged
		 * short, and the correlation tail died through every safe debug
		 * surface (driven: the bare mask where the unpadded twin keeps
		 * its tail). The assignment arm requires a NON-EMPTY run after
		 * the '=' — trailing padding never arms the gate; an '=' mid-
		 * token with a short run behind it still does (the conscious
		 * err-safe trade, recorded with the round). (2) The decoded
		 * view judged exactly ONE rawurldecode — a TRIPLE-encoded
		 * delimiter ('%25253F', the shape a proxy chain re-encoding an
		 * already-re-encoded parameter produces) decoded to '%253F',
		 * matched no encoded arm, and leaked the same four characters
		 * (driven). The decode runs to FIXPOINT now — each layer
		 * strictly shrinks the bytes, the loop terminating on the first
		 * unchanged pass. (3) The decoded pass is SKIPPED when the raw
		 * value carries no '%' byte — rawurldecode returns such a view
		 * unchanged and the helper judged the identical string twice.
		 */

		/*
		 * t31-glm53-6 [R53-4, driven leak — the padding arm disarmed
		 * the ENCODED boundary too]: 'redirect%3Fcode%3DBCJK3502='
		 * carries its only raw literal '=' at the very end, so the
		 * round-52 non-empty-run refinement left the gate unarmed
		 * and the fixpoint-decode pass never judged the view whose
		 * decode spells the real delimiters — '…502=' rendered
		 * where the round-51 code (an '=' anywhere arming) rendered
		 * the bare mask. The '=' stays the ANCHOR the round-51
		 * doctrine demands (an '='-less opaque key with an encoded
		 * triple never arms — R51-15's pinned tail, untouched); the
		 * non-empty-run refinement yields only when an encoded
		 * delimiter triple rides the raw view — the decoded shape
		 * is real whatever the raw '=' placement — while the pure
		 * padding shape ('abcdefghijklmnopqrs=', no '%' byte) keeps
		 * its correlation tail exactly as round 52 pinned.
		 *
		 * t31-glm54-1 [R54-1, driven leak — the yield armed only the
		 * SINGLE-layer spelling]: the round-53 yield stripos'd the
		 * one-layer triples, and '%253F' contains no '%3f' substring
		 * — so the double- and triple-encoded spellings whose only
		 * raw '=' is the trailing pad left the gate unarmed and the
		 * fixpoint-decode pass never judged them ('redirect%253Fcode
		 * %253DBCJK-3502=' rendered '…502=' at HEAD, the R53-4 leak
		 * one encoding layer over, regressed against the round-51
		 * parent). The yield consults the LAYERED probe now — every
		 * encoding layer's own raw spelling judged in turn, the same
		 * fixpoint walk the decode pass rides — so the multi-layer
		 * spellings arm exactly as the single-layer one does. The
		 * '='-less R51-15 tail is untouched (no '=' means the arm
		 * cannot fire at all), and the pure padding shape keeps its
		 * tail (no '%' byte means no layer ever spells a triple).
		 */

		/*
		 * t31-glm55-5 [R55-7, driven leak — the trailing pad disarmed
		 * an EARLIER mid-value '=']: the assignment arm consulted
		 * only the LAST '=' (strrpos), so a two-'=' composition — a
		 * real short credential behind an '=' plus a trailing
		 * base64 pad — left the gate unarmed and the correlation
		 * tail rendered through every safe debug surface
		 * ('a=BCJK-3502xy=' rendered '…2xy=' where its padding-free
		 * twin rendered the bare mask; the Cookie channel rendered
		 * 'sid=abcdefghi=' with four of the nine session-id bytes,
		 * and the realistic 'session=dGVzdA==' cookie leaked the
		 * base64 tail where 'session=dGVzdA' masks whole), the
		 * R52-6 mid-'=' err-safe trade broken in the one
		 * composition round 53 fixed for the ENCODED arm alone.
		 * The arm fires when the FIRST '=' is followed by a byte —
		 * exactly "some '=' carries a non-empty run" — while the
		 * '=' stays the ANCHOR (no '=' anywhere and the arm never
		 * fires, the R51-15 '='-less tail untouched; the pure
		 * padding shape's single trailing '=' followed by nothing
		 * stays unarmed); the run judgment keeps the helper's own
		 * LAST-'=' boundary, so a two-'=' shape whose trailing run
		 * is empty or short always masks (the err-safe direction,
		 * an unpinned double-pad opaque key newly masking with it).
		 */
		$eq_first = strpos( $value, '=' );
		$eq_armed = false !== $eq_first && ( $eq_first + 1 < \strlen( $value ) || self::raw_carries_encoded_delimiter( $value ) );

		/*
		 * t31-glm58-2 [R58-2, driven at HEAD by both the review and
		 * the driver — the R51-15 trade reopened by a NEW shape, its
		 * pinned row untouched]: a whole CONTAINER stored
		 * percent-encoded (an OAuth state cookie carrying
		 * 'state%3Dxyz%26code%3DBCJK-3502') has NO raw anchor byte
		 * at all — no '://', no raw '?', '#', or '=' — so the gate
		 * never armed and the whole-value tail clause printed four
		 * of the nine device-code characters ('…3502') through every
		 * safe debug form while every raw-delimiter twin masked. The
		 * container SIGNATURE arms: an encoded assignment BESIDE an
		 * encoded pair-separator (%3d + %26, case-insensitive per
		 * the standing vocabulary) — the two-parameter shape a bare
		 * opaque key never carries (R51-15's protected row holds a
		 * lone opener triple and no pair-separator: its tail
		 * stands). Monotone mask-more: arming only enters values
		 * into the boundary judgment, whose decoded view resolves
		 * every layer and whose run restarts after the R56-1
		 * separator.
		 *
		 * t31-glm59-2 [R59-5, driven — the round-58 signature one
		 * vocabulary member and one layer short]: the pair-separator
		 * class is the R56-1 family WHOLE ('&' the query/cookie-pair
		 * separator, ';' the cookie/matrix one — %3b beside %26, the
		 * raw spellings of both beside the encoded), the layers EVERY
		 * one (a double-encoded container leaking where its
		 * single-encoded control masked — the fixpoint walk the
		 * decode pass itself rides), and the spellings MIXED (a raw
		 * '&' beside an encoded assignment: 'state%3Dxyz&code=X'
		 * leaking where its raw twin masked). The walk judges each
		 * layer's own spelling, either arm enough — the R51-15 row
		 * (a lone opener triple, no assignment, no separator at ANY
		 * layer) stays outside every shape.
		 */

		/*
		 * t31-glm60-2 [R60-3, driven — the round-59 walk required the
		 * assignment and the separator to co-occur within ONE layer's
		 * view]: a cross-layer mixed spelling — the assignment
		 * single-encoded, the separator one layer deeper (round 59's
		 * own 'mixed' inner spelling re-encoded once by an outer
		 * layer) — never armed the gate at any layer and the
		 * whole-value tail clause printed four of nine device-code
		 * characters ('state%3Dxyz%2526code%3DBCJK-3502' → '…3502'
		 * where its same-layer twin masked). The conjuncts compose
		 * across the walk now: ANY layer spelled '%3d' AND ANY layer
		 * carried a separator (encoded or raw, the R56-1 family) —
		 * one pass over the ONE per-layer walker, monotone mask-more
		 * by construction, the R51-15 row (a lone opener triple, no
		 * assignment at ANY layer, no separator at ANY layer) and the
		 * alone-spelling keys staying outside every shape.
		 */
		$container_saw_assignment = false;
		$container_saw_separator  = false;
		$container_armed          = self::any_layer_carries(
			$value,
			static function ( string $view ) use ( &$container_saw_assignment, &$container_saw_separator ): bool {
				if ( false !== stripos( $view, '%3d' ) ) {
					$container_saw_assignment = true;
				}
				foreach ( self::ENCODED_PAIR_SEPARATORS as $separator ) {
					if ( false !== stripos( $view, $separator ) ) {
						$container_saw_separator = true;
						break;
					}
				}
				if ( false !== strpos( $view, '&' ) || false !== strpos( $view, ';' ) ) {
					$container_saw_separator = true;
				}

				return $container_saw_assignment && $container_saw_separator;
			}
		);
		if ( ! $container_armed && $container_saw_assignment && $container_saw_separator ) {
			$container_armed = true;
		}
		$raw_gated = false !== strpos( $value, '://' ) || false !== strrpos( $value, '?' ) || false !== strrpos( $value, '#' ) || $eq_armed || $container_armed;
		if ( $raw_gated && self::value_carries_short_embedded_credential( $value ) ) {
			return self::MASK;
		}
		if ( $raw_gated && false !== strpos( $value, '%' ) ) {
			$decoded = $value;
			while ( true ) {
				$next_decode = rawurldecode( $decoded );
				if ( $next_decode === $decoded ) {
					break;
				}
				$decoded = $next_decode;
			}
			if ( self::value_carries_short_embedded_credential( $decoded ) ) {
				return self::MASK;
			}
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
	 * Whether a percent-encoded delimiter triple rides the value at ANY
	 * encoding layer (t31-glm54-1): each layer's own raw spelling is
	 * judged in turn — '%253F' carries no triple at layer 0 and its
	 * once-decoded view '%3F' does — so the gate arms on the multi-layer
	 * spellings exactly as it arms on the single-layer one, whatever the
	 * raw '=' placement. The walk is mask()'s fixpoint decode itself:
	 * each decode strictly shrinks the view, so the loop terminates, and
	 * a view with no '%' byte (or one whose escapes decode no further)
	 * answers false without ever having spelled a triple at any layer.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The raw view.
	 * @return bool True when any encoding layer spells an encoded delimiter.
	 */
	private static function raw_carries_encoded_delimiter( string $value ): bool {
		return self::any_layer_carries(
			$value,
			static function ( string $view ): bool {
				foreach ( self::ENCODED_DELIMITER_TRIPLES as $triple ) {
					if ( false !== stripos( $view, $triple ) ) {
						return true;
					}
				}

				return false;
			}
		);
	}

	/**
	 * Whether ANY decode layer of a value satisfies a per-layer
	 * predicate (t31-glm60-2 [R60-10, the one per-layer walker]): the
	 * fixpoint decode walk — each decode strictly shrinks the view, so
	 * the loop terminates; a view with no '%' byte (or one whose
	 * escapes decode no further) ends the walk. The round-59 container
	 * arm hand-spelled the loop a THIRD time beside this owner and the
	 * decoded pass; every per-layer judgment rides the ONE walker now.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $value     The raw view.
	 * @param callable $judges_layer Invoked per layer; true stops the walk.
	 * @return bool True when any layer satisfied the predicate.
	 */
	private static function any_layer_carries( string $value, callable $judges_layer ): bool {
		$view = $value;
		while ( true ) {
			if ( $judges_layer( $view ) ) {
				return true;
			}
			if ( false === strpos( $view, '%' ) ) {
				return false;
			}
			$decoded = rawurldecode( $view );
			if ( $decoded === $view ) {
				return false;
			}
			$view = $decoded;
		}
	}

	/**
	 * Whether ONE view of a value carries a short credential at its
	 * tail (t31-glm51-2): the caller's RAW GATE owns the value-shape
	 * question — this helper judges only views already URL-shaped or
	 * assignment-bearing (the internal gate the round-52 fold removed
	 * answered both conjuncts true for every reachable call, the
	 * decoded view preserving the raw literals rawurldecode cannot
	 * touch). The boundary is the LAST opener-equivalent ('?','#',
	 * '%3f','%23') and the last assignment-equivalent ('=','%3d')
	 * beyond it, the run sliced after the WINNING delimiter's own
	 * byte length (one or three — the round-50 inflation closing),
	 * and the run judged against MIN_LENGTH_FOR_VISIBLE_TAIL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value One view of the value (raw, or decoded to fixpoint).
	 * @return bool True when the trailing run is a short embedded credential.
	 */
	private static function value_carries_short_embedded_credential( string $value ): bool {
		/*
		 * The encoded arms compose the ONE triple vocabulary
		 * (t31-glm54-1): [0] is the query opener, [1] the fragment
		 * opener, [2] the assignment — the indexed composition names
		 * each member's role at its seat (the HARNESS_MODEL_ID house
		 * idiom), so a delimiter widening lands at the list or
		 * nowhere.
		 */
		$query_at  = strrpos( $value, '?' );
		$encoded_q = strripos( $value, self::ENCODED_DELIMITER_TRIPLES[0] );
		$frag_at   = strrpos( $value, '#' );
		$encoded_f = strripos( $value, self::ENCODED_DELIMITER_TRIPLES[1] );
		$open_at   = -1;
		$open_len  = 0;
		foreach ( array(
			array( $query_at, 1 ),
			array( false === $encoded_q ? -1 : $encoded_q, 3 ),
			array( $frag_at, 1 ),
			array( false === $encoded_f ? -1 : $encoded_f, 3 ),
		) as $opener ) {
			if ( false !== $opener[0] && $opener[0] > $open_at ) {
				$open_at  = $opener[0];
				$open_len = $opener[1];
			}
		}

		$assign_at  = strrpos( $value, '=' );
		$encoded_a  = strripos( $value, self::ENCODED_DELIMITER_TRIPLES[2] );
		$assign_len = 1;
		if ( false !== $encoded_a && ( false === $assign_at || $encoded_a > $assign_at ) ) {
			$assign_at  = $encoded_a;
			$assign_len = 3;
		}

		if ( false !== $assign_at && $assign_at > $open_at ) {
			$run_at = $assign_at + $assign_len;
		} elseif ( $open_at >= 0 ) {
			$run_at = $open_at + $open_len;
		} else {
			/*
			 * t31-glm52-5 [R52-7, driven over-mask — the branch's
			 * $assign_at is ALWAYS false here]: the no-opener arm is
			 * reachable only for a '://'-SHAPED view (no '?', no '#',
			 * no '=' — the gate's other conjuncts answered for it),
			 * and the old spelling sliced substr($value, false + 1) —
			 * the false+1 coercion dropping the FIRST byte and judging
			 * the remainder as the run, a 13-character scheme-bearing
			 * value with no query, no fragment, and no assignment
			 * rendering the bare mask (driven) where its 14-character
			 * twin kept its tail. No opener and no assignment means no
			 * embedded credential at any boundary — the view keeps its
			 * tail.
			 */
			return false;
		}

		/*
		 * t31-glm56-1 [R56-1, driven fail-open — no parameter
		 * SEPARATOR in the boundary's vocabulary]: the run once
		 * stretched from the winning delimiter to the view's end,
		 * so when the FINAL parameter is equals-less the last '='
		 * belongs to an EARLIER sibling and the judged run spanned
		 * both parameters — an OTP-class credential riding behind
		 * a sibling kept its visible tail through every safe debug
		 * form ('https://client.example/cb?state=xyz&BCJK-3502'
		 * rendered '…3502' where its equals-bearing and final-only
		 * twins render the bare mask; the cookie separator ';' and
		 * the fixpoint-decode pass over '%26' leaked identically,
		 * the decoded view judged with the same separator-less
		 * vocabulary). The FINAL parameter judges (the R48-7
		 * doctrine's own words): the run restarts after the last
		 * parameter separator beyond the winning delimiter — '&' the
		 * query/cookie-pair separator, ';' the cookie/matrix one
		 * (their percent-encoded spellings ride the decoded view,
		 * where they are these bytes). The separator only ever
		 * moves the run start FORWARD — the run shortens, a short
		 * run stays short — so no verdict can loosen, only a leak
		 * can close (every earlier row's run carries no separator).
		 */
		$separator_at = false;
		foreach ( array( '&', ';' ) as $separator ) {
			$found = strrpos( $value, $separator, $run_at );
			if ( false !== $found && ( false === $separator_at || $found > $separator_at ) ) {
				$separator_at = $found;
			}
		}
		if ( false !== $separator_at ) {
			$run_at = $separator_at + 1;
		}

		$run = (string) substr( $value, $run_at );

		return self::count_characters( $run ) <= self::MIN_LENGTH_FOR_VISIBLE_TAIL;
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
