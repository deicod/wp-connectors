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
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = (string) $zip->getNameIndex($i);
        $parts = explode('/', $name);
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
         * (t31-r12-10): wp_connectors_is_embed_destination() folds case
         * exactly like the builder's collision fence, so any casing of
         * the generated subtree is classified (never content-judged) as
         * embed territory on both sides.
         */
        $isEmbeddedShared = wp_connectors_is_embed_destination($name, $parts[0]);
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
            $violations[] = sprintf('inspect: zip contains development entry "%s".', $name);
        }
    }
    $zip->close();

    if (count($topDirs) !== 1) {
        $violations[] = sprintf(
            'inspect: zip must contain exactly one top-level plugin directory, found: %s.',
            implode(', ', array_keys($topDirs))
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
        $violations[] = sprintf('inspect: invalid top-level plugin directory name "%s".', $slug);

        return $violations;
    }
    if ($traversalEntries !== array()) {
        foreach ($traversalEntries as $name) {
            $violations[] = sprintf('inspect: zip entry "%s" escapes the extraction directory.', $name);
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
    mkdir($workDir, 0755, true);
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
        $extract_reason = '';
        set_error_handler(static function ( $errno, $errstr ) use ( &$extract_reason ) {
            $extract_reason = (string) $errstr;

            return true;
        });
        $extracted = $zip->extractTo($workDir);
        restore_error_handler();
        $zip->close();
        if (true !== $extracted) {
            $violations[] = sprintf(
                'inspect: cannot extract %s — %s; the artifact is judged whole or not at all, never over a partial extraction tree.',
                basename($zipPath),
                '' !== $extract_reason ? $extract_reason : 'extraction returned failure without a diagnostic'
            );

            return $violations;
        }

        $pluginDir = $workDir . '/' . $slug;
        if (! is_dir($pluginDir)) {
            $violations[] = sprintf('inspect: the single top-level entry "%s" is not a plugin directory.', $slug);

            return $violations;
        }

        $mainFiles = wp_connectors_find_main_plugin_files($pluginDir);
        if ($mainFiles === array()) {
            $violations[] = sprintf('inspect: %s: no main plugin file with a Plugin Name header.', $slug);
        } else {
            $violations = array_merge($violations, wp_connectors_main_file_violations($pluginDir, $mainFiles));
            $headers = wp_connectors_parse_plugin_headers($mainFiles[0]);
            $violations = array_merge($violations, wp_connectors_duplicate_header_violations($mainFiles[0], $slug));
            $violations = array_merge($violations, wp_connectors_header_violations($headers, $slug));
            $violations = array_merge($violations, wp_connectors_version_constant_violations($pluginDir, $headers, $mainFiles));
            $violations = array_merge($violations, wp_connectors_autoloader_violations($pluginDir));
        }
        $violations = array_merge($violations, wp_connectors_self_containment_violations($pluginDir));

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
                $violations[] = sprintf('inspect: %s failed php -l: %s', str_replace($workDir . '/', '', $file->getPathname()), implode(' ', $output));
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
            $violations[] = 'inspect: ' . str_replace($workDir . '/', '', $secretFinding);
        }

        return $violations;
    } finally {
        wp_connectors_inspect_rrmdir($workDir);
    }
}

/**
 * Recursively removes a directory.
 *
 * @param string $dir Absolute directory path.
 * @return void
 */
function wp_connectors_inspect_rrmdir($dir)
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    /*
     * Diagnostics for the CLI run ONLY (glm17-16, review round
     * t31-r9-4): at file top these two calls executed in every process
     * that REQUIRED the file too — the test suite loads it for
     * wp_connectors_inspect_artifact() (BuildArtifactsTest,
     * BuildSeamPropertyTest), and a host running php-cli with
     * display_errors off would have had it flipped on process-wide
     * just by loading a test. Same shape check-conventions.php already
     * wears.
     */
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    if (count($argv) < 2) {
        fwrite(STDERR, "usage: php bin/inspect-artifact.php <zip>\n");
        exit(2);
    }
    $zipPath = $argv[1];
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
