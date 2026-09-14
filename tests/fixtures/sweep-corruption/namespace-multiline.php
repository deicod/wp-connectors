<?php
/**
 * Sweep-corruption fixture: the shared namespace spelled across lines,
 * outside any rewritable statement.
 *
 * Planted for the namespace gate pin (t31-r4 K1 / t31-r4-5): the string
 * literal carries '…\WpConnectors\' and 'Shared\…' on different lines, so
 * the old per-line whitelist never saw one line containing the full
 * namespace substring — the multiline spelling shipped past the sweep
 * (and past the build's contiguous postcondition probe) at exit 0. The
 * whole-file gate blanks rewritable statements and scans what remains
 * with the whitespace-tolerant survivor pattern.
 */

final class NamespaceMultilineFixture
{
    /**
     * The runtime value names a shared class across a line break.
     *
     * @return string
     */
    public function class_name(): string
    {
        return 'Deicod\WpConnectors\
Shared\Clock';
    }
}
