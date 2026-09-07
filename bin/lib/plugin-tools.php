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
 * glm15-2: comment removal is TOKEN-based (token_get_all), not regex —
 * the previous regex strip also deleted comment-LOOKING lines inside
 * string and heredoc bodies, so text a plugin merely prints was judged
 * as PHP. Comment bytes become SPACES, preserving every byte offset in
 * the file: statements and offsets sliced from the stripped source line
 * up with the original exactly.
 *
 * glm17-14: a comment token's trailing line terminator is PRESERVED,
 * not blanked. On PHP < 8.0 the tokenizer includes the newline in
 * T_COMMENT (8.0+ emits it as separate whitespace), so blanking the
 * whole token joined the next line onto the comment's line in this
 * view — un-anchoring every ^-anchored line scan on those runtimes
 * (the unused-import scanner's /^use/m silently missed real dead
 * imports on the composer-pinned 7.4 floor; empirically confirmed in
 * the glm17 verifier round). Keeping the terminator byte verbatim
 * preserves length, so the offset invariant above is untouched, and
 * on PHP 8.0+ this branch is a no-op (the token carries no newline).
 *
 * @param string $source PHP source.
 * @return string Source with comments replaced by spaces (same length;
 *                 any line terminator inside the comment token stays).
 */
function wp_connectors_strip_comments($source)
{
    $stripped = '';
    foreach (token_get_all($source) as $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;

        if (T_COMMENT === $id || T_DOC_COMMENT === $id) {
            $terminator = preg_match('/(?:\r\n|\n|\r)\z/', $text, $tail) ? $tail[0] : '';
            $stripped .= str_repeat(' ', strlen($text) - strlen($terminator)) . $terminator;
            continue;
        }

        $stripped .= $text;
    }

    return $stripped;
}

/**
 * Blanks string and heredoc CONTENTS so code-shape scans see only code.
 *
 * glm15-2: the include/assignment/signature analyses were regexes over
 * comment-stripped source, so a '$var = ...;' or 'function' or
 * 'require ...;' written inside a quoted string or heredoc counted as
 * real code — a phantom assignment could satisfy (or poison) a variable
 * include's resolution and phantom includes were analyzed as
 * statements. This returns a copy of the SAME LENGTH where every byte
 * belonging to a string region — single/double-quoted literals, heredoc
 * and nowdoc bodies, and {$...} interpolations inside them — is a
 * space. Real code keeps its bytes and its offsets, so matches found on
 * the masked copy slice the true statement text out of the original.
 *
 * @param string $code PHP source (comment-stripping optional).
 * @return string Same-length copy with string contents blanked.
 */
function wp_connectors_mask_string_contents($code)
{
    $masked = '';
    $in_heredoc = false;
    $in_interpolated = false;
    $curly = 0;

    foreach (token_get_all($code) as $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;

        if (T_START_HEREDOC === $id) {
            $masked .= str_repeat(' ', strlen($text));
            $in_heredoc = true;
            continue;
        }
        if (T_END_HEREDOC === $id) {
            $masked .= str_repeat(' ', strlen($text));
            $in_heredoc = false;
            continue;
        }

        $in_string = $in_heredoc || $in_interpolated || $curly > 0;

        if (T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id) {
            ++$curly;
            $masked .= str_repeat(' ', strlen($text));
            continue;
        }
        if ($curly > 0 && '{' === $token) {
            ++$curly;
        } elseif ($curly > 0 && '}' === $token) {
            --$curly;
        }
        if (T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id) {
            // Simple literals anywhere; content chunks of heredocs and
            // interpolated strings (the quotes ride these tokens except
            // for the opening double quote of an interpolated string).
            $masked .= str_repeat(' ', strlen($text));
            continue;
        }
        if ($in_string) {
            // Interpolated variables, operator/object tokens inside
            // {$...}, and the structural quotes/braces: masked.
            if ($in_interpolated && 0 === $curly && '"' === $token) {
                $in_interpolated = false;
            }
            $masked .= str_repeat(' ', strlen($text));
            continue;
        }
        if ('"' === $token) {
            // A bare double quote opens an interpolated string (a simple
            // one was swallowed whole as T_CONSTANT_ENCAPSED_STRING).
            $in_interpolated = true;
            $masked .= ' ';
            continue;
        }

        $masked .= $text;
    }

    return $masked;
}

/**
 * The one per-file tokenizer provider for the conventions checks (glm25-8).
 *
 * The self-containment analyzer and the unused-import scanner each
 * walk connectors/*.php in the same process, and each used to run
 * strip_comments() + mask_string_contents() over every file it
 * visited — two full token_get_all passes per check, four per file
 * per gate run, with the tokenize being the scanners' dominant CPU
 * cost. This provider computes the (source, code, masked) triple ONCE
 * per file CONTENT and serves both checks: the cache key carries the
 * content's md5, so a rewritten file re-tokenizes (no mtime
 * granularity, no order dependence — the glm15-6 memoization
 * boundary), and same-content re-reads anywhere in the process reuse
 * the entry. Returns null for an unreadable file; every caller OWNS
 * that return (the unused-import scan counts it loudly, the
 * self-containment driver keeps the empty-analysis tolerance the old
 * (string) cast gave it).
 *
 * @param string $path Absolute file path.
 * @return array{source: string, code: string, masked: string}|null The
 *         raw source, its comment-stripped view, and the string-masked
 *         view of that (same lengths), or null when unreadable.
 */
function wp_connectors_file_code_views($path)
{
    /** @var array<string, array{source: string, code: string, masked: string}> $views */
    static $views = array();

    $source = @file_get_contents($path);
    if (false === $source) {
        return null;
    }

    $key = $path . "\0" . md5($source);
    if (!isset($views[$key])) {
        $code = wp_connectors_strip_comments($source);
        $views[$key] = array(
            'source' => $source,
            'code' => $code,
            'masked' => wp_connectors_mask_string_contents($code),
        );
    }

    return $views[$key];
}

/**
 * Reads a plugin file's leading bytes (glm25-9, glm25-12).
 *
 * The main-file discovery, the header parser, and the duplicate-header
 * check each read the same 8 KB head of the same main file in one run
 * — one owner for the read expression instead of three hand-rolled
 * copies. Deliberately NOT memoized: the stat-guarded memo this round
 * first added (mtime + size) served a STALE head for a same-path,
 * same-size rewrite inside one mtime second — verifier-reproduced
 * 30/30 on back-to-back rewrites — while saving only page-cached
 * 8 KB reads. No current consumer rewrites mid-run (the CLIs scan
 * static trees; the test fixtures are fresh per test), but a memo
 * whose correctness depends on that is a hazard class with no
 * benefit; the plain read is correct by construction (unlike the
 * glm25-8 code-views memo, whose content-keyed md5 makes it sound
 * under rewrite by design). A read that fails yields '' (the
 * callers' existing (string) cast semantics: no header found).
 *
 * @param string $file  Absolute file path.
 * @param int    $bytes Leading bytes to read (default 8192).
 * @return string The head bytes, or '' when unreadable.
 */
function wp_connectors_plugin_file_head($file, $bytes = 8192)
{
    return (string) @file_get_contents($file, false, null, 0, $bytes);
}

/**
 * Finds ALL root-level files carrying a Plugin Name header (sorted by name).
 *
 * Exactly one of these may exist (docs/CONVENTIONS.md, rule 1): more than
 * one header-bearing root file is something WordPress could expose as two
 * plugins. Callers that need to enforce that use
 * wp_connectors_main_file_violations() with this list.
 *
 * @param string $pluginDir Absolute plugin directory.
 * @return list<string> Absolute paths (possibly empty).
 */
function wp_connectors_find_main_plugin_files($pluginDir)
{
    $mainFiles = array();
    foreach (glob(rtrim($pluginDir, '/') . '/*.php') ?: array() as $candidate) {
        $head = wp_connectors_plugin_file_head($candidate);
        if (strpos($head, 'Plugin Name:') !== false) {
            $mainFiles[] = $candidate;
        }
    }
    sort($mainFiles, SORT_STRING);

    return $mainFiles;
}

