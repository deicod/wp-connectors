<?php
/**
 * Device-authorization session value object (Task 3.1).
 *
 * The neutral result shape of a device-authorization request: the codes
 * the user confirms, where they confirm them, how fast the poll may
 * run, and when the session dies. The SHAPE is fixed by the flow; the
 * endpoints, client identifiers, and poll implementation are per-plugin
 * provider config — none of them live here.
 *
 * Pure value object: immutable, constructor-validated, no environment
 * access.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Flow;

use DateTimeImmutable;
use Deicod\WpConnectors\Shared\Http\HeaderMap;
use Deicod\WpConnectors\Shared\Http\Url;
use Deicod\WpConnectors\Shared\Support\SecretMask;
use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable, constructor-validated device-authorization session.
 *
 * @since 0.1.0
 */
final class DeviceAuthorizationSession {

	/**
	 * Device code (the poll credential; provider spellings differ, the role does not).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $device_code;

	/**
	 * User code (what the admin types at the verification page).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $user_code;

	/**
	 * Verification page the admin opens (the validated spelling — the
	 * §4.1 step-1 edge strip applies before storing, t31-ocr61-1).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $verification_uri;

	/**
	 * Minimum poll interval in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $interval_seconds;

	/**
	 * Absolute session expiry.
	 *
	 * @since 0.1.0
	 *
	 * @var DateTimeImmutable
	 */
	private readonly DateTimeImmutable $expires_at;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string            $device_code      Device code (non-empty).
	 * @param string            $user_code        User code (non-empty).
	 * @param string            $verification_uri Verification page (absolute http(s) URL; stored at its validated spelling — the §4.1 step-1 edge strip applies before storing).
	 * @param int               $interval_seconds Minimum poll interval (at least 1 second).
	 * @param DateTimeImmutable $expires_at       Absolute session expiry.
	 * @throws InvalidArgumentException When any field violates the contract above.
	 */
	public function __construct( string $device_code, string $user_code, string $verification_uri, int $interval_seconds, DateTimeImmutable $expires_at ) {
		if ( '' === trim( $device_code ) ) {
			throw new InvalidArgumentException( 'The device code must be a non-empty string.' );
		}
		if ( '' === trim( $user_code ) ) {
			throw new InvalidArgumentException( 'The user code must be a non-empty string.' );
		}

		/*
		 * Both codes are PROVIDER-SUPPLIED strings (RFC 8628 §3.2), so
		 * they ride the ONE control-byte guard (t31-r12-5): with only
		 * the non-empty screen, a raw CRLF constructed and print_r()
		 * forged lines in the MASKED debug tail below (the mask keeps
		 * the last four characters, controls included — reproduced),
		 * the forged-log-line channel r1-19/r2-1/r11-5 closed on the
		 * URL and header surfaces but not on the provider-supplied
		 * code positions.
		 */
		HeaderMap::assert_no_control_bytes( $device_code, 'The device code' );
		HeaderMap::assert_no_control_bytes( $user_code, 'The user code' );

		/*
		 * The STORED spelling is the VALIDATED spelling (OCR round 61,
		 * t31-ocr61-1 — the r57-4 class swept to this VO): the §4.1
		 * step-1 edge strip inside Url::parse_validated() judged the
		 * edge-STRIPPED spelling while $verification_uri kept the
		 * caller's raw bytes, so an accepted
		 * "https://device.example/verify\n" constructed with
		 * verification_uri() — the RAW channel handed to the
		 * authorization redirect — returning edge-control-bearing
		 * bytes no screen ever judged (driven at HEAD): the exact
		 * raw/validated divergence HttpRequest refused at
		 * t31-ocr57-4, reopening the forged-log-line/
		 * broken-redirect-header class. The strip rides the ONE owner
		 * Url owns, BEFORE storing: the parse below judges exactly the
		 * bytes stored, and a URI with no edge bytes stores
		 * byte-exact.
		 *
		 * Census (the sweep this comment owes): verification_uri is
		 * the ONE URI-bearing field this VO carries — RFC 8628 §3.2's
		 * verification_uri_complete (the query-bearing twin) and any
		 * other URI a provider may send belong to the future flow
		 * parser, not this shape, and the masked view's
		 * verification_uri derives through Url::redacted() from this
		 * same stored spelling — no second stored URI channel exists
		 * to sweep.
		 */
		$verification_uri = Url::strip_edge_control_or_space( $verification_uri );
		Url::parse_validated( $verification_uri );

		/*
		 * The INTERVAL FLOOR is this VO's own decision, not the
		 * RFC's (OCR round 49, t31-ocr49-14): RFC 8628 §3.2
		 * defines `interval` with no lower bound — only the ABSENT
		 * field defaults to 5 seconds — so a provider may answer 0
		 * ("no minimum") and a flow parser that passes such a
		 * payload here meets this refusal. It is deliberate: a
		 * 0-second floor is a busy-loop against the token endpoint,
		 * the exact hammering §3.5's interval exists to prevent, and
		 * `interval_seconds` types as a positive int by construction
		 * (the docblock's "at least 1 second" contract rides the
		 * same decision). A future parser pre-normalizes or refuses
		 * 0 with a verdict of its own naming this floor — never an
		 * opaque throw the payload cannot explain.
		 */
		if ( $interval_seconds < 1 ) {
			throw new InvalidArgumentException( 'The poll interval must be at least one second.' );
		}

		$this->device_code      = $device_code;
		$this->user_code        = $user_code;
		$this->verification_uri = $verification_uri;
		$this->interval_seconds = $interval_seconds;
		$this->expires_at       = $expires_at;
	}

