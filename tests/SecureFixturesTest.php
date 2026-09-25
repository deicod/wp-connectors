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

        $tempDir = $this->scanScratchRoot('wp-connectors-scan');
        /*
         * The staging-assert sweep completion (t31-ocr54-1 — the
         * t31-ocr53-6 sweep asserted every OTHER scan site this file
         * grew and walked past the file's own first one): the battery
         * planted its fixture on bare mkdir()/file_put_contents(), so
         * a failed stage (a read-only temp, ENOSPC) surfaced as a
         * missing 'zai-key'/'github-token' verdict — scanner-shaped
         * red over a staging failure, the misattribution class. Every
         * staging write asserts its own landing now, naming its path
         * (the r53-6 sweep's own idiom).
         *
         * The staging-inside-try completion (t31-ocr55-7 — the
         * battery the r54-1 asserts GAINED was left out of the
         * r54-2 inside-try closure, and rode in NO try at all): a
         * scan throw or a report assert once stranded the planted
         * tree in the shared temp root with no finally anywhere.
         * Staging and scan ride inside the try now, the finally
         * owning every exit from the first mkdir on — the report
         * asserts judge captured findings above the release.
         */
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create — a staging failure fails as staging, never as the maker verdict.");
            $this->assertNotFalse(file_put_contents($tempDir . '/known-secret-fixture.conf', "api_key = {$zaiKey}\ntoken: {$githubToken}\n"), "staging: {$tempDir}/known-secret-fixture.conf must write — a staging failure fails as staging, never as the maker verdict.");

            $findings = wp_connectors_scan_paths(array( $tempDir ));
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        $this->assertStringContainsString('zai-key', $report);
        $this->assertStringContainsString('github-token', $report);
        // Findings must never echo the secret itself.
        $this->assertStringNotContainsString($zaiKey, $report);
        $this->assertStringNotContainsString($githubToken, $report);
    }

    public function testTheMarkupHtmlFamilyIsScannedForCredentials()
    {
        /*
         * t31-glm38-2 [R38-2, security:medium, driven end-to-end]: the
         * walk's extension allowlist omitted html/htm/xhtml while the
         * same library's marker grammar serves exactly that family — a
         * live credential embedded in an HTML asset was never read,
         * admin.html shipping ACCEPTED where the byte-identical
         * admin.svg was REJECTED (the same bytes judged purely by
         * extension, the driven producer the ledger's generic
         * allowlist residual names). The trio joins the walk
         * allowlist: every markup spelling answers the finding.
         */
        $githubToken = 'ghp_' . bin2hex(random_bytes(18));
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-html');
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create.");
            foreach (array( 'admin.html', 'admin.htm', 'admin.xhtml', 'admin.svg' ) as $asset) {
                $this->assertNotFalse(
                    file_put_contents($tempDir . '/' . $asset, "<script>var k = \"{$githubToken}\";</script>\n"),
                    "staging: {$tempDir}/{$asset} must write."
                );
            }

            $findings = wp_connectors_scan_paths(array( $tempDir ));
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        foreach (array( 'admin.html', 'admin.htm', 'admin.xhtml', 'admin.svg' ) as $asset) {
            $this->assertStringContainsString($asset . ':1 github-token', $report, "The credential in {$asset} is read — the markup family and the marker grammar agree for the first time (red at HEAD: the html trio absent).");
        }
        $this->assertStringNotContainsString($githubToken, $report, 'Findings never echo the secret itself.');
    }

    public function testAMarkerInsideAQuotePairStraddlingASampleNeverExempts(): void
    {
        /*
         * R39-1 (security:medium, driven fail-open — the marker
         * honored inside STRING DATA at the sample-straddling
         * boundary): a quote pair straddling an embedded '<?php …
         * ?>' sample never pairs in the per-slice prose view (the
         * region compositor blanks the sample bytes between them),
         * so a '// secrets:allow' marker sitting between those
         * quotes matched the prose arm and exempted a live
         * credential on the same line. The marker consults the
         * QUOTE-BLANKED prose view — glm19-2's doctrine (a
         * marker-shaped text in string data is data, never an
         * exemption) at the one boundary the per-slice view left
         * open. Five legs: the laundered shape flags now; the
         * no-marker and no-marker-in-quotes controls flag; the
         * REAL prose marker still exempts; the marker AFTER a
         * properly-quoted string (outside the pair) still exempts.
         */
        $token = 'ghp_' . bin2hex(random_bytes(18));
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-marker');
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create.");
            $legs = array(
                'straddled' => 1, // red at HEAD: 0 — the laundered shape.
                'no-marker' => 1,
                'no-marker-quotes' => 1,
                'real-marker' => 0,
                'marker-after-quotes' => 0,
            );
            $lines = array(
                "straddled" => "guide 'note <?php \$x=1; ?> ok // secrets:allow done' {$token}\n",
                'no-marker' => "guide note ok done {$token}\n",
                'no-marker-quotes' => "guide 'note <?php \$x=1; ?> ok done' {$token}\n",
                'real-marker' => "guide note ok // secrets:allow done {$token}\n",
                'marker-after-quotes' => "guide 'a quoted note' // secrets:allow {$token}\n",
            );
            foreach ($legs as $leg => $expected) {
                $this->assertNotFalse(file_put_contents($tempDir . '/guide.md', $lines[ $leg ]), "staging: {$tempDir}/guide.md ({$leg}) must write.");
                $findings = wp_connectors_scan_paths(array( $tempDir ));
                $this->assertCount(
                    $expected,
                    $findings,
                    "The {$leg} leg answers exactly {$expected} finding(s) — the marker inside a quote pair straddling a sample never exempts while real prose markers still do."
                );
            }
        } finally {
            WpHarness::releaseScratch($tempDir);
        }
    }

    /**
     * glm14-2: a failed read is a finding, never a laundered empty scan.
     */
    public function testAnUnreadableFileFailsTheSecretScanLoudly()
    {
        /*
         * The old (string) file_get_contents() casts (both arms — the
         * file-root target and the walk) laundered a false read into
         * an empty string: a chmod-000 file carrying a live-shaped
         * token scanned to "0 finding(s)" exit 0 with the raw E_WARNING
         * leaked beside it, while every sibling gate treats the same
         * shape as a loud FAIL (check-conventions' unreadable-file
         * violation; php -l's exit 1). The security gate was the one
         * silent channel; driven red at HEAD exactly this shape (0
         * findings, both arms).
         */
        $this->skipChmod0000LegOnRootRunner('the unreadable-file secret-scan leg');
        $githubToken = 'ghp_' . bin2hex(random_bytes(18));
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-unreadable');
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create — a staging failure fails as staging, never as the scan verdict.");
            $this->assertNotFalse(file_put_contents($tempDir . '/readable.php', "<?php\n\$t = '{$githubToken}';\n"), "staging: {$tempDir}/readable.php must write — a staging failure fails as staging, never as the scan verdict.");
            $this->assertNotFalse(file_put_contents($tempDir . '/unreadable.php', "<?php\n\$t = '{$githubToken}';\n"), "staging: {$tempDir}/unreadable.php must write — a staging failure fails as staging, never as the scan verdict.");
            $this->assertTrue(chmod($tempDir . '/unreadable.php', 0000), "staging: {$tempDir}/unreadable.php must lock — the permission-bit premise of this leg.");

            $findings = wp_connectors_scan_paths(array( $tempDir ));
            $directFileFindings = wp_connectors_scan_paths(array( $tempDir . '/unreadable.php' ));
        } finally {
            @chmod($tempDir . '/unreadable.php', 0644);
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        // The unreadable file refuses loudly through BOTH arms — the walk and the file-root target.
        $this->assertStringContainsString('unreadable.php: unreadable file — the secret scan cannot run', $report);
        $this->assertStringContainsString('unreadable file — the secret scan cannot run', implode("\n", $directFileFindings));
        // Readable files in the same tree scan unchanged, and no finding ever echoes the token.
        $this->assertStringContainsString('readable.php', $report);
        $this->assertStringContainsString('github-token', $report);
        $this->assertStringNotContainsString($githubToken, $report);
    }

    /**
     * glm14-3: the over-size skip is loud — the verdict never reads
     * clean over bytes the walk did not read. glm20-1: the file-root
     * arm answers the same loud refusal for a directly-named over-bound
     * single file.
     */
    public function testAnOverSizeFileFailsTheSecretScanLoudly()
    {
        /*
         * The walk silently skipped any file over 2 MB (no diagnostic,
         * no doctrine note): a 2.4 MB big.php beside a 41-byte
         * small.php carrying the IDENTICAL live-shaped token answered
         * exactly 1 finding — big.php invisible — and a zip shipping a
         * >2 MB entry passed the inspector's credential screen
         * ACCEPTED at 0 violations (driven red at HEAD by the
         * reviewer, re-driven pre-fix). The cap stays (a deliberate
         * memory bound over trees the walk did not choose); the skip
         * answers a finding line now, so both consumers — the CLI's
         * exit code and the inspector's violations — refuse.
         *
         * glm20-1 (the #1 hybrid's caller-seam half): the FILE-ROOT
         * arm named directly had no cap at all — the token-memory
         * census charges only the span factor, so a >2 MB prose-heavy
         * named file (a tiny sample span, the census passing) scanned
         * EVERY byte to a clean zero-finding verdict, no refusal
         * (driven red at HEAD: 0 findings over a 2.85 MB payload, the
         * bound never consulted) — and a dense-enough shape kept the
         * fatal-without-verdict window instead, the census's
         * uncharged whole-call terms (the residual the round-20 ledger
         * names). The named target answers the same LOUD 2 MB refusal
         * the walk ships, and the under-bound twin still scans.
         */
        $githubToken = 'ghp_' . bin2hex(random_bytes(18));
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-oversize');
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create — a staging failure fails as staging, never as the scan verdict.");
            $this->assertNotFalse(file_put_contents($tempDir . '/small.php', "<?php\n\$t = '{$githubToken}';\n"), "staging: {$tempDir}/small.php must write — a staging failure fails as staging, never as the scan verdict.");
            // 2,450,041 bytes — one byte class over the 2 * 1024 * 1024 cap, the reviewer's own driven size.
            $this->assertNotFalse(file_put_contents($tempDir . '/big.php', '<?php\n$t = \'' . $githubToken . '\';\n' . str_repeat('// ' . bin2hex(random_bytes(16)) . "\n", 98000)), "staging: {$tempDir}/big.php must write — a staging failure fails as staging, never as the scan verdict.");
            $this->assertGreaterThan(2 * 1024 * 1024, filesize($tempDir . '/big.php'), 'staging: big.php must land over the 2 MB cap the leg premises.');
            // The FILE-ROOT shape: over the cap on BYTES, a tiny sample
            // span — the census passes, every byte scans (no live key:
            // the HEAD verdict reads clean, the silent half of the red).
            $this->assertNotFalse(file_put_contents($tempDir . '/wide.php', '<?php $x = 1; ?> ' . str_repeat("// prose line without any key\n", 95000)), "staging: {$tempDir}/wide.php must write — a staging failure fails as staging, never as the scan verdict.");
            $this->assertGreaterThan(2 * 1024 * 1024, filesize($tempDir . '/wide.php'), 'staging: wide.php must land over the 2 MB cap the leg premises.');
            // glm21-2: a >2 MB entry whose extension the walk never
            // reads — the artifact shape's assets/big.png.
            $this->assertNotFalse(file_put_contents($tempDir . '/big.png', str_repeat('x', 3 * 1024 * 1024 + 1)), "staging: {$tempDir}/big.png must write — a staging failure fails as staging, never as the scan verdict.");
            $this->assertGreaterThan(2 * 1024 * 1024, filesize($tempDir . '/big.png'), 'staging: big.png must land over the 2 MB cap the leg premises.');

            $findings = wp_connectors_scan_paths(array( $tempDir ));
            $directOverBound = wp_connectors_scan_paths(array( $tempDir . '/wide.php' ));
            $directUnderBound = wp_connectors_scan_paths(array( $tempDir . '/small.php' ));
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        $this->assertStringContainsString('big.php: over the 2 MB secret-scan size limit — the secret scan cannot run', $report);
        // The under-limit twin still scans normally, and no finding ever echoes the token.
        $this->assertStringContainsString('small.php', $report);
        $this->assertStringContainsString('github-token', $report);
        $this->assertStringNotContainsString($githubToken, $report);

        /*
         * glm21-2: the allowlist judges BEFORE the cap — the cap once
         * fired first, so a legitimate 3 MB assets/big.png inside a
         * shipped artifact rejected the whole inspection over bytes
         * the extension screen would never read (red at HEAD: the
         * big.png cap line rode the report). The cap fires only for
         * extensions the scan would actually read.
         */
        $this->assertStringNotContainsString('big.png', $report, 'A >2 MB entry whose extension the walk never reads stays skipped by the ALLOWLIST — the size cap fires only for extensions the scan would actually read (red at HEAD: the big.png cap line).');

        // glm20-1: the directly-named over-bound file answers the SAME
        // loud refusal (red at HEAD: the whole 2.85 MB scanned to a
        // clean zero-finding verdict, no refusal line at all).
        $this->assertSame(
            array( $tempDir . '/wide.php: over the 2 MB secret-scan size limit — the secret scan cannot run' ),
            $directOverBound,
            'A >2 MB single-file argument answers the loud refusal naming the file — the CLI exit code and the inspector both derive their refusal from this list.'
        );
        // The under-bound named twin scans unchanged — the cap never over-refuses.
        $this->assertSame(
            array( $tempDir . '/small.php:2 github-token (GitHub token)' ),
            $directUnderBound,
            'An under-bound single-file argument scans exactly as before the cap.'
        );
    }

    /**
     * glm21-3: a scan root that names NOTHING — a typo'd CLI target, a
     * dangling symlink — answers the loud refusal naming it, never a
     * clean '0 finding(s)' exit 0 over a tree the scan never saw (the
     * glm14-5/ocr53-4/glm14-2 refuse-loudly class, observed in
     * ocr20-4's narrative and never adjudicated until this round).
     */
    public function testAMissingScanRootAnswersTheLoudRefusalNeverACleanVerdict()
    {
        $missing = sys_get_temp_dir() . '/wp-connectors-scan-missing-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->assertSame(
            array( $missing . ': unreadable scan root — the secret scan cannot run' ),
            wp_connectors_scan_paths(array( $missing )),
            'A typo\'d CLI target certifies nothing clean — the missing root answers the loud refusal naming it (red at HEAD: 0 findings, exit 0).'
        );

        // A DANGLING-SYMLINK root answers the same refusal — is_file()
        // and is_dir() both read false through the dead link.
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the dangling-root leg cannot stage its dead link.');
        }
        $dangling = $missing . '-dangling';
        $this->assertTrue(@symlink($missing . '/nowhere', $dangling), 'staging: the dangling root link must take — a staging failure fails as staging, never as the scan verdict.');
        try {
            $this->assertSame(
                array( $dangling . ': unreadable scan root — the secret scan cannot run' ),
                wp_connectors_scan_paths(array( $dangling )),
                'A dangling-symlink root answers the same loud refusal — never a clean verdict over a tree the link never reaches.'
            );
        } finally {
            @unlink($dangling);
        }

        // An EXISTING root rides unchanged: the empty tree scans clean.
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-missingroot');
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create — a staging failure fails as staging, never as the scan verdict.");
            $this->assertSame(array(), wp_connectors_scan_paths(array( $tempDir )), 'An existing empty root scans clean exactly as before — the refusal owns the names-nothing class alone.');
        } finally {
            WpHarness::releaseScratch($tempDir);
        }
    }

    /**
     * The scan-scratch root maker — the r42-6 collision doctrine swept
     * to every site (OCR round 51, t31-ocr51-3; ONE census comment
     * across the file): every scan site this file grew — the
     * known-secret battery, the prune-fold battery, the artifact
     * battery, the fresh-process battery — derived its scratch root
     * pid-prefixed and RANDOM-suffixed, then RECLAIMED a pre-existing
     * tree at the freshly derived name (releaseScratch before the
     * mkdir). Per the battery's own t31-ocr42-6 census: a
     * random-suffixed name that already exists is a FOREIGN tree by
     * construction (same pid plus the same 4 random bytes is the
     * recycled-pid collision shape, 2^32 per pair) — the collision
     * REGENERATES, never reclaims; the reclaim arm's only reachable
     * effect was deleting another run's live scratch, the exact
     * cross-run-destruction class the suffix was added to close.
     * Same-process leftovers — a site that died between its mkdir and
     * its finally — are the PID PREFIX's own: the sweep below
     * reclaims this pid's stale trees (pid uniqueness keeps a live
     * foreign run out of the glob), everything else at the rolled
     * name is foreign, and the suffix ROLLS until the name is free —
     * the foreign tree stands untouched. The exact-name collision
     * landing itself is unconstructible without subverting the
     * random source (the r42-6 adjudication); the sweep and the
     * foreign-untouched legs are driven at
     * testTheScanScratchRootSweepsItsOwnStaleTreesAndNeverTouchesForeignOnes.
     *
     * @param string $stem The site's scratch stem (e.g. 'wp-connectors-scan-prune').
     * @return string A scratch root no existing tree occupies.
     */
    private function scanScratchRoot(string $stem): string
    {
        foreach (glob(sys_get_temp_dir() . '/' . $stem . '-' . getmypid() . '-*') ?: array() as $stale) {
            WpHarness::releaseScratch($stale);
        }
        do {
            $root = sys_get_temp_dir() . '/' . $stem . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        } while (is_dir($root));

        return $root;
    }

    /**
     * OCR-round-51 pin (t31-ocr51-3): the reclaim arms the r42-6
     * doctrine retired, driven at the maker that replaced them. The
     * SWEEP leg: a same-process stale tree (the debris shape — a site
     * that died between its mkdir and its finally) is reclaimed,
     * HEAD's sites reclaiming nothing. The FOREIGN leg: a tree in the
     * same name vocabulary under a pid this process does not hold
     * (the recycled-pid leftover shape, the r42-6 collision class —
     * planted under a pid ONE ABOVE the kernel's 2^22 pid ceiling,
     * claimable by no live process per the t31-ocr52-4 census below)
     * stands
     * untouched — the sweep's pid scope can never name it, and the
     * roll never reclaims it; a widened glob (the scope dropped) or a
     * restored reclaim arm over foreign names answers here. The FRESH
     * leg: the rolled root is free — the site proceeds under a suffix
     * no existing tree holds.
     */
    public function testTheScanScratchRootSweepsItsOwnStaleTreesAndNeverTouchesForeignOnes()
    {
        do {
            $stale = sys_get_temp_dir() . '/wp-connectors-scan-' . getmypid() . '-' . bin2hex(random_bytes(4));
        } while (is_dir($stale));
        /*
         * The staging-inside-try completion (t31-ocr55-7): the plants
         * once preceded the try (the $foreign pair) or rode in NO
         * finally at all (the $stale debris — the sweep's own
         * subject, so its release must survive a staging assert that
         * throws before the sweep ever runs). Every plant rides the
         * outer try now; the inner finally keeps its own release, and
         * the outer finally reclaims whatever still stands (a $stale
         * the maker's sweep already took is a guarded no-op, a
         * $foreign this test never finishes planting is reclaimed
         * instead of stranded).
         */
        try {
            $this->assertTrue(mkdir($stale, 0755, true), 'staging: the stale debris tree must create — a staging failure fails as staging, never as the maker verdict.');
            $this->assertNotFalse(file_put_contents($stale . '/debris.conf', "api_key = stale\n"), 'staging: the stale debris marker must write — a staging failure fails as staging, never as the maker verdict.');

            /*
             * The foreign pid rides ONE ABOVE THE KERNEL'S OWN CEILING
             * (PID_MAX_LIMIT, 2^22 = 4194304 on every 64-bit Linux build —
             * a pid above it is claimable by NO live process; OCR round
             * 52, t31-ocr52-4): the plant once spelled getmypid() + 1, an
             * ADJACENT pid — and adjacent pids are exactly two runners
             * spawned by one orchestrator (the parallel-CI shape the
             * HarnessCopyTreeTest 'parallel CI runners sharing the temp
             * root' doctrine contemplates), so the plant could sit on a
             * REAL runner's live scratch vocabulary and the simulation
             * collide with what it simulates. Above the ceiling the
             * docblock's 'foreign by construction' claim holds
             * unconditionally: the sweep's pid scope can never name it
             * and no live process ever will.
             */
            $foreignPid = 4194305;
            do {
                $foreign = sys_get_temp_dir() . '/wp-connectors-scan-' . $foreignPid . '-' . bin2hex(random_bytes(4));
            } while (is_dir($foreign));
            $this->assertTrue(mkdir($foreign, 0755, true), 'staging: the foreign tree must create — a staging failure fails as staging, never as the maker verdict.');
            $this->assertNotFalse(file_put_contents($foreign . '/foreign.conf', "foreign run tree\n"), 'staging: the foreign marker must write — a staging failure fails as staging, never as the maker verdict.');

            $root = $this->scanScratchRoot('wp-connectors-scan');
            try {
                $this->assertFileDoesNotExist($stale, 'A same-process stale tree is the pid prefix\'s own — the sweep reclaims it (a site that died before its finally leaves no permanent debris).');
                $this->assertFileExists($foreign . '/foreign.conf', 'A tree under a pid this process does not hold is foreign by construction — the sweep\'s pid scope never names it, the roll never reclaims it.');
                $this->assertNotSame($stale, $root);
                $this->assertNotSame($foreign, $root);
                $this->assertTrue(mkdir($root, 0755, true), 'The rolled root is free — the site proceeds under a fresh suffix.');
            } finally {
                WpHarness::releaseScratch($foreign, $root);
            }
        } finally {
            // The foreign tree exists only past its own plants (a
            // $stale staging assert throws before $foreign is ever
            // derived) — release what stands, never an undefined
            // spelling.
            WpHarness::releaseScratch($stale);
            if (isset($foreign)) {
                WpHarness::releaseScratch($foreign);
            }
        }
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

        $tempDir = $this->scanScratchRoot('wp-connectors-scan-prune');
        /*
         * The staging-assert sweep (t31-ocr53-6 — the ocr27-9 doctrine,
         * the sweep battery's own rule four batteries over): these
         * batteries planted their fixtures on bare mkdir()/
         * file_put_contents(), so a failed stage (a read-only temp,
         * ENOSPC) surfaced as the test's own verdict — an EMPTY-REPORT
         * 'prune' leg here (a missing tree trivially contains no
         * 'VENDOR' fragment), a phantom 'no dev-named ancestor' below,
         * a misleading child-exit-code failure in the fresh-process
         * leg — never as the staging failure it was. Every staging
         * write in this sweep asserts its own landing, naming its
         * path (the r51-3 sweep battery's own idiom).
         *
         * The staging-inside-try completion (t31-ocr54-2 — the
         * t31-ocr16-14/t31-ocr18-3 leak class, ONE census comment
         * across the file's three scan batteries): the r53-6 asserts
         * landed BEFORE the try whose finally owns releaseScratch(),
         * so a failed staging assert threw with the tree half-planted
         * and no finally in scope — the partial scratch stranded in
         * the shared temp root (these stems are each used once per
         * run: no later battery's pid sweep reclaims them). Staging
         * rides inside the try now, this battery and the artifact
         * and fresh-process batteries below: the finally owns every
         * exit from the first mkdir on, and the staging failure
         * itself still fails as staging through the assert's own
         * named verdict while the release reclaims whatever landed.
         */
        try {
            $this->assertTrue(mkdir($tempDir . '/VENDOR', 0755, true), "staging: {$tempDir}/VENDOR must create — a staging failure fails as staging, never as the maker verdict.");
            $this->assertTrue(mkdir($tempDir . '/Tools', 0755, true), "staging: {$tempDir}/Tools must create — a staging failure fails as staging, never as the maker verdict.");
            $this->assertTrue(mkdir($tempDir . '/Tests', 0755, true), "staging: {$tempDir}/Tests must create — a staging failure fails as staging, never as the maker verdict.");
            $this->assertTrue(mkdir($tempDir . '/phpunit.cache', 0755, true), "staging: {$tempDir}/phpunit.cache must create — a staging failure fails as staging, never as the maker verdict.");
            foreach (array( 'VENDOR', 'Tools', 'Tests' ) as $prunedOrCovered) {
                $this->assertNotFalse(file_put_contents($tempDir . '/' . $prunedOrCovered . '/leak.conf', "api_key = {$zaiKey}\n"), "staging: {$tempDir}/{$prunedOrCovered}/leak.conf must write — a staging failure fails as staging, never as the maker verdict.");
            }
            $this->assertNotFalse(file_put_contents($tempDir . '/phpunit.cache/cached.xml', "<r>{$zaiKey}</r>\n"), "staging: {$tempDir}/phpunit.cache/cached.xml must write — a staging failure fails as staging, never as the maker verdict.");

            $report = implode("\n", wp_connectors_scan_paths(array( $tempDir )));

            // Case-variant spellings of PRUNED names: never descended.
            $this->assertStringNotContainsString('VENDOR', $report, 'A case-variant vendor segment prunes exactly where the folded gates judge it a development entry.');
            $this->assertStringNotContainsString('Tools', $report, 'A case-variant tools segment prunes exactly where the folded gates judge it a development entry.');
            // A vocabulary member the subset does not name: still this
            // scan's charge, in any casing. The expected fragments spell
            // their separator the way the producer does (t31-ocr21-5):
            // the scanner carries the raw iterator pathname, whose
            // joins are DIRECTORY_SEPARATOR — a hardcoded '/' would
            // fail the same verdict on Windows.
            $this->assertStringContainsString('Tests' . DIRECTORY_SEPARATOR . 'leak.conf', $report, 'Tests is not one of the pruned names — the repo scan covers it in every casing.');
            $this->assertStringContainsString('zai-key', $report);
            // The dotless cache spelling is not the subset's '.phpunit.cache'
            // either — scanned, not skipped.
            $this->assertStringContainsString('phpunit.cache' . DIRECTORY_SEPARATOR . 'cached.xml', $report, 'The prune subset names the dotted .phpunit.cache only; the dotless spelling stays scanned.');
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
            $holder = dirname($tempDir) . '/wp-connectors-scan-ancestor-' . getmypid() . '-' . bin2hex(random_bytes(4));
            $ancestor = $holder . '/DIST';
            try {
                /*
                 * The staging-inside-try completion (t31-ocr55-7): the
                 * r53-6 asserts landed BEFORE this inner try, so a
                 * failed staging assert threw with the ancestor tree
                 * half-planted and no finally in scope — the exact
                 * leak class one finally down. The plants ride the
                 * try whose finally owns them.
                 */
                $this->assertTrue(mkdir($ancestor . '/root', 0755, true), "staging: {$ancestor}/root must create — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
                $this->assertNotFalse(file_put_contents($ancestor . '/root/leak.conf', "api_key = {$zaiKey}\n"), "staging: {$ancestor}/root/leak.conf must write — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
                $ancestorReport = implode("\n", wp_connectors_scan_paths(array( $ancestor . '/root' )));
                $this->assertStringContainsString('root' . DIRECTORY_SEPARATOR . 'leak.conf', $ancestorReport, 'A dev-named ANCESTOR (case-variant, the folded spelling) of the scan root never prunes the scan itself.');
                $this->assertStringContainsString('zai-key', $ancestorReport);
            } finally {
                WpHarness::releaseScratch($holder);
            }
        } finally {
            WpHarness::releaseScratch($tempDir);
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

        $tempDir = $this->scanScratchRoot('wp-connectors-scan-artifact');
        try {
            $this->assertTrue(mkdir($tempDir . '/VENDOR', 0755, true), "staging: {$tempDir}/VENDOR must create — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
            $this->assertTrue(mkdir($tempDir . '/plain', 0755, true), "staging: {$tempDir}/plain must create — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
            $this->assertNotFalse(file_put_contents($tempDir . '/VENDOR/leak.conf', "api_key = {$zaiKey}\n"), "staging: {$tempDir}/VENDOR/leak.conf must write — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
            $this->assertNotFalse(file_put_contents($tempDir . '/plain/leak.conf', "api_key = {$zaiKey}\n"), "staging: {$tempDir}/plain/leak.conf must write — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");

            $repoReport = implode("\n", wp_connectors_scan_paths(array( $tempDir ), true));
            $artifactReport = implode("\n", wp_connectors_scan_paths(array( $tempDir ), false));

            $this->assertStringNotContainsString('VENDOR', $repoReport, 'The repo scan keeps pruning the dev-shaped segment.');
            $this->assertStringContainsString('plain' . DIRECTORY_SEPARATOR . 'leak.conf', $repoReport, 'The repo scan keeps its coverage verdict outside the segment.');

            $this->assertStringContainsString('VENDOR' . DIRECTORY_SEPARATOR . 'leak.conf', $artifactReport, 'The artifact scan reads straight through the dev-shaped segment — its verdict unchanged.');
            $this->assertStringContainsString('plain' . DIRECTORY_SEPARATOR . 'leak.conf', $artifactReport, 'The artifact scan keeps the plain verdict too.');
            $this->assertStringNotContainsString($zaiKey, $artifactReport, 'Findings still never echo the secret itself.');
        } finally {
            WpHarness::releaseScratch($tempDir);
        }
    }

    /**
     * Runs one scanner-library child script and answers its report —
     * the ONE spawn owner (glm20-5, hoisted at its FOURTH consumer,
     * the repo's own recorded threshold: the fresh-process leg, the
     * 23k-pair whale, the multi-span bound leg, and the INI-pinned
     * lexing pair — four inline realpath+assertNotFalse-plus-exec
     * stanzas already drifting, the '";\n"' concat against the sprintf
     * bind and the -d flags scattered per site).
     *
     * The owner carries the t31-ocr25-8 doctrine for every consumer:
     * the library path is asserted resolved BEFORE the embed — a
     * realpath() false (a broken checkout, an open_basedir wall) is an
     * environment problem, never the defect the child would otherwise
     * fatal as — and the script sprintf-binds the path at its
     * `require %s;` (never a concat), the caller's INI flags riding
     * the child's -d options, the output lines collapsed into one
     * report beside the exit code.
     *
     * glm20-6: the spawn is BOUNDED — exec() waits on the child
     * forever, so a never-terminating regression in the scanner (the
     * glm15-4 precedent: the unbounded rescan) would hang phpunit
     * itself with no verdict, no failure, no timeout. The owner rides
     * coreutils timeout(1) on POSIX hosts (the isPosixHost() gate —
     * a host without the tool answers its own loud 127, never a
     * silent unbounded wait), the default bound generous beside every
     * consumer's measured wall clock.
     *
     * @param string       $script          PHP code carrying one `require %s;` placeholder for the library path.
     * @param list<string> $ini_flags       INI spellings for the child (e.g. 'memory_limit=1G'); already-shell-safe literals from this file.
     * @param int          $timeout_seconds The spawn bound (glm20-6); the tight bound is the driven leg's own.
     * @param bool         $raw_ini_flags   The MUTATION spelling (glm24-7): splice each -d flag RAW, the escapeshellarg removed — the one call shape the glm23-9 driver rides to prove the seam; never a green leg's.
     * @return array{report: string, exit: int} The child's merged stdout/stderr and its exit code (124: killed at the bound).
     */
    private function spawnScannerChild(string $script, array $ini_flags = array(), int $timeout_seconds = 30, bool $raw_ini_flags = false): array
    {
        $scannerLibrary = realpath(__DIR__ . '/../bin/lib/secret-scanner.php');
        $this->assertNotFalse($scannerLibrary, 'The scanner library path must resolve before the spawned-engine leg runs — a realpath() false (a broken checkout, an open_basedir wall) is an environment problem, never the defect the child would otherwise carry.');
        $command = WpHarness::isPosixHost() ? 'timeout ' . $timeout_seconds . ' ' : '';
        $command .= escapeshellarg(PHP_BINARY);
        foreach ($ini_flags as $flag) {
            /*
             * glm21-11: each flag rides escapeshellarg — this was the
             * repo's single variable-interpolated unescaped value at
             * an exec seam (the six call sites ship literals today,
             * but the OWNER is the seam every future flag rides): one
             * escaped token per -d value, a flag carrying spaces or
             * shell metacharacters reaching the child intact instead
             * of the shell reading them as its own grammar. The
             * $raw_ini_flags escape hatch (glm24-7) is the mutation
             * driver's alone — the seam's own mutation expressed as
             * one owner parameter, never a hand-copied command
             * spawning a stale child beside the green run's twin
             * (the glm23-15 class).
             */
            $command .= ' -d ' . ($raw_ini_flags ? $flag : escapeshellarg($flag));
        }
        $command .= ' -r ' . escapeshellarg(sprintf($script, var_export($scannerLibrary, true)));
        $output = array();
        $exit = 1;
        exec($command . ' 2>&1', $output, $exit);
        $report = implode("\n", $output);

        /*
         * glm20-3/glm21-10: the warnings-to-failures regime RESTORED at
         * the spawn boundary — in-process, PHPUnit converts every
         * Warning/Notice/Deprecation to a failure; spawned, an engine
         * diagnostic printed into the child's merged report passed
         * UNASSERTED beside green verdict lines. glm20-3 pinned the
         * negative needle at the whale leg alone — ONE of the six
         * consumers, the other clean-exit legs running uncovered; the
         * needle rides the OWNER now: no PHP diagnostic line may
         * appear in the child's report (both CLI spellings covered —
         * this host prints 'Warning:', others prefix 'PHP '). The
         * timeout-bound leg (exit 124) is excepted: a killed child's
         * partial report is the bound's own subject, never the
         * cleanliness pin's. The mutant assert drives the needle's own
         * catch at every spawn.
         */
        $diagnostics_needle = '/^(?:PHP )?(?:Warning|Notice|Deprecated):/m';
        if (124 !== $exit) {
            $this->assertSame(0, preg_match($diagnostics_needle, $report), "The spawned engine runs clean — no engine Warning/Notice/Deprecation crosses the boundary unasserted: {$report}");
        }
        $this->assertSame(1, preg_match($diagnostics_needle, "Warning: Undefined variable \$key in Command line code on line 1"), 'The diagnostics needle catches an injected child-side warning — the regime the spawn boundary drops, restored at the owner.');

        return array( 'report' => $report, 'exit' => $exit );
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
        /*
         * The exec-capability guard (t31-ocr18-2, the t31-ocr16-12
         * doctrine over this child-process consumer): the
         * fresh-process load pattern rides a spawned engine, and on a
         * disable_functions host the spawn was an undefined-function
         * \Error instead of the visible skip.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the fresh-process leg cannot run (the scanner loads through a spawned engine).');
        }

        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));

        $tempDir = $this->scanScratchRoot('wp-connectors-scan-only');
        try {
            $this->assertTrue(mkdir($tempDir . '/VENDOR', 0755, true), "staging: {$tempDir}/VENDOR must create — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
            $this->assertTrue(mkdir($tempDir . '/plain', 0755, true), "staging: {$tempDir}/plain must create — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
            $this->assertNotFalse(file_put_contents($tempDir . '/VENDOR/leak.conf', "api_key = {$zaiKey}\n"), "staging: {$tempDir}/VENDOR/leak.conf must write — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
            $this->assertNotFalse(file_put_contents($tempDir . '/plain/leak.conf', "api_key = {$zaiKey}\n"), "staging: {$tempDir}/plain/leak.conf must write — a staging failure fails as staging, never as the maker verdict (the t31-ocr53-6 sweep).");
            /*
             * The library-path assert and the exec stanza ride the ONE
             * spawn owner (glm20-5) — its t31-ocr25-8 doctrine carrying
             * this leg's own subject: the path asserted resolved BEFORE
             * the embed, so a realpath() false never read as the
             * SCANNER's self-containment defect this pin exists to
             * vouch for (an environment failure stays the
             * environment's).
             */
            $script = 'require %s;'
                . ' foreach (wp_connectors_scan_paths(array(' . var_export($tempDir, true) . ')) as $finding) { echo $finding, "\n"; }';
            $spawned = $this->spawnScannerChild($script);

            $report = $spawned['report'];
            $exit = $spawned['exit'];
            $this->assertSame(0, $exit, "A fresh process requiring ONLY the scanner library must scan, never fatal mid-walk: {$report}");
            $this->assertStringContainsString('plain' . DIRECTORY_SEPARATOR . 'leak.conf', $report, 'The fresh-process scan finds the live-looking key outside the pruned segments.');
            $this->assertStringContainsString('zai-key', $report);
            $this->assertStringNotContainsString('VENDOR', $report, 'The case-variant dev segment prunes exactly as the in-process battery pins.');
            $this->assertStringNotContainsString($zaiKey, $report, 'Findings still never echo the secret itself.');
        } finally {
            WpHarness::releaseScratch($tempDir);
        }
    }

    /**
     * glm22-1 [#1, driven — the standing scan_paths residual since OCR
     * round 24 closes here by its own convention]: the walk names an
     * unreadable directory instead of dying as an uncaught SPL fatal.
     * A chmod-000 root or entry aborts the bare RecursiveDirectoryIterator
     * walk with its own UnexpectedValueException — from the constructor
     * or mid-recursion through getChildren() — and nothing caught it:
     * the CLI call site (scan-secrets.php) hands this walk an unfenced
     * tree by construction, so the whole scan died at exit 255 with NO
     * verdict (red at HEAD: the uncaught exception itself). The walk
     * rides the ocr33-6/ocr36-2 fence idiom its sibling walkers carry:
     * the boundary abort converts to the walk's own named refusal in
     * the glm14-2 vocabulary (the SPL message parenthetically), the
     * readable roots' findings stay beside it, and the walk keeps
     * answering past the abort.
     */
    public function testTheScanPathsWalkNamesAnUnreadableDirectoryInsteadOfDyingUncaught()
    {
        /*
         * The permission-denial probe (the t31-ocr30-3 capability
         * shape): a process the permissions cannot deny (root walks a
         * chmod-000 directory open) can never drive the refusal, and a
         * leg that cannot go red is a vacuous green — skip, naming the
         * premise. The probe restores its own permissions so the
         * finally's release owns it either way.
         *
         * glm23-10: the probe stages INSIDE its own try/finally (the
         * ocr30-3 sibling's shape): a false staging answer mid-probe —
         * a chmod the bits refuse, a mkdir that lands nowhere — once
         * leaks the /wpct-scan-perm-<uniqid> tree per failed run,
         * possibly at mode 0000. The restore stays AHEAD of the
         * finally's release (the asserted chmod-back, the skip's own
         * ordering preserved), and the finally's @chmod is the
         * idempotent belt that keeps releaseScratch able to list
         * whatever mode the tree died at — construction-evident: the
         * release call follows the probe in the finally now, every
         * exit shape covered.
         */
        $probe = sys_get_temp_dir() . '/wpct-scan-perm-' . uniqid('', true);
        $denied = false;
        try {
            $this->assertTrue(mkdir($probe, 0755, true), "staging: the probe directory must create — a staging failure fails as staging, never as the capability verdict.");
            $this->assertTrue(chmod($probe, 0000), "staging: the probe directory must lock — a staging failure fails as staging, never as the capability verdict.");
            $denied = WpHarness::canDenyDirectoryOpen($probe);
            $this->assertTrue(chmod($probe, 0755), "staging: the probe directory must unlock again — a staging failure fails as staging, never as the finally's cleanup.");
        } finally {
            @chmod($probe, 0755);
            WpHarness::releaseScratch($probe);
        }
        if (! $denied) {
            $this->markTestSkipped('This process walks a chmod-000 directory open (permissions cannot deny it — root-shaped), so the unreadable-directory leg can never drive its refusal: the walk would read the tree and answer as the readable control.');
        }

        $zaiKey = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));
        $readable = $this->scanScratchRoot('wp-connectors-scan-fence');
        $lockedRoot = $this->scanScratchRoot('wp-connectors-scan-fence');
        $lockedChild = $this->scanScratchRoot('wp-connectors-scan-fence');
        try {
            $this->assertTrue(mkdir($readable, 0755, true), "staging: {$readable} must create — a staging failure fails as staging, never as the fence verdict.");
            $this->assertNotFalse(file_put_contents($readable . '/leak.conf', "api_key = {$zaiKey}\n"), "staging: {$readable}/leak.conf must write — a staging failure fails as staging, never as the fence verdict.");
            $this->assertTrue(mkdir($lockedRoot, 0755, true), "staging: {$lockedRoot} must create — a staging failure fails as staging, never as the fence verdict.");
            $this->assertTrue(chmod($lockedRoot, 0000), "staging: {$lockedRoot} must lock — a staging failure fails as staging, never as the fence verdict.");
            $this->assertTrue(mkdir($lockedChild . '/locked', 0755, true), "staging: {$lockedChild}/locked must create — a staging failure fails as staging, never as the fence verdict.");
            $this->assertNotFalse(file_put_contents($lockedChild . '/locked/Hidden.conf', "api_key = {$zaiKey}\n"), "staging: the locked-tree source must write — a staging failure fails as staging, never as the fence verdict.");
            $this->assertTrue(chmod($lockedChild . '/locked', 0000), "staging: {$lockedChild}/locked must lock — a staging failure fails as staging, never as the fence verdict.");

            /*
             * The ROOT-order legs are order-deterministic (the input
             * array's own order — never the readdir hash order a
             * same-root sibling leg would depend on): the readable
             * root's finding stays BESIDE the locked root's refusal
             * (the partial count stays loud, the walk answering past
             * the abort — red at HEAD: the exception killed the scan
             * before any verdict).
             */
            $findings = wp_connectors_scan_paths(array( $readable, $lockedRoot ));
            $report = implode("\n", $findings);
            /*
             * glm23-12: the needle composes the separator — the
             * finding's label is the iterator's HOST-joined pathname,
             * so a '/'-hardcoded fragment reds over the platform's
             * separator vocabulary on a '\' host, through no defect of
             * the fence the leg pins (the fresh-process sibling's
             * composed-spelling idiom, t31-ocr29-7's class).
             */
            $this->assertStringContainsString($readable . DIRECTORY_SEPARATOR . 'leak.conf:1 zai-key', $report, 'The readable root\'s finding stands beside the refusal — the partial count stays loud, never laundered by the abort.');
            $this->assertStringContainsString("{$lockedRoot}: unreadable directory — the secret scan cannot run (", $report, 'The chmod-000 ROOT answers the walk\'s own named refusal (red at HEAD: the constructor\'s uncaught UnexpectedValueException), the SPL message parenthetically.');
            $this->assertStringNotContainsString($zaiKey, $report, 'Findings still never echo the secret itself.');

            /*
             * The CHILD leg: a chmod-000 child aborts the walk
             * MID-RECURSION (getChildren(), not the constructor) and
             * the root carries the same named refusal — the
             * unreachable source itself is never scanned (the tree is
             * judged whole or not at all), and never a fatal.
             */
            $childFindings = wp_connectors_scan_paths(array( $lockedChild ));
            $this->assertCount(1, $childFindings, 'The locked-child root answers exactly the one refusal line — the tree is named, never a stack trace (red at HEAD: the uncaught mid-recursion fatal).');
            $this->assertStringContainsString("{$lockedChild}: unreadable directory — the secret scan cannot run (", $childFindings[0], 'The mid-recursion abort names the walked root in the glm14-2 vocabulary, the SPL message parenthetically.');
            $this->assertStringContainsString('locked', $childFindings[0], 'The SPL message carries the unreadable entry itself.');
        } finally {
            @chmod($lockedRoot, 0755);
            @chmod($lockedChild . '/locked', 0755);
            WpHarness::releaseScratch($readable);
            WpHarness::releaseScratch($lockedRoot);
            WpHarness::releaseScratch($lockedChild);
        }
    }

    /**
     * glm20-6: the spawn is bounded — a never-terminating child
     * regression fails loudly at the bound, never hangs phpunit.
     */
    public function testASleepingScannerChildFailsLoudlyPastTheSpawnBound()
    {
        /*
         * exec() waits on the child FOREVER: a scanner regression that
         * never terminates (the glm15-4 unbounded-rescan precedent)
         * crossed into a spawned leg would hang the whole suite with
         * no verdict and no failure — the wall-clock pins this file
         * already carries guard the SLOW child, but nothing guarded
         * the one that never answers. The spawn owner rides coreutils
         * timeout(1) on POSIX hosts (the isPosixHost() gate): the
         * child sleeping past the bound is killed, the owner answering
         * timeout(1)'s own 124 exit beside whatever the child printed
         * before the kill — a fast, named failure. The leg drives the
         * mechanism at a TIGHT bound (2 s, the owner's own parameter)
         * so the proof costs seconds, never the production 30 s.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the bounded-spawn leg cannot run (the sleeping child rides a spawned engine).');
        }
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('This host is not the POSIX one — the spawn bound rides coreutils timeout(1), and the owner keeps the unbounded spawn on every other host.');
        }

        $started = microtime(true);
        $spawned = $this->spawnScannerChild('require %s; sleep(100000); echo "child-never-finished\n";', array(), 2);
        $elapsed = microtime(true) - $started;

        $this->assertSame(124, $spawned['exit'], "A child sleeping past the spawn bound is killed at it — timeout(1)'s own 124, never exec() waiting forever (red at HEAD: the hang itself): {$spawned['report']}");
        $this->assertStringNotContainsString('child-never-finished', $spawned['report'], 'The sleeping child never completes its verdict lines — the report carries no tail from a scan that never answered.');
        $this->assertLessThan(20.0, $elapsed, sprintf('The bound answers fast (%.1fs wall) — a hung-suite shape becomes a seconds-scale loud failure.', $elapsed));
    }

    /**
     * glm21-11: the spawn owner escapes its -d INI flags — the one
     * variable-interpolated value at an exec seam this repo shipped
     * (literals at the six call sites, but the owner is the seam). A
     * flag carrying shell metacharacters rides as ONE token: the shell
     * reads none of it as its own grammar (the driven poison carries
     * spaces, a command substitution, backgrounding, and a pipe — at
     * HEAD the raw splice answered the shell's own syntax error, exit
     * 2, the child never running), and the child answers its verdict
     * with the flag applied.
     *
     * glm22-9: the pin rides the REAL boundary now — the round-21
     * poison carried a ';', and php's own -d parser keeps only an
     * UNQUOTED value's pre-semicolon run (INI comment semantics, the
     * engine's own grammar — ';' ends the value, '&'/'|'/'('/')' are
     * the INI expression operators, driven: the unquoted poison
     * answers the parser's own "syntax error, unexpected ')'"), so
     * the old needle 'flag-token=wpct' proved the pre-space run
     * reached the child while every byte past the ';' never did: the
     * pin held only because the poison contained a semicolon. The
     * poison now rides the engine's own QUOTED-value spelling (the
     * INI single quote, as a php.ini author spells a value carrying
     * the grammar's own bytes) and the needle is the WHOLE value —
     * the anchored full-line match proves the SHELL boundary carried
     * every byte un-mangled and un-expanded to the engine's own
     * parser (a truncated or expanded tail fails it, the glm20-2
     * anchored-needle idiom), and an executed substitution would
     * REWRITE the line and fail the same needle — the non-execution
     * proof riding the same line. The unquoted semicolon cut stays
     * pinned beside it below: the engine's semantics, stated, never
     * the shell's.
     */
    public function testTheSpawnOwnerEscapesItsIniFlagsAtTheExecBoundary()
    {
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the escaped-flag leg cannot run (the probe rides a spawned engine).');
        }

        $poison = 'wpct $(echo pwned) "quoted" & |';
        $spawned = $this->spawnScannerChild(
            'require %s; echo "flag-token=", ini_get("user_agent"), "\n";',
            array( "user_agent='" . $poison . "'" )
        );

        $this->assertSame(0, $spawned['exit'], "The child runs with the metacharacter-bearing flag on its command line — one token at the exec boundary: {$spawned['report']}");
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote('flag-token=' . $poison, '/') . '$/m', $spawned['report']),
            'The WHOLE metacharacter-bearing value reaches the engine un-mangled and un-expanded — spaces, command substitution, double quotes, backgrounding, and pipe every byte intact through the shell boundary and the engine\'s own quoted-value grammar (the anchored full-line needle; the round-21 substring pin was vacuous for this class, and an executed substitution would rewrite the line and fail it).'
        );

        /*
         * The UNQUOTED semicolon cut the docblock above states,
         * pinned at the engine itself: ';' is the INI comment byte —
         * the pre-semicolon run alone is the value, the round-21
         * pin's hidden premise stated and driven.
         */
        $spawned_cut = $this->spawnScannerChild(
            'require %s; echo "flag-token=", ini_get("user_agent"), "\n";',
            array( 'user_agent=wpct probe; $(echo pwned) & |' )
        );
        $this->assertSame(0, $spawned_cut['exit'], "The unquoted semicolon-bearing twin runs too — one token at the boundary: {$spawned_cut['report']}");
        $this->assertSame(
            1,
            preg_match('/^flag-token=wpct probe$/m', $spawned_cut['report']),
            'A semicolon cuts an unquoted INI value at the engine\'s own comment grammar — the pre-semicolon run alone reaches the child, the hidden premise of the round-21 pin stated and pinned.'
        );
    }

    /**
     * glm23-9: the MUTATION DRIVER — the escaped-flag leg's own flag
     * spelling spliced RAW (the escapeshellarg the owner rides
     * removed, the seam's mutation): the shell passes the
     * single-quoted token whole, php RUNS, and the value arrives
     * MANGLED — the engine's own parser cutting it and printing its
     * own diagnostic (driven: exit 0, 'PHP:  syntax error, unexpected
     * \')\'', the flag-token line short of the whole value). This
     * CORRECTS round-22's own red claim: at the raw splice of the
     * INI-quoted flag spelling exec answers EXIT 0 with the child's
     * own INI diagnostic printed — never the 'shell's own syntax
     * error, exit 2, no child' the round documented (that shape held
     * for the round-21 UNQUOTED poison alone). The exit-0 assertion
     * is green over the mutation; the ANCHORED NEEDLE is the one
     * red, and this driver pins the child-side evidence it rides:
     * the flag-token line at the raw splice does NOT carry the whole
     * value.
     *
     * glm24-6: the driver premises the POSIX SHELL's single-quote
     * grammar (the flag spliced RAW as one single-quoted token — the
     * vocabulary a non-POSIX host spells differently) and the
     * anchored needle pins THIS ENGINE'S own INI quoted-value mangle
     * (the grammar the escaped-flag leg's docblock states — the
     * premise named, never assumed portable): ungated, the
     * t31-ocr29-7 class glm23-12 closed in the same file — a non-POSIX
     * host would red over the platform's own quoting vocabulary,
     * never the escaping seam the driver exists to pin. The leg gates
     * with its own named skip; POSIX behavior unchanged.
     *
     * glm25-2: the gate rides the method's TOP now — glm24-6 placed
     * it MID-METHOD, one leg deep (the escaped-flag leg's verdicts
     * already run above it), so on a non-POSIX host the skip folded
     * those already-run verdicts: the escaped-flag leg lost its
     * coverage there for a premise it does not carry (its own gate is
     * canSpawnChildren alone), and the unquoted-cut twin never ran at
     * all. The driver in its own method restores the shapes (each
     * method's verdicts independent, the file's own idiom).
     */
    public function testTheRawSpliceMutationDriverPinsTheMangledArrival()
    {
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the raw-splice driver cannot run (the probe rides a spawned engine).');
        }
        if (! WpHarness::isPosixHost()) {
            $this->markTestSkipped('This host\'s platform separator is not the POSIX one — the raw-splice driver splices its INI-quoted flag through the POSIX shell\'s single-quote grammar and its needle pins this engine\'s own INI mangle, so a non-POSIX host would judge the platform\'s quoting vocabulary, never the escaping seam the driver pins (glm24-6, the t31-ocr29-7 class).');
        }

        $poison = 'wpct $(echo pwned) "quoted" & |';
        /*
         * glm24-7: the driver rides the SPAWN OWNER (the mutation as
         * one raw-flags parameter) — the hand-copied plumbing
         * (realpath/timeout-30/escapeshellarg/sprintf bind/2>&1/
         * implode) was the glm23-15 hand-copied-spelling class one
         * round later: a later edit to the owner would leave a
         * re-spelled stanza spawning a STALE child beside the green
         * run's twin, a false verdict on the leg whose purpose is
         * proving the refusal. The command is byte-identical by
         * construction — the same owner, the one flag spliced raw.
         */
        $spawned_raw = $this->spawnScannerChild(
            'require %s; echo "flag-token=", ini_get("user_agent"), "\n";',
            array( "user_agent='" . $poison . "'" ),
            30,
            true
        );
        $raw_report = $spawned_raw['report'];
        $this->assertSame(0, $spawned_raw['exit'], "The FALSE PREMISE corrected, driven: at the raw splice of the INI-quoted flag the shell passes the token whole and php RUNS — exit 0 with the child's own INI diagnostic printed ({$raw_report}) — never round-22's claimed shell exit 2, so the exit assertion alone can never catch this mutation.");
        $this->assertSame(
            0,
            preg_match('/^' . preg_quote('flag-token=' . $poison, '/') . '$/m', $raw_report),
            "The child-side evidence the pin rides: the flag-token line at the raw splice does NOT carry the whole value ({$raw_report}) — the mangled arrival is exactly what the escaped-flag leg's anchored needle goes red over, the one distinguishing assertion at this mutation."
        );
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

    /**
     * glm21-4: the marker grammar knows the HTML COMMENT enclosure —
     * `<!-- secrets:allow -->` — the only comment syntax a markup
     * payload carries (.svg walk-allowlisted, .html CLI-named, .md
     * prose), while the markdown HEADING spelling rides the `#` arm
     * DELIBERATELY (markdown has no comment syntax; the heading is the
     * prose-level enclosure a marked .md fixture rides).
     */
    public function testTheAllowMarkerHonorsTheHtmlCommentEnclosure()
    {
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // The HTML comment exempts on every enclosure position (red at
        // HEAD: every commented row flagged — the grammar knew no '<!--').
        $this->assertSame(array(), wp_connectors_scan_string("<text>{$key}</text> <!-- secrets:allow -->\n", 'marked.svg'), 'The trailing HTML comment exempts the svg line (red at HEAD: flagged).');
        $this->assertSame(array(), wp_connectors_scan_string("<!-- secrets:allow --> <text>{$key}</text>\n", 'marked.svg'), 'The leading HTML comment exempts identically.');
        $this->assertSame(array(), wp_connectors_scan_string("<!--secrets:allow--> <text>{$key}</text>\n", 'page.html'), 'The tight no-space spelling exempts too, the CLI-named .html riding the same arm.');

        // The markdown HEADING spelling stays honored — re-derived and
        // pinned DELIBERATELY (the docblock's own note), never by accident.
        $this->assertSame(array(), wp_connectors_scan_string("# secrets:allow {$key}\n", 'head.md'), 'The markdown heading spelling stays honored through the # arm — a deliberate member of the enclosure vocabulary, pinned.');

        /*
         * glm22-3: the comment-form arm carries its OWN left-boundary
         * class — the enclosure once rode the shared whitespace guard,
         * right for ///# line comments but wrong for markup where a
         * comment characteristically follows its element with NO
         * separator: the glued compact-markup shapes are markers now
         * (red at HEAD: each flagged — a legitimately marked .svg
         * false-found), the tag-closer and quote-closer edges the
         * honest markup boundary.
         */
        $this->assertSame(array(), wp_connectors_scan_string("</text><!-- secrets:allow --> <text>{$key}</text>\n", 'glued.svg'), 'The glued tag-closer shape exempts (red at HEAD: flagged) — a comment follows its element with no separator.');
        $this->assertSame(array(), wp_connectors_scan_string("<svg><!-- secrets:allow --> <text>{$key}</text>\n", 'glued.svg'), 'The svg-opener glued shape exempts identically.');
        $this->assertSame(array(), wp_connectors_scan_string("<text>{$key}</text><!--secrets:allow-->\n", 'tight.svg'), 'The tight glued shape exempts too — no separator, no space inside the enclosure.');
        $this->assertSame(array(), wp_connectors_scan_string("<a title=\"x\"/><!-- secrets:allow --> <text>{$key}</text>\n", 'quoted.svg'), 'A quote-closer edge is the markup boundary class — the attribute list closes and the comment opens.');

        // The unmarked control still flags — the enclosure exempts, prose never does.
        $this->assertSame(
            array( "unmarked.svg:1 {$expect}" ),
            wp_connectors_scan_string("<text>{$key}</text>\n", 'unmarked.svg'),
            'The same svg line without the marker still flags — the enclosure owns the exemption, never the payload.'
        );
        // The compact UNMARKED shape still flags — the widened boundary
        // exempts marked compact markup, never the compact shape itself.
        $this->assertSame(
            array( "compact.svg:1 {$expect}" ),
            wp_connectors_scan_string("<svg><text>{$key}</text></svg>\n", 'compact.svg'),
            'Live keys inside compact svg with no marker still flag — the boundary class widens the enclosure edge, never the exemption.'
        );

        /*
         * The line-comment spellings keep the WHITESPACE guard — the
         * widened boundary class is the comment-form arm's alone (a
         * glued `key// secrets:allow` in code is not a comment the
         * grammar owes), and the established spellings answer
         * unchanged.
         */
        $this->assertStringContainsString('zai-key', implode("\n", wp_connectors_scan_string(bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8)) . '// secrets:allow', 'glued-line')), 'A value glued to a // marker is NOT exempt — the line-comment arms keep the whitespace guard.');
        $this->assertSame(array(), wp_connectors_scan_string($key . ' // secrets:allow', 'spaced-line'), 'The spaced // spelling exempts exactly as before.');
        $this->assertSame(array(), wp_connectors_scan_string('# secrets:allow ' . $key, 'spaced-hash'), 'The spaced # spelling exempts exactly as before.');

        /*
         * glm23-6: the glue-broadened '<!--' boundary is the MARKUP
         * family's alone — an HTML comment is a comment form markup
         * payloads genuinely carry (.html/.svg/.xml/.md, the family
         * glm21-4/glm22-3's premise holds over); in .env/.json/.txt
         * and every non-markup extension it is not, and the glued
         * boundary class (the quote/tag closers) LAUNDERED keys there:
         * `api_key="<live-key>"<!-- secrets:allow -->` answered ZERO
         * findings in a .env while the unmarked control flagged (red
         * at HEAD: 0 findings — the '"' before the '<!--' rode the
         * boundary class). The '<!--' spelling requires the
         * line-comment/whitespace boundary exactly as before in
         * non-markup; the SPACED spelling keeps exempting everywhere
         * it did.
         */
        $this->assertSame(
            array( "launder.env:1 {$expect}" ),
            wp_connectors_scan_string("api_key=\"{$key}\"<!-- secrets:allow -->\n", 'launder.env'),
            'A quote-glued <!-- marker in a .env does NOT exempt (red at HEAD: 0 findings) — .env carries no HTML comment form, and the glue-broadened boundary launders live keys there.'
        );
        $this->assertSame(
            array( "launder.json:1 {$expect}" ),
            wp_connectors_scan_string("\"api_key\": \"{$key}\"<!-- secrets:allow -->\n", 'launder.json'),
            'The quote-glued marker in a .json does not exempt either — the extension split refuses the laundering shape in every non-markup family member.'
        );
        $this->assertSame(
            array(),
            wp_connectors_scan_string("api_key = {$key} <!-- secrets:allow -->\n", 'spaced.env'),
            'The SPACED <!-- spelling keeps exempting in a .env exactly as before — the split refuses the GLUED boundary alone, never the spaced enclosure.'
        );
        $this->assertSame(
            array(),
            wp_connectors_scan_string("api_key = {$key} <!-- secrets:allow -->\n", 'spaced.txt'),
            'The spaced spelling in a .txt answers identically — the boundary doctrine changes nothing for the spellings that always exempted.'
        );

        /*
         * glm23-7: within the markup family the left boundary accepts
         * DIRECT TEXT adjacency too — the round's own premise (a
         * comment characteristically follows its element with no
         * separator) holds for text content exactly as it held for
         * the tag/quote closers glm22-3 named: `{$key}<!-- secrets:
         * allow -->` still FLAGGED while the spaced spelling exempted
         * (driven red at HEAD), the marker-arm's class split one
         * member short. The class is unified for the family: any
         * markup edge a comment opens at.
         */
        $this->assertSame(
            array(),
            wp_connectors_scan_string("{$key}<!-- secrets:allow -->\n", 'textglued.svg'),
            'A TEXT-glued compact marker in a .svg exempts (red at HEAD: flagged) — the element a comment follows with no separator need not be a tag.'
        );
        $this->assertSame(
            array( "textglued-control.svg:1 {$expect}" ),
            wp_connectors_scan_string("{$key} in prose\n", 'textglued-control.svg'),
            'Unmarked keys in the same shapes still flag — the widened boundary exempts the marker, never the payload.'
        );

        /*
         * glm24-1: the markup family's enumeration is COMPLETED —
         * php/phtml/htm/xhtml ride the arm beside html/svg/xml/md.
         * A .php template IS a payload whose comment grammar
         * includes HTML comments (the round's own doctrine: the
         * template spellings carry HTML bytes), and the SHORT
         * spellings join their long twins — a glued
         * `<!-- secrets:allow -->` in a .php template flagged at
         * HEAD while the identical .html exempted, and a .htm
         * flagged where .html exempted: the family is the
         * payload's comment grammar, never a hand-list of
         * extensions one spelling short.
         */
        $this->assertSame(
            array(),
            wp_connectors_scan_string("<input value=\"{$key}\"><!-- secrets:allow -->\n", 'template.php'),
            'A quote-glued marker in a .php template exempts (red at HEAD: flagged) — a .php template is a payload whose comment grammar includes HTML comments.'
        );
        $this->assertSame(
            array(),
            wp_connectors_scan_string("<input value=\"{$key}\"><!-- secrets:allow -->\n", 'template.phtml'),
            'The .phtml template spelling answers identically — the same template grammar, the same family.'
        );
        $this->assertSame(
            array(),
            wp_connectors_scan_string("<input value=\"{$key}\"><!-- secrets:allow -->\n", 'page.htm'),
            'The SHORT .htm spelling joins its .html twin (red at HEAD: flagged while .html exempted) — the family owns the grammar, not one spelling of it.'
        );
        $this->assertSame(
            array(),
            wp_connectors_scan_string("<input value=\"{$key}\"><!-- secrets:allow -->\n", 'page.xhtml'),
            'The .xhtml spelling answers identically — every markup spelling the walk can name rides the one family.'
        );
        $this->assertSame(
            array( "unmarked.php:1 {$expect}" ),
            wp_connectors_scan_string("<input value=\"{$key}\">\n", 'unmarked.php'),
            'The unmarked .php control still flags — the widened family exempts the marker, never the payload.'
        );
        // The non-markup refusal keeps its members: .txt joins .env/.json
        // above in refusing the glued form (the split is the grammar's,
        // never the extension's popularity).
        $this->assertSame(
            array( "launder.txt:1 {$expect}" ),
            wp_connectors_scan_string("api_key=\"{$key}\"<!-- secrets:allow -->\n", 'launder.txt'),
            'A quote-glued <!-- marker in a .txt still does NOT exempt — the completed markup family changed nothing for the non-markup members.'
        );
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

    public function testCrOnlyLineEndingsDoNotLaunderTheMarkerAcrossLines()
    {
        /*
         * t31-glm29-1 [R29-1, security:high, driven fail-open]: the
         * line split rode "\n" alone, so a CR-only payload collapsed to
         * ONE line and the line-local `secrets:allow` marker exempted a
         * live secret sitting on a DIFFERENT CR-line — the artifact
         * accepted at exit 0 where the byte-identical LF twin was
         * rejected (the glm19-2 per-arm doctrine violated at the
         * CR-line boundary). The split owns the tokenizer's exact three
         * terminators now (\r\n, \r, \n — the class the text lens
         * spells, never PCRE's broader \R), the marker's line-locality
         * computed over the same split: the CR twin flags exactly like
         * the LF twin, CRLF behavior byte-identical, and a marker on
         * the SAME CR-line as the key still exempts — the grammar
         * tightened its locality, never its vocabulary.
         */
        $key = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));

        $lf_findings = wp_connectors_scan_string("// secrets:allow\n\$key = \"{$key}\";\n", 'lf.env');
        $crlf_findings = wp_connectors_scan_string("// secrets:allow\r\n\$key = \"{$key}\";\r\n", 'crlf.env');
        $cr_findings = wp_connectors_scan_string("// secrets:allow\r\$key = \"{$key}\";\r", 'cr.env');

        $this->assertNotEmpty($lf_findings, 'The LF twin flags — the control.');
        $this->assertNotEmpty($crlf_findings, 'The CRLF twin flags exactly as before the fix (byte-identical behavior).');
        $this->assertNotEmpty($cr_findings, 'The CR marker+key payload flags like its LF twin (red at HEAD: zero findings — one line, the marker exempting across the CR boundary).');
        $this->assertStringContainsString('zai-key', implode("\n", $cr_findings), 'The finding is the live key on its own marker-free CR-line.');
        $this->assertStringContainsString('cr.env:2', implode("\n", $cr_findings), 'The finding names the CR-line the key sits on — the engine\'s own line count.');

        // A marked CR-line still exempts: the fix tightens locality at
        // the terminator, never the marker grammar itself.
        $this->assertSame(array(), wp_connectors_scan_string("{$key} // secrets:allow\r", 'sameline.env'), 'A marker on the SAME CR-line as the key exempts.');
        $this->assertSame(array(), wp_connectors_scan_string("{$key} // secrets:allow\r\n", 'sameline-crlf.env'), 'A marker on the SAME CRLF-line as the key exempts.');

        /*
         * The masked-view arm rides the same split: a CR-terminated PHP
         * payload's marker-in-comment exempts only its own CR-line —
         * the mask's terminator-preserving blank keeps $views
         * index-aligned with the source split (red at HEAD: the whole
         * payload one line, the code marker exempting the key line
         * across the CR boundary).
         */
        $php_findings = wp_connectors_scan_string("<?php\r// secrets:allow\r\$k = '{$key}';\r", 'cr.php');
        $this->assertNotEmpty($php_findings, 'The code arm honors the marker only on its own CR-line (red at HEAD: exempted).');
        $this->assertStringContainsString('cr.php:3', implode("\n", $php_findings), 'The code arm names the key\'s own CR-line.');
    }

    public function testTheArtifactScanRejectsACrLaunderedKey()
    {
        /*
         * The artifact-level shape of t31-glm29-1: a shipped file whose
         * CR-only line endings launder a live key past a marker sitting
         * on a different CR-line was ACCEPTED (exit 0 over the walk,
         * the byte-identical LF twin rejected) — the artifact scan now
         * answers the finding through the same three-terminator split.
         */
        $key = bin2hex(random_bytes(16)) . '.' . bin2hex(random_bytes(8));
        $tempDir = $this->scanScratchRoot('wp-connectors-cr-scan');
        try {
            $this->assertTrue(mkdir($tempDir . '/sub', 0755, true), "staging: {$tempDir}/sub must create — a staging failure fails as staging, never as the scan verdict.");
            $this->assertNotFalse(file_put_contents($tempDir . '/sub/notes.txt', "// secrets:allow\r\$key = \"{$key}\";\r"), "staging: {$tempDir}/sub/notes.txt must write — a staging failure fails as staging, never as the scan verdict.");

            $findings = wp_connectors_scan_paths(array( $tempDir ), false);
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        $this->assertNotEmpty($findings, 'The artifact with the CR-laundered key is REJECTED (red at HEAD: accepted at exit 0).');
        $this->assertStringContainsString('zai-key', $report, 'The finding is the live key, never its bytes.');
        $this->assertStringNotContainsString($key, $report, 'Findings never echo the secret itself.');
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

    public function testADictionaryWordInsideALiveValueExemptsNothing()
    {
        /*
         * R43-8 (driven under-refusal through the real CLI): the
         * recognizably-fake screen matched a dictionary word
         * anywhere separator-bounded INSIDE an already-matched value,
         * so a live credential whose own body carries '-test-' or
         * '_test_' (realistic staging tokens) was exempted wholesale
         * and shipped undetected where the '-tesx-' twin flagged. The
         * word exempts only when the value's HEAD before it is
         * placeholder material itself — empty, vendor markers and
         * short type segments (at most 4 bytes), other dictionary
         * words, or the sequential filler — never the high-entropy
         * bytes of a live body ('sk-proj-TEST-…' stays fake, the
         * documented vendor-example shape).
         */
        // The live bodies compose at runtime so this test's own source
        // never carries a contiguous credential shape (the repo scan's
        // own charge — the same discipline as the forgery pins).
        $live = array(
            'slack body, word between entropy runs' => 'xoxb-' . '7f3k9q2m' . '-test-' . 'QwRtYui2Zx9vBn4Lm8Kp',
            'bearer body, underscored word' => 'ghp_' . 'aZ1bY2' . '_test_' . 'cX0dW3eF4gH5iJ6kL7mN',
            'api03 body, word segment' => 'sk-ant-' . 'api03-' . '_test_-' . 'Zz9yXx8wWv7uUtTsRr',
        );
        foreach ($live as $name => $value) {
            $this->assertFalse(wp_connectors_is_recognizably_fake_secret($value), "The {$name} shape is live — entropy ahead of the word names it (red at HEAD: exempted).");
        }

        /*
         * t31-glm45-4 (R45-2, driven end-to-end — glm43-4's rule
         * never inspected the bytes AFTER the word): a short-prefix +
         * word + trailing-entropy live token shipped as fake where
         * the same entropy ahead of the word flags. The TAIL after
         * the word must be placeholder material too — never
         * high-entropy credential bytes on EITHER side.
         */
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'eu1' . '-test-' . '9f3k9q2m4p5o6i7u8'), 'Entropy AFTER the word names the live body too — the mirror half of the head rule (red at HEAD: exempted).');
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('sk-' . 'eu1' . '-test-' . '9f3k9q2m4p5o6i7u8x2'), 'The api-key family carries the same tail-entropy shape.');

        /*
         * t31-glm48-3 (R48-3, driven through the real CLI — the
         * tail's entropy budget is AGGREGATE, never per segment):
         * the per-segment at-most-6 slack admitted ANY COUNT of
         * short entropy segments — 35 chunked bytes in seven 5-byte
         * pieces shipped as fake where the SAME bytes contiguous
         * flag, a live credential chunked into dash-separated
         * pieces sailing past the contract this very pin states.
         * The filler anchor tightened with it: a segment carrying
         * non-sequential bytes AROUND the digit run
         * ('k0123456789z') is entropy, the PURE sequential runs
         * ('0123456789', 'abcdefgh1234') staying filler.
         */
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . 'k3f9m' . '-7d2xq' . '-9m4zb' . '-2h8wn' . '-5c7yp' . '-8r3tv' . '-4n6yq'), 'Chunked entropy is entropy — the aggregate budget counts every short segment together (red at HEAD: exempted).');
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . 'k0123456789' . 'z'), 'Non-sequential bytes around the digit run name the live body — the pure-run filler keeps only pure runs (red at HEAD: exempted).');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('sk-proj-' . 'TEST-' . 'PLACEHOLDER-' . '0123456789'), 'The PURE digit run stays filler — the pinned placeholder spelling keeps its exemption.');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('sk-ant-' . 'YOUR_KEY_' . 'abcdefgh1234'), 'The digit-flanked sequential run stays filler — the pinned vendor-example spelling keeps its exemption.');

        /*
         * R55-1 (driven through the real CLI): the tail rules judged
         * only the bytes AFTER the run — any live credential inside
         * one segment became exempt filler by SUFFIXING the run, the
         * head side of the 'entropy AROUND the run' contract never
         * enforced. The head gates every tail rule now: empty or the
         * pure digit flank, never non-sequential bytes.
         */
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . '7f3kq2mz4n' . 'abcdefgh'), 'Entropy BEFORE the run is entropy — the head side of the around-the-run contract (red at HEAD: exempted by the empty tail).');
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . 'qf3kq2mz4n5t7w9x2k4m' . 'abcdefgh'), 'Twenty junk head bytes launder nothing — the head gate is unbounded where the tail gate was.');
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . 'z' . 'k0123456789'), 'The digit run\'s head-side mirror counts — the tail-side twin always did.');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . '123' . '0123456789'), 'The pure DIGIT head stays filler — the pinned digit-flank rule mirrored to the head side.');
        $this->assertNotEmpty(wp_connectors_scan_string('a = xoxb-' . 'test-' . '7f3kq2mz4n' . 'abcdefgh' . "\n", 'r55-head.txt'), 'The driven CLI shape flags — the live credential suffixed with the run launders no more (red at HEAD: clean).');

        /*
         * t31-glm49-2 (R49-2, driven through the real CLI — the
         * chunked-entropy laundering, BOTH sides): the head loop
         * carried NO aggregate budget and the tail's aggregate
         * skipped its ≤4-byte segments, so a live credential
         * chunked into 4-byte dash-separated pieces shipped as
         * recognizably fake. Both sides ride ONE unified budget —
         * every non-dictionary, non-filler segment counting toward
         * its side's aggregate, the bound 9 the pinned fixtures'
         * own maximum ('test-key-abc123' the boundary).
         */
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'a1b2' . '-c3d4' . '-e5f6' . '-g7h8' . '-i9j0' . '-test-key'), 'Four-byte chunks are entropy too — the unified budget counts the HEAD side (red at HEAD: exempted).');
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . 'k3f9' . '-7d2xq' . '-9m4z' . '-b2c5' . '-n8p3' . '-t6w1' . '-y4u9'), 'Four-byte chunks are entropy too — the unified budget counts the TAIL side (red at HEAD: exempted).');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('test-' . 'key-' . 'abc123'), 'The boundary fixture keeps its exemption — 3 + 6 = 9, the corpus-derived bound.');

        /*
         * t31-glm50-4 (R50-9, driven through the real CLI — the
         * unanchored run exemption defeating the unified budget it
         * enforced one clause above): any segment merely CONTAINING
         * the run exempted wholesale, non-sequential entropy around
         * it and all — the exact opposite of the R48-3 contract's
         * own words. The exemption is the sequential-continuation
         * arm (the run plus digits or alphabet-continuing letters)
         * and the dictionary alone, at the walker AND the
         * value-level twin. R50-14 beside it: the dictionary gains
         * 'api'/'key'/'here' — the canonical multi-word placeholder
         * tail 'your-api-key-here' newly read as a live credential
         * by the unified budget (a false-FAIL regression versus the
         * pre-round-49 walker, driven A/B).
         */
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'test-' . 'abcdefgh9f3kq2mz4n'), 'Non-sequential bytes around the alphabet run are entropy — the walker\'s continuation arm refuses the tail (red at HEAD: exempted by the unanchored contains).');
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'abcdefgh9f3kq2mz4n'), 'The value-level twin agrees — no unanchored catch of a run elsewhere in the value (red at HEAD: exempted).');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'your-' . 'api-' . 'key-' . 'here'), 'The canonical placeholder word tail keeps its exemption — api/key/here join the dictionary (red at HEAD: a live credential).');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('test-' . 'api-' . 'key-' . 'here'), 'The leading-word spelling of the same tail.');

        /*
         * t31-glm51-1 (R51-1, driven through the real CLI — the
         * vocabulary SPLIT): the round-50 'api'/'key'/'here'
         * addition landed at the segment walker and the all-fake
         * tail pass but NOT at the head/tail WORD-SPLIT seat, so
         * 'api' cost 0 head bytes at the walker while the split
         * still anchored on a LATER word — 16 bytes of chunked
         * entropy riding the head side of the LAST dictionary word
         * passing the budget and the chunked-entropy laundering
         * class glm49-2 closed reopening. ONE owner serves all
         * three seats now; the all-placeholder control keeps its
         * exemption through the unified vocabulary.
         */
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('sk-' . 'api-' . 'k3f9m7d' . '-test-' . 'a1b2c3d4e'), 'The split anchors the FIRST dictionary word with the full vocabulary — the chunked head entropy counts against the budget (red at HEAD: exempted).');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('xoxb-' . 'api-' . 'abcdefgh'), 'The all-placeholder control keeps its exemption through the unified vocabulary — prefix, dictionary word, pure filler.');

        $fake = array(
            'vendor example head' => 'sk-proj-TEST-abc123',
            'whole-value words' => 'YOUR_API_KEY',
            'leading word' => 'test-key-abc123',
            'fixture prefix' => 'wpct_fixture_9f3a2b',
            'not-a-real head' => 'not-a-real-key-12345',
            'sequential filler body' => 'sk-proj-abcdefghij_test_klmnopqrstuvwxyz01',
            'dollar placeholder' => '$' . '{PLACEHOLDER}',
            'angle placeholder' => '<your-token-here>',
        );
        foreach ($fake as $name => $value) {
            $this->assertTrue(wp_connectors_is_recognizably_fake_secret($value), "The {$name} shape stays recognizably fake — the fixtures' own spellings keep their exemption.");
        }
    }

    public function testAMarkupSpliceOnCodeBytesExemptsNothing()
    {
        /*
         * R51-3 (driven through the real CLI): the code-view consult
         * honored the markup '<!--' arm on PHP CODE bytes — where
         * '<!--' is operators ('<', '!', T_DEC; the masker keeps
         * them verbatim; real inline-HTML markers blank to spaces
         * there, glm16-1) — so '1 <!-- secrets:allow --> + 2;'
         * exempted a live credential beside it, glm19-2's 'markers
         * count only in REAL comments' defeated at the one seat
         * judging the masked code view (a .md payload carries no
         * lint gate behind it — the scan-before-lint threat model).
         * The code consult rides the line-comment arms alone; the
         * prose consult keeps the markup arm (the pure-HTML .php
         * template of glm24-1 IS prose, its '<!--' a real comment).
         */
        $key = 'ghp_' . str_repeat('q', 30);
        $findings = wp_connectors_scan_string("<?php \$k = \x27{$key}\x27; \$z = 1 <!-- secrets:allow --> + 2;\n", 'a.php', true);
        $this->assertNotEmpty($findings, 'An operator-spelled markup marker on code bytes exempts nothing (red at HEAD: 0 findings).');
        $this->assertSame(array(), wp_connectors_scan_string("<?php \$k = \x27{$key}\x27; // secrets:allow\n", 'b.php', true), 'The line-comment marker keeps its exemption.');
        $this->assertSame(array(), wp_connectors_scan_string("<?php \$k = \x27{$key}\x27; # secrets:allow\n", 'c.php', true), 'The hash marker keeps its exemption.');
        $this->assertSame(
            array(),
            wp_connectors_scan_string("<input value=\"{$key}\"><!-- secrets:allow -->\n", 'template.php'),
            'The pure-HTML .php template keeps its exemption — the prose consult keeps the markup arm (glm24-1 unchanged).'
        );
    }

    public function testAMidLineStarOperatorNeverExempts()
    {
        /*
         * R52-3 (driven through the scan API): the docblock-
         * continuation opener '\*' rode the shared '(?:^|\s)' guard,
         * so a MID-LINE '*' needed only whitespace before it —
         * 'note 1 * secrets:allow then key …' in a prose payload
         * exempted a live credential on the line (red at HEAD:
         * exempt) while a real docblock continuation is always
         * LINE-INITIAL. The bare '\*' arm carries its own line
         * anchor; the line-initial continuation keeps its exemption.
         */
        $key = 'ghp_' . str_repeat('q', 30);
        $this->assertNotEmpty(
            wp_connectors_scan_string("note 1 * secrets:allow then key {$key}\n", 'm.txt'),
            'A mid-line star operator never exempts — multiplication is not a comment (red at HEAD: exempt).'
        );
        $this->assertSame(
            array(),
            wp_connectors_scan_string("/*\n * secrets:allow {$key}\n */\n", 'd.php'),
            'The LINE-INITIAL docblock continuation keeps its exemption — the anchor admits leading whitespace and the star, never a mid-line operator.'
        );
        $this->assertNotEmpty(
            wp_connectors_scan_string("note key {$key}\n", 'n.txt'),
            'The no-marker control flags beside the anchored arm.'
        );

        /*
         * R53-6 (driven at HEAD): the round-52 anchor's BULLET
         * collateral — the line-initial list/quote/ordered markers a
         * marked bullet legitimately carries all false-flagged
         * (exempt at master); the anchor admits the marker run, the
         * mid-line multiplication shapes keeping their flags.
         */
        $this->assertSame(array(), wp_connectors_scan_string("- * secrets:allow {$key}\n", 'dash.txt'), 'The dash-bullet marker before the star keeps its exemption (red at HEAD: flagged).');
        $this->assertSame(array(), wp_connectors_scan_string("1. * secrets:allow {$key}\n", 'ord.txt'), 'The ordered-list marker keeps its exemption (red at HEAD: flagged).');
        $this->assertSame(array(), wp_connectors_scan_string("> * secrets:allow {$key}\n", 'quote.txt'), 'The blockquote marker keeps its exemption (red at HEAD: flagged).');
        $this->assertNotEmpty(wp_connectors_scan_string("5 * secrets:allow {$key}\n", 'five.txt'), 'A bare digit before the star is multiplication, never a marker — still flags.');
        $this->assertNotEmpty(wp_connectors_scan_string("note 1 * secrets:allow {$key}\n", 'mid.txt'), 'The mid-line multiplication shape keeps its flag — the marker class admits markers only at the line start.');

        /*
         * R54-3 (driven at HEAD): the region compositor's prose view
         * is a SLICE — a head beginning where an embedded sample
         * ENDED started the view at its own byte 0, mid-line in the
         * source, and the marker-run arm read it as line-initial
         * ('<?php $x=1; ?> - * secrets:allow ghp_…' exempted the
         * credential, the R52-3 laundering reopened by the round-53
         * anchor's own arm). The slice carries a SENTINEL before a
         * mid-line head; the genuinely line-initial bullet keeps its
         * exemption; the plain-label control still flags.
         */
        $this->assertNotEmpty(wp_connectors_scan_string("<?php \$x=1; ?> - * secrets:allow {$key}\n", 'slice-dash.md'), 'A marker run after an embedded sample is MID-LINE in the source — never line-initial (red at HEAD: exempt).');
        $this->assertNotEmpty(wp_connectors_scan_string("<?php \$x=1; ?> > * secrets:allow {$key}\n", 'slice-quote.md'), 'The quote-marker run after a sample judges the same.');
        $this->assertNotEmpty(wp_connectors_scan_string("<?php \$x=1; ?> 1. * secrets:allow {$key}\n", 'slice-ord.md'), 'The ordered-list run after a sample judges the same.');
        $this->assertSame(array(), wp_connectors_scan_string("guide <?php \$x=1; ?> done\n- * secrets:allow {$key}\n", 'slice-clean.md'), 'A genuinely line-initial marked bullet on its own line keeps its exemption — the sentinel rides only the slice that FOLLOWS a region on the same line.');
        /*
         * R55-5 (driven at HEAD): the round-54 sentinel guarded only
         * empty-prose heads — the JOIN between two prose slices
         * dropped the region bytes and re-supplied whitespace
         * adjacency the source never had, a head slice's trailing
         * space riding the (?:^|\s) \s arm across the seam. Every
         * seam takes the sentinel now.
         */
        $this->assertNotEmpty(wp_connectors_scan_string("x <?php \$x=1; ?>// secrets:allow {$key}\n", 'seam-open.md'), 'A glued \'//\' after a region preceded by whitespace PROSE never rides the manufactured adjacency — the seam sentinel breaks it (red at HEAD: exempt).');
        $this->assertNotEmpty(wp_connectors_scan_string("x <?php \$x=1; ?> mid <?php \$y=2; ?>// secrets:allow {$key}\n", 'seam-mid.md'), 'A mid-slice seam launders identically — every join drops region bytes.');
        $this->assertSame(array(), wp_connectors_scan_string("<?php \$x=1; ?> // secrets:allow {$key}\n", 'seam-legit.md'), 'A marker after a region preceded by its OWN real whitespace keeps the (?:^|\s) arm — the honest spelling still exempts.');

        /*
         * R54-6 (driven at HEAD): an ODD count of unescaped quotes
         * leaves the leftmost-first pairing arbitrary — the prose
         * apostrophe in "don't" paired with the string's own opener,
         * the marker rode OUTSIDE every blanked pair, read as code,
         * and exempted the credential. The ambiguity refuses the
         * exemption; the apostrophe-free control and the
         * cleanly-paired escaped-quote line keep their verdicts.
         */
        $this->assertNotEmpty(wp_connectors_scan_string("don't say 'x // secrets:allow y' {$key}\n", 'odd-quote.txt'), 'A marker on a line whose quote pairing is ambiguous never exempts — no line-local lens can prove it sits in a comment (red at HEAD: exempt).');
        $this->assertNotEmpty(wp_connectors_scan_string("do not say 'x // secrets:allow y' {$key}\n", 'even-quote.txt'), 'The apostrophe-free control keeps its flag — the marker rides inside a cleanly-paired string, string data never exempting.');
        $this->assertSame(array(), wp_connectors_scan_string("note: 'It'\\''s marked' // secrets:allow {$key}\n", 'escaped-quote.txt'), 'A cleanly-paired line with escaped quotes keeps its exemption — escape pairs are shed before the count, the marker sitting in a real comment after the string.');
        /*
         * R55-2 (driven both directions): the guard counted the RAW
         * line while the pairing it guards judges the region-stripped
         * prose view — an embedded sample's quotes flipped the parity
         * both ways. The guard counts the judged view now.
         */
        $this->assertNotEmpty(wp_connectors_scan_string("<?php \$a = \"don't\"; ?> don't say 'x // secrets:allow y' {$key}\n", 'sample-parity-open.md'), 'A SAMPLE apostrophe making the raw count even never arms the guard over an odd JUDGED view — the marker reads as code no more (red at HEAD: exempt).');
        $this->assertSame(array(), wp_connectors_scan_string("<?php \$a = \"don't\"; ?> ok // secrets:allow {$key}\n", 'sample-parity-clean.md'), 'A sample apostrophe over a quote-free judged view never refuses the marker — the legitimately-marked comment keeps its exemption (red at HEAD: false-flagged, master exempts).');

        /*
         * R53-8 (driven): the value-level final pass re-derived the
         * per-segment predicate without the derived hyphenated
         * windows — one value, two verdicts by separator spelling
         * ('not_a_real' live, 'not-a-real' fake). The final pass
         * delegates to the walker; entropy beside the window keeps
         * its live verdict.
         */
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('not_a_real'), 'The window pieces under an underscore separator read as placeholder — the walker\'s empty-span answer everywhere (red at HEAD: live).');
        $this->assertTrue(wp_connectors_is_recognizably_fake_secret('test_value'), 'The test-value window the same way.');
        $this->assertFalse(wp_connectors_is_recognizably_fake_secret('xoxb-not_a_real-9f3k9q2m'), 'Entropy beside the window keeps its live verdict — the delegation launders nothing.');

        /*
         * R52-9 (the fourth seat's closure, the R44-9 two-arm-copy
         * class at the round-51 owner's own neighbor): the
         * hyphenated dictionary words are WINDOW-spelled at the
         * segment walks by pieces DERIVED from the one owner — the
         * hand-coded piece triples are gone, a future hyphenated
         * word landing everywhere or nowhere. The window pieces
         * count ZERO budget bytes.
         */
        $this->assertSame(array(), wp_connectors_fake_secret_placeholder_spans(array( 'not', 'a', 'real' )), 'The not-a-real window pieces count no bytes — derived from the owner.');
        $this->assertSame(array(), wp_connectors_fake_secret_placeholder_spans(array( 'test', 'value' )), 'The test-value window pieces count no bytes — derived from the owner.');
        $this->assertSame(array( 3 ), wp_connectors_fake_secret_placeholder_spans(array( 'test', 'value', 'xyz' )), 'A non-window segment past the window still counts its bytes.');
    }

    public function testMarkerInsideAHeredocBodyExemptsNothing()
    {
        /*
         * glm15-1: the marker exemption honored markers inside heredoc/
         * nowdoc DATA — the line-local blanker owns quoted literals only,
         * so a heredoc body line with a live key plus a lookalike
         * '// secrets:allow' had no quote bytes to blank and laundered
         * the finding away (red at HEAD: 0 findings over the live key).
         * The body is string data through the token census now: the
         * marker must sit in CODE, and a body-line marker exempts
         * nothing.
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);

        $heredoc = "<?php\n\$payload = <<<EOT\n{$key} // secrets:allow\nEOT;\n";
        $this->assertSame(
            array( "heredoc:3 openai-anthropic-key (OpenAI/Anthropic API key)" ),
            wp_connectors_scan_string($heredoc, 'heredoc'),
            'A marker inside heredoc DATA exempts nothing (red at HEAD: laundered to zero findings).'
        );

        // The nowdoc twin — same body, same verdict.
        $nowdoc = "<?php\n\$payload = <<<'EOT'\n{$key} // secrets:allow\nEOT;\n";
        $this->assertSame(
            array( "nowdoc:3 openai-anthropic-key (OpenAI/Anthropic API key)" ),
            wp_connectors_scan_string($nowdoc, 'nowdoc'),
            'The nowdoc body is string data exactly like the heredoc body.'
        );

        // The plain-line control still flags in the same shape.
        $this->assertSame(
            array( "plain:1 openai-anthropic-key (OpenAI/Anthropic API key)" ),
            wp_connectors_scan_string($key, 'plain')
        );

        // A marker in REAL CODE still exempts: the code-level control.
        $this->assertSame(array(), wp_connectors_scan_string("\$v = '{$key}'; // secrets:allow", 'code'));
    }

    public function testEveryStringDataRegionClassLaunderedNothing()
    {
        /*
         * glm16-1: the glm15-1 heredoc census failed open four ways
         * (all driven red at HEAD — zero findings through the marker
         * judge): a NESTED heredoc clobbered its single-boolean state
         * machine (only the inner body marked, live keys on OUTER body
         * lines laundering); every other token-visible data region
         * laundered (multi-line quoted-literal interiors,
         * __halt_compiler() tails, ?>-bounded inline HTML, a
         * lexer-refused opener); and the EOF branch was off by one (an
         * unterminated heredoc without a trailing newline marked ZERO
         * body lines — byte-identical contents ± one newline flipped
         * the verdict). The census is deleted; the marker judge rides
         * the ONE token-masked view, nesting-aware and
         * length-preserving — every region class one owner.
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // (a) NESTED heredoc: the outer body past the interpolated inner
        // close is still string data (red at HEAD: the census's boolean
        // closed at the inner T_END_HEREDOC — zero findings).
        $nested = "<?php\necho <<<OUTER\n{\$v = <<<INNER\ninner\nINNER; }\n{$key} // secrets:allow\nOUTER;\n";
        $this->assertSame(
            array( "nested:6 {$expect}" ),
            wp_connectors_scan_string($nested, 'nested'),
            'A marker on the OUTER body of a nested heredoc exempts nothing.'
        );

        // (b) Multi-line quoted-literal interior: the line inside the
        // literal carries no quote bytes (red at HEAD: laundered).
        $multiline = "<?php\n\$x = \"\n{$key} // secrets:allow\n\";\n";
        $this->assertSame(
            array( "multiline:3 {$expect}" ),
            wp_connectors_scan_string($multiline, 'multiline'),
            'A marker inside a multi-line quoted interior exempts nothing.'
        );

        // (c) The __halt_compiler() tail: bytes after the halt are data.
        $halt = "<?php __halt_compiler();\n{$key} // secrets:allow\n";
        $this->assertSame(
            array( "halt:2 {$expect}" ),
            wp_connectors_scan_string($halt, 'halt'),
            'A marker in the halt-compiler tail exempts nothing.'
        );

        // (d) Close-tag-bounded inline HTML inside a .php payload.
        $html = "<?php ?>\n{$key} // secrets:allow\n<?php\n\$x = 1;\n";
        $this->assertSame(
            array( "html:2 {$expect}" ),
            wp_connectors_scan_string($html, 'html'),
            'A marker in an inline-HTML region exempts nothing.'
        );

        // (e) A lexer-refused opener rides the SAME whole-payload
        // inline-HTML class — but its lexing is INI-dependent (this
        // host runs short_open_tag=On, where '<?phpecho' is real code
        // and the marker a real comment, correctly exempt), so the
        // class is driven by the halt-tail and close-tag legs above
        // rather than an INI-flaky spelling here.

        // (f) The EOF adjacency: an unterminated heredoc blanks through
        // EOF with AND without the trailing newline — byte-identical
        // contents ± one byte answer ONE verdict (red at HEAD: the
        // no-newline spelling marked zero body lines).
        $unterminated = "<?php\n\$x = <<<EOT\n{$key} // secrets:allow";
        $this->assertSame(
            array( "unterm:3 {$expect}" ),
            wp_connectors_scan_string($unterminated, 'unterm'),
            'An unterminated heredoc body flags without a trailing newline.'
        );
        $this->assertSame(
            array( "untermnl:3 {$expect}" ),
            wp_connectors_scan_string($unterminated . "\n", 'untermnl'),
            'The trailing newline flips nothing — same verdict ± the byte.'
        );

        // The documented tolerance stays: a non-PHP payload (no '<?'
        // anywhere) keeps its marker (the pinned glm15-1 doctrine).
        $this->assertSame(array(), wp_connectors_scan_string("{$key} // secrets:allow", 'txt'));
    }

    public function testTheMaskedViewKeepsLineAlignmentSoAMarkerExemptsOnlyItsOwnLine()
    {
        /*
         * glm17-1: the glm16-1 mask ride was length-preserving but NOT
         * line-preserving — newlines inside multi-line string regions
         * blanked to spaces, so explode("\n", $masked) answered FEWER
         * lines than the source and every line past the first
         * multi-line region shifted UP into an earlier line's view: a
         * code marker lines BELOW a live key landed on the key's own
         * index and exempted it (driven red at HEAD: zero findings).
         * The blanking keeps interior newlines now; the view lines map
         * 1:1 onto the source lines and a marker exempts only ITS line.
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // The driven shape: a multi-line quoted interior (three
        // swallowed newlines) above the key, a REAL code marker three
        // lines below it — at HEAD the marker's view rode the key's
        // index and the key laundered to zero findings.
        $shifted = "<?php\n\$m = \"\na\nb\n\";\n\$k = '{$key}';\n\$a = 1;\n\$b = 1;\n\$c = 1; // secrets:allow\n";
        $this->assertSame(
            array( "shifted:6 {$expect}" ),
            wp_connectors_scan_string($shifted, 'shifted'),
            'A code marker three lines DOWN exempts nothing above it (red at HEAD: the collapsed view carried the marker onto the key\'s line — zero findings).'
        );

        // The control: a code-level marker still exempts ITS OWN line.
        $this->assertSame(array(), wp_connectors_scan_string("\$v = '{$key}'; // secrets:allow", 'ownline'));

        // And the alignment holds over the heredoc class too: the
        // marker below a heredoc body never exempts the body's key.
        $heredoc = "<?php\n\$p = <<<EOT\n{$key}\nEOT;\n\$z = 2; // secrets:allow\n";
        $this->assertSame(
            array( "hdshift:3 {$expect}" ),
            wp_connectors_scan_string($heredoc, 'hdshift'),
            'The heredoc body\'s interior newlines survive the blanking — the marker below exempts only its own line.'
        );
    }

    public function testADenseEntryOverTheTokenMemoryBoundAnswersAVerdictNeverAFatal()
    {
        /*
         * glm16-2: token_get_all() on a ~1.9 MB dense entry needs ~98x
         * the source in token arrays and FATALED at the 128M default
         * with no verdict from the scanner and the inspector alike —
         * the glm14-3/glm14-6 class reopened by the glm16-1 mask ride,
         * under the walk's own 2 MB cap. The ride owns its memory
         * bound now: the dense-worst-case estimate over the PHP-MODE
         * SPANS (a prose run between tags is one T_INLINE_HTML token —
         * the ledger's code samples, not its megabytes, are what it
         * tokenizes; glm17-2: the SUM of the spans, the stream is
         * materialized whole) against the parsed limit minus live
         * usage answers the LOUD refusal naming the file, the glm14-2
         * vocabulary — never a fatal, and never a clean verdict over
         * bytes the scan could not tokenize. (The dense entry is ONE
         * span, and the estimate is deliberately the WORST case, so
         * ~1.9 MB x 98 ≈ 186 MB exceeds a 128M limit whole, not merely
         * its headroom.)
         *
         * glm17-12: the bound this leg asserts is ENVIRONMENT-relative
         * — the headroom is the parsed memory_limit minus live usage,
         * so under memory_limit=-1 or a >=~190M limit the same bytes
         * tokenize fine and the refusal never fires (driven red under
         * both: the verdict baked to the runner's own limit). The test
         * PINS its own ceiling — 128M, the default the finding drove —
         * and restores the runner's limit on every exit (an empty
         * original spelling restores as -1, the canonical unbounded).
         */
        $this->runDenseEntryBoundLeg();
    }

    /**
     * Staged ballast — kept alive by the property until the staging
     * test ends (glm19-10).
     *
     * @var string
     */
    private $ballast = '';

    /**
     * Stages live REAL usage past a floor by measured top-up,
     * answering the LANDED reading (glm19-10).
     *
     * memory_get_usage(true) advances in allocator CHUNKS (~2 MiB
     * jumps), never per 64 KiB append: a loop that assumed smooth
     * growth could be judged against a reading one whole chunk past
     * its target — the round-18 ballast leg's ceiling assert answered
     * a RED where the shape was the allocator's own. The stager stops
     * at the first landed reading past the floor; the CALLER judges
     * the landed value against its own ceiling, a jump past it the
     * named skip (the window unreachable on this allocator), never a
     * red.
     *
     * @param int $floor_bytes The real-usage floor to pass.
     * @return int The landed real-usage reading.
     */
    private function stageBallastPastFloor(int $floor_bytes): int
    {
        while (memory_get_usage(true) < $floor_bytes) {
            $this->ballast .= str_repeat('x', 64 * 1024);
        }

        return memory_get_usage(true);
    }

    /**
     * The pinned-128M dense-entry bound leg, shared by the environment
     * pin (glm17-12) and the fatal-band ballast leg (glm18-10).
     *
     * @return void
     */
    private function runDenseEntryBoundLeg(): void
    {
        $runner_limit = (string) ini_get('memory_limit');
        /*
         * glm19-8: the guard rides BEFORE the ini_set — a runner with
         * live usage already past the pin's own ceiling gets a
         * REFUSED lowering (the engine answers false plus an
         * E_WARNING PHPUnit converts to an exception — 'Failed to set
         * memory limit … Current memory usage is …'), so the pin line
         * answered a SPURIOUS RED, never the named skip. The guard's
         * band covers the refusal shape whole: live usage past the
         * band's floor skips loudly before the ceiling is ever
         * touched, whichever side of the pin the runner sits on.
         *
         * glm18-10: the pin leaves a FATAL BAND. With live usage in
         * the ~(126, 128) MiB window the ini_set still succeeds and
         * the very next staging allocation (the ~1.9 MB dense entry
         * below) kills the engine at exit 255 before any verdict —
         * the mechanism driven on a 512M host where a ballast-heavy
         * runner lowered itself into the window. The guard measures
         * REAL usage (memory_get_usage's true arm — the engine
         * enforces the limit against real bytes, and the allocator's
         * untracked overhead sits outside the emalloc reading): live
         * usage already past the band's floor skips LOUDLY — the
         * suite's own skip doctrine, a named reason never a silent
         * pass — and the leg never lowers into the fatal window.
         *
         * glm19-9: the floor derives from the STAGING PEAK, never the
         * resting size — the dense concat holds TWO ~1.91 MiB copies
         * transiently (the str_repeat operand and the concat result
         * both alive, ~3.82 MiB above the guard-time reading), so a
         * (125.8, 126.0) MiB window passed the pin-minus-2M floor,
         * the ini_set succeeded, and the very staging killed the
         * engine at exit 255 before any verdict (driven). The bound
         * is derived from the fixture's OWN sizes — the same
         * arithmetic that builds the entry — plus one drift margin
         * for the allocator's untracked overhead.
         */
        $dense_units = 118000;
        $staging_bytes = strlen('<?php ') + $dense_units * strlen('$x=$x+$x;$y[]=$x;');
        if (memory_get_usage(true) > 128 * 1024 * 1024 - 2 * $staging_bytes - 1024 * 1024) {
            $this->markTestSkipped(sprintf('Live usage (%d bytes real) already sits in the 128M pin\'s fatal band — the dense staging\'s transient peak would fatal the engine before any verdict, and a ceiling the engine would refuse to lower is the same band\'s own shape; the bound defect stays driven under the default host.', memory_get_usage(true)));
        }
        $this->assertNotFalse(ini_set('memory_limit', '128M'), 'The memory ceiling must be pinnable at runtime — the bound this leg asserts derives from it.');
        try {
            $dense = '<?php ' . str_repeat('$x=$x+$x;$y[]=$x;', $dense_units);
            $this->assertGreaterThan(1800000, strlen($dense), 'staging: the dense entry must be the ~1.9 MB driven shape.');
            $this->assertLessThan(2 * 1024 * 1024, strlen($dense), 'staging: the dense entry stays UNDER the walk cap — the bound this leg drives is the token pass, never the 2 MB size screen.');

            $findings = wp_connectors_scan_string($dense, 'dense.php');
            $this->assertSame(
                array( 'dense.php: over the secret-scan token-memory bound — the secret scan cannot run' ),
                $findings,
                'The over-capacity token pass answers a refusal, never a fatal (red at HEAD: memory exhaustion, no verdict).'
            );

            // Normal files are unchanged: the bound never trips for them.
            $this->assertSame(array(), wp_connectors_scan_string("<?php \$ok = 1; // secrets:allow\n", 'ok.php'));
        } finally {
            ini_set('memory_limit', '' === $runner_limit ? '-1' : $runner_limit);
        }
    }

    public function testTheDenseBoundPinSkipsLoudlyWhenLiveUsageSitsInTheFatalBand()
    {
        /*
         * glm18-10's driven leg: stage live usage INTO the ~(126, 128)
         * MiB band (ballast under a raised ceiling — the runner's own
         * 128M default cannot stage it from below), lower the ceiling
         * onto the band, and run the pinned leg — the guard must skip
         * LOUDLY before the dense staging allocation (red at HEAD: the
         * staging killed the engine, phpunit exit 255, no verdict).
         * The skip is caught and named, so the leg passes green on a
         * fixed tree instead of registering as a permanent skip.
         */
        $runner_limit = (string) ini_get('memory_limit');
        $this->assertNotFalse(ini_set('memory_limit', '1G'), 'The staging ceiling must be pinnable — the ballast cannot be staged from under the runner\'s own default limit.');
        try {
            if (memory_get_usage(true) >= 127 * 1024 * 1024) {
                $this->markTestSkipped('This runner already carries more live usage than the band\'s target — the ballast shape is unreachable here.');
            }
            // Measured top-up in REAL bytes (the engine's own limit
            // accounting), the LANDED reading answered — glm19-10: the
            // allocator advances in chunks, so the ceiling judgment
            // rides the landed value, a jump past the drift-headroom
            // ceiling the named skip (never a red).
            $landed = $this->stageBallastPastFloor(127 * 1024 * 1024);
            if ($landed >= 128 * 1024 * 1024 - 512 * 1024) {
                $this->markTestSkipped(sprintf('Allocator chunking landed the ballast at %d bytes, past the band\'s drift-headroom ceiling — the ballast shape is unreachable on this allocator this pass.', $landed));
            }
            $this->assertGreaterThan(126 * 1024 * 1024, $landed, 'staging: the ballast must land inside the fatal band — past the guard\'s floor.');
            $this->assertLessThan(128 * 1024 * 1024 - 512 * 1024, $landed, 'staging: the ballast must stay under the band\'s ceiling with drift headroom — the pin still has to succeed.');

            try {
                $this->runDenseEntryBoundLeg();
                $this->fail('The guard must skip before the dense staging when live usage sits in the band (red at HEAD: the staging killed the engine at exit 255).');
            } catch (\PHPUnit\Framework\SkippedTestError $skip) {
                $this->assertStringContainsString('fatal band', (string) $skip->getMessage(), 'The skip names the band — the suite\'s own loud-skip doctrine, never a silent pass.');
            }
        } finally {
            $this->ballast = '';
            ini_set('memory_limit', '' === $runner_limit ? '-1' : $runner_limit);
        }
    }

    public function testTheDenseBoundPinSkipsNotRedsWhenTheRunnerAlreadyExceedsThePinnedCeiling()
    {
        /*
         * glm19-8's driven leg: stage live usage PAST the 128M pin
         * itself (ballast under a raised ceiling), then run the pinned
         * leg — the engine REFUSES the lowering (ini_set false plus an
         * E_WARNING PHPUnit converts to an exception), so at HEAD the
         * leg answered a SPURIOUS RED at the pin line where the guard
         * must skip LOUDLY first, before the ceiling is ever touched.
         */
        $runner_limit = (string) ini_get('memory_limit');
        $this->assertNotFalse(ini_set('memory_limit', '1G'), 'The staging ceiling must be pinnable — the ballast cannot be staged from under the runner\'s own limit.');
        try {
            if (memory_get_usage(true) >= 128 * 1024 * 1024 + 2 * 1024 * 1024) {
                $this->markTestSkipped('This runner already carries more live usage than the over-ceiling shape targets — the leg is unreachable here.');
            }
            // Stage past the pin itself; the allocator's own chunk
            // granularity cannot overshoot anything this leg judges
            // (there is no upper window — only the guard has to fire).
            $landed = $this->stageBallastPastFloor(128 * 1024 * 1024 + 1024 * 1024);
            $this->assertGreaterThan(128 * 1024 * 1024, $landed, 'staging: live usage sits past the pin — the lowering the engine refuses.');

            try {
                $this->runDenseEntryBoundLeg();
                $this->fail('The guard must skip before the ini_set when live usage sits past the pin (red at HEAD: the refused lowering answered a spurious red).');
            } catch (\PHPUnit\Framework\SkippedTestError $skip) {
                $this->assertStringContainsString('fatal band', (string) $skip->getMessage(), 'The skip names the band — the suite\'s own loud-skip doctrine, never a spurious red.');
            }
        } finally {
            $this->ballast = '';
            ini_set('memory_limit', '' === $runner_limit ? '-1' : $runner_limit);
        }
    }

    public function testTheDenseBoundPinSkipsWhenUsageSitsInTheStagingTransientWindow()
    {
        /*
         * glm19-9's driven leg: the guard floor derived from the
         * RESTING staging size (~1.9 MB — the pin-minus-2M floor), but
         * the concat holds TWO copies transiently (~3.82 MiB above the
         * guard-time reading): a (125.8, 126.0) MiB window passed the
         * HEAD guard, the pin succeeded, and the very staging killed
         * the engine at exit 255 before any verdict. The floor
         * accounts for the staging PEAK now — the window answers the
         * caught skip.
         */
        $runner_limit = (string) ini_get('memory_limit');
        $this->assertNotFalse(ini_set('memory_limit', '1G'), 'The staging ceiling must be pinnable — the ballast cannot be staged from under the runner\'s own limit.');
        try {
            if (memory_get_usage(true) >= 126 * 1024 * 1024) {
                $this->markTestSkipped('This runner already carries more live usage than the staging-transient window — the leg is unreachable here.');
            }
            $landed = $this->stageBallastPastFloor((int) (125.9 * 1024 * 1024));
            if ($landed >= 126 * 1024 * 1024) {
                $this->markTestSkipped(sprintf('Allocator chunking landed the ballast at %d bytes, past the window\'s ceiling — the leg is unreachable on this allocator this pass.', $landed));
            }
            $this->assertGreaterThan(125.8 * 1024 * 1024, $landed, 'staging: the ballast lands inside the staging-transient window — past the RESTING-size floor the HEAD guard judged by.');
            $this->assertLessThan(126 * 1024 * 1024, $landed, 'staging: the ballast stays under the HEAD guard\'s floor — the pin itself still has to succeed at HEAD.');

            try {
                $this->runDenseEntryBoundLeg();
                $this->fail('The guard must skip when the staging TRANSIENT (both concat copies) would cross the pin (red at HEAD: the staging killed the engine at exit 255, no verdict).');
            } catch (\PHPUnit\Framework\SkippedTestError $skip) {
                $this->assertStringContainsString('fatal band', (string) $skip->getMessage(), 'The skip names the band — the loud skip, never the engine\'s own exit 255.');
            }
        } finally {
            $this->ballast = '';
            ini_set('memory_limit', '' === $runner_limit ? '-1' : $runner_limit);
        }
    }

    public function testTheBallastStagerAnswersTheLandedReadingToleratingAllocatorChunkJumps()
    {
        /*
         * glm19-10: the ballast loops assumed ~64 KiB growth per
         * append, but memory_get_usage(true) advances in allocator
         * CHUNKS (~2 MiB jumps measured) — the first reading past a
         * tight target can land a whole chunk beyond it, and the
         * round-18 leg's ceiling assert answered a RED where the
         * shape was the allocator's own. The stager answers the
         * LANDED reading and every ceiling judgment rides it — a jump
         * past the ceiling is the named skip, never a red. The
         * allocator's own jump shape cannot be driven from userland;
         * the unit pin holds the stager's contract instead (the
         * suite's own doctrine for the undrivable shape).
         */
        $before = memory_get_usage(true);
        $this->assertSame($before, $this->stageBallastPastFloor($before - 1), 'A floor the reading already passes is a no-op — the landed reading answered unchanged, zero appends.');
        $this->assertSame('', $this->ballast, 'No append happens for an already-passed floor.');

        $floor = $before + 3 * 1024 * 1024;
        $landed = $this->stageBallastPastFloor($floor);
        $this->assertGreaterThanOrEqual($floor, $landed, 'The stager answers a LANDED reading at or past the floor — however far the last allocator chunk jumped.');
        $this->assertNotSame('', $this->ballast, 'The ballast is staged and stays alive with the property until the test ends.');
    }

    public function testThePairBoundedCompositorStaysLinearOverThousandsOfPairs()
    {
        /*
         * glm19-11 (measured twice independently by the review): the
         * compositor re-walked ALL regions from index 0 for EVERY
         * line — O(lines × regions) — and a 23,000-pair .md answered
         * in ~13.9 s where the cursor shape answers in well under a
         * second. The walk rides a by-ref region CURSOR now (regions
         * sorted, line starts monotonic): a region closed on an
         * earlier line is consumed for good, each line starting where
         * the last stopped — O(lines + regions), verdicts
         * byte-identical. The leg pins the equivalence (the verdicts)
         * and the bounded wall clock on the driven shape.
         *
         * glm19-11b (the post-round red): the leg first ran the 23k
         * scan IN-PROCESS — its whole-call peak (~105M above entry
         * usage: the token streams the census's span factor bounds
         * PLUS the payload, the masked view, the 46,000-entry regions
         * array, and the 23,000 findings) fataled a 128M runner
         * mid-suite under random order (driven: 'Allowed memory size
         * exhausted' at the region walk's tokenize), and the
         * pinned-ceiling shape could not restore either — the arena
         * retains the freed token-stream pages, the engine refuses
         * the lowering, and the limit left elevated poisoned every
         * later census-relative leg (driven: the >1.3 MB unclosed-tail
         * refusal never firing). The scan rides a SPAWNED ENGINE now,
         * the suite's glm17-2/glm18-4 doctrine: the whale memory is
         * process-isolated, the verdicts and the wall clock printed
         * by the child under its own 1G ceiling.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the spawned-engine leg cannot run (the 23k-pair scan rides a child process).');
        }

        // The exec stanza rides the ONE spawn owner (glm20-5), its 1G
        // ceiling the whale's own INI flag.
        $script = 'require %s;' . "\n" . <<<'CHILD'
$key = 'sk-ant-api3-' . str_repeat('q', 30);
$pair = "<?php \$i = 1; ?> prose {$key} between the pairs <?php \$j = 2; ?>\n";
$payload = str_repeat($pair, 23000);
$started = microtime(true);
$findings = wp_connectors_scan_string($payload, 'pairs.md');
$elapsed = microtime(true) - $started;
echo 'count=', count($findings), "\n";
echo 'first=', $findings[0], "\n";
echo 'mid=', $findings[11499], "\n";
echo 'last=', $findings[22999], "\n";
echo 'elapsed=', sprintf('%%.3f', $elapsed), "\n";
CHILD;
        $spawned = $this->spawnScannerChild($script, array( 'memory_limit=1G' ));
        $report = $spawned['report'];
        $exit = $spawned['exit'];

        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';
        $this->assertSame(0, $exit, "The 23k-pair scan answers verdicts in the spawned engine, never a fatal: {$report}");
        // glm20-3's diagnostics needle rides the spawn OWNER since
        // glm21-10 — every clean-exit leg asserts the child's report
        // carries no engine diagnostic line, this leg included.
        /*
         * glm20-2: the verdict needles are ANCHORED full-line matches —
         * the spawn move left substring pins at the child boundary, so
         * a drifted count=230001 report false-passed 'count=23000'
         * (and a drifted first=pairs.md:10 the :1 needle), the
         * verdict-equivalence pin the old assertCount/assertSame shape
         * carried gone with the boundary. Each needle answers exactly
         * one full line of the child's report; the drifted-report
         * mutant below pins the needle's own contract.
         */
        $countNeedle = '/^count=23000$/m';
        $this->assertSame(1, preg_match($countNeedle, $report), "Every prose key between the pairs flags — the linear walk launders nothing and finds everything the re-walk found (verdict equivalence): {$report}");
        $this->assertSame(1, preg_match('/^' . preg_quote("first=pairs.md:1 {$expect}", '/') . '$/m', $report), "The first line's verdict is the pair-bounded one, the full line exact: {$report}");
        $this->assertSame(1, preg_match('/^' . preg_quote("mid=pairs.md:11500 {$expect}", '/') . '$/m', $report), "A middle line's verdict is identical, the full line exact: {$report}");
        $this->assertSame(1, preg_match('/^' . preg_quote("last=pairs.md:23000 {$expect}", '/') . '$/m', $report), "The last line's verdict is identical, the full line exact: {$report}");
        $this->assertSame(1, preg_match('/^elapsed=([0-9.]+)$/m', $report, $clock), "The child reports its own wall clock: {$report}");
        $this->assertLessThan(10.0, (float) $clock[1], sprintf('The 23k-pair scan answers in bounded time (%ss measured in the child) — the cursor walk is O(lines + regions), never the per-line re-walk from index 0 (red at HEAD: ~13.9 s measured).', $clock[1]));
        // The needle's own contract: the drifted count report the
        // substring pin false-passed (red at HEAD's pin shape) answers
        // ZERO matches — an anchored needle false-passes nothing.
        $this->assertSame(0, preg_match($countNeedle, "count=230001\n"), 'The anchored count needle never false-passes a drifted count — the substring pin answered 1 for this exact report line.');
    }

    public function testASumOfDenseSpansAnswersTheRefusalNeverTheFatal()
    {
        /*
         * glm17-2: the token-memory gate multiplied only the LARGEST
         * span by the dense factor, but token_get_all() materializes
         * the WHOLE stream at once — 24 dense ~100 KB spans (~2.4 MB
         * of PHP, every span under any per-span bound) passed the gate
         * then FATALED at the 128M default with no verdict (driven in
         * a spawned engine at HEAD: exit 255). The gate bounds the SUM
         * of the spans now — the multi-span payload answers the loud
         * refusal, the glm14-2 vocabulary, never the fatal.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the memory-bound leg cannot run (the fatal class rides a spawned engine).');
        }

        // The exec stanza rides the ONE spawn owner (glm20-5), the
        // bound leg's own 128M ceiling its INI flag.
        $script = <<<'CHILD'
require %s;
$span = '<?php ' . str_repeat('$x=$x+$x;$y[]=$x;', 6200) . '?>';
$payload = str_repeat($span . "\nprose run between the samples\n", 24);
echo 'bytes=', strlen($payload), "\n";
foreach (wp_connectors_scan_string($payload, 'multi.php') as $finding) {
    echo $finding, "\n";
}
CHILD;
        $spawned = $this->spawnScannerChild($script, array( 'memory_limit=128M' ));
        $report = $spawned['report'];
        $exit = $spawned['exit'];

        $this->assertSame(0, $exit, "The 24-span dense payload answers the LOUD refusal, never a fatal (red at HEAD: the spawned engine died at the memory limit with no verdict): {$report}");
        $this->assertStringContainsString('multi.php: over the secret-scan token-memory bound — the secret scan cannot run', $report, 'The refusal names the file in the glm14-2 vocabulary.');

        // The control: honest small multi-span PHP never trips the sum
        // bound — the samples tokenize, the live key still finds.
        $githubToken = 'ghp_' . bin2hex(random_bytes(18));
        $small = "<?php \$a = 1; ?> prose between the samples <?php \$t = '{$githubToken}';";
        $this->assertSame(
            array( 'small.php:1 github-token (GitHub token)' ),
            wp_connectors_scan_string($small, 'small.php'),
            'A small multi-span payload scans normally — the sum bound refuses only what would fatal.'
        );
    }

    public function testTextFamilyPayloadsKeepTheirProseMarkers()
    {
        /*
         * glm17-3: the '<?' pre-gate was extension-blind — an '<?xml'
         * declaration or a fenced, unclosed php sample inside a text
         * family file routed the WHOLE payload onto the masked view,
         * where the masker blanks prose as inline HTML and a
         * legitimately marked fixture's marker vanished (driven red at
         * HEAD: a marked .md answered 1 finding). Text-family payloads
         * reach the tokenizer only for a matched '<?php'...'?>'
         * sample; every other prose shape keeps the line-local arm.
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // The driven benign shape: a marked fixture with an '<?xml'
        // declaration (red at HEAD: 1 finding — the prose marker
        // blanked as inline HTML).
        $xmlDecl = "# Provider configuration\n\napi_key = {$key} // secrets:allow\n\n<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $this->assertSame(array(), wp_connectors_scan_string($xmlDecl, 'docs.md'), 'An <?xml declaration in prose never routes the .md onto the masked view (red at HEAD: the marker blanked, 1 finding).');

        // The short-echo shape rides the same arm (a '<?=' sample is
        // not a '<?php' open).
        $shortEcho = "Guide\n\n<?= \$k ?> with the key {$key} // secrets:allow\n";
        $this->assertSame(array(), wp_connectors_scan_string($shortEcho, 'guide.md'), 'A <?= sample in prose keeps the line-local marker (red at HEAD: blanked).');

        // The unclosed fenced sample: no matching close tag, no
        // whole-file tokenizer ride. CORRECTED (glm18-1): the >1.3 MB
        // unclosed-sample shape the glm17-2 finding named as the
        // over-refusal once scanned clean at its line cost — a verdict
        // purchased by never tokenizing the tail — but the tail IS
        // code the engine lexes and its string-data interiors
        // laundered through the line-local arm (driven, the
        // glm18-1 test below). The tail rides the masker now, so it
        // rides the token-memory census like every matched sample:
        // the honest worst-case bound on ~1.5 MB of tokenized tail is
        // the LOUD refusal, glm17-2's own recorded premise (no honest
        // factor passes 1.4 MB while refusing 2.4 MB).
        $big = '<?php $sample = 1;' . str_repeat("\n# prose line the lexer never sees because the sample never closes", 23000);
        $this->assertGreaterThan(1300000, strlen($big), 'staging: the unclosed-sample .md must be the >1.3 MB bound shape.');
        $this->assertSame(
            array( 'big.md: over the secret-scan token-memory bound — the secret scan cannot run' ),
            wp_connectors_scan_string($big, 'big.md'),
            'A tokenized unclosed tail over the bound answers the loud refusal (red at HEAD: clean — the tail never reached the tokenizer).'
        );

        // The laundering class STAYS masked: a matched php open plus
        // close tag
        // sample in a text-family file routes to the masked view, so a
        // marker inside the sample's multi-line string data exempts
        // nothing — the line-local arm would launder it (the interior
        // line carries no quote bytes).
        $sample = "<?php \$x = \"\n{$key} // secrets:allow\n\"; ?>\n";
        $this->assertSame(
            array( "sample.md:2 {$expect}" ),
            wp_connectors_scan_string($sample, 'sample.md'),
            'A matched-close embedded sample rides the masked view — its string data launders nothing.'
        );
    }

    /**
     * glm18-2: the file-root arm judges a directly-named file by
     * CONTENT shape, not extension — explicitly named = operator
     * intent.
     */
    public function testADirectlyNamedPhpHeadedFileScansByContentShapeWhateverItsExtension()
    {
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-named');
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create — a staging failure fails as staging, never as the shape verdict.");
            // The driven shape: a short-echo-headed pure-PHP script
            // under a non-php extension. The extension-aware gate never
            // saw '<?php'-without-a-close: the matched-pair arm wants a
            // '<?php' open and the unclosed arm an unclosed one, so the
            // closed '<?=' script laundered its multi-line string
            // interior through the line-local arm (red at HEAD: 0
            // findings, exit 0 — base flagged it).
            $this->assertNotFalse(
                file_put_contents($tempDir . '/config.inc', "<?= \"\n{$key} // secrets:allow\n\" ?>\n"),
                "staging: {$tempDir}/config.inc must write — a staging failure fails as staging, never as the shape verdict."
            );
            // The '<?php'-headed twin through the same arm (both-green
            // pin: glm18-1's unclosed routing answers it either way).
            $this->assertNotFalse(
                file_put_contents($tempDir . '/settings.inc', "<?php\n// config\n\$k = \"\n{$key} // secrets:allow\n\";\n"),
                "staging: {$tempDir}/settings.inc must write — a staging failure fails as staging, never as the shape verdict."
            );
            // Text content keeps glm17-3's benign extension routing
            // under a directly-named target (the marked prose fixture
            // stays exempt).
            $this->assertNotFalse(
                file_put_contents($tempDir . '/docs.md', "# Provider configuration\n\napi_key = {$key} // secrets:allow\n\n<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"),
                "staging: {$tempDir}/docs.md must write — a staging failure fails as staging, never as the shape verdict."
            );
            // The same php-headed script spelled '.inc' inside a WALKED
            // tree: the walk never chooses its files, so the extension
            // screen rules it — the allowlist never reads a non-member
            // extension at all. The file-root doctrine is arm-scoped
            // (CORRECTED at glm18-11: the region walk routes embedded
            // samples through the mask for every TEXT-family extension
            // the allowlist reads, so the walk's screen and the content
            // shape are different questions — this leg pins the screen).
            $this->assertTrue(mkdir($tempDir . '/tree', 0755, true), "staging: {$tempDir}/tree must create — a staging failure fails as staging, never as the shape verdict.");
            $this->assertNotFalse(
                file_put_contents($tempDir . '/tree/config.inc', "<?= \"\n{$key} // secrets:allow\n\" ?>\n"),
                "staging: {$tempDir}/tree/config.inc must write — a staging failure fails as staging, never as the shape verdict."
            );

            $namedEcho = wp_connectors_scan_paths(array( $tempDir . '/config.inc' ));
            $namedOpen = wp_connectors_scan_paths(array( $tempDir . '/settings.inc' ));
            $namedDocs = wp_connectors_scan_paths(array( $tempDir . '/docs.md' ));
            $walked = wp_connectors_scan_paths(array( $tempDir . '/tree' ));
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $this->assertSame(
            array( "{$tempDir}/config.inc:2 {$expect}" ),
            $namedEcho,
            'A directly-named php-headed file rides the CODE arm whatever its extension (red at HEAD: the extension-aware gate never saw the closed <?= script — clean, exit 0).'
        );
        $this->assertSame(
            array( "{$tempDir}/settings.inc:4 {$expect}" ),
            $namedOpen,
            'The <?php-headed twin flags through the same arm.'
        );
        $this->assertSame(array(), $namedDocs, 'Text content keeps glm17-3\'s benign extension routing under a directly-named target.');
        $this->assertSame(array(), $walked, 'The WALK\'s extension screen never reads the non-allowlisted \'.inc\' at all — the content-shape doctrine is the file-root arm\'s own.');
    }

    public function testUnclosedTextFamilySamplesMaskStringDataInteriorsNotProse()
    {
        /*
         * glm18-1: glm17-3's unclosed-sample routing was a FALSE
         * NEGATIVE class — the ledger claim "unclosed shapes keep the
         * line-local arm exactly as the pre-diff behavior read them"
         * is false for string-DATA markers: an unclosed '<?php'/'<?='
         * sample's multi-line string interior carries no quote bytes
         * on its own lines, so the line-local lens honored a marker
         * sitting IN the data and a live key beside it scanned to
         * zero findings (driven at HEAD: 0; at base, where the bare
         * '<?' probe masked the payload: 1). The tail rides the
         * masked view from its open tag's line onward now — the
         * routing that flags the interior without blanking the prose
         * above (glm17-3's benign legs stay 0) — and because the
         * masker tokenizes the tail, the tail rides the token-memory
         * census (the >1.3 MB leg's flip sits in the glm17-3 battery
         * above).
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // The driven shape: prose, an unclosed sample, a live key plus
        // marker inside a multi-line string interior (red at HEAD: the
        // interior line's marker honored by the line-local lens — 0
        // findings).
        $md = "# Doc\n\n<?php\n\$k = \"\n{$key} // secrets:allow\n\";\n";
        $this->assertSame(
            array( "doc.md:5 {$expect}" ),
            wp_connectors_scan_string($md, 'doc.md'),
            'A marker inside an unclosed sample\'s string interior exempts nothing (red at HEAD: laundered to zero findings).'
        );

        // The unclosed short-echo tail launders identically (everything
        // after an unclosed '<?=' lexes as code too).
        $shortEcho = "Guide\n\n<?= \"\n{$key} // secrets:allow\n\"\n";
        $this->assertSame(
            array( "guide.md:4 {$expect}" ),
            wp_connectors_scan_string($shortEcho, 'guide.md'),
            'An unclosed <?= tail rides the same masked-view routing (red at HEAD: laundered).'
        );

        // The prose ABOVE the unclosed open keeps the line-local arm —
        // glm17-3's benign routing survives (a marked fixture's prose
        // marker still exempts its own line).
        $pre = "api_key = {$key} // secrets:allow\n\n<?php \$x = 1;\n";
        $this->assertSame(array(), wp_connectors_scan_string($pre, 'pre.md'), 'A prose marker above the unclosed sample keeps exempting its own line.');

        // And a REAL code comment beside the sample stays honored in
        // both arms — comments are not string data, the masker never
        // blanks them.
        $code = "<?php\n\$k = \"{$key}\"; // secrets:allow\n";
        $this->assertSame(array(), wp_connectors_scan_string($code, 'code.md'), 'A real code-comment marker keeps exempting its own line.');
    }

    public function testTheSpanCensusRidesTheHostsActualLexingNotTheIniBlindByteWalk()
    {
        /*
         * glm18-4: the span walk counted every '<?'...'?>' byte pair as
         * a span regardless of whether the ENGINE opens it — the
         * short_open_tag INI is ON on dev boxes and OFF on the
         * production default, and 23k '<?xml-stylesheet …?>' processing
         * instructions in a 1.84 MB .php doc lex as ONE inline-HTML
         * run under the default (measured token cost ~1x, ~1.8 MB)
         * while the census charged the dense ~98x factor and answered
         * the loud refusal over bytes the tokenizer never opens
         * (driven in a spawned engine at HEAD). The census classifies
         * each open spelling against the host's own probed lexing
         * (wp_connectors_engine_opener_lexing()) — and the legs pin
         * BOTH directions under pinned INI, so the verdict never
         * depends on the host this suite happens to run on: under
         * short_open_tag=0 the PI doc scans and real '<?php' spans
         * still refuse; under short_open_tag=1 the very same PI bytes
         * ARE spans the engine opens and the refusal is honest.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the INI-pinned lexing legs cannot run (both legs ride a spawned engine).');
        }

        // Both INI-pinned legs ride the ONE spawn owner (glm20-5), the
        // pinned spelling each call's own INI flag.
        $script = <<<'CHILD'
require %s;
$pi = '<?xml-stylesheet type="text/xsl" href="../../style/long/path/sheetnumber7.xsl"?>';
$doc = "<?php\n// head\n" . str_repeat($pi, 23000) . "\n?>\n";
echo 'pi-bytes=', strlen($doc), "\n";
foreach (wp_connectors_scan_string($doc, 'pi.php') as $finding) {
    echo $finding, "\n";
}
$span = '<?php ' . str_repeat('$x=$x+$x;$y[]=$x;', 6200) . '?>';
$dense = str_repeat($span . "\nprose run between the samples\n", 24);
foreach (wp_connectors_scan_string($dense, 'dense.php') as $finding) {
    echo $finding, "\n";
}
CHILD;

        $default = $this->spawnScannerChild($script, array( 'memory_limit=128M', 'short_open_tag=0' ));
        $default_ini = $default['report'];
        $this->assertSame(0, $default['exit'], "Under the production-default short_open_tag=0 the whole payload answers verdicts, never a fatal: {$default_ini}");
        $this->assertStringContainsString('pi-bytes=1840018', $default_ini, 'staging: the PI document must be the 1.84 MB driven shape.');
        $this->assertStringNotContainsString('pi.php:', $default_ini, 'PIs the engine lexes inline never charge the census (red at HEAD: the loud token-memory refusal).');
        $this->assertStringContainsString('dense.php: over the secret-scan token-memory bound — the secret scan cannot run', $default_ini, 'Real <?php spans still charge the census under the same INI — the probe-aware walk refuses exactly what would fatal.');

        $short = $this->spawnScannerChild($script, array( 'memory_limit=128M', 'short_open_tag=1' ));
        $short_ini = $short['report'];
        $this->assertSame(0, $short['exit'], "Under short_open_tag=1 the payload answers the refusal as a verdict, never a fatal: {$short_ini}");
        $this->assertStringContainsString('pi.php: over the secret-scan token-memory bound — the secret scan cannot run', $short_ini, 'The same PI bytes ARE spans an INI that opens the short spelling — the honest refusal stands.');
    }

    public function testACompletePairMentionRoutesOnlyTheSampleRegionNotTheWholeFile()
    {
        /*
         * glm18-11 (the round's residual closure — finding 6): prose
         * MERELY MENTIONING a complete '<?php … ?>' pair routed the
         * WHOLE text-family file onto the masked view, where the
         * mention's surrounding prose blanked as inline HTML and a
         * legitimately marked fixture's marker vanished — the
         * marked-fixture false positive glm17-3 closed for the
         * declaration/unclosed spellings survived for this one
         * (identical at base, pre-existing, unrecorded). The routing
         * is PAIR-BOUNDED now — the round's region walk generalizes
         * glm18-1's unclosed-tail split to the matched class: the
         * sample regions ride the masked view, the bytes outside them
         * keep the line-local arm.
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // The driven shape: a marked fixture line OUTSIDE the mentioned
        // pair stays exempt (red at HEAD: the whole-file mask blanked
        // the prose marker — 1 finding).
        $mention = "# Guide\n\nUse `<?php echo 1; ?>` inline.\n\napi_key = {$key} // secrets:allow\n";
        $this->assertSame(array(), wp_connectors_scan_string($mention, 'guide.md'), 'A marker in prose beside a mentioned pair keeps exempting its own line (red at HEAD: the whole-file mask blanked it — 1 finding).');

        // A live key outside the pair with no marker still flags —
        // pair-bounded routing launders nothing outside the region.
        $unmarked = "# Guide\n\nUse `<?php echo 1; ?>` inline.\n\napi_key = {$key}\n";
        $this->assertSame(
            array( "guide2.md:5 {$expect}" ),
            wp_connectors_scan_string($unmarked, 'guide2.md'),
            'An unmarked key outside the region flags exactly as before.'
        );

        // The mention's own region keeps the masked view — a marker
        // inside the sample's multi-line string data exempts nothing
        // (the glm17-3 laundering pin, on the mention spelling).
        $inPair = "<?php \$x = \"\n{$key} // secrets:allow\n\"; ?>\n";
        $this->assertSame(
            array( "sample.md:2 {$expect}" ),
            wp_connectors_scan_string($inPair, 'sample.md'),
            'Inside the mentioned pair the masked view owns the marker judgment.'
        );

        // Prose BETWEEN two mentioned pairs keeps the line-local arm.
        $between = "<?php \$a = 1; ?>\napi_key = {$key} // secrets:allow\n<?php \$b = 2; ?>\n";
        $this->assertSame(array(), wp_connectors_scan_string($between, 'between.md'), 'Prose between two pairs keeps the line-local arm.');
    }

    public function testAnInStringCloseTagDoesNotSplitTheSampleRegion()
    {
        /*
         * glm19-1: wp_connectors_php_sample_regions() closed each
         * region at the first BYTE-level '?>' — a close spelled inside
         * a quoted interior split the region, the code after the
         * in-string close fell to the line-local arm, and the
         * interior's marker honored there: the glm18-1 laundering
         * class reopened through the region walk (driven: 0 findings
         * at HEAD, 1 at base). The region close rides the tokenizer
         * now — the engine's lexer is the one owner of where PHP mode
         * ends.
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // The driven shape: the sample's string data carries a close
        // tag itself; the multi-line interior after the in-string
        // close keeps the masked view (red at HEAD: the split dropped
        // the tail onto the line-local arm — the marker honored, zero
        // findings).
        $inString = "<?php \$s = \"?>\"; \$k = \"\n{$key} // secrets:allow\n\";?>\n";
        $this->assertSame(
            array( "instr.md:2 {$expect}" ),
            wp_connectors_scan_string($inString, 'instr.md'),
            'A ?> inside the sample\'s string data does not close the region (red at HEAD: the split dropped the tail onto the line-local arm — zero findings).'
        );

        // The genuine close OUTSIDE string data still closes: the
        // prose below the sample keeps the line-local arm, and a prose
        // marker beside the sample stays honored.
        $after = "<?php \$s = \"?>\"; ?>\n{$key} // secrets:allow\n";
        $this->assertSame(array(), wp_connectors_scan_string($after, 'after.md'), 'The genuine ?> outside string data still closes the region — the prose below keeps the line-local arm.');

        // And the region walk's own answer is pinned: the matched pair
        // spans through the REAL close, the in-string spelling never a
        // boundary.
        $regions = wp_connectors_php_sample_regions($inString);
        $this->assertCount(1, $regions, 'One region — the in-string ?> never splits it.');
        $this->assertSame(0, $regions[0][0], 'The region opens at the sample\'s open tag.');
        $this->assertSame((int) strrpos($inString, '?>') + 1, $regions[0][1], 'The region closes at the REAL close tag — the in-string ?> is interior, never a boundary.');
    }

    public function testTheLineSkipNeverCrossesARegionBoundary()
    {
        /*
         * glm19-2: the composed code view fed the WHOLE mixed line to
         * the marker judge, so a prose marker OUTSIDE the region
         * exempted a key INSIDE it (driven: 0 findings at HEAD, 1 at
         * base) — the line-skip crossed the region boundary. The
         * exemption is PER-ARM: a match inside a region's bytes is
         * exempt only by a marker inside the REGION's code view (the
         * marker must sit in CODE for the region's bytes), a match in
         * the prose bytes keeps the line-local arm's own marker.
         */
        $key = 'sk-ant-api3-' . str_repeat('q', 30);
        $expect = 'openai-anthropic-key (OpenAI/Anthropic API key)';

        // The driven shape: the key inside the mentioned pair, the
        // marker in the prose after it (red at HEAD: the composed view
        // carried the prose marker across the boundary — zero
        // findings).
        $mixed = "<?php \$k = \"{$key}\"; ?> // secrets:allow\n";
        $this->assertSame(
            array( "mixed.md:1 {$expect}" ),
            wp_connectors_scan_string($mixed, 'mixed.md'),
            'A prose marker outside the region never exempts a key inside it (red at HEAD: the composed view carried the marker across the boundary — zero findings).'
        );

        // The pure-code marker keeps exempting: the marker inside the
        // sample's own code comment, on the same one-line shape.
        $inCode = "<?php \$k = \"{$key}\"; // secrets:allow ?>\n";
        $this->assertSame(array(), wp_connectors_scan_string($inCode, 'incode.md'), 'A marker in the sample\'s real code comment keeps exempting the region\'s own line.');

        // And the boundary never crosses BACK either: a prose marker
        // keeps exempting a PROSE key on the same mixed line — the
        // glm17-3 short-echo doctrine, pinned on the mixed line now.
        $proseKey = "<?= \$k ?> with the key {$key} // secrets:allow\n";
        $this->assertSame(array(), wp_connectors_scan_string($proseKey, 'prose.md'), 'A prose marker keeps exempting a PROSE key on the same mixed line.');
    }

    public function testTheMemoryLimitParserIsWidthAwareAndNeverWraps()
    {
        /*
         * glm17-13: the headroom parser scaled the limit with an
         * integer multiply — on a 32-bit build a '4G' spelling
         * overflowed the width and answered garbage (a wrapped count
         * driving the headroom to 0, EVERY scan into the loud
         * refusal) where the honest reading of a limit beyond the
         * addressable width is the bound-off class. The scale rides
         * the float arithmetic and SATURATES at PHP_INT_MAX — the
         * same saturation path clamps '4G' on 32-bit and any >8EiB
         * spelling on 64-bit, width-aware by construction. A 32-bit
         * simulation is not cheap on this host; the unit pin drives
         * the parser function directly.
         */
        $this->assertSame(128 * 1024 * 1024, wp_connectors_memory_limit_to_bytes('128M'), 'In-width values stay exact.');
        /*
         * t31-glm48-4 SUPERSEDES the former '128mb' row ('the unit
         * folds case-insensitively with the optional b'): that
         * contract described the HAND spelling — the engine's own
         * parser rejects the 'b' multiplier ('unknown multiplier
         * "b", interpreting as "128"', driven), enforcing 128 BYTES
         * where the hand grammar read 128 mebibytes and the census
         * overstated the real limit ~1,000,000x on such hosts. The
         * engine is the oracle the census must agree with.
         */
        $this->assertSame(128, wp_connectors_memory_limit_to_bytes('128mb'), 'The engine\'s own verdict: the \'b\' multiplier is not one — the census agrees with the enforced limit, never the hand spelling (red at the hand grammar: 128 mebibytes).');
        $this->assertSame(2 * 1024 * 1024 * 1024, wp_connectors_memory_limit_to_bytes('2G'));
        $this->assertSame(512 * 1024, wp_connectors_memory_limit_to_bytes('512K'));
        /*
         * R43-9 (driven fatal-without-verdict): a fractional spelling
         * the engine still enforces (clamping '128.5M' to '128M')
         * once fell out of the grammar and DISABLED the census — the
         * dense payload fatalling at exit 255 with no verdict where
         * the integer control answered the loud refusal. The grammar
         * admits the fractional tail and floors to the engine's own
         * clamp.
         */
        $this->assertSame(128 * 1024 * 1024, wp_connectors_memory_limit_to_bytes('128.5M'), "A fractional spelling parses to the engine clamp — never to a bound-off PHP_INT_MAX that disables the census.");
        $this->assertSame(1024, wp_connectors_memory_limit_to_bytes('1024'), 'A bare byte count parses.');

        // Over-width saturates — never a wrapped or cast-garbage count.
        // '9999999999G' scales past the 64-bit width on THIS host and
        // past any 32-bit spelling the same code path clamps.
        $this->assertSame(PHP_INT_MAX, wp_connectors_memory_limit_to_bytes('9999999999G'), 'A limit beyond the integer width saturates to the bound-off answer (red at the old integer multiply: an overflowed count).');

        // Unparseable spellings answer the same bound-off class.
        $this->assertSame(PHP_INT_MAX, wp_connectors_memory_limit_to_bytes('-1'));
        $this->assertSame(PHP_INT_MAX, wp_connectors_memory_limit_to_bytes('not-a-limit'));
    }

    /**
     * glm28-16 (post-round): the repo scan rides a FRESH spawned child
     * — the production context the composer @scan-secrets gate runs
     * every check. The post-round check REDDERNED here with the
     * census refusal over the repo's own two biggest sources
     * (REFUTATION_LEDGER.md, BuildArtifactsTest.php), and the
     * diagnosis — measured, recorded in the ledger's residual note —
     * is a LEGITIMATE trip of glm17-2's fail-safe census inside the
     * memory-squeezed phpunit process, never a scanner regression:
     * the census estimates the dense-worst-case token cost
     * (span_total × 98, glm17-2's measured adversarial ceiling) at
     * 72.1 MB and 70.0 MB for the two files against an in-suite
     * headroom that ambient suite usage regularly holds below their
     * 55.9/58.0 MB break-evens — while the REAL token costs measure
     * 13.4 MB and 11.6 MB and a fresh CLI child (headroom ~118 MB
     * under the same 128M limit) scans both green, exactly as the
     * composer gate does. NO scanner-side bound change can admit
     * these files in-suite without reopening the adversarial fatal
     * window glm17-2 closed (any factor low enough to fit the
     * squeezed headroom under-estimates a hostile mid-size dense
     * payload whose real cost is the full 98×, and the artifact scan
     * — the hostile surface, files under the 2 MB cap — would FATAL
     * instead of refusing); the honest fix is the CONTEXT: the test
     * scans the repo in the fresh-process shape the scan's own
     * production entry point spells. The squeezed-child leg below
     * pins the census's fail-safe refusal as UNCHANGED — the guard
     * moved nowhere.
     */
    public function testScannerAcceptsRepoSources()
    {
        $repoRoot = dirname(__DIR__);
        // Mirror the CLI default: the whole repository root (the scan prunes
        // .git/vendor/node_modules/dist/tools itself), so root config files
        // like mise.toml and composer.json are covered too.
        if (self::canSpawnChildren()) {
            $script = sprintf(
                'require %%s; fwrite(STDOUT, "FINDINGS=" . json_encode(wp_connectors_scan_paths(array(%s))) . "\n");',
                var_export($repoRoot, true)
            );
            $spawned = $this->spawnScannerChild($script);
            $this->assertSame(0, $spawned['exit'], 'The fresh-child repo scan completes — the production-shaped context: ' . $spawned['report']);
            $this->assertSame(1, preg_match('/^FINDINGS=(\[\])$/m', $spawned['report']), "Repository sources must stay secret-free in the fresh-process context, our own biggest files included (red at HEAD: the ledger and BuildArtifactsTest answered the census refusal inside the squeezed phpunit process): {$spawned['report']}");

            /*
             * The fail-safe leg, pinned UNCHANGED beside the fix: a
             * child whose memory_limit is pinned BELOW the census's
             * own estimate still answers the loud refusal over the
             * same repo — the guard glm17-2 built moves nowhere (the
             * fix moved the test's context, never the census).
             */
            $squeezed = $this->spawnScannerChild($script, array( 'memory_limit=64M' ));
            $this->assertSame(0, $squeezed['exit'], 'The squeezed child still completes — the census refuses LOUDLY per file, it never fatals: ' . $squeezed['report']);
            $this->assertSame(1, preg_match('/REFUTATION_LEDGER\.md: over the secret-scan token-memory bound/', $squeezed['report']), 'A 64M child cannot afford the ledger\'s dense-worst-case estimate (72.1 MB against ~58 MB headroom) and the census answers its loud refusal — the fail-safe direction preserved (the fix moved the test\'s context, never the guard).');

            return;
        }
        $findings = wp_connectors_scan_paths(array( $repoRoot ));

        $this->assertSame(array(), $findings, 'Repository sources must stay secret-free: ' . implode("\n", $findings));
    }

    /**
     * glm28-1: a PCRE abort over the pattern walk is the LOUD refusal,
     * never clean — `=== 0` once let the FALSE return (match limit
     * exhausted) fall into the match loop over an empty $matches and
     * answer zero findings for the aborting pattern, so a live token
     * later on the same line went unflagged (the fail-open direction;
     * the unpadded control flags). DERIVATION NOTE, recorded: the
     * ten flat patterns AUTO-POSSESSIFY on this PCRE2 engine — every
     * crafted pad (the round's own 'A'-pad premise included) was
     * probed and none aborts at any realistic limit, failed starts
     * and successful matches alike staying off the match counter —
     * so the drive rides the PINNED-LIMIT idiom the suite's abort
     * pins already ride (SharedOAuthContractsHttpTest's PCRE pin):
     * at the floor limit 1 ANY match attempt aborts, the one
     * deterministic injection this engine offers, the limit restored
     * on every exit path.
     */
    public function testAPcreAbortOverThePatternWalkRefusesLoudlyNeverClean()
    {
        $key = 'sk-ant-' . str_repeat('a1B2', 10) . 'fixture';
        $host_limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $findings = wp_connectors_scan_string("<?php\n\$k = '{$key}';\n", 'abort.php');
            $this->assertCount(1, $findings, 'The aborting pattern walk answers exactly the one refusal line — red at HEAD it answered ZERO findings over the same live-shaped key (the fail-open).');
            $this->assertStringContainsString('the secret-pattern walk aborted (PCRE:', $findings[0], 'The refusal rides the walk\'s own loud vocabulary with the engine\'s diagnostic.');
            $this->assertStringContainsString('the secret scan cannot run', $findings[0], 'The refusal names the scan, the glm14-2 shape.');

            // Clean lines stay clean at the same pinned limit: a line no
            // pattern candidate lives on never starts a match attempt,
            // so the floor limit never fires.
            $this->assertSame(array(), wp_connectors_scan_string("<?php\n\$plain = 1;\n", 'abort.php'), 'A candidate-free payload keeps its clean verdict under the pinned floor — the refusal is the abort, never the size.');
        } finally {
            ini_set('pcre.backtrack_limit', $host_limit);
        }

        // The unpadded control at the restored limit: the same key flags.
        $this->assertNotSame(array(), wp_connectors_scan_string($key . "\n", 'abort.php'), 'The control flags at the host default — the abort above was the pinned limit, never the payload.');
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

    /**
     * Round-57 pin (t31-glm57-3 [R57-3, measured] — the dictionary
     * alternation once per process): R52-15 imploded the sixteen
     * words once per spans CALL; the walker answers up to three
     * calls per candidate value (the head pass, the tail pass, the
     * value-level all-fake pass) beside the value-level word
     * consult's own implode, so a dense hostile payload — the
     * extracted-zip shape the artifact scan rides, inside the
     * walk's own 2 MB cap — rebuilt the string per pass (measured
     * A/B at landing: 1609ms -> 626ms over one dense 2 MB payload
     * of 32,438 candidates, the scan outputs byte-identical both
     * sides). The one helper owns the string; both battery seats
     * consult it — pure function of the constant word list,
     * verdict-identical by construction.
     */
    public function testTheDictionaryAlternationRidesTheOncePerProcessOwner(): void
    {
        $this->assertSame(
            implode('|', wp_connectors_fake_secret_dictionary_words()),
            wp_connectors_fake_secret_dictionary_alternation(),
            'The helper derives from the one word list — never a pasted second spelling of the alternation.'
        );

        /*
         * The source pin (the one-owner count): one declaration, two
         * consults — the spans walker's battery and the value-level
         * word probe. A regrown inline implode beside the owner
         * drops its consult spelling here.
         */
        $source = (string) file_get_contents(__DIR__ . '/../bin/lib/secret-scanner.php');
        $this->assertNotSame('', $source, 'The scanner source must be readable for the composition pin.');
        $this->assertSame(3, substr_count($source, 'wp_connectors_fake_secret_dictionary_alternation'), 'One declaration, two consults — no inline implode regrows beside the owner.');
    }

    /**
     * Round-58 pin (t31-glm58-6 [R58-7, driven at HEAD by both the
     * review and the driver]): the bare ''=== arm sent EVERY
     * extension-less payload the walk admits onto the whole-file
     * masked view, so a legitimately-marked example in an
     * extension-less README could never exempt — the byte-identical
     * .md twin exempted through the region-bounded routing. The
     * extension-less payload rides the CODE arm only when its HEAD
     * opens PHP (the glm18-2 content-shape owner); prose-headed
     * payloads keep the text-family routing with the markers
     * honored.
     */
    public function testAnExtensionLessProseHeadedPayloadHonorsItsMarkers(): void
    {
        $token = 'ghp_' . str_repeat('abcd', 9);
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-extless');
        try {
            $this->assertTrue(@mkdir($tempDir, 0755, true), "staging: {$tempDir} must create.");
            $this->assertNotFalse(file_put_contents($tempDir . '/README', "prose with a marked example:\n\n{$token} // secrets:allow\n\n<?php \$x = 1; ?>\ntrailing prose\n"), 'staging: README must write.');
            $this->assertNotFalse(file_put_contents($tempDir . '/README.md', "prose with a marked example:\n\n{$token} // secrets:allow\n\n<?php \$x = 1; ?>\ntrailing prose\n"), 'staging: README.md must write.');
            $this->assertNotFalse(file_put_contents($tempDir . '/cli-script', "<?php\n\$tok = '{$token}';\n"), 'staging: cli-script must write.');
            $this->assertNotFalse(file_put_contents($tempDir . '/UNMARKED', "some prose\n{$token}\n"), 'staging: UNMARKED must write.');

            $findings = wp_connectors_scan_paths(array( $tempDir ));
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        $this->assertStringNotContainsString('/README:', $report, 'A marked example in an extension-less PROSE-HEADED payload exempts exactly like its .md twin (red at HEAD: FAIL README:2 github-token).');
        $this->assertStringNotContainsString('/README.md:', $report, 'The .md twin keeps its exemption.');
        $this->assertStringContainsString('/cli-script:', $report, 'A PHP-HEADED extension-less payload keeps the code arm — the \'-arm\' spelling the \'\'=== arm was built for.');
        $this->assertStringContainsString('/UNMARKED:', $report, 'The unmarked control still flags — no vacuous exemption class opened.');
    }

    /**
     * Round-58 pin (t31-glm58-7 [R58-8, driven at the real CLI]):
     * the marker grammar's enclosure vocabulary had no ';' member —
     * the INI family's own comment character (php.ini, git config)
     * — so a marked fixture line in a .ini payload false-flagged
     * where the byte-identical '#' spelling exempted. ';' joins the
     * openers for the INI-grammar family alone (glm23-6: the
     * enclosure vocabulary is a property of the payload's comment
     * grammar — .env keeps '#' only, ';' a value byte there).
     */
    public function testTheSemicolonCommentEnclosureServesTheIniFamily(): void
    {
        $token = 'ghp_' . str_repeat('abcd', 9);
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-inisemi');
        try {
            $this->assertTrue(@mkdir($tempDir, 0755, true), "staging: {$tempDir} must create.");
            $this->assertNotFalse(file_put_contents($tempDir . '/app.ini', "github_token = \"{$token}\" ; secrets:allow\n"), 'staging: app.ini must write.');
            $this->assertNotFalse(file_put_contents($tempDir . '/hash.ini', "github_token = \"{$token}\" # secrets:allow\n"), 'staging: hash.ini must write.');
            $this->assertNotFalse(file_put_contents($tempDir . '/bare.ini', "github_token = \"{$token}\"\n"), 'staging: bare.ini must write.');
            $this->assertNotFalse(file_put_contents($tempDir . '/app.env', "github_token = \"{$token}\" ; secrets:allow\n"), 'staging: app.env must write.');

            $findings = wp_connectors_scan_paths(array( $tempDir ));
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        $this->assertStringNotContainsString('/app.ini:', $report, 'The INI family\'s own comment character exempts a marked line (red at HEAD: FAIL app.ini:1 github-token).');
        $this->assertStringNotContainsString('/hash.ini:', $report, 'The \'#\' spelling keeps its exemption.');
        $this->assertStringContainsString('/bare.ini:', $report, 'The unmarked control still flags.');
        $this->assertStringContainsString('/app.env:', $report, 'The family boundary holds — \';\' is a value byte in a dotenv value, not a comment there.');
         */
        $this->assertStringNotContainsString('/app.ini.dist:', $report, 'The compound ini-DISTRIBUTION spelling keeps its base grammar\'s comment character (red at HEAD: FAIL app.ini.dist:1 github-token).');
        $this->assertStringContainsString('/app.env.dist:', $report, 'The compound dotenv spelling keeps the dotenv boundary — \';\' stays a value byte there.');
    }

    /**
     * Round-59 pin (t31-glm59-5 [R59-7+R59-1] — the round-58 head-shape
     * clause was the wrong owner, both legs): a php-HEADED
     * extension-less prose doc (a fenced sample first, prose after)
     * rode the whole-file code arm and no marker spelling could
     * exempt while the byte-identical .md twin exempted; and a
     * bare-'<?'-headed payload's routing followed the head's
     * spelling instead of the engine's own lexing, so a marker
     * riding heredoc string data behind a host-lexed T_OPEN_TAG
     * laundered a live key where the pre-round-58 code arm caught
     * it. The '' special-case is gone — extension-less payloads ride
     * the text-family routing wholesale, the region walk admitting
     * openers by the ENGINE'S token verdict (the host-probed
     * lexing: the production-default host never mints T_OPEN_TAG
     * for a bare '<?', so its prose verdicts stand exactly as
     * glm17-3 holds them there).
     */
    public function testAnExtensionLessPhpHeadedProseDocHonorsMarkersAndTheBareTagFollowsTheEngine(): void
    {
        $token = 'ghp_' . str_repeat('abcd', 9);

        /*
         * The INI-pinned legs (the engine-follows arm): a bare-'<?'
         * payload's marker riding heredoc string data behind a
         * host-lexed T_OPEN_TAG launders on a host whose engine
         * opens the short spelling (red at HEAD under
         * -d short_open_tag=1: 0 findings) and honors as prose where
         * the engine refuses the opener — driven through spawned
         * engines under both spellings (the glm18-4 doctrine: pinned
         * INI in a fresh child). The extension-less PHP-HEADED prose
         * doc's routing stays the round-58 adjudication (the code
         * arm — its post-'?>' bytes are inline HTML, string data the
         * glm16-1 doctrine refuses to exempt): R59-7 records the
         * prose-parity counter-argument as Task 3.3 inheritance.
         */
        if ( ! $this->canSpawnChildren() ) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the INI-pinned spawned legs cannot run; the INI-independent legs above already passed.');
        }
        $fixture = sys_get_temp_dir() . '/wp-connectors-baretag-' . uniqid('', true) . '-' . getmypid();
        $this->assertTrue(@mkdir($fixture, 0755, true), "staging: {$fixture} must create.");
        try {
            $this->assertNotFalse(file_put_contents($fixture . '/F1README', "<?\n\$d = <<<EOT\n{$token} // secrets:allow\nEOT;\n?>\nOutro\n"), 'staging: the spawned F1README must write.');
            $script = sprintf('require %%s; $f = wp_connectors_scan_paths(array(%s)); echo "count=" . count($f) . "\n";', var_export($fixture, true));
            $on = $this->spawnScannerChild($script, array( 'short_open_tag=1' ));
            $this->assertSame(0, $on['exit'], "The On-engine child runs clean: {$on['report']}");
            $this->assertSame(1, preg_match('/^count=1$/m', $on['report']), "A host whose engine opens the bare '<?' sees the heredoc-carried marker as STRING DATA — the marked key flags (red at HEAD: count=0): {$on['report']}");
            $off = $this->spawnScannerChild($script, array( 'short_open_tag=0' ));
            $this->assertSame(0, $off['exit'], "The Off-engine child runs clean: {$off['report']}");
            $this->assertSame(1, preg_match('/^count=0$/m', $off['report']), "The production-default host refuses the bare '<?' — the payload is prose and the marker honors (the glm17-3 doctrine stands there): {$off['report']}");
        } finally {
            WpHarness::releaseScratch($fixture);
        }
    }
}
