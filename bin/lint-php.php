<?php
/**
 * PHP syntax check (php -l) over all repository PHP sources.
 *
 * Excludes vendor/, tools/, dist/ and anything else that is generated or
 * third-party. Exits non-zero if any file fails to parse.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$roots = array(__DIR__ . '/../connectors', __DIR__ . '/../shared', __DIR__ . '/../bin', __DIR__ . '/../tests');
$exclude = array('vendor', 'tools', 'dist', 'node_modules', '.git', '.phpunit.cache');

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
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $parts = explode(DIRECTORY_SEPARATOR, $file->getPathname());
        if (array_intersect($parts, $exclude) !== array()) {
            continue;
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