/**
 * Finds the main plugin file (the first header-bearing root file, by name).
 *
 * Deterministic (alphabetically first of the scan); when more than one
 * candidate exists the caller must ALSO surface the
 * wp_connectors_main_file_violations() violation instead of silently
 * accepting the first match.
 *
 * @param string $pluginDir Absolute plugin directory.
 * @return string|null Absolute path, or null when absent.
 */
function wp_connectors_find_main_plugin_file($pluginDir)
{
    $mainFiles = wp_connectors_find_main_plugin_files($pluginDir);

    return $mainFiles === array() ? null : $mainFiles[0];
}

/**
 * Enforces the exactly-one-main-file rule (docs/CONVENTIONS.md, rule 1).
 *
 * Shared by the conventions check, the builder, and the artifact inspector:
 * an archive with two header-bearing root files would be accepted by
 * WordPress as two plugins, so it must be rejected everywhere.
 *
 * @param string      $pluginDir Absolute plugin directory.
 * @param list<string> $mainFiles Pre-scanned candidates from
 *                                wp_connectors_find_main_plugin_files()
 *                                (rescanned when empty).
 * @return list<string> Violation messages (empty when zero or one candidate).
 */
function wp_connectors_main_file_violations($pluginDir, array $mainFiles = array())
{
    if ($mainFiles === array()) {
        $mainFiles = wp_connectors_find_main_plugin_files($pluginDir);
    }
    if (count($mainFiles) <= 1) {
        return array();
    }

    $names = array();
    foreach ($mainFiles as $file) {
        $names[] = basename($file);
    }

    return array( sprintf(
        '%s: multiple main plugin files with Plugin Name headers (%s); exactly one is allowed.',
        basename(rtrim($pluginDir, '/')),
        implode(', ', $names)
    ) );
}

/**
 * Returns the shared plugin-header match pattern.
 *
 * One pattern for header parsing and the duplicate-header check, so the two
 * can never drift apart.
 *
 * @return string PCRE pattern with two capture groups (header name, value).
 */
function wp_connectors_plugin_header_pattern()
{
    return '/^(?:\s*\*\s*)?(Plugin Name|Version|Requires at least|Requires PHP|License|Text Domain|Author):\s*(.+)$/mi';
}

/**
 * Parses WordPress plugin headers from a main plugin file (docblock-tolerant).
 *
 * Repeated headers keep the FIRST occurrence, matching WordPress's
 * get_file_data(); duplicates are reported separately by
 * wp_connectors_duplicate_header_violations().
 *
 * @param string $file Absolute path to the main plugin file.
 * @return array<string, string> Header name (lowercased) => value.
 */
function wp_connectors_parse_plugin_headers($file)
{
    $head = wp_connectors_plugin_file_head($file);
    $headers = array();
    if (preg_match_all(wp_connectors_plugin_header_pattern(), $head, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            if (! isset($headers[ $key ])) {
                $headers[ $key ] = trim($match[2]);
            }
        }
    }

    return $headers;
}

/**
 * Flags repeated recognized plugin headers (docs/CONVENTIONS.md).
 *
 * WordPress ignores every duplicate after the first, so a repeated header is
 * at best a stale leftover and at worst a value the tooling would act on
 * while WordPress never sees it. Shared by the conventions check, the
 * builder, and the artifact inspector.
 *
 * @param string $file Absolute path to the main plugin file.
 * @param string $slug Plugin directory slug.
 * @return list<string> Violation messages.
 */
function wp_connectors_duplicate_header_violations($file, $slug)
{
    $head = wp_connectors_plugin_file_head($file);
    $violations = array();
    $seen = array();
    if (preg_match_all(wp_connectors_plugin_header_pattern(), $head, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            if (isset($seen[ $key ])) {
                $violations[] = sprintf(
                    '%s: duplicate "%s" plugin header; WordPress keeps the first value ("%s").',
                    $slug,
                    ucwords($key),
                    $seen[ $key ]
                );
                continue;
            }
            $seen[ $key ] = trim($match[2]);
        }
    }

    return $violations;
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
 * Whether a __DIR__-anchored include resolves outside the plugin directory.
 *
 * "Anchored" alone is not enough: `require __DIR__ . '/../../x.php';` is
 * anchored yet walks OUT of the plugin (only the dirname(__DIR__) spelling
 * was caught before). When the statement's string literals are static, this
 * composes them against the containing file's directory and requires the
 * result to stay inside the plugin dir — the self-containment invariant.
 * Nested-but-inside includes (a file in src/Sub requiring
 * `__DIR__ . '/../support.php'`) still pass.
 *
 * @param string $file     Absolute path of the file containing the include.
 * @param string $include  The full include statement.
 * @param list<array{0: string, 1: string}> $literals Quoted literals of the
 *                          statement as [opening quote, inner text] pairs
 *                          (glm29-3: quote-aware), in order.
 * @param string $pluginDir Absolute plugin directory.
 * @return bool True when the include is __DIR__-anchored, static, and escapes.
 */
function wp_connectors_anchored_include_escapes_plugin($file, $include, array $literals, $pluginDir)
{
    if (strpos($include, '__DIR__') === false) {
        return false;
    }
    if (preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $include)) {
        // Already flagged by the upward-dirname rule; do not double-report.
        return false;
    }
    $walksUp = false;
    foreach ($literals as $literal_pair) {
        if (wp_connectors_literal_is_interpolated($literal_pair[0], $literal_pair[1])) {
            // Dynamic segment: the target cannot be resolved statically.
            return false;
        }
        if (preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $literal_pair[1])) {
            $walksUp = true;
        }
    }
    if (! $walksUp) {
        // A purely downward path can never leave the plugin dir.
        return false;
    }

    $resolved = dirname($file);
    foreach ($literals as $literal_pair) {
        $literal = $literal_pair[1];
        foreach (preg_split('#[/\\\\]+#', $literal) ?: array() as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $resolved = dirname($resolved);
                continue;
            }
            $resolved .= '/' . $segment;
        }
    }

    $root = rtrim((string) $pluginDir, '/');

    return $resolved !== $root && strpos($resolved . '/', $root . '/') !== 0;
}

/**
 * Reasons an include-target expression cannot be proven to stay in-root.
 *
 * An expression is PROVEN in-root when it is anchored on __DIR__ or ABSPATH,
 * contains no upward dirname(__DIR__/__FILE__) call, no dynamic ${...}
 * literal segment, and at least one quoted literal whose static '..' path
 * (when present) still resolves inside the plugin dir via
 * wp_connectors_anchored_include_escapes_plugin(). Anchored expressions
 * whose only runtime pieces trail the anchor as '/'-joined segments (the
 * mandated PSR-4 autoloader's class-name mapping) count as proven.
 *
 * @param string $file       Absolute path of the file containing the include.
 * @param string $expression Include-target expression to analyze.
 * @param string $pluginDir  Absolute plugin directory.
 * @return list<string> Violation reasons (empty when provably in-root).
 */
function wp_connectors_include_expression_reasons($file, $expression, $pluginDir)
{
    if (preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $expression)) {
        return array( 'escapes upward through dirname()' );
    }
    if (strpos($expression, '__DIR__') === false && strpos($expression, 'ABSPATH') === false) {
        return array( 'is not anchored to __DIR__ or ABSPATH' );
    }
    $quoted_literals = wp_connectors_quoted_literals($expression);
    if ($quoted_literals === array()) {
        return array( 'combines the anchor with unresolvable runtime segments' );
    }
    foreach ($quoted_literals as $literal_pair) {
        if (wp_connectors_literal_is_interpolated($literal_pair[0], $literal_pair[1])) {
            return array( 'contains an interpolated segment' );
        }
    }
    if (wp_connectors_anchored_include_escapes_plugin($file, $expression, $quoted_literals, $pluginDir)) {
        return array( 'resolves outside the plugin dir' );
    }

    return array();
}

