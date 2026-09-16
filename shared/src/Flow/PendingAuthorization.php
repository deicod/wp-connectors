<?php
/**
 * Pending user-scoped authorization flow state (Task 3.1).
 *
 * The neutral shape of an authorization flow that is mid-air: which
 * admin (user id) started it, for which provider, when, and the flow's
 * own state — exactly one of a device-authorization session (awaiting
 * the user's confirmation, poll pending) or a PKCE pair (awaiting the
 * paste-back of the code). The revoke path deletes all provider- and
 * user-scoped pending state so a flow that was mid-air during
 * revocation can never later install a fresh grant.
 *
 * Pure value object; persistence and expiry sweeping belong to the
 * admin/flow tasks.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Flow;

use DateTimeImmutable;
use Deicod\WpConnectors\Shared\Http\HeaderMap;
use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable, constructor-validated pending authorization.
 *
 * @since 0.1.0
 */
final class PendingAuthorization {

	/**
	 * The admin who started the flow.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $user_id;

	/**
	 * Provider label (neutral; values come from per-plugin provider config).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $provider_id;

	/**
	 * When the flow started.
	 *
	 * @since 0.1.0
	 *
	 * @var DateTimeImmutable
	 */
	private readonly DateTimeImmutable $created_at;

	/**
	 * Device-flow session, when the pending flow is a device flow.
	 *
	 * @since 0.1.0
	 *
	 * @var DeviceAuthorizationSession|null
	 */
	private readonly ?DeviceAuthorizationSession $device_session;

	/**
	 * PKCE pair, when the pending flow is an authorization-code-with-PKCE flow.
	 *
	 * @since 0.1.0
	 *
	 * @var PkceCodePair|null
	 */
	private readonly ?PkceCodePair $pkce_pair;

	/**
	 * Constructor (private: use the for_device()/for_pkce() factories).
	 *
	 * @since 0.1.0
	 *
	 * @param int                             $user_id        Starting admin's user id (positive).
	 * @param string                          $provider_id    Provider label (non-empty).
	 * @param DateTimeImmutable               $created_at     Flow start reading.
	 * @param DeviceAuthorizationSession|null $device_session Device session, or null.
	 * @param PkceCodePair|null               $pkce_pair      PKCE pair, or null.
	 * @throws InvalidArgumentException When the user/provider is invalid or the payload is not exactly one flow.
	 */
	private function __construct( int $user_id, string $provider_id, DateTimeImmutable $created_at, ?DeviceAuthorizationSession $device_session, ?PkceCodePair $pkce_pair ) {
		if ( $user_id < 1 ) {
			throw new InvalidArgumentException( 'The user id must be positive.' );
		}
		if ( '' === trim( $provider_id ) ) {
			throw new InvalidArgumentException( 'The provider id must be a non-empty string.' );
		}

		/*
		 * The ONE control-byte guard on the provider-supplied string this
		 * VO carries (t31-r12-5): the authorization-code flow's own
		 * code/state ride the flow PAYLOADS, and those gate themselves
		 * (PkceCodePair by its RFC 7636 grammar; the device session by
		 * the same guard this round gave it) — the free-text label is
		 * the string left unguarded, and it rendered raw (a CRLF-bearing
		 * provider id forged a line in a print_r of the pending flow,
		 * reproduced). A future code/state field on this VO joins the
		 * same guard, not a copy of it.
		 */
		HeaderMap::assert_no_control_bytes( $provider_id, 'The provider id' );
		if ( ( null === $device_session ) === ( null === $pkce_pair ) ) {
			throw new InvalidArgumentException( 'A pending authorization carries exactly one flow payload (device session or PKCE pair).' );
		}

		$this->user_id        = $user_id;
		$this->provider_id    = $provider_id;
		$this->created_at     = $created_at;
		$this->device_session = $device_session;
		$this->pkce_pair      = $pkce_pair;
	}

	/**
	 * A pending device flow.
	 *
	 * @since 0.1.0
	 *
	 * @param int                        $user_id     Starting admin's user id.
	 * @param string                     $provider_id Provider label.
	 * @param DeviceAuthorizationSession $session    Device-authorization session.
	 * @param DateTimeImmutable          $created_at  Flow start reading.
	 * @return self
	 */
	public static function for_device( int $user_id, string $provider_id, DeviceAuthorizationSession $session, DateTimeImmutable $created_at ): self {
		return new self( $user_id, $provider_id, $created_at, $session, null );
	}

	/**
	 * A pending authorization-code-with-PKCE flow.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $user_id     Starting admin's user id.
	 * @param string            $provider_id Provider label.
	 * @param PkceCodePair      $pair        PKCE pair.
	 * @param DateTimeImmutable $created_at  Flow start reading.
	 * @return self
	 */
	public static function for_pkce( int $user_id, string $provider_id, PkceCodePair $pair, DateTimeImmutable $created_at ): self {
		return new self( $user_id, $provider_id, $created_at, null, $pair );
	}

