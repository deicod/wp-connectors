<?php
/**
 * Persisted OAuth grant (Task 3.1).
 *
 * The stored grant carries more than the token set: a persisted
 * GENERATION counter and a lifecycle state. The generation is the
 * fencing primitive the refresh coordination and the revoke/exchange
 * serialization ride — every commit that must invalidate in-flight work
 * (revoke, reconnect exchange) advances it, and a writer returning
 * after its lease checks the persisted generation before committing, so
 * a late refresh can never overwrite a newer (rotated or revoked) grant.
 *
 * Pure value object: immutable, transitions return new instances, no
 * environment access. State/token compatibility is enforced at every
 * construction (Connected requires a token set; a Revoked tombstone is
 * UNCONSTRUCTIBLE — revoke() is the only tombstone producer, so every
 * tombstone's generation is advanced by construction), so an
 * inconsistent grant is unrepresentable.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Grant;

use Deicod\WpConnectors\Shared\Http\HeaderMap;
use Deicod\WpConnectors\Shared\Support\SecretMask;
use Deicod\WpConnectors\Shared\Token\AccessTokenSet;
use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable, constructor-validated stored grant.
 *
 * @since 0.1.0
 */
final class StoredGrant {

	/**
	 * Provider label (neutral; values come from per-plugin provider config).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $provider_id;

	/**
	 * Fencing generation; only ever moves forward.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $generation;

	/**
	 * Lifecycle state.
	 *
	 * @since 0.1.0
	 *
	 * @var GrantState
	 */
	private readonly GrantState $state;

