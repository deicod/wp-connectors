<?php
/**
 * PHP syntax check (php -l) over all repository PHP sources.
 *
 * Excludes development entries (the ONE shared vocabulary's segments —
 * vendor/, tools/, tests-nested trees, caches — in any casing) below
 * each walked root. Exits non-zero if any file fails to parse.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/plugin-tools.php';

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    /*
     * Diagnostics for the CLI run ONLY (t31-r10-3, the t31-r9-4 class
     * already fixed in build.php, inspect-artifact.php,
     * check-conventions.php, and scan-secrets.php): at file top these
     * two calls — and the whole walk, and the exit() — executed in every
     * process that REQUIRED the file too, so a php-cli host with
     * display_errors off had it flipped on process-wide just by loading
     * a library. The file scope carries only the require now.
     */
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    $roots = array(__DIR__ . '/../connectors', __DIR__ . '/../shared', __DIR__ . '/../bin', __DIR__ . '/../tests');

    $files = array();
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            // The extension judgment rides the ONE case-insensitive owner
            // (verifier note on t31-r4-9): a '.PHP'-spelled source is as
            // loadable as any other and must not escape the lint gate.
            if (! wp_connectors_is_php_source($file->getPathname())) {
                continue;
            }
            /*
             * The exclusion rides the ONE development-entry vocabulary
             * (review round t31-r12-9): the hand-rolled case-sensitive
             * list here had already drifted from the owner — the
             * dotless 'phpunit.cache/' and a 'VENDOR/' spelling were
             * linted while the builder excluded and the inspector
             * rejected both spellings, the exact drift the owner's
             * docblock forbids. Judged on the segments BELOW the root
             * (folding included): the roots themselves — tests among
             * them — are this gate's own charge, not development
             * entries.
             */
            $relative = (string) substr($file->getPathname(), strlen($root) + 1);
            foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
                if (wp_connectors_is_development_entry($segment)) {
                    continue 2;
                }
            }
            $files[] = $file->getPathname();
        }
    }

    sort($files);
    if ($files === array()) {
        fwrite(STDERR, "lint-php: no PHP files found (unexpected)\n");
        exit(1);
    }

    $php = escapeshellarg(PHP_BINARY);
    $failures = 0;
    foreach ($files as $path) {
        $output = array();
        $exit = 0;
        exec(sprintf('%s -l %s 2>&1', $php, escapeshellarg($path)), $output, $exit);
        if ($exit !== 0) {
            ++$failures;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
    }

    printf("lint-php: %d file(s) checked, %d failure(s)\n", count($files), $failures);
    exit($failures === 0 ? 0 : 1);
}
