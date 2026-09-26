<?php
/**
 * Sweep-corruption fixture: a direct clock read in a VO-shaped file.
 *
 * Planted for the clock/environment gate pin (t31-r2-7): the host is
 * reached only through the ports, and a direct clock read inside
 * shared/ would bypass the clock port entirely.
 */

final class PlantedClockFixture
{
    public function stale(): int
    {
        return time();
    }
}