	/**
	 * Device code.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function device_code(): string {
		return $this->device_code;
	}

	/**
	 * User code.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function user_code(): string {
		return $this->user_code;
	}

	/**
	 * Verification page URI — the VALIDATED spelling (the stored-form
	 * contract, t31-ocr61-1): exactly the bytes Url::parse_validated()
	 * judged, so the authorization redirect channel never carries edge
	 * control-or-space bytes no screen ever judged.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function verification_uri(): string {
		return $this->verification_uri;
	}

	/**
	 * Minimum poll interval in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function interval_seconds(): int {
		return $this->interval_seconds;
	}

	/**
	 * Absolute session expiry.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function expires_at(): DateTimeImmutable {
		return $this->expires_at;
	}

	/**
	 * Safe debug rendering for the serialization channel — print_r(),
	 * var_dump(), and every debugger that walks object properties
	 * (verifier round t31-r11-5).
	 *
	 * The device code is the poll credential (RFC 8628 §3.2 — the
	 * client's proof at the token endpoint); the user code is masked
	 * beside it, conservative by design: it is the pairing capability
	 * that binds an authorization to this session at the verification
	 * page, and a dump is a display surface, not a trust boundary. Both
	 * mask through the one vocabulary (SecretMask::mask()); the
	 * verification URI renders in its REDACTED shape (scheme://host[:port]
	 * /path, the HttpRequest redaction seam — userinfo, query, and
	 * fragment dropped, t31-ocr14-1) and the timing facts are public.
	 *
	 * Rides the same masked view as __serialize() below (OCR round 3,
	 * t31-ocr3-1) — one vocabulary owner, both channels.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Masked codes plus the public facts, never containing the credentials.
	 */
	public function __debugInfo(): array {
		return $this->masked_view();
	}

