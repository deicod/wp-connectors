<?php
/**
 * The build-seam publication property battery (t31-r5, the round's
 * class-killer mandate).
 *
 * Closes the defect class four targeted rounds chased one spelling at a
 * time (t31-r3's config seam → t31-r4's closed schema → this round's
 * embed/publication findings): the SILENT THIRD — a build run that
 * exits clean while shipping a library-less, 0-byte, broken, or
 * half-described artifact. Per the M2 pattern (a surviving silent-loss
 * class gets a property harness, SseAggregatorMutationPropertyTest),
 * this battery states the INVARIANT over the adversarial build-state
 * space instead of pinning spellings:
 *
 *   for every build run,
 *
 *   CLEAN — the run succeeds AND the artifact is complete and sound:
 *           the zip opens, every expected entry is present and
 *           non-empty, every PHP entry parses after extraction, the
 *           sidecar and manifest agree with the zip's checksum, and
 *           the embedded tree is exactly the shared PHP-source set;
 *   LOUD  — the run refuses (RuntimeException; the CLI exits != 0)
 *           AND the previous good artifact set (zip, sidecar,
 *           manifest) is byte-untouched, with nothing landed and no
 *           staging residue;
 *
 *   NEVER — the silent third: success with an incomplete, unsound, or
 *           half-described artifact.
 *
 * The state table is ENUMERATED and exhaustive (no seed knob: every
 * adversarial state the round named is a row), each row seeded with a
 * previous GOOD build first, so the LOUD leg's byte-untouched
 * assertion is always meaningful. A failing row fails the run with the
 * state's name, the expected class, and the observed divergence — the
 * reproducing setup IS the row. Two forced-failure rows (zip-add
 * failure, failed close) are seam-driven in the production staging
 * context — the t31-r3-16 precedent: libzip defers entry reads to
 * close(), so no external input throws mid-archive — and are labeled
 * as such in their row comments.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/build.php';
require_once __DIR__ . '/../bin/inspect-artifact.php';

final class BuildSeamPropertyTest extends WpConnectorsTestCase
{
    /**
     * The invariant over every enumerated adversarial state.
     *
     * @return void
     */
    public function testThePublicationInvariantHoldsOverEveryAdversarialBuildState(): void
    {
        /*
         * The battery-level exec guard is GONE, narrowed to the rows
         * that need it (OCR round 20, t31-ocr20-5 — the over-broad
         * skip the ocr18-2 guard left behind): only the CLEAN rows
         * ride child processes (classifyClean's php -l soundness walk
         * and the inspector's own syntax loop over the extracted
         * tree), while the LOUD rows refuse IN-PROCESS — the build
         * path spawns nothing (grep-derived: no exec/proc_open under
         * bin/build.php or the plugin-tools gates it rides). The old
         * whole-battery skip silenced every LOUD refusal verdict on a
         * disable_functions host; now those rows run and only the
         * CLEAN rows answer the row-level capability skip in
         * runState() below, and the CLEAN non-vacuity assertion is
         * conditional on the capability (the LOUD half stays
         * unconditional — it always runs).
         */

        $failures = array();
        $run = array( 'CLEAN' => 0, 'LOUD' => 0 );

        foreach ($this->states() as $state_id => $state) {
            $verdict = $this->runState($state_id, $state);
            if ('SKIP' === $verdict['class']) {
                // Row-level skip (t31-ocr4-1): a chmod-0000 row on a
                // root runner skips ITSELF, never the battery — the
                // other states stay charged.
                continue;
            }
            ++$run[$state['expect']];
            if ('FAIL' === $verdict['class']) {
                $failures[] = sprintf("[%s] expected %s: %s", $state_id, $state['expect'], $verdict['why']);
            }
        }

        // Non-vacuity: every expected class that CAN run did. The LOUD
        // half is unconditional (nothing it rides needs exec); the
        // CLEAN half is charged only where its child-process soundness
        // walk can spawn (t31-ocr20-5).
        $this->assertGreaterThan(0, $run['LOUD'], 'The battery must exercise at least one LOUD state.');
        if (self::canSpawnChildren()) {
            $this->assertGreaterThan(0, $run['CLEAN'], 'The battery must exercise at least one CLEAN state.');
        }

        $this->assertSame(
            array(),
            $failures,
            "The build-seam invariant failed for the enumerated states (each row's setup is its reproducer):\n - "
            . implode("\n - ", $failures)
        );
    }

    /*
     * OCR-round-16 pin (t31-ocr16-3): a corrupt shipped artifact
     * answers the soundness reopen's FAIL ROW, never an assertion
     * abort and never a ValueError. At HEAD the reopen branch sat
     * BELOW the completeness walk, whose first step (the shared
     * zipEntryNames owner) aborts the battery as a raw assertion on
     * any artifact that does not open — so the strict gate
     * t31-ocr11-6 built to answer this exact class with a named row
     * was unreachable-in-practice, and its failure arm carried
     * close() on the never-opened handle (a ValueError on PHP >= 8,
     * probed on this engine) that would have masked the row had the
     * branch ever run. The reopen gate owns the first zip judgment
     * now and closes only a handle that opened.
     */
    public function testACorruptArtifactAnswersTheReopenFailRowNotAnAbort()
    {
        $scratch = $this->makeScratchRepo('corrupt-reopen');
        try {
            WpConnectorsBuild::buildPlugin($scratch['plugin'], $scratch['dist']);
            // Corrupt the shipped artifact: the reopen must answer its
            // own verdict (red at HEAD: the zipEntryNames assertion
            // aborted the row before the branch was ever consulted).
            $this->assertNotFalse(
                file_put_contents($scratch['zip'], 'not a zip archive'),
                "The corrupt fixture must land at {$scratch['zip']} — an unwritten corruption drives nothing."
            );
            $verdict = $this->classifyClean(
                $scratch,
                array('never reached — the reopen answers first'),
                'never reached either',
                array( 'expect' => 'CLEAN', 'apply' => static function (): void {
                } )
            );
            $this->assertSame('FAIL', $verdict['class'], 'A corrupt artifact is a FAIL row, never an assertion abort or an engine ValueError.');
            $this->assertStringContainsString('could not reopen the artifact', $verdict['why'], 'The row names its owner: the independent-extraction reopen gate.');
            /*
             * The return is pinned by its CONSTANT, never its numeric
             * literal (t31-ocr20-9): '19' coupled the row to this
             * engine's libzip mapping — the stable spelling of the
             * contract is ZipArchive::ER_NOZIP, the enum the engine
             * itself defines (same value here; a mapping change would
             * move the constant with it, not the literal).
             */
            $this->assertStringContainsString((string) ZipArchive::ER_NOZIP, $verdict['why'], 'The row names the ER_* return (ER_NOZIP on a corrupt archive).');
        } finally {
            WpHarness::releaseScratch($scratch['root']);
        }
    }

    /*
     * OCR-round-30 pin (t31-ocr30-6, the t31-ocr13-6 extra-channel
     * doctrine's apply twin): the apply closure's throws are ROW
     * verdicts, never battery aborts. The bare invocation once let one
     * row's throw — a needle-drift refusal the row itself raises, an
     * engine error over a staging path that vanished — abort the whole
     * row table as a test ERROR, masking the states behind it (every
     * other row-verdict channel in this battery converts: the extra
     * closure's assertion failures, the seeded control build's
     * refusal, the reopen/extract/statIndex gates). A throw converts
     * to a FAIL row naming the throw's class and message now, and the
     * table keeps its charge row by row.
     */
    public function testAnApplyThrowAnswersAFailRowNotABatteryAbort()
    {
        $verdict = $this->runState('apply-throw', array(
            'expect' => 'LOUD',
            'apply' => static function (array $scratch): void {
                throw new RuntimeException('apply-throw: the planted throw must ride the row channel');
            },
            'fragment' => 'never consulted — the apply throw answers first',
        ));
        $this->assertSame('FAIL', $verdict['class'], 'An apply throw is a FAIL row, never a battery abort (red at HEAD: the throw escaped runState as a test ERROR, masking the states behind it).');
        $this->assertStringContainsString('apply closure threw', $verdict['why'], 'The row names the channel the throw rode.');
        $this->assertStringContainsString('RuntimeException', $verdict['why'], 'The row names the throw\'s class.');
        $this->assertStringContainsString('planted throw must ride the row channel', $verdict['why'], 'The row carries the throw\'s own message.');
    }

    /**
     * The adversarial state table (exhaustive for the round's charter).
     *
     * Each row: 'expect' ('CLEAN' or 'LOUD'), 'apply' (mutates the
     * seeded scratch), an optional refusal-message fragment the LOUD
     * row must carry, optional CLEAN-state extra assertions, and an
     * optional 'skip_on_root' flag for the chmod-0000 rows (uid 0
     * reads through mode 0000, t31-ocr4-1 — the row skips itself on a
     * root runner instead of failing as a false silent third).
     *
     * @return array<string, array{expect: string, apply: callable, fragment?: string, extra?: callable, skip_on_root?: bool, needs_symlink?: bool}>
     */
    private function states(): array
    {
        return array(
            'control-unmutated-rebuild' => array(
                'expect' => 'CLEAN',
                'apply' => static function (): void {
                },
            ),
            'plugin-tree-symlink-in-excluded-path' => array(
                // t31-r5-7: a vendor/node_modules symlink (a composer
                // path repo, an npm .bin shim) ships nothing — the
                // refusal belongs to paths that would ship, not these.
                // The row NEEDS the symlink capability (t31-ocr10-14):
                // a bare call fatals the state on exactly the hosts
                // that never exercised it; the row skips itself
                // instead, the skip_on_root pattern.
                'expect' => 'CLEAN',
                'needs_symlink' => true,
                'apply' => static function (array $scratch): void {
                    mkdir($scratch['plugin'] . '/vendor/bin', 0755, true);
                    symlink('/usr/bin/true', $scratch['plugin'] . '/vendor/bin/tool');
                },
                'extra' => function (array $scratch, string $zipPath): void {
                    foreach ($this->zipEntryNames($zipPath) as $entry) {
                        $this->assertStringNotContainsString('vendor/', $entry, 'The excluded symlink tree must not ship.');
                    }
                },
            ),
            'shared-source-unreadable' => array(
                // t31-r5-2: a chmod-000 shared source laundered through
                // (string) file_get_contents() shipped a 0-byte library
                // file with the sidecar+manifest published at exit 0.
                // t31-r8-4 supersession: the refusal now fires EARLIER,
                // at the collector's PSR-4 casing fence (the config
                // seam, before any filesystem mutation), so the fragment
                // is the fence's; readSharedSource's own loud read seam
                // stays as defense in depth behind it.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    chmod($scratch['shared'] . '/Clock/ClockInterface.php', 0000);
                },
                'fragment' => 'cannot be read',
                'skip_on_root' => true,
            ),
            'shared-source-whitespace-only' => array(
                // t31-r5-2's empty half: rewriteSharedNamespace('') returns
                // '' without throwing — the 0-byte ship without a read
                // failure at all. t31-r8-4 supersession: the collector's
                // namespace fence refuses the declaration-less bytes at
                // the config seam; the 'no bytes' seam stays behind it.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['shared'] . '/GrantInterface.php', " \n\t\n");
                },
                'fragment' => 'declares no namespace',
            ),
            'plugin-source-unreadable' => array(
                // t31-r5-2's other collection point: an unreadable plugin
                // file shipped a 0-byte zip entry at exit 0.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    chmod($scratch['plugin'] . '/src/Provider/ExampleProvider.php', 0000);
                },
                'fragment' => 'cannot copy',
                'skip_on_root' => true,
            ),
            'shared-tree-empty' => array(
                // t31-r5-4: is_dir() passed while the tree carried no PHP
                // sources — a library-less zip at exit 0.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    unlink($scratch['shared'] . '/Clock/ClockInterface.php');
                    unlink($scratch['shared'] . '/GrantInterface.php');
                    file_put_contents($scratch['shared'] . '/README.md', "# empty of sources\n");
                },
                'fragment' => 'no PHP sources',
            ),
            'build-json-malformed' => array(
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['plugin'] . '/build.json', "{\"embed_shared\": true,\n}\n");
                },
                'fragment' => 'malformed',
            ),
            'build-json-escaped-duplicate' => array(
                // t31-r5-6: a \u-escaped duplicate key decodes to the same
                // key; the raw-text fence counted quoted spellings and
                // last-wins silently meant no-embed at exit 0.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['plugin'] . '/build.json', "{\"embed_shared\": true, \"\\u0065mbed_shared\": false}\n");
                },
                'fragment' => '2 times',
            ),
            'build-json-wrongly-typed' => array(
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['plugin'] . '/build.json', "{\"embed_shared\": \"false\"}\n");
                },
                'fragment' => 'JSON boolean',
            ),
            'plugin-owned-src-shared-collision' => array(
                // t31-r5-1: the plugin's own src/Shared/<path> was
                // silently REPLACED by the generated embed copy.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    mkdir($scratch['plugin'] . '/src/Shared/Clock', 0755, true);
                    file_put_contents(
                        $scratch['plugin'] . '/src/Shared/Clock/ClockInterface.php',
                        "<?php\n// the plugin author's own copy — silently overwritten pre-fix\n"
                    );
                },
                'fragment' => 'collision',
            ),
            'shared-source-upper-php' => array(
                // t31-r5-3: a .PHP-cased source shipped rewritten while
                // the shipped autoloader probes lowercase '.php' — an
                // unreachable class on a case-sensitive filesystem with
                // build AND inspect green.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents(
                        $scratch['shared'] . '/ClockMath.PHP',
                        "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n"
                    );
                },
                'fragment' => 'non-canonical extension',
            ),
            'plugin-owned-src-shared-collision-case-variant' => array(
                // t31-r5-16: a case-variant plugin path ('src/shared/')
                // shipped BOTH entries — on case-insensitive extraction
                // the author's un-rewritten copy overwrote the embed.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    mkdir($scratch['plugin'] . '/src/shared/Clock', 0755, true);
                    file_put_contents(
                        $scratch['plugin'] . '/src/shared/Clock/ClockInterface.php',
                        "<?php\n// the plugin author's case-variant own copy\n"
                    );
                },
                'fragment' => 'case-insensitive collision',
            ),
            'shared-source-near-source-spelling' => array(
                // t31-r5-14: a trailing space or dot hides the extension
                // — the file read as a PHP source was invisible to every
                // gate: built clean, shipped nowhere, its class a
                // not-found fatal.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['shared'] . '/ClockMath.php ', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");
                },
                'fragment' => 'NEAR-SOURCE',
            ),
            'shared-source-near-source-spelling-newline-tail' => array(
                // t31-r6-2: r5-14's tail charlist (" \t.") missed
                // \n/\r/\v/\f — 'ClockMath.php\n' (the newline IN THE
                // FILENAME) was neither collected nor refused:
                // invisible to every gate, absent from every zip, its
                // class a not-found fatal (reproduced).
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['shared'] . "/ClockMath.php\n", "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");
                },
                'fragment' => 'NEAR-SOURCE',
            ),
            'shared-source-near-source-spelling-control-tail' => array(
                // t31-r6-4: r6-2's own literal still missed the C0
                // controls and DEL — 'ClockMath.php\x01' was STILL
                // neither collected nor refused (verifier-confirmed).
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['shared'] . "/ClockMath.php\x01", "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");
                },
                'fragment' => 'NEAR-SOURCE',
            ),
            'shared-source-near-source-spelling-leading-space' => array(
                // t31-r6-4: the LEADING side was unfenced — ' ClockMath.php'
                // COLLECTED and SHIPPED while the autoloader maps class
                // names onto label-shaped paths: a dead entry, build and
                // inspect green (reproduced).
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['shared'] . '/ ClockMath.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {}\n");
                },
                'fragment' => 'NEAR-SOURCE',
            ),
            'shared-tree-symlink-dir' => array(
                // The row NEEDS the symlink capability (t31-ocr13-2):
                // its apply carries a bare symlink() call — on exactly
                // the hosts that cannot create links that call FATALS
                // the battery (function_exists is not the capability
                // signal, the t31-ocr6-14 lesson) instead of skipping
                // the way its needs_symlink siblings do.
                'expect' => 'LOUD',
                'needs_symlink' => true,
                'apply' => static function (array $scratch): void {
                    mkdir($scratch['shared'] . '/Linked', 0755, true);
                    file_put_contents(
                        $scratch['shared'] . '/Linked/LinkedSource.php',
                        "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Linked;\ninterface LinkedSource {}\n"
                    );
                    symlink($scratch['shared'] . '/Linked', $scratch['shared'] . '/LinkedDir');
                },
                'fragment' => 'symlink',
            ),
            'landing-sidecar-blocked' => array(
                // t31-r5-S: the constructible landing blocker refuses at
                // the pre-flight, before the first rename.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    unlink($scratch['zip'] . '.sha256');
                    mkdir($scratch['zip'] . '.sha256');
                },
                'fragment' => 'not a regular file',
            ),
            'landing-manifest-blocked' => array(
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    unlink($scratch['dist'] . '/checksums.txt');
                    mkdir($scratch['dist'] . '/checksums.txt');
                },
                'fragment' => 'not a regular file',
            ),
            'landing-zip-blocked' => array(
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    unlink($scratch['zip']);
                    mkdir($scratch['zip']);
                },
                'fragment' => 'not a regular file',
            ),
            'version-header-traversal' => array(
                // t31-r5-15: the header's bytes reached the artifact
                // filename and staging paths unchecked — a traversal
                // spelling staged the archive and sidecar OUTSIDE dist/.
                // The mutation needles are DERIVED from the fixture at
                // runtime (OCR round 6, t31-ocr6-8): the row used to
                // str_replace two exact literals ('Version:           0.1.0'
                // with its 11-space alignment, "'0.1.0'"), so any fixture
                // drift (header respacing, a version bump) made the
                // replace a silent no-op, the mutation landed nothing,
                // and the row failed as a phantom build defect. A needle
                // miss now fails loudly AT THE MUTATION STEP, naming
                // which spelling drifted.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    $mainPath = $scratch['plugin'] . '/example-connector.php';
                    $main = (string) file_get_contents($mainPath);
                    $traversal = '0.1/../../../vsec-precious';
                    if (1 !== preg_match('/Version:([ \t]++)(\S++)/', $main, $header)) {
                        throw new RuntimeException('version-header-traversal: the fixture header carries no Version line — the mutation needle drifted.');
                    }
                    $mutated = str_replace($header[0], 'Version:' . $header[1] . $traversal, $main, $headerCount);
                    $mutated = str_replace("'" . $header[2] . "'", "'{$traversal}'", $mutated, $quotedCount);
                    if (1 > $headerCount || 1 > $quotedCount) {
                        throw new RuntimeException(sprintf('version-header-traversal: the mutation needle missed the fixture (header spelling matched %d, quoted spelling matched %d) — the fixture drifted; refusing to run a no-op mutation as a phantom build defect.', $headerCount, $quotedCount));
                    }
                    file_put_contents($mainPath, $mutated);
                },
                'fragment' => 'version token',
            ),
            'manifest-unreadable' => array(
                // t31-r5-13: the merge's unchecked read laundered a
                // chmod-000 manifest into an empty line set — the landed
                // manifest carried only this run's entry, every other
                // plugin's checksum destroyed at exit 0.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    chmod($scratch['dist'] . '/checksums.txt', 0000);
                },
                'fragment' => 'cannot read the checksum manifest',
                'skip_on_root' => true,
            ),
            'zip-staging-path-blocked' => array(
                // t31-r5-S: leftover junk at the (PID-unique) staging
                // path is a production failure, not a landing one.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    mkdir($scratch['dist'] . '/.' . basename($scratch['zip']) . '.tmp-' . getmypid());
                },
                'fragment' => 'cannot create the staging archive',
            ),
        );
    }

    /**
     * Runs one state and classifies the observation against the invariant.
     *
     * @param string                                                $state_id Row label (diagnostics).
     * @param array{expect: string, apply: callable, fragment?: string, extra?: callable, skip_on_root?: bool, needs_symlink?: bool} $state The row (skip_on_root and needs_symlink — the t31-ocr4-1/t31-ocr10-14 row-level skip flags the head of this method consults).
     * @return array{class: string, why: string} 'PASS', 'FAIL', or 'SKIP' with the reason (SKIP: the row-level root-runner and symlink-capability legs).
     */
    private function runState(string $state_id, array $state): array
    {
        // Row-level root-runner skip (t31-ocr4-1): a chmod-0000 row's
        // LOUD expectation cannot fire when uid 0 reads through mode
        // 0000 — the row skips itself, never the battery.
        if (! empty($state['skip_on_root']) && self::runningAsRootRunner()) {
            return array('class' => 'SKIP', 'why' => 'chmod-0000 does not block reads for uid 0 — the permission-bit refusal cannot fire in a root container (t31-ocr4-1).');
        }
        // Row-level symlink-capability skip (t31-ocr10-14, the
        // skip_on_root pattern): a link-bearing row cannot be applied
        // on a host without the capability — and the bare symlink()
        // call would FATAL the state there (function_exists is not the
        // capability signal, the t31-ocr6-14 lesson). The row skips
        // itself with a named why, never the battery.
        if (! empty($state['needs_symlink']) && ! self::canSymlink()) {
            return array('class' => 'SKIP', 'why' => 'this host cannot create symlinks — the link-bearing state cannot be applied (t31-ocr10-14).');
        }
        /*
         * Row-level exec-capability skip (t31-ocr20-5, the same
         * pattern): only the CLEAN rows ride child processes —
         * classifyClean's php -l soundness walk over the extracted
         * entries and the release-gate inspection's own syntax loop —
         * while the LOUD rows refuse in-process (the build path
         * spawns nothing). On a disable_functions host the CLEAN row
         * would FATAL at the first spawned lint; it skips ITSELF with
         * a named why, and the LOUD rows keep their charge (the
         * battery-level skip this replaces silenced them too).
         */
        if ('CLEAN' === $state['expect'] && ! self::canSpawnChildren()) {
            return array('class' => 'SKIP', 'why' => 'exec/escapeshellarg is disabled on this host — the CLEAN row\'s soundness walk and release-gate inspection ride child-process php -l and cannot run (t31-ocr20-5; the LOUD rows need no child process and keep running).');
        }

        $scratch = $this->makeScratchRepo($state_id);
        try {
            // Seed: one previous GOOD build of the same inputs.
            try {
                $seedZip = WpConnectorsBuild::buildPlugin($scratch['plugin'], $scratch['dist']);
            } catch (RuntimeException $seedFailure) {
                return array('class' => 'FAIL', 'why' => 'the seeded control build itself refused: ' . $seedFailure->getMessage());
            }
            $seedNames = $this->zipEntryNames($seedZip);
            $seedManifest = (string) file_get_contents($scratch['dist'] . '/checksums.txt');

            // Apply the adversarial state, then snapshot what the run must
            // leave byte-untouched (the mutation itself may remove a member
            // to block a landing — the snapshot sees the post-mutation set;
            // an unreadable member snapshots as its unreadability, so a
            // state like manifest-unreadable compares like-for-like).
            /*
             * The apply closure's throws are ROW verdicts too (OCR
             * round 30, t31-ocr30-6, the t31-ocr13-6 extra-channel
             * doctrine), never battery aborts: the bare invocation once
             * let one row's throw — a needle-drift refusal the row
             * itself raises, an engine error over a staging path that
             * vanished — abort the whole row table as a test ERROR,
             * masking the states behind it. A throw converts to a FAIL
             * row naming the throw's class and message, and the table
             * keeps its charge row by row (each row's setup is its
             * reproducer).
             */
            try {
                ($state['apply'])($scratch);
            } catch (\Throwable $apply_failure) {
                return array('class' => 'FAIL', 'why' => 'the state\'s apply closure threw (' . get_class($apply_failure) . '): ' . $apply_failure->getMessage());
            }
            $snapZip = $this->readMemberOrMarker($scratch['zip']);
            $snapSidecar = $this->readMemberOrMarker($scratch['zip'] . '.sha256');
            $snapManifest = $this->readMemberOrMarker($scratch['dist'] . '/checksums.txt');
            // Dot-file state the state itself planted (a staging-path
            // blocker) is not build residue; anything NEW is.
            $planted = array_merge(
                glob($scratch['dist'] . '/.checksums-*') ?: array(),
                glob($scratch['dist'] . '/.*.tmp-*') ?: array()
            );

            // The run under test.
            $refusal = null;
            try {
                WpConnectorsBuild::buildPlugin($scratch['plugin'], $scratch['dist']);
            } catch (RuntimeException $e) {
                $refusal = $e->getMessage();
            }

            if ('CLEAN' === $state['expect']) {
                if (null !== $refusal) {
                    return array('class' => 'FAIL', 'why' => 'the state must BUILD, the run refused: ' . $refusal);
                }

                return $this->classifyClean($scratch, $seedNames, $seedManifest, $state);
            }

            if (null === $refusal) {
                return array('class' => 'FAIL', 'why' => 'the silent third: the run succeeded where it must refuse');
            }
            if (isset($state['fragment']) && false === strpos($refusal, $state['fragment'])) {
                return array('class' => 'FAIL', 'why' => "the refusal does not name its cause (wanted '{$state['fragment']}'): {$refusal}");
            }

            // The previous good artifact set: byte-untouched, nothing
            // landed, no staging residue.
            $nowZip = $this->readMemberOrMarker($scratch['zip']);
            $nowSidecar = $this->readMemberOrMarker($scratch['zip'] . '.sha256');
            $nowManifest = $this->readMemberOrMarker($scratch['dist'] . '/checksums.txt');
            if ($nowZip !== $snapZip || $nowSidecar !== $snapSidecar || $nowManifest !== $snapManifest) {
                return array('class' => 'FAIL', 'why' => 'the refusal touched the previous good artifact set (zip/sidecar/manifest diverged from the post-mutation snapshot)');
            }
            $residue = array_diff(
                array_merge(
                    glob($scratch['dist'] . '/.checksums-*') ?: array(),
                    glob($scratch['dist'] . '/.*.tmp-*') ?: array()
                ),
                $planted,
                // The manifest lock (t31-r5-11) is persistent dist
                // furniture — the merge's coordination primitive, not
                // residue from this run.
                array( $scratch['dist'] . '/.checksums.lock' )
            );
            if ($residue !== array()) {
                return array('class' => 'FAIL', 'why' => 'the refusal left staging residue behind: ' . implode(', ', $residue));
            }
            if ((glob($scratch['dist'] . '/.stage-example-connector*') ?: array()) !== array()) {
                return array('class' => 'FAIL', 'why' => 'the refusal left the staging tree behind');
            }

            return array('class' => 'PASS', 'why' => '');
        } finally {
            if (is_dir($scratch['shared'] . '/Clock')) {
                @chmod($scratch['shared'] . '/Clock/ClockInterface.php', 0644);
            }
            @chmod($scratch['plugin'] . '/src/Provider/ExampleProvider.php', 0644);
            /*
             * The THIRD chmod'd path of the census (OCR round 28,
             * t31-ocr28-7): the manifest-unreadable row's apply() leaves
             * dist/checksums.txt at mode 0000, and this finally restored
             * only the other two — WpHarness::rrmdir() unlinks with no
             * chmod fallback, so on a host whose unlink cannot remove a
             * 0000 file the whole wpct-battery-* scratch tree leaked per
             * run. The restore lives HERE, at the row that broke it (the
             * ocr27-9 doctrine: the site owning the state owns its
             * cleanup — the forced-close leg's own finally already
             * restores its staged-source twin the same way), not in
             * rrmdir: the removal owner serves every caller, and a
             * chmod-before-unlink fallback there would change the
             * removal contract for a residue only this row plants.
             */
            @chmod($scratch['dist'] . '/checksums.txt', 0644);
            WpHarness::releaseScratch($scratch['root']);
        }
    }

    /**
     * The CLEAN half of the invariant: exit 0 means the artifact is
     * complete and sound — never merely present.
     *
     * @param array<string, string>                    $scratch      The scratch repo map.
     * @param list<string>                             $seedNames    The seeded build's entry names.
     * @param string                                   $seedManifest The seeded manifest bytes.
     * @param array{expect: string, apply: callable, fragment?: string, extra?: callable, skip_on_root?: bool, needs_symlink?: bool} $state The row (the full row shape runState() receives; the skip flags are consulted before this half runs).
     * @return array{class: string, why: string} 'PASS' or 'FAIL' with the reason.
     */
    private function classifyClean(array $scratch, array $seedNames, string $seedManifest, array $state): array
    {
        $zipPath = $scratch['zip'];
        if (! is_file($zipPath)) {
            return array('class' => 'FAIL', 'why' => 'the successful run left no zip');
        }

        /*
         * Soundness FIRST (OCR round 16, t31-ocr16-3): the reopen
         * gate owns the corrupt-artifact class as its FAIL row. The
         * block used to run BELOW the completeness walk, whose first
         * step (the shared zipEntryNames owner, t31-ocr8-10) ABORTS
         * the whole battery as a raw assertion on a corrupt artifact
         * — so the reopen branch this strict gate exists to answer
         * with (t31-ocr11-6: a failed open is a FAIL row naming the
         * return) was unreachable-in-practice, and its failure arm
         * carried close() on the never-opened handle (on PHP >= 8 a
         * ValueError: 'Invalid or uninitialized Zip object' — probed
         * on this engine) that would have MASKED the row had it ever
         * run. The reopen gate answers first now and closes only a
         * handle that opened; every verdict below it is unchanged.
         */
        // Soundness: every PHP entry parses after independent
        // extraction (the extension judgment rides the one owner).
        $extract = $scratch['root'] . '/.extract';
        mkdir($extract, 0755, true);
        try {
            $zip = new ZipArchive();
            /*
             * Both returns OWNED (OCR round 11, t31-ocr11-6): an
             * unchecked reopen/extraction walked an EMPTY dir, every
             * file check skipped, and the soundness half passed
             * vacuously — a green battery over a judgment that never
             * ran (the strict-gate doctrine the suite's other open()
             * sites carry, t31-ocr10-16). A failed open/extract is a
             * FAIL row naming the return, never a silent pass.
             */
            $opened = $zip->open($zipPath);
            if (true !== $opened) {
                return array('class' => 'FAIL', 'why' => 'the independent extraction could not reopen the artifact — open() returned ' . var_export($opened, true));
            }
            $extracted = $zip->extractTo($extract);
            $zip->close();
            if (true !== $extracted) {
                return array('class' => 'FAIL', 'why' => 'the independent extraction returned failure — the soundness walk must never judge an empty or partial tree');
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($extract, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (! wp_connectors_is_php_source($file->getPathname())) {
                    continue;
                }
                $output = array();
                $exit = 0;
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $exit);
                if (0 !== $exit) {
                    return array('class' => 'FAIL', 'why' => 'a shipped PHP entry does not parse: ' . implode(' ', $output));
                }
            }
        } finally {
            WpHarness::releaseScratch($extract);
        }

        // Completeness: same entry set as the seeded good build, every
        // entry non-empty (a 0-byte entry is the unreadable-source ship).
        $names = $this->zipEntryNames($zipPath);
        if ($names !== $seedNames) {
            return array('class' => 'FAIL', 'why' => 'the rebuilt entry set diverged from the seeded build');
        }
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath)) {
            return array('class' => 'FAIL', 'why' => 'the shipped zip does not open');
        }
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            /*
             * A statIndex() FAILURE is a FAIL row too (OCR round 27,
             * t31-ocr27-10, the t31-ocr11-6/11-10 strict-gate doctrine
             * the reopen/extractTo twins above already carry): the
             * is_array() guard turned a false return into a silent
             * skip — the emptiness walk judged nothing over that
             * entry and the battery passed vacuously, a green row
             * over a judgment that never ran. The row names the
             * return, never a silent pass.
             */
            if (false === $stat) {
                $zip->close();
                return array('class' => 'FAIL', 'why' => "entry index {$i} has no stat — statIndex() returned false, never a silent skip");
            }
            if ($stat['size'] <= 0) {
                $zip->close();
                return array('class' => 'FAIL', 'why' => "entry {$stat['name']} shipped empty");
            }
        }
        $zip->close();

        // The embedded tree is EXACTLY the shared PHP-source set —
        // enumerated independently of the build's collector.
        $embedded = array();
        foreach ($names as $entry) {
            if (0 === strpos($entry, 'example-connector/src/Shared/')) {
                $embedded[] = substr($entry, strlen('example-connector/src/Shared/'));
            }
        }
        sort($embedded);
        $sources = array();
        $sourceIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scratch['shared'], FilesystemIterator::SKIP_DOTS)
        );
        foreach ($sourceIterator as $sourceFile) {
            /** @var SplFileInfo $sourceFile */
            if (wp_connectors_is_php_source($sourceFile->getPathname())) {
                $sources[] = str_replace($scratch['shared'] . '/', '', $sourceFile->getPathname());
            }
        }
        sort($sources);
        if ($embedded !== $sources) {
            return array('class' => 'FAIL', 'why' => 'the embedded tree is not exactly the shared PHP-source set (embedded: ' . implode(',', $embedded) . '; sources: ' . implode(',', $sources) . ')');
        }

        /*
         * Soundness includes the RELEASE gate's verdict: the artifact the
         * doctrine ships is the artifact the inspector accepts (added with
         * t31-r5-10 — the round's own vocabulary-drift finding was exactly
         * a build-clean/inspect-rejected contradiction). A disagreement
         * is a FAIL ROW (t31-ocr13-6), never a battery abort — the
         * inspector's own rendering already rode the printable seam, so
         * the lines interpolate into the aggregator's report as-is.
         */
        $inspect = $scratch['root'] . '/.battery-inspect';
        $violations = wp_connectors_inspect_artifact($zipPath, $inspect);
        if ($violations !== array()) {
            return array('class' => 'FAIL', 'why' => 'the CLEAN artifact failed the inspector — build and inspect give ONE verdict: ' . implode('; ', $violations));
        }

        // Consistency: the sidecar and manifest describe THIS zip.
        $checksum = hash_file('sha256', $zipPath);
        $sidecar = (string) file_get_contents($zipPath . '.sha256');
        if ($checksum . '  ' . basename($zipPath) . "\n" !== $sidecar) {
            return array('class' => 'FAIL', 'why' => 'the sidecar does not describe the shipped zip');
        }
        $manifest = (string) file_get_contents($scratch['dist'] . '/checksums.txt');
        if (false === strpos($manifest, basename($zipPath) . '  ' . $checksum . "\n")) {
            return array('class' => 'FAIL', 'why' => 'the manifest does not describe the shipped zip');
        }
        if ($manifest !== $seedManifest) {
            return array('class' => 'FAIL', 'why' => 'the rebuilt manifest diverged from the seeded manifest (a deterministic rebuild must land byte-identical)');
        }

        if (isset($state['extra'])) {
            /*
             * The extra closure's assertions are ROW verdicts
             * (t31-ocr13-6), never battery aborts: a thrown assertion
             * failure converts to a FAIL row riding the aggregator —
             * each row's setup is its reproducer, and one row's failed
             * control must not mask the states behind it.
             */
            try {
                ($state['extra'])($scratch, $zipPath);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                return array('class' => 'FAIL', 'why' => 'the CLEAN state\'s extra control failed: ' . $e->getMessage());
            }
        }

        return array('class' => 'PASS', 'why' => '');
    }

    /**
     * The two seam-driven forced-failure states (t31-r5's zip-add and
     * close rows): libzip defers entry reads to close(), so no external
     * input makes addFile() return false or close() fail inside a real
     * run — the failure channels are driven in the production staging
     * context instead (the t31-r3-16 pinned-seam precedent), and the
     * refusal is asserted against the same previous-good contract.
     *
     * @return void
     */
    public function testTheForcedArchiveFailureChannelsRefuseLoudlyAtTheStagingPath(): void
    {
        $scratch = $this->makeScratchRepo('forced-archive-failures');
        try {
            $seedZip = WpConnectorsBuild::buildPlugin($scratch['plugin'], $scratch['dist']);
            $zipBefore = (string) file_get_contents($scratch['zip']);
            $sidecarBefore = (string) file_get_contents($scratch['zip'] . '.sha256');
            $manifestBefore = (string) file_get_contents($scratch['dist'] . '/checksums.txt');
            $this->assertFileExists($seedZip);

            // (a) zip-ADD failure: a staged source that vanishes before
            // its add. The pin is the CONTRACT, not libzip's reporting
            // detail (OCR round 6, t31-ocr6-7): builds differ on
            // whether addFile() stats at add (returns false here) or
            // defers the read to close() — build.php's own
            // closeArchiveOrThrow comment asserts the deferred shape —
            // so the pinned fact is that the full add+close sequence
            // NEVER silently succeeds: the failure is observable by
            // close time at the latest, which is exactly the channel
            // the production checked add ('cannot add … to') and the
            // checked close ('cannot finalize …') each turn into the
            // build's refusal. Green on both libzip behaviors.
            $staged = $scratch['root'] . '/staged-source.php';
            file_put_contents($staged, "<?php\n// staged\n");
            $addTemp = $scratch['dist'] . '/.add-probe.zip';
            $zip = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $zip->open($addTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr10-16).',
                    $addTemp,
                    var_export($opened, true)
                )
            );
            $this->assertTrue($zip->addFile($staged, 'staged-source.php'));
            unlink($staged);
            $addReportedFailure = true !== @$zip->addFile($staged, 'vanished-source.php');
            // The @ is the whole suppression (t31-ocr6-16, the round's
            // own verifier pass): an error-handler capture here was
            // dead code — collected, never asserted — and redundant
            // beside @ (the suite's warning conversion ignores calls
            // made under it). The boolean verdict is the pin.
            $closeReportedFailure = true !== @$zip->close();
            $this->assertTrue(
                $addReportedFailure || $closeReportedFailure,
                'A vanished staged source must fail the add+close sequence by close time at the latest — whichever libzip build shape (stat-at-add or deferred read) this runtime rides, the sequence never silently succeeds.'
            );
            @unlink($addTemp);

            // (b) forced CLOSE failure: a staged source unreadable at
            // read time (the deterministic external spelling on this
            // runtime) makes close() return false — driven through the
            // production seam, which must refuse naming the archive.
            //
            // Root-runner skip (t31-ocr4-1), hoisted ABOVE the archive's
            // creation (t31-ocr12-4): uid 0 reads the staged source
            // through mode 0000, close() succeeds, and the refusal below
            // never fires. The guard once fired MID-LEG — after open() +
            // addFile() — and markTestSkipped()'s throw left the archive
            // handle OPEN while the owning finally's rrmdir deleted its
            // destination underneath it: a teardown race. The skip now
            // precedes the open; no handle exists at skip time.
            $this->skipChmod0000LegOnRootRunner('the forced-close chmod-0000 leg of the staging-path pin');
            $closeTemp = $scratch['dist'] . '/.close-probe.zip';
            file_put_contents($staged, "<?php\n// staged\n");
            $zip = new ZipArchive();
            $this->assertTrue(
                true === ($opened = $zip->open($closeTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE)),
                sprintf(
                    '%s cannot be opened for zip writing (ZipArchive::open returned %s — a truthy ER_* int must not pass this gate, t31-ocr10-16).',
                    $closeTemp,
                    var_export($opened, true)
                )
            );
            $this->assertTrue($zip->addFile($staged, 'staged-source.php'));
            chmod($staged, 0000);
            $finalize = new ReflectionMethod(WpConnectorsBuild::class, 'closeArchiveOrThrow');
            $refused = null;
            /*
             * The capture is the SILENCER, never the requirement (OCR
             * round 26, t31-ocr26-10): libzip's read warning on the
             * chmod-0000 source is ENGINE-OPTIONAL — some builds emit
             * none — and the non-empty-capture assertion once made it a
             * hard premise of the leg (red on every quiet-zip host for
             * a probe whose REFUSAL — the null assertion below — had
             * already fired). The refusal owns the contract; the
             * handler stays so a chatty build's warning cannot leak
             * into the suite's warning conversion either way.
             */
            set_error_handler(static function (): bool {
                return true;
            });
            try {
                $finalize->invoke(null, $zip, 'close-probe.zip');
            } catch (RuntimeException $e) {
                $refused = $e->getMessage();
            } finally {
                restore_error_handler();
            }
            chmod($staged, 0644);
            $this->assertNotNull($refused, 'A failed finalization must refuse the build, never fall through.');
            $this->assertStringContainsString('cannot finalize', $refused);
            @unlink($closeTemp);

            // The forced failures happened at staging paths: the seeded
            // artifact set is byte-untouched and nothing landed.
            $this->assertSame($zipBefore, (string) file_get_contents($scratch['zip']));
            $this->assertSame($sidecarBefore, (string) file_get_contents($scratch['zip'] . '.sha256'));
            $this->assertSame($manifestBefore, (string) file_get_contents($scratch['dist'] . '/checksums.txt'));
        } finally {
            @chmod($scratch['root'] . '/staged-source.php', 0644);
            WpHarness::releaseScratch($scratch['root']);
        }
    }

    /**
     * Assembles the scratch repo one state runs against.
     *
     * @param string $state_id Row label (unique directory name).
     * @return array<string, string> root/plugin/dist/shared/zip paths.
     */
    private function makeScratchRepo(string $state_id): array
    {
        /*
         * The derivation needle is validated BEFORE anything exists
         * (OCR round 25, t31-ocr25-7): the needle-miss throw used to
         * fire AFTER the mkdir/copyTree work — a fixture drift left a
         * half-built scratch tree behind the refusal, and every caller
         * invokes this maker BEFORE its own try/finally, so the tree
         * leaked. The fixture main file is read at its source (the
         * copy below copies it verbatim, so the bytes the needle is
         * judged on are the bytes that land).
         */
        /*
         * The fixture resolution gets its OWN refusal channel (t31-ocr25
         * rd-1): a swallowed realpath() false read as a needle miss on
         * the next line — the environment impersonation the ocr25-8
         * class close kills, one file over.
         */
        $fixture = realpath(__DIR__ . '/fixtures/plugins/example-connector');
        if (false === $fixture) {
            throw new RuntimeException('battery scratch: the fixture plugin tree does not resolve — an environment problem (a broken checkout, an open_basedir wall), never a needle drift; nothing was created.');
        }
        $mainFile = $fixture . '/example-connector.php';
        if (! is_file($mainFile)) {
            throw new RuntimeException("battery scratch: the fixture main file is missing at {$mainFile} — an environment problem, never a needle drift; nothing was created.");
        }
        $main = (string) file_get_contents($mainFile);
        /*
         * The artifact name derives from the FIXTURE at runtime (OCR
         * round 11, t31-ocr11-7): build.php names it
         * connectors-{slug}-{Version header}.zip, and the battery once
         * pinned the literal 'connectors-example-connector-0.1.0.zip'
         * — a fixture version bump reddened five-plus rows as phantom
         * build defects. The derivation rides the file's own needle
         * regex (the version-header-traversal row's, t31-ocr6-8): a
         * needle miss fails loudly AT THE DERIVATION, never runs a
         * no-op expectation as a phantom verdict.
         */
        if (1 !== preg_match('/Version:([ \t]++)(\S++)/', $main, $header)) {
            throw new RuntimeException('battery scratch: the fixture main file carries no Version header — the artifact-name derivation needle drifted (t31-ocr11-7); nothing was created.');
        }

        /*
         * Temp-rooted and RANDOM-suffixed (OCR round 11, t31-ocr11-16):
         * the fixed name under the repo's dist/ made two CONCURRENT
         * suite runs collide on one scratch tree — run B's rrmdir of
         * the "stale" root deleted run A's in-flight battery. Every
         * other scratch maker in the suite rides temp+suffix
         * (canSymlink()'s probe is the doctrine's own model); the
         * state id keeps the row identifiable in /tmp, the suffix
         * makes the tree one run's own.
         */
        $root = sys_get_temp_dir() . '/wpct-battery-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($state_id)) . '-' . bin2hex(random_bytes(4));
        if (is_dir($root)) {
            WpHarness::releaseScratch($root);
        }
        /*
         * The maker owns its OWN cleanup (OCR round 26, t31-ocr26-11):
         * every caller invokes makeScratchRepo() BEFORE its own
         * try/finally, so a throw from any CREATION step below once
         * leaked the half-built wpct-battery-* tree in system temp —
         * the ocr25-7 needle close moved the VALIDATION-phase refusal
         * before the first mkdir; this catch is the creation-phase
         * twin. The reclaim rides the throw's own way out, and the
         * original refusal keeps propagating.
         */
        try {
            mkdir($root . '/shared/src/Clock', 0755, true);
            mkdir($root . '/dist', 0755, true);
            file_put_contents(
                $root . '/shared/src/Clock/ClockInterface.php',
                "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n"
            );
            file_put_contents(
                $root . '/shared/src/GrantInterface.php',
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface GrantInterface {}\n"
            );

            $plugin = $root . '/plugin/example-connector';
            /*
             * The fixture tree rides the ONE scratch-tree copy owner
             * (OCR round 6, t31-ocr6-5): this inline twin (str_replace
             * prefix strip, no isLink() guard) had re-grown the exact two
             * defect shapes ocr4-2/-3 killed in WpHarness::copyTree() —
             * every-occurrence stripping on a nested same-name segment and
             * silent link-following — in the one place the battery's own
             * verdicts would never reach. copyTree() also owns the
             * root-exists guarantee the old pre-create carried (it mkdirs
             * each target's dirname recursively, order-free — t31-r6-7's
             * readdir-order concern was the inline loop's own).
             */
            WpHarness::copyTree($fixture, $plugin);
            file_put_contents($plugin . '/build.json', "{\"embed_shared\": true}\n");

            return array(
                'root' => $root,
                'plugin' => $plugin,
                'dist' => $root . '/dist',
                'shared' => $root . '/shared/src',
                'zip' => $root . '/dist/connectors-example-connector-' . $header[2] . '.zip',
            );
        } catch (\Throwable $creation_refusal) {
            WpHarness::releaseScratch($root);

            throw $creation_refusal;
        }
    }

    /**
     * One artifact member's byte snapshot, or a marker for its absence
     * or unreadability (the manifest-unreadable state must compare
     * like-for-like across the run).
     *
     * @param string $path Absolute member path.
     * @return string|null Bytes, a marker, or null when absent.
     */
    private function readMemberOrMarker(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return false === $bytes ? '__UNREADABLE__' : $bytes;
    }

    /**
     * Fix-round pin (t31-r9-4, extended by t31-r10-3 and t31-r12-11):
     * the file-scope error_reporting(E_ALL) + ini_set('display_errors',
     * '1') ran in every process that REQUIRED these files, not just
     * the CLI run — and this suite is one of the requirers (build.php
     * and inspect-artifact.php load for the class and the inspector
     * function), so a php-cli host with display_errors off had it
     * flipped on process-wide just by running the tests (reproduced:
     * `php -d display_errors=0 -r 'require bin/build.php; …'` printed
     * 1). The glm17-16 class, already fixed in check-conventions.php
     * but unguarded here — the two files grew their require-side
     * consumers (this suite) after that fix. t31-r10-3 adds
     * lint-php.php to the pin: its file scope carried the diagnostics
     * AND the whole walk AND the exit() — a require ran the lint.
     * t31-r12-11 folds the four in-diff scripts' guard + diagnostics
     * into the ONE helper (wp_connectors_cli_entry()) and adds
     * check-conventions.php to the require side. t31-ocr3-8 closes
     * the sweep: scan-secrets.php — the last script wearing the
     * file-top diagnostics — rides the helper too, and every leg above
     * extends to all five entry scripts. Pinned through a
     * child process because the in-process ini state belongs to
     * PHPUnit's own runner, not to this test.
     */
    public function testRequiringTheBuildAndInspectFilesNeverFlipsDisplayErrors(): void
    {
        /*
         * The exec-capability guard (t31-ocr18-2, the t31-ocr16-12
         * doctrine over this consumer — five spawn sites, one guard):
         * every leg of this pin (the display_errors require, both GPC
         * guards, the forged-name refusal, the scan-secrets sweep)
         * verdicts through a spawned engine, and on a
         * disable_functions host the first spawn was an
         * undefined-function \Error before a single verdict.
         */
        if (! self::canSpawnChildren()) {
            $this->markTestSkipped('This host has exec/escapeshellarg in disable_functions — the require-side legs cannot run (all five entry-script probes spawn child processes).');
        }

        /*
         * The five entry-script paths are asserted resolved BEFORE the
         * embed (t31-ocr25 rd-1, the ocr25-8 class census): a
         * realpath() false (a broken checkout, an open_basedir wall)
         * once embedded `require false;` straight into the child —
         * the fatal then read as the entry scripts' own defect, an
         * environment problem wearing the pin's subject.
         */
        $entryScriptExports = array();
        foreach (array('/../bin/build.php', '/../bin/inspect-artifact.php', '/../bin/lint-php.php', '/../bin/check-conventions.php', '/../bin/scan-secrets.php') as $entryScript) {
            $resolved = realpath(__DIR__ . $entryScript);
            $this->assertNotFalse($resolved, "The entry script {$entryScript} must resolve before the child embed — a realpath() false is an environment problem, never the entry scripts' own defect.");
            $entryScriptExports[] = var_export($resolved, true);
        }
        $script = 'require ' . implode('; require ', $entryScriptExports) . ';'
            . ' echo ini_get("display_errors");';
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($script) . ' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, 'The require itself must not error.');
        $this->assertSame(
            array('0'),
            $output,
            'Requiring bin/build.php, bin/inspect-artifact.php, bin/lint-php.php, bin/check-conventions.php, and bin/scan-secrets.php into a host process must leave display_errors exactly as the host set it and must not run any walk — the diagnostics and the walks belong to the CLI guard, not the file scope.'
        );

        /*
         * Verifier-round pin (t31-r12-17, the correctness lens): the
         * guard read $_SERVER['argv'], which is UNPOPULATED under a
         * variables_order ini without "S" — the guard answered false
         * and every entry script became a SILENT EXIT-0 NO-OP
         * (reproduced: the inspector printed nothing and exited 0, its
         * ACCEPTED contract, instead of inspecting). Under GPC the
         * guard must fire exactly as under the default ini: the
         * inspector's missing-file refusal still exits 2, and the lint
         * still checks its files and says so.
         */
        exec(escapeshellarg(PHP_BINARY) . ' -d variables_order=GPC ' . escapeshellarg(realpath(__DIR__ . '/../bin/inspect-artifact.php')) . ' /nonexistent-zip.zip 2>&1', $gpcOutput, $gpcExit);
        $this->assertSame(2, $gpcExit, 'Under variables_order=GPC the CLI guard still fires — never a silent exit-0 no-op.');
        $this->assertStringContainsString('no such file', implode("\n", $gpcOutput));

        /*
         * OCR round 11 (t31-ocr11-25): the guard's no-such-file line
         * interpolated the RAW caller path — basename() spellings
         * already rode the printable seam (ocr11-13), and this
         * full-path line is the same class one screen above: driven by
         * the lens, a newline in the argument forged a
         * verdict-lookalike line in the inspector's own STDERR. The
         * line rides the seam now (the forged text stays on the
         * refusal's own line, every control a space).
         */
        $forgedArg = "/no-such\ninspect: totally-legit.zip ACCEPTED (0 violation(s))\n.zip";
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(realpath(__DIR__ . '/../bin/inspect-artifact.php')) . ' ' . escapeshellarg($forgedArg) . ' 2>&1', $forgedOutput, $forgedExit);
        $this->assertSame(2, $forgedExit, 'The forged-name refusal still exits 2.');
        $this->assertStringNotContainsString("\ninspect: totally-legit", implode("\n", $forgedOutput), 'A newline in the caller path cannot START a verdict line — the guard line rides the printable seam.');

        exec(escapeshellarg(PHP_BINARY) . ' -d variables_order=GPC ' . escapeshellarg(realpath(__DIR__ . '/../bin/lint-php.php')) . ' 2>&1', $gpcLintOutput, $gpcLintExit);
        $this->assertSame(0, $gpcLintExit);
        $this->assertStringContainsString('file(s) checked', implode("\n", $gpcLintOutput), 'The lint still runs its walk under GPC.');

        /*
         * OCR round 3 (t31-ocr3-8): scan-secrets.php rides the helper
         * too — the GPC leg proves its guard still fires (the scan runs
         * and says so, never a silent exit-0 no-op), closing the
         * t31-r12-11 sweep at all five entry scripts. The scan TARGET
         * is anchored like every sibling spawn (t31-ocr20-5): this was
         * the one CWD-relative path handed to a spawned script — the
         * scan silently walked NOTHING when the runner started outside
         * the repo root, its 'finding(s)' line vacuously green.
         */
        exec(escapeshellarg(PHP_BINARY) . ' -d variables_order=GPC ' . escapeshellarg(realpath(__DIR__ . '/../bin/scan-secrets.php')) . ' ' . escapeshellarg(realpath(__DIR__ . '/fixtures')) . ' 2>&1', $gpcScanOutput, $gpcScanExit);
        $this->assertSame(0, $gpcScanExit, 'Under variables_order=GPC the scanner\'s CLI guard still fires — never a silent exit-0 no-op.');
        $this->assertStringContainsString('finding(s)', implode("\n", $gpcScanOutput), 'The scan still runs its walk under GPC.');

        // The helper is the single spelling (t31-r12-11; all five since
        // t31-ocr3-8): every entry script consumes
        // wp_connectors_cli_entry() and none carries a hand-rolled copy
        // of the guard anymore.
        foreach (array('build.php', 'inspect-artifact.php', 'lint-php.php', 'check-conventions.php', 'scan-secrets.php') as $entry) {
            $source = (string) file_get_contents(__DIR__ . '/../bin/' . $entry);
            $this->assertStringContainsString('wp_connectors_cli_entry(__FILE__)', $source, "{$entry} consumes the ONE CLI-entry helper.");
            $this->assertStringNotContainsString("realpath(\$argv[0]) === __FILE__", $source, "{$entry} carries no hand-rolled guard copy.");
            $this->assertStringNotContainsString("ini_set('display_errors'", $source, "{$entry} carries no hand-rolled diagnostics copy.");
        }
    }

    /**
     * OCR round 11 (t31-ocr11-15): the args helper's list<string>
     * contract holds OUTSIDE a CLI process too — a web/fpm SAPI
     * leaves the auto-global undefined, and the bare return raised
     * an "Undefined $argv" warning and handed back null. Pinned
     * in-process by unbinding the auto-global (the honest spelling
     * of "no argv" a test can produce under the CLI runner), with
     * the runner's own vector restored afterward.
     *
     * OCR round 31 (t31-ocr31-9): the pin once rode INSIDE its
     * exec-gated sibling — a plain $GLOBALS read that spawns no
     * child, silently skipped on every disable_functions host
     * alongside the spawn-bearing legs. Its own method rides no
     * capability gate: the in-process contract answers everywhere
     * the suite runs, whatever the host does to exec.
     */
    public function testTheArgsHelperAnswersTheEmptyListOutsideACliProcess(): void
    {
        $hadArgv = array_key_exists('argv', $GLOBALS);
        $savedArgv = $hadArgv ? $GLOBALS['argv'] : null;
        unset($GLOBALS['argv']);
        try {
            $this->assertSame(array(), wp_connectors_cli_args(), 'With no argv bound, the helper answers the empty list — the list<string> contract holds outside a CLI process, never an "Undefined $argv" warning and a null.');
        } finally {
            if ($hadArgv) {
                $GLOBALS['argv'] = $savedArgv;
            }
        }
    }
}