/**
 * Extracts the non-literal runtime segments of an include-target expression.
 *
 * String literals are blanked out, then the expression is split on '.'
 * concatenation operators at bracket depth zero. Everything left that is
 * neither the __DIR__/ABSPATH anchor nor a blanked literal — a variable, a
 * function call, an array access — is a segment the literal analysis cannot
 * see and wp_connectors_runtime_segment_reasons() must resolve separately.
 *
 * @param string $statement Include statement or plain expression.
 * @return list<string> Runtime segment expressions (empty when static).
 */
function wp_connectors_include_runtime_segments($statement)
{
    $argument = trim((string) preg_replace('/^(?:require|include)(?:_once)?\s*/i', '', trim($statement)), " \t\n\r();");
    /*
     * glm29-3: an INTERPOLATED double-quoted literal stays visible.
     * Blanking every quoted string to '' classified the whole
     * runtime-built path as a static segment, so a "$name" inside the
     * quotes never surfaced here as runtime — the segment walk saw a
     * proven-literal include while PHP interpolated '../' traversal
     * into the target at runtime. Single-quoted literals and
     * interpolation-free double-quoted ones keep the blanking: their
     * text IS their runtime value.
     */
    $blanked = (string) preg_replace_callback(
        '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/',
        static function ($match) {
            if ('"' === $match[0][0] && false !== strpos(substr($match[0], 1, -1), '$')) {
                return $match[0];
            }

            return "''";
        },
        $argument
    );

    // glm28-10: the dot split rides the ONE depth-zero span walk.
    $segments = array();
    foreach (wp_connectors_depth_zero_spans($blanked, '([', ')]', static function ($view, $i) {
        return '.' === $view[ $i ] ? 1 : 0;
    }) as list($span_start, $span_end)) {
        $segments[] = (string) substr($blanked, $span_start, $span_end - $span_start);
    }

    $runtime = array();
    foreach ($segments as $segment) {
        $trimmed = trim($segment);
        if ($trimmed === '' || $trimmed === "''" || $trimmed === '__DIR__' || $trimmed === 'ABSPATH') {
            continue;
        }
        $runtime[] = $trimmed;
    }

    return $runtime;
}

/**
 * The byte spans between depth-zero cut positions (glm28-10: the ONE
 * depth-zero SPLIT walk — the wp_connectors_matching_delimiter_end()
 * precedent applied to the split family).
 *
 * Four hand-rolled copies of this loop lived across the tree (the
 * group-use member split on '{}'-depth commas in check-conventions.php,
 * the runtime-segment split on '()'/[]'-depth dots, and the map-literal
 * comma and '=>' splits here) — structurally parallel walks a
 * depth-semantics fix (an unbalanced-input rule, a new delimiter class)
 * had to land in four places at once, the exact drift class glm20-10
 * closed for the matcher family. One parameterized walk now; every
 * split rides it with its own opens/closes classes and cut predicate.
 *
 * The walk judges $view (a masked/blanked same-length copy at every
 * call site) and returns [start, end) spans of it — valid byte ranges
 * in ANY same-length string, so a caller may slice the ORIGINAL bytes
 * at the same offsets (the glm17-15 length invariant). The predicate
 * returns the cut's BYTE LENGTH (0 = no cut at $i), so a two-byte
 * '=>' cut advances past both bytes; spans EXCLUDE the cut bytes and
 * the final span (the tail) is always present, empty or not — callers
 * state their own empty-element policy.
 *
 * @param string   $view   The view the walk judges (masked/blanked).
 * @param string   $opens  Every byte that opens a nesting level ('{', '([').
 * @param string   $closes Every byte that closes one, in $opens order.
 * @param callable $cuts   function ( string $view, int $i ): int — the cut's
 *                         byte length at $i, or 0 when $i is no cut.
 * @return array<int, array{0: int, 1: int}> The [start, end) spans.
 */
function wp_connectors_depth_zero_spans($view, $opens, $closes, $cuts)
{
    $spans = array();
    $start = 0;
    $depth = 0;
    $length = strlen($view);
    for ($i = 0; $i < $length; ++$i) {
        $char = $view[ $i ];
        if (false !== strpos($opens, $char)) {
            ++$depth;
        } elseif (false !== strpos($closes, $char)) {
            --$depth;
        }
        if (0 !== $depth) {
            continue;
        }

        $cut = (int) $cuts($view, $i);
        if ($cut > 0) {
            $spans[] = array($start, $i);
            $start = $i + $cut;
            $i += $cut - 1;
        }
    }
    $spans[] = array($start, $length);

    return $spans;
}

/**
 * The byte offset closing the delimiter opened at $open, or false when
 * unbalanced (glm20-10: the ONE depth walk both delimiters share).
 *
 * The brace and paren forms used to be two copies differing only in
 * delimiter literals — and had already diverged (EOF on one walk's
 * unbalanced input, false on the other's), the drift shape a copy
 * invites: a fix to one walk's semantics had to land twice or the
 * callers silently got different answers. The walk itself now answers
 * only what it can know — 'closed here' or false ('cannot close') —
 * and each caller states its own unbalanced POLICY at its site.
 *
 * @param string $masked     String-masked source (strings blank, so data
 *                           delimiters cannot unbalance the walk).
 * @param int    $open       Offset of the opening delimiter.
 * @param string $open_char  The opening delimiter ('{' or '(').
 * @param string $close_char The closing delimiter ('}' or ')').
 * @return int|false Offset of the matching close, or false.
 */
function wp_connectors_matching_delimiter_end($masked, $open, $open_char, $close_char)
{
    $length = strlen($masked);
    $depth = 0;
    for ($i = $open; $i < $length; ++$i) {
        if ($open_char === $masked[ $i ]) {
            ++$depth;
        } elseif ($close_char === $masked[ $i ]) {
            --$depth;
            if (0 === $depth) {
                return $i;
            }
        }
    }

    return false;
}

/**
 * The byte offset closing the brace opened at $open, or end-of-file when
 * unbalanced (glm18-17: the walk the visibility spans share).
 *
 * glm20-10: the shared delimiter walk wearing the visibility spans'
 * own POLICY for an unclosable brace — over-approximate to EOF, the
 * glm18-7 boundary: a wider region only refuses proofs, never launders
 * one, so the span grows rather than under-bounds. This wrapper is the
 * one place that policy lives; every caller below gets it by name.
 *
 * @param string $masked String-masked source (strings blank, code braces only).
 * @param int    $open   Offset of the opening '{'.
 * @return int Offset of the matching '}' (or the last byte).
 */
function wp_connectors_matching_brace_end($masked, $open)
{
    $end = wp_connectors_matching_delimiter_end($masked, $open, '{', '}');

    return false === $end ? strlen($masked) - 1 : $end;
}

/**
 * The byte offset closing the paren opened at $open, or false when
 * unbalanced (glm18-17: the walk the visibility spans share).
 *
 * glm20-10: the shared delimiter walk under the honest contract —
 * false lets each caller state its own unbalanced policy where the
 * do-while tail approximates to EOF but a loop header that cannot
 * close bounds nothing.
 *
 * @param string $masked String-masked source.
 * @param int    $open   Offset of the opening '('.
 * @return int|false Offset of the matching ')', or false.
 */
function wp_connectors_matching_paren_end($masked, $open)
{
    return wp_connectors_matching_delimiter_end($masked, $open, '(', ')');
}

