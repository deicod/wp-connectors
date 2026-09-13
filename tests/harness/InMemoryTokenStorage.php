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
        return $this->grants[$provider_id] ?? null;
    }

    public function save(string $provider_id, StoredGrant $grant, int $expected_generation): bool
    {
        $persisted = $this->grants[$provider_id] ?? null;
        $persisted_generation = null === $persisted
            ? TokenStorageInterface::EXPECT_NO_GRANT
            : $persisted->generation();

        if ($persisted_generation !== $expected_generation) {
            return false;
        }

        $this->grants[$provider_id] = $grant;
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
}
