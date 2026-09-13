<?php
/**
 * Deterministic clock for time-sensitive contract tests (Task 3.1).
 *
 * Implements the shared clock port with a fully controlled reading, so
 * token expiry windows, refresh skew, and cooldown math can be pinned to
 * the second without sleeping. Independent of WpHarness's frozen time
 * (that one serves the WordPress API stubs; shared contracts must not
 * reach for host facilities at all).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Shared\Clock\ClockInterface;

final class DeterministicClock implements ClockInterface
{
    /** @var \DateTimeImmutable */
    private \DateTimeImmutable $reading;

    /**
     * @param \DateTimeImmutable $starting_at The initial reading.
     */
    public function __construct(\DateTimeImmutable $starting_at)
    {
        $this->reading = $starting_at;
    }

    /**
     * The controlled reading.
     *
     * @return \DateTimeImmutable
     */
    public function now(): \DateTimeImmutable
    {
        return $this->reading;
    }

    /**
     * Moves the reading forward (never backward — cooldown/expiry tests
     * age time; rewinding is not a wall-clock behavior).
     *
     * @param int $seconds Seconds to advance by (must be non-negative).
     * @return void
     */
    public function advanceBy(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('DeterministicClock only advances; ' . $seconds . ' given.');
        }

        $this->reading = $this->reading->modify(sprintf('+%d seconds', $seconds));
    }
}
