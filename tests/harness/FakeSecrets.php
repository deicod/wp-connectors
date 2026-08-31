<?php
/**
 * Fake credential factories for tests and fixtures.
 *
 * Every value produced here is (a) generated at runtime, (b) carries an
 * obvious fixture marker, and (c) therefore passes the secret scanner
 * (bin/lib/secret-scanner.php) while still exercising real code paths.
 * NEVER put real credentials in tests — use these factories.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

final class FakeSecrets
{
    /**
     * Generic API key with an obvious fixture marker.
     *
     * @return string
     */
    public static function apiKey()
    {
        return 'wpct_fixture_' . bin2hex(random_bytes(16));
    }

    /**
     * A key shaped like a real z.ai key (32hex.16hex) but built from a
     * runtime-random value and exempted only via the strict companion
     * marker. Use only for redaction/format tests; an exact
     * `// secrets:allow` comment must be stored alongside it (a bare
     * "fixture" word exempts nothing).
     *
     * @return string
     */
    public static function zaiShapedKey()
    {
        return bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));
    }

    /**
     * OAuth access token (fixture).
     *
     * @return string
     */
    public static function accessToken()
    {
        return 'wpct_fixture_at_' . bin2hex(random_bytes(24));
    }

    /**
     * OAuth refresh token (fixture).
     *
     * @return string
     */
    public static function refreshToken()
    {
        return 'wpct_fixture_rt_' . bin2hex(random_bytes(24));
    }

    /**
     * Device-flow code (fixture).
     *
     * @return string
     */
    public static function deviceCode()
    {
        return 'wpct_fixture_dc_' . bin2hex(random_bytes(16));
    }

    /**
     * PKCE code verifier (fixture, RFC 7636 charset).
     *
     * @return string
     */
    public static function codeVerifier()
    {
        return 'wpct_fixture_verifier_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Builds an unsigned fixture JWT carrying the given claims.
     *
     * The payload always includes "fixture": true and a fixture issuer, so
     * scanner allowlisting and downstream assertions can recognize it. Not
     * cryptographically valid — never use for signature tests.
     *
     * @param array<string, mixed> $claims Extra JWT claims.
     * @return string JWT-shaped string.
     */
    public static function jwt(array $claims = array())
    {
        $header = array( 'alg' => 'none', 'typ' => 'JWT', 'fixture' => true );
        $payload = array_merge(
            array( 'iss' => 'fixture.test', 'sub' => 'fixture-subject', 'iat' => 1700000000, 'fixture' => true ),
            $claims
        );

        $encode = static function ( array $data ) {
            return rtrim(strtr(base64_encode(wp_json_encode($data)), '+/', '-_'), '=');
        };

        return $encode($header) . '.' . $encode($payload) . '.wpct_fixture_signature';
    }
}
