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
 * boundary. The fake models that boundary now (a serialize/unserialize
 * round trip — exactly what a real adapter's encode/decode does to the
 * grant), so a test that leans on instance identity fails against the
 * fake exactly as it would against the envelope, instead of passing
 * here and breaking in Task 3.2.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Grant\StoredGrant;
use Deicod\WpConnectors\Shared\Grant\TokenStorageInterface;

final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<string, StoredGrant> */
    private array $grants = array();

    /** @var array<string, int> */
    private array $saveCounts = array();

    public function load(string $provider_id): ?StoredGrant
    {
        $grant = $this->grants[$provider_id] ?? null;

        return null === $grant ? null : self::detachedCopy($grant);
    }

    public function save(string $provider_id, StoredGrant $grant, int $expected_generation): bool
    {
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
        unset($this->grants[$provider_id]);
    }

    /**
     * How many commits (successful saves) the provider has seen — the
     * fenced-off attempts do not count, they committed nothing.
     *
     * @param string $provider_id
     * @return int
     */
    public function saveCount(string $provider_id): int
    {
        return $this->saveCounts[$provider_id] ?? 0;
    }

    /**
     * A storage-round-trip copy of a grant (t31-r9-5): serialize out,
     * unserialize back — the same whole-graph encode/decode a real
     * (encrypted) adapter puts the grant through, so nothing the
     * caller holds and nothing the caller gets back is the instance
     * the other side holds. The construction is the boundary: a
     * Revoked tombstone reconstructs too (the round trip bypasses the
     * private constructor, the same forward note StoredGrant carries
     * for Task 3.2's deliberate hydration producer).
     *
     * @param StoredGrant $grant The grant to detach.
     * @return StoredGrant A value-equal, instance-distinct copy.
     * @throws RuntimeException When the round trip yields anything but the grant (never constructible for this VO graph).
     */
    private static function detachedCopy(StoredGrant $grant): StoredGrant
    {
        $copy = unserialize(serialize($grant));

        if (!$copy instanceof StoredGrant) {
            throw new RuntimeException('The in-memory storage fake could not reconstruct the stored grant — the storage round trip is broken.');
        }

        return $copy;
    }
}
