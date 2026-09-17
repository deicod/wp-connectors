<?php
/**
 * Artifact inspector: validates a built plugin zip independently.
 *
 *   php bin/inspect-artifact.php dist/connectors-example-connector-0.1.0.zip
 *
 * Rejects (non-zero exit):
 *   - zips without exactly one top-level plugin directory (including zips
 *     whose sole top-level entry is a FILE — reported as a violation, never
 *     a crash, with the temp extraction tree cleaned up on every path),
 *   - top-level names that are not real slugs or entries with '..' path
 *     segments (extraction would write outside the work dir),
 *   - more than one main plugin file with a Plugin Name header,
 *   - missing/invalid plugin headers, version-constant mismatch, wrong text
 *     domain (same rules as bin/check-conventions.php),
 *   - repo-relative includes or shared/ references (self-containment),
 *   - development files inside the zip (vendor/, tests/, composer files...)
 *     on PLUGIN-OWNED paths — the generated <slug>/src/Shared/ subtree is
 *     exempt from that vocabulary (t31-r5-5: shared/src has no exclusion
 *     concepts; build and inspect give ONE verdict), while traversal,
 *     syntax, secret, and self-containment checks still judge it,
 *   - any PHP file that does not pass `php -l` after extraction.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/plugin-tools.php';
require_once __DIR__ . '/lib/secret-scanner.php';

/**
 * Inspects a zip archive and returns violations.
 *
 * @param string $zipPath  Absolute path to the zip.
 * @param string $workDir  Directory to extract into (created, then removed).
 * @return list<string> Violation messages (empty = artifact accepted).
 */
