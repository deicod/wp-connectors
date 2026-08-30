<?php
/**
 * Secret-pattern scanner CLI.
 *
 *   php bin/scan-secrets.php            # the whole repository
 *   php bin/scan-secrets.php path ...   # specific files/directories
 *
 * Exits non-zero when any live-credential shape is found (fixture-marked
 * lines are allowed). Also wired into `composer check` and into the artifact
 * inspector.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lib/secret-scanner.php';

// Guarded so tests can require this file for the helpers.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $repoRoot = dirname(__DIR__);
    $targets = array_slice($argv, 1);
    if ($targets === array()) {
        // Scan the whole repository root: the traversal already prunes .git,
        // vendor, node_modules, dist, and tools, so root config files
        // (mise.toml, composer.json, *.xml.dist, .github/**, dotfiles) are
        // all covered — enumerating subdirectories would leave them
        // invisible.
        $targets = array( $repoRoot );
    }

    $findings = wp_connectors_scan_paths($targets);
    foreach ($findings as $finding) {
        fwrite(STDERR, 'secrets: FAIL ' . $finding . "\n");
    }

    printf("secrets: %d finding(s)\n", count($findings));
    exit($findings === array() ? 0 : 1);
}
