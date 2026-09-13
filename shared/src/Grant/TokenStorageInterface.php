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
 * - Atomic replacement: save() installs the given grant wholesale;
 *   readers observe either the previous grant or the new one, never a
 *   partial or merged state.
 * - Encrypted at rest with authenticated encryption; the envelope is
 *   versioned, and binds its ciphertext to BOTH the provider and the
 *   site context, so a ciphertext transplanted from another provider
 *   or site fails authentication rather than granting access.
 * - Never partial plaintext: a decrypt failure yields a typed storage
 *   failure and NO token material — never a partially decoded set.
 * - Fail closed: when the key material is unusable and no external key
 *   source exists, load fails with a typed storage failure rather
 *   than persisting a decrypt-capable key beside the ciphertext.
 * - delete() removes the grant entirely (revoke/uninstall cleanup).
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
	 * Atomically installs (replaces) the grant for a provider.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $provider_id Provider label.
	 * @param StoredGrant $grant       The grant to persist.
	 * @return void
	 * @throws OAuthStorageException When the grant cannot be persisted.
	 */
	public function save( string $provider_id, StoredGrant $grant ): void;

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