function wp_connectors_inspect_artifact($zipPath, $workDir)
{
    $violations = array();
    $zip = new ZipArchive();
    if (true !== $zip->open($zipPath)) {
        return array( sprintf('inspect: cannot open %s as a zip archive.', basename($zipPath)) );
    }

    // Forbidden entries are matched on whole path segments/files, so entries
    // like "assets/latest/x.png" are never false-rejected. The vocabulary
    // and its CASE FOLDING are the ONE shared development-entry judgment
    // (t31-r5-10, t31-r6-3) — the builder's collector and this inspector
    // cannot drift on what a dev entry is (they had: the dotless
    // 'phpunit.cache' shipped through builds the inspector rejected; and
    // the byte-exact comparison let case variants — 'Tests/', 'Build.json',
    // 'VENDOR' — ship AND pass inspection, both gates agreeing on the wrong
    // verdict). A basename is a segment, so one any-part check covers both
    // the former segment and file lists.

    $topDirs = array();
    $sawDirectoryEntry = false;
    $traversalEntries = array();
    $seenEntryNames = array();
    $seenFoldedNames = array();
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = (string) $zip->getNameIndex($i);
        $parts = explode('/', $name);
        /*
         * The duplicate-entry fence (verifier round t31-r12-15, the
         * security lens's HIGH): a hostile zip may carry one entry
         * name MORE THAN ONCE — the ZipArchive WRITER refuses to
         * produce that shape (same-name writes replace), but the
         * READER counts every copy and extractTo() keeps only the
         * LAST: the extraction returns TRUE, every content check below
         * judges the landed bytes, and the first copy's bytes (the
         * webshell, the live key) are judged by nobody — ACCEPTED at 0
         * violations (reproduced on a real built zip). Byte-exact AND
         * case-folded duplicates refuse: on a case-insensitive
         * extraction target ('Assets/logo.png' beside
         * 'assets/logo.png') one silently overwrites the other — the
         * r6 deferred collision class's INSPECTOR half, consumed here;
         * the builder-side fence over the collected entry set (and its
         * directory prefixes) stays the r6 line's own round.
         */
        $folded_name = wp_connectors_ascii_lower($name);
        if (isset($seenEntryNames[$name])) {
            $violations[] = sprintf(
                'inspect: zip carries the entry name "%s" more than once — extraction keeps only one copy, so the other bytes are judged by nobody.',
                wp_connectors_printable($name)
            );
        } else {
            $seenEntryNames[$name] = true;
        }
        if (isset($seenFoldedNames[$folded_name])) {
            $violations[] = sprintf(
                'inspect: zip carries case-fold duplicate entry names ("%s") — on a case-insensitive extraction target one silently overwrites the other.',
                wp_connectors_printable($name)
            );
        } else {
            $seenFoldedNames[$folded_name] = true;
        }
        $topDirs[ $parts[0] ] = true;
        // An entry with a second path segment proves the top-level name is
        // (also) a directory; a zip of only "file.php"-style entries has a
        // FILE at the top level, which directory-based checks below cannot
        // traverse (RecursiveDirectoryIterator would throw).
        if (count($parts) > 1) {
            $sawDirectoryEntry = true;
        }
        // No entry may carry a '..' path segment anywhere: extracting such
        // an entry writes outside the work dir (host traversal). Backslash
        // separators are rejected too — harmless literal characters on Linux,
        // but path separators under PHP on Windows. (Applies to EVERY entry,
        // embedded or not — this is host safety, not a tree doctrine.)
        if (in_array('..', $parts, true) || strpos($name, '\\') !== false) {
            $traversalEntries[] = $name;
        }
        /*
         * The forbidden-entry vocabulary is a PLUGIN-OWNED-path concept
         * (review round t31-r5-5): the generated <slug>/src/Shared/
         * subtree is embedded from shared/src, which has NO exclusion
         * concepts (the t31-r3-4 doctrine — every PHP source ships,
         * wherever it lives, and the architecture sweep gates that
         * tree) — so a shared source under shared/src/tools/ shipped by
         * design was rejected here, and build and inspect gave
         * contradictory verdicts no CI run could satisfy (reproduced).
         * ONE verdict now: the segment/file check exempts the embedded
         * subtree. No attack path opens: a plugin hiding its own code
         * under src/Shared/tools/ cannot reach a zip through the build
         * (collectFiles drops excluded segments; a plugin-owned Shared
         * path colliding with an embed destination refuses the build,
         * t31-r5-1), and the embedded copy is generated from
         * sweep-gated shared/src. Every OTHER check — traversal,
         * headers, syntax (php -l), secrets, self-containment — still
         * judges every entry, embedded or not.
         */
        /*
         * The embed-territory judgment rides the ONE owner
         * (t31-r12-10, corrected t31-r12-16):
         * wp_connectors_is_embed_destination() matches the CANONICAL
         * prefix the builder generates — a case-variant spelling is
         * foreign (the builder's case-insensitive fence refuses the
         * whole tree that carries one), so its segments judge by the
         * vocabulary below, per the t31-r12-3 signal doctrine.
         */
        /*
         * The exemption requires the top-level name to NOT be a
         * development entry itself (t31-ocr10-8): the name is
         * archive-controlled, and a hostile zip whose single top-level
         * dir IS a dev entry ('vendor/src/Shared/…' — driven) exempted
         * everything under it from dev-entry classification, the
         * vocabulary judged through its ONE owner. The embed territory
         * only exists under a REAL plugin slug.
         */
        $isEmbeddedShared = ! wp_connectors_is_development_entry($parts[0]) && wp_connectors_is_embed_destination($name, $parts[0]);
        // Segment check (whole path components; a basename is one),
        // judged by the ONE comparison owner — case-insensitively
        // (t31-r6-3), the same fold the builder's collector excludes
        // by, so the two gates give ONE verdict in every casing.
        $isForbidden = false;
        if (! $isEmbeddedShared) {
            foreach ($parts as $part) {
                if (wp_connectors_is_development_entry($part)) {
                    $isForbidden = true;

                    break;
                }
            }
        }
        if ($isForbidden) {
            /*
             * Every verdict line that interpolates archive-controlled
             * text renders through the ONE printable seam (round
             * t31-r13-1, the security lens, reproduced): an entry name
             * survives getNameIndex() BYTE-EXACT — newline included —
             * on this runtime (neither ZipArchive side sanitizes
             * control bytes in names; probed again this round, and the
             * r12 ledger's sanitization premise corrected), so a raw
             * interpolation printed a FORGED verdict line beside the
             * real REJECTED one (the driver's repro: entry name
             * '…/vendor/x\ninspect: FORGED-LINE-ACCEPTED (0
             * violations)\n.php'). The dev-entry line here, the
             * top-dir list, the invalid-slug and traversal refusals,
             * the not-a-plugin-directory refusal, the php -l failure,
             * and the secret findings all interpolate entry-derived
             * bytes and all ride the seam now; the printable body
             * still names the offending entry.
             */
            $violations[] = sprintf('inspect: zip contains development entry "%s".', wp_connectors_printable($name));
        }
    }
    $zip->close();

    if (count($topDirs) !== 1) {
        $violations[] = sprintf(
            'inspect: zip must contain exactly one top-level plugin directory, found: %s.',
            implode(', ', array_map('wp_connectors_printable', array_keys($topDirs)))
        );

        return $violations;
    }
    $slug = (string) array_key_first($topDirs);
    if ($slug === '' ) {
        $violations[] = 'inspect: zip has an empty top-level directory name.';

        return $violations;
    }
    // The root name must be a real slug BEFORE anything is built from it:
    // '../payload.php'-style entries make $workDir . '/..' point at HOST
    // paths outside the extraction dir, so every check below would traverse
    // (and extraction would write) outside the work dir.
    if ($slug === '.' || $slug === '..' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $slug)) {
        // The name that FAILED the grammar prints through the seam (see
        // the dev-entry site): pre-grammar, its bytes are unjudged.
        $violations[] = sprintf('inspect: invalid top-level plugin directory name "%s".', wp_connectors_printable($slug));

        return $violations;
    }
    if ($traversalEntries !== array()) {
        foreach ($traversalEntries as $name) {
            $violations[] = sprintf('inspect: zip entry "%s" escapes the extraction directory.', wp_connectors_printable($name));
        }

        return $violations;
    }
    if (! $sawDirectoryEntry) {
        // Reject root-file archives BEFORE any directory traversal: the sole
        // top-level entry is a file (e.g. plugin.php), not a plugin folder.
        $violations[] = sprintf(
            'inspect: zip must contain exactly one top-level plugin directory; the sole entry "%s" is a file.',
            $slug
        );

        return $violations;
    }

    // Extract independently and validate the real tree. Everything below
    // runs inside try/finally so the temp tree is removed on EVERY path.
    if (is_dir($workDir)) {
        wp_connectors_inspect_rrmdir($workDir);
    }
    /*
     * The extraction dir is UNIQUE-OWNED (t31-ocr10-2, the WRITE half
     * of the planted-link threat t31-ocr9-10 closed for deletion): the
     * workDir spellings are fixed and predictable — the CLI's
     * sys_get_temp_dir() . '/wp-connectors-inspect-' . getmypid() (pids
     * enumerable), the tests' dist/.inspect-* literals — so a symlink
     * PRE-PLANTED at the name made is_dir() follow it, mkdir() fail
     * ("File exists", the warning leaking raw), and extractTo() WRITE
     * through the link into the attacker's chosen tree (driven pre-fix:
     * the extracted plugin dir landed inside the victim tree). A random
     * unique suffix cannot be pre-planted; the rrmdir link guard below
     * stays as depth. mkdir() is the race-free creation (a pre-existing
     * name of any kind fails it, retried on a fresh suffix).
     */
    /*
     * The retry loop's own failure premise is CAPTURED, never leaked
     * (t31-ocr10-17, the verifier's refutation lens — the r12-19
     * doctrine one screen below, applied to the loop that shares its
     * screen): on an unwritable parent the 16 retries each raised a
     * RAW 'mkdir(): Permission denied' warning to output before the
     * polite refusal printed (driven). The capture is
     * exception-safe (the r12-20 finally) and the refusal names the
     * captured reason through the printable seam.
     */
    $extractDir = '';
    $create_reason = '';
    set_error_handler(static function ( $errno, $errstr ) use ( &$create_reason ) {
        $create_reason = wp_connectors_printable((string) $errstr);

        return true;
    });
    try {
        for ($attempt = 0; $attempt < 16 && '' === $extractDir; ++$attempt) {
            $candidate = $workDir . '-' . bin2hex(random_bytes(8));
            if (mkdir($candidate, 0755, true)) {
                $extractDir = $candidate;
            }
        }
    } finally {
        restore_error_handler();
    }
    if ('' === $extractDir) {
        $violations[] = sprintf(
            'inspect: cannot create a unique extraction directory under %s — %s; the artifact is judged whole or not at all.',
            wp_connectors_printable($workDir),
            '' !== $create_reason ? $create_reason : 'creation returned failure without a diagnostic'
        );

        return $violations;
    }
    try {
        /*
         * Both extraction returns are OWNED (review round t31-r12-1): the
         * unchecked open()/extractTo() pair let a PARTIAL extraction
         * inspect green — a zip carrying an entry whose name exceeds the
         * filesystem's NAME_MAX (reproduced: a 300-byte path component)
         * makes extractTo() return false mid-tree, the engine warning
         * leaked raw to output, and every check below ran over whatever
         * subset HAD extracted while the never-extracted remainder (the
         * webshell and the live key behind it) was judged by nobody —
         * ACCEPTED, 0 violations, exit 0. A failure to open or extract
         * the whole archive is now a refusal naming the reason (the
         * captured engine diagnostic — silenced, never leaked raw), and
         * NO content check runs over a partial tree: the artifact is
         * judged whole or not at all.
         */
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath)) {
            $violations[] = sprintf('inspect: cannot open %s as a zip archive for independent extraction.', basename($zipPath));

            return $violations;
        }
        /*
         * The captured diagnostic renders through the ONE printable
         * seam (verifier round t31-r12-19, the security lens): the
         * engine warning interpolates the hostile ENTRY NAME, and a
         * 250+-byte component with an embedded newline (or an ANSI
         * escape) otherwise forges lines — or rewrites the terminal —
         * inside the inspector's own STDERR output. The refusal still
         * names the reason; the reason can no longer forge one.
         */
        $extract_reason = '';
        set_error_handler(static function ( $errno, $errstr ) use ( &$extract_reason ) {
            $extract_reason = wp_connectors_printable((string) $errstr);

            return true;
        });
        /*
         * The capture is EXCEPTION-SAFE (verifier round t31-r12-20, the
         * security lens): anything extractTo() throws between the two
         * handler calls (8.5 throws ValueError on an unusable
         * destination) otherwise leaves the swallow-all capture handler
         * installed for the REST of the process, silently suppressing
         * every later warning, notice, and deprecation. The restore
         * rides a finally; the throw itself keeps propagating.
         */
        try {
            $extracted = $zip->extractTo($extractDir);
        } finally {
            restore_error_handler();
        }
        $zip->close();
        if (true !== $extracted) {
            $violations[] = sprintf(
                'inspect: cannot extract %s — %s; the artifact is judged whole or not at all, never over a partial extraction tree.',
                basename($zipPath),
                '' !== $extract_reason ? $extract_reason : 'extraction returned failure without a diagnostic'
            );

            return $violations;
        }

        $pluginDir = $extractDir . '/' . $slug;
        if (! is_dir($pluginDir)) {
            $violations[] = sprintf('inspect: the single top-level entry "%s" is not a plugin directory.', wp_connectors_printable($slug));

            return $violations;
        }

        /*
         * The MERGED helper lines render through the ONE printable
         * seam at the merge (verifier round t31-r13-4, raised
         * independently by both lenses): the helpers interpolate
         * archive-controlled text — main-file basenames, header
         * values, the version-constant value, landed paths, include
         * statements — and r13-1's seam covered only the lines the
         * inspector spells itself, so a newline-bearing landed name or
         * a CR/ESC-bearing header value forged lines beside the real
         * REJECTED verdict (reproduced). The helpers stay pure
         * producers (build and the conventions gate render them over
         * the repo's own trusted bytes); the INSPECTOR is the
         * hostile-input surface, so the seam rides its merge points.
         */
        $mainFiles = wp_connectors_find_main_plugin_files($pluginDir);
        if ($mainFiles === array()) {
            $violations[] = sprintf('inspect: %s: no main plugin file with a Plugin Name header.', $slug);
        } else {
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_main_file_violations($pluginDir, $mainFiles)));
            $headers = wp_connectors_parse_plugin_headers($mainFiles[0]);
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_duplicate_header_violations($mainFiles[0], $slug)));
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_header_violations($headers, $slug)));
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_version_constant_violations($pluginDir, $headers, $mainFiles)));
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_autoloader_violations($pluginDir)));
        }
        $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_self_containment_violations($pluginDir)));

        // Every PHP file must pass a syntax check after independent extraction.
        $php = escapeshellarg(PHP_BINARY);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            // The extension judgment rides the ONE case-insensitive owner
            // (verifier note on t31-r4-9): a '.PHP'-spelled entry ships in
            // the zip and must pass the post-extraction syntax check like
            // any other source — the exact-case check let a parse-broken
            // .PHP file through inspection clean.
            if (! wp_connectors_is_php_source($file->getPathname())) {
                continue;
            }
            $output = array();
            $exit = 0;
            exec(sprintf('%s -l %s 2>&1', $php, escapeshellarg($file->getPathname())), $output, $exit);
            if ($exit !== 0) {
                // Both interpolations carry the LANDED entry bytes (a
                // newline is a legal filename character here, and the
                // engine's own diagnostic echoes the same path) — both
                // ride the seam (see the dev-entry site).
                $violations[] = sprintf('inspect: %s failed php -l: %s', wp_connectors_printable(str_replace($extractDir . '/', '', $file->getPathname())), wp_connectors_printable(implode(' ', $output)));
            }
        }

        /*
         * No development credentials inside artifacts — the scan runs
         * UNPRUNED (review round t31-r12-3, closing the round-6 ledger
         * line): wp_connectors_scan_paths()'s dev-segment prune is a
         * repository-walk concept, and the tree a zip actually ships has
         * no dev segments by construction (the builder's collector drops
         * them by the one development-entry vocabulary) — so a pruned
         * segment inside an extracted artifact is itself the anomaly,
         * never a reason to stop reading. The src/Shared dev-entry
         * exemption above composes with this exactly: it exempts
         * CLASSIFICATION (a shared source may live under src/Shared/
         * tools/), while this scan still judges every file the artifact
         * ships under it — the r6 HIGH had a live key at
         * <slug>/src/Shared/vendor/keys.txt inspect ACCEPTED while the
         * identical key one directory up rejected (reproduced).
         */
        foreach (wp_connectors_scan_paths(array( $pluginDir ), false) as $secretFinding) {
            // The finding's path carries the landed entry bytes (see the
            // dev-entry site for the seam doctrine).
            $violations[] = 'inspect: ' . wp_connectors_printable(str_replace($extractDir . '/', '', $secretFinding));
        }

        return $violations;
    } finally {
        wp_connectors_inspect_rrmdir($extractDir);
    }
}

