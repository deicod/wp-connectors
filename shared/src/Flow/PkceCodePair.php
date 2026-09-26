<?php
/**
 * PKCE verifier/challenge pair (Task 3.1).
 *
 * The neutral PKCE state: the code verifier (stayed secret on this
 * side) and its S256 code challenge (sent in the authorization
 * request). Both must draw from the RFC 7636 unreserved charset with
 * the RFC's 43-128 length bounds. The pair is value-object pure —
 * generation of the verifier itself (randomness) belongs to the flow
 * implementation, which then derives the pair via from_verifier().
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Flow;

use Deicod\WpConnectors\Shared\Support\SecretMask;
use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable, constructor-validated PKCE code pair.
 *
 * @since 0.1.0
 */
final class PkceCodePair {

	/**
	 * RFC 7636 code verifier: unreserved charset, 43-128 characters.
	 *
	 * The dash sits LAST in the class (OCR round 20, t31-ocr20-7): between
	 * `0-9` and `.` it formed the incidental range `-.` (0x2D-0x2E —
	 * exactly the two intended bytes, so the behavior was always right,
	 * but the intent read as an accident one edit away from a real
	 * range).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}\z/';

	/**
	 * The code verifier (this side's secret half).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $code_verifier;

	/**
	 * The S256 code challenge (the public half).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $code_challenge;

	/**
	 * Constructor.
	 *
	 * Direct construction serves rehydration of pairs that were built by
	 * from_verifier() — and the binding this VO exists to carry is
	 * ENFORCED here (OCR round 2, t31-ocr2-8): the constructor verifies
	 * the challenge equals BASE64URL(SHA-256(verifier)) (constant-time
	 * compare) and rejects a mismatched pair loudly. Without the check
	 * a hand-built or corrupted pair was perfectly representable and
	 * failed far away at the provider as an opaque invalid_grant. There
	 * is deliberately NO unverified rehydration path; if a legitimate
	 * one ever appears it must be a named, gated constructor with its
	 * own docblock — never a loosening of this one.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code_verifier  Code verifier (RFC 7636 charset, 43-128 chars).
	 * @param string $code_challenge S256 code challenge (RFC 7636 charset, 43-128 chars) — must equal BASE64URL(SHA-256($code_verifier)).
	 * @throws InvalidArgumentException When either half violates the charset or length bounds, or when the challenge is not the verifier's S256 derivative.
	 */
	public function __construct( string $code_verifier, string $code_challenge ) {
		if ( 1 !== preg_match( self::VERIFIER_PATTERN, $code_verifier ) ) {
			throw new InvalidArgumentException( 'The code verifier must use the code-verifier charset with 43-128 characters.' );
		}
		if ( 1 !== preg_match( self::VERIFIER_PATTERN, $code_challenge ) ) {
			throw new InvalidArgumentException( 'The code challenge must use the code-verifier charset with 43-128 characters.' );
		}
		// The binding (t31-ocr2-8): one derivation owner (s256_challenge(),
		// the same callable from_verifier() rides), compared constant-time.
		if ( ! hash_equals( self::s256_challenge( $code_verifier ), $code_challenge ) ) {
			throw new InvalidArgumentException( 'The code challenge must be BASE64URL(SHA-256(code_verifier)) — the S256 binding this pair exists to carry; a mismatched pair is not rehydration data, it is a corrupted pair, and refusing it here beats failing far away at the provider as an opaque invalid_grant.' );
		}

		$this->code_verifier  = $code_verifier;
		$this->code_challenge = $code_challenge;
	}

	/**
	 * Derives the pair from a verifier, challenge = BASE64URL(SHA-256(verifier)).
	 *
	 * The RFC 7636 S256 transformation; the challenge is exactly 43
	 * characters (32 bytes, no padding). The construction re-verifies
	 * the binding through the constructor (one enforcement point).
	 *
	 * @since 0.1.0
	 *
	 * @param string $code_verifier Code verifier.
	 * @return self
	 * @throws InvalidArgumentException When the verifier violates the charset or length bounds.
	 */
	public static function from_verifier( string $code_verifier ): self {
		return new self( $code_verifier, self::s256_challenge( $code_verifier ) );
	}

