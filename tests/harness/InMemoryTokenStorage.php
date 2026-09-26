<?php
/**
 * In-memory TokenStorageInterface fake (Task 3.1).
 *
 * The contract-test fake for the shared storage port: a per-provider
 * map with the port's generation-checked whole-grant replacement (the
 * compare-and-set against the persisted generation). Reused by the
 * encrypted-storage and refresh-coordination suites as the in-memory
 * half of comparison tests.
 *
 * Review round t31-r9-5: load()/save() pass DETACHED COPIES both ways.
 * The fake used to store and return the caller's very instance, and
 * the port tests pinned assertSame on it — semantics no DECRYPTING
 * (real) adapter can honor: a real load reconstructs the object graph
 * from persisted bytes, so identity never survives the storage
 * boundary. The fake models that boundary now (the token set rebuilds
 * through its strict storage serialization, to_array()/from_array() —
 * the exact payload a real adapter's encode/decode puts through the
 * envelope; the grant itself re-states through its own private
 * constructor in the class's scope, the hydration shape the VO's
 * forward note reserves for Task 3.2's named producer — the old
 * serialize()/unserialize() round trip is the channel the grant's
 * masked __serialize() doctrine refuses by design, OCR round 2
 * t31-ocr2-1), so a test that leans on instance identity fails
 * against the fake exactly as it would against the envelope, instead
 * of passing here and breaking in Task 3.2.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Grant\StoredGrant;
use Deicod\WpConnectors\Shared\Http\HeaderMap;
use Deicod\WpConnectors\Shared\Grant\TokenStorageInterface;
use Deicod\WpConnectors\Shared\Token\AccessTokenSet;

final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<string, StoredGrant> */
    private array $grants = array();

    /** @var array<string, int> */
    private array $saveCounts = array();

    public function load(string $provider_id): ?StoredGrant
    {
        self::screen_key($provider_id);

        $grant = $this->grants[$provider_id] ?? null;

        return null === $grant ? null : self::detachedCopy($grant);
    }

    public function save(string $provider_id, StoredGrant $grant, int $expected_generation): bool
    {
        self::screen_key($provider_id);

        /*
         * The expectation domain (t31-ocr15-2): the sentinel (-1) is
         * the FLOOR of the observable world — a load() answer is null
         * (the sentinel) or a grant whose generation is >= 0 — so an
         * expectation below it names no state any writer could have
         * observed. Pre-fix it fell through to the CAS comparison and
         * answered a silent FALSE: a "fence verdict" for a fence that
         * cannot exist, exactly the caller-bug class the identity
         * (t31-ocr1-7) and monotonicity (t31-ocr2-3) rules reject
         * typed. Rejected loudly, nothing committed; false stays
         * reserved for genuine fence verdicts.
         */
        if ($expected_generation < TokenStorageInterface::EXPECT_NO_GRANT) {
            throw new InvalidArgumentException(sprintf(
                'The expected generation (%d) is below the EXPECT_NO_GRANT sentinel (%d) — no observable persisted state sits there, so the expectation is a caller typo, never a fence verdict to answer with false.',
                $expected_generation,
                TokenStorageInterface::EXPECT_NO_GRANT
            ));
        }

        /*
         * The provider-identity rule (t31-ocr1-7): the parameter is
         * the storage key and MUST equal the grant's own label — the
         * reference fake used to key blindly by parameter, so
         * save('provider-a', $grantForProviderB) silently installed
         * B's grant under A's slot, exactly the shape the port's
         * docblock now forbids implementations to accept. Rejected
         * loudly, nothing committed under either key.
         */
        if ($provider_id !== $grant->provider_id()) {
            throw new InvalidArgumentException(sprintf(
                'The storage key (%s) and the grant\'s provider label (%s) must agree — a mismatched save is a misrouted call, never a silent install under the wrong slot.',
                $provider_id,
                $grant->provider_id()
            ));
        }

        /*
         * The monotonicity leg of the fence (t31-ocr2-3): the CAS
         * equality alone passes a grant whose OWN generation sits
         * below the expectation — the finding's repro: a stale
         * Connected grant at generation 3 saved with expected:4 over a
         * persisted Revoked tombstone at 4 passed (4 === 4) and the
         * persisted fence REGRESSED to 3, the revoked tokens
         * resurrected. Every legitimate commit satisfies
         * generation >= expected (the writer observed the persisted
         * state and moved forward from it), so a lower-generation
         * grant is a caller bug: rejected loudly, nothing committed —
         * the same typed-rejection class the identity rule rides.
         */
        if ($grant->generation() < $expected_generation) {
            throw new InvalidArgumentException(sprintf(
                'The committed grant\'s generation (%d) must be at least the expected generation (%d) — a lower-generation save would regress the persisted fence and resurrect revoked tokens.',
                $grant->generation(),
                $expected_generation
            ));
        }

        $persisted = $this->grants[$provider_id] ?? null;
        $persisted_generation = null === $persisted
            ? TokenStorageInterface::EXPECT_NO_GRANT
            : $persisted->generation();

        if ($persisted_generation !== $expected_generation) {
            return false;
        }

        $this->grants[$provider_id] = self::detachedCopy($grant);
        $this->saveCounts[$provider_id] = ($this->saveCounts[$provider_id] ?? 0) + 1;

        return true;
    }

    public function delete(string $provider_id): void
    {
        self::screen_key($provider_id);

        /*
         * The install's counters retire with the grant (OCR round 41,
         * t31-ocr41-2): saveCount() reports the CURRENT install's
         * commits — per-install semantics — so a delete/reinstall
         * cycle never reads the prior install's lifetime total into
         * the new one's count.
         */
        unset($this->grants[$provider_id], $this->saveCounts[$provider_id]);
    }

    /**
     * The caller-controlled key rides the ONE control-byte guard on
     * EVERY port method (verifier round t31-ocr1-13 for save; OCR round
     * 6, t31-ocr6-2, for load()/delete()): the grant's own label was
     * screened at StoredGrant construction (r13-2), but the PARAMETER
     * reaches rejection and failure messages unscreened — a
     * '\n'-bearing key forges a line in the log the exception lands
     * in, the exact class every provider-supplied string rides the
     * guard for, and a Task-3.2 adapter embedding the key in an
     * OAuthStorageException message on a failed load or delete reopens
     * it identically. One screen owner, four call sites (saveCount()
     * joined in OCR round 53, t31-ocr53-5).
     *
     * @param string $provider_id The caller-controlled storage key.
     * @return void
     */
    private static function screen_key(string $provider_id): void
    {
        HeaderMap::assert_no_control_bytes($provider_id, 'The storage key');
    }

    /**
     * How many commits (successful saves) the provider has seen — the
     * fenced-off attempts do not count, they committed nothing.
     *
     * The key rides the ONE screen owner here too (OCR round 53,
     * t31-ocr53-5): this was the one public entry taking the same
     * caller-controlled storage key while skipping screen_key() — a
     * control-bearing key silently answered 0 instead of the typed
     * refusal every other key-taking method performs.
     *
     * @param string $provider_id
     * @return int
     */
    public function saveCount(string $provider_id): int
    {
        self::screen_key($provider_id);

        return $this->saveCounts[$provider_id] ?? 0;
    }

    /**
     * A storage-boundary copy of a grant (t31-r9-5): the token set
     * rebuilds through its STRICT storage serialization
     * (to_array()/from_array()) and the grant re-states through its own
     * private constructor in the class's scope — the hydration shape
     * StoredGrant's forward note reserves for Task 3.2's named producer
     * (a persisted Revoked tombstone re-states as the tombstone it is;
     * the full constructor validation re-runs on the way in). The same
     * whole-graph value round trip a real (encrypted) adapter puts the
     * grant through, so nothing the caller holds and nothing the caller
     * gets back is the instance the other side holds.
     *
     * @param StoredGrant $grant The grant to detach.
     * @return StoredGrant A value-equal, instance-distinct copy.
     */
    private static function detachedCopy(StoredGrant $grant): StoredGrant
    {
        $token_set = null === $grant->token_set()
            ? null
            : AccessTokenSet::from_array($grant->token_set()->to_array());

        $restate = \Closure::bind(
            static function (StoredGrant $grant, ?AccessTokenSet $token_set): StoredGrant {
                return new StoredGrant($grant->provider_id(), $grant->generation(), $grant->state(), $token_set);
            },
            null,
            StoredGrant::class
        );

        return $restate($grant, $token_set);
    }
}