/**
 * The byte regions whose variable writes an include at $offset can read
 * (glm18-7).
 *
 * Straight-line execution reads only writes placed BEFORE the include —
 * but inside a loop the include also sits in, that ordering inverts: a
 * write placed later in the loop body still executes before the
 * include's NEXT iteration, so a plain "before the offset" cut models
 * straight-line execution only and let loop shapes launder foreign
 * include paths past the gate (round 18: a while-loop reassignment
 * placed after the require produced zero violations while the second
 * iteration included an out-of-root marker, empirically confirmed). The
 * visible region is [0, $offset) plus the span of every loop construct
 * that CONTAINS $offset.
 *
 * glm18-17 (verifier round): a do-while's span covers its TRAILING
 * condition too — the construct re-executes `while (cond);` after every
 * pass, so a write in the tail executes after the include each
 * iteration and is read by its next pass (the round-18 form ended the
 * span at the body's closing brace and the tail laundered, empirically
 * confirmed); a BRACELESS do (`do require ...; while (cond);`) is
 * matched as well and over-approximates to end-of-file.
 *
 * glm18-19 (verifier round): FUNCTION-declaration spans join the set —
 * recursion and repeated callback invocation re-enter a body, so every
 * write in the enclosing function of an include is visible to it,
 * before or after the include's own position.
 *
 * Every span is OVER-approximated: a braced body closes at its matching
 * brace on the string-masked view (string/heredoc contents are blank,
 * so the depth count only sees code braces), and any shape we do not
 * confidently parse — an alternative-syntax body (while: … endwhile;),
 * a braceless single-statement body, a tail whose parens we cannot
 * close — extends to end-of-file instead. A wider region can only
 * refuse more proofs, never launder one.
 *
 * @param string $masked String-masked source (same length as the code).
 * @param int    $offset Byte offset the include statement starts at.
 * @return list<array{0: int, 1: int}> Inclusive [start, end] byte ranges.
 */
function wp_connectors_write_visibility_spans($masked, $offset)
{
    $spans = array(array(0, max(0, $offset - 1)));
    $length = strlen($masked);

    if (! preg_match_all('/\b(?:while|for|foreach)\s*\(|\bdo\s*\{|\bdo\b(?!\s*\{)|\bfunction\b/', $masked, $loops, PREG_OFFSET_CAPTURE)) {
        return $spans;
    }

    foreach ($loops[0] as $loop) {
        $construct = $loop[0];
        $last = $loop[1] + strlen($construct) - 1; // Position of '(' or '{', or the keyword's last letter.

        if ('n' === $construct[ strlen($construct) - 1 ]) {
            /*
             * A function/method/closure declaration (glm18-19, verifier
             * round): recursion and repeated callback invocation are
             * backward edges the loop spans do not model — a write
             * placed after the include inside a function that re-enters
             * executes before the include's NEXT activation (round 18:
             * the recursion fixture laundered a foreign path,
             * empirically confirmed). Every write in the enclosing
             * function's body is therefore visible to an include
             * inside it. A bodyless declaration (interface/abstract —
             * a ';' before any '{') bounds nothing; a body brace we
             * cannot close falls to the shared EOF approximation.
             */
            $j = $loop[1] + strlen($construct);
            $body_open = false;
            while ($j < $length) {
                if (';' === $masked[ $j ]) {
                    break; // Bodyless declaration: no body to span.
                }
                if ('{' === $masked[ $j ]) {
                    $body_open = $j;
                    break;
                }
                ++$j;
            }
            if (false === $body_open) {
                continue;
            }
            $body_close = wp_connectors_matching_brace_end($masked, $body_open);

            if ($offset >= $loop[1] && $offset <= $body_close) {
                $spans[] = array($loop[1], $body_close);
            }
            continue;
        }

        if ('o' === $construct[ strlen($construct) - 1 ]) {
            // A braceless `do statement; while (...);`: the single-statement
            // body and the tail cannot be bounded textually — EOF.
            if ($offset >= $loop[1]) {
                $spans[] = array($loop[1], $length - 1);
            }
            continue;
        }

        if ('{' === $masked[ $last ]) {
            // A braced `do { ... } while (cond);`.
            $body_close = wp_connectors_matching_brace_end($masked, $last);

            if ($body_close < $length - 1) {
                /*
                 * glm18-17: extend through the trailing condition — its
                 * re-execution each pass makes tail writes visible to an
                 * include inside the body. Anything unparsable after the
                 * body falls to the EOF approximation below.
                 */
                $j = $body_close + 1;
                while ($j < $length && ctype_space($masked[ $j ])) {
                    ++$j;
                }
                if (0 === stripos((string) substr($masked, $j, 5), 'while')) {
                    $paren = strpos($masked, '(', $j);
                    $tail_close = false === $paren ? false : wp_connectors_matching_paren_end($masked, $paren);
                    if (false !== $tail_close) {
                        $body_close = $tail_close;
                    } else {
                        $body_close = $length - 1;
                    }
                }
            }

            if ($offset >= $loop[1] && $offset <= $body_close) {
                $spans[] = array($loop[1], $body_close);
            }
            continue;
        }

        // while/for/foreach: walk the header parens to the matching close.
        $header_close = wp_connectors_matching_paren_end($masked, $last);

        if (false === $header_close) {
            continue; // A header we cannot close bounds nothing.
        }
        $j = $header_close + 1;
        while ($j < $length && ctype_space($masked[ $j ])) {
            ++$j;
        }
        if ($j >= $length) {
            continue;
        }
        if ('{' !== $masked[ $j ]) {
            // Alternative-syntax or braceless body: unbounded (EOF).
            if ($offset >= $loop[1]) {
                $spans[] = array($loop[1], $length - 1);
            }
            continue;
        }

        $body_close = wp_connectors_matching_brace_end($masked, $j);

        if ($offset >= $loop[1] && $offset <= $body_close) {
            $spans[] = array($loop[1], $body_close);
        }
    }

    return $spans;
}

/**
 * Whether every textual WRITE to a variable visible to an include is a
 * form the map-literal analysis models (GLM10 verifier round on #14).
 *
 * The synthetic foreach binding and the array-literal proof reason
 * about the values a map variable's same-file assignments carry —
 * which is only the set of runtime values when nothing else can write
 * the map. Strict rule: every assignment-shaped write is a WHOLE-array
 * array()/[] literal, and the variable never appears as an element
 * access ($map[...] — read or write, element shapes are unmodeled), a
 * list() target, an array-write helper argument, a by-reference
 * binding, or inside a function signature (a parameter DEFAULT the
 * assignment regex can mistake for the map's definition while the
 * caller's argument wins at runtime). Any unrecognized shape refuses
 * the proof, restoring the flagged default.
 *
 * @param string $masked   String-masked view of the file (same length as
 *                         the comment-stripped source; offsets
 *                         interchangeable).
 * @param string $variable Variable token, including the leading '$'.
 * @param int    $offset   Byte offset the include starts at.
 * @return bool True when only whole-array literal writes are visible to the include.
 */
