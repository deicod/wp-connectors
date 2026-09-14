<?php
/**
 * Sweep-corruption fixture: a WordPress reach spelled across lines.
 *
 * Planted for the WP-reach gate pin (t31-r3-8): the call name and its
 * argument list sit on DIFFERENT lines, so the old per-line application
 * of the gate's pattern never saw the spelling (the pattern's \s*
 * legitimately spans the break) — exactly the class the static/clock
 * gates closed by moving to whole-file application (t31-r2-5).
 */

final class WpReachMultilineFixture
{
    public function label(): string
    {
        $saved = __
            ( 'save' );

        return $saved;
    }
}
