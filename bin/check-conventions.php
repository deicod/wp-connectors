<?php
/**
 * Automated enforcement of docs/CONVENTIONS.md.
 *
 * Validates every plugin-shaped directory (everything under connectors/,
 * plus fixture plugins under tests/fixtures/plugins):
 *
 *   1. one main plugin file with all required headers,
 *   2. Text Domain equals the directory slug,
 *   3. a {SLUG}_VERSION constant matching the header Version,
 *   4. src/autoload.php registers exactly the plugin's PSR-4 namespace,
 *   5. no shipped file requires/includes outside the plugin dir, references
 *      vendor/autoload or composer at runtime,
 *   6. shared/ is never included from a plugin.
 *
 * Exits non-zero on the first category of violations; all findings are
 * printed. Run via `composer conventions`.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Strips docblock and line comments so checks only see functional code.
 *
 * @param string $source PHP source.
 * @return string Source with comment lines removed.
 */
function wp_connectors_strip_comments($source)
{
    $withoutDocblocks = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    return (string) preg_replace('/^\s*(?:\*|\/\/|#).*$/m', '', $withoutDocblocks);
}

/**
 * Collects convention violations.
 *
 * @param string $rootDir Repository root.
 * @return list<string> Violation messages (empty = clean).
 */
function wp_connectors_check_plugin_dir($rootDir)
{
    $violations = array();
    $slug = basename($rootDir);

    // --- 1. main plugin file + headers ------------------------------------
    $mainFile = null;
    foreach (glob($rootDir . '/*.php') ?: array() as $candidate) {
        $head = (string) file_get_contents($candidate, false, null, 0, 8192);
        if (strpos($head, 'Plugin Name:') !== false) {
            if (null !== $mainFile) {
                $violations[] = sprintf('%s: multiple main plugin files with a "Plugin Name:" header.', $slug);

                break;
            }
            $mainFile = $candidate;
        }
    }
    if (null === $mainFile) {
        $violations[] = sprintf('%s: no main plugin file with a "Plugin Name:" header at the plugin root.', $slug);

        return $violations;
    }

    $head = (string) file_get_contents($mainFile, false, null, 0, 8192);
    $headers = array();
    if (preg_match_all('/^(?:\s*\*\s*)?(Plugin Name|Version|Requires at least|Requires PHP|License|Text Domain|Author):\s*(.+)$/mi', $head, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $headers[ strtolower($match[1]) ] = trim($match[2]);
        }
    }

    foreach (array( 'plugin name', 'version', 'requires at least', 'requires php', 'license', 'text domain', 'author' ) as $required) {
        if (! isset($headers[ $required ]) || '' === $headers[ $required ]) {
            $violations[] = sprintf('%s: main file header is missing "%s".', $slug, ucwords($required));
        }
    }
    if (isset($headers['requires at least']) && '6.9' !== $headers['requires at least']) {
        $violations[] = sprintf('%s: "Requires at least" must be 6.9, found "%s".', $slug, $headers['requires at least']);
    }
    if (isset($headers['requires php']) && '7.4' !== $headers['requires php']) {
        $violations[] = sprintf('%s: "Requires PHP" must be 7.4, found "%s".', $slug, $headers['requires php']);
    }
    if (isset($headers['license']) && false === strpos($headers['license'], 'GPL-2.0-or-later')) {
        $violations[] = sprintf('%s: license header must be GPL-2.0-or-later, found "%s".', $slug, $headers['license']);
    }

    // --- 2. text domain equals slug ----------------------------------------
    if (isset($headers['text domain']) && $headers['text domain'] !== $slug) {
        $violations[] = sprintf('%s: Text Domain "%s" must equal the directory slug.', $slug, $headers['text domain']);
    }

    // --- 3. version constant ----------------------------------------------
    $mainSource = (string) file_get_contents($mainFile);
    $constantName = strtoupper(str_replace('-', '_', $slug)) . '_VERSION';
    if (! preg_match('/define\(\s*[\'"]' . preg_quote($constantName, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $mainSource, $constantMatch)) {
        $violations[] = sprintf('%s: main file must define constant %s.', $slug, $constantName);
    } elseif (isset($headers['version']) && $constantMatch[1] !== $headers['version']) {
        $violations[] = sprintf('%s: %s (%s) does not match header Version (%s).', $slug, $constantName, $constantMatch[1], $headers['version']);
    }

    // --- 4. PSR-4 autoloader ----------------------------------------------
    $autoload = $rootDir . '/src/autoload.php';
    if (! is_file($autoload)) {
        $violations[] = sprintf('%s: src/autoload.php is missing.', $slug);
    } else {
        $autoloadCode = wp_connectors_strip_comments((string) file_get_contents($autoload));
        if (strpos($autoloadCode, 'spl_autoload_register') === false) {
            $violations[] = sprintf('%s: src/autoload.php must register a PSR-4 autoloader.', $slug);
        }
        if (stripos($autoloadCode, 'composer') !== false || stripos($autoloadCode, 'vendor') !== false) {
            $violations[] = sprintf('%s: src/autoload.php must not reference composer or vendor.', $slug);
        }
    }

    // --- 5./6. self-containment --------------------------------------------
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $code = wp_connectors_strip_comments((string) file_get_contents($path));
        $relative = str_replace($rootDir . '/', '', $path);

        // Flag only include/require statements carrying a string-literal path
        // that is not anchored inside the plugin (__DIR__-relative).
        if (preg_match_all('/\b(?:require|include)(?:_once)?\b[^;]*;/', $code, $includes)) {
            foreach ($includes[0] as $include) {
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $include, $literals)) {
                    foreach ($literals[1] as $literal) {
                        $escaped = (strpos($literal, '${') !== false); // Dynamic path parts are fine when anchored below.
                        $anchored = strpos($include, '__DIR__') !== false || strpos($include, 'ABSPATH') !== false;
                        $escapesUp = (bool) preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $include);
                        if ((! $anchored && ! $escaped) || $escapesUp) {
                            $violations[] = sprintf('%s: %s includes a path not anchored to the plugin dir: %s', $slug, $relative, trim($include));
                        }
                    }
                }
            }
        }
        if (stripos($code, 'vendor/autoload') !== false) {
            $violations[] = sprintf('%s: %s references vendor/autoload (no Composer at runtime).', $slug, $relative);
        }
        if (preg_match('/(?:require|include|ComposerAutoloader|ComposerLoader)/i', $code) && stripos($code, 'composer') !== false) {
            $violations[] = sprintf('%s: %s references Composer at runtime.', $slug, $relative);
        }
        if (preg_match('#(?:\.\./)+shared/|\bshared/#', $code)) {
            $violations[] = sprintf('%s: %s references shared/ (generated copies only, never source includes).', $slug, $relative);
        }
    }

    return $violations;
}

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
    $violations = wp_connectors_check_plugin_dir($pluginRoot);
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
