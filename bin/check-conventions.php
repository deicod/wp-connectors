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

if (wp_connectors_cli_entry(__FILE__)) {
    /*
     * The guard + diagnostics are the ONE helper (t31-r12-11); this
     * file's former hand-rolled copy (the glm17-16 original — at file
     * top the calls executed in every requiring process, the test
     * suite loads this file for the scanner fixtures) is gone.
     */

    $repoRoot = dirname(__DIR__);
    $pluginRoots = array();
    foreach (glob($repoRoot . '/connectors/*', GLOB_ONLYDIR) ?: array() as $dir) {
        $pluginRoots[] = $dir;
    }
    foreach (glob($repoRoot . '/tests/fixtures/plugins/*', GLOB_ONLYDIR) ?: array() as $dir) {
        $pluginRoots[] = $dir;
    }

    /*
     * The closing summary counts by SOURCE (OCR round 5, t31-ocr5-2):
     * one pooled count read as "N plugin dir(s) checked, M violation(s)"
     * on a run whose only violations came from shared/src — dirs never
     * scanned for them carried the count. Every number names the tree
     * it counted: the plugin tree (the per-dir checks plus the
     * connectors/ unused-import scan), the shared source tree, the
     * repo-level checks.
     */
    $plugin_failures = 0;
    $shared_failures = 0;
    $repo_failures = 0;
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
            ++$plugin_failures;
        }
    }

    // Repo-level checks.
    if (! is_file($repoRoot . '/CHANGELOG.md')) {
        fwrite(STDERR, "conventions: FAIL CHANGELOG.md is missing at the repository root.\n");
        ++$repo_failures;
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
        $connectors_partial = 0;
        $plugin_failures += wp_connectors_unused_import_violations($repoRoot . '/connectors', $connectors_partial);
    } catch (UnexpectedValueException $e) {
        fwrite(STDERR, "conventions: FAIL connectors: unreadable subdirectory — the unused-import scan aborted ({$e->getMessage()}).\n");
        /*
         * The PARTIAL count rides the abort's own FAIL (OCR round 43,
         * t31-ocr43-8): the abort once discarded every violation
         * counted before the refusal, the tally undercounting by
         * exactly the FAIL lines it had already printed — the by-ref
         * count answers under the abort now (the collector's finally
         * owns the sync), so the summary keeps every printed offense.
         */
        $plugin_failures += $connectors_partial + 1;
    }

    /*
     * The dev gate grows to the shared tree (review round t31-r9-7):
     * the unused-import scan covered only connectors/, so a dead
     * import in shared/src passed every gate and then shipped into
     * EVERY embedding plugin — the phantom-dependency drift the gate
     * exists to kill, one tree further out. Same scanner, same
     * verdict vocabulary; the is_dir guard keeps the gate green on
     * trees that carry no shared source (fixture repos).
     */
    if (is_dir($repoRoot . '/shared/src')) {
        try {
            $shared_partial = 0;
            $shared_failures += wp_connectors_unused_import_violations($repoRoot . '/shared/src', $shared_partial);
        } catch (UnexpectedValueException $e) {
            fwrite(STDERR, "conventions: FAIL shared/src: unreadable subdirectory — the unused-import scan aborted ({$e->getMessage()}).\n");
            // The partial count rides the abort here too (the same
            // t31-ocr43-8 seam, both roots).
            $shared_failures += $shared_partial + 1;
        }
    }

    $failures = $plugin_failures + $shared_failures + $repo_failures;
    printf(
        "conventions: %d plugin dir(s) checked, %d plugin-tree violation(s), %d shared/src violation(s), %d repo violation(s)\n",
        count($pluginRoots),
        $plugin_failures,
        $shared_failures,
        $repo_failures
    );
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
 * @param int|null $counted Output: the violations counted so FAR — set
 *                          even when the walk aborts mid-recursion, so
 *                          the caller keeps every offense the abort
 *                          would otherwise drop (OCR round 43,
 *                          t31-ocr43-8; the prints happen as found,
 *                          the return only at the end).
 * @param-out int $counted The sync always assigns an int (the finally
 *                         owns it), null being only the default the
 *                         optional caller starts from.
 * @return int Violation count.
 * @throws UnexpectedValueException When a subdirectory cannot be opened
 *                                  mid-recursion (the scan cannot step
 *                                  past it; the CLI gate converts the
 *                                  abort to a counted FAIL — glm17-17 —
 *                                  folding $counted in beside it).
 */
