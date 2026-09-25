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
    /*
     * t31-glm59-7 [R59-10, driven — glob('…/*') never matches
     * dot-led names]: a malformed '.wip' connector was INVISIBLE to
     * the census (0 violations over a tree it never judged) while
     * '--slug=.wip' refused it loudly — omission, not absence. The
     * census reads the directory: every child directory is a plugin
     * root, dot-led included, judged by the same screens.
     */
    $pluginRoots = array_merge($pluginRoots, wp_connectors_child_directories($repoRoot . '/connectors'));
    /*
     * t31-glm60-4 [R60-7, driven — the fixtures census kept glob,
     * the identical silent skip one line below the round-59 fix: a
     * dot-led tests/fixtures/plugins/.wipfix stayed invisible while
     * its visible twin answered seven violations]: the ONE
     * directory-listing owner serves every census seat.
     */
    $pluginRoots = array_merge($pluginRoots, wp_connectors_child_directories($repoRoot . '/tests/fixtures/plugins'));

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
        /*
         * t31-glm57-1 [R57-1]: the conventions gate certified trees
         * whose directory name the inspector's own grammar rejects —
         * the same 'zai copy' shape that built green and inspected
         * REJECTED. The owner is the one grammar all three seats
         * ride; the gate refuses before certifying the tree.
         */
        if (! wp_connectors_slug_is_legal_artifact_name($slug)) {
            $violations[] = sprintf('%s: the connector directory name is outside the artifact grammar [A-Za-z0-9_.-] — bin/inspect-artifact.php rejects every artifact composed under this name; rename the connector directory.', $slug);
        }
        /*
         * t31-glm58-1 [R58-1]: the dev-entry owner beside the
         * grammar owner — a connector directory NAMED 'tools' or
         * 'tests' certified green here while the inspector rejected
         * the published zip's every entry (driven: conventions 0 /
         * build 0 / inspect REJECTED). One vocabulary, three seats.
         */
        if (wp_connectors_is_development_entry($slug)) {
            $violations[] = sprintf('%s: the connector directory name IS a development-entry name — bin/inspect-artifact.php rejects every artifact composed under it; rename the connector directory.', $slug);
        }
        /*
         * t31-glm59-1 [R59-2]: the near-source owner beside the
         * grammar and dev-entry owners — a trailing-junk slug
         * ('zai.php.') certified green here while the inspector
         * rejected the published zip's every entry (driven:
         * conventions 0 / build 0 / inspect REJECTED x64). One
         * vocabulary, three seats.
         */
        if (wp_connectors_segment_is_near_source_php($slug)) {
            $violations[] = sprintf('%s: the connector directory name is a NEAR-SOURCE PHP spelling (trailing edge junk hiding the extension) — bin/inspect-artifact.php rejects every artifact composed under it; rename the connector directory.', $slug);
        }

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
            fwrite(STDERR, 'conventions: FAIL ' . wp_connectors_printable($violation) . "\n");
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
        // t31-glm43-6 [R43-10]: glm42-2's sweep claim ('the two
        // walk-abort prints') silently missed THIS one — the
        // replacement's indent never matched and the shared/src
        // twin alone landed; the record's r26-8 correction.
        fwrite(STDERR, 'conventions: FAIL connectors: unreadable subdirectory — the unused-import scan aborted (' . wp_connectors_printable($e->getMessage()) . ").\n");
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
            fwrite(STDERR, 'conventions: FAIL shared/src: unreadable subdirectory — the unused-import scan aborted (' . wp_connectors_printable($e->getMessage()) . ").\n");
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
 * (glm17-8): a `use ...;` statement line inside a nowdoc/heredoc body
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
                fwrite(STDERR, wp_connectors_printable(sprintf(
                    "conventions: FAIL %s: unreadable file — the unused-import scan cannot run.",
                    substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1)
                )) . "\n");
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
            /*
             * The statement anchors own the INDENTED, the LIST, and
             * the CLOSE-TAG spellings (OCR round 62, t31-ocr62-1):
             * the anchors were ^ alone under /m — column-0
             * statements only — and the plain tail required ';',
             * while PHP admits an import INDENTED inside a braced
             * namespace block ('namespace X {\n    use Foo\Bar;'),
             * the COMMA-SEPARATED list ('use A\B, C\D;'), and the
             * close-tag terminator the engine implies a ';' for
             * ('use A\B ?>') — so a dead import in any of the three
             * spellings was INVISIBLE to the gate (the exact
             * silent-false-negative class the r46-9 keyword census
             * closed, one grammar member over) while its ASCII twin
             * was caught. The three list spellings are the ones bin/
             * build.php's own unownedUseImportSpellingClass() names
             * as legal inputs (the comma list and the close tag with
             * their own refusal errands there — the REWRITER refuses
             * what this gate must still SEE), so the codebase
             * already treats them as real inputs, never typos. The
             * [ \t]* anchor class rides BOTH statement patterns and
             * the two keyword-prefix strips below (the derivation
             * must strip what the widened patterns now match — the
             * r46-9 rule), the terminator alternation rides the
             * plain pattern and the group's trailing check, and the
             * comma arm unrolls below through the group unroller.
             * The indentation the anchor now accepts is exactly the
             * exposure that needs the TRAIT fence
             * (wp_connectors_use_statement_in_import_position(), its
             * own census below): a class-body 'use SomeTrait;' is
             * indented too, and the mention verdict would flag every
             * legitimately-used trait in the tree — the fence keeps
             * the errand on imports.
             */
            /*
             * The statement-start BOUNDARY replaces the line anchor
             * (OCR round 63, t31-ocr63-1 — the r62 anchor's own
             * straggler): [ \t]* under /m still saw only
             * statement-INITIAL lines, while the engine admits a use
             * statement mid-line — 'namespace X { use A\B; }' on one
             * line, 'use A\B; use C\D;' on one line, '<?php use A\B;'
             * (the use riding the open tag's line), every one
             * php -l-clean — so a dead import in any of them was
             * INVISIBLE to the gate (red at HEAD: 0) while bin/
             * build.php's rewriter, whose statement-start judgment is
             * a lookbehind with no line anchor, handled them fine —
             * the gate drift the round-62 census claimed closed. The
             * lookbehind is the boundary class the r49/r60 census
             * owns ('(?<![label byte])', the same WP_CONNECTORS_
             * LABEL_BYTES class the mention boundary spells): a use
             * token is a use STATEMENT exactly when no label byte
             * runs into it — mid-line after '{', ';', '}', or the
             * open tag all satisfy it, while a 'use' inside a longer
             * label ('reuse') or riding a label's tail
             * ('<?phpuse' — not an open tag at all, the engine's
             * own spelling rule) never does. The /m modifier rides
             * off with the anchor (no '^' left to fold). The fence
             * below owns the whole trait judgment now — every
             * spelling the rewriter's own class names, the fence the
             * one errand keeper (a one-line 'class C { use T; }' is
             * matched AND fenced, never anchor-blinded).
             *
             * The class carries the SEPARATOR too (OCR round 64,
             * t31-ocr64-2): the shared statement-start anchor this
             * census claims to ride — build.php's own one-owner
             * spelling, t31-ocr49-3 over the t31-ocr60-3 byte class —
             * refuses label byte AND namespace separator, and the
             * patterns above spelled only the label half, so 'use
             * Foo\use Bar;' matched at the SECOND use (a backslash
             * runs into it) and raised a phantom for Bar. All three
             * statement patterns ride the FULL class now — the
             * premise this comment already stated, made true.
             */
            $matches = array();
            preg_match_all(
                '/(?<![\\\\' . WP_CONNECTORS_LABEL_BYTES . '\\\\])(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?[' . WP_CONNECTORS_LABEL_BYTES . '\\\\]+(?:\s+(?i:as)\s+([' . WP_CONNECTORS_LABEL_BYTES . ']+))?\s*(?:;|\?>)/',
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
                '/(?<![\\\\' . WP_CONNECTORS_LABEL_BYTES . '\\\\])(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?[' . WP_CONNECTORS_LABEL_BYTES . '\\\\]+\s*\{/',
                $code_view,
                $group_matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            );

            /*
             * The comma-list arm (t31-ocr62-1, the same census): the
             * opening proves the shape — the FIRST member's name run
             * (alias included) followed by a comma; the plain tail
             * pattern and the group's '{' requirement both refused
             * the comma, so a dead member rode unflagged. A list is
             * grammar-wise a group without braces: the handler below
             * unrolls its members through the SAME group unroller
             * under an empty prefix.
             */
            $comma_matches = array();
            preg_match_all(
                '/(?<![\\\\' . WP_CONNECTORS_LABEL_BYTES . '\\\\])(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?[' . WP_CONNECTORS_LABEL_BYTES . '\\\\]+(?:\s+(?i:as)\s+[' . WP_CONNECTORS_LABEL_BYTES . ']+)?\s*,/',
                $code_view,
                $comma_matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            );

            if ($matches === array() && $group_matches === array() && $comma_matches === array()) {
                continue;
            }

            $fence = null;
            foreach ($matches as $match) {
                if (! wp_connectors_use_statement_in_import_position($code_view, $match[0][1], $source, $fence)) {
                    // A trait clause list, never an import — the
                    // fence helper's own census.
                    continue;
                }

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
                // t31-glm40-5: the length derives from the matcher (strlen($match[0][0])) — the
                // substr copy glm39-7 left behind is gone (its sole consumer was strlen).

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
                // global class import with no backslash). The tail slice
                // drops the TERMINATOR by its own length — ';' or the
                // two-byte close tag the widened pattern now matches
                // (t31-ocr62-1).
                $terminator_length = '?>' === substr($match[0][0], -2) ? 2 : 1;
                // The strip anchor rides the boundary class the match
                // now opens with (t31-ocr63-1): the matched text BEGINS
                // at the 'use' keyword itself, so the derivation strips
                // exactly what the widened pattern matched.
                $qualified = trim(preg_replace('/^(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?/', '', substr($match[0][0], 0, -$terminator_length)));
                $lastBackslash = strrpos($qualified, '\\');
                $alias = isset($match[1][0]) && \is_string($match[1][0]) ? $match[1][0] : '';
                $short = '' !== $alias
                    ? $alias
                    : (false === $lastBackslash ? $qualified : substr($qualified, $lastBackslash + 1));

                // t31-glm46-5 [R46-14, the r26-8 record class]: this
                // seat-level empty-short guard is deleted — glm45-9
                // moved the judgment to the ONE owner inside the
                // helper, and the leftover copy read as load-bearing
                // ('every arm answered at one owner' was false while
                // it stood).

                // Remove exactly the matched statement bytes at the
                // captured offset (glm16-17: the removal must take ONE copy
                // — str_replace removed every copy, so a comment line
                // ending in the exact use-statement text was stripped too,
                // flagging an import whose only other mention was that
                // comment), then require at least one label-boundary mention
                // of the short name anywhere in the remaining source (code,
                // comments, or docblocks).
                // t31-glm39-7: the offset scan — no per-import copy (see the helper);
                // t31-glm40-5: the length derives from the matcher (strlen($match[0][0])),
                // the substr copy glm39-7 left behind gone (its sole consumer was strlen).
                $mentioned = wp_connectors_mention_outside_statement($source, $short, $statement_offset, strlen($match[0][0]));
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
                if ($mentioned) {
                    continue;
                }

                fwrite(STDERR, wp_connectors_printable(sprintf(
                    "conventions: FAIL %s: unused import '%s' — the short name appears nowhere else in the file.",
                    substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1),
                    $qualified
                )) . "\n");
                ++$violations;
            }

            /*
             * The comma-list handler (t31-ocr62-1): the statement
             * extent runs to the first ';' or close tag on the
             * MASKED view past the opening's comma — comments blank
             * to spaces and string contents are masked (glm17-15's
             * length-not-text invariant), so neither can hide the
             * terminator. An unterminated list is not a well-formed
             * declaration — @lint owns unparseable files (the glm17
             * boundary, the group seam's own rule). The RAW bytes at
             * the captured offset are removed once for every
             * member's mention check (the group form's own shape),
             * and each member rides the same mention contract: one
             * label-boundary mention anywhere (code, comments,
             * docblocks), case-insensitive.
             */
            $fence = null;
            foreach ($comma_matches as $match) {
                if (! wp_connectors_use_statement_in_import_position($code_view, $match[0][1], $source, $fence)) {
                    // A trait clause list ('use TraitA, TraitB;') —
                    // the fence helper's own census.
                    continue;
                }

                $search_from = $match[0][1] + strlen($match[0][0]);
                $semi = strpos($code_view, ';', $search_from);
                $close_tag = strpos($code_view, '?>', $search_from);
                if (false === $semi && false === $close_tag) {
                    continue;
                }
                if (false !== $close_tag && (false === $semi || $close_tag < $semi)) {
                    $terminator_length = 2;
                    $statement_end = $close_tag + 2;
                } else {
                    $terminator_length = 1;
                    $statement_end = $semi + 1;
                }

                $body = (string) preg_replace(
                    '/^(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?/',
                    '',
                    substr($code_view, $match[0][1], $statement_end - $terminator_length - $match[0][1])
                );
                $member_imports = wp_connectors_group_use_imports('', $body);

                // t31-glm40-5: the offset scan — the length derives from the matcher's own end
                // ($statement_end - $match[0][1]); the substr copy and its alias pair (glm39-7's
                // leftovers, named for the deleted copy algorithm) are gone.

                foreach ($member_imports as $member_import) {
                    if (wp_connectors_mention_outside_statement($source, $member_import['short'], $match[0][1], $statement_end - $match[0][1])) {
                        continue;
                    }

                    /*
                     * The display strips exactly the ONE artifact
                     * separator the ''-prefix composition prepends
                     * (OCR round 67, t31-ocr67-5): ltrim once stripped
                     * ALL leading separators, so a fully-qualified
                     * member ('use \A\B, \C\D;' composes '\C\D' to
                     * '\\C\D') printed as 'C\D' — losing the
                     * fully-qualified marker the single arm prints for
                     * 'use \C\D;' and the group arm for its members.
                     * One artifact separator in, one separator out;
                     * the FAIL vocabulary agrees across all three
                     * arms (display-only — the verdict rides the
                     * short name).
                     */
                    $comma_display = '\\' === ($member_import['qualified'][0] ?? '')
                        ? substr($member_import['qualified'], 1)
                        : $member_import['qualified'];
                    fwrite(STDERR, wp_connectors_printable(sprintf(
                        "conventions: FAIL %s: unused import '%s' (comma-list member) — the short name appears nowhere else in the file.",
                        substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1),
                        $comma_display
                    )) . "\n");
                    ++$violations;
                }
            }

            $fence = null;
            foreach ($group_matches as $match) {
                if (! wp_connectors_use_statement_in_import_position($code_view, $match[0][1], $source, $fence)) {
                    // A trait adaptation ('use T { … }'), never a
                    // group import — the fence helper's own census.
                    continue;
                }

                $open = $match[0][1] + strlen($match[0][0]) - 1;
                $close = wp_connectors_matching_brace_end($code_view, $open);

                /*
                 * The declaration must close with ';' — or the close
                 * tag the engine implies one for (t31-ocr62-1, the
                 * statement-boundary owner's own vocabulary) — right
                 * after the matching brace on the masked view;
                 * anything else (an unbalanced body the walk ran to
                 * EOF on, a missing terminator) is not a well-formed
                 * declaration — @lint owns unparseable files (the
                 * glm17 boundary), so the scanner stays neutral on
                 * that class.
                 */
                if (1 !== preg_match('/^[ \t\r\n]*(?:;|\?>)/', (string) substr($code_view, $close + 1), $semi)) {
                    continue;
                }
                $statement_end = $close + 1 + strlen($semi[0]);

                $prefix = preg_replace('/^[ \t]*(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?|[\s{]+$/', '', $match[0][0]);
                $member_imports = wp_connectors_group_use_imports(
                    (string) $prefix,
                    (string) substr($code_view, $open + 1, $close - $open - 1)
                );

                /*
                 * The RAW bytes at the captured offset (glm17-15), removed
                 * once for every member's mention check — the whole group
                 * statement is the declaration surface.
                 */
                // t31-glm40-5: the offset scan — the length derives from the matcher's own end
                // ($statement_end - $match[0][1]); the substr copy and its alias pair (glm39-7's
                // leftovers, named for the deleted copy algorithm) are gone.

                foreach ($member_imports as $member_import) {
                    // Same mention contract as the single form: one
                    // label-boundary mention anywhere (code, comments,
                    // docblocks), case-insensitive (glm17-9) — the boundary
                    // rides the ONE label byte class above (t31-ocr60-1),
                    // this seam with it: \b broke both directions for a
                    // high-byte short name in the single form, and the
                    // member twin carries the same short names.
                    if (wp_connectors_mention_outside_statement($source, $member_import['short'], $match[0][1], $statement_end - $match[0][1])) {
                        continue;
                    }

                    fwrite(STDERR, wp_connectors_printable(sprintf(
                        "conventions: FAIL %s: unused import '%s' (group-use member) — the short name appears nowhere else in the file.",
                        substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1),
                        $member_import['qualified']
                    )) . "\n");
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
 * Whether a use statement at an offset sits in IMPORT position — the
 * trait fence the indented anchor needs (OCR round 62, t31-ocr62-1).
 *
 * The column-0 anchor once made the question moot: imports sit at
 * column 0 (the top level, or an unbraced namespace declaration),
 * and a TRAIT clause list — `use SomeTrait;` inside a class body —
 * is always indented, so no trait use ever matched a pattern. The
 * r62 [ \t]* anchor saw the indented spelling, and the r63
 * statement-start boundary after it sees EVERY spelling mid-line
 * included ('class C { use SomeTrait; }' on one line is matched AND
 * fenced) — so the gate must not send a
 * trait clause down the import errand: the trait's name is its own
 * only mention in the typical tree, so the mention verdict would
 * flag every legitimately-used trait (the false-positive class the
 * widening would otherwise answer on the real scan roots). The
 * judgment is build.php's brace-kind doctrine over the masked view:
 * a '{' opens a NAMESPACE block — the one block kind inside which a
 * use statement is still an import — exactly when the declaration
 * run before it is the `namespace` keyword's own (a name run, or
 * nothing at all for the global block); every other brace (a class,
 * a function, a control block, an anonymous class) is 'other'. The
 * statement is an import when NO 'other' frame encloses it. The
 * walk rides the MASKED view: string contents and comments are
 * blanked (same length, the glm17-15 invariant), so every brace it
 * counts is code — an interpolation's braces cannot unbalance the
 * count the way they once build.php's token walk (the t31-ocr16-10
 * class), and a brace inside a heredoc body is data (glm17-8).
 *
 * THE INLINE-HTML ARM (OCR round 63, t31-ocr63-2): inline HTML
 * between close and open tags keeps its bytes on that view, and the
 * walk once counted every HTML '{'/'}' as a CODE brace — a legal
 * php -l-clean file whose '?>' HTML carries a template placeholder
 * or inline JS/CSS braces armed an 'other' frame past the reopen,
 * and every use statement after the HTML was judged a trait clause
 * and skipped (dead imports escaping, red at HEAD); a stray HTML
 * '}' popped a real frame the code still owed (verified empty of
 * verdict flips on every lint-clean shape — a close tag inside a
 * class body is a parse error, so the frame a trait clause needs is
 * always pushed after the HTML — but the walk judged code braces it
 * never owned). The arm is the file's own brace-counting doctrine
 * for non-code regions: while the engine is in HTML mode (between a
 * real close tag and the next real open tag, and over a leading
 * HTML head), braces and ';' never touch the frame stack. The open
 * tag judgment counts ONLY the engine's INI-independent spellings —
 * '<?php' and '<?=' — never a bare '<?': '<?xml … ?>' in a leading
 * HTML head is inline HTML under the production-default INI
 * (short_open_tag=Off), and the walk's judgment must not depend on
 * the host's INI the way token_get_all's does. '<?php' further
 * carries the engine's own FOLLOWER class — [ \t\r\n] or end of
 * input, read from the raw source (a comment glued to the tag is a
 * NON-opener to the engine, and the masked view would answer the
 * blanked space; t31-ocr64-1). A match offset that
 * lands in HTML is never an import statement — the bytes are inline
 * text the statement patterns still SEE (the r63 boundary anchor
 * matches a mid-HTML 'use' exactly as it does a code one), so the
 * fence owns the region judgment the anchor no longer carries by
 * accident: this gate owns PHP-source trees, never templating
 * mixtures, and @lint owns what does not parse.
 *
 * @param string   $code_view The masked view the statement offsets came from.
 * @param int      $offset    The statement's byte offset (the pattern match).
 * @param string   $source    The raw source (same length as the view — the
 *                            open-tag follower reads the byte the view's
 *                            comment-blanking would hide).
 * @param array|null &$walker  The walk's resumable state. The three
 *                            statement arms each iterate their matches in
 *                            ASCENDING offset order over the same unmutated
 *                            view, so a caller holding one walker across its
 *                            loop resumes from the last statement's byte
 *                            (frames, run start, engine mode) instead of
 *                            re-walking from byte 0 per statement — the
 *                            O(statements × filesize) re-scan once measured
 *                            ~50ms of the ~247ms gate. A null, a view/source
 *                            change, or a non-ascending offset re-initializes
 *                            (t31-glm41-6, verdict-identical: the resumed
 *                            walk answers the same state a fresh walk to the
 *                            same offset answers — every byte the loop reads
 *                            is a function of the view/source bytes, never of
 *                            anything a prior walk consumed).
 * @param-out array $walker The walk's state after the call — always an
 *                          initialized state array (null never survives).
 * @return bool True when no non-namespace block encloses the statement
 *              AND the statement sits in PHP code, never inline HTML.
 */