/**
 * Recursively removes a directory.
 *
 * The no-symlinks doctrine the two sibling owners already carry
 * (bin/build.php's rrmdir, t31-r3-era; tests/harness/WpHarness.php's,
 * t31-ocr1-11 — and this third owner skipped it, found by the round-9
 * refutation lens, t31-ocr9-10): a LINK at the removal root is never
 * deleted through (is_dir() follows links; the iterator constructed
 * on a linked path walks the TARGET tree — the workDir names here are
 * fixed and predictable, pre-plantable on a shared host, and the lens
 * DROVE a planted link emptying the victim tree through this owner),
 * and a linked child inside an owned tree is unlinked AS ITSELF,
 * never rmdir'd through (isDir() follows links — the ! isLink() guard
 * keeps the linked dir on the unlink branch).
 *
 * The link probe reads the TAIL-STRIPPED spelling and the ROOT has
 * its own clause (t31-ocr10-15, the round-10 verifier's refutation
 * lens — r9-10's own ledgered re-open condition FIRED): the workDir
 * is a PUBLIC parameter of wp_connectors_inspect_artifact(), so a
 * caller-controlled spelling reaches this owner, and driven pre-fix
 * a trailing '/', '/.', or '/..' family tail forced stat THROUGH a
 * planted link ('link/.' EMPTIED the victim tree past the plain
 * guard) while a root-collapsing spelling walked the filesystem
 * root's children. Only the PROBE strips the tails (a stripped '/..'
 * names a different directory, the ocr9-1 doctrine — the walk keeps
 * the caller's spelling). This owner's verdict vocabulary is the
 * silent return (a production finally must not throw), so the root
 * refuses silently too — the extraction lands in the unique dir
 * regardless.
 *
 * @param string $dir Absolute directory path.
 * @return void
 */
