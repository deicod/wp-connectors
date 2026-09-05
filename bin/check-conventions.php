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

        $mainFiles = wp_connectors_find_main_plugin_files($pluginRoot);
        if ($mainFiles === array()) {
            $violations[] = sprintf('%s: no main plugin file with a "Plugin Name:" header at the plugin root.', $slug);
        } else {
            $headers = wp_connectors_parse_plugin_headers($mainFiles[0]);
            $violations = array_merge(
                $violations,
                wp_connectors_main_file_violations($pluginRoot, $mainFiles),
                wp_connectors_duplicate_header_violations($mainFiles[0], $slug),
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
    $failures += wp_connectors_unused_import_violations($repoRoot . '/connectors');

    printf("conventions: %d plugin dir(s) checked, %d violation(s)\n", count($pluginRoots), $failures);
    exit($failures === 0 ? 0 : 1);
}

/**
 * Flags `use` imports whose short name appears nowhere else in the file
 * (glm16-10).
 *
 * Dead imports imply call paths that do not exist (an import of
 * SseFrameBuffer suggests the class does its own framing), so every
 * future reader and IDE navigation chases phantom dependencies — and
 * neither phpstan nor the WordPress coding standard flags the
 * staleness. The rule is deliberately conservative: ANY mention of the
 * short name outside the use statement counts as a use, including
 * prose comments and docblock types (a docblock TYPE is a real
 * phpstan-resolved use); only an import whose short name appears
 * NOWHERE else is flagged.
 *
 * @param string $root Directory to scan recursively for .php files.
 * @return int Violation count.
 */
function wp_connectors_unused_import_violations(string $root): int
{
    $violations = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $lines = null; // Parsed lazily per matching import below.

        $matches = array();
        if (preg_match_all('/^use\s+(?:function\s+|const\s+)?[\w\\\\]+(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($matches as $match) {
            // The short name is the alias when one is given, else the
            // last segment of the qualified name (the whole name for a
            // global class import with no backslash).
            $qualified = trim(preg_replace('/^use\s+(?:function\s+|const\s+)?/', '', substr($match[0], 0, -1)));
            $lastBackslash = strrpos($qualified, '\\');
            $alias = (string) ($match[1] ?? '');
            $short = '' !== $alias
                ? $alias
                : (false === $lastBackslash ? $qualified : substr($qualified, $lastBackslash + 1));

            if ($short === '') {
                continue;
            }

            // Remove the use statement itself (its FIRST occurrence
            // only — glm16-17: str_replace removed every copy, so a
            // comment line ending in the exact use-statement text
            // would have been stripped too, flagging an import whose
            // only other mention was that comment), then require at
            // least one word-boundary mention of the short name
            // anywhere in the remaining source (code, comments, or
            // docblocks).
            $withoutUse = $source;
            $usePosition = strpos($withoutUse, $match[0]);
            if (false !== $usePosition) {
                $withoutUse = substr_replace($withoutUse, '', $usePosition, strlen($match[0]));
            }
            if (preg_match('/\b' . preg_quote($short, '/') . '\b/', $withoutUse) === 1) {
                continue;
            }

            fwrite(STDERR, sprintf(
                "conventions: FAIL %s: unused import '%s' — the short name appears nowhere else in the file.\n",
                substr($file->getPathname(), strlen($root) + 1),
                $qualified
            ));
            ++$violations;
        }
    }

    return $violations;
}