function wp_connectors_use_statement_in_import_position(string $code_view, int $offset, string $source, ?array &$walker = null): bool
{
    if (null === $walker || $walker['view'] !== $code_view || $walker['source'] !== $source || $walker['i'] > $offset) {
        $walker = array('view' => $code_view, 'source' => $source, 'i' => 0, 'frames' => array(), 'run_start' => 0, 'in_html' => true);
    }
    $frames = &$walker['frames'];
    $run_start = &$walker['run_start'];
    // The engine starts in HTML mode: a file's leading bytes are
    // inline HTML until the first real open tag (t31-ocr63-2).
    $in_html = &$walker['in_html'];
    $length = min($offset, strlen($code_view));
    for ($i = $walker['i']; $i < $length; ++$i) {
        $byte = $code_view[$i];
        if ('?' === $byte) {
            if (0 < $i && '<' === $code_view[$i - 1]) {
                // Open tag candidate: only the INI-independent
                // spellings ('<?php', '<?=') open PHP mode — the run
                // resumes past the tag's keyword bytes (its trailing
                // whitespace is the run class's own leading side).
                // A '<?' in any other spelling ('<?xml' over a
                // leading HTML head) is HTML text, never a mode
                // switch: the engine's own default-INI lexing, made
                // INI-independent here because the walk must not
                // inherit the host's short_open_tag the masked
                // view's tokenizer does.
                $after = $i + 1;
                if ('=' === ($code_view[$after] ?? '')) {
                    ++$after;
                    $in_html = false;
                    $run_start = $after;
                } elseif ('php' === wp_connectors_ascii_lower((string) substr($code_view, $after, 3))) {
                    // The keyword fold rides the ONE ASCII owner (OCR
                    // round 69, t31-ocr69-2, the r11-6 doctrine): the
                    // r68-2 sweep claimed this seam for the locale-fold
                    // census while the compare still consulted
                    // strtolower() — no misclassification observable
                    // (p/h are locale-invariant bytes, the r68-2
                    // refutation's own leg), but every verdict-feeding
                    // fold in the change set rides the table fold, and
                    // the claim is true now.
                    /*
                     * The follower is the engine's own class, read
                     * from the RAW source (OCR round 64, t31-ocr64-1):
                     * T_OPEN_TAG lexes only when '<?php' is followed
                     * by whitespace or end of input — '<?phpecho'/
                     * '<?phpinfo()' are INLINE HTML under the
                     * production-default short_open_tag=Off, and the
                     * walk once flipped to PHP mode on any byte pair,
                     * letting a 'use …;' TEXT line in the markup raise
                     * a phantom over a region the engine never parsed.
                     * The class is exactly [ \t\r\n] (probed: even
                     * '\x0B'/'\f' leave the glued spelling HTML), and
                     * the byte must be RAW because the view blanks a
                     * comment to spaces — '<?php//note' is HTML to the
                     * engine (no follower) while the blanked view
                     * would answer a space; tag DETECTION stays on the
                     * view, where a string-embedded '<?php' is masked
                     * away and cannot flip the mode at all.
                     */
                    $follower = $source[$after + 3] ?? '';
                    if ('' === $follower || str_contains(" \t\r\n", $follower)) {
                        $after += 3;
                        $in_html = false;
                        $run_start = $after;
                    }
                }
                continue;
            }
            if ('>' === ($code_view[$i + 1] ?? '') && ! $in_html) {
                // Close tag: the implied ';' (run boundary). The HTML
                // past it is the inline-HTML arm's own territory —
                // braces there never touch the frame stack.
                $in_html = true;
                $run_start = $i;
            }
            continue;
        }
        if ($in_html) {
            // Inline HTML: braces and ';' are template text. An
            // unbalanced '{placeholder' no longer arms an 'other'
            // frame past the reopen, and a stray '}' no longer pops
            // a frame the code still owes (t31-ocr63-2).
            continue;
        }
        if ('{' === $byte) {
            // The declaration run before the brace — everything since
            // the last structural boundary — is the `namespace`
            // keyword's own (its name run and whitespace, or nothing
            // for 'namespace {') exactly when it matches this shape;
            // any other opener (a class, 'if (…)', 'function f(…)')
            // carries a byte the run class refuses. The mode tags are
            // boundaries themselves (above): the OPEN tag's bytes
            // must not ride into the run of the first declaration
            // after it — '<?php namespace X {' is the canonical file
            // head, and the tag's '<' would otherwise classify the
            // brace 'other'.
            $frames[] = 1 === preg_match(
                '/\A[ \t\r\n\x0B\x0C]*(?i:namespace)\b[' . WP_CONNECTORS_LABEL_BYTES . '\\\\\s]*\z/',
                substr($code_view, $run_start, $i - $run_start)
            ) ? 'namespace' : 'other';
            $run_start = $i + 1;
        } elseif ('}' === $byte || ';' === $byte) {
            if ('}' === $byte && $frames !== array()) {
                array_pop($frames);
            }
            $run_start = $i + 1;
        }
    }

    // A match that lands in HTML is inline text, never an import
    // statement — the region judgment the r63 boundary anchor left
    // for this fence to own.
    $walker['i'] = $length;
    if ($in_html) {
        return false;
    }

    return ! in_array('other', $frames, true);
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





/**
 * Whether the short import name is mentioned (label-boundary, case-insensitive)
 * anywhere in the source OUTSIDE one statement's own byte range.
 *
 * t31-glm39-7 [R39-12, measured efficiency — the gate's dominant-cost seat]:
 * the check once materialized a full copy of the file per import
 * (substr_replace) and ran the mention regex over each copy — O(imports x
 * filesize) allocations and scans, ~7MB re-scanned for a real 130KB/54-import
 * connector file, ~127ms of the 211ms conventions run. The offset form is
 * verdict-identical BY CONSTRUCTION: the copy's removal excluded exactly the
 * statement's byte range, so scanning the un-copied source and skipping
 * matches inside that range asks the same question at zero allocation.
 */
function wp_connectors_mention_outside_statement($source, $short, $statement_offset, $statement_length)
{
    /*
     * t31-glm45-2 [R44-8, the driver-ordered pre-measured claim —
     * the round-44 review's ~35% seat measurement]: the helper ran
     * one full-source preg_match_all PER IMPORT with the pattern
     * rebuilt per call (glm39-7 removed the per-import COPIES but
     * kept the per-import scans — an import-heavy file paying
     * imports x filesize). THE SINGLE WALK: one maximal-label-run
     * pass per FILE, bucketed by the ascii-folded token — a
     * mention of $short is exactly a whole label token equal to it
     * case-insensitively (the lookbehind refusing a label byte
     * before the run keeps every run MAXIMAL and whole — a mention of
     * $short is a maximal label run equal to it case-insensitively,
     * digit-led runs included (the walk spells the FULL label byte
     * class, never the head class, so every label-byte short — even
     * one no real import grammar mints — answers the old verdict)), the fold
     * ASCII-only like the old pattern's byte-mode /i. The
     * single-slot memo serves the three arms' consecutive consults
     * over the same file; an abort answers false (the fail-closed
     * direction glm39-7 recorded).
     */
    static $memo_source = null;
    static $memo_buckets = null;
    if ($source !== $memo_source) {
        $memo_source = $source;
        $memo_buckets = array();
        $hits = preg_match_all('/(?<![' . WP_CONNECTORS_LABEL_BYTES . '])[' . WP_CONNECTORS_LABEL_BYTES . ']+/', $source, $tokens, PREG_OFFSET_CAPTURE);
        if (false !== $hits && $hits > 0) {
            foreach ($tokens[0] as $token) {
                $memo_buckets[ wp_connectors_ascii_lower($token[0]) ][] = $token[1];
            }
        }
    }
    /*
     * t31-glm45-9 [R45-8, driven — glm45-2's own edge]: the empty
     * short (a group-use/comma member ending in a backslash, the
     * shape guard admitting 'B\') answered NOT-mentioned where the
     * old inline pattern matched everywhere — only the single arm
     * guarded the empty short at its own seat, the member arms did
     * not. The single arm's own guard rides the helper now (the
     * seat-local line numbers this block once cited drifted with
     * every later edit and are gone): an empty short is not a name,
     * the member arms' 'unused import' FAIL for the shape
     * unreachable (@lint owns the unparseable file, the
     * scanner-neutral boundary).
     */
    if ('' === $short) {
        return true;
    }
    $lowered = wp_connectors_ascii_lower($short);
    if (! isset($memo_buckets[ $lowered ])) {
        return false;
    }
    $excluded_end = $statement_offset + $statement_length;
    foreach ($memo_buckets[ $lowered ] as $offset) {
        if ($offset >= $excluded_end || $offset + strlen($short) <= $statement_offset) {
            return true;
        }
    }

    return false;
}
