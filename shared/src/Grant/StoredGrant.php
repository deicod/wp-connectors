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

use Deicod\WpConnectors\Shared\Token\AccessTokenSet;
use InvalidArgumentException;

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