function wp_connectors_unused_import_violations(string $root, ?int &$counted = null): int
{
    $violations = 0;

    /*
     * The finally owns the by-ref sync (OCR round 43, t31-ocr43-8):
     * the collector prints every violation as it is found but once
     * returned its count only at the END, so the mid-walk abort's
     * conversion at the gate silently dropped every offense counted
     * before the refusal — the tally losing exactly the FAIL lines it
     * had already printed. The iterator's UnexpectedValueException
     * flies through UNTOUCHED (the gate's conversion is the verdict)
     * and $counted still answers: one sync point covering every
     * increment site and every abort shape, never a per-site
     * bookkeeping twin. The CONSTRUCTION rides inside the guarded
     * region (OCR round 45, t31-ocr45-7): an unopenable scan root
     * throws from the RecursiveDirectoryIterator constructor itself,
     * and the former placement — outside the try — ran no finally and
     * left $counted unassigned, falsifying the docblock's own
     * "@param-out ... always" contract under the very abort the sync
     * exists to cover.
     */
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                // The iterator yields directories too, and one NAMED *.php
                // passes the extension gate below (glm17-10).
                continue;
            }
            // The extension judgment rides the ONE case-insensitive owner
            // (t31-r4-9) — a '.PHP'-spelled source is judged like any other.
            if (! wp_connectors_is_php_source($file->getPathname())) {
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
                 *
                 * The below-root offset rides the sibling's rtrim spelling
                 * at ALL THREE FAIL sites of this scan (OCR round 32,
                 * t31-ocr32-6 — the t31-ocr14-4 parity lint-php.php
                 * replaced its bare substr(getPathname(), strlen($root)+1)
                 * with in this same update; this scan's three sites are
                 * the sweep): a trailing-separator root once ate one byte
                 * too few, and every FAIL line named its file one
                 * character short — the offset derives from the root
                 * AFTER its separator is stripped, the one spelling both
                 * scanners' diagnostics share. The strip is the
                 * DUAL-SEPARATOR class since t31-ocr31-5 moved the
                 * sibling (bin/lib/secret-scanner.php) to
                 * rtrim($root, '/\\'): the native-separator-only strip
                 * keeps a '/'-suffixed root on a '\' host one byte over,
                 * so these sites spell the same class — the offset judges
                 * the spelling CLASS, never the host it runs on, and on a
                 * POSIX host the arithmetic is byte-identical to the
                 * former rtrim.
                 */
                fwrite(STDERR, sprintf(
                    "conventions: FAIL %s: unreadable file — the unused-import scan cannot run.\n",
                    substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1)
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

            /*
             * The KEYWORD axes ride the engine's case-insensitivity
             * (OCR round 46, t31-ocr46-9 — the r35-1 doctrine's
             * scanner sibling, this owner): the statement patterns
             * once spelled use/function/const/as byte-exact
             * lowercase while PHP accepts `Use`, `use FUNCTION`,
             * `Const`, and `AS` alike — so a case-variant import in
             * connectors/ went unscanned, a dead one invisible to the
             * gate (the exact silent-false-negative class the gate
             * exists for). The keywords match through SCOPED (?i:…)
             * groups at every keyword-bearing pattern in this file —
             * the statement pair here, the two keyword-prefix strips
             * below (the qualified-name and group-prefix derivations
             * must strip what the widened patterns now match), and
             * the member kind/alias parses in the group unroller.
             */
            /*
             * The LABEL byte class rides the ONE owner (OCR round 59,
             * t31-ocr59-2): the \w classes below are ASCII-only in
             * PCRE's byte mode, while PHP labels admit the high bytes
             * — so a legal high-byte import ('use Foo\Grüß;') failed
             * the class mid-name, matched nothing, and was INVISIBLE
             * to this gate (the exact silent-false-negative class the
             * r46-9 keyword census closed, one grammar member over).
             * Every class that matches identifier bytes here — the
             * statement pair, the group opening, and the member alias
             * parse below (its un-aliased else-arm, the member NAME
             * shape guard, riding the same class since t31-ocr60-2)
             * — spells WP_CONNECTORS_LABEL_BYTES (bin/lib/
             * plugin-tools.php, the census comment there listing every
             * seam aligned).
             */
            $matches = array();
            preg_match_all(
                '/^(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?[' . WP_CONNECTORS_LABEL_BYTES . '\\\\]+(?:\s+(?i:as)\s+([' . WP_CONNECTORS_LABEL_BYTES . ']+))?\s*;/m',
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
                '/^(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?[' . WP_CONNECTORS_LABEL_BYTES . '\\\\]+\s*\{/m',
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
                $qualified = trim(preg_replace('/^(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?/', '', substr($match[0][0], 0, -1)));
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
                // comment), then require at least one label-boundary mention
                // of the short name anywhere in the remaining source (code,
                // comments, or docblocks).
                $withoutUse = substr_replace($source, '', $statement_offset, strlen($statement));
                // Case-insensitive: PHP class and function name resolution is
                // itself case-insensitive (glm17-9), so `new widget()` is a
                // real use of an import of ...Widget. The i modifier only
                // widens what counts as a use — strictly more conservative.
                /*
                 * The mention boundary rides the ONE label byte class (OCR
                 * round 60, t31-ocr60-1 — the r59 widening's follow-on):
                 * \b is PCRE's ASCII word boundary, and a high byte is a
                 * NON-word byte in byte mode, so for a short name ending
                 * in one ('Grüß') the verdict broke BOTH directions — a
                 * real mention 'new Grüß()' found no boundary between the
                 * 0x9F and '(' (legal code flagged unused), while \bGrüß\b
                 * DID match inside the lookalike 'Grüßx' (a dead import
                 * passing). The label-class lookarounds ask the question
                 * \b asked — does a name END here — over the bytes a PHP
                 * label actually admits.
                 */
                if (preg_match('/(?<![' . WP_CONNECTORS_LABEL_BYTES . '])' . preg_quote($short, '/') . '(?![' . WP_CONNECTORS_LABEL_BYTES . '])/i', $withoutUse) === 1) {
                    continue;
                }

                fwrite(STDERR, sprintf(
                    "conventions: FAIL %s: unused import '%s' — the short name appears nowhere else in the file.\n",
                    substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1),
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

                $prefix = preg_replace('/^(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?|[\s{]+$/', '', $match[0][0]);
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
                    // label-boundary mention anywhere (code, comments,
                    // docblocks), case-insensitive (glm17-9) — the boundary
                    // rides the ONE label byte class above (t31-ocr60-1),
                    // this seam with it: \b broke both directions for a
                    // high-byte short name in the single form, and the
                    // member twin carries the same short names.
                    if (preg_match('/(?<![' . WP_CONNECTORS_LABEL_BYTES . '])' . preg_quote($member_import['short'], '/') . '(?![' . WP_CONNECTORS_LABEL_BYTES . '])/i', $withoutUse) === 1) {
                        continue;
                    }

                    fwrite(STDERR, sprintf(
                        "conventions: FAIL %s: unused import '%s' (group-use member) — the short name appears nowhere else in the file.\n",
                        substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1),
                        $member_import['qualified']
                    ));
                    ++$violations;
                }
            }
        }
    } finally {
        // The by-ref sync (t31-ocr43-8): the abort flies through
        // UNTOUCHED — the gate owns the verdict — and the partial
        // count still answers beside it.
        $counted = $violations;
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
        if (1 === preg_match('/^(?:(?i:function)|(?i:const))\s+/', $member, $member_kind)) {
            $member = trim(substr($member, strlen($member_kind[0])));
        }

        $alias = '';
        if (1 === preg_match('/^([' . WP_CONNECTORS_LABEL_BYTES . '\\\\]+)\s+(?i:as)\s+([' . WP_CONNECTORS_LABEL_BYTES . ']+)$/', $member, $parts)) {
            $alias = $parts[2];
            $member = $parts[1];
        } elseif (1 !== preg_match('/^[' . WP_CONNECTORS_LABEL_BYTES . '\\\\]+$/', $member)) {
            /*
             * Not a name/alias shape: unparseable input, lint owns it.
             * The shape guard rides the ONE label byte class (OCR
             * round 60, t31-ocr60-2 — the r59 straggler the alias
             * parse one branch above already closed): the ASCII \w
             * class refused an un-aliased high-byte member
             * ('use Vendor\Pkg\{Grüß};' matches the widened group
             * opening, then died HERE) — silently continue'd,
             * invisible to the unused-import gate, the exact
             * silent-false-negative class the round claims retired
             * while the aliased twin of the same member flagged.
             */
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
