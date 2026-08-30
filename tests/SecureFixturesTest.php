<?php
/**
 * Secure test-fixture rule acceptance tests (Task 0.6).
 *
 * Proves the fake-secret factories produce scanner-clean fixture values,
 * the response builders emit the documented shapes, and the secret scanner
 * intentionally rejects a known-secret fixture while accepting the repo.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/lib/secret-scanner.php';

final class SecureFixturesTest extends WpConnectorsTestCase
{
    /*
     * Fake secret factories.
     */

    public function testFakeKeyFactoriesProduceMarkedSecrets()
    {
        foreach (array( 'apiKey', 'accessToken', 'refreshToken', 'deviceCode', 'codeVerifier' ) as $factory) {
            $value = FakeSecrets::$factory();
            $this->assertStringContainsString('wpct_fixture', $value, "{$factory} must carry the fixture marker");
            // Factory output is scanner-clean even without marker context.
            $this->assertSame(array(), wp_connectors_scan_string($value, 'inline'));
        }
    }

    public function testZaiShapedKeyMatchesLivePatternOnlyInline()
    {
        $key = FakeSecrets::zaiShapedKey();

        // It genuinely looks live (so redaction tests are meaningful)...
        $this->assertSame(
            array( 'bare:1 zai-key (z.ai / bigmodel.cn API key)' ),
            wp_connectors_scan_string($key, 'bare')
        );

        // ...and the documented pairing rule keeps stored fixtures clean:
        // a zai-shaped key may only be stored next to its marker.
        $line = $key . ' // fixture';
        $this->assertSame(array(), wp_connectors_scan_string($line, 'marked'));
    }

    public function testFakeJwtRoundTripsClaimsAndIsFixtureMarked()
    {
        $jwt = FakeSecrets::jwt(array( 'email' => 'fixture-user@example.test', 'exp' => 1700003600 ));

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertTrue($payload['fixture']);
        $this->assertSame('fixture.test', $payload['iss']);
        $this->assertSame('fixture-user@example.test', $payload['email']);

        // Redaction helper sees nothing to leak.
        $this->assertRedacted('Token stored for fixture account', $jwt);
    }

    public function testSeededFakeTokenStaysOutOfPlaintextOptions()
    {
        $token = FakeSecrets::accessToken();

        // Simulate a plugin storing an encrypted envelope holding the token.
        update_option('fixture_oauth_tokens', array(
            'v' => 1,
            'envelope' => base64_encode(hash('sha256', $token, true) . 'opaque-ciphertext'),
        ));

        $this->assertOptionNotPlaintext('fixture_oauth_tokens', $token);
    }

    /*
     * HTTP response builders.
     */

    public function testWpAndPsr7BuildersProduceExpectedShapes()
    {
        $wp = HttpResponseFactory::wp(429, '{"error":"rate_limited"}', array( 'Retry-After' => '7' ));
        $this->assertSame(429, wp_remote_retrieve_response_code($wp));
        $this->assertSame('7', wp_remote_retrieve_header($wp, 'Retry-After'));

        $psr7 = HttpResponseFactory::psr7(200, '{"ok":true}', array( 'Content-Type' => 'application/json' ));
        $this->assertSame(200, $psr7->getStatusCode());

        // Both plug straight into the harness mock layers.
        $this->mockHttpResponse($wp);
        $this->assertSame(429, wp_remote_retrieve_response_code(wp_remote_get('https://fixture.test/x')));
        WpHarness::$sdk_mock_queue[] = $psr7;
    }

    public function testOpenAiModelBodyMatchesEvidenceShape()
    {
        $body = HttpResponseFactory::openAiModelsBody(array( 'glm-5.3', 'glm-5.3-flash' ));
        $decoded = json_decode($body, true);

        $this->assertSame('list', $decoded['object']);
        $this->assertCount(2, $decoded['data']);
        $this->assertSame('glm-5.3', $decoded['data'][0]['id']);
        $this->assertSame('model', $decoded['data'][0]['object']);
        $this->assertSame('z-ai', $decoded['data'][0]['owned_by']);
        $this->assertArrayHasKey('created', $decoded['data'][0]);
    }

    public function testErrorBodyBuilders()
    {
        $oauth = json_decode(HttpResponseFactory::oauthErrorBody('invalid_grant', 'fixture grant expired'), true);
        $this->assertSame('invalid_grant', $oauth['error']);
        $this->assertSame('fixture grant expired', $oauth['error_description']);

        $openai = json_decode(HttpResponseFactory::openAiErrorBody('fixture message'), true);
        $this->assertSame('fixture message', $openai['error']['message']);
    }

    /*
     * Secret scanner acceptance: reject known secrets, accept the repo.
     */

    public function testScannerRejectsKnownSecretFixture()
    {
        // Built at runtime (never a literal in source): a realistic z.ai-shaped
        // key and a GitHub-style token, with no fixture markers around them.
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));
        $githubToken = 'ghp_' . bin2hex(random_bytes(18));

        $tempDir = sys_get_temp_dir() . '/wp-connectors-scan-' . getmypid();
        if (is_dir($tempDir)) {
            WpHarness::rrmdir($tempDir);
        }
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/known-secret-fixture.conf', "api_key = {$zaiKey}\ntoken: {$githubToken}\n");

        $findings = wp_connectors_scan_paths(array( $tempDir ));
        WpHarness::rrmdir($tempDir);

        $report = implode("\n", $findings);
        $this->assertStringContainsString('zai-key', $report);
        $this->assertStringContainsString('github-token', $report);
        // Findings must never echo the secret itself.
        $this->assertStringNotContainsString($zaiKey, $report);
        $this->assertStringNotContainsString($githubToken, $report);
    }

    public function testScannerAcceptsRepoSources()
    {
        $repoRoot = dirname(__DIR__);
        $findings = wp_connectors_scan_paths(array(
            $repoRoot . '/connectors',
            $repoRoot . '/shared',
            $repoRoot . '/bin',
            $repoRoot . '/tests',
            $repoRoot . '/docs',
            $repoRoot . '/README.md',
            $repoRoot . '/CHANGELOG.md',
        ));

        $this->assertSame(array(), $findings, 'Repository sources must stay secret-free: ' . implode("\n", $findings));
    }

    public function testLiveTestEnvironmentVariablesAreOptInOnly()
    {
        // The documented opt-in variables (docs/TESTING.md) must never be set
        // during offline runs, so tests can never accidentally go live.
        $optIn = array(
            'WP_CONNECTORS_TEST_ZAI_API_KEY',
            'WP_CONNECTORS_TEST_ZAI_REGION',
            'WP_CONNECTORS_TEST_ZAI_PLAN',
            'WP_CONNECTORS_TEST_OPENAI_REFRESH_TOKEN',
            'WP_CONNECTORS_TEST_XAI_REFRESH_TOKEN',
            'WP_CONNECTORS_TEST_ANTHROPIC_REFRESH_TOKEN',
        );
        foreach ($optIn as $name) {
            $this->assertFalse(getenv($name), "{$name} must not be set during the offline suite.");
        }
    }
}
