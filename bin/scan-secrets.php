<?php
/**
 * Secret-pattern scanner CLI.
 *
 *   php bin/scan-secrets.php            # the whole repository
 *   php bin/scan-secrets.php path ...   # specific files/directories
 *
 * Exits non-zero when any live-credential shape is found. Only structured
 * exemptions apply: a strict "secrets:allow" comment marker on the line, or
 * a matched value recognizable as a fake (see bin/lib/secret-scanner.php).
 * Also wired into `composer check` and into the artifact inspector.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

// plugin-tools for the CLI entry helpers below (and the vocabulary
// fold owner the scanner's prune consumes, t31-ocr1-5); the scanner
// library owns its own dependency on it since t31-ocr3-5, so this
// require serves THIS file's direct calls.
require_once __DIR__ . '/lib/plugin-tools.php';
require_once __DIR__ . '/lib/secret-scanner.php';

/*
 * The ONE guard + diagnostics helper (OCR round 3, t31-ocr3-8,
 * completing t31-r12-11's sweep): this was the last entry script
 * wearing the file-top error_reporting()/ini_set() calls, which ran
 * in every requiring process — the r9-4 class the other four scripts
 * shed. Guarded so tests can require this file for the scanner.
 */
if (wp_connectors_cli_entry(__FILE__)) {
    $repoRoot = dirname(__DIR__);
    $targets = array_slice(wp_connectors_cli_args(), 1);
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