	/**
	 * The serialize() channel rides the same masked view (OCR round 3,
	 * t31-ocr3-1 — the direct follow-on of the ocr2-1 doctrine: the
	 * HTTP value objects closed this channel in t31-ocr1-8, the token
	 * carrier and grant in t31-ocr2-1, and the device session was the
	 * last credential-bearing VO with a dump hook but no serialize
	 * hook).
	 *
	 * Un-hooked, serialize() bypasses __debugInfo() by engine design
	 * and emits the raw property tree — the device code (the poll
	 * credential) and the user code (the pairing capability) in
	 * cleartext — for serialize() of the session itself and of every
	 * container holding it (a queue payload, a cache entry, a
	 * PendingAuthorization whose own __serialize() carries the session
	 * as the OBJECT so this hook applies). The masked view is a
	 * SNAPSHOT, not a round-trip payload: this version owns no storage
	 * serialization for the session (a pending flow is built through
	 * for_device(), never unserialized), and __unserialize() below
	 * refuses the safe form.
	 *
	 * var_export() stays the one channel EXCLUDED by engine design (no
	 * hook exists — the raw dump is display material); its
	 * reconstruction channel, __set_state(), refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Masked codes plus the public facts, never containing the credentials.
	 */
	public function __serialize(): array {
		return $this->masked_view();
	}

	/**
	 * A masked payload is not a reconstruction source — it refuses.
	 *
	 * The safe forms are lossy by design (both codes are masked, so
	 * nothing can rebuild a session from them); unserialize() on the
	 * __serialize() payload throws instead of half-initializing typed
	 * properties against masked fields.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data The masked payload (never a source of truth).
	 * @return never
	 * @throws RuntimeException Always — the masked snapshot is not a round-trip payload.
	 */
	public function __unserialize( array $data ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the payload to the hook; the refusal is the contract, the payload is not read.
		throw new RuntimeException( 'A masked device-authorization session is a snapshot, not a round-trip payload — reconstruct through the constructor, never from a serialization of its own safe form.' );
	}

	/**
	 * The var_export() eval channel refuses the same way.
	 *
	 * The var_export() call itself dumps the raw property tree through
	 * no hook (engine design — the one channel the masking contract
	 * cannot ride, named as excluded in this class's docblocks), but
	 * the dump it produces is executable code: evaluating it calls
	 * __set_state(), which refuses — an exported session never
	 * reconstructs from its own raw dump.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $properties The exported property tree.
	 * @return never
	 * @throws RuntimeException Always — the raw dump is not a reconstruction source.
	 */
	public static function __set_state( array $properties ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the export to the hook; the refusal is the contract, the tree is not read.
		throw new RuntimeException( 'A masked device-authorization session cannot be reconstructed from an exported property tree — the raw dump is display material, never a payload.' );
	}

	/**
	 * The masked snapshot the dump and serialize channels render — the
	 * ONE view both hooks ride (OCR round 3, t31-ocr3-1), so the two
	 * channels cannot drift. The verification URI renders redacted
	 * (t31-ocr14-1): scheme://host[:port]/path only, never the
	 * userinfo, query, or fragment a credential-carrying URI would
	 * leak in cleartext.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Masked codes, the redacted verification URI, and the public facts, never containing the credentials.
	 */
	private function masked_view(): array {
		/*
		 * The URI renders through the ONE redaction seam the HTTP
		 * contract owns (OCR round 14, t31-ocr14-1; one OWNER since
		 * OCR round 31, t31-ocr31-6): Url::redacted() glues the
		 * scheme://authority/path shape — the same static
		 * HttpRequest::redacted_url() derives from, never a hand-twin
		 * rebuild — dropping userinfo, query, and fragment. A
		 * provider-supplied verification_uri can carry credentials in
		 * any of the three (throttled providers embed one-time tokens
		 * in the query; parse_validated accepts userinfo by design),
		 * and the masked view rendered the raw string verbatim — the
		 * exact cleartext class the VO's own masking exists to
		 * prevent. The parse cannot throw here: the constructor
		 * already validated this exact string. The RAW property stays
		 * intact for the authorization redirect (verification_uri()
		 * below) — the browser needs the full URI; only the VIEW is
		 * masked.
		 */
		return array(
			'device_code'      => SecretMask::mask( $this->device_code ),
			'user_code'        => SecretMask::mask( $this->user_code ),
			'verification_uri' => Url::redacted( $this->verification_uri ),
			'interval_seconds' => $this->interval_seconds,
			'expires_at'       => $this->expires_at,
		);
	}
}
