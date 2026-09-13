<?php
/**
 * Provider-neutral token storage port (Task 3.1).
 *
 * The seam through which the OAuth runtime persists grants. The neutral
 * shape is fixed here: load, save, delete — one grant per provider.
 *
 * Storage invariants every implementation must uphold (the encrypted
 * envelope itself is a later task, but the contract is fixed now):
 *
 * - Generation-checked commit (review round t31-r1): save() is a
 *   compare-and-set against the PERSISTED generation — the fencing
 *   primitive the refresh coordination and revoke/exchange
 *   serialization ride. The writer states the generation it observed
 *   at its last load() (EXPECT_NO_GRANT when it observed none); the
 *   implementation installs the given grant wholesale when that
 *   expectation still holds, and commits NOTHING when it does not —
 *   a late writer returning past its lease (its grant was rotated or
 *   revoked meanwhile) discards its tokens instead of silently
 *   overwriting the newer grant. A false return is a fence verdict,
 *   never an error: the caller reloads and abandons its work.
 * - Atomicity within a committed save: readers observe either the
 *   previous grant or the new one, never a partial or merged state.
 * - Encrypted at rest with authenticated encryption; the envelope is
 *   versioned, and binds its ciphertext to BOTH the provider and the
 *   site context, so a ciphertext transplanted from another provider
 *   or site fails authentication rather than granting access.
 * - Never partial plaintext: a decrypt failure yields a typed storage
 *   failure and NO token material — never a partially decoded set.
 * - Fail closed: when the key material is unusable and no external key
 *   source exists, load fails with a typed storage failure rather
 *   than persisting a decrypt-capable key beside the ciphertext.
 * - delete() removes the grant entirely (revoke/uninstall cleanup);
 *   it is unconditional — revocation itself persists a tombstone via
 *   the generation-checked save(), so the fence stays observable.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Grant;

use Deicod\WpConnectors\Shared\Exception\OAuthStorageException;

/**
 * Contract for per-provider grant persistence.
 *
 * @since 0.1.0
 */
interface TokenStorageInterface {

	/**
	 * The expected-generation sentinel for save(): no grant may be
	 * persisted for the provider (the connect/exchange first install).
	 *
	 * Grant generations are non-negative, so -1 names exactly "absent".
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXPECT_NO_GRANT = -1;

	/**
	 * Loads the stored grant for a provider.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider_id Provider label.
	 * @return StoredGrant|null The stored grant, or null when none is stored.
	 * @throws OAuthStorageException When stored state exists but cannot be read (corruption, unusable key material).
	 */
	public function load( string $provider_id ): ?StoredGrant;

	/**
	 * Atomically installs (replaces) the grant for a provider if the
	 * persisted generation still matches the writer's expectation.
	 *
	 * The compare-and-set of the fencing design: expected_generation
	 * is the generation the writer observed at its last load() (or
	 * EXPECT_NO_GRANT when it observed no grant). When the persisted
	 * generation has moved past it, NOTHING is committed and the
	 * return is false — the writer's facts are stale and its tokens
	 * are discarded, never merged over the newer (rotated or revoked)
	 * grant. A false return is not an error; the caller reloads and
	 * abandons its in-flight work.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $provider_id         Provider label.
	 * @param StoredGrant $grant               The grant to persist.
	 * @param int         $expected_generation The persisted generation this commit is fenced on (EXPECT_NO_GRANT when none).
	 * @return bool True when the grant was committed; false when the precondition failed (nothing committed).
	 * @throws OAuthStorageException When the grant cannot be persisted.
	 */
	public function save( string $provider_id, StoredGrant $grant, int $expected_generation ): bool;

	/**
	 * Deletes the stored grant for a provider.
	 *
	 * Deleting an absent grant is a no-op, never an error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider_id Provider label.
	 * @return void
	 * @throws OAuthStorageException When deletion fails.
	 */
	public function delete( string $provider_id ): void;
}
