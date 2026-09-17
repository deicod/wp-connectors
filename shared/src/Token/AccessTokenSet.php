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
use Deicod\WpConnectors\Shared\Support\SecretMask;
use InvalidArgumentException;
use RuntimeException;

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
	 * The exact canonical spelling one serialized instant must have.
	 *
	 * Parsing with createFromFormat() alone accepts non-canonical
	 * spellings — a 'Z' suffix, whitespace before the offset,
	 * one-to-five-digit fractions ('.5' becomes 500000 microseconds) —
	 * laundering corrupted or foreign payloads into valid sets. The
	 * shape is validated explicitly: four-digit year, T separator,
	 * six-digit fraction, and the canonical UTC offset exactly.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SERIAL_INSTANT_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00\z/';

	/**
	 * The token grammar both token positions must satisfy: VSCHAR
	 * (review round t31-r9-3).
	 *
	 * RFC 6749 fixes the token positions of the protocol as printable
	 * US-ASCII — §1.5's grammar note with the Appendix A ABNF
	 * (`access-token = 1*VSCHAR`, `refresh-token = 1*VSCHAR`,
	 * `VSCHAR = %x20-7E`) — so a token carrying anything else (a raw
	 * non-UTF-8 byte, multibyte UTF-8, a control byte) is not a token
	 * the protocol ever spells. Screened at construction because
	 * to_array() is the Task-3.2 envelope payload: non-VSCHAR material
	 * made json_encode() of the payload return FALSE, a set that saves
	 * but cannot load one layer further out. The screen also rides
	 * from_array() by construction (the loader builds through this
	 * constructor), so a corrupted payload refuses at load, never
	 * inside the envelope.
	 *
	 * The abort-as-reject rule (glm36-8) rides the probe: a PCRE
	 * failure refuses the token, never passes it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const VSCHAR_PATTERN = '/\A[\x20-\x7E]+\z/';

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
	 * The 64-BIT int domain is these bounds' domain (t31-ocr17-8): both
	 * this ceiling and the obtained-at floor below sit far outside the
	 * 32-bit int range, and composer.json's ">=8.2" (platform 8.2.0)
	 * pins the ENGINE, never the int width — on a 32-bit build both
	 * constants would silently become FLOAT constants and every
	 * comparison over them would judge a different domain than the one
	 * they were derived for. No 32-bit path exists in this project
	 * (every supported build is 64-bit; ledgered with the round), so
	 * this is the stated assumption, not a runtime guard — a future
	 * 32-bit target re-derives these bounds first.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SERIALIZABLE_EXPIRY_CEILING = 253402300799;

	/**
	 * The first UTC second of year 0000: the reading's floor.
	 *
	 * The mirror of the expiry ceiling, on the OTHER end (verifier
	 * round t31-r1-20): a reading before year 0000 (a BCE date, which
	 * 64-bit DateTime represents) renders a SIGNED year
	 * ('-1199-02-15T...'), a spelling from_array() rejects — the set
	 * would save but never reload, the permanently-unloadable-grant
	 * shape t31-r1-2 closed for DST. Year 0000 itself renders '0000',
	 * a legal canonical spelling, so it stays inside. The 64-bit int
	 * domain assumption is the ceiling's to state (t31-ocr17-8); this
	 * floor shares it.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SERIALIZABLE_OBTAINED_FLOOR = -62167219200;

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
	 * The addition is ABSOLUTE elapsed seconds, UTC-projected, so a
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
	 * @param string            $access_token  Access token; empty or whitespace-only values are rejected, and the bytes must satisfy the RFC 6749 VSCHAR grammar (%x20-%x7E, t31-r9-3).
	 * @param string|null       $refresh_token Refresh token, or null when none was issued; empty or whitespace-only strings are rejected, and non-null values must satisfy the RFC 6749 VSCHAR grammar (%x20-%x7E, t31-r9-3).
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
		// VSCHAR at both token positions (t31-r9-3): the token grammar
		// of RFC 6749 §1.5/Appendix A, screened here so the storage
		// payload is always JSON-encodable. `1 !==` reads no-match AND
		// a PCRE abort as rejections (glm36-8).
		if ( 1 !== preg_match( self::VSCHAR_PATTERN, $access_token ) ) {
			throw new InvalidArgumentException( 'The access token must satisfy the RFC 6749 VSCHAR grammar (printable US-ASCII bytes %x20-%x7E only) — a token carrying any other byte is not a spelling the protocol makes, and its storage payload could not be JSON-encoded (to_array() is the encrypted envelope\'s payload).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed grammar description in a developer-facing rejection; escaping belongs to the display layer.
		}
		if ( null !== $refresh_token && 1 !== preg_match( self::VSCHAR_PATTERN, $refresh_token ) ) {
			throw new InvalidArgumentException( 'The refresh token must satisfy the RFC 6749 VSCHAR grammar (printable US-ASCII bytes %x20-%x7E only) — a token carrying any other byte is not a spelling the protocol makes, and its storage payload could not be JSON-encoded (to_array() is the encrypted envelope\'s payload).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed grammar description in a developer-facing rejection; escaping belongs to the display layer.
		}
		if ( $expires_in <= 0 ) {
			throw new InvalidArgumentException( sprintf( 'The expires_in offset must be a positive number of seconds, %d given.', $expires_in ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}

		/*
		 * Overflow-free spelling (t31-ocr12-5): the former
		 * 'expires_in > ceiling - obtained_at' subtracted the READING
		 * from the ceiling, and an obtained-at deep enough (below
		 * 253402300799 - PHP_INT_MAX, ~year -292277022365) promoted
		 * that difference to float — the verdict rode approximation on
		 * exactly the readings nearest the domain edge, and stayed
		 * correct only because the floor guard below caught them
		 * after. The subtraction now runs on the operand the
		 * constructor has already bounded: expires_in is a validated
		 * positive int, so 'ceiling - expires_in' bottoms at
		 * 253402300799 - PHP_INT_MAX, which sits above PHP_INT_MIN —
		 * it never leaves the int domain, and the reading is compared
		 * untouched. Same algebra ('reading plus lifetime must not
		 * pass the last UTC second of year 9999'), no spelling of it
		 * that degrades at the edge.
		 */
		if ( $obtained_at->getTimestamp() > self::SERIALIZABLE_EXPIRY_CEILING - $expires_in ) {
			throw new InvalidArgumentException( sprintf( 'The derived expiry must stay within the serializable range (the last UTC second of year 9999); obtained-at plus %d seconds does not.', $expires_in ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}
		if ( $obtained_at->getTimestamp() < self::SERIALIZABLE_OBTAINED_FLOOR ) {
			throw new InvalidArgumentException( 'The obtained-at reading must stay within the serializable range (year 0000 through 9999 UTC); a BCE reading renders a signed year no payload can reload.' );
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
	 * Safe debug rendering for the serialization channel — print_r(),
	 * var_dump(), and every debugger that walks object properties
	 * (verifier round t31-r11-5).
	 *
	 * Both token positions ARE the secret; the r1 redaction contract
	 * enumerated the rendered surfaces but not this one, and without
	 * __debugInfo() the engine dumps the raw property tree — the access
	 * and refresh tokens in full. Both mask through the one vocabulary
	 * (SecretMask::mask(), the same owner the header renders ride); the
	 * non-secret facts (lifetime, instants) render as themselves.
	 *
	 * Rides the same masked view as __serialize() below (OCR round 2,
	 * t31-ocr2-1) — one vocabulary owner, both channels.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Masked tokens plus the public facts, never containing token material.
	 */
	public function __debugInfo(): array {
		return $this->masked_view();
	}

	/**
	 * The serialize() channel rides the same masked view (OCR round 2,
	 * t31-ocr2-1).
	 *
	 * Un-hooked, serialize() bypasses __debugInfo() by engine design and
	 * emits the raw property tree — both token positions in cleartext —
	 * for serialize() of the set itself and of every container holding it
	 * (a queue payload, a cache entry, a StoredGrant whose own
	 * __serialize() delegates here). The HTTP value objects closed exactly
	 * this channel with HasMaskedHeaders::__serialize (t31-ocr1-8); this
	 * is the same doctrine on the token carrier — the payload is
	 * byte-identical in vocabulary to __debugInfo() above, so the dump
	 * and serialize forms cannot drift. The masked view is a SNAPSHOT,
	 * not a round-trip payload: to_array()/from_array() are the storage
	 * round trip, and __unserialize() below refuses the safe form.
	 *
	 * var_export() stays the one channel EXCLUDED by engine design (no
	 * hook exists — the raw dump is display material); its reconstruction
	 * channel, __set_state(), refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Masked tokens plus the public facts, never containing token material.
	 */
	public function __serialize(): array {
		return $this->masked_view();
	}

	/**
	 * A masked payload is not a reconstruction source — it refuses.
	 *
	 * The safe forms are lossy by design (the tokens are masked, so
	 * nothing can rebuild a token set from them); unserialize() on the
	 * __serialize() payload throws instead of half-initializing typed
	 * properties against masked fields. Storage reconstruction rides
	 * from_array() on the to_array() payload (the envelope's inner
	 * bytes), never a serialization of the safe form.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data The masked payload (never a source of truth).
	 * @return never
	 * @throws RuntimeException Always — the masked snapshot is not a round-trip payload.
	 */
	public function __unserialize( array $data ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the payload to the hook; the refusal is the contract, the payload is not read.
		throw new RuntimeException( 'A masked token set is a snapshot, not a round-trip payload — reconstruct through the constructor or from_array(), never from a serialization of its own safe form.' );
	}

	/**
	 * The var_export() eval channel refuses the same way.
	 *
	 * The var_export() call itself dumps the raw property tree through
	 * no hook (engine design — the one channel the masking contract
	 * cannot ride, named as excluded in this class's docblocks), but the
	 * dump it produces is executable code: evaluating it calls
	 * __set_state(), which refuses — an exported token set never
	 * reconstructs from its own raw dump.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $properties The exported property tree.
	 * @return never
	 * @throws RuntimeException Always — the raw dump is not a reconstruction source.
	 */
	public static function __set_state( array $properties ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the export to the hook; the refusal is the contract, the tree is not read.
		throw new RuntimeException( 'A masked token set cannot be reconstructed from an exported property tree — the raw dump is display material, never a payload.' );
	}

	/**
	 * The masked snapshot the dump and serialize channels render — the
	 * ONE view both hooks ride (OCR round 2, t31-ocr2-1), so the two
	 * channels cannot drift.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Masked tokens plus the public facts, never containing token material.
	 */
	private function masked_view(): array {
		return array(
			'access_token'  => SecretMask::mask( $this->access_token ),
			'refresh_token' => null === $this->refresh_token ? null : SecretMask::mask( $this->refresh_token ),
			'expires_in'    => $this->expires_in,
			'obtained_at'   => $this->obtained_at,
			'expires_at'    => $this->expires_at,
		);
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
	 * The product carries exactly the keys this version models —
	 * from_array() ignores keys it does not model, so a payload that
	 * round-trips through an older reader degrades to that reader's
	 * shape (the forward-tolerance decision; the envelope owns the
	 * format version).
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
	 * Strict about everything this version models, forward-tolerant
	 * about what it does not (review round t31-r1, the Task-4.4
	 * decision): the five modelled keys must all be PRESENT and
	 * strictly typed, types are never coerced (`'3600'` is not an
	 * int), the instants must carry the canonical spelling, and the
	 * serialized expiry must equal the re-derived one — a payload
	 * whose facts disagree is malformed, not repaired. Keys this
	 * version does not model are IGNORED: a payload written by a
	 * newer version (Task 4.4 adds the id-token facts member) loads
	 * on the older reader instead of fail-closing every stored grant
	 * into a forced re-connect. Format versioning stays the ENVELOPE's
	 * job (the versioned-encryption invariant documented on the
	 * storage port) — the inner payload keeps no version of its own.
	 * Honest cost, stated: a load → save round trip through an older
	 * reader DROPS the unmodelled keys.
	 *
	 * The expiry compare is on the CANONICAL UTC rendering of both
	 * instants, so a payload serialized from a named-timezone reading
	 * round-trips exactly: the re-derived expiry is re-derived by the
	 * same absolute arithmetic, and the parsed reading re-attaches
	 * only the offset the canonical spelling itself carries.
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
		$missing  = array_diff( $expected, array_keys( $data ) );
		if ( array() !== $missing ) {
			throw new InvalidArgumentException( 'The serialized token set must carry the keys ' . implode( ', ', $expected ) . ' — missing: ' . implode( ', ', $missing ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed class-owned key lists in a developer-facing rejection; escaping belongs to the display layer.
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

		$obtained_at = self::parse_serialized_instant( $data['obtained_at'] );
		$expires_at  = self::parse_serialized_instant( $data['expires_at'] );

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

	/**
	 * Parses one serialized instant: exact canonical shape, honest parse.
	 *
	 * The shape check runs first (createFromFormat() alone accepts
	 * non-canonical spellings); the parse must then also be
	 * calendar-honest — createFromFormat() silently ROLLS an impossible
	 * date — February 30 becomes March 2 — with only a warning, which
	 * would launder a corrupted payload whose two rolled instants
	 * re-derive consistently past the strict expiry compare. A parse
	 * error OR warning is a rejection.
	 *
	 * @since 0.1.0
	 *
	 * @param string $instant The serialized spelling.
	 * @return DateTimeImmutable The parsed instant (offset-only UTC zone).
	 * @throws InvalidArgumentException When the spelling is not exactly canonical or not calendar-valid.
	 */
	private static function parse_serialized_instant( string $instant ): DateTimeImmutable {
		if ( 1 !== preg_match( self::SERIAL_INSTANT_PATTERN, $instant ) ) {
			throw new InvalidArgumentException( 'The serialized instants must be the canonical UTC spelling exactly (YYYY-MM-DDTHH:MM:SS.ffffff+00:00) — a Z suffix, a padded fraction, surrounding whitespace, or a non-UTC offset is rejected.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed format description in a developer-facing rejection; escaping belongs to the display layer.
		}

		$parsed = DateTimeImmutable::createFromFormat( self::SERIAL_INSTANT_FORMAT, $instant );
		if ( false === $parsed ) {
			throw new InvalidArgumentException( 'The serialized instants must match the serialization format exactly.' );
		}

		$errors = DateTimeImmutable::getLastErrors();
		if ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			throw new InvalidArgumentException( 'The serialized instants must be calendar-valid — an impossible date is a corrupted payload, never one to roll forward.' );
		}

		return $parsed;
	}
}
