<?php
/**
 * Automated enforcement of docs/CONVENTIONS.md.
 *
 * Validates every plugin-shaped directory (everything under connectors/,
 * plus fixture plugins under tests/fixtures/plugins) using the shared
 * helpers in bin/lib/plugin-tools.php — the same rules bin/build.php and
 * bin/inspect-artifact.php enforce on built artifacts.
 *
 * Exits non-zero on violations. Run via `composer conventions`.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lib/plugin-tools.php';

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $repoRoot = dirname(__DIR__);
    $pluginRoots = array();
    foreach (glob($repoRoot . '/connectors/*', GLOB_ONLYDIR) ?: array() as $dir) {
        $pluginRoots[] = $dir;
    }
    foreach (glob($repoRoot . '/tests/fixtures/plugins/*', GLOB_ONLYDIR) ?: array() as $dir) {
        $pluginRoots[] = $dir;
    }

    $failures = 0;
    foreach ($pluginRoots as $pluginRoot) {
        $slug = basename($pluginRoot);
        $violations = array();

        $mainFile = wp_connectors_find_main_plugin_file($pluginRoot);
        if (null === $mainFile) {
            $violations[] = sprintf('%s: no main plugin file with a "Plugin Name:" header at the plugin root.', $slug);
        } else {
            $headers = wp_connectors_parse_plugin_headers($mainFile);
            $violations = array_merge(
                $violations,
                wp_connectors_header_violations($headers, $slug),
                wp_connectors_version_constant_violations($pluginRoot, $headers),
                wp_connectors_autoloader_violations($pluginRoot),
                wp_connectors_self_containment_violations($pluginRoot)
            );
        }

        foreach ($violations as $violation) {
            fwrite(STDERR, "conventions: FAIL {$violation}\n");
            ++$failures;
        }
    }

    // Repo-level checks.
    if (! is_file($repoRoot . '/CHANGELOG.md')) {
        fwrite(STDERR, "conventions: FAIL CHANGELOG.md is missing at the repository root.\n");
        ++$failures;
    }

    printf("conventions: %d plugin dir(s) checked, %d violation(s)\n", count($pluginRoots), $failures);
    exit($failures === 0 ? 0 : 1);
}
