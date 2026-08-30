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

$repoRoot = dirname(__DIR__);
$argv = isset($argv) ? $argv : array();
$targets = array_slice($argv, 1);
if ($targets === array()) {
    $targets = array( $repoRoot . '/connectors', $repoRoot . '/shared', $repoRoot . '/bin', $repoRoot . '/tests', $repoRoot . '/docs', $repoRoot . '/README.md', $repoRoot . '/CHANGELOG.md' );
}

$findings = wp_connectors_scan_paths($targets);
foreach ($findings as $finding) {
    fwrite(STDERR, 'secrets: FAIL ' . $finding . "\n");
}

printf("secrets: %d finding(s)\n", count($findings));
exit($findings === array() ? 0 : 1);
