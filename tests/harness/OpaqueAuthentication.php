<?php
/**
 * The identity-passthrough request authentication double (glm22-10).
 *
 * The opaque (non-Api-key) wiring only third-party
 * setRequestAuthentication() callers can produce — the shape the
 * glm14-5/glm16-1 opaque-wiring pins and the wrap-funnel foreign-auth
 * tests drive. The identity-passthrough anonymous class was spelled
 * five times across the suites under three different names, so an SDK
 * method addition to RequestAuthenticationInterface would break five
 * fatal sites instead of one, and a future copy could silently differ
 * from the passthrough contract those pins assume. One harness-owned
 * double now; if the interface grows a method, this file is the single
 * place to satisfy it.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

final class OpaqueAuthentication implements \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface
{
    /**
     * Passes the request through untouched.
     *
     * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The outgoing request.
     * @return \WordPress\AiClient\Providers\Http\DTO\Request The same request.
     */
    public function authenticateRequest(\WordPress\AiClient\Providers\Http\DTO\Request $request): \WordPress\AiClient\Providers\Http\DTO\Request
    {
        return $request;
    }

    /**
     * The empty schema (no serialized state).
     *
     * @return array
     */
    public static function getJsonSchema(): array
    {
        return array();
    }
}
