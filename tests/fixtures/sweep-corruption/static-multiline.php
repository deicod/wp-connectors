<?php
/**
 * Sweep-corruption fixture: a static property spelled across lines.
 *
 * Planted for the static-mutable gate pin (t31-r2-5): 'private static'
 * and the typed variable sit on DIFFERENT lines, so the old per-line
 * application of the gate's pattern never saw the spelling (the pattern
 * itself matches it — its \s+ legitimately spans the break — which is
 * exactly why the gate had to move to whole-file application).
 */

final class StaticMultilineFixture
{
    private static
        int $counter;
}
