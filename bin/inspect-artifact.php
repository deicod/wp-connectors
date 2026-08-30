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
 *   - more than one main plugin file with a Plugin Name header,
 *   - missing/invalid plugin headers, version-constant mismatch, wrong text
 *     domain (same rules as bin/check-conventions.php),
 *   - repo-relative includes or shared/ references (self-containment),
 *   - development files inside the zip (vendor/, tests/, composer files...),
 *   - any PHP file that does not pass `php -l` after extraction.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

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
    // like "assets/latest/x.png" are never false-rejected.
    $forbiddenSegments = array( 'vendor', '.git', '.github', 'tests', 'test', 'tools', 'dist', 'node_modules', 'phpunit.cache' );
    $forbiddenFiles = array(
        'composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.xml.dist',
        'phpcs.xml', 'phpcs.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
        'package.json', 'package-lock.json', 'Makefile', 'build.json',
        '.phpunit.result.cache', '.phpcs-cache.json', 'phpcs-cache.json',
        '.gitignore', '.gitattributes', '.editorconfig', '.distignore',
    );

    $topDirs = array();
    $sawDirectoryEntry = false;
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
        // Segment check (whole path components) and exact file-name check.
        $isForbidden = false;
        foreach ($parts as $part) {
            if (in_array($part, $forbiddenSegments, true)) {
                $isForbidden = true;

                break;
            }
        }
        if (! $isForbidden && in_array($parts[ count($parts) - 1 ], $forbiddenFiles, true)) {
            $isForbidden = true;
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
        $zip = new ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($workDir);
        $zip->close();

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
            $violations = array_merge($violations, wp_connectors_header_violations($headers, $slug));
            $violations = array_merge($violations, wp_connectors_version_constant_violations($pluginDir, $headers));
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
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $output = array();
            $exit = 0;
            exec(sprintf('%s -l %s 2>&1', $php, escapeshellarg($file->getPathname())), $output, $exit);
            if ($exit !== 0) {
                $violations[] = sprintf('inspect: %s failed php -l: %s', str_replace($workDir . '/', '', $file->getPathname()), implode(' ', $output));
            }
        }

        // No development credentials inside artifacts.
        foreach (wp_connectors_scan_paths(array( $pluginDir )) as $secretFinding) {
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
