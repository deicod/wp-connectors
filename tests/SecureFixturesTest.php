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
     * @return array{report: string, exit: int} The child's merged stdout/stderr and its exit code (124: killed at the bound).
     */
    private function spawnScannerChild(string $script, array $ini_flags = array(), int $timeout_seconds = 30): array
    {
        $scannerLibrary = realpath(__DIR__ . '/../bin/lib/secret-scanner.php');
        $this->assertNotFalse($scannerLibrary, 'The scanner library path must resolve before the spawned-engine leg runs — a realpath() false (a broken checkout, an open_basedir wall) is an environment problem, never the defect the child would otherwise carry.');
        $command = WpHarness::isPosixHost() ? 'timeout ' . $timeout_seconds . ' ' : '';
        $command .= escapeshellarg(PHP_BINARY);
        foreach ($ini_flags as $flag) {
            $command .= ' -d ' . $flag;
        }
        $command .= ' -r ' . escapeshellarg(sprintf($script, var_export($scannerLibrary, true)));
        $output = array();
        $exit = 1;
        exec($command . ' 2>&1', $output, $exit);

        return array( 'report' => implode("\n", $output), 'exit' => $exit );
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
        /*
         * glm20-3: the spawn move also dropped PHPUnit's warnings-to-
         * failures regime at the boundary — a whale-scale-only
         * Warning/Notice/Deprecation regression in the child printed
         * into $report unasserted (in-process, PHPUnit converts each
         * to a failure; spawned, the verdict lines still pinned green
         * beside the engine's own complaint). The negative needle
         * restores the regime: no PHP diagnostic line may appear in
         * the child's report (both CLI spellings covered — this host
         * prints 'Warning:', others prefix 'PHP '). The mutant pin
         * below drives the needle's own catch.
         */
        $diagnosticsNeedle = '/^(?:PHP )?(?:Warning|Notice|Deprecated):/m';
        $this->assertSame(0, preg_match($diagnosticsNeedle, $report), "The spawned scan runs clean — no engine Warning/Notice/Deprecation crosses the boundary unasserted: {$report}");
        $this->assertSame(1, preg_match($diagnosticsNeedle, "Warning: Undefined variable \$key in Command line code on line 1"), 'The diagnostics needle catches an injected child-side warning — the regime the spawn move dropped, restored.');
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
        $this->assertSame(128 * 1024 * 1024, wp_connectors_memory_limit_to_bytes('128mb'), 'The unit folds case-insensitively with the optional b.');
        $this->assertSame(2 * 1024 * 1024 * 1024, wp_connectors_memory_limit_to_bytes('2G'));
        $this->assertSame(512 * 1024, wp_connectors_memory_limit_to_bytes('512K'));
        $this->assertSame(1024, wp_connectors_memory_limit_to_bytes('1024'), 'A bare byte count parses.');

        // Over-width saturates — never a wrapped or cast-garbage count.
        // '9999999999G' scales past the 64-bit width on THIS host and
        // past any 32-bit spelling the same code path clamps.
        $this->assertSame(PHP_INT_MAX, wp_connectors_memory_limit_to_bytes('9999999999G'), 'A limit beyond the integer width saturates to the bound-off answer (red at the old integer multiply: an overflowed count).');

        // Unparseable spellings answer the same bound-off class.
        $this->assertSame(PHP_INT_MAX, wp_connectors_memory_limit_to_bytes('-1'));
        $this->assertSame(PHP_INT_MAX, wp_connectors_memory_limit_to_bytes('not-a-limit'));
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
