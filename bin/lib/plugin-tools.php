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
        $head = (string) file_get_contents($candidate, false, null, 0, 8192);
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
    $head = (string) file_get_contents($file, false, null, 0, 8192);
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
    $head = (string) file_get_contents($file, false, null, 0, 8192);
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
 * @param string       $file     Absolute path of the file containing the include.
 * @param string       $include  The full include statement.
 * @param list<string> $literals Quoted string literals of the statement, in order.
 * @param string       $pluginDir Absolute plugin directory.
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
    foreach ($literals as $literal) {
        if (strpos($literal, '${') !== false) {
            // Dynamic segment: the target cannot be resolved statically.
            return false;
        }
        if (preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $literal)) {
            $walksUp = true;
        }
    }
    if (! $walksUp) {
        // A purely downward path can never leave the plugin dir.
        return false;
    }

    $resolved = dirname($file);
    foreach ($literals as $literal) {
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
    if (! preg_match_all('/[\'"]([^\'"]+)[\'"]/', $expression, $matches)) {
        return array( 'combines the anchor with unresolvable runtime segments' );
    }
    foreach ($matches[1] as $literal) {
        if (strpos($literal, '${') !== false) {
            return array( 'contains a dynamic ${...} segment' );
        }
    }
    if (wp_connectors_anchored_include_escapes_plugin($file, $expression, $matches[1], $pluginDir)) {
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
    $blanked = (string) preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/', "''", $argument);

    $segments = array();
    $current = '';
    $depth = 0;
    $length = strlen($blanked);
    for ($i = 0; $i < $length; ++$i) {
        $char = $blanked[ $i ];
        if ($char === '(' || $char === '[') {
            ++$depth;
        } elseif ($char === ')' || $char === ']') {
            --$depth;
        }
        if ($char === '.' && $depth === 0) {
            $segments[] = $current;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $segments[] = $current;

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
 * Whether every textual WRITE to a variable before an offset is a form
 * the map-literal analysis models (GLM10 verifier round on #14).
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
 * @param string $code     Comment-stripped source of the file.
 * @param string $variable Variable token, including the leading '$'.
 * @param int    $offset   Byte offset the include starts at.
 * @return bool True when only whole-array literal writes precede the offset.
 */
function wp_connectors_array_writes_recognized($code, $variable, $offset)
{
    /*
     * glm15-2: the write-shape scan runs on the string-masked copy, so
     * '$map[...]', 'function ...(' or '$map = scalar;' text inside a
     * quoted string or heredoc body can neither refuse a legitimate
     * map-literal proof nor launder one (same length as $code, so the
     * offsets are interchangeable).
     */
    $before = (string) substr(wp_connectors_mask_string_contents($code), 0, $offset);
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

    // Every assignment-shaped write must be a whole-array literal.
    if (preg_match_all('/' . $quoted . '\s*(?:\.\s*=|=(?![=>]))\s*([^;]+);/', $before, $writes, PREG_SET_ORDER)) {
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
 * include analyses: statements of the shape `$x = …;` / `$x .= …;` whose
 * start precedes $offset (an assignment after the include cannot be read
 * by it).
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
 * @param string $variable Variable token, including the leading '$'.
 * @param int    $offset   Byte offset the include starts at.
 * @return list<string> Assignment statements (each ends with ';').
 */
function wp_connectors_same_file_assignments($code, $variable, $offset)
{
    /*
     * glm15-2: assignment POSITIONS are matched on the string-masked
     * copy (same length as $code), so an assignment-shaped line inside
     * a quoted string or heredoc body is never collected — a phantom
     * in-string assignment could otherwise satisfy a variable include
     * the runtime never resolves that way. The matched offsets slice
     * the REAL statement (string literals intact) out of $code.
     */
    $masked = wp_connectors_mask_string_contents($code);

    $assignments = array();
    if (preg_match_all('/' . '\$' . preg_quote(substr($variable, 1), '/') . '\s*(?:\.)?=(?![=>])[^;]+;/', $masked, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $assignment) {
            if ($assignment[1] >= $offset) {
                // Assigned after the include — the include cannot read it.
                continue;
            }
            $assignments[] = (string) substr($code, $assignment[1], strlen($assignment[0]));
        }
    }

    if (preg_match_all('/foreach\s*\((.+?)\)\s*\{/', $masked, $foreaches, PREG_OFFSET_CAPTURE)) {
        foreach ($foreaches[1] as $foreach_match) {
            if ($foreach_match[1] >= $offset) {
                // The loop body (and its includes) runs after the binding.
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
                && ! wp_connectors_array_writes_recognized($code, $source, $offset)) {
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
    if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $statement, $matches)) {
        foreach ($matches[1] as $literal) {
            if (strpos($literal, '${') !== false || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $literal)) {
                // Only strictly downward literal segments may surround the
                // class-name mapping.
                return false;
            }
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
 * @return list<string> Violation reasons (empty when provably in-root).
 */
function wp_connectors_runtime_segment_reasons($file, $code, $statement, $offset, $pluginDir)
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
        $assignments = wp_connectors_same_file_assignments($code, $segment, $offset);
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
 * @return list<string> Violation reasons (empty when provably in-root).
 */
function wp_connectors_hidden_include_reasons($file, $code, $include, $offset, $pluginDir)
{
    $argument = trim((string) preg_replace('/^(?:require|include)(?:_once)?\s*/i', '', trim($include)), " \t\n\r();");

    if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $argument)) {
        $assignments = wp_connectors_same_file_assignments($code, $argument, $offset);
        if ($assignments === array()) {
            return array( sprintf('variable %s has no resolvable same-file assignment', $argument) );
        }
        $reasons = array();
        foreach ($assignments as $assignment) {
            $expression = trim((string) preg_replace('/^[^=]*?(?:\.)?=\s*/', '', trim($assignment)), ';');

            /*
             * GLM10 #14: an assignment whose value is an array() literal
             * (the uninstall owner chain's map) or a plain VARIABLE bound
             * by a foreach over one resolves through the map's own
             * same-file literal — every element VALUE must prove in-root
             * by the same literal analysis a direct include passes.
             * Verifier round: only when the owned variable's writes are
             * all whole-array literals (an element write or append the
             * assignment collector cannot see would make the analyzed
             * values a non-superset of the runtime ones); otherwise the
             * literal falls through to the not-anchored rejection.
             */
            if (preg_match('/^(?:array\s*\(|\[)/i', $expression)
                && wp_connectors_array_writes_recognized($code, $argument, $offset)) {
                foreach (wp_connectors_array_literal_value_reasons($file, $code, $expression, $offset, $pluginDir) as $reason) {
                    $reasons[] = sprintf('variable %s resolves to a path that %s', $argument, $reason);
                }
                continue;
            }

            if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $expression)) {
                $inner_assignments = wp_connectors_same_file_assignments($code, $expression, $offset);
                if ($inner_assignments === array()) {
                    $reasons[] = sprintf('variable %s depends on %s with no resolvable same-file assignment', $argument, $expression);
                    continue;
                }
                foreach ($inner_assignments as $inner_assignment) {
                    $inner_expression = trim((string) preg_replace('/^[^=]*?(?:\.)?=\s*/', '', trim($inner_assignment)), ';');
                    if (preg_match('/^(?:array\s*\(|\[)/i', $inner_expression)
                        && wp_connectors_array_writes_recognized($code, $expression, $offset)) {
                        foreach (wp_connectors_array_literal_value_reasons($file, $code, $inner_expression, $offset, $pluginDir) as $reason) {
                            $reasons[] = sprintf('variable %s resolves through %s to a path that %s', $argument, $expression, $reason);
                        }
                        continue;
                    }
                    foreach (wp_connectors_include_expression_reasons($file, $inner_expression, $pluginDir) as $reason) {
                        $reasons[] = sprintf('variable %s resolves through %s to a path that %s', $argument, $expression, $reason);
                    }
                    // An inner assignment may itself mix the anchor with
                    // runtime segments — the same per-segment proof the
                    // direct case applies, or a trailing `$x` would ride
                    // an anchored literal unnoticed.
                    foreach (wp_connectors_runtime_segment_reasons($file, $code, $inner_expression, $offset, $pluginDir) as $reason) {
                        $reasons[] = sprintf('variable %s resolves through %s to a path that %s', $argument, $expression, $reason);
                    }
                }
                continue;
            }

            foreach (wp_connectors_include_expression_reasons($file, $expression, $pluginDir) as $reason) {
                $reasons[] = sprintf('variable %s resolves to a path that %s', $argument, $reason);
            }
            // An assignment may itself mix the anchor with runtime segments
            // (`$path = __DIR__ . '/' . $x;`) — it must pass the same
            // per-segment proof, not just the literal one.
            foreach (wp_connectors_runtime_segment_reasons($file, $code, $expression, $offset, $pluginDir) as $reason) {
                $reasons[] = sprintf('variable %s resolves to a path that %s', $argument, $reason);
            }
        }

        return $reasons;
    }

    $reasons = wp_connectors_include_expression_reasons($file, $argument, $pluginDir);

    return $reasons === array() ? array() : array( sprintf('expression %s', $reasons[0]) );
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
 * @return list<string> Violation reasons (empty when every value is provably in-root).
 */
function wp_connectors_array_literal_value_reasons($file, $code, $expression, $offset, $pluginDir)
{
    $inner = (string) preg_replace('/^(?:array\s*\(|\[)\s*/i', '', trim($expression));
    $inner = (string) preg_replace('/\s*\)?\]?\s*$/', '', $inner);

    // Blank string contents WITHOUT changing the byte length, so comma
    // cut positions computed on the blanked copy slice the ORIGINAL.
    $blanked = (string) preg_replace_callback(
        '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s',
        static function ($match) {
            return '\'' . str_repeat('x', max(0, strlen($match[0]) - 2)) . '\'';
        },
        $inner
    );

    $elements = array();
    $cut = -1;
    $depth = 0;
    $length = strlen($blanked);
    for ($i = 0; $i < $length; ++$i) {
        $char = $blanked[ $i ];
        if ($char === '(' || $char === '[') {
            ++$depth;
        } elseif ($char === ')' || $char === ']') {
            --$depth;
        }
        if ($char === ',' && $depth === 0) {
            $elements[] = trim((string) substr($inner, $cut + 1, $i - $cut - 1));
            $cut = $i;
        }
    }
    $tail = trim((string) substr($inner, $cut + 1));
    if ('' !== $tail) {
        $elements[] = $tail;
    }

    $reasons = array();
    $values = 0;
    foreach ($elements as $element) {
        if ('' === $element) {
            continue;
        }

        // The VALUE side of a top-level `=>` (the arrow inside a nested
        // structure sits below depth zero and never splits this element).
        $element_blank = (string) preg_replace_callback(
            '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s',
            static function ($match) {
                return '\'' . str_repeat('x', max(0, strlen($match[0]) - 2)) . '\'';
            },
            $element
        );
        $depth = 0;
        $value = $element;
        $length = strlen($element_blank);
        for ($i = 0; $i < $length - 1; ++$i) {
            $char = $element_blank[ $i ];
            if ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
            }
            if (0 === $depth && '=' === $char && '>' === $element_blank[ $i + 1 ]) {
                $value = trim((string) substr($element, $i + 2));
                break;
            }
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
        foreach (wp_connectors_runtime_segment_reasons($file, $code, $value, $offset, $pluginDir) as $reason) {
            $reasons[] = sprintf('includes a map value (%s) that %s', $value, $reason);
        }
    }

    if (0 === $values) {
        return array( 'includes no element values' );
    }

    return $reasons;
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
        $code = wp_connectors_strip_comments((string) file_get_contents($path));
        $relative = str_replace($pluginDir . '/', '', $path);

        /*
         * glm15-2: the include keyword scan runs on the string-masked
         * copy (same length as $code), so a 'require ...;' or
         * '$x = ...;' written inside a quoted string or heredoc is never
         * analyzed as a statement — matched offsets slice the REAL
         * statement (literals intact) out of $code.
         */
        $masked = wp_connectors_mask_string_contents($code);

        if (preg_match_all('/\b(?:require|include)(?:_once)?\b[^;]*;/', $masked, $includes, PREG_OFFSET_CAPTURE)) {
            foreach ($includes[0] as $include_match) {
                $include = array(substr($code, $include_match[1], strlen($include_match[0])), $include_match[1]);
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $include[0], $literals)) {
                    foreach ($literals[1] as $literal) {
                        $dynamic = (strpos($literal, '${') !== false);
                        $anchored = strpos($include[0], '__DIR__') !== false || strpos($include[0], 'ABSPATH') !== false;
                        $escapesUp = (bool) preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $include[0]);
                        if ((! $anchored && ! $dynamic) || $escapesUp) {
                            $violations[] = sprintf('%s: %s includes a path not anchored to the plugin dir: %s', $slug, $relative, trim($include[0]));
                        }
                    }
                    if (wp_connectors_anchored_include_escapes_plugin($path, $include[0], $literals[1], $pluginDir)) {
                        $violations[] = sprintf('%s: %s includes a path not anchored to the plugin dir: %s', $slug, $relative, trim($include[0]));
                    }
                    // A quoted literal must not select literal-only analysis
                    // and hide the variable parts of the same statement:
                    // `require __DIR__ . '/' . $dependency;` is analyzed
                    // segment by segment like any other hidden target.
                    foreach (wp_connectors_runtime_segment_reasons($path, $code, $include[0], $include[1], $pluginDir) as $reason) {
                        $violations[] = sprintf('%s: %s includes a target not provably inside the plugin dir (%s): %s', $slug, $relative, $reason, trim($include[0]));
                    }
                } else {
                    // No quoted literal: the target is hidden behind a variable
                    // or a runtime expression the scanner cannot see. Strict
                    // allow — only targets provably inside the plugin root pass.
                    foreach (wp_connectors_hidden_include_reasons($path, $code, $include[0], $include[1], $pluginDir) as $reason) {
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