	/**
	 * Starting admin's user id.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Provider label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function provider_id(): string {
		return $this->provider_id;
	}

	/**
	 * Flow start reading.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Device session, or null when this is a PKCE flow.
	 *
	 * @since 0.1.0
	 *
	 * @return DeviceAuthorizationSession|null
	 */
	public function device_session(): ?DeviceAuthorizationSession {
		return $this->device_session;
	}

	/**
	 * PKCE pair, or null when this is a device flow.
	 *
	 * @since 0.1.0
	 *
	 * @return PkceCodePair|null
	 */
	public function pkce_pair(): ?PkceCodePair {
		return $this->pkce_pair;
	}

	/**
	 * Safe debug rendering for the serialization channel — print_r(),
	 * var_dump(), and every debugger that walks object properties.
	 *
	 * The carrier itself is public facts (user, provider, start); its
	 * credentials live in the payload objects, whose own __debugInfo()
	 * masks them (t31-r11-5). The nesting carrier needs no mask of its
	 * own — the engine applies the payload's hook at every level — and
	 * that composition is now STATED as the class's own masked view
	 * (OCR round 3, t31-ocr3-1): print_r() composed correctly by
	 * engine accident, but an un-hooked serialize() of the carrier
	 * would have dumped its properties raw (the public facts plus the
	 * payload objects, which the engine serializes through their OWN
	 * __serialize() — masked — once those exist; the carrier states
	 * the view so neither channel rides an accident).
	 *
	 * Rides the same masked view as __serialize() below (OCR round 3,
	 * t31-ocr3-1) — one vocabulary owner, both channels.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The carrier's public facts with the payload's masked rendering.
	 */
	public function __debugInfo(): array {
		return $this->masked_view();
	}

	/**
	 * The serialize() channel rides the same masked view (OCR round 3,
	 * t31-ocr3-1 — the container half of the pair/session fix; the
	 * StoredGrant shape).
	 *
	 * The carrier holds a credential-bearing payload BY VALUE, so it
	 * inherits the serialize channel through it: un-hooked, serialize()
	 * of the carrier emits its raw property tree, and whether the
	 * nested payload's cleartext reached the bytes was decided one
	 * level down. The carrier states the composition instead: its own
	 * facts (user, provider, start) are public and render as
	 * themselves, and the payload rides as the OBJECT — the engine
	 * applies the session's/pair's OWN __serialize() at that level, so
	 * the masking decision stays the payload's (one doctrine, no
	 * second owner to drift). The masked view is a SNAPSHOT, not a
	 * round-trip payload: this version owns no storage serialization
	 * for a pending flow (persistence belongs to the admin/flow tasks,
	 * built through for_device()/for_pkce()), and __unserialize()
	 * below refuses the safe form.
	 *
	 * var_export() stays the one channel EXCLUDED by engine design (no
	 * hook exists — the raw dump is display material); its
	 * reconstruction channel, __set_state(), refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The carrier's public facts with the payload's masked rendering.
	 */
	public function __serialize(): array {
		return $this->masked_view();
	}

	/**
	 * A masked payload is not a reconstruction source — it refuses.
	 *
	 * The safe forms are lossy by design (the payload's credentials are
	 * masked, so nothing can rebuild a pending flow from them);
	 * unserialize() on the __serialize() payload throws instead of
	 * half-initializing typed properties — and against a private
	 * constructor whose exactly-one-payload invariant would never run.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data The masked payload (never a source of truth).
	 * @return never
	 * @throws RuntimeException Always — the masked snapshot is not a round-trip payload.
	 */
	public function __unserialize( array $data ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the payload to the hook; the refusal is the contract, the payload is not read.
		throw new RuntimeException( 'A masked pending authorization is a snapshot, not a round-trip payload — reconstruct through for_device()/for_pkce(), never from a serialization of its own safe form.' );
	}

	/**
	 * The var_export() eval channel refuses the same way.
	 *
	 * The var_export() call itself dumps the raw property tree through
	 * no hook (engine design — the one channel the masking contract
	 * cannot ride, named as excluded in this class's docblocks), but
	 * the dump it produces is executable code: evaluating it calls
	 * __set_state(), which refuses — an exported pending flow never
	 * reconstructs from its own raw dump.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $properties The exported property tree.
	 * @return never
	 * @throws RuntimeException Always — the raw dump is not a reconstruction source.
	 */
	public static function __set_state( array $properties ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the export to the hook; the refusal is the contract, the tree is not read.
		throw new RuntimeException( 'A masked pending authorization cannot be reconstructed from an exported property tree — the raw dump is display material, never a payload.' );
	}

	/**
	 * The masked snapshot the dump and serialize channels render — the
	 * ONE view both hooks ride (OCR round 3, t31-ocr3-1), so the two
	 * channels cannot drift. The payload rides as the OBJECT: the
	 * engine applies its own __debugInfo()/__serialize() at that
	 * level, keeping the masking decision the payload's.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The carrier's public facts with the payload's masked rendering.
	 */
	private function masked_view(): array {
		return array(
			'user_id'        => $this->user_id,
			'provider_id'    => $this->provider_id,
			'created_at'     => $this->created_at,
			'device_session' => $this->device_session,
			'pkce_pair'      => $this->pkce_pair,
		);
	}
}
