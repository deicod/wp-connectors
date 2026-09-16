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
 * - Monotonic commit (OCR round 2, t31-ocr2-3): the CAS equality is
 *   necessary but not sufficient — a grant whose OWN generation is
 *   BELOW the stated expectation would pass an equality-only fence
 *   (persisted 4, expected 4, stale grant at 3) and REGRESS the
 *   persisted fence backwards, resurrecting revoked tokens. Every
 *   legitimate commit satisfies grant.generation() >=
 *   expected_generation (the writer observed the persisted state and
 *   moved forward from it), so implementations REJECT a
 *   lower-generation grant loudly (the typed caller-bug rejection,
 *   nothing committed) instead of accepting the regression.
 * - Provider identity is ONE label, both spellings (OCR round 1,
 *   t31-ocr1-7): save()'s $provider_id parameter is the STORAGE KEY
 *   and MUST equal the grant's own provider_id() — the envelope binds
 *   its ciphertext to the provider, so a key/label disagreement is a
 *   misrouted call, never data to write. Implementations REJECT the
 *   mismatch (the typed caller-bug rejection, never a silent install
 *   of one provider's grant under another's slot) and commit nothing.
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
use InvalidArgumentException;

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
	 * The returned grant is a RECONSTRUCTED instance, never the caller's
	 * stored one: identity does not survive the storage boundary (a real
	 * adapter decodes persisted bytes into a fresh object graph, the
	 * in-memory fake round-trips a copy — review round t31-r9-5). Callers
	 * compare grants by VALUE (the accessors), never by ===.
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
	 * Provider identity (t31-ocr1-7): $provider_id is the storage key
	 * and MUST equal the grant's own provider_id() — the two spellings
	 * name ONE label, and the envelope binds its ciphertext to the
	 * provider. A save whose key and label disagree is a misrouted
	 * call: implementations REJECT it (the typed caller-bug
	 * rejection) and commit NOTHING — never a silent install of one
	 * provider's grant under another's slot. The caller-controlled key
	 * is SCREENED FOR CONTROL BYTES (HeaderMap's one shared guard) and
	 * rejected BEFORE any other judgment (t31-ocr1-13, stated here so
	 * the docblock specifies everything the reference fake enforces):
	 * an unscreened key cannot ride the mismatch rejection's sprintf —
	 * a '\n'-bearing storage key would otherwise forge a line in the
	 * log the exception lands in, the exact class every
	 * provider-supplied string rides the guard for.
	 *
	 * Monotonicity (OCR round 2, t31-ocr2-3): the grant's OWN
	 * generation must be at least $expected_generation. An
	 * equality-only fence passes a stale grant whose generation sits
	 * below the expectation (persisted 4, expected 4, grant at 3) and
	 * the persisted fence REGRESSES — revoked tokens resurrect. Every
	 * legitimate commit satisfies the inequality (the writer observed
	 * the persisted state and moved forward from it), so a
	 * lower-generation grant is a caller bug: implementations REJECT it
	 * loudly and commit NOTHING.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $provider_id         Provider label (must equal the grant's own; screened for control bytes and rejected — typed, nothing committed — BEFORE any other judgment).
	 * @param StoredGrant $grant               The grant to persist (its generation must be at least $expected_generation).
	 * @param int         $expected_generation The persisted generation this commit is fenced on (EXPECT_NO_GRANT when none).
	 * @return bool True when the grant was committed; false when the precondition failed (nothing committed).
	 * @throws InvalidArgumentException When $provider_id does not equal the grant's provider_id(), or when the grant's generation is below $expected_generation (nothing committed either way).
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
