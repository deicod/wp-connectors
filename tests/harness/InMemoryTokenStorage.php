<?php
/**
 * In-memory TokenStorageInterface fake (Task 3.1).
 *
 * The contract-test fake for the shared storage port: a per-provider
 * map with whole-grant replacement. Reused by the encrypted-storage and
 * refresh-coordination suites as the in-memory half of comparison tests.
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

    public function save(string $provider_id, StoredGrant $grant): void
    {
        $this->grants[$provider_id] = $grant;
        $this->saveCounts[$provider_id] = ($this->saveCounts[$provider_id] ?? 0) + 1;
    }

    public function delete(string $provider_id): void
    {
        unset($this->grants[$provider_id]);
    }

    /**
     * @param string $provider_id
     * @return int
     */
    public function saveCount(string $provider_id): int
    {
        return $this->saveCounts[$provider_id] ?? 0;
    }
}
