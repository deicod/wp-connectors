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
     * clean over bytes the walk did not read.
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
         */
        $githubToken = 'ghp_' . bin2hex(random_bytes(18));
        $tempDir = $this->scanScratchRoot('wp-connectors-scan-oversize');
        try {
            $this->assertTrue(mkdir($tempDir, 0755, true), "staging: {$tempDir} must create — a staging failure fails as staging, never as the scan verdict.");
            $this->assertNotFalse(file_put_contents($tempDir . '/small.php', "<?php\n\$t = '{$githubToken}';\n"), "staging: {$tempDir}/small.php must write — a staging failure fails as staging, never as the scan verdict.");
            // 2,450,041 bytes — one byte class over the 2 * 1024 * 1024 cap, the reviewer's own driven size.
            $this->assertNotFalse(file_put_contents($tempDir . '/big.php', '<?php\n$t = \'' . $githubToken . '\';\n' . str_repeat('// ' . bin2hex(random_bytes(16)) . "\n", 98000)), "staging: {$tempDir}/big.php must write — a staging failure fails as staging, never as the scan verdict.");
            $this->assertGreaterThan(2 * 1024 * 1024, filesize($tempDir . '/big.php'), 'staging: big.php must land over the 2 MB cap the leg premises.');

            $findings = wp_connectors_scan_paths(array( $tempDir ));
        } finally {
            WpHarness::releaseScratch($tempDir);
        }

        $report = implode("\n", $findings);
        $this->assertStringContainsString('big.php: over the 2 MB secret-scan size limit — the secret scan cannot run', $report);
        // The under-limit twin still scans normally, and no finding ever echoes the token.
        $this->assertStringContainsString('small.php', $report);
        $this->assertStringContainsString('github-token', $report);
        $this->assertStringNotContainsString($githubToken, $report);
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
             * The library path is asserted resolved BEFORE the embed (OCR
             * round 25, t31-ocr25-8): realpath() answering false (a broken
             * checkout, an open_basedir wall) used to embed `require
             * false;` straight into the child script — the child's fatal
             * then read as the SCANNER's self-containment defect (the
             * very thing this pin exists to vouch for), never the
             * environment problem it was. The assertion names its own
             * subject; the failure channel is the environment's.
             */
            $scannerLibrary = realpath(__DIR__ . '/../bin/lib/secret-scanner.php');
            $this->assertNotFalse($scannerLibrary, 'The scanner library path must resolve before the fresh-process leg runs — a realpath() false (a broken checkout, an open_basedir wall) is an environment problem, never the scanner self-containment defect the child would otherwise fatal as.');
            $script = 'require ' . var_export($scannerLibrary, true) . ';'
                . ' foreach (wp_connectors_scan_paths(array(' . var_export($tempDir, true) . ')) as $finding) { echo $finding, "\n"; }';
            $output = array();
            $exit = 1;
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

            $report = implode("\n", $output);
            $this->assertSame(0, $exit, "A fresh process requiring ONLY the scanner library must scan, never fatal mid-walk: {$report}");
            $this->assertStringContainsString('plain' . DIRECTORY_SEPARATOR . 'leak.conf', $report, 'The fresh-process scan finds the live-looking key outside the pruned segments.');
            $this->assertStringContainsString('zai-key', $report);
            $this->assertStringNotContainsString('VENDOR', $report, 'The case-variant dev segment prunes exactly as the in-process battery pins.');
            $this->assertStringNotContainsString($zaiKey, $report, 'Findings still never echo the secret itself.');
        } finally {
            WpHarness::releaseScratch($tempDir);
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
