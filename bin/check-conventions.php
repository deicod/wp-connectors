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

require_once __DIR__ . '/lib/plugin-tools.php';

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    /*
     * Diagnostics for the CLI run ONLY (glm17-16): at file top these
     * two calls executed in every process that REQUIRED the file too —
     * the test suite loads it for the scanner fixtures, and a host
     * running php-cli with display_errors off would have had it
     * flipped on process-wide just by loading a test.
     */
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

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
                wp_connectors_version_constant_violations($pluginRoot, $headers, $mainFiles),
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
    /*
     * glm17-17: a subdirectory the iterator cannot OPEN mid-recursion
     * aborts the scan with an UnexpectedValueException (it cannot step
     * past what it cannot enter). The gate converts the abort to the
     * same loud-counted channel as the unreadable-FILE branch — a
     * named FAIL and a non-zero exit instead of an uncaught fatal's
     * stack trace — and the partial count scanned so far is kept.
     */
    try {
        $failures += wp_connectors_unused_import_violations($repoRoot . '/connectors');
    } catch (UnexpectedValueException $e) {
        fwrite(STDERR, "conventions: FAIL connectors: unreadable subdirectory — the unused-import scan aborted ({$e->getMessage()}).\n");
        ++$failures;
    }

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
 * (glm17-9): class and function name resolution is case-insensitive,
 * so a mixed-case reference is a real use (constants resolve
 * case-SENSITIVELY — the i modifier is the conservative direction for
 * every import kind, never a narrowing).
 *
 * @param string $root Directory to scan recursively for .php files.
 * @return int Violation count.
 * @throws UnexpectedValueException When a subdirectory cannot be opened
 *                                  mid-recursion (the scan cannot step
 *                                  past it; the CLI gate converts the
 *                                  abort to a counted FAIL — glm17-17).
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

        /*
         * glm25-8: the file's views come from the ONE shared tokenizer
         * provider — the self-containment analyzer over the same file
         * in this process already paid for the tokenization (two
         * token_get_all passes per file per check, four per gate run,
         * with the tokenize the scanners' dominant cost).
         */
        $views = wp_connectors_file_code_views($file->getPathname());
        if (null === $views) {
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
        $source = $views['source'];

        /*
         * glm17-8: imports are FOUND on the token-masked view, never the
         * raw source — the glm15-2 idiom (wp_connectors_strip_comments()
         * + wp_connectors_mask_string_contents(), both same-length). A
         * column-0 `use ...;` line inside a nowdoc/heredoc body or a
         * block comment is DATA, not code; the raw-source regex treated
         * it as a real import and flagged a phantom unused import when
         * the short name appeared nowhere else. Both transforms are
         * length-preserving, so an offset captured in the masked view is
         * the same byte offset in $source. The matched TEXT may differ
         * from the raw bytes (glm17-15: a comment between the qualified
         * name and the `as` alias is legal PHP and blanks to spaces in
         * the view), so the statement bytes are sliced from $source
         * below, never taken from the match text.
         */
        $code_view = $views['masked'];

        $matches = array();
        preg_match_all(
            '/^use\s+(?:function\s+|const\s+)?[\w\\\\]+(?:\s+as\s+(\w+))?\s*;/m',
            $code_view,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        /*
         * glm20-3: GROUP-USE declarations (use Foo\{A, B as C};) are
         * their own form — the single-class pattern above stops at the
         * '{', so until now every import inside a group was invisible
         * to the gate (a silent false negative of the exact
         * phantom-dependency class the gate exists for). The opening is
         * matched on the SAME masked view; the closing brace comes from
         * the shared brace walk (string contents are masked and
         * comments blanked, so no data brace can unbalance it), and the
         * members are unrolled from the MASKED statement bytes — a
         * comment blanked to spaces inside the body cannot hide the
         * comma that splits two members the way its raw bytes would
         * (glm17-15's length-not-text invariant, applied to the split).
         */
        $group_matches = array();
        preg_match_all(
            '/^use\s+(?:function\s+|const\s+)?[\w\\\\]+\s*\{/m',
            $code_view,
            $group_matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        if ($matches === array() && $group_matches === array()) {
            continue;
        }

        foreach ($matches as $match) {
            /*
             * glm17-11: the offset capture IS the statement's position.
             * The old code re-derived it with an unanchored strpos over
             * the whole source, which removes the FIRST textual copy of
             * the statement text — not necessarily the matched statement
             * — and paid a full-source string copy plus rescan per match.
             * The dead `false !== $usePosition` guard is gone too — the
             * offset comes from the matcher itself.
             */
            $statement_offset = $match[0][1];
            // The REAL bytes at the captured offset, same LENGTH as the
            // masked match (a mid-statement comment blanks to spaces in
            // the view, so the match text is not the statement): used
            // for the removal and the flag message (glm17-15).
            $statement        = substr($source, $statement_offset, strlen($match[0][0]));

            /*
             * glm28-1 (glm28-22 hardening): a comment anywhere in the
             * statement — glm17-15 met it between the name and the
             * alias, the round-28 verifier met it before the terminator
             * (a trailing note rode into the short name, flagging a
             * genuinely USED import), and the security verifier met it
             * between the keyword and the name (a real-bytes cut there
             * emptied the derived name and silently SKIPPED a dead
             * import the pre-round scanner flagged). The qualified name
             * derives from the MASKED match text now: every comment
             * blanks to a space run there (glm17-14 keeps the line
             * terminator, which \s+ spans), the import's own bytes are
             * word chars and backslashes only — never masked — and the
             * keyword-prefix regex's \s+ spans the blanked run wherever
             * the comment sits. The REAL statement bytes above still
             * serve the one-copy removal below (glm17-15) and the
             * statement's LENGTH; the flag message prints the clean
             * derived name.
             */
            // The short name is the alias when one is given, else the
            // last segment of the qualified name (the whole name for a
            // global class import with no backslash).
            $qualified = trim(preg_replace('/^use\s+(?:function\s+|const\s+)?/', '', substr($match[0][0], 0, -1)));
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

        foreach ($group_matches as $match) {
            $open = $match[0][1] + strlen($match[0][0]) - 1;
            $close = wp_connectors_matching_brace_end($code_view, $open);

            /*
             * The declaration must close with ';' right after the
             * matching brace on the masked view; anything else (an
             * unbalanced body the walk ran to EOF on, a missing
             * terminator) is not a well-formed declaration — @lint owns
             * unparseable files (the glm17 boundary), so the scanner
             * stays neutral on that class.
             */
            if (1 !== preg_match('/^[ \t\r\n]*;/', (string) substr($code_view, $close + 1), $semi)) {
                continue;
            }
            $statement_end = $close + 1 + strlen($semi[0]);

            $prefix = preg_replace('/^use\s+(?:function\s+|const\s+)?|[\s{]+$/', '', $match[0][0]);
            $member_imports = wp_connectors_group_use_imports(
                (string) $prefix,
                (string) substr($code_view, $open + 1, $close - $open - 1)
            );

            /*
             * The RAW bytes at the captured offset (glm17-15), removed
             * once for every member's mention check — the whole group
             * statement is the declaration surface.
             */
            $statement = substr($source, $match[0][1], $statement_end - $match[0][1]);
            $withoutUse = substr_replace($source, '', $match[0][1], strlen($statement));

            foreach ($member_imports as $member_import) {
                // Same mention contract as the single form: one
                // word-boundary mention anywhere (code, comments,
                // docblocks), case-insensitive (glm17-9).
                if (preg_match('/\b' . preg_quote($member_import['short'], '/') . '\b/i', $withoutUse) === 1) {
                    continue;
                }

                fwrite(STDERR, sprintf(
                    "conventions: FAIL %s: unused import '%s' (group-use member) — the short name appears nowhere else in the file.\n",
                    substr($file->getPathname(), strlen($root) + 1),
                    $member_import['qualified']
                ));
                ++$violations;
            }
        }
    }

    return $violations;
}

/**
 * Splits a group-use body at its TOP-LEVEL commas (glm20-3).
 *
 * A member may itself be a nested group ('Sub\{Deep, Deeper}'), so the
 * split must respect brace depth; the body comes from the MASKED view,
 * where string contents and comments cannot contribute commas.
 *
 * @param string $body The text between the group's braces (masked view).
 * @return array<int, string> Non-empty, trimmed member texts.
 */
function wp_connectors_group_use_members(string $body): array
{
    /*
     * glm28-10: the member split rides the ONE depth-zero span walk
     * (bin/lib/plugin-tools.php) with this scanner's '{}' depth class
     * — the hand-rolled loop was structurally identical to the three
     * plugin-tools splits. Empty members (a trailing comma) drop.
     */
    $members = array();
    foreach (wp_connectors_depth_zero_spans($body, '{', '}', static function (string $view, int $i): int {
        return ',' === $view[$i] ? 1 : 0;
    }) as list($span_start, $span_end)) {
        $members[] = trim((string) substr($body, $span_start, $span_end - $span_start));
    }

    return array_values(array_filter($members, static function ($member): bool {
        return '' !== $member;
    }));
}

/**
 * Unrolls one group-use statement's members into import pairs (glm20-3).
 *
 * Each member yields its qualified name (group prefix + member path) and
 * the SHORT name other code references (the alias when 'as' is given,
 * else the last segment of the MEMBER's own path — not the prefix:
 * 'use Vendor\Pkg\{Sub\Widget};' is referenced as Widget). Nested
 * groups recurse with the composed prefix. A member that is not a
 * plain name/alias shape (only possible in a file lint already rejects)
 * is skipped — the scanner stays neutral on unparseable input. An
 * optional per-member 'function '/'const ' kind prefix (the MIXED
 * group-use syntax, glm27-2) is stripped before the parse; it never
 * affects the short name.
 *
 * @param string $prefix The group's namespace prefix ('Vendor\Pkg').
 * @param string $body   The text between the group's braces (masked view).
 * @return array<int, array{qualified: string, short: string}> Member imports.
 */
function wp_connectors_group_use_imports(string $prefix, string $body): array
{
    $imports = array();
    foreach (wp_connectors_group_use_members($body) as $member) {
        $open = strpos($member, '{');
        if (false !== $open && '}' === substr(rtrim($member), -1)) {
            foreach (wp_connectors_group_use_imports(
                trim($prefix . '\\' . trim(substr($member, 0, $open)), '\\'),
                (string) substr($member, $open + 1, -1)
            ) as $nested) {
                $imports[] = $nested;
            }
            continue;
        }

        /*
         * glm27-2 (Codex R21 finding 2): a MIXED group-use declaration
         * carries per-member kinds — use Vendor\Pkg\{function helper,
         * const FLAG, Widget}; — and the typed members failed BOTH
         * regexes below, silently skipping them as though invalid
         * syntax: an unused typed member then reported no violation,
         * defeating the unused-import check for the mixed syntax. The
         * kind prefix never affects the SHORT name (function helper →
         * helper; const FLAG as F → F), so it is stripped before the
         * alias/name parse and dropped. The statement-level form
         * (use function Vendor\{...}) is already handled where the
         * prefix is built above the group walk.
         */
        if (1 === preg_match('/^(function|const)\s+/', $member, $member_kind)) {
            $member = trim(substr($member, strlen($member_kind[0])));
        }

        $alias = '';
        if (1 === preg_match('/^([\w\\\\]+)\s+as\s+(\w+)$/', $member, $parts)) {
            $alias = $parts[2];
            $member = $parts[1];
        } elseif (1 !== preg_match('/^[\w\\\\]+$/', $member)) {
            // Not a name/alias shape: unparseable input, lint owns it.
            continue;
        }

        $last_backslash = strrpos($member, '\\');
        $imports[] = array(
            'qualified' => $prefix . '\\' . $member,
            'short' => '' !== $alias
                ? $alias
                : (false === $last_backslash ? $member : substr($member, $last_backslash + 1)),
        );
    }

    return $imports;
}