function wp_connectors_array_writes_recognized($masked, $variable, $offset)
{
    /*
     * glm15-2: the write-shape scan runs on the string-masked copy, so
     * '$map[...]', 'function ...(' or '$map = scalar;' text inside a
     * quoted string or heredoc body can neither refuse a legitimate
     * map-literal proof nor launder one (same length as the source, so
     * the offsets are interchangeable).
     *
     * glm18-7: the scanned region is every region whose writes the
     * include can read — the pre-include prefix plus any loop construct
     * spanning the include (a write placed after it inside that loop
     * still executes before the include's next iteration). Regions are
     * concatenated; a seam can only splice unrelated fragments into a
     * false match, which refuses the proof — the safe direction.
     *
     * glm24-6: the view arrives computed — the per-file driver masks
     * once and every analysis below rides that one view (this helper
     * used to re-tokenize the whole file on every consult).
     */
    $before = '';
    foreach (wp_connectors_write_visibility_spans($masked, $offset) as $span) {
        $before .= (string) substr($masked, $span[0], $span[1] - $span[0] + 1);
    }
    $quoted = preg_quote($variable, '/');

    // Element access, append, or element write: $map[...].
    if (preg_match('/' . $quoted . '\s*\[/', $before)) {
        return false;
    }

    // list() destructuring mentioning the map.
    if (preg_match('/\blist\s*\([^)]*' . $quoted . '/i', $before)) {
        return false;
    }

    // Array-write helpers.
    if (preg_match('/(?:array_push|array_unshift|array_splice|unset)\s*\(\s*' . $quoted . '\b/i', $before)) {
        return false;
    }

    // By-reference aliasing (a write channel through &$map).
    if (preg_match('/=\s*&\s*' . $quoted . '\b/', $before)) {
        return false;
    }

    // An occurrence inside a function signature (a parameter default).
    if (preg_match_all('/\bfunction\b/i', $before, $functions, PREG_OFFSET_CAPTURE)) {
        foreach ($functions[0] as $function) {
            $open = strpos($before, '(', $function[1]);
            if (false === $open) {
                continue;
            }
            $depth = 0;
            $length = strlen($before);
            $close = $length - 1;
            for ($i = $open; $i < $length; ++$i) {
                $char = $before[ $i ];
                if ($char === '(') {
                    ++$depth;
                } elseif ($char === ')') {
                    --$depth;
                    if (0 === $depth) {
                        $close = $i;
                        break;
                    }
                }
            }
            if (false !== strpos((string) substr($before, $function[1], $close - $function[1] + 1), $variable)) {
                return false;
            }
        }
    }

    /*
     * Every assignment-shaped write must be a whole-array literal.
     * glm18-8: the operator alternation covers EVERY compound assignment
     * form (+ = - = * = **= /= .= %= &= |= ^= <<= >>= ??=), not just =
     * and .= — a '$map += $other;' union-merge was previously an
     * INVISIBLE write channel, so the element-literal proof concluded
     * all runtime values were the proven literals while the array union
     * injected foreign entries (round 18 #8, empirically confirmed).
     * The op tokens admit no internal whitespace in PHP, so no spacing
     * variants are missed; comparisons (==, !=, <=, >=, =>) stay
     * unmatched through the single-= lookahead and the op classes.
     */
    if (preg_match_all('/' . $quoted . '\s*(?:\?\?=|\*\*=|<<=|>>=|[-+*\/%&|^.]=|=(?![=>]))\s*([^;]+);/', $before, $writes, PREG_SET_ORDER)) {
        foreach ($writes as $write) {
            if (! preg_match('/^(?:array\s*\(|\[)/i', trim($write[1]))) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Same-file assignments to a variable that execute before a byte offset.
 *
 * The ONE assignment collector shared by the literal-free and the mixed
 * include analyses: statements of the shape `$x = …;`, `$x .= …;`, and
 * every other compound-assignment form (glm18-8) whose start lies in a
 * region the include can read a write from — before the include, or
 * anywhere inside a loop construct that also contains it (glm18-7: a
 * write after the include inside that loop still executes before the
 * include's next iteration).
 *
 * GLM10 #14: a foreach VALUE binding is an assignment-shaped read —
 * `foreach ($map as $k => $v)` hands $v every VALUE of $map — so it is
 * also collected, as the synthetic assignment `$v = $map;` that the
 * plain-variable analyses then resolve through $map's own same-file
 * assignment (e.g. the uninstall owner chain's map of literal __DIR__
 * paths). Bindings the foreach regex cannot parse are simply not
 * collected: a variable include through them stays flagged.
 *
 * @param string $code     Comment-stripped source of the file.
 * @param string $masked   String-masked view of the same file (same
 *                         length as $code; computed once per file by
 *                         the driver since glm24-6).
 * @param string $variable Variable token, including the leading '$'.
 * @param int    $offset   Byte offset the include starts at.
 * @return list<string> Assignment statements (each ends with ';').
 */
function wp_connectors_same_file_assignments($code, $masked, $variable, $offset)
{
    /*
     * glm15-2: assignment POSITIONS are matched on the string-masked
     * copy (same length as $code), so an assignment-shaped line inside
     * a quoted string or heredoc body is never collected — a phantom
     * in-string assignment could otherwise satisfy a variable include
     * the runtime never resolves that way. The matched offsets slice
     * the REAL statement (string literals intact) out of $code.
     *
     * glm18-7: an assignment placed AFTER the include is invisible only
     * under straight-line execution — inside a loop the include also
     * sits in, a later write still executes before the include's next
     * iteration, so visibility rides the write-visibility spans (the
     * pre-include prefix plus every spanning loop construct).
     */
    $spans = wp_connectors_write_visibility_spans($masked, $offset);
    $visible = static function (int $at) use ($spans): bool {
        foreach ($spans as $span) {
            if ($at >= $span[0] && $at <= $span[1]) {
                return true;
            }
        }

        return false;
    };

    /*
     * glm18-18 (verifier round): a by-reference alias whose SOURCE is
     * the variable — `$alias = &$var;` — is a write channel the
     * assignment regex cannot see (the collector matches writes TO the
     * variable, never writes THROUGH an alias), so one anywhere in the
     * visible regions refuses the proof entirely — the same channel
     * the map path's array_writes_recognized() has refused since the
     * GLM10 #14 round. The plain-variable path refused nothing:
     * `$alias = &$f; $alias = <foreign>; require $f;` laundered
     * (empirically confirmed).
     */
    foreach ($spans as $span) {
        if (preg_match('/=\s*&\s*' . preg_quote($variable, '/') . '\b/', (string) substr($masked, $span[0], $span[1] - $span[0] + 1))) {
            return array();
        }
    }

    /*
     * glm18-8: the collector's operator alternation matches every
     * compound assignment form too (the same set the write-shape check
     * recognizes) — a '$map += $other;' write collected with its RHS
     * keeps the every-assignment-must-prove rule covering the union:
     * each value source (the prior whole writes, the unioned RHS) is
     * analyzed separately, so a compound write can no longer hide.
     */
    $assignments = array();
    if (preg_match_all('/' . '\$' . preg_quote(substr($variable, 1), '/') . '\s*(?:\?\?=|\*\*=|<<=|>>=|[-+*\/%&|^.]=|=(?![=>]))[^;]+;/', $masked, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $assignment) {
            if (! $visible($assignment[1])) {
                // Outside every region the include can read a write from.
                continue;
            }
            $assignments[] = (string) substr($code, $assignment[1], strlen($assignment[0]));
        }
    }

    if (preg_match_all('/foreach\s*\((.+?)\)\s*\{/', $masked, $foreaches, PREG_OFFSET_CAPTURE)) {
        foreach ($foreaches[1] as $foreach_match) {
            if (! $visible($foreach_match[1])) {
                // The binding is outside every region the include reads.
                continue;
            }
            $foreach = array((string) substr($code, $foreach_match[1], strlen($foreach_match[0])), $foreach_match[1]);
            if (! preg_match('/^(.+?)\s+as\s+(.+)$/s', $foreach[0], $parts)) {
                continue;
            }
            $value_variable = trim($parts[2]);
            $arrow = strpos($value_variable, '=>');
            if (false !== $arrow) {
                $value_variable = trim((string) substr($value_variable, $arrow + 2));
            }
            if ($value_variable !== $variable) {
                continue;
            }
            $source = trim($parts[1]);
            if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $source)
                && ! wp_connectors_array_writes_recognized($masked, $source, $offset)) {
                /*
                 * Verifier round on GLM10 #14: the map can be written in
                 * forms this analysis cannot model ($map[] appends,
                 * element writes, a parameter default the caller's
                 * argument overrides) — the analyzed value set would not
                 * be a superset of the runtime values, so the binding is
                 * refused and the include stays flagged.
                 */
                continue;
            }
            $assignments[] = $variable . ' = ' . $source . ';';
        }
    }

    return $assignments;
}

/**
 * Whether a mixed anchored expression is the mandated PSR-4 autoloader shape.
 *
 * The ONE sanctioned variable include in a plugin: inside the plugin's own
 * src/autoload.php, an expression anchored on __DIR__ whose runtime segments
 * are all str_replace() calls mapping the autoloader's class-name argument
 * into a path, with only downward ('..'-free) literals. PHP class names are
 * identifier-only, so the mapped segments can never traverse; and the file
 * itself is separately pinned by wp_connectors_autoloader_violations()
 * (exactly one registration, bound to the slug-derived prefix). Every other
 * runtime segment anywhere else stays subject to strict resolution.
 *
 * @param string       $file     Absolute path of the file containing the include.
 * @param string       $statement Include statement or plain expression.
 * @param list<string> $segments Runtime segments from
 *                               wp_connectors_include_runtime_segments().
 * @param string       $pluginDir Absolute plugin directory.
 * @return bool True when the narrow autoloader exception applies.
 */
function wp_connectors_is_psr4_autoloader_shape($file, $statement, array $segments, $pluginDir)
{
    if (rtrim((string) $pluginDir, '/') . '/src/autoload.php' !== $file) {
        return false;
    }
    if (strpos($statement, '__DIR__') === false) {
        return false;
    }
    foreach (wp_connectors_quoted_literals($statement) as $literal_pair) {
        if (wp_connectors_literal_is_interpolated($literal_pair[0], $literal_pair[1]) || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $literal_pair[1])) {
            // Only strictly downward static literal segments may surround
            // the class-name mapping (glm29-3: quote-aware — an
            // interpolated literal is runtime text, never sanctioned).
            return false;
        }
    }
    foreach ($segments as $segment) {
        if (! preg_match('/^str_replace\s*\(/i', $segment)) {
            return false;
        }
    }

    return true;
}

/**
 * Reasons an anchored include mixing literals with runtime segments cannot
 * be proven to stay in-root.
 *
 * `require __DIR__ . '/' . $dependency;` carries a quoted literal, which
 * used to select the literal-only analysis and skip the variable part
 * entirely — an indirectly assigned escaping path shipped unnoticed. Every
 * segment is now analyzed: each plain variable resolves through its
 * same-file assignments (the substituted path must stay in-root), and any
 * other runtime segment (function call, array access, runtime-built path)
 * or unresolvable variable is reported. The only exception is the mandated
 * PSR-4 autoloader shape (wp_connectors_is_psr4_autoloader_shape()).
 *
 * @param string $file     Absolute path of the file containing the include.
 * @param string $code     Comment-stripped source of that file.
 * @param string $statement The include statement (starts at the keyword).
 * @param int    $offset   Byte offset of the statement within $code.
 * @param string $pluginDir Absolute plugin directory.
 * @param string $masked   String-masked view of $code (same length;
 *                         computed once per file by the driver, glm24-6).
 * @return list<string> Violation reasons (empty when provably in-root).
 */
function wp_connectors_runtime_segment_reasons($file, $code, $statement, $offset, $pluginDir, $masked)
{
    $segments = wp_connectors_include_runtime_segments($statement);
    if ($segments === array()) {
        return array();
    }
    if (strpos($statement, '__DIR__') === false && strpos($statement, 'ABSPATH') === false) {
        // Unanchored statements are flagged by the literal analysis already.
        return array();
    }
    if (preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $statement)) {
        // Already flagged by the upward-dirname rule; do not double-report.
        return array();
    }
    if (wp_connectors_is_psr4_autoloader_shape($file, $statement, $segments, $pluginDir)) {
        return array();
    }

    $reasons = array();
    foreach ($segments as $segment) {
        if (! preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
            $reasons[] = 'combines the anchor with unresolvable runtime segments';
            continue;
        }
        $assignments = wp_connectors_same_file_assignments($code, $masked, $segment, $offset);
        if ($assignments === array()) {
            $reasons[] = sprintf('depends on %s with no resolvable same-file assignment', $segment);
            continue;
        }
        foreach ($assignments as $assignment) {
            $value = trim((string) preg_replace('/^[^=]*?(?:\.)?=\s*/', '', trim($assignment)), ';');
            if (wp_connectors_include_runtime_segments($value) !== array()) {
                $reasons[] = sprintf('depends on %s built from unresolvable runtime segments', $segment);
                continue;
            }
            // Substitute the resolved value in place, then hold the fully
            // composed path to the same in-root proof as a static include.
            $substituted = (string) str_replace($segment, $value, $statement);
            foreach (wp_connectors_include_expression_reasons($file, $substituted, $pluginDir) as $reason) {
                $reasons[] = sprintf('%s through %s', $reason, $segment);
            }
        }
    }

    return $reasons;
}

