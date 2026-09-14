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
        $failures = array();
        $run = array( 'CLEAN' => 0, 'LOUD' => 0 );

        foreach ($this->states() as $state_id => $state) {
            ++$run[$state['expect']];
            $verdict = $this->runState($state_id, $state);
            if ('FAIL' === $verdict['class']) {
                $failures[] = sprintf("[%s] expected %s: %s", $state_id, $state['expect'], $verdict['why']);
            }
        }

        // Non-vacuity: every expected class actually executed.
        $this->assertGreaterThan(0, $run['CLEAN'], 'The battery must exercise at least one CLEAN state.');
        $this->assertGreaterThan(0, $run['LOUD'], 'The battery must exercise at least one LOUD state.');

        $this->assertSame(
            array(),
            $failures,
            "The build-seam invariant failed for the enumerated states (each row's setup is its reproducer):\n - "
            . implode("\n - ", $failures)
        );
    }

    /**
     * The adversarial state table (exhaustive for the round's charter).
     *
     * Each row: 'expect' ('CLEAN' or 'LOUD'), 'apply' (mutates the
     * seeded scratch), an optional refusal-message fragment the LOUD
     * row must carry, and optional CLEAN-state extra assertions.
     *
     * @return array<string, array{expect: string, apply: callable, fragment?: string, extra?: callable}>
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
                'expect' => 'CLEAN',
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
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    chmod($scratch['shared'] . '/Clock/ClockInterface.php', 0000);
                },
                'fragment' => 'unreadable shared source',
            ),
            'shared-source-whitespace-only' => array(
                // t31-r5-2's empty half: rewriteSharedNamespace('') returns
                // '' without throwing — the 0-byte ship without a read
                // failure at all.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    file_put_contents($scratch['shared'] . '/GrantInterface.php', " \n\t\n");
                },
                'fragment' => 'no bytes',
            ),
            'plugin-source-unreadable' => array(
                // t31-r5-2's other collection point: an unreadable plugin
                // file shipped a 0-byte zip entry at exit 0.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    chmod($scratch['plugin'] . '/src/Provider/ExampleProvider.php', 0000);
                },
                'fragment' => 'cannot copy',
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
                'expect' => 'LOUD',
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
                    unlink($scratch['dist'] . '/connectors-example-connector-0.1.0.zip.sha256');
                    mkdir($scratch['dist'] . '/connectors-example-connector-0.1.0.zip.sha256');
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
                    unlink($scratch['dist'] . '/connectors-example-connector-0.1.0.zip');
                    mkdir($scratch['dist'] . '/connectors-example-connector-0.1.0.zip');
                },
                'fragment' => 'not a regular file',
            ),
            'version-header-traversal' => array(
                // t31-r5-15: the header's bytes reached the artifact
                // filename and staging paths unchecked — a traversal
                // spelling staged the archive and sidecar OUTSIDE dist/.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    $mainPath = $scratch['plugin'] . '/example-connector.php';
                    $traversal = '0.1/../../../vsec-precious';
                    file_put_contents($mainPath, str_replace(array('Version:           0.1.0', "'0.1.0'"), array("Version:           {$traversal}", "'{$traversal}'"), (string) file_get_contents($mainPath)));
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
            ),
            'zip-staging-path-blocked' => array(
                // t31-r5-S: leftover junk at the (PID-unique) staging
                // path is a production failure, not a landing one.
                'expect' => 'LOUD',
                'apply' => static function (array $scratch): void {
                    mkdir($scratch['dist'] . '/.connectors-example-connector-0.1.0.zip.tmp-' . getmypid());
                },
                'fragment' => 'cannot create the staging archive',
            ),
        );
    }

    /**
     * Runs one state and classifies the observation against the invariant.
     *
     * @param string                                                $state_id Row label (diagnostics).
     * @param array{expect: string, apply: callable, fragment?: string, extra?: callable} $state The row.
     * @return array{class: string, why: string} 'PASS' or 'FAIL' with the reason.
     */
    private function runState(string $state_id, array $state): array
    {
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
            ($state['apply'])($scratch);
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
            if (is_dir($scratch['dist'] . '/.stage-example-connector')) {
                return array('class' => 'FAIL', 'why' => 'the refusal left the staging tree behind');
            }

            return array('class' => 'PASS', 'why' => '');
        } finally {
            if (is_dir($scratch['shared'] . '/Clock')) {
                @chmod($scratch['shared'] . '/Clock/ClockInterface.php', 0644);
            }
            @chmod($scratch['plugin'] . '/src/Provider/ExampleProvider.php', 0644);
            WpHarness::rrmdir($scratch['root']);
        }
    }

    /**
     * The CLEAN half of the invariant: exit 0 means the artifact is
     * complete and sound — never merely present.
     *
     * @param array<string, string>                    $scratch      The scratch repo map.
     * @param list<string>                             $seedNames    The seeded build's entry names.
     * @param string                                   $seedManifest The seeded manifest bytes.
     * @param array{expect: string, apply: callable, fragment?: string, extra?: callable} $state The row.
     * @return array{class: string, why: string}
     */
    private function classifyClean(array $scratch, array $seedNames, string $seedManifest, array $state): array
    {
        $zipPath = $scratch['zip'];
        if (! is_file($zipPath)) {
            return array('class' => 'FAIL', 'why' => 'the successful run left no zip');
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
            if (is_array($stat) && $stat['size'] <= 0) {
                $zip->close();
                return array('class' => 'FAIL', 'why' => "entry {$stat['name']} shipped empty");
            }
        }
        $zip->close();

        // Soundness: every PHP entry parses after independent
        // extraction (the extension judgment rides the one owner).
        $extract = $scratch['root'] . '/.extract';
        mkdir($extract, 0755, true);
        try {
            $zip = new ZipArchive();
            $zip->open($zipPath);
            $zip->extractTo($extract);
            $zip->close();
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
        } finally {
            WpHarness::rrmdir($extract);
        }

        // Soundness includes the RELEASE gate's verdict: the artifact the
        // doctrine ships is the artifact the inspector accepts (added with
        // t31-r5-10 — the round's own vocabulary-drift finding was exactly
        // a build-clean/inspect-rejected contradiction).
        $inspect = $scratch['root'] . '/.battery-inspect';
        $this->assertSame(
            array(),
            wp_connectors_inspect_artifact($zipPath, $inspect),
            'A CLEAN artifact must pass the inspector — build and inspect give ONE verdict.'
        );

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
            ($state['extra'])($scratch, $zipPath);
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
            // its add is the one spelling addFile() reports AT add time
            // (empirically: returns false, with libzip's own warning) —
            // the production loop's checked add turns exactly this
            // return into the 'cannot add' refusal, at the staging
            // path, before anything lands.
            $staged = $scratch['root'] . '/staged-source.php';
            file_put_contents($staged, "<?php\n// staged\n");
            $addTemp = $scratch['dist'] . '/.add-probe.zip';
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($addTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $this->assertTrue($zip->addFile($staged, 'staged-source.php'));
            unlink($staged);
            $this->assertFalse(
                @$zip->addFile($staged, 'vanished-source.php'),
                'A vanished staged source must report false at add time — the failure channel the production add loop checks.'
            );
            // The close is cleanup only (it fails on the unlinked source —
            // the deferred-read behavior the close row below drives).
            @$zip->close();
            @unlink($addTemp);

            // (b) forced CLOSE failure: a staged source unreadable at
            // read time (the deterministic external spelling on this
            // runtime) makes close() return false — driven through the
            // production seam, which must refuse naming the archive.
            $closeTemp = $scratch['dist'] . '/.close-probe.zip';
            file_put_contents($staged, "<?php\n// staged\n");
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($closeTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $this->assertTrue($zip->addFile($staged, 'staged-source.php'));
            chmod($staged, 0000);
            $finalize = new ReflectionMethod(WpConnectorsBuild::class, 'closeArchiveOrThrow');
            $refused = null;
            $warnings = array();
            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                $warnings[] = $errstr;

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
            $this->assertNotSame(array(), $warnings, 'The probe must drive a REAL close() failure (libzip\'s own read warning is the evidence).');
            @unlink($closeTemp);

            // The forced failures happened at staging paths: the seeded
            // artifact set is byte-untouched and nothing landed.
            $this->assertSame($zipBefore, (string) file_get_contents($scratch['zip']));
            $this->assertSame($sidecarBefore, (string) file_get_contents($scratch['zip'] . '.sha256'));
            $this->assertSame($manifestBefore, (string) file_get_contents($scratch['dist'] . '/checksums.txt'));
        } finally {
            @chmod($scratch['root'] . '/staged-source.php', 0644);
            WpHarness::rrmdir($scratch['root']);
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
        $root = __DIR__ . '/../dist/.battery-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($state_id));
        if (is_dir($root)) {
            WpHarness::rrmdir($root);
        }
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
        // The plugin root exists before the copy loop rides it
        // (t31-r6-7): the loop's first mkdir came from a subdirectory
        // entry, so a readdir order yielding a root FILE first broke
        // the copy — directory-entry order is not a contract.
        mkdir($plugin, 0755, true);
        $fixtureRoot = realpath(__DIR__ . '/fixtures/plugins/example-connector');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $plugin . '/' . str_replace($fixtureRoot . '/', '', $item->getPathname());
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
        file_put_contents($plugin . '/build.json', "{\"embed_shared\": true}\n");

        return array(
            'root' => $root,
            'plugin' => $plugin,
            'dist' => $root . '/dist',
            'shared' => $root . '/shared/src',
            'zip' => $root . '/dist/connectors-example-connector-0.1.0.zip',
        );
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
     * The entry names of a zip, in zip order.
     *
     * @param string $zipPath Absolute zip path.
     * @return list<string> Entry names.
     */
    private function zipEntryNames(string $zipPath): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath), "The built zip must open: {$zipPath}");
        $names = array();
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }
}
