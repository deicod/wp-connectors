<?php
/**
 * Immutable OAuth access-token set (Task 3.1).
 *
 * The provider-neutral carrier for one issued token triple: access token,
 * optional refresh token, and the expiry facts. The refresh token is
 * nullable BY DESIGN: a refresh response may omit the replacement
 * `refresh_token` (providers do), and "no replacement token was issued"
 * (null) is a different fact from an empty string — the token-set merge
 * semantics of the refresh coordination task keep the STORED refresh token
 * when the response carries none, so the value object must be able to say
 * "this set has no refresh token" without ambiguity.
 *
 * Pure value object: constructor-validated, immutable, no environment
 * access (WordPress or otherwise). Expiry is DERIVED from the obtained-at
 * reading plus the `expires_in` source seconds, never stored independently,
 * so the two facts cannot drift apart. Skew-adjusted freshness
 * (expiry-minus-skew) is the refresh policy's job, not this object's.
 *
 * `to_array()`/`from_array()` are the STORAGE serialization (strict,
 * round-trip exact) later wrapped in the encrypted envelope — they carry
 * token material by design and are never a display surface.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Token;

use DateTimeImmutable;
use DateTimeZone;
use Deicod\WpConnectors\Shared\Support\InstantArithmetic;
use InvalidArgumentException;

/**
 * Immutable, constructor-validated OAuth token set.
 *
 * @since 0.1.0
 */
final class AccessTokenSet {

	/**
	 * Serialization format for the two instants (RFC 3339 with microseconds).
	 *
	 * Microseconds are included so the round trip is exact: an ATOM-formatted
	 * instant would silently drop the sub-second part of a clock reading and
	 * the re-derived expiry would no longer match the serialized one.
	 *
	 * The serialized spelling is CANONICAL UTC: to_array() renders both
	 * instants in the UTC zone ('+00:00' offset). A named timezone's
	 * rendering is DST-dependent — the same instant spans two offset
	 * spellings across a transition — while the UTC spelling names the
	 * instant unambiguously, so from_array() can re-derive and compare
	 * without the zone context the payload cannot carry.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const SERIAL_INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

	/**
	 * The zone every serialized instant renders in (canonical UTC).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SERIAL_ZONE_NAME = 'UTC';

	/**
	 * The last UTC second of year 9999: the derived expiry's ceiling.
	 *
	 * The canonical serialization renders a four-digit year, so an expiry
	 * past this instant cannot round-trip. The bound is checked on the
	 * DERIVED timestamp (obtained-at plus expires_in) rather than on
	 * expires_in alone because a far-future reading with a modest
	 * lifetime can cross it just as an oversized lifetime from a normal
	 * reading can — and an unchecked derivation saturates silently
	 * (modify() clamps astronomically large offsets to no change at all,
	 * yielding expiry == obtained-at with every gate green).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SERIALIZABLE_EXPIRY_CEILING = 253402300799;

	/**
	 * Access token (non-empty, non-whitespace-only).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $access_token;

	/**
	 * Refresh token, or null when this set carries none.
	 *
	 * Null models "no refresh token / no replacement was issued"; an empty
	 * string is REJECTED by the constructor so the two states can never be
	 * conflated.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private readonly ?string $refresh_token;

	/**
	 * Lifetime in seconds as issued by the provider (`expires_in` source).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $expires_in;

	/**
	 * Clock reading at issuance.
	 *
	 * @since 0.1.0
	 *
	 * @var DateTimeImmutable
	 */
	private readonly DateTimeImmutable $obtained_at;

