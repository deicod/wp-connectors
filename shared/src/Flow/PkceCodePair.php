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

/**
 * Immutable, constructor-validated PKCE code pair.
 *
 * @since 0.1.0
 */
final class PkceCodePair {

	/**
	 * RFC 7636 code verifier: unreserved charset, 43-128 characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const VERIFIER_PATTERN = '/^[A-Za-z0-9-._~]{43,128}\z/';

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
	 * Direct construction serves rehydration; fresh pairs come from
	 * from_verifier(), which derives the challenge.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code_verifier  Code verifier (RFC 7636 charset, 43-128 chars).
	 * @param string $code_challenge S256 code challenge (RFC 7636 charset, 43-128 chars).
	 * @throws InvalidArgumentException When either half violates the charset or length bounds.
	 */
	public function __construct( string $code_verifier, string $code_challenge ) {
		if ( 1 !== preg_match( self::VERIFIER_PATTERN, $code_verifier ) ) {
			throw new InvalidArgumentException( 'The code verifier must use the code-verifier charset with 43-128 characters.' );
		}
		if ( 1 !== preg_match( self::VERIFIER_PATTERN, $code_challenge ) ) {
			throw new InvalidArgumentException( 'The code challenge must use the code-verifier charset with 43-128 characters.' );
		}

		$this->code_verifier  = $code_verifier;
		$this->code_challenge = $code_challenge;
	}

	/**
	 * Derives the pair from a verifier, challenge = BASE64URL(SHA-256(verifier)).
	 *
	 * The RFC 7636 S256 transformation; the challenge is exactly 43
	 * characters (32 bytes, no padding).
	 *
	 * @since 0.1.0
	 *
	 * @param string $code_verifier Code verifier.
	 * @return self
	 * @throws InvalidArgumentException When the verifier violates the charset or length bounds.
	 */
	public static function from_verifier( string $code_verifier ): self {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the RFC 7636 S256 challenge IS base64url(SHA-256(verifier)); this is the specification's own encoding, not obfuscation.
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		return new self( $code_verifier, $challenge );
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
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked verifier beside the public challenge, never containing the confidential half.
	 */
	public function __debugInfo(): array {
		return array(
			'code_verifier'  => SecretMask::mask( $this->code_verifier ),
			'code_challenge' => $this->code_challenge,
		);
	}
}