	/**
	 * Token set, when the grant carries one.
	 *
	 * @since 0.1.0
	 *
	 * @var AccessTokenSet|null
	 */
	private readonly ?AccessTokenSet $token_set;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * PRIVATE (review round t31-r2-3): the tombstone invariant is structural
	 * only if no public spelling can construct a Revoked grant — an
	 * un-advanced tombstone persisted by mistake does not fence (an
	 * in-flight refresh CAS-commits over the revoke). Every public entry
	 * point rejects Revoked with the typed exception; revoke() — the one
	 * tombstone producer — and the immutable transitions are the internal
	 * callers left. Forward note for Task 3.2: hydrating a PERSISTED
	 * tombstone will need its own deliberate producer (e.g. a named
	 * constructor documented "the persisted generation was advanced when
	 * the tombstone was minted; hydration re-states persisted facts, it
	 * never mints") — do not reopen the plain constructor for it.
	 *
	 * @param string              $provider_id Provider label (non-empty).
	 * @param int                 $generation  Fencing generation (non-negative).
	 * @param GrantState          $state       Lifecycle state.
	 * @param AccessTokenSet|null $token_set   Token set; REQUIRED for Connected, FORBIDDEN for Revoked, optional otherwise.
	 * @throws InvalidArgumentException When the combination violates the contract above.
	 */
	private function __construct( string $provider_id, int $generation, GrantState $state, ?AccessTokenSet $token_set = null ) {
		if ( '' === trim( $provider_id ) ) {
			throw new InvalidArgumentException( 'The provider id must be a non-empty string.' );
		}

		/*
		 * The ONE control-byte guard on the grant's free-text label
		 * (t31-r13-2): the trim screen alone let a '\n'-bearing
		 * provider id construct, and print_r() of the grant — whose
		 * token set renders masked — forged a line BESIDE the masked
		 * secrets (reproduced). PendingAuthorization's label rides
		 * HeaderMap's guard (t31-r12-5); this constructor joins the
		 * SAME callable, not a copy of it — every public spelling
		 * (in_state, the immutable transitions, revoke()) funnels
		 * through here, so no produced grant carries the channel.
		 */
		HeaderMap::assert_no_control_bytes( $provider_id, 'The provider id' );
		if ( $generation < 0 ) {
			throw new InvalidArgumentException( sprintf( 'The grant generation must be non-negative, %d given.', $generation ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}
		if ( GrantState::Connected === $state && null === $token_set ) {
			throw new InvalidArgumentException( 'A connected grant requires a token set.' );
		}
		if ( GrantState::Revoked === $state && null !== $token_set ) {
			throw new InvalidArgumentException( 'A revoked tombstone carries no token set — build it via revoke() so the generation advances.' );
		}

		$this->provider_id = $provider_id;
		$this->generation  = $generation;
		$this->state       = $state;
		$this->token_set   = $token_set;
	}

	/**
	 * Constructs a grant in any NON-tombstone state (review round
	 * t31-r2-3).
	 *
	 * The public construction entry now that the constructor is private:
	 * Revoked is rejected with the class's typed exception — revoke() is
	 * the only tombstone producer, because only it can guarantee the
	 * generation ADVANCES (the fence an in-flight refresh or exchange
	 * commits against; a tombstone minted at an un-advanced generation
	 * does not fence).
	 *
	 * @since 0.1.0
	 *
	 * @param string              $provider_id Provider label (non-empty).
	 * @param int                 $generation  Fencing generation (non-negative).
	 * @param GrantState          $state       Lifecycle state; Revoked is rejected (use revoke()).
	 * @param AccessTokenSet|null $token_set   Token set; REQUIRED for Connected, optional otherwise.
	 * @return self
	 * @throws InvalidArgumentException When Revoked is requested, or the combination violates the constructor contract.
	 */
	public static function in_state( string $provider_id, int $generation, GrantState $state, ?AccessTokenSet $token_set = null ): self {
		if ( GrantState::Revoked === $state ) {
			throw new InvalidArgumentException( 'A revoked tombstone must be built via revoke() so the generation advances — an un-advanced tombstone does not fence, and an in-flight refresh would CAS-commit over the revoke.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed wording in a developer-facing rejection; escaping belongs to the display layer.
		}

		return new self( $provider_id, $generation, $state, $token_set );
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
	 * Fencing generation.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function generation(): int {
		return $this->generation;
	}

	/**
	 * Lifecycle state.
	 *
	 * @since 0.1.0
	 *
	 * @return GrantState
	 */
	public function state(): GrantState {
		return $this->state;
	}

	/**
	 * Token set, or null when the grant carries none.
	 *
	 * @since 0.1.0
	 *
	 * @return AccessTokenSet|null
	 */
	public function token_set(): ?AccessTokenSet {
		return $this->token_set;
	}

	/**
	 * Safe debug rendering for the serialization channel — print_r(),
	 * var_dump(), and every debugger that walks object properties
	 * (verifier round t31-r11-5).
	 *
	 * The grant itself is public facts (provider, generation, state);
	 * its secret material lives in the token set, whose own
	 * __debugInfo() masks both token positions. The nested object
	 * renders through that mask — one vocabulary, no second masking
	 * decision to drift.
	 *
	 * Rides the same masked view as __serialize() below (OCR round 2,
	 * t31-ocr2-1) — one vocabulary owner, both channels.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The grant's public facts with the token set's masked dump.
	 */
	public function __debugInfo(): array {
		return $this->masked_view();
	}

	/**
	 * The serialize() channel rides the same masked view (OCR round 2,
	 * t31-ocr2-1).
	 *
	 * Un-hooked, serialize() bypasses __debugInfo() by engine design and
	 * emits the raw property tree — the nested token set's access and
	 * refresh tokens in cleartext — for serialize() of the grant itself
	 * and of every container holding it (a queue payload, a cache
	 * entry). The grant's own facts (provider, generation, state) are
	 * public and render as themselves; the token set rides the set's OWN
	 * __serialize() (the engine serializes nested objects through their
	 * hook), so the masking decision stays the set's — one doctrine, no
	 * second owner to drift. The masked view is a SNAPSHOT, not a
	 * round-trip payload: __unserialize() below refuses it, and storage
	 * reconstruction belongs to the envelope's deliberate hydration
	 * producer (the constructor's forward note), never to a
	 * serialization of the safe form.
	 *
	 * var_export() stays the one channel EXCLUDED by engine design (no
	 * hook exists — the raw dump is display material); its reconstruction
	 * channel, __set_state(), refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The grant's public facts with the token set's masked payload.
	 */
	public function __serialize(): array {
		return $this->masked_view();
	}

	/**
	 * A masked payload is not a reconstruction source — it refuses.
	 *
	 * The safe forms are lossy by design (the nested tokens are masked,
	 * so nothing can rebuild a grant from them); unserialize() on the
	 * __serialize() payload throws instead of half-initializing typed
	 * properties against masked fields. Storage reconstruction is the
	 * Task-3.2 envelope's deliberate hydration seam (re-stating
	 * persisted facts, never minting), not this channel.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data The masked payload (never a source of truth).
	 * @return never
	 * @throws RuntimeException Always — the masked snapshot is not a round-trip payload.
	 */
	public function __unserialize( array $data ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the payload to the hook; the refusal is the contract, the payload is not read.
		throw new RuntimeException( 'A masked stored grant is a snapshot, not a round-trip payload — reconstruct through the construction API, never from a serialization of its own safe form.' );
	}

	/**
	 * The var_export() eval channel refuses the same way.
	 *
	 * The var_export() call itself dumps the raw property tree through
	 * no hook (engine design — the one channel the masking contract
	 * cannot ride, named as excluded in this class's docblocks), but the
	 * dump it produces is executable code: evaluating it calls
	 * __set_state(), which refuses — an exported grant never
	 * reconstructs from its own raw dump.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $properties The exported property tree.
	 * @return never
	 * @throws RuntimeException Always — the raw dump is not a reconstruction source.
	 */
	public static function __set_state( array $properties ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the export to the hook; the refusal is the contract, the tree is not read.
		throw new RuntimeException( 'A masked stored grant cannot be reconstructed from an exported property tree — the raw dump is display material, never a payload.' );
	}

	/**
	 * The masked snapshot the dump and serialize channels render — the
	 * ONE view both hooks ride (OCR round 2, t31-ocr2-1), so the two
	 * channels cannot drift. The token set rides as the OBJECT: the
	 * engine applies its own __debugInfo()/__serialize() at that level,
	 * keeping the masking decision the set's.
	 *
	 * The label renders through SecretMask::utf8_for_safe_render()
	 * (OCR round 6, t31-ocr6-3, the r4-13/r8-6 doctrine at this VO's
	 * seam): the constructor's control-byte screen is byte-permissive
	 * by design — a provider config label is opaque, and a lone 0xE9
	 * is not a control byte — but its VERBATIM rendering made the
	 * dump and serialize forms invalid UTF-8, the json_encode()-false
	 * log-drop class. The rendered form escapes exactly like every
	 * established safe-debug form ('%E9'), the stored bytes never
	 * change; PendingAuthorization's label leg rides the same one
	 * rendering owner.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The grant's public facts with the token set's masked dump.
	 */
	private function masked_view(): array {
		return array(
			'provider_id' => SecretMask::utf8_for_safe_render( $this->provider_id ),
			'generation'  => $this->generation,
			'state'       => $this->state,
			'token_set'   => $this->token_set,
		);
	}

	/**
	 * Grant with a replaced token set (same generation, same state).
	 *
	 * The refresh-commit path's shape; the generation decision belongs to
	 * the caller (with_generation()/revoke() enforce its direction).
	 *
	 * @since 0.1.0
	 *
	 * @param AccessTokenSet $token_set The replacement token set.
	 * @return self
	 * @throws InvalidArgumentException When the current state forbids tokens (Revoked).
	 */
	public function with_token_set( AccessTokenSet $token_set ): self {
		return new self( $this->provider_id, $this->generation, $this->state, $token_set );
	}

	/**
	 * Grant with a different lifecycle state (token set preserved).
	 *
	 * Revoked is rejected: the tombstone's defining property is its
	 * ADVANCED generation (the fence late writers commit against), and a
	 * plain state swap cannot advance it — revoke() is the only tombstone
	 * producer.
	 *
	 * The reverse direction — a tombstone transitioning BACK to a live
	 * state at the same generation — stays permitted by adjudication
	 * (t31-r2-3): it is fence-neutral (the generation is preserved, so
	 * no in-flight writer's commit verdict changes), and whether a
	 * tombstone is terminal is the Task 3.3 coordinator's policy, not
	 * the value object's. Reopen only if a same-generation resurrection
	 * ever changes a fence verdict.
	 *
	 * @since 0.1.0
	 *
	 * @param GrantState $state The new state.
	 * @return self
	 * @throws InvalidArgumentException When the state is Revoked (use revoke()) or the token set is incompatible with the new state.
	 */
	public function with_state( GrantState $state ): self {
		if ( GrantState::Revoked === $state ) {
			throw new InvalidArgumentException( 'A revoked tombstone must be built via revoke() so the generation advances.' );
		}

		return new self( $this->provider_id, $this->generation, $state, $this->token_set );
	}

	/**
	 * Grant with an advanced fencing generation (strictly greater only).
	 *
	 * @since 0.1.0
	 *
	 * @param int $generation The new generation; must be strictly greater than the current one.
	 * @return self
	 * @throws InvalidArgumentException When the generation does not advance.
	 */
	public function with_generation( int $generation ): self {
		if ( $generation <= $this->generation ) {
			throw new InvalidArgumentException( sprintf( 'The grant generation only moves forward: %d given, current %d.', $generation, $this->generation ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- validated ints in a developer-facing rejection; escaping belongs to the display layer.
		}

		return new self( $this->provider_id, $generation, $this->state, $this->token_set );
	}

	/**
	 * The revocation tombstone: tokens dropped, generation advanced.
	 *
	 * The advanced generation is what fences a refresh or exchange that
	 * returns after the revoke — the persisted tombstone's generation is
	 * what every commit checks, so the late writer discards its tokens
	 * instead of silently reconnecting the provider.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 * @throws InvalidArgumentException When the generation cannot advance (already at PHP_INT_MAX — the tombstone is unrepresentable, not minted).
	 */
	public function revoke(): self {
		if ( PHP_INT_MAX === $this->generation ) {
			throw new InvalidArgumentException( 'The grant generation cannot advance past PHP_INT_MAX — the revocation tombstone is unrepresentable.' );
		}

		return new self( $this->provider_id, $this->generation + 1, GrantState::Revoked, null );
	}
}