/**
 * Reasons a literal-free include/require cannot be proven to stay in-root.
 *
 * An include like `require $dependency;` carries no quoted literal, so the
 * scanner cannot see its target in the statement itself and would report
 * nothing — letting conventions, the builder, and the inspector package a
 * plugin that fails standalone. Strict allow: a plain-variable target passes
 * only when EVERY same-file assignment before the include resolves through
 * wp_connectors_include_expression_reasons() to a provably in-root path —
 * including the mixed-segment proof of wp_connectors_runtime_segment_reasons()
 * for assignments that themselves combine the anchor with runtime segments;
 * any other expression (unassigned variable, function call, array access,
 * runtime-built path) must prove itself the same way or is reported.
 *
 * @param string $file      Absolute path of the file containing the include.
 * @param string $code      Comment-stripped source of that file.
 * @param string $include   The include statement (starts at the keyword).
 * @param int    $offset    Byte offset of the statement within $code.
 * @param string $pluginDir Absolute plugin directory.
 * @param string $masked    String-masked view of $code (same length;
 *                          computed once per file by the driver, glm24-6).
 * @return list<string> Violation reasons (empty when provably in-root).
 */
function wp_connectors_hidden_include_reasons($file, $code, $include, $offset, $pluginDir, $masked)
{
    $argument = trim((string) preg_replace('/^(?:require|include)(?:_once)?\s*/i', '', trim($include)), " \t\n\r();");

    if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $argument)) {
        $assignments = wp_connectors_same_file_assignments($code, $masked, $argument, $offset);
        if ($assignments === array()) {
            return array( sprintf('variable %s has no resolvable same-file assignment', $argument) );
        }
        /*
         * glm28-15: the per-assignment proof rules — the array-literal
         * branch, the variable-through-variable resolution, and the
         * fall-through literal+segment proofs — lived twice (the inner
         * one-level unroll re-implemented the outer body minus its
         * plain-variable branch), so a proof-rule change had to land in
         * both copies. ONE helper judges every assignment at both
         * depths now; see wp_connectors_assignment_value_reasons() for
         * the deliberate two-level resolution cap.
         */
        $reasons = array();
        $prefix = sprintf('variable %s resolves to a path that %%s', $argument);
        foreach ($assignments as $assignment) {
            $expression = trim((string) preg_replace('/^[^=]*?(?:\.)?=\s*/', '', trim($assignment)), ';');
            foreach (wp_connectors_assignment_value_reasons($file, $code, $argument, $argument, $expression, $offset, $pluginDir, $masked, $prefix, 0) as $reason) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    $reasons = wp_connectors_include_expression_reasons($file, $argument, $pluginDir);

    return $reasons === array() ? array() : array( sprintf('expression %s', $reasons[0]) );
}

/**
 * Reasons one same-file assignment's value cannot be proven in-root, at
 * one resolution depth (glm28-15: the ONE per-assignment proof body —
 * the former outer and inner copies of the hidden-include resolution).
 *
 * The branches, all behavior-preserving:
 *
 * - The array-literal branch (GLM10 #14): an assignment whose value is
 *   an array() literal (the uninstall owner chain's map) resolves
 *   through the map's own same-file literal, every element VALUE judged
 *   by the same literal analysis a direct include passes — only when
 *   the OWNED variable's writes are all whole-array literals (an
 *   element write or append the collector cannot see would make the
 *   analyzed values a non-superset of the runtime ones); otherwise the
 *   literal falls through to the proofs below.
 * - The plain-variable branch, at depth 0 ONLY: a variable-valued
 *   assignment resolves through the inner variable's own same-file
 *   assignments, re-entering this helper at depth 1. The TWO-LEVEL cap
 *   is deliberate and load-bearing: it is a complete cycle guard by
 *   construction ($a = $b; $b = $a; terminates at depth 1 through the
 *   fall-through proofs), and a deeper resolution would CHANGE the
 *   verdict for a 3-hop chain (the third hop currently keeps the
 *   generic not-anchored rejection) — a verdict change that must not
 *   ride in as a refactor.
 * - The fall-through proofs: the literal analysis plus the per-segment
 *   runtime proof — an assignment may itself mix the anchor with
 *   runtime segments (`$path = __DIR__ . '/' . $x;`), and at depth 1
 *   the plain-variable RHS lands here exactly as the pre-glm28 inner
 *   block judged it.
 *
 * @param string $file             Absolute path of the file containing the include.
 * @param string $code             Comment-stripped source of that file.
 * @param string $reason_variable  The include's variable (every reason names it).
 * @param string $owned_variable   The variable whose assignment is judged (the
 *                                 array gate checks ITS writes; differs per depth).
 * @param string $expression       The assignment's value expression.
 * @param int    $offset           Byte offset of the include statement within $code.
 * @param string $pluginDir        Absolute plugin directory.
 * @param string $masked           String-masked view of $code (same length).
 * @param string $prefix           The depth's reason template ('... that %s').
 * @param int    $depth            0 at the include's own assignments, 1 one hop in.
 * @return list<string> Violation reasons for this assignment (empty when provably in-root).
 */
function wp_connectors_assignment_value_reasons($file, $code, $reason_variable, $owned_variable, $expression, $offset, $pluginDir, $masked, $prefix, $depth)
{
    $reasons = array();

    if (preg_match('/^(?:array\s*\(|\[)/i', $expression)
        && wp_connectors_array_writes_recognized($masked, $owned_variable, $offset)) {
        foreach (wp_connectors_array_literal_value_reasons($file, $code, $expression, $offset, $pluginDir, $masked) as $reason) {
            $reasons[] = sprintf($prefix, $reason);
        }

        return $reasons;
    }

    if (0 === $depth && preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $expression)) {
        $inner_assignments = wp_connectors_same_file_assignments($code, $masked, $expression, $offset);
        if ($inner_assignments === array()) {
            return array( sprintf('variable %s depends on %s with no resolvable same-file assignment', $reason_variable, $expression) );
        }

        $inner_prefix = sprintf('variable %s resolves through %s to a path that %%s', $reason_variable, $expression);
        foreach ($inner_assignments as $inner_assignment) {
            $inner_expression = trim((string) preg_replace('/^[^=]*?(?:\.)?=\s*/', '', trim($inner_assignment)), ';');
            foreach (wp_connectors_assignment_value_reasons($file, $code, $reason_variable, $expression, $inner_expression, $offset, $pluginDir, $masked, $inner_prefix, 1) as $reason) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    foreach (wp_connectors_include_expression_reasons($file, $expression, $pluginDir) as $reason) {
        $reasons[] = sprintf($prefix, $reason);
    }
    foreach (wp_connectors_runtime_segment_reasons($file, $code, $expression, $offset, $pluginDir, $masked) as $reason) {
        $reasons[] = sprintf($prefix, $reason);
    }

    return $reasons;
}

/**
 * Length-preserving string blanking of one expression's quoted literals
 * (glm22-15).
 *
 * Both passes of the array-literal walk (the whole-literal element split
 * and the per-element value split) judge commas and arrows on the SAME
 * blanked view: quoted contents become runs of 'x' between preserved
 * quote bytes, so cut positions computed on the blanked copy slice the
 * ORIGINAL. Deliberately NOT wp_connectors_mask_string_contents() — that
 * is the token-aware shared masker with different semantics; this helper
 * only ever judges delimiter structure, never code.
 *
 * @param string $expression The expression whose quoted literals to blank.
 * @return string The blanked copy (same byte length).
 */
function wp_connectors_blank_quoted_strings($expression)
{
    return (string) preg_replace_callback(
        '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s',
        static function ($match) {
            return '\'' . str_repeat('x', max(0, strlen($match[0]) - 2)) . '\'';
        },
        $expression
    );
}

/**
 * Reasons an array() literal's element VALUES cannot be proven in-root
 * (GLM10 #14).
 *
 * The map shape the uninstall owner chain uses — one `$map = array( ... )`
 * literal whose values are the include targets a foreach binds — is
 * statically decidable exactly the way a direct include is: every element
 * VALUE expression (the right side of a top-level `=>`, or a bare list
 * element) must pass wp_connectors_include_expression_reasons(). The
 * elements are split on depth-zero commas of a length-preserving
 * string-blanked copy, so commas and arrows inside quoted keys or nested
 * structures never cut an element.
 *
 * @param string $file       Absolute path of the file containing the literal.
 * @param string $code       Comment-stripped source of that file.
 * @param string $expression The array() / [] literal.
 * @param int    $offset     Byte offset the include starts at.
 * @param string $pluginDir  Absolute plugin directory.
 * @param string $masked     String-masked view of $code (same length;
 *                           computed once per file by the driver, glm24-6).
 * @return list<string> Violation reasons (empty when every value is provably in-root).
 */
function wp_connectors_array_literal_value_reasons($file, $code, $expression, $offset, $pluginDir, $masked)
{
    $inner = (string) preg_replace('/^(?:array\s*\(|\[)\s*/i', '', trim($expression));
    $inner = (string) preg_replace('/\s*\)?\]?\s*$/', '', $inner);

    // Blank string contents WITHOUT changing the byte length, so comma
    // cut positions computed on the blanked copy slice the ORIGINAL
    // (glm22-15: the one shared blanking helper; glm28-10: the split
    // rides the ONE depth-zero span walk).
    $blanked = wp_connectors_blank_quoted_strings($inner);

    $elements = array();
    foreach (wp_connectors_depth_zero_spans($blanked, '([', ')]', static function ($view, $i) {
        return ',' === $view[ $i ] ? 1 : 0;
    }) as list($span_start, $span_end)) {
        $elements[] = trim((string) substr($inner, $span_start, $span_end - $span_start));
    }

    $reasons = array();
    $values = 0;
    foreach ($elements as $element) {
        if ('' === $element) {
            continue;
        }

        /*
         * The VALUE side of a top-level `=>` (the arrow inside a nested
         * structure sits below depth zero and never splits this element)
         * — judged on the same shared blanked view (glm22-15; glm28-10:
         * the FIRST depth-zero cut of the ONE span walk, its two-byte
         * predicate advancing past the '>' of '=>').
         */
        $element_blank = wp_connectors_blank_quoted_strings($element);
        $arrow_spans = wp_connectors_depth_zero_spans($element_blank, '([', ')]', static function ($view, $i) {
            return $i + 1 < strlen($view) && '=' === $view[ $i ] && '>' === $view[ $i + 1 ] ? 2 : 0;
        });
        $value = $element;
        if (count($arrow_spans) > 1) {
            $value = trim((string) substr($element, $arrow_spans[0][1] + 2));
        }

        ++$values;
        foreach (wp_connectors_include_expression_reasons($file, $value, $pluginDir) as $reason) {
            $reasons[] = sprintf('includes a map value (%s) that %s', $value, $reason);
        }

        /*
         * Verifier round on GLM10 #14: a value may itself mix the anchor
         * with runtime segments (`__DIR__ . '/' . $page . '.php'`) — the
         * literal analysis above passes it (anchored, carries a quoted
         * literal), so the per-segment proof the direct-assignment branch
         * applies must run here too, or routing an escaping expression
         * through a map + foreach launders what a direct include is
         * flagged for.
         */
        foreach (wp_connectors_runtime_segment_reasons($file, $code, $value, $offset, $pluginDir, $masked) as $reason) {
            $reasons[] = sprintf('includes a map value (%s) that %s', $value, $reason);
        }
    }

    if (0 === $values) {
        return array( 'includes no element values' );
    }

    return $reasons;
}

/**
 * The quoted string literals of an expression, each with its opening
 * quote character (glm29-3).
 *
 * The old quote-blind capture (`[\'"]([^\'"]+)[\'"]`) lost which quote
 * opened a literal, so interpolation judgments could not tell a
 * double-quoted runtime-built string from a single-quoted static one —
 * the laundering hole the interpolation predicate below closes.
 *
 * @param string $expression Include-target expression or statement.
 * @return list<array{0: string, 1: string}> [opening quote, inner text] pairs.
 */
function wp_connectors_quoted_literals($expression)
{
    $literals = array();
    if (preg_match_all('/([\'"])([^\'"]+)\\1/', $expression, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $literals[] = array($match[1], $match[2]);
        }
    }

    return $literals;
}

/**
 * Whether a quoted literal's runtime value is controlled by string
 * interpolation (glm29-3 — the round-29 security fix).
 *
 * A double-quoted literal containing a '$' is DYNAMIC: PHP interpolates
 * `$name`, `{$name}`, `${name}`, and `$$var` spellings into its value at
 * runtime, so the quoted text is NOT the value that reaches the include.
 * The proof machinery used to test only '${', missing every other
 * spelling — `require __DIR__ . "/sub/$name.php";` with
 * `$name = "../../../evil";` laundered out-of-root traversal past every
 * layer (the literal scan judged the interpolated text as a static
 * in-root segment, the runtime-segment blanking erased the whole quoted
 * string, and the assignment substitution never ran because the include
 * statement textually references no variable). Deliberately
 * OVER-detecting: an escaped or trailing literal '$' inside a
 * double-quoted include path is flagged as dynamic too — a false
 * violation a maintainer can see and argue with beats a silent
 * traversal hole (security > tool convenience). Single-quoted literals
 * never interpolate; a '$' there IS the filename.
 *
 * @param string $quote   The literal's opening quote character.
 * @param string $literal The literal's inner text.
 * @return bool True when the runtime value cannot be read off the text.
 */
function wp_connectors_literal_is_interpolated($quote, $literal)
{
    return '"' === $quote && false !== strpos($literal, '$');
}

/**
 * Checks that no PHP file in the plugin escapes the plugin directory.
 *
 * Flags include/require statements with unanchored literal paths, upward
 * dirname() escapes, __DIR__-anchored includes whose '..' segments resolve
 * outside the plugin dir, anchored includes that mix literals with variable
 * or runtime segments that cannot each be proven in-root, literal-free
 * includes whose target (a variable or runtime expression) cannot be proven
 * to stay inside the plugin dir, runtime references to vendor/autoload or
 * Composer, and any reference to the repository-level shared/ source.
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
        /*
         * glm25-8: the file's views come from the ONE shared tokenizer
         * provider — the unused-import scan over the same file in this
         * process reuses the entry instead of re-tokenizing (the
         * unreadable tolerance below is the old (string) cast's: an
         * empty analysis, never a fatal).
         */
        $views = wp_connectors_file_code_views($path);
        $code = null !== $views ? $views['code'] : '';
        $relative = str_replace($pluginDir . '/', '', $path);

        /*
         * glm15-2: the include keyword scan runs on the string-masked
         * copy (same length as $code), so a 'require ...;' or
         * '$x = ...;' written inside a quoted string or heredoc is never
         * analyzed as a statement — matched offsets slice the REAL
         * statement (literals intact) out of $code.
         *
         * glm24-6: this is the ONE mask pass for the file — every
         * analysis below (the assignment collector, the write-shape
         * check, and their callers) receives the view instead of
         * re-tokenizing the whole file per consult (the per-include ×
         * per-segment × per-assignment fan-out used to re-mask O(8×)
         * on files like uninstall.php).
         */
        $masked = null !== $views ? $views['masked'] : '';

        if (preg_match_all('/\b(?:require|include)(?:_once)?\b[^;]*;/', $masked, $includes, PREG_OFFSET_CAPTURE)) {
            foreach ($includes[0] as $include_match) {
                $include = array(substr($code, $include_match[1], strlen($include_match[0])), $include_match[1]);
                $quoted_literals = wp_connectors_quoted_literals($include[0]);
                if ($quoted_literals !== array()) {
                    foreach ($quoted_literals as $literal_pair) {
                        /*
                         * glm29-3: the old '${'-only dynamic test both
                         * missed every other interpolation form ($name,
                         * {$name}, $$var) and SUPPRESSED this unanchored
                         * flag for the forms it did see — leaving an
                         * unanchored runtime-built target flagged
                         * nowhere (the runtime layers route back with
                         * "already flagged by the literal analysis").
                         * Unanchored flags fire regardless of
                         * interpolation now; an anchored interpolated
                         * literal is judged by the runtime layers below.
                         */
                        $anchored = strpos($include[0], '__DIR__') !== false || strpos($include[0], 'ABSPATH') !== false;
                        $escapesUp = (bool) preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $include[0]);
                        if (! $anchored || $escapesUp) {
                            $violations[] = sprintf('%s: %s includes a path not anchored to the plugin dir: %s', $slug, $relative, trim($include[0]));
                        }
                    }
                    if (wp_connectors_anchored_include_escapes_plugin($path, $include[0], $quoted_literals, $pluginDir)) {
                        $violations[] = sprintf('%s: %s includes a path not anchored to the plugin dir: %s', $slug, $relative, trim($include[0]));
                    }
                    // A quoted literal must not select literal-only analysis
                    // and hide the variable parts of the same statement:
                    // `require __DIR__ . '/' . $dependency;` is analyzed
                    // segment by segment like any other hidden target.
                    foreach (wp_connectors_runtime_segment_reasons($path, $code, $include[0], $include[1], $pluginDir, $masked) as $reason) {
                        $violations[] = sprintf('%s: %s includes a target not provably inside the plugin dir (%s): %s', $slug, $relative, $reason, trim($include[0]));
                    }
                } else {
                    // No quoted literal: the target is hidden behind a variable
                    // or a runtime expression the scanner cannot see. Strict
                    // allow — only targets provably inside the plugin root pass.
                    foreach (wp_connectors_hidden_include_reasons($path, $code, $include[0], $include[1], $pluginDir, $masked) as $reason) {
                        $violations[] = sprintf('%s: %s includes a target not provably inside the plugin dir (%s): %s', $slug, $relative, $reason, trim($include[0]));
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
 * The ONE derivation shared by bin/build.php (shared-code namespace
 * rewriting), bin/check-conventions.php (expected autoloader prefix), and
 * the test bootstrap (dev autoloader). Slug segments are capitalized except
 * known acronyms, which keep their documented casing ('openai' -> 'OpenAi',
 * per docs/CONVENTIONS.md).
 *
 * @param string $slug Plugin slug.
 * @return string
 */
function wp_connectors_namespace_suffix_from_slug($slug)
{
    $acronyms = array( 'openai' => 'OpenAi' );

    $parts = array();
    foreach (explode('-', strtolower((string) $slug)) as $segment) {
        $parts[] = isset($acronyms[ $segment ]) ? $acronyms[ $segment ] : ucfirst($segment);
    }

    return implode('', $parts);
}

/**
 * Checks the {SLUG}_VERSION constant matches the header Version.
 *
 * glm25-9: accepts the caller's pre-scanned main-file list (the
 * main_file_violations() idiom — rescanned when empty) — every CLI
 * caller already ran wp_connectors_find_main_plugin_files(), and the
 * rescan re-globbed the whole root and re-read every root .php's
 * head on every conventions/build/inspect run.
 *
 * @param string               $pluginDir Absolute plugin directory.
 * @param array<string,string> $headers   Parsed headers.
 * @param list<string>         $mainFiles Pre-scanned candidates from
 *                                        wp_connectors_find_main_plugin_files()
 *                                        (rescanned when empty).
 * @return list<string> Violation messages.
 */
function wp_connectors_version_constant_violations($pluginDir, array $headers, array $mainFiles = array())
{
    $slug = basename(rtrim($pluginDir, '/'));
    $violations = array();
    if ($mainFiles === array()) {
        $mainFile = wp_connectors_find_main_plugin_file($pluginDir);
    } else {
        $mainFile = $mainFiles[0];
    }
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