	/**
	 * The RFC 7636 S256 derivation — the ONE owner of the binding math
	 * (t31-ocr2-8): from_verifier() mints through it, the constructor
	 * verifies through it, and the two can never disagree about what
	 * the challenge of a verifier is.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code_verifier Code verifier.
	 * @return string The base64url, unpadded S256 challenge (exactly 43 characters).
	 */
	private static function s256_challenge( string $code_verifier ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the RFC 7636 S256 challenge IS base64url(SHA-256(verifier)); this is the specification's own encoding, not obfuscation.
		return rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Code verifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function code_verifier(): string {
		return $this->code_verifier;
	}

	/**
	 * Code challenge.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function code_challenge(): string {
		return $this->code_challenge;
	}

	/**
	 * Safe debug rendering for the serialization channel — print_r(),
	 * var_dump(), and every debugger that walks object properties
	 * (verifier round t31-r11-5).
	 *
	 * The verifier is the confidential half (RFC 7636 §4.1: the client
	 * keeps it secret until the token request); the challenge is its
	 * one-way derivative and travels in the authorization request, so
	 * it is public by construction. The dump masks the verifier through
	 * the one vocabulary (SecretMask::mask()) and renders the challenge
	 * as itself.
	 *
	 * Rides the same masked view as __serialize() below (OCR round 3,
	 * t31-ocr3-1) — one vocabulary owner, both channels.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked verifier beside the public challenge, never containing the confidential half.
	 */
	public function __debugInfo(): array {
		return $this->masked_view();
	}

	/**
	 * The serialize() channel rides the same masked view (OCR round 3,
	 * t31-ocr3-1 — the direct follow-on of the ocr2-1 doctrine: the
	 * HTTP value objects closed this channel in t31-ocr1-8, the token
	 * carrier and grant in t31-ocr2-1, and the pair was the last
	 * credential-bearing VO with a dump hook but no serialize hook).
	 *
	 * Un-hooked, serialize() bypasses __debugInfo() by engine design
	 * and emits the raw property tree — the code verifier (RFC 7636's
	 * confidential half) in cleartext — for serialize() of the pair
	 * itself and of every container holding it (a queue payload, a
	 * cache entry, a PendingAuthorization whose own __serialize()
	 * carries the pair as the OBJECT so this hook applies). The masked
	 * view is a SNAPSHOT, not a round-trip payload: this version owns
	 * no storage serialization for the pair (a pending flow is built
	 * through from_verifier(), never unserialized), and
	 * __unserialize() below refuses the safe form.
	 *
	 * var_export() stays the one channel EXCLUDED by engine design (no
	 * hook exists — the raw dump is display material); its
	 * reconstruction channel, __set_state(), refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked verifier beside the public challenge, never containing the confidential half.
	 */
	public function __serialize(): array {
		return $this->masked_view();
	}

	/**
	 * A masked payload is not a reconstruction source — it refuses.
	 *
	 * The safe forms are lossy by design (the verifier is masked, so
	 * nothing can rebuild a pair from them); unserialize() on the
	 * __serialize() payload throws instead of half-initializing typed
	 * properties against masked fields — and a rebuilt pair would owe
	 * the constructor's binding check a verifier it no longer has.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data The masked payload (never a source of truth).
	 * @return never
	 * @throws RuntimeException Always — the masked snapshot is not a round-trip payload.
	 */
	public function __unserialize( array $data ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the payload to the hook; the refusal is the contract, the payload is not read.
		throw new RuntimeException( 'A masked PKCE pair is a snapshot, not a round-trip payload — reconstruct through the constructor or from_verifier(), never from a serialization of its own safe form.' );
	}

	/**
	 * The var_export() eval channel refuses the same way.
	 *
	 * The var_export() call itself dumps the raw property tree through
	 * no hook (engine design — the one channel the masking contract
	 * cannot ride, named as excluded in this class's docblocks), but
	 * the dump it produces is executable code: evaluating it calls
	 * __set_state(), which refuses — an exported pair never
	 * reconstructs from its own raw dump.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $properties The exported property tree.
	 * @return never
	 * @throws RuntimeException Always — the raw dump is not a reconstruction source.
	 */
	public static function __set_state( array $properties ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the export to the hook; the refusal is the contract, the tree is not read.
		throw new RuntimeException( 'A masked PKCE pair cannot be reconstructed from an exported property tree — the raw dump is display material, never a payload.' );
	}

	/**
	 * The masked snapshot the dump and serialize channels render — the
	 * ONE view both hooks ride (OCR round 3, t31-ocr3-1), so the two
	 * channels cannot drift.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked verifier beside the public challenge, never containing the confidential half.
	 */
	private function masked_view(): array {
		return array(
			'code_verifier'  => SecretMask::mask( $this->code_verifier ),
			'code_challenge' => $this->code_challenge,
		);
	}
}
