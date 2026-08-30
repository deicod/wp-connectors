<?php
/**
 * Shared plugin-parsing and self-containment helpers.
 *
 * Used by bin/check-conventions.php (source trees), bin/build.php, and
 * bin/inspect-artifact.php (extracted artifacts), so all three enforce the
 * same rules from docs/CONVENTIONS.md.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

/**
 * Strips docblock and line comments so checks only see functional code.
 *
 * @param string $source PHP source.
 * @return string Source with comments removed.
 */
function wp_connectors_strip_comments($source)
{
    $withoutDocblocks = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    return (string) preg_replace('/^\s*(?:\*|\/\/|#).*$/m', '', $withoutDocblocks);
}

/**
 * Finds the main plugin file (the root-level file with a Plugin Name header).
 *
 * @param string $pluginDir Absolute plugin directory.
 * @return string|null Absolute path, or null when absent.
 */
function wp_connectors_find_main_plugin_file($pluginDir)
{
    $mainFile = null;
    foreach (glob(rtrim($pluginDir, '/') . '/*.php') ?: array() as $candidate) {
        $head = (string) file_get_contents($candidate, false, null, 0, 8192);
        if (strpos($head, 'Plugin Name:') !== false) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Parses WordPress plugin headers from a main plugin file (docblock-tolerant).
 *
 * @param string $file Absolute path to the main plugin file.
 * @return array<string, string> Header name (lowercased) => value.
 */
function wp_connectors_parse_plugin_headers($file)
{
    $head = (string) file_get_contents($file, false, null, 0, 8192);
    $headers = array();
    if (preg_match_all('/^(?:\s*\*\s*)?(Plugin Name|Version|Requires at least|Requires PHP|License|Text Domain|Author):\s*(.+)$/mi', $head, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $headers[ strtolower($match[1]) ] = trim($match[2]);
        }
    }

    return $headers;
}

/**
 * Validates required headers per docs/CONVENTIONS.md.
 *
 * @param array<string, string> $headers Parsed headers.
 * @param string                $slug    Plugin directory slug.
 * @return list<string> Violation messages.
 */
function wp_connectors_header_violations(array $headers, $slug)
{
    $violations = array();
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
    if (isset($headers['text domain']) && $headers['text domain'] !== $slug) {
        $violations[] = sprintf('%s: Text Domain "%s" must equal the directory slug.', $slug, $headers['text domain']);
    }

    return $violations;
}

/**
 * Checks that no PHP file in the plugin escapes the plugin directory.
 *
 * Flags include/require statements with unanchored literal paths, upward
 * dirname() escapes, runtime references to vendor/autoload or Composer, and
 * any reference to the repository-level shared/ source.
 *
 * @param string $pluginDir Absolute plugin directory.
 * @return list<string> Violation messages ("<slug>: <file>: <message>").
 */
function wp_connectors_self_containment_violations($pluginDir)
{
    $violations = array();
    $slug = basename(rtrim($pluginDir, '/'));
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $code = wp_connectors_strip_comments((string) file_get_contents($path));
        $relative = str_replace($pluginDir . '/', '', $path);

        if (preg_match_all('/\b(?:require|include)(?:_once)?\b[^;]*;/', $code, $includes)) {
            foreach ($includes[0] as $include) {
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $include, $literals)) {
                    foreach ($literals[1] as $literal) {
                        $dynamic = (strpos($literal, '${') !== false);
                        $anchored = strpos($include, '__DIR__') !== false || strpos($include, 'ABSPATH') !== false;
                        $escapesUp = (bool) preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $include);
                        if ((! $anchored && ! $dynamic) || $escapesUp) {
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

/**
 * Checks that src/autoload.php registers exactly one Composer-free PSR-4
 * autoloader bound to the plugin's own Deicod\WpConnectors\<Ns>\ prefix.
 *
 * @param string $pluginDir Absolute plugin directory.
 * @return list<string> Violation messages.
 */
function wp_connectors_autoloader_violations($pluginDir)
{
    $slug = basename(rtrim($pluginDir, '/'));
    $violations = array();
    $autoload = rtrim($pluginDir, '/') . '/src/autoload.php';
    if (! is_file($autoload)) {
        $violations[] = sprintf('%s: src/autoload.php is missing.', $slug);

        return $violations;
    }
    $code = wp_connectors_strip_comments((string) file_get_contents($autoload));
    if (strpos($code, 'spl_autoload_register') === false) {
        $violations[] = sprintf('%s: src/autoload.php must register a PSR-4 autoloader.', $slug);
    }
    if (substr_count($code, 'spl_autoload_register') !== 1) {
        $violations[] = sprintf('%s: src/autoload.php must register exactly one autoloader.', $slug);
    }
    if (stripos($code, 'composer') !== false || stripos($code, 'vendor') !== false) {
        $violations[] = sprintf('%s: src/autoload.php must not reference composer or vendor.', $slug);
    }
    $expectedPrefix = 'Deicod\\WpConnectors\\' . wp_connectors_namespace_suffix_from_slug($slug) . '\\';
    // Autoloaders typically write the prefix as a single-quoted literal with
    // escaped backslashes; normalize before matching.
    $normalized = str_replace('\\\\', '\\', $code);
    if (strpos($normalized, $expectedPrefix) === false) {
        $violations[] = sprintf(
            '%s: src/autoload.php must bind PSR-4 prefix %s (derived from the plugin slug).',
            $slug,
            $expectedPrefix
        );
    }

    return $violations;
}

/**
 * Derives the plugin namespace segment from the slug (openai-oauth -> OpenAiOauth).
 *
 * @param string $slug Plugin slug.
 * @return string
 */
function wp_connectors_namespace_suffix_from_slug($slug)
{
    return implode('', array_map('ucfirst', explode('-', strtolower((string) $slug))));
}

/**
 * Checks the {SLUG}_VERSION constant matches the header Version.
 *
 * @param string               $pluginDir Absolute plugin directory.
 * @param array<string,string> $headers   Parsed headers.
 * @return list<string> Violation messages.
 */
function wp_connectors_version_constant_violations($pluginDir, array $headers)
{
    $slug = basename(rtrim($pluginDir, '/'));
    $violations = array();
    $mainFile = wp_connectors_find_main_plugin_file($pluginDir);
    if (null === $mainFile) {
        return array( sprintf('%s: no main plugin file found.', $slug) );
    }
    $source = (string) file_get_contents($mainFile);
    $constantName = strtoupper(str_replace('-', '_', $slug)) . '_VERSION';
    if (! preg_match('/define\(\s*[\'"]' . preg_quote($constantName, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $source, $constantMatch)) {
        $violations[] = sprintf('%s: main file must define constant %s.', $slug, $constantName);
    } elseif (isset($headers['version']) && $constantMatch[1] !== $headers['version']) {
        $violations[] = sprintf('%s: %s (%s) does not match header Version (%s).', $slug, $constantName, $constantMatch[1], $headers['version']);
    }

    return $violations;
}
