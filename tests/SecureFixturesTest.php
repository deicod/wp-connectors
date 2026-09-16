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
        // a zai-shaped key may only be stored next to the strict marker.
        $line = $key . ' // secrets:allow';
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

    /**
     * OCR-round-1 pin (t31-ocr1-5): the repo walk's prune list is a
     * SUBSET of the one development-entry vocabulary, and it is judged
     * by the vocabulary's OWN fold — never the byte-exact
     * array_intersect the prune used to spell. A case-variant 'VENDOR/'
     * or 'Tools/' is a development entry to the builder, inspector,
     * and lint (all folded); the repo walk prunes it in exactly those
     * spellings now. The subset boundary stays sharp in every casing:
     * 'Tests/' IS a development entry to the folded gates but is NOT
     * one of the pruned names — the repo scan covers tests by
     * contract, so a live-looking key under it still FINDS.
     */
    public function testTheRepoWalkPruneFoldsLikeTheDevelopmentEntryVocabulary()
    {
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));

        $tempDir = sys_get_temp_dir() . '/wp-connectors-scan-prune-' . getmypid();
        if (is_dir($tempDir)) {
            WpHarness::rrmdir($tempDir);
        }
        mkdir($tempDir . '/VENDOR', 0755, true);
        mkdir($tempDir . '/Tools', 0755, true);
        mkdir($tempDir . '/Tests', 0755, true);
        mkdir($tempDir . '/phpunit.cache', 0755, true);
        foreach (array( 'VENDOR', 'Tools', 'Tests' ) as $prunedOrCovered) {
            file_put_contents($tempDir . '/' . $prunedOrCovered . '/leak.conf', "api_key = {$zaiKey}\n");
        }
        file_put_contents($tempDir . '/phpunit.cache/cached.xml', "<r>{$zaiKey}</r>\n");

        try {
            $report = implode("\n", wp_connectors_scan_paths(array( $tempDir )));

            // Case-variant spellings of PRUNED names: never descended.
            $this->assertStringNotContainsString('VENDOR', $report, 'A case-variant vendor segment prunes exactly where the folded gates judge it a development entry.');
            $this->assertStringNotContainsString('Tools', $report, 'A case-variant tools segment prunes exactly where the folded gates judge it a development entry.');
            // A vocabulary member the subset does not name: still this
            // scan's charge, in any casing.
            $this->assertStringContainsString('Tests/leak.conf', $report, 'Tests is not one of the pruned names — the repo scan covers it in every casing.');
            $this->assertStringContainsString('zai-key', $report);
            // The dotless cache spelling is not the subset's '.phpunit.cache'
            // either — scanned, not skipped.
            $this->assertStringContainsString('phpunit.cache/cached.xml', $report, 'The prune subset names the dotted .phpunit.cache only; the dotless spelling stays scanned.');
            // Findings still never echo the secret itself.
            $this->assertStringNotContainsString($zaiKey, $report);

            /*
             * The below-root boundary (verifier round t31-ocr1-12): the
             * prune judged FULL pathname parts, so a dev-named ANCESTOR
             * of the scan root pruned everything under it silently
             * (reproduced: 0 findings under a Dist/ ancestor while the
             * same tree under Dst/ found) — the exact-case shape was
             * pre-round, the ocr1-5 fold widened it to every casing.
             * Ancestors are not this walk's dev tree; only segments
             * BELOW the root judge. The ancestor segment is a
             * CASE-VARIANT of an actually-excluded name (OCR round 4,
             * t31-ocr4-6): the former 'Dist-ancestor-<pid>' spelling
             * matched nothing under EITHER judging, so the sub-test
             * stayed green over the regression it documented — full-
             * pathname judging must match this segment (and prune, and
             * find nothing) for the pin to kill the below-root slice's
             * removal.
             */
            $holder = dirname($tempDir) . '/wp-connectors-scan-ancestor-' . getmypid();
            $ancestor = $holder . '/DIST';
            mkdir($ancestor . '/root', 0755, true);
            file_put_contents($ancestor . '/root/leak.conf', "api_key = {$zaiKey}\n");
            try {
                $ancestorReport = implode("\n", wp_connectors_scan_paths(array( $ancestor . '/root' )));
                $this->assertStringContainsString('root/leak.conf', $ancestorReport, 'A dev-named ANCESTOR (case-variant, the folded spelling) of the scan root never prunes the scan itself.');
                $this->assertStringContainsString('zai-key', $ancestorReport);
            } finally {
                WpHarness::rrmdir($holder);
            }
        } finally {
            WpHarness::rrmdir($tempDir);
        }
    }

    /**
     * OCR-round-9 pin (t31-ocr9-6): the artifact scan (prune off)
     * never prunes — a dev-shaped segment inside a fully extracted
     * artifact is the SIGNAL, not a skip (the r12-3 doctrine) — and
     * the per-file segment walk is now skipped entirely when pruning
     * is off (it existed only to prune; its inner guard was constant
     * for the whole walk). This pins UNCHANGED VERDICTS over a fixture
     * tree on both sides of the flag: the repo scan prunes the
     * dev-shaped segment, the artifact scan reads straight through it,
     * and neither verdict changes shape.
     */
    public function testTheArtifactScanNeverPrunesAndTheRepoScanKeepsItsVerdicts()
    {
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));

        $tempDir = sys_get_temp_dir() . '/wp-connectors-scan-artifact-' . getmypid();
        if (is_dir($tempDir)) {
            WpHarness::rrmdir($tempDir);
        }
        mkdir($tempDir . '/VENDOR', 0755, true);
        mkdir($tempDir . '/plain', 0755, true);
        file_put_contents($tempDir . '/VENDOR/leak.conf', "api_key = {$zaiKey}\n");
        file_put_contents($tempDir . '/plain/leak.conf', "api_key = {$zaiKey}\n");

        try {
            $repoReport = implode("\n", wp_connectors_scan_paths(array( $tempDir ), true));
            $artifactReport = implode("\n", wp_connectors_scan_paths(array( $tempDir ), false));

            $this->assertStringNotContainsString('VENDOR', $repoReport, 'The repo scan keeps pruning the dev-shaped segment.');
            $this->assertStringContainsString('plain/leak.conf', $repoReport, 'The repo scan keeps its coverage verdict outside the segment.');

            $this->assertStringContainsString('VENDOR/leak.conf', $artifactReport, 'The artifact scan reads straight through the dev-shaped segment — its verdict unchanged.');
            $this->assertStringContainsString('plain/leak.conf', $artifactReport, 'The artifact scan keeps the plain verdict too.');
            $this->assertStringNotContainsString($zaiKey, $artifactReport, 'Findings still never echo the secret itself.');
        } finally {
            WpHarness::rrmdir($tempDir);
        }
    }

    /**
     * OCR-round-3 pin (t31-ocr3-5): the scanner library is
     * self-contained on its own load path. The prune's fold mechanic
     * (wp_connectors_segment_is_named()) lives in the vocabulary owner
     * (plugin-tools.php), and the scanner used to rely on its CALLERS
     * having loaded plugin-tools first — this suite's own require at
     * the top of this file is exactly that load pattern, which worked
     * only because the test bootstrap happened to load plugin-tools
     * before PHPUnit reached it. A fresh process requiring ONLY
     * bin/lib/secret-scanner.php fataled mid-scan on the first walked
     * entry ("Call to undefined function"). The dependency is declared
     * by require_once INSIDE the library now: a subprocess proves the
     * fresh-process load pattern works AND prunes case-variant dev
     * segments exactly as the in-process battery above pins.
     */
    public function testRequiringOnlyTheScannerLibraryScansAndPrunes()
    {
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));

        $tempDir = sys_get_temp_dir() . '/wp-connectors-scan-only-' . getmypid();
        if (is_dir($tempDir)) {
            WpHarness::rrmdir($tempDir);
        }
        mkdir($tempDir . '/VENDOR', 0755, true);
        mkdir($tempDir . '/plain', 0755, true);
        file_put_contents($tempDir . '/VENDOR/leak.conf', "api_key = {$zaiKey}\n");
        file_put_contents($tempDir . '/plain/leak.conf', "api_key = {$zaiKey}\n");

        try {
            $script = 'require ' . var_export(realpath(__DIR__ . '/../bin/lib/secret-scanner.php'), true) . ';'
                . ' foreach (wp_connectors_scan_paths(array(' . var_export($tempDir, true) . ')) as $finding) { echo $finding, "\n"; }';
            $output = array();
            $exit = 1;
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

            $report = implode("\n", $output);
            $this->assertSame(0, $exit, "A fresh process requiring ONLY the scanner library must scan, never fatal mid-walk: {$report}");
            $this->assertStringContainsString('plain/leak.conf', $report, 'The fresh-process scan finds the live-looking key outside the pruned segments.');
            $this->assertStringContainsString('zai-key', $report);
            $this->assertStringNotContainsString('VENDOR', $report, 'The case-variant dev segment prunes exactly as the in-process battery pins.');
            $this->assertStringNotContainsString($zaiKey, $report, 'Findings still never echo the secret itself.');
        } finally {
            WpHarness::rrmdir($tempDir);
        }
    }

    public function testScannerDoesNotBypassOnGenericProseWords()
    {
        // Regression for the over-broad marker allowlist: generic words like
        // "example"/"sample"/"fake" must NOT suppress scanning of a real
        // credential shape on the same line.
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));
        $githubToken = 'ghp_' . bin2hex(random_bytes(18));
        $contents = "See the example integration guide: api_key = {$zaiKey}\n"
            . "# sample environment config\ntoken: {$githubToken}\n";

        $findings = wp_connectors_scan_string($contents, 'prose');

        $report = implode("\n", $findings);
        $this->assertStringContainsString('zai-key', $report);
        $this->assertStringContainsString('github-token', $report);
    }

    public function testScannerDoesNotBypassOnBareFixtureWord()
    {
        // Regression for the loose fixture-marker exemption: a line merely
        // CONTAINING the substring "fixture" must no longer suppress the
        // scan — a real credential on such a line stays detectable.
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));
        $openaiKey = 'sk-proj-' . bin2hex(random_bytes(20));

        $contents = "\$fixture_key = \"{$zaiKey}\";\n"
            . '// fixture-shaped sample credentials for the docs' . "\n"
            . "fixture {$openaiKey} token\n";

        $findings = wp_connectors_scan_string($contents, 'fixtureword');

        $report = implode("\n", $findings);
        $this->assertStringContainsString('fixtureword:1 zai-key', $report);
        $this->assertStringContainsString('fixtureword:3 openai-anthropic-key', $report);
        // The old loose comment marker ("// fixture") no longer exempts either.
        $loose = wp_connectors_scan_string($zaiKey . ' // fixture', 'loose');
        $this->assertStringContainsString('zai-key', implode("\n", $loose));
    }

    public function testScannerExemptsOnlyStrictMarkerAndFakeValues()
    {
        // (a) The strict marker comment — and only it — exempts a line.
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));
        $this->assertSame(array(), wp_connectors_scan_string($zaiKey . ' // secrets:allow', 'sl'));
        $this->assertSame(array(), wp_connectors_scan_string('# secrets:allow ' . $zaiKey, 'hash'));
        // A lookalike token outside a comment is not the marker.
        $fakeMarker = wp_connectors_scan_string("\$note = 'secrets:allow'; key = \"{$zaiKey}\";", 'fakemarker');
        $this->assertStringContainsString('zai-key', implode("\n", $fakeMarker));

        // (b) Values recognizable as fakes pass without any marker: well-known
        // dummy segments, placeholder shapes, sequential filler.
        $this->assertSame(array(), wp_connectors_scan_string('sk-proj-TEST-PLACEHOLDER-0123456789', 'dummy'));
        $this->assertSame(array(), wp_connectors_scan_string('token = sk-ant-YOUR_KEY_abcdefgh1234', 'yourkey'));
        $this->assertSame(array(), wp_connectors_scan_string('key = ${ZAI_API_KEY}', 'curly'));
        $this->assertSame(array(), wp_connectors_scan_string('key = <api-key>', 'angle'));

        // ...while shape-identical live-looking values stay flagged.
        $this->assertStringContainsString(
            'openai-anthropic-key',
            implode("\n", wp_connectors_scan_string('sk-proj-' . str_repeat('q', 40), 'live'))
        );
    }

    public function testMarkerInsideStringContentsDoesNotExemptTheLine()
    {
        // Regression: the marker regex matched comment syntax INSIDE quoted
        // strings, so a line carrying a live value plus the string content
        // "// secrets:allow" was fully exempted. Markers must live in REAL
        // comments (string-literal contents are blanked out before the
        // marker search).
        $openaiKey = 'sk-proj-' . bin2hex(random_bytes(20));
        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));

        // (a) A lookalike marker held in string content exempts nothing:
        // the live value next to it stays flagged.
        $inString = wp_connectors_scan_string("\$note = '{$openaiKey}' . '// secrets:allow';", 'instr');
        $this->assertStringContainsString('openai-anthropic-key', implode("\n", $inString));

        // Escape-aware: a marker hidden behind an escaped quote inside a
        // string is still string content, not a comment.
        $escaped = wp_connectors_scan_string("\$m = 'can\\'t trust // secrets:allow'; k = \"{$zaiKey}\";", 'esc');
        $this->assertStringContainsString('zai-key', implode("\n", $escaped));

        // (c) '#' comment markers behave identically.
        $hashInString = wp_connectors_scan_string("\$cfg = '# secrets:allow' . \"{$zaiKey}\";", 'hashstr');
        $this->assertStringContainsString('zai-key', implode("\n", $hashInString));

        // (b) A REAL trailing marker comment still exempts its line — both
        // after a recognizably fake value and after a live-looking one.
        $this->assertSame(array(), wp_connectors_scan_string('sk-proj-TEST-PLACEHOLDER-0123456789 // secrets:allow', 'fakedummy'));
        $this->assertSame(array(), wp_connectors_scan_string("key = {$openaiKey} // secrets:allow", 'livedummy'));
        $this->assertSame(array(), wp_connectors_scan_string("cfg = {$zaiKey} # secrets:allow", 'realhash'));
        $this->assertSame(array(), wp_connectors_scan_string("# secrets:allow {$zaiKey}", 'realhash2'));
    }

    public function testScannerAcceptsRepoSources()
    {
        $repoRoot = dirname(__DIR__);
        // Mirror the CLI default: the whole repository root (the scan prunes
        // .git/vendor/node_modules/dist/tools itself), so root config files
        // like mise.toml and composer.json are covered too.
        $findings = wp_connectors_scan_paths(array( $repoRoot ));

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
            // Assert on a boolean so a failure diff can never echo the value.
            $this->assertFalse(getenv($name) !== false, "{$name} must not be set during the offline suite.");
        }
    }
}
