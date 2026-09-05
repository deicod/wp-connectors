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
 * (glm16-10). Imports are located on a token-masked view of the source
 * (glm17-8): a column-0 `use ...;` line inside a nowdoc/heredoc body
 * or a block comment is data, not an import, and never counts.
 *
 * Dead imports imply call paths that do not exist (an import of
 * SseFrameBuffer suggests the class does its own framing), so every
 * future reader and IDE navigation chases phantom dependencies — and
 * neither phpstan nor the WordPress coding standard flags the
 * staleness. The rule is deliberately conservative: ANY mention of the
 * short name outside the use statement counts as a use, including
 * prose comments and docblock types (a docblock TYPE is a real
 * phpstan-resolved use); only an import whose short name appears
 * NOWHERE else is flagged. Mention matching is case-insensitive
 * (glm17-9): PHP name resolution is, so a mixed-case reference is a
 * real use.
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
        if ($file->isDir()) {
            // The iterator yields directories too, and one NAMED *.php
            // passes the extension gate below (glm17-10).
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (false === $source) {
            /*
             * Loud, never silently compliant: the (string) cast used
             * to turn a read failure into '' — an unreadable file was
             * skipped with zero violations and a green gate, making
             * the unused-import guarantee vacuous for exactly the
             * files something is wrong with (glm17-10). Counted as a
             * violation so the exit code stays non-zero.
             */
            fwrite(STDERR, sprintf(
                "conventions: FAIL %s: unreadable file — the unused-import scan cannot run.\n",
                substr($file->getPathname(), strlen($root) + 1)
            ));
            ++$violations;
            continue;
        }

        /*
         * glm17-8: imports are FOUND on the token-masked view, never the
         * raw source — the glm15-2 idiom (wp_connectors_strip_comments()
         * + wp_connectors_mask_string_contents(), both same-length). A
         * column-0 `use ...;` line inside a nowdoc/heredoc body or a
         * block comment is DATA, not code; the raw-source regex treated
         * it as a real import and flagged a phantom unused import when
         * the short name appeared nowhere else. Both transforms are
         * length-preserving, so an offset captured in the masked view is
         * the same byte offset in $source — and a real use statement
         * contains neither comment nor string bytes, so its matched text
         * is identical in both views.
         */
        $code_view = wp_connectors_mask_string_contents(
            wp_connectors_strip_comments($source)
        );

        $matches = array();
        if (preg_match_all(
            '/^use\s+(?:function\s+|const\s+)?[\w\\\\]+(?:\s+as\s+(\w+))?\s*;/m',
            $code_view,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) === 0) {
            continue;
        }

        foreach ($matches as $match) {
            /*
             * glm17-11: the offset capture IS the statement's position.
             * The old code re-derived it with an unanchored strpos over
             * the whole source, which removes the FIRST textual copy of
             * the statement text — not necessarily the matched statement
             * — and paid a full-source string copy plus rescan per match.
             * The dead `false !== $usePosition` guard is gone too: the
             * captured text is by construction a substring of $source at
             * exactly this offset.
             */
            $statement        = $match[0][0];
            $statement_offset = $match[0][1];

            // The short name is the alias when one is given, else the
            // last segment of the qualified name (the whole name for a
            // global class import with no backslash).
            $qualified = trim(preg_replace('/^use\s+(?:function\s+|const\s+)?/', '', substr($statement, 0, -1)));
            $lastBackslash = strrpos($qualified, '\\');
            $alias = isset($match[1][0]) && \is_string($match[1][0]) ? $match[1][0] : '';
            $short = '' !== $alias
                ? $alias
                : (false === $lastBackslash ? $qualified : substr($qualified, $lastBackslash + 1));

            if ($short === '') {
                continue;
            }

            // Remove exactly the matched statement bytes at the
            // captured offset (glm16-17: the removal must take ONE copy
            // — str_replace removed every copy, so a comment line
            // ending in the exact use-statement text was stripped too,
            // flagging an import whose only other mention was that
            // comment), then require at least one word-boundary mention
            // of the short name anywhere in the remaining source (code,
            // comments, or docblocks).
            $withoutUse = substr_replace($source, '', $statement_offset, strlen($statement));
            // Case-insensitive: PHP class and function name resolution is
            // itself case-insensitive (glm17-9), so `new widget()` is a
            // real use of an import of ...Widget. The i modifier only
            // widens what counts as a use — strictly more conservative.
            if (preg_match('/\b' . preg_quote($short, '/') . '\b/i', $withoutUse) === 1) {
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
