<?php
/**
 * Sweep-corruption fixture: a provider name in a provider-neutral shape.
 *
 * Planted for the provider-name gate pin (t31-r3-8): the generic shared
 * classes must not name any provider — provider config belongs to the
 * per-plugin directories — and the gate now applies its pattern to the
 * whole file through the same helper every other gate rides.
 */

final class ProviderNameFixture
{
    public function vendor(): string
    {
        return 'claude';
    }
}