	/**
	 * Absolute expiry, derived as obtained-at plus expires_in.
	 *
	 * The addition is ABSOLUTE elapsed time (UTC projection), so a
	 * reading taken in a DST-observing timezone yields the same instant
	 * UTC arithmetic would — wall-clock addition drifts by the
	 * transition delta. The reading's timezone stays attached.
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
	 * @param string            $access_token  Access token; empty or whitespace-only values are rejected.
	 * @param string|null       $refresh_token Refresh token, or null when none was issued; empty or whitespace-only strings are rejected.
	 * @param int               $expires_in    Lifetime in seconds; must be positive (the expiry offset from obtained-at may not be zero or negative) and small enough that the derived expiry stays inside the serializable range (year 9999 UTC).
	 * @param DateTimeImmutable $obtained_at   Clock reading at issuance.
	 * @throws InvalidArgumentException When any field violates the contract above.
	 */
	public function __construct( string $access_token, ?string $refresh_token, int $expires_in, DateTimeImmutable $obtained_at ) {
		if ( '' === $access_token || '' === trim( $access_token ) ) {
			throw new InvalidArgumentException( 'The access token must be a non-empty, non-whitespace string.' );
		}
		if ( null !== $refresh_token && '' === trim( $refresh_token ) ) {
			throw new InvalidArgumentException( 'The refresh token must be null (none issued) or a non-empty, non-whitespace string; an empty string is not a valid "no token" spelling.' );
		}
		if ( $expires_in <= 0 ) {
			throw new InvalidArgumentException( sprintf( 'The expires_in offset must be a positive number of seconds, %d given.', $expires_in ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}
		if ( $expires_in > self::SERIALIZABLE_EXPIRY_CEILING - $obtained_at->getTimestamp() ) {
			throw new InvalidArgumentException( sprintf( 'The derived expiry must stay within the serializable range (the last UTC second of year 9999); obtained-at plus %d seconds does not.', $expires_in ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}

		$this->access_token  = $access_token;
		$this->refresh_token = $refresh_token;
		$this->expires_in    = $expires_in;
		$this->obtained_at   = $obtained_at;
		$this->expires_at    = InstantArithmetic::plus_seconds( $obtained_at, $expires_in );
	}

	/**
	 * Access token.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function access_token(): string {
		return $this->access_token;
	}

	/**
	 * Refresh token, or null when this set carries none.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function refresh_token(): ?string {
		return $this->refresh_token;
	}

	/**
	 * Whether this set carries a refresh token.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_refresh_token(): bool {
		return null !== $this->refresh_token;
	}

	/**
	 * Lifetime in seconds as issued by the provider.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function expires_in(): int {
		return $this->expires_in;
	}

	/**
	 * Clock reading at issuance.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function obtained_at(): DateTimeImmutable {
		return $this->obtained_at;
	}

	/**
	 * Absolute expiry (obtained-at plus expires_in), derived, never stored.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function expires_at(): DateTimeImmutable {
		return $this->expires_at;
	}

	/**
	 * Token set with the refresh token merged per refresh-response semantics.
	 *
	 * A null replacement (the response omitted `refresh_token`) KEEPS the
	 * stored refresh token — discarding a still-valid token on an omitting
	 * response would force an unnecessary reconnection. A non-empty string
	 * replaces it. Always returns a new instance; this set is unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $replacement Replacement refresh token from the response, or null when the response carried none.
	 * @return self
	 * @throws InvalidArgumentException When the replacement is a non-null empty or whitespace-only string.
	 */
	public function with_replacement_refresh_token( ?string $replacement ): self {
		if ( null === $replacement ) {
			return new self( $this->access_token, $this->refresh_token, $this->expires_in, $this->obtained_at );
		}

		return new self( $this->access_token, $replacement, $this->expires_in, $this->obtained_at );
	}

	/**
	 * Storage serialization (strict, round-trip exact).
	 *
	 * Both instants render in their CANONICAL UTC spelling — the
	 * serialized payload must name each instant unambiguously, and a
	 * named timezone's rendering is DST-dependent (the same instant
	 * spans two offset spellings across a transition, which would make
	 * the from_array() re-derivation and its strict compare
	 * zone-context-dependent). The value objects keep their own
	 * timezones; only the serialization is canonical.
	 *
	 * Carries token material BY DESIGN — this is the payload the encrypted
	 * envelope wraps. Never a display or debug surface.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Serialized token set.
	 */
	public function to_array(): array {
		return array(
			'access_token'  => $this->access_token,
			'refresh_token' => $this->refresh_token,
			'expires_in'    => $this->expires_in,
			'obtained_at'   => self::serialize_instant( $this->obtained_at ),
			'expires_at'    => self::serialize_instant( $this->expires_at ),
		);
	}

	/**
	 * Rebuilds a token set from its storage serialization.
	 *
	 * Strict in both directions: the key set must match exactly (missing or
	 * extra keys rejected), types are never coerced (`'3600'` is not an
	 * int), the instants must parse in the serialization format, and the
	 * serialized expiry must equal the re-derived one — a payload whose
	 * facts disagree is malformed, not repaired. The compare is on the
	 * CANONICAL UTC rendering of both instants, so a payload serialized
	 * from a named-timezone reading round-trips exactly: the re-derived
	 * expiry is re-derived by the same absolute arithmetic, and the
	 * parsed reading re-attaches only the offset the canonical spelling
	 * itself carries.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $data Serialized token set.
	 * @return self
	 * @throws InvalidArgumentException When the payload is not a strictly well-formed token set.
	 */
	public static function from_array( $data ): self {
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'The serialized token set must be an array.' );
		}

		$expected = array( 'access_token', 'expires_at', 'expires_in', 'obtained_at', 'refresh_token' );
		$actual   = array_keys( $data );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'The serialized token set must carry exactly the keys ' . implode( ', ', $expected ) . ' — missing or extra keys are rejected.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed class-owned key list; escaping belongs to the display layer.
		}
		if ( ! is_string( $data['access_token'] ) ) {
			throw new InvalidArgumentException( 'The serialized access token must be a string.' );
		}
		if ( ! ( is_string( $data['refresh_token'] ) || null === $data['refresh_token'] ) ) {
			throw new InvalidArgumentException( 'The serialized refresh token must be a string or null.' );
		}
		if ( ! is_int( $data['expires_in'] ) ) {
			throw new InvalidArgumentException( 'The serialized expires_in must be an int (no numeric strings).' );
		}
		if ( ! is_string( $data['obtained_at'] ) || ! is_string( $data['expires_at'] ) ) {
			throw new InvalidArgumentException( 'The serialized instants must be strings.' );
		}

		$obtained_at = DateTimeImmutable::createFromFormat( self::SERIAL_INSTANT_FORMAT, $data['obtained_at'] );
		$expires_at  = DateTimeImmutable::createFromFormat( self::SERIAL_INSTANT_FORMAT, $data['expires_at'] );
		if ( false === $obtained_at || false === $expires_at ) {
			throw new InvalidArgumentException( 'The serialized instants must match the serialization format exactly.' );
		}

		$set = new self( $data['access_token'], $data['refresh_token'], $data['expires_in'], $obtained_at );

		// Strict instant compare on the canonical rendering: formatted
		// strings, never loose object comparison and never identity
		// (re-parsed instants are new objects).
		if ( self::serialize_instant( $set->expires_at() ) !== self::serialize_instant( $expires_at ) ) {
			throw new InvalidArgumentException( 'The serialized expiry does not match the serialized obtained-at plus expires_in.' );
		}

		return $set;
	}

	/**
	 * Renders one instant in its canonical serialized spelling (UTC).
	 *
	 * @since 0.1.0
	 *
	 * @param DateTimeImmutable $instant The instant to render.
	 * @return string The canonical UTC serialization-format spelling.
	 */
	private static function serialize_instant( DateTimeImmutable $instant ): string {
		return $instant
			->setTimezone( new DateTimeZone( self::SERIAL_ZONE_NAME ) )
			->format( self::SERIAL_INSTANT_FORMAT );
	}
}