function wp_connectors_inspect_rrmdir($dir)
{
    $probe = $dir;
    if ('/' !== $probe) {
        $probe = rtrim($probe, '/');
        while (true) {
            if ('/.' === substr($probe, -2)) {
                $probe = rtrim(substr($probe, 0, -2), '/');
                continue;
            }
            if ('/..' === substr($probe, -3)) {
                $probe = rtrim(substr($probe, 0, -3), '/');
                continue;
            }
            break;
        }
    }
    if (is_link($probe)) {
        return;
    }
    if (! is_dir($dir)) {
        return;
    }
    if ('/' === realpath($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && ! $item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

if (wp_connectors_cli_entry(__FILE__)) {
    /*
     * The guard + diagnostics are the ONE helper (t31-r12-11); this
     * file's former hand-rolled copy (t31-r9-4 — at file top the calls
     * executed in every requiring process; the test suite loads this
     * file for wp_connectors_inspect_artifact()) is gone. The args
     * ride the ONE argv accessor (t31-r12-17): the auto-global,
     * function-scoped so the read stays provably defined —
     * $_SERVER['argv'] is unpopulated under a variables_order ini
     * without "S", where the guard still fires.
     */

    $cliArgs = wp_connectors_cli_args();
    if (count($cliArgs) < 2) {
        fwrite(STDERR, "usage: php bin/inspect-artifact.php <zip>\n");
        exit(2);
    }
    $zipPath = (string) $cliArgs[1];
    if (! is_file($zipPath)) {
        fwrite(STDERR, "inspect: no such file: {$zipPath}\n");
        exit(2);
    }
    $violations = wp_connectors_inspect_artifact($zipPath, sys_get_temp_dir() . '/wp-connectors-inspect-' . getmypid());
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    printf("inspect: %s %s (%d violation(s))\n", basename($zipPath), $violations === array() ? 'ACCEPTED' : 'REJECTED', count($violations));
    exit($violations === array() ? 0 : 1);
}
