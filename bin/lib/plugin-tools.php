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
 * imports on the former composer-pinned 7.4 floor; empirically confirmed in
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
 * The shared source's own namespace — the tree the build's namespace
 * rewriter owns (record 0005): `Deicod\WpConnectors\Shared`.
 *
 * ONE owner for the family vocabulary (review round t31-r7): the build's
 * rewrite postcondition, the architecture sweep's namespace gate, and the
 * token detector below all derive the vendor prefix
 * (`Deicod\WpConnectors`) and the own-tree root from THIS spelling, so
 * the three can never drift about what counts as the shared family.
 *
 * Review round t31-r9-9: the rewriter's PATTERNS derive from this
 * spelling too — the namespace-declaration, use-statement, group-use-
 * prefix, and member-leaf patterns and their replacement sides are
 * built from these segments via preg_quote inside
 * WpConnectorsBuild::rewriteSharedNamespace(), so a family rename is a
 * ONE-EDIT change here, never synchronized two-file edits (the helper's
 * single-ownership claim covers the mechanism now, not just the
 * postcondition).
 *
 * @return string The shared source namespace (the pre-rewrite side).
 */
function wp_connectors_shared_source_namespace()
{
    return 'Deicod\\WpConnectors\\Shared';
}

/**
 * The ONE spelling-pattern generator for any family namespace (verifier
 * round t31-r7-8): every stem segment joined with the
 * whitespace-tolerant separator (`\s*\\\s*`), the leaf segment under a
 * letter-aware lookahead with the brace alternative beside it.
 *
 * The generator exists because the text lens must judge MORE than the
 * source spelling: a consumer that knows a rewritten TARGET prefix
 * (bin/build.php's postcondition, and the sweep's dry-run through it)
 * hands it here so comment/docblock/inline-HTML TEXT naming the target
 * is a finding too — pre-r7-8 a shared source that hand-spelled the
 * building plugin's own target prefix in a docblock was invisible to
 * the text lens (the source pattern cannot match a spelling with the
 * suffix segment between WpConnectors and Shared) while the sweep's
 * name walk refused the same file's code positions: build-green on a
 * dangling reference the sweep flagged, verdict drift.
 *
 * For the shared source namespace the generated bytes are IDENTICAL to
 * the pattern this repo carried as a constant since t31-r4 K1 (verified
 * byte-for-byte at the r7 move) — the three totality dimensions
 * (case-insensitive, whitespace-tolerant between segments, brace-aware)
 * are unchanged; the generator only makes the same shape derivable for
 * any other family spelling.
 *
 * @param string $namespace A family namespace (source or target side).
 * @return string PCRE pattern matching a spelling of that namespace.
 */
function wp_connectors_family_namespace_pattern($namespace)
{
    $segments = array_map(
        static function ( $segment ) {
            return preg_quote( (string) $segment, '/' );
        },
        explode( '\\', ltrim( (string) $namespace, '\\' ) )
    );
    $leaf = array_pop( $segments );
    $stem = implode( '\\s*\\\\\\s*', $segments );

    return '/(?<![A-Za-z0-9_])' . $stem . '\\s*\\\\\\s*(?:' . $leaf . '(?![A-Za-z0-9_])|\\{(?:[^;]*?[\\s,{])?' . $leaf . '(?![A-Za-z0-9_]))/i';
}

/**
 * The SIBLING spelling-pattern for the vendor prefix — the text lens's
 * full-family vocabulary (verifier round t31-r8-3).
 *
 * The name-pattern lens above judges every family reference by
 * STRUCTURE (the vendor prefix exactly, or anything under it), but the
 * text lens tried only the source and target SPELLING patterns: a
 * docblock `@throws \Deicod\WpConnectors\Zai\ApiClient` in a shared
 * source launders exactly where the same sibling in a code or string
 * position refuses — and the dev sweep rides the same detector, so
 * nothing caught it anywhere. One vocabulary at every lens now: this
 * pattern matches the vendor prefix stem in TEXT whenever the next
 * segment is NOT one of the spellings the dedicated patterns already
 * own — which is every remaining family shape: the BARE vendor prefix,
 * and every sibling continuation under it (a brace-group head included:
 * the group's first member is checked like any next segment).
 *
 * The shape rides the same totality dimensions as the family generator
 * (case-insensitive, whitespace-tolerant between the stem's segments,
 * name-boundary aware on both edges), and consumes the sibling's own
 * next segment when one follows so the reported spelling names the
 * sibling (`…\Zai`), never a bare stem with the charge unattributed.
 * The exclusion is a lookahead on the segments AFTER the stem, each
 * alternative a FULL below-vendor tail a dedicated pattern owns (the
 * source tail 'Shared'; the target tail '<Suffix>\Shared' when the
 * consumer knows a target) with the generator's leaf boundary — so
 * 'Shared' excludes `…\Shared\Clock` but never the sibling
 * 'SharedStorage', and the target tail excludes `…\<Suffix>\Shared\…`
 * but never the target-SEGMENT sibling `…\<Suffix>\OAuth` (verifier
 * round t31-r8-9: excluding the suffix segment ALONE waved every
 * spelling under the building plugin's own segment through the build
 * postcondition while the sweep refused the same file — verdict
 * drift, the r7-8 class one segment inside the target tree). The
 * exclusion exists for diagnostics, not verdicts: the source and
 * target patterns run first and return on their own match, but a
 * double-backslash spelling misses them in the RAW view, and the
 * lookahead keeps the fuller spelling's report from being preempted
 * by a bare-stem match.
 *
 * @param list<string> $excluded_tails Namespace tails below the vendor
 *        prefix whose spellings the dedicated patterns own.
 * @return string PCRE pattern matching a sibling/bare spelling of the
 *         vendor prefix in text.
 */
function wp_connectors_family_sibling_pattern(array $excluded_tails)
{
    $own_lower = strtolower(wp_connectors_shared_source_namespace());
    $vendor = substr($own_lower, 0, (int) strrpos($own_lower, '\\'));
    $stem = implode('\\s*\\\\\\s*', array_map(
        static function ( $segment ) {
            return preg_quote( (string) $segment, '/' );
        },
        explode( '\\', $vendor )
    ));

    /*
     * The separator the exclusion and the continuation ride tolerates
     * ONE OR TWO backslashes: a double-backslash spelling (the
     * class-string convention) misses the dedicated patterns in the
     * RAW view, and a single-separator lookahead there would let the
     * sibling's bare stem preempt the fuller report the UNESCAPED view
     * owes — the diagnostics half of the exclusion, held on both
     * spellings of every separator the lens judges.
     */
    $separator = '(?:\\s*\\\\\\s*|\\s*\\\\\\\\\\s*)';

    $excluded = array();
    foreach ( $excluded_tails as $tail ) {
        $excluded[] = implode($separator, array_map(
            static function ( $segment ) {
                return preg_quote( strtolower( (string) $segment ), '/' );
            },
            explode( '\\', (string) $tail )
        )) . '(?![A-Za-z0-9_])';
    }

    return '/(?<![A-Za-z0-9_])' . $stem . '(?![A-Za-z0-9_])(?!' . $separator . '(?:' . implode('|', $excluded) . '))(?:' . $separator . '[A-Za-z_][A-Za-z0-9_]*)?/i';
}

/**
 * The shared-namespace SPELLING pattern — the text lens of the round-7
 * token detector (formerly bin/build.php's
 * WpConnectorsBuild::SHARED_NAMESPACE_SURVIVOR_PATTERN, rounds t31-r3-2 →
 * t31-r4 K1 → t31-r6).
 *
 * THREE totality dimensions, each closing an earlier defect class:
 * case-INsensitive (`/i` — PHP namespaces resolve case-insensitively, the
 * t31-r3-2 posture closed by t31-r4 K1); whitespace-tolerant BETWEEN the
 * segments (a string-literal spelling may break the line, t31-r4-5); and
 * brace-aware (a group-use MEMBER carries `Shared` at a member position,
 * t31-r4-4 — including a member ALIASED exactly `Shared`, which this
 * lens refuses rather than narrowing, per the t31-r4 fail-loud pin).
 *
 * ROUND 7 SCOPES IT TO NON-CODE TEXT (t31-r7): the pattern is applied to
 * comment/docblock, string-literal, and inline-HTML token TEXT — never to
 * code, whose name positions the token walk
 * (wp_connectors_php_name_references()) owns structurally: a comment can
 * INTERRUPT a code name, but the walk reassembles the run and the comment
 * dies by construction, so the pattern never needs to see code bytes
 * (where the rewritable forms legitimately carry the spelling). A PCRE
 * abort is a finding (kind 'pcre-abort'), never a pass — the glm36-8
 * doctrine at the detector's own seams. The TARGET-spelling variant for
 * consumers that know one rides the ONE generator
 * (wp_connectors_family_namespace_pattern()).
 *
 * @return string PCRE pattern matching a spelling of the source namespace.
 */
function wp_connectors_shared_namespace_pattern()
{
    return wp_connectors_family_namespace_pattern( wp_connectors_shared_source_namespace() );
}

/**
 * The index of the first non-trivia token at or after a position, or null.
 *
 * Trivia = the tokens the PHP grammar skips inside statements
 * (whitespace, comments, docblocks). The name walk below consults this to
 * decide what FOLLOWS a keyword or a name run without caring how the
 * trivia was spelled.
 *
 * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
 * @param int                                             $from   Index to start at.
 * @return int|null The next code-token index, or null at the end.
 */
function wp_connectors_next_code_token_index(array $tokens, $from)
{
    for ($count = count($tokens), $i = $from; $i < $count; ++$i) {
        $id = is_array($tokens[ $i ]) ? $tokens[ $i ][0] : null;
        if (null !== $id && (T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * Whether a token id may begin (or continue) an assembled name run.
 *
 * @param int $id Token id.
 * @return bool True for T_STRING and every T_NAME_* token.
 */
function wp_connectors_is_name_token_id($id)
{
    return T_STRING === $id || T_NAME_QUALIFIED === $id || T_NAME_FULLY_QUALIFIED === $id || T_NAME_RELATIVE === $id;
}

/**
 * Assembles the maximal NAME RUN starting at a token index.
 *
 * A run is one T_STRING or T_NAME_* token plus every separator-joined
 * continuation: a T_NS_SEPARATOR (with trivia allowed around it — PHP's
 * lexer only emits single T_NAME_* tokens for UNINTERRUPTED names, so an
 * interrupted spelling arrives as pieces the run reconstitutes) or a
 * T_NAME_FULLY_QUALIFIED directly after trivia (the lexer bakes the
 * leading backslash into the piece that follows an interruption). The
 * assembled name is the concatenation of the run's name and separator
 * token texts with every trivia token dropped — comments and whitespace
 * can INTERRUPT a run, but never contribute bytes to it, which is what
 * makes the comment-interrupted spelling of a namespace visible to the
 * walk by construction (t31-r7: the defect class three regex rounds
 * chased one spelling at a time).
 *
 * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
 * @param int                                             $start  Index of the run's first token.
 * @return array{end: int, name: string} The index of the run's last token
 *         and the assembled name (leading backslashes NOT stripped).
 */
function wp_connectors_name_run(array $tokens, $start)
{
    $count = count($tokens);
    $is_trivia = static function ($token): bool {
        $id = is_array($token) ? $token[0] : null;

        return null !== $id && (T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id);
    };

    $end = $start;
    $name = '';
    $j = $start;
    while (true) {
        $token = $tokens[ $j ];
        $id = is_array($token) ? $token[0] : null;
        if (T_NS_SEPARATOR === $id) {
            $name .= $token[1];
        } elseif (wp_connectors_is_name_token_id($id)) {
            $name .= $token[1];
        }

        // Continuation 1: trivia* then a fully-qualified piece (the lexer
        // baked the leading backslash into it — appending is exact).
        $k = $j + 1;
        while ($k < $count && $is_trivia($tokens[ $k ])) {
            ++$k;
        }
        if ($k < $count && T_NAME_FULLY_QUALIFIED === (is_array($tokens[ $k ]) ? $tokens[ $k ][0] : null)) {
            $j = $k;
            continue;
        }
        /*
         * Continuation 2: trivia* T_NS_SEPARATOR trivia* name-part. The
         * separator's OWN byte is appended with the join — a standalone
         * T_NS_SEPARATOR (the shape trivia-after-separator lexes to) carries
         * the '\' the assembled name needs, and skipping it corrupts the
         * assembly ('Deicod\WpConnectors\' + newline + 'Shared\Clock'
         * assembled as 'Deicod\WpConnectorsShared\Clock', a name no family
         * predicate can match — verifier round t31-r7-6: the whitespace
         * spellings the r4-era regex postcondition refused had become
         * exit-0 ships under the token walk). A fully-qualified name part
         * bakes its own leading backslash in, so its join adds nothing.
         */
        if ($k < $count && T_NS_SEPARATOR === (is_array($tokens[ $k ]) ? $tokens[ $k ][0] : null)) {
            $m = $k + 1;
            while ($m < $count && $is_trivia($tokens[ $m ])) {
                ++$m;
            }
            $part_id = $m < $count && is_array($tokens[ $m ]) ? $tokens[ $m ][0] : null;
            if ($m < $count && wp_connectors_is_name_token_id($part_id)) {
                if (T_NAME_FULLY_QUALIFIED !== $part_id) {
                    $name .= $tokens[ $k ][1];
                }
                $j = $m;
                continue;
            }
        }
        break;
    }

    return array('end' => $j, 'name' => $name);
}

/**
 * Every assembled NAME in a PHP source, with the position kind that
 * decides what the reference may legally be (review round t31-r7's
 * terminal fix: namespace-reference detection at the TOKEN level, not the
 * regex level).
 *
 * One walk over token_get_all() output yields each name with one of
 * three kinds:
 *
 * - 'declaration' — the name of a `namespace X;` statement (the bare
 *   T_NAMESPACE keyword is always a declaration in PHP 8; the relative
 *   `namespace\Foo` operator is its own T_NAME_RELATIVE token and never a
 *   keyword);
 * - 'use' — a name inside a `use` import statement, closure lexical
 *   `use (...)` excluded; a group statement's prefix (the name before
 *   `\{`) is not reported itself, its MEMBERS are reported composed with
 *   the prefix (`use Deicod\WpConnectors\{Shared\Clock}` reports
 *   `Deicod\WpConnectors\Shared\Clock`); `as` aliases are not references
 *   and are not reported (a QUALIFIED post-`as` spelling can never be an
 *   alias and is reported un-composed, verifier round t31-r10-9); a
 *   TRAIT-ADAPTATION block (`use SomeTrait {…}` — the brace glued to the
 *   clause with NO separator) is not an import at all: the glued clause
 *   and every member name report as 'code' positions, un-composed
 *   (round t31-r10-1: the r8-noted misparse read the adaptation clause
 *   as a group prefix and composed the members against the TRAIT name,
 *   so a fully-qualified family reference inside the braces produced
 *   zero carriers — laundered past the detector, the build
 *   postcondition, and the sweep, reproduced as an exit-0 ship). A
 *   MULTI-TRAIT list (`use A, B {…}`) arms the adaptation flag only at
 *   the brace, so its earlier clauses report as 'use' import positions,
 *   un-composed — a family clause there is still refused by both gates
 *   through the rewrite-ownership verdict (the rewriter owns no
 *   adaptation clause), never laundered (verifier round t31-r10-11
 *   restating the classification honestly);
 * - 'code' — every other name position (inline references, catch
 *   clauses, attributes, `::class`, call names).
 *
 * Names are reassembled across whitespace and comments
 * (wp_connectors_name_run()), so a spelling interrupted mid-name is seen
 * exactly like its contiguous twin — PHP 8's parser refuses such
 * spellings in lintable code, but the walk owes totality independent of
 * any lint precondition (the build's postcondition judges rewritten bytes
 * before any lint gate runs).
 *
 * @param string $source PHP source bytes.
 * @return list<array{name: string, lower: string, kind: string, offset: int, line: int}>
 *         Assembled names (leading backslashes stripped), lowercased
 *         twin, position kind, and 0-based byte offset / 1-based line of
 *         the run's first token.
 */
function wp_connectors_php_name_references($source)
{
    return wp_connectors_name_references_from_tokens(token_get_all($source));
}

/**
 * The name walk over an ALREADY-TOKENIZED stream — the body
 * wp_connectors_php_name_references() wraps (verifier round t31-r8-7:
 * one tokenization pass feeds both lenses of the family detector, the
 * name walk and the text lens alike, where each used to re-tokenize
 * the same bytes — the detector's dominant cost, paid twice per call,
 * with file:line bookkeeping a drift risk across the two streams).
 *
 * The walk needs nothing but the token stream: the 1-based line of a
 * reported run is its FIRST token's own line field (the run's first
 * token is always an array token: name token ids are never single-byte
 * tokens). ONE line semantics for the whole detector: the engine
 * counts \n, \r\n, and a lone \r as line terminators, and the text
 * lens derives its lines with the same class (the \R reader at its
 * push seam) — a "\n"-only count the lenses once spelled disagreed
 * with the engine on lone-\r files, drifting the two lenses' lines
 * apart inside one detector run (verifier round t31-r8-11).
 *
 * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
 * @return list<array{name: string, lower: string, kind: string, offset: int, line: int}>
 *         Assembled names, shaped exactly like the wrapper's.
 */
function wp_connectors_name_references_from_tokens(array $tokens)
{
    $count = count($tokens);
    $references = array();
    $offset = 0;
    $use_open = false;
    $group_prefix = null;
    $group_prefix_display = '';
    $group_prefix_offset = 0;
    $group_prefix_line = 0;
    $group_member_seen = false;
    $group_brace_depth = 0;
    $awaiting_group_prefix = false;
    $skip_alias = false;
    $declaration_pending = false;
    $adaptation_block = false;

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[ $i ];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $token_offset = $offset;
        $offset += strlen($text);

        if (T_USE === $id) {
            // A closure's lexical `use (` is not a namespace import; every
            // other `use` opens an import statement whose FIRST name is
            // the group-prefix candidate.
            $follower = wp_connectors_next_code_token_index($tokens, $i + 1);
            $use_open = null !== $follower && '(' !== $tokens[ $follower ];
            $group_prefix = null;
            $group_member_seen = false;
            $group_brace_depth = 0;
            $awaiting_group_prefix = true;
            $skip_alias = false;
            $adaptation_block = false;

            continue;
        }
        if (T_NAMESPACE === $id) {
            /*
             * Only the two LEGAL declaration shapes open one
             * (verifier round t31-r8-10): a bare name or an unqualified
             * sequence. `namespace \X;` (T_NAME_FULLY_QUALIFIED) and
             * `namespace namespace\X;` are parse-error spellings whose
             * names still assemble — classifying them as declarations
             * let the invalid spelling CORRUPT the file's in-effect
             * namespace, and a family-resolving relative after it then
             * resolved against the junk base and laundered past both
             * gates (reproduced: rewrite shipped where the control file
             * refused). They fall to 'code' positions now, where a
             * family spelling refuses in every consumer.
             */
            $follower = wp_connectors_next_code_token_index($tokens, $i + 1);
            $follower_id = null !== $follower && is_array($tokens[ $follower ]) ? $tokens[ $follower ][0] : null;
            $declaration_pending = T_STRING === $follower_id || T_NAME_QUALIFIED === $follower_id;

            continue;
        }

        if (null === $id || ! wp_connectors_is_name_token_id($id)) {
            if ($use_open) {
                /*
                 * The statement-boundary SET (verifier round t31-r8-1):
                 * ';' plus every PHP-mode tag boundary. A close tag IS a
                 * statement terminator — the engine implies the
                 * semicolon at '?>' — but r7-7's reset named only the
                 * ';' spelling, so a hostile `use Foo\Bar as ?>` left
                 * the alias skip armed across the tag and into the
                 * re-entered code, where it silently ATE the next name
                 * run: a family reference there became invisible to
                 * both gates (adversarially confirmed: pre-round REFUSE,
                 * round exit-0 ship). The re-entry tags can never occur
                 * inside a live import statement — an open tag only
                 * ever follows a close tag or starts the file — so
                 * resetting at them too is the invariant worn on both
                 * sides: a mode boundary IS a statement boundary,
                 * whichever side of it the walk stands.
                 */
                if (';' === $token || T_CLOSE_TAG === $id || T_OPEN_TAG === $id || T_OPEN_TAG_WITH_ECHO === $id) {
                    if (null !== $group_prefix && ! $group_member_seen) {
                        $references[] = array(
                            'name' => $group_prefix_display,
                            'lower' => $group_prefix,
                            'kind' => 'use',
                            'offset' => $group_prefix_offset,
                            'line' => $group_prefix_line,
                        );
                    }
                    $use_open = false;
                    $group_prefix = null;
                    $group_member_seen = false;
                    $group_brace_depth = 0;
                    $adaptation_block = false;
                    /*
                     * The alias skip dies with its statement (verifier
                     * round t31-r7-7): a dangling `as` (invalid PHP, but
                     * the walk owes totality independent of lint) armed
                     * the skip, and leaving it armed past the ';' let it
                     * silently EAT the next name run anywhere in the file
                     * — a family reference in that position became
                     * invisible to both gates (adversarially confirmed:
                     * pre-round REFUSE, round exit-0 ship).
                     */
                    $skip_alias = false;
                } elseif ('{' === $token) {
                    /*
                     * A brace NO group prefix owns (round t31-r10-1) opens
                     * an ADAPTATION block: the grammar's group use always
                     * braces after its prefix (`use Prefix\{`), so a
                     * depth-0 brace with no prefix set is the trait
                     * adaptation's own — multi-trait `use A, B {…}`
                     * included, where the clause list reported as imports
                     * above ends and the adaptation members begin. Every
                     * member name reports as a code position, un-composed
                     * (the name-run branch below); the rewriter owns no
                     * adaptation spelling, so the sweep and the build
                     * postcondition refuse a family member in lockstep —
                     * no laundering, no verdict drift.
                     */
                    if (0 === $group_brace_depth && null === $group_prefix) {
                        $adaptation_block = true;
                    }
                    ++$group_brace_depth;
                } elseif ('}' === $token) {
                    // A NESTED close (a brace group inside the members) keeps
                    // the statement's prefix; only the matching close of the
                    // group itself retires it.
                    --$group_brace_depth;
                    if ($group_brace_depth <= 0) {
                        if (null !== $group_prefix && ! $group_member_seen) {
                            /*
                             * THE EMPTY-BODY FENCE (verifier round
                             * t31-r10-9): `use Deicod\WpConnectors\{};`
                             * reported NOTHING — the prefix is not
                             * reported itself and an empty body carries
                             * no member — a zero-carrier spelling through
                             * the whole detector (the group body is the
                             * one import position whose emptiness is
                             * legal PHP's parse error and the walk's
                             * silence). An empty group statement reports
                             * its prefix; every legal body (a member, an
                             * aliased member, a function/const member)
                             * reports at least one name and never trips
                             * the fence.
                             */
                            $references[] = array(
                                'name' => $group_prefix_display,
                                'lower' => $group_prefix,
                                'kind' => 'use',
                                'offset' => $group_prefix_offset,
                                'line' => $group_prefix_line,
                            );
                        }
                        $group_prefix = null;
                        $group_member_seen = false;
                        $group_brace_depth = 0;
                        $adaptation_block = false;
                        $skip_alias = false;
                    }
                } elseif (T_AS === $id) {
                    /*
                     * An adaptation block's `as` renames a METHOD (an
                     * alias the import walk would skip), but the walk's
                     * totality owes the invalid qualified spelling after
                     * it a report, not a skip (t31-r10-1) — the skip arms
                     * for import statements only.
                     */
                    if (! $adaptation_block) {
                        $skip_alias = true;
                    }
                }
                // Whitespace, comments, commas, and the `function`/`const`
                // kind keywords of an import are trivia to this walk.
                $declaration_pending = false;

                continue;
            }

            continue;
        }

        // A name run: assemble it whole before classifying. The offset
        // counter advances over EVERY token the run consumed — trivia
        // included — so it stays true to the stream; the line rides the
        // START token's own line field (t31-r8-7: no source bytes here,
        // and the run's first token is always an array token).
        $run = wp_connectors_name_run($tokens, $i);
        $display = ltrim($run['name'], '\\');
        $token_line = is_array($token) ? (int) $token[2] : 1;
        $offset = $token_offset;
        for ($k = $i; $k <= $run['end']; ++$k) {
            $offset += strlen(is_array($tokens[ $k ]) ? $tokens[ $k ][1] : $tokens[ $k ]);
        }
        $i = $run['end'];

        /*
         * The run's RAW spelling decides its composition rights (verifier
         * round t31-r10-9): an ABSOLUTE run (leading backslash) never
         * resolves against a group prefix — composing it produced
         * `Prefix\Deicod\WpConnectors\…`, a name no family predicate can
         * match, and the reference laundered to zero carriers (reproduced
         * end-to-end: the build shipped a group member importing the
         * source namespace verbatim, exit 0). A QUALIFIED run (any
         * backslash) can never be an `as` ALIAS either — an alias is a
         * bare identifier — so the alias skip eats only bare runs and
         * the invalid qualified post-`as` spelling is REPORTED, the
         * totality principle t31-r10-1 stated for adaptation blocks,
         * owed on the import side too.
         */
        $is_absolute_run = '\\' === ($run['name'][0] ?? '');
        $is_qualified_run = false !== strpos((string) $run['name'], '\\');
        $alias_position = false;
        if ($skip_alias) {
            $skip_alias = false;
            if (! $is_qualified_run) {
                continue;
            }
            $alias_position = true;
        }

        $kind = 'code';
        if ($use_open) {
            /*
             * Only the statement's FIRST name can be the GROUP PREFIX —
             * the name a `\{` follows (`use Prefix\{members};`), the
             * grammar's ONLY brace-after-prefix shape: the separator is
             * part of the prefix. A brace glued DIRECTLY to the clause
             * (`use SomeTrait {…}`) is the trait-ADAPTATION spelling
             * (round t31-r10-1): the clause is a trait REFERENCE, not a
             * prefix — it reports below as a code position, the members
             * report un-composed, and the group-prefix composition never
             * runs. A later run followed by `{` is a member of a NESTED
             * brace group (unparseable PHP, judged anyway — totality over
             * validity), never a new prefix.
             */
            $is_prefix_candidate = $awaiting_group_prefix;
            $awaiting_group_prefix = false;
            if ($is_prefix_candidate && ! $adaptation_block) {
                $brace = wp_connectors_next_code_token_index($tokens, $i + 1);
                $separator_before_brace = null !== $brace && T_NS_SEPARATOR === (is_array($tokens[ $brace ]) ? $tokens[ $brace ][0] : null);
                if ($separator_before_brace) {
                    $brace = wp_connectors_next_code_token_index($tokens, $brace + 1);
                }
                if (null !== $brace && '{' === $tokens[ $brace ]) {
                    if ($separator_before_brace) {
                        $group_prefix = strtolower($display);
                        $group_prefix_display = $display;
                        $group_prefix_offset = $token_offset;
                        $group_prefix_line = $token_line;
                        $group_member_seen = false;

                        continue;
                    }
                    // The adaptation block: mark it and report the clause
                    // as the code reference it is (the fall-through below).
                    $adaptation_block = true;
                }
            }
            if (! $adaptation_block) {
                $kind = 'use';
                if (null !== $group_prefix && ! $is_absolute_run && ! $alias_position) {
                    $display = $group_prefix_display . '\\' . $display;
                }
            }
        } elseif ($declaration_pending) {
            $kind = 'declaration';
        }
        $declaration_pending = false;

        // A reported name inside an open group statement is a MEMBER —
        // the empty-body fence below rides the flag.
        if ($use_open && null !== $group_prefix && ! $adaptation_block) {
            $group_member_seen = true;
        }

        $references[] = array(
            'name' => $display,
            'lower' => strtolower($display),
            'kind' => $kind,
            'offset' => $token_offset,
            'line' => $token_line,
        );
    }

    return $references;
}

/**
 * Every use-statement name of a PHP source, composed and resolved.
 *
 * The thin filter over wp_connectors_php_name_references(): what a file
 * IMPORTS (plain and group-use members alike, aliases dropped), for the
 * import-vocabulary enumeration pins the architecture sweep rides.
 *
 * @param string $source PHP source bytes.
 * @return list<string> The import names, original case, in source order.
 */
function wp_connectors_use_statement_names($source)
{
    $names = array();
    foreach (wp_connectors_php_name_references($source) as $reference) {
        if ('use' === $reference['kind']) {
            $names[] = $reference['name'];
        }
    }

    return $names;
}

/**
 * The runtime value of a static string literal's inner text.
 *
 * Round 7's string lens (t31-r7): a literal whose VALUE names a namespace
 * — most dangerously the double-backslash class-string spelling
 * ('Deicod\\WpConnectors\\Shared\\Clock', whose bytes never spell the
 * single-backslash name) — is judged by what PHP computes from it, not by
 * its bytes. Single-quoted literals resolve \' and \\ only; double-quoted
 * (and heredoc) literals resolve the full escape table (octal, hex,
 * \u{...}, and the standard one-character escapes; an unknown escape
 * keeps both bytes). Nowdoc bodies never resolve escapes — pass the
 * raw inner text with the single-quote semantics of "nothing to do".
 *
 * @param string $quote The literal's quote character ("'" or '"').
 * @param string $inner The literal's inner text (quotes stripped).
 * @return string The value PHP would compute for the literal.
 */
function wp_connectors_unescape_php_string_literal($quote, $inner)
{
    if ("'" === $quote) {
        $value = '';
        $length = strlen($inner);
        for ($i = 0; $i < $length; ++$i) {
            if ('\\' === $inner[ $i ] && $i + 1 < $length && ("'" === $inner[ $i + 1 ] || '\\' === $inner[ $i + 1 ])) {
                $value .= $inner[ ++$i ];

                continue;
            }
            $value .= $inner[ $i ];
        }

        return $value;
    }

    $simple = array('n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'f' => "\f", 'e' => "\x1b", '\\' => '\\', '$' => '$', '"' => '"');
    $value = '';
    $length = strlen($inner);
    for ($i = 0; $i < $length; ++$i) {
        $char = $inner[ $i ];
        if ('\\' !== $char || $i + 1 >= $length) {
            $value .= $char;

            continue;
        }
        $next = $inner[ ++$i ];
        if (isset($simple[ $next ])) {
            $value .= $simple[ $next ];

            continue;
        }
        if ('x' === $next || 'X' === $next) {
            $hex = '';
            while ($i + 1 < $length && strlen($hex) < 2 && false !== stripos('0123456789abcdef', $inner[ $i + 1 ])) {
                $hex .= $inner[ ++$i ];
            }
            if ('' !== $hex) {
                $value .= chr((int) hexdec($hex));

                continue;
            }
            $value .= '\\' . $next;

            continue;
        }
        if ('u' === $next && $i + 1 < $length && '{' === $inner[ $i + 1 ]) {
            $close = strpos($inner, '}', $i + 2);
            if (false !== $close) {
                $codepoint = (int) hexdec(substr($inner, $i + 2, $close - $i - 2));
                if ($codepoint > 0 && $codepoint <= 0x10ffff) {
                    // UTF-8 encoded in place (mbstring is not a dependency
                    // of this tooling; the encoder is four ranges).
                    if ($codepoint < 0x80) {
                        $value .= chr($codepoint);
                    } elseif ($codepoint < 0x800) {
                        $value .= chr(0xc0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3f));
                    } elseif ($codepoint < 0x10000) {
                        $value .= chr(0xe0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f));
                    } else {
                        $value .= chr(0xf0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3f)) . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f));
                    }
                    $i = $close;

                    continue;
                }
            }
            $value .= '\\' . $next;

            continue;
        }
        if (false !== strpos('01234567', $next)) {
            $octal = $next;
            while ($i + 1 < $length && strlen($octal) < 3 && false !== strpos('01234567', $inner[ $i + 1 ])) {
                $octal .= $inner[ ++$i ];
            }
            /*
             * Masked to the low byte (verifier round t31-r11-8): the
             * engine itself wraps an octal escape past \377 ("\400" is
             * chr(0), "\777" is chr(255) — verified), but chr() with a
             * codepoint over 255 DEPRECATES on the 8.5 runtime, and
             * this unescaper runs mid-gate — the deprecation notice
             * pollutes the gate's output while every verdict stays
             * correct. The explicit & 0xFF applies the same wrap the
             * engine applies, deprecation-free.
             */
            $value .= chr(((int) octdec($octal)) & 0xFF);

            continue;
        }
        // An unrecognized escape keeps both bytes, exactly as PHP does.
        $value .= '\\' . $next;
    }

    return $value;
}

/**
 * Every reference to the shared-namespace FAMILY in a PHP source, by
 * token — the ONE detector both namespace gates ride (t31-r7's terminal
 * fix; one implementation, two consumers: bin/build.php's rewrite
 * postcondition and the architecture sweep's namespace gate).
 *
 * The family is the vendor prefix `Deicod\WpConnectors` and everything
 * under it; a reference is reported with a five-way position kind that
 * carries what each consumer needs to judge it:
 *
 * - 'declaration' / 'use' / 'code' — from the name walk
 *   (wp_connectors_php_name_references()); comments are structurally
 *   invisible to it (a comment can only INTERRUPT a name run, never carry
 *   one), so the comment-interrupted spelling dies by construction;
 * - 'relative' — a `namespace\…` operator whose resolution against the
 *   file's declared namespace is a family name in a position the
 *   rewrite does not carry through: EVERY family-resolving relative in
 *   a USE position (verifier round t31-r11-1 — the r8-2 "adapts by
 *   construction" premise is false there: a relative use statement is a
 *   parse error PHP never accepts, so nothing ever adapts; the REWRITER
 *   owns the spelling now, resolving it against the source declaration
 *   and emitting the rewritten fully-qualified import, and a survivor
 *   here is a rewriter miss), plus a relative in any other position
 *   whose base is NOT a rewrite-owned tree (the source root, or the
 *   target root when judging rewritten bytes): such a spelling dangles
 *   inside the plugin, because the rewriter never touches it and the
 *   declaration it resolves against is never rewritten. A relative in a
 *   CODE position under a rewrite-owned tree stays legal — it adapts
 *   through the rewrite by construction (t31-r8-2, still the doctrine
 *   for the position where the premise holds);
 * - 'string' — a string literal (quoted, heredoc, or nowdoc) whose TEXT
 *   spells the family (the whitespace-tolerant pattern, so a value broken
 *   across lines still refuses, t31-r4-5's doctrine) OR whose runtime
 *   VALUE names it (unescaped first — the double-backslash class-string
 *   spelling, finding 4). Interpolated literals are judged on their text
 *   chunks only: their values are runtime-built, the ledgered K1
 *   split-composed boundary, unchanged;
 * - 'comment' — a comment/docblock spelling the family, raw or
 *   double-backslash (a docblock @throws is a finding either way);
 * - 'inline-html' — the family spelled in bytes outside PHP tags;
 * - 'pcre-abort' — a text-lens match aborted (glm36-8: an abort is a
 *   finding, never a pass).
 *
 * @param string      $source           PHP source bytes.
 * @param string|null $target_namespace The consumer's rewritten target
 *        namespace (e.g. 'Deicod\WpConnectors\OpenAiOauth\Shared') whose
 *        spellings the TEXT lens judges alongside the source spelling —
 *        the build's postcondition and the sweep's dry-run through it
 *        pass one, so a comment/docblock naming the target is a finding
 *        too; null judges the source spelling only.
 * @return list<array{name: string, lower: string, kind: string, offset: int, line: int}>
 *         Family references in source order (name as spelled, lowercased
 *         twin, position kind, 0-based byte offset, 1-based line).
 */
function wp_connectors_shared_family_references($source, $target_namespace = null)
{
    $own_lower = strtolower(wp_connectors_shared_source_namespace());
    // The vendor prefix is everything of the own namespace before its
    // final segment — derived, never spelled twice.
    $vendor_lower = substr($own_lower, 0, (int) strrpos($own_lower, '\\'));
    $is_family = static function (string $lower) use ($vendor_lower): bool {
        return $lower === $vendor_lower || 0 === strpos($lower, $vendor_lower . '\\');
    };
    $target_lower = null;
    if (null !== $target_namespace && (string) $target_namespace !== '') {
        $target_lower = strtolower(ltrim((string) $target_namespace, '\\'));
    }

    /*
     * The RELATIVE operator resolves against the file's declared
     * namespace before the family predicates judge it (verifier round
     * t31-r8-2): T_NAME_RELATIVE carries its literal `namespace\` prefix
     * through the walk, so `namespace\WpConnectors\Shared\Clock` in a
     * file declaring `namespace Deicod;` — which PHP resolves to the
     * family name `Deicod\WpConnectors\Shared\Clock` — never matched the
     * vendor predicate and shipped un-rewritten: class-not-found at
     * runtime with both gates green (the declaration itself escaped too,
     * being outside the vendor prefix). The resolution is the one PHP
     * itself performs: declared namespace + '\' + the relative tail
     * (global namespace when nothing is declared yet).
     *
     * THE ADAPTATION CARVE-OUT, restated for verifier round t31-r11-1:
     * a relative spelling resolves against WHATEVER the file declares,
     * so when the declared namespace is a tree the rewrite OWNS (the
     * source root — or the consumer's target root, judging
     * already-rewritten bytes), the declaration is rewritten and a
     * relative in a CODE position follows it — it adapts by construction
     * (`namespace\FormsFixture` survives its file's rewrite resolving
     * under the target — the pinned legal shape). The r8 round extended
     * that premise to EVERY position; it is FALSE in the use position:
     * a relative USE statement is a parse error the engine never
     * accepts (verified on 8.5.10 — `use namespace\Foo;` is a syntax
     * error), so it adapts nowhere; it once rode the rewriter's
     * patterns untouched and shipped inside the zip at exit 0. The
     * rewriter owns the spelling now (it resolves the operator against
     * the SOURCE declaration and rewrites it like any other family
     * spelling), and the detector owns it in lockstep: every
     * family-resolving relative in a USE position reports, base owned
     * or not — on rewritten bytes that can only be a rewriter miss, and
     * the build's postcondition refuses it; at the sweep the dev-time
     * gate is the stricter verdict by design (the spelling is not one
     * legal PHP accepts, so no shared source may carry it, while the
     * build — meeting one anyway in planted bytes — rewrites it and
     * ships working code). Code positions keep the ownership half, and
     * a relative whose resolution is family WHILE its base is NOT
     * rewrite-owned dangles in every position: exactly those report
     * too, under the kind 'relative' — never 'use', whose
     * rewritable-position reading would wave an un-rewritable spelling
     * through the sweep while the build's postcondition refuses it.
     */
    $rewrite_owns = static function (string $base_lower) use ($own_lower, $target_lower): bool {
        foreach (array( $own_lower, $target_lower ) as $root) {
            if (null !== $root && ($base_lower === $root || 0 === strpos($base_lower, $root . '\\'))) {
                return true;
            }
        }

        return false;
    };

    /*
     * ONE tokenization pass feeds BOTH lenses (verifier round
     * t31-r8-7): the name walk and the text lens each used to
     * re-tokenize the same source — the detector's dominant cost, paid
     * twice per call, and a file:line drift risk across the two
     * streams. The walk takes the stream directly
     * (wp_connectors_name_references_from_tokens()); the text-lens
     * loop below reuses the same array.
     */
    $tokens = token_get_all($source);

    $references = array();
    $declared_lower = null;
    $declared_display = '';
    foreach (wp_connectors_name_references_from_tokens($tokens) as $reference) {
        if ('declaration' === $reference['kind']) {
            $declared_lower = $reference['lower'];
            $declared_display = $reference['name'];
        }
        if (0 === strpos($reference['lower'], 'namespace\\')) {
            $tail_lower = substr($reference['lower'], strlen('namespace\\'));
            $resolved_lower = (null !== $declared_lower && '' !== $declared_lower ? $declared_lower . '\\' : '') . $tail_lower;
            /*
             * The use position carries NO carve-out (t31-r11-1): the
             * spelling never adapts (a parse error in PHP), so a
             * family-resolving relative USE statement reports under a
             * rewrite-owned base too — on rewritten bytes a survivor is
             * a rewriter miss, and the postcondition must refuse it.
             */
            $use_position = 'use' === $reference['kind'];
            if ($is_family($resolved_lower) && ($use_position || null === $declared_lower || ! $rewrite_owns($declared_lower))) {
                $references[] = array(
                    'name' => (null !== $declared_lower && '' !== $declared_lower ? $declared_display . '\\' : '') . substr($reference['name'], strlen('namespace\\')),
                    'lower' => $resolved_lower,
                    'kind' => 'relative',
                    'offset' => $reference['offset'],
                    'line' => $reference['line'],
                );
            }

            continue;
        }
        if ($is_family($reference['lower'])) {
            $references[] = $reference;
        }
    }

    /*
     * The TEXT lens's patterns: the source spelling always, plus the
     * consumer's TARGET spelling when one is provided (t31-r7-8: a
     * comment naming the target is a finding too, not an invisible
     * spelling the source pattern cannot match), plus the SIBLING
     * vocabulary (t31-r8-3: the full family predicate — any spelling
     * under the vendor prefix that the dedicated patterns do not own,
     * the bare prefix included — so a docblock naming a sibling refuses
     * exactly where the same sibling in a code or string position
     * does; one vocabulary at every lens). The excluded tails are the
     * FULL below-vendor tails the dedicated patterns own — the source
     * tree's 'Shared' and the target's '<Suffix>\Shared' (t31-r8-9:
     * the suffix segment ALONE over-covered, waving target-SEGMENT
     * siblings like '…\<Suffix>\OAuth' through the build postcondition
     * while the sweep refused them, verdict drift).
     */
    $patterns = array( wp_connectors_shared_namespace_pattern() );
    $vendor_segment_count = count(explode('\\', $vendor_lower));
    $sibling_exclusions = array( implode('\\', array_slice(explode('\\', $own_lower), $vendor_segment_count)) );
    if (null !== $target_lower) {
        $patterns[] = wp_connectors_family_namespace_pattern( (string) $target_namespace );
        $target_lower_segments = explode('\\', $target_lower);
        if (count($target_lower_segments) > $vendor_segment_count) {
            $sibling_exclusions[] = implode('\\', array_slice($target_lower_segments, $vendor_segment_count));
        }
    }
    $patterns[] = wp_connectors_family_sibling_pattern( $sibling_exclusions );
    /*
     * The text lens's line derivation (t31-r8-11): the \R class is the
     * engine's own line semantics for the terminators a PHP file
     * carries (\n, \r\n, and a lone \r), and the reader the sweep's
     * numberedLines() splits by — a "\n"-only count drifted from the
     * name lens's token lines on lone-\r files, quoting the wrong
     * source line in the refusal diagnostics. The count stays
     * MATCH-precise (lines are read at the finding's offset, not the
     * token's start — a finding deep inside a long docblock names its
     * own line).
     */
    $line_of = static function (int $offset) use ($source): int {
        return preg_match_all('/\R/', substr($source, 0, $offset), $line_matches) + 1;
    };
    $push_text_finding = function (string $kind, int $offset, string $spelling) use (&$references, $line_of): void {
        $references[] = array(
            'name' => $spelling,
            'lower' => 'pcre-abort' === $kind ? '' : strtolower(ltrim($spelling, '\\')),
            'kind' => $kind,
            'offset' => $offset,
            'line' => $line_of($offset),
        );
    };
    /*
     * The TEXT lens: each pattern over the token's raw text, then over
     * its backslash-unescaped twin (which also catches single-backslash
     * spellings — the normalization is a no-op for them, so the second
     * view alone carries both spellings). A match on the RAW view is
     * reported at its exact byte offset; a match that only the unescaped
     * view yields is reported at the TOKEN's offset — the two views have
     * different lengths, so the unescaped match offset would be a
     * different byte than the source carries (the token-level position
     * stays honest either way). An abort is a finding, never a pass.
     */
    $text_lens = function (string $kind, string $text, int $offset) use ($patterns, $push_text_finding): void {
        foreach (array( $text, str_replace('\\\\', '\\', $text) ) as $view) {
            foreach ($patterns as $pattern) {
                $hit = array();
                $result = preg_match($pattern, $view, $hit, PREG_OFFSET_CAPTURE);
                if (false === $result) {
                    $push_text_finding('pcre-abort', $offset, 'the shared-namespace text lens aborted (PCRE: ' . preg_last_error_msg() . ')');

                    return;
                }
                if (1 === $result) {
                    $push_text_finding($kind, $view === $text ? $offset + $hit[0][1] : $offset, $hit[0][0]);

                    return;
                }
            }
        }
    };

    // The SAME token stream the name walk rode above (t31-r8-7): the
    // text lens never re-tokenizes what the walk already consumed.
    $count = count($tokens);
    $offset = 0;
    $in_heredoc = false;
    $heredoc_dynamic = false;
    $heredoc_chunks = array();
    $heredoc_offset = 0;
    $heredoc_quote = '"';
    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[ $i ];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $token_offset = $offset;
        $offset += strlen($text);

        if (T_CONSTANT_ENCAPSED_STRING === $id) {
            // Text lens: the raw token (whitespace-tolerant, so a value
            // broken across lines refuses — t31-r4-5's doctrine holds).
            $text_lens('string', $text, $token_offset);
            /*
             * Value lens: what PHP computes from the literal (finding 4:
             * the double-backslash class-string spelling). The token text
             * of a b/B-prefixed literal (the binary-string spelling,
             * `b"\x44eicod…"`) INCLUDES the prefix byte — reading the
             * quote off $text[0] left the prefix inside the enclosure,
             * the unescape shifted by one, and the runtime value carried
             * a stray quote byte that no family predicate could match
             * while the unprefixed twin refused (round t31-r10-2). The
             * prefix is value-free (a b-literal computes exactly what its
             * unprefixed twin computes); strip it and both enclosures
             * normalize identically.
             */
            $literal = ('b' === $text[0] || 'B' === $text[0]) ? substr($text, 1) : $text;
            $quote = $literal[0];
            $value = wp_connectors_unescape_php_string_literal($quote, substr($literal, 1, -1));
            if ($is_family(strtolower($value))) {
                $push_text_finding('string', $token_offset, $value);
            }

            continue;
        }
        if (T_START_HEREDOC === $id) {
            $in_heredoc = true;
            $heredoc_dynamic = false;
            $heredoc_chunks = array();
            $heredoc_offset = $token_offset;
            $heredoc_quote = false !== strpos($text, "'") ? "'" : '"';

            continue;
        }
        if (T_END_HEREDOC === $id) {
            $in_heredoc = false;
            foreach ($heredoc_chunks as $chunk) {
                $text_lens('string', $chunk[0], $chunk[1]);
            }
            // Value lens over the whole body: a heredoc resolves the
            // double-quoted escape table, a nowdoc resolves nothing. An
            // interpolated piece anywhere makes the value runtime-built —
            // the ledgered K1 split-composed boundary (the TEXT lens above
            // still judged every chunk).
            if (! $heredoc_dynamic) {
                $body = '';
                foreach ($heredoc_chunks as $chunk) {
                    $body .= $chunk[0];
                }
                $value = "'" === $heredoc_quote ? $body : wp_connectors_unescape_php_string_literal('"', $body);
                if ($is_family(strtolower($value))) {
                    $push_text_finding('string', $heredoc_offset, $value);
                }
            }
            $heredoc_chunks = array();
            $heredoc_dynamic = false;

            continue;
        }
        if (T_ENCAPSED_AND_WHITESPACE === $id) {
            if ($in_heredoc) {
                $heredoc_chunks[] = array($text, $token_offset);
            } else {
                // A chunk of an INTERPOLATED string: its value is
                // runtime-built (the ledgered boundary), but its TEXT is
                // still judged — byte parity with K1's whole-file scan.
                $text_lens('string', $text, $token_offset);
            }

            continue;
        }
        // Interpolation pieces inside a heredoc mark its value dynamic.
        if ($in_heredoc && (T_VARIABLE === $id || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id)) {
            $heredoc_dynamic = true;
        }
        if (T_COMMENT === $id || T_DOC_COMMENT === $id) {
            $text_lens('comment', $text, $token_offset);

            continue;
        }
        if (T_INLINE_HTML === $id) {
            $text_lens('inline-html', $text, $token_offset);

            continue;
        }
    }

    return $references;
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
    /*
     * The Version header is a version TOKEN (verifier round t31-r5-15):
     * its bytes flow unchecked into the artifact filename and the
     * staging paths, and a traversal spelling ('0.1/../../../vsec')
     * staged the archive and sidecar OUTSIDE dist/ on runtimes whose
     * write paths lexically collapse '..' — residue stranded past the
     * cleanup's raw-spelling unlinks (adversarially confirmed; the
     * pre-round shape published the whole set at the escaped path at
     * exit 0). A token charset with no separators closes the class at
     * the ONE header gate every consumer rides.
     */
    if (isset($headers['version']) && 1 !== preg_match('/\A[A-Za-z0-9._+-]+\z/', $headers['version'])) {
        $violations[] = sprintf('%s: "Version" must be a version token (letters, digits, dots, underscores, hyphens, plus — no separators), found "%s".', $slug, $headers['version']);
    }
    if (isset($headers['requires php']) && '8.2' !== $headers['requires php']) {
        $violations[] = sprintf('%s: "Requires PHP" must be 8.2, found "%s".', $slug, $headers['requires php']);
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
 * destructuring target (list() or its '[ ... ] =' spelling, glm36-1),
 * an array-write helper argument, a by-reference binding, or inside a
 * function signature (a parameter DEFAULT the assignment regex can
 * mistake for the map's definition while the caller's argument wins at
 * runtime). Any unrecognized shape refuses the proof, restoring the
 * flagged default.
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

    /*
     * glm36-8 (verifier round): every refusal below reads preg_match()
     * with `0 !==` — a PCRE abort (backtrack-limit exhaustion, verifier-
     * reproduced with a ~4 KB bracket-run statement) returns FALSE,
     * which reads as "no match" and would spend the call's budget
     * before the REAL write later in the text is ever examined. An
     * error REFUSES the proof now: fail-closed, never fail-open.
     */

    // Element access, append, or element write: $map[...].
    if (0 !== preg_match('/' . $quoted . '\s*\[/', $before)) {
        return false;
    }

    /*
     * list() destructuring mentioning the map. glm36-8: the span is
     * [^;]* (a destructuring statement carries no semicolon), so
     * NESTED parens cross — 'list( list($a), $map )' and
     * 'list( ($a), $map )' laundered through the old [^)]* bound
     * (verifier-reproduced); the widened span also refuses a pure
     * source read ('list($a) = $map') — over-approximate, the safe
     * direction.
     */
    if (0 !== preg_match('/\blist\s*\([^;]*' . $quoted . '\b/i', $before)) {
        return false;
    }

    /*
     * glm36-1: the square-bracket spelling of the destructuring target
     * above — '[ $map ] = source;', PHP 7.1+'s twin of list(). The
     * assignment alternation below anchors on the variable directly
     * followed by an operator, so a bracket-preceded target was an
     * INVISIBLE whole-array write and a foreign rewrite laundered the
     * literal proof (empirically confirmed: the semantically identical
     * list() form refused). The [^;]* spans cross the nested brackets
     * of '[ [ $x ], $map ] =' and the '=>' of keyed spellings (whose
     * literal keys the masked view blanks).
     *
     * glm36-8 (verifier round): NO statement anchor. The glm36-1 form
     * anchored on a ';', '{', '}', or whitespace before the bracket —
     * so '([$map] = ...)', 'if ([$map] = ...)', 'return[$map] = ...',
     * and a call argument 'foo([$map] = ...)' (the bracket preceded by
     * '(' or a keyword character) all laundered. A bracket group an
     * assignment follows can only re-bind what it names, so the
     * unanchored form refuses more — including an element WRITE keyed
     * by the map ('$rows[$map] = 1', a read of the map as index): a
     * documented over-approximation in the safe direction.
     */
    if (0 !== preg_match('/\[[^;]*' . $quoted . '\b[^;]*\]\s*=(?![=>])/', $before)) {
        return false;
    }

    /*
     * glm36-8 (verifier round): a foreach VALUE binding through a
     * bracket group — 'foreach ($rows as [$map])', 'as $k => [$map]',
     * 'as ['k' => $map]' — re-binds the variable per iteration
     * (empirically confirmed laundering on both paths, pre-round and
     * glm36-1 alike: the header's bracket group ends ')' or ',', never
     * '='). The search stays inside the header: the after-'as' spans
     * are paren-bounded, so brackets in the loop BODY or before 'as'
     * (the SOURCE position — the legitimate whole-map iteration) never
     * trip it. The list() twin in the same position was already
     * refused above.
     */
    if (0 !== preg_match('/foreach\s*\([^;]*\bas\b[^;()]*\[[^;()]*' . $quoted . '\b/', $before)) {
        return false;
    }

    /*
     * glm36-8 (verifier round): VARIABLE-VARIABLE writes — '$$name = ...'
     * with $name spelling this variable, or '${'f'} = ...' — never
     * spell the target textually (the masked view blanks the '${'f'}'
     * name), so no write-shape check can see them (verifier-reproduced
     * laundering, pre-existing). One anywhere in the visible regions
     * refuses every proof in the file: dynamic naming defeats static
     * proof wholesale, the by-ref channel's rule (glm18-18).
     */
    if (0 !== preg_match('/\$\$|\$\{/', $before)) {
        return false;
    }

    // Array-write helpers.
    if (0 !== preg_match('/(?:array_push|array_unshift|array_splice|unset)\s*\(\s*' . $quoted . '\b/i', $before)) {
        return false;
    }

    /*
     * By-reference channels (a write through &$map). glm36-8: the
     * aliasing form '=&$map' gains its twin — a foreach VALUE binding
     * 'foreach ($rows as &$map)' hands the variable each element BY
     * REFERENCE and leaves it bound to the last one (verifier-
     * reproduced laundering, pre-existing; the plain '= &' shape never
     * matched it).
     */
    if (0 !== preg_match('/(?:=\s*&|\bas\s*&)\s*' . $quoted . '\b/', $before)) {
        return false;
    }

    // An occurrence inside a function signature (a parameter default).
    $signature_matches = preg_match_all('/\bfunction\b/i', $before, $functions, PREG_OFFSET_CAPTURE);
    if (false === $signature_matches) {
        return false; // A PCRE abort refuses the proof (glm36-8).
    }
    if ($signature_matches > 0) {
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
    $write_matches = preg_match_all('/' . $quoted . '\s*(?:\?\?=|\*\*=|<<=|>>=|[-+*\/%&|^.]=|=(?![=>]))\s*([^;]+);/', $before, $writes, PREG_SET_ORDER);
    if (false === $write_matches) {
        return false; // A PCRE abort refuses the proof (glm36-8).
    }
    if ($write_matches > 0) {
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
     *
     * glm36-1/glm36-8: the destructuring refusals ride the SAME loop —
     * the list() spelling (span [^;]*, so nested parens cross) and its
     * square-bracket twin (NO statement anchor, so '([$f] = ...)',
     * 'if ([$f] = ...)' and 'return[$f] = ...' refuse like their
     * statement-level forms; an element WRITE keyed by the variable,
     * '$rows[$f] = 1', refuses too — over-approximate, the safe
     * direction) — plus the verifier-round channels: a foreach VALUE
     * binding through a bracket group ('as [$f]', 'as $k => [$f]'),
     * the by-ref VALUE binding ('as &$f' beside the '= &' alias
     * above), and VARIABLE-VARIABLE writes ('$$name', '${'f'}' — the
     * target is never spelled textually, so no shape check can see
     * it; one anywhere refuses every proof in the file). Every
     * preg_match() reads `0 !==`: a PCRE abort returns FALSE and must
     * refuse, never read as "no match" (verifier-reproduced
     * backtrack-limit bypass).
     */
    $refused = preg_quote($variable, '/');
    foreach ($spans as $span) {
        $region = (string) substr($masked, $span[0], $span[1] - $span[0] + 1);
        if (0 !== preg_match('/(?:=\s*&|\bas\s*&)\s*' . $refused . '\b/', $region)
            || 0 !== preg_match('/\$\$|\$\{/', $region)
            || 0 !== preg_match('/\blist\s*\([^;]*' . $refused . '\b/i', $region)
            || 0 !== preg_match('/\[[^;]*' . $refused . '\b[^;]*\]\s*=(?![=>])/', $region)
            || 0 !== preg_match('/foreach\s*\([^;]*\bas\b[^;()]*\[[^;()]*' . $refused . '\b/', $region)) {
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
 * The optional $scanRoot (review round t31-r12-12) scopes the WALK
 * tighter than the ANCHOR: every verdict below still judges against
 * $pluginDir, but only files under $scanRoot are visited. Build's
 * composed-tree postcondition rides this with the embed destination
 * subtree — the plugin files beside it were already judged by the
 * pre-gate over the same anchor — so the postcondition stops
 * re-tokenizing bytes whose verdict cannot change.
 *
 * @param string      $pluginDir Absolute plugin directory (the anchoring base).
 * @param string|null $scanRoot  Optional absolute walk root under $pluginDir (default: walk $pluginDir).
 * @return list<string> Violation messages ("<slug>: <file>: <message>").
 */
function wp_connectors_self_containment_violations($pluginDir, $scanRoot = null)
{
    $violations = array();
    $slug = basename(rtrim($pluginDir, '/'));
    /*
     * The WALK may be scoped tighter than the ANCHOR (review round
     * t31-r12-12): build's composed-tree postcondition passes the embed
     * destination subtree as $scanRoot while $pluginDir stays the
     * composed tree root — the plugin files beside the subtree were
     * already judged byte-for-byte by the pre-gate over the SAME
     * anchor, so re-walking them re-tokenized identical bytes for an
     * identical verdict. The anchoring base NEVER narrows with the
     * walk: an include anchored at the plugin root ABOVE the subtree
     * (dirname(__DIR__, 2) . '/helper.php' from src/Shared/…) is
     * inside the artifact and stays legal, exactly as the full-tree
     * walk and the inspector judged it.
     */
    $scanRoot = null === $scanRoot ? $pluginDir : rtrim((string) $scanRoot, '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
    );
    /*
     * glm31-4 (round-31 finding 4): a subdirectory the iterator cannot
     * OPEN mid-recursion aborts the walk with an UnexpectedValueException
     * (it cannot step past what it cannot enter — glm17-17's class, on
     * the sibling scan this file shares). The guard lives HERE, at the
     * ONE shared owner, so every consumer's failure channel fires
     * automatically: the conventions gate counts the returned violation,
     * inspect-artifact lists it, and build's refusing RuntimeException
     * carries it (UnexpectedValueException already extends
     * RuntimeException, so build was the only consumer whose catch
     * held pre-round). The abort converts to the same loud-counted
     * channel glm17-10 gave the unreadable FILE — a named violation,
     * never an uncaught fatal exiting 255 — and the partial violations
     * collected before the abort are kept.
     */
    try {
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            // The extension judgment rides the ONE case-insensitive owner
            // (t31-r4-9): a '.PHP'-spelled file in a plugin tree ships in
            // the zip (collectFiles has no extension filter) and must be
            // scanned here — the exact-case check let it escape every
            // self-containment gate (build and inspect alike).
            if (! wp_connectors_is_php_source($file->getPathname())) {
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
                         *
                         * glm38-7: judged ONCE per include, not once per
                         * quoted literal — the per-literal loop this
                         * replaced never read its variable and recomputed
                         * these two loop-invariant probes from the same
                         * statement, appending the identical violation N
                         * times for an N-literal include.
                         */
                        $anchored = strpos($include[0], '__DIR__') !== false || strpos($include[0], 'ABSPATH') !== false;
                        $escapesUp = (bool) preg_match('/dirname\s*\(\s*__(?:DIR|FILE)__/', $include[0]);
                        if (! $anchored || $escapesUp) {
                            $violations[] = sprintf('%s: %s includes a path not anchored to the plugin dir: %s', $slug, $relative, trim($include[0]));
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
    } catch (UnexpectedValueException $e) {
        $violations[] = sprintf(
            '%s: unreadable subdirectory — the self-containment scan aborted (%s)',
            $slug,
            $e->getMessage()
        );
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
 * Whether a path names a PHP source, by extension, CASE-INSENSITIVELY
 * (review round t31-r4-9).
 *
 * The ONE owner of the is-a-php-source judgment: PHP resolves includes
 * by any extension case ('.PHP' is as loadable as '.php'), so a
 * case-sensitive check made gates disagree — the sweep collected a
 * .PHP source (t31-r3-9) while the PSR-4 gate's basename($path, '.php')
 * never stripped the extension and the self-containment walker skipped
 * the file entirely. Every consumer that judges the extension rides
 * this predicate (the shared-source collector below, the PSR-4 gate's
 * type-name stem, the self-containment and unused-import walkers), so
 * collect, strip, and classify can never disagree again.
 *
 * @param string $path File path or name (only the tail is judged).
 * @return bool True when the name ends in '.php' in any case.
 */
function wp_connectors_is_php_source($path)
{
    return '.php' === strtolower(substr((string) $path, -4));
}

/**
 * The basename with the (any-case) '.php' extension stripped — the ONE
 * extension-strip owner (review round t31-r4-9).
 *
 * basename($path, '.php') strips only the exact-case suffix, so a
 * '.PHP'-spelled source kept its extension and the PSR-4 gate compared
 * a type name against 'ClockMath.PHP' — a misleading failure naming
 * the wrong defect. A name that is not a PHP source (per the ONE
 * predicate above) returns its basename unchanged.
 *
 * @param string $path File path or name.
 * @return string The basename, extension-stripped when it is a '.php' in any case.
 */
function wp_connectors_basename_without_php_extension($path)
{
    $basename = basename((string) $path);

    return wp_connectors_is_php_source($basename) ? substr($basename, 0, -4) : $basename;
}

/**
 * The byte class no path-segment EDGE may carry (verifier round
 * t31-r6-4, extending t31-r6-2): every C0 control byte, DEL, and the
 * dot — the complete set, owned once.
 *
 * ONE owner for the edge-junk class the path judgments strip. The
 * shared-source collector's near-source fence strips it on BOTH
 * sides (a tail hides the extension from the collector; a leading or
 * trailing byte on a collected source's path segment ships a class
 * no label-shaped autoload path can address). The
 * development-entry comparison strips its TRAILING side only (its
 * vocabulary's own members may begin with a dot, and Windows path
 * normalization strips trailing dots and spaces per component, so
 * 'vendor '/'.git ' fold onto the real dev entries at extraction).
 * rtrim/ltrim/trim all take this list verbatim.
 *
 * @return string The strip charlist.
 */
function wp_connectors_path_edge_junk()
{
    return implode('', array_map('chr', range(0, 0x20))) . ".\x7F";
}

/**
 * The ONE development-entry vocabulary both release gates judge by
 * (verifier round t31-r5-10).
 *
 * What the build's collector excludes from a plugin tree and what the
 * artifact inspector rejects as a development entry were two
 * hand-maintained lists — and they had drifted: the inspector forbade
 * the DOTLESS 'phpunit.cache' segment while the builder excluded only
 * '.phpunit.cache', so a plugin carrying a phpunit.cache/ directory
 * shipped through the build at exit 0 and the same zip failed
 * inspection (adversarially confirmed) — the t31-r5-5 contradiction
 * class, one spelling outside the embedded-subtree exemption. ONE
 * owner now: the builder's collector drops any path carrying one of
 * these names as a segment, and the inspector rejects any entry
 * carrying one as a segment (its former separate basename list is
 * subsumed — a basename is a segment). A name joins the list only
 * when a dev tool actually starts dropping it in plugin trees. The
 * segment COMPARISON is owned by wp_connectors_is_development_entry()
 * below (case-insensitive, t31-r6-3) — never in_array/array_intersect
 * at a consumer.
 *
 * @return list<string> Sorted development-entry names (segments and files).
 */
function wp_connectors_development_entry_names()
{
    return array(
        '.git', '.github', '.gitignore', '.gitattributes', '.editorconfig',
        'vendor', 'node_modules', 'dist', 'tools', 'tests', 'test',
        'composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.xml.dist',
        'phpcs.xml', 'phpcs.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
        '.phpunit.result.cache', '.phpcs-cache.json', 'phpcs-cache.json',
        '.phpunit.cache', 'phpunit.cache',
        'package.json', 'package-lock.json', 'Makefile',
        'webpack.config.js', 'vite.config.js',
        'build.json', '.distignore',
    );
}

/**
 * Whether a path segment names a development entry, CASE-INSENSITIVELY
 * (review round t31-r6-3).
 *
 * The ONE comparison owner for the vocabulary above: both release
 * gates compared segments byte-exactly — the builder's collector by
 * array_intersect, the inspector by in_array — so a case-variant
 * spelling ('Tests/Bootstrap.php', 'Build.json', 'VENDOR') was no
 * development entry to EITHER gate: it shipped in the release zip AND
 * passed inspection (reproduced; both gates agreed on the wrong
 * verdict, so the one-verdict checks never fired), while on a
 * case-insensitive extraction target (Windows/macOS hosts) every one
 * of those names folds onto the dev entry it is one case away from —
 * the t31-r5-16 collision doctrine applied to the vocabulary. The
 * judgment folds case now: what the gates exclude is the vocabulary
 * in any casing, and build and inspect give ONE verdict both
 * directions — the build excludes the segment, the inspector rejects
 * the entry. The comparison also strips the segment's TRAILING edge
 * junk (verifier round t31-r6-5, the case fold's sibling byte-class):
 * Windows path normalization strips trailing dots and spaces per
 * component, so 'vendor '/'.git '/'tests\t' fold onto the real dev
 * entries at extraction — and still carry dev content on hosts that
 * preserve the odd spelling. The junk class is the ONE edge-junk
 * owner's; the LEADING side is deliberately not stripped (the
 * vocabulary's own members may begin with a dot — ltrim would
 * destroy the '.git' family).
 *
 * @param string $segment One path segment (a basename is one).
 * @return bool True when the segment matches a vocabulary name in any case.
 */
function wp_connectors_is_development_entry($segment)
{
    $segment = rtrim((string) $segment, wp_connectors_path_edge_junk());
    foreach (wp_connectors_development_entry_names() as $development_entry) {
        if (0 === strcasecmp($segment, $development_entry)) {
            return true;
        }
    }

    return false;
}

/**
 * The embed destination prefix for a plugin slug: "<slug>/src/Shared/"
 * — the ONE spelling owner of where build.json's embed_shared composes
 * the shared library inside a plugin tree (review round t31-r12-10).
 *
 * The writer (bin/build.php's embed loop) and the artifact inspector
 * spelled this prefix independently — the writer with the r5-16
 * CASE-INSENSITIVE collision fence, the inspector with a byte-exact
 * strpos exemption — so a case-variant spelling of the embed territory
 * was refused by the build (the fence folds) while inspection judged it
 * as plugin-owned (the exemption did not): two verdicts on one
 * destination. ONE owner now: the writer builds every destination from
 * this prefix, and the inspector judges embed territory through
 * wp_connectors_is_embed_destination() below, folding case exactly as
 * the writer's fence does — the stricter semantics, never the looser.
 *
 * @param string $slug Plugin slug (the zip's top-level directory).
 * @return string The prefix every embed destination rides ('<slug>/src/Shared/').
 */
function wp_connectors_embed_destination_prefix($slug)
{
    return $slug . '/src/Shared/';
}

/**
 * Whether a zip entry path sits inside the embed destination subtree —
 * CASE-INSENSITIVELY, the writer's fence fold (review round t31-r12-10).
 *
 * The classification exemption this feeds (the inspector exempts the
 * embedded subtree from the development-entry vocabulary, t31-r5-5)
 * folds exactly like the builder's collision fence: any casing of
 * 'src/Shared/' is generated territory by the builder's own doctrine
 * (a plugin-owned case-variant there REFUSES the build). The fold
 * exempts CLASSIFICATION only — traversal, syntax, secret, and
 * self-containment checks still judge every entry under it, so the
 * wider exemption never exempts content.
 *
 * @param string $entry Zip entry path.
 * @param string $slug  The archive's top-level plugin directory.
 * @return bool True when the entry sits under the embed destination in any casing.
 */
function wp_connectors_is_embed_destination($entry, $slug)
{
    return 0 === stripos((string) $entry, wp_connectors_embed_destination_prefix($slug));
}

/**
 * Collects every PHP source file (relative paths) under a source-only tree.
 *
 * The shared/src file vocabulary's ONE owner (review round t31-r3-4):
 * what the build's embed collection ships and what the architecture
 * sweep judges must be the same file set. The embed collection reused
 * collectFiles() — whose EXCLUDED_PATHS drop any subdirectory named
 * tests/tools/dist/vendor — so a shared source living under
 * shared/src/tools/ loaded in development (the dev autoloader walks the
 * whole tree), passed the sweep (same walk), and then silently missed
 * the zip: the shipped plugin fataled on the missing class. Exclusions
 * are a DIST-TREE concept (dev files a plugin directory carries);
 * shared/src is a source-only tree whose PHP sources ALL ship.
 *
 * The extension CASING doctrine (review round t31-r5-3, superseding
 * t31-r3-9/t31-r4-9's collect-any-case posture for THIS tree): the
 * collector accepts only the canonical lowercase '.php' spelling and
 * REFUSES any other casing loudly, naming the file. The shipped
 * autoloader (src/autoload.php — the only loader, bound to the
 * slug-derived prefix) maps class names onto paths by appending the
 * lowercase '.php' literal, so a '.PHP'-spelled source shipped through
 * the embed is a class NO loader can reach on a case-sensitive
 * filesystem — it built, shipped rewritten, and passed inspection
 * while the plugin fataled on the missing class (verified through the
 * real shipped autoloader). Refusing is strictly stronger than the old
 * silently-dead ship and the t31-r3-9 silently-skipped ship both: the
 * file is never invisible. The case-insensitive JUDGMENT owner
 * (wp_connectors_is_php_source) is unchanged and keeps serving the
 * gates that judge EXISTING files in plugin trees (self-containment,
 * unused imports, the inspector's syntax loop, the lint gate).
 *
 * A symlink REFUSES the walk loudly (review round t31-r4-7): the old
 * silent skip was the no-symlinks doctrine's quiet half — a symlinked
 * directory under shared/src loaded in development (the dev autoloader
 * maps class names straight onto paths, link and all), was invisible
 * to the architecture sweep, and missed every zip (reproduced; zero
 * symlinks in the tree today, so this is the doctrine made loud, not a
 * live incident). The build embed and the sweep share this ONE
 * collector, so the refusal fires in every channel that touches the
 * source tree.
 *
 * @param string $dir Absolute source-only directory (shared/src).
 * @return list<string> Sorted relative .php file paths.
 * @throws RuntimeException When the tree carries a symlink, a
 *                          non-canonical extension casing, or a
 *                          near-source spelling (an edge byte hiding
 *                          the extension or riding a path segment).
 */
function wp_connectors_php_source_files($dir)
{
    $files = array();
    $dir = rtrim((string) $dir, '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isLink()) {
            throw new RuntimeException(sprintf(
                'shared source tree carries a symlink (%s -> %s) — the no-symlinks doctrine refuses the walk instead of silently skipping a source that loads in development and misses the zip',
                $file->getPathname(),
                (string) $file->getLinkTarget()
            ));
        }
        if (! $file->isFile()) {
            continue;
        }
        $relative = str_replace($dir . '/', '', $file->getPathname());
        // The extension judgment rides the ONE case-insensitive owner
        // (t31-r4-9): nothing is silently skipped by a casing the
        // judgment cannot see. For THIS tree the judgment is then
        // narrowed by the casing doctrine (t31-r5-3): a source that is
        // a PHP file by any case but not by the canonical lowercase
        // spelling REFUSES — the shipped autoloader probes '.php'
        // lowercase, so any other casing ships a class nothing loads.
        if (! wp_connectors_is_php_source($relative)) {
            /*
             * Near-source spellings refuse first (verifier round
             * t31-r5-14): a name whose trailing whitespace or dot
             * hides the extension ('ClockMath.php ', 'ClockMath.php.')
             * is a file a human READS as a PHP source while every gate
             * — this collector, the sweep, the dev autoloader's
             * class-to-path map — judges it as not one: it builds
             * clean, ships nowhere, and a class declared inside it is
             * a class-not-found fatal with build and inspect green
             * (adversarially confirmed) — the exact silently-invisible
             * ship the r5-3 doctrine claims never happens. Refusing
             * closes the neighborhood at the ONE owner. (Names that
             * are merely DIFFERENT — 'ClockMath.phpé', 'Notes.md' —
             * stay out of scope: nothing loads them in development
             * either, so no divergence exists.)
             *
             * The tail strip rides the ONE edge-junk owner
             * (wp_connectors_path_edge_junk()): r5-14's charlist
             * (" \t.") missed \n/\r/\v/\f (review round t31-r6-2), and
             * r6-2's own literal still missed the rest of the C0
             * controls and DEL — 'ClockMath.php\x01' was STILL
             * neither collected nor refused (verifier round
             * t31-r6-4, reproduced) — so the class is owned once,
             * completely: every byte 0x00-0x20, DEL, and the dot. A
             * filename hiding the extension behind ANY trailing byte
             * is the same near-source spelling and refuses the same
             * way.
             */
            $trimmedTail = rtrim(basename($relative), wp_connectors_path_edge_junk());
            if ('' !== $trimmedTail && wp_connectors_is_php_source($trimmedTail)) {
                throw new RuntimeException(sprintf(
                    'shared source %s is a NEAR-SOURCE spelling (trailing whitespace, control byte, or dot hides the extension) — it reads as a PHP source but is invisible to every gate and absent from every ship; rename it to the canonical .php',
                    $dir . '/' . $relative
                ));
            }
            continue;
        }
        /*
         * Near-source spellings, the LEADING side (verifier round
         * t31-r6-4): the fence above guards names whose TAIL hides
         * the extension from the collector; the mirror defect is a
         * COLLECTED source whose path segment carries an edge byte —
         * ' ClockMath.php', '.ClockMath.php', 'Clock /Math.php'. It
         * collects and ships while the shipped autoloader maps class
         * names onto LABEL-SHAPED paths (class names carry no
         * whitespace, control bytes, or dots), so the file ships a
         * class no loader can address (reproduced: class_exists
         * through the real shipped autoloader false, build and
         * inspect green) — the t31-r5-3 dead-ship class, from the
         * other edge. Every segment of a collected source's path
         * must survive its own edge strip.
         */
        foreach (explode('/', $relative) as $segment) {
            if ($segment !== trim($segment, wp_connectors_path_edge_junk())) {
                throw new RuntimeException(sprintf(
                    'shared source %s is a NEAR-SOURCE spelling (a path segment carries a leading or trailing whitespace, control byte, or dot) — it collects and ships, but the shipped autoloader maps class names onto label-shaped paths, so its class is a class no loader can address; rename the segment',
                    $dir . '/' . $relative
                ));
            }
        }
        if ('.php' !== substr($relative, -4)) {
            throw new RuntimeException(sprintf(
                'shared source %s carries a non-canonical extension casing — the shipped autoloader maps class names onto lowercase ".php" paths, so any other casing ships a class no loader reaches on a case-sensitive filesystem; rename the source',
                $dir . '/' . $relative
            ));
        }
        /*
         * The PSR-4 CASING-AGREEMENT fence for the embedded tree
         * (verifier round t31-r8-4): the r5-3 doctrine fenced the
         * EXTENSION's casing but not the DIRECTORIES' —
         * shared/src/tools/Helper.php declaring `…\Tools;` collected,
         * staged at src/Shared/tools/, passed inspection, and
         * published, while the shipped autoloader maps the class name
         * `…\Tools\Helper` onto `src/Shared/Tools/Helper.php`
         * verbatim: class_exists through the real shipped loader was
         * FALSE with every gate green (end-to-end reproduced). The
         * staged path's directory segments must agree with the declared
         * namespace's segments below the shared root CASE-EXACTLY
         * (depth included) — the root's own casing stays the family
         * detector's and the rewrite postcondition's charge, since the
         * build itself maps the root onto src/Shared/ regardless of
         * spelling. A missing declaration refuses too: a global-
         * namespace source staged under src/Shared/ is a tree no
         * autoload path can address.
         */
        // @: the diagnostic is suppressed, the failed return owned below — the glm17-16 idiom.
        $contents = @file_get_contents($dir . '/' . $relative);
        if (false === $contents) {
            throw new RuntimeException(sprintf(
                'shared source %s cannot be read for the PSR-4 casing fence — an unreadable source refuses the walk, never ships unverified',
                $dir . '/' . $relative
            ));
        }
        /*
         * Every declaration the source carries, not just the first
         * (verifier round t31-r8-8): the fence's first cut broke at the
         * file's first namespace block, so a legal multi-block source —
         * first block agreeing with its staged path, second block one
         * level deeper — collected, rewrote clean, passed inspection,
         * and published while the second block's class mapped onto a
         * path nothing stages (interface_exists through the shipped
         * autoloader FALSE, end-to-end reproduced). The embed maps ONE
         * staged path per file, so a SECOND declaration block stages
         * nowhere at all — refused outright, with both spellings named.
         */
        $declarations = array();
        foreach (wp_connectors_php_name_references($contents) as $reference) {
            if ('declaration' === $reference['kind']) {
                $declarations[] = $reference['name'];
            }
        }
        if ($declarations === array()) {
            throw new RuntimeException(sprintf(
                'shared source %s declares no namespace — the embed stages it under src/Shared/, a tree only the slug-derived namespace prefix addresses, so a global-namespace source ships a class no loader can reach; declare %s\\… in it',
                $dir . '/' . $relative,
                wp_connectors_shared_source_namespace()
            ));
        }
        if (count($declarations) > 1) {
            throw new RuntimeException(sprintf(
                'shared source %s declares %d namespaces (%s) — the embed stages ONE path per file, so a second block\'s classes stage nowhere the shipped autoloader addresses (class_exists false with every gate green); split the blocks into one file per namespace',
                $dir . '/' . $relative,
                count($declarations),
                implode(', ', $declarations)
            ));
        }
        $declared_namespace = $declarations[0];
        $root_lower_segments = explode('\\', strtolower(wp_connectors_shared_source_namespace()));
        $declared_segments = explode('\\', ltrim($declared_namespace, '\\'));
        if (count($declared_segments) < count($root_lower_segments)
            || array_map('strtolower', array_slice($declared_segments, 0, count($root_lower_segments))) !== $root_lower_segments) {
            throw new RuntimeException(sprintf(
                'shared source %s declares %s — not the shared tree root %s the embed rewrites and stages under src/Shared/, so its staged path maps no autoloadable class; declare the tree root (or deeper) in it',
                $dir . '/' . $relative,
                $declared_namespace,
                wp_connectors_shared_source_namespace()
            ));
        }
        $below_root = array_slice($declared_segments, count($root_lower_segments));
        $directories = array();
        foreach (explode('/', dirname($relative)) as $segment) {
            if ('' !== $segment && '.' !== $segment) {
                $directories[] = $segment;
            }
        }
        if ($below_root !== $directories) {
            throw new RuntimeException(sprintf(
                'shared source %s declares %s but its staged path spells the namespace directories %s — the shipped autoloader maps class names onto paths verbatim (PSR-4, case-sensitive), so the casing disagreement ships a class no loader reaches; rename the directory or the declaration so they agree exactly',
                $dir . '/' . $relative,
                $declared_namespace,
                implode('\\', $directories === array() ? array( '(the tree root)' ) : $directories)
            ));
        }
        $files[] = $relative;
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * Locale-independent ASCII case folds for the tooling (verifier round
 * t31-r11-6) — the bin-side twins of the shared tree's AsciiFold
 * (shared/src/Support/AsciiFold.php, the r2-14 owner). This file is
 * standalone tooling: it loads without the autoloader into build and
 * check processes, so it cannot reach the shared class — the byte
 * tables are spelled here instead, and the two owners share the
 * doctrine, not a require.
 *
 * WHY the tooling needs them: strtolower()/strtoupper()/ucfirst() map
 * each byte through the C library's tolower()/toupper(), which glibc
 * resolves through the process LC_CTYPE locale — under a Turkish
 * tr_* locale the ASCII 'i' upper-cases to the two-byte 'İ' (U+0130)
 * and 'I' lower-cases to the dotless 'ı' (U+0131). The slug→identifier
 * core is exactly the surface that must not consult a locale: its
 * output IS the keyed vocabulary (the version-constant name a plugin
 * file must spell bare, the namespace segment every hand-written
 * autoloader prefix repeats) — under tr_TR the slug 'zai' derived
 * 'ZAİ_VERSION' / 'İnkOauth', spellings no bare code reference and no
 * hand-spelled prefix can ever match again (the r2-14 BY-SCOPE
 * doctrine does not transfer: those folds were comparison keys
 * consistent under any locale; these are DERIVED IDENTIFIERS that
 * must be identical in every process). An explicit byte-table fold has
 * no locale to consult.
 *
 * @param string $value The bytes to fold.
 * @return string The folded bytes — identical in every locale.
 */
function wp_connectors_ascii_lower($value)
{
    return strtr((string) $value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}

/**
 * The upper twin of wp_connectors_ascii_lower() — see its doctrine.
 *
 * @param string $value The bytes to fold.
 * @return string The folded bytes — identical in every locale.
 */
function wp_connectors_ascii_upper($value)
{
    return strtr((string) $value, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
}

/**
 * The slug's identifier segments: lowercased, '-' AND '.' separated
 * (t31-r5-12 — a dotted slug's naive spellings are not legal labels, so
 * the dot separates like the dash and every derived segment stays a
 * label). The lower-case fold is the LOCALE-INDEPENDENT one
 * (wp_connectors_ascii_lower(), t31-r11-6).
 *
 * @param string $slug Plugin slug.
 * @return list<string> The lowercased segments, in slug order.
 */
function wp_connectors_slug_segments($slug)
{
    return preg_split('/[-.]/', wp_connectors_ascii_lower((string) $slug)) ?: array();
}

/**
 * Derives an IDENTIFIER from a plugin slug — the ONE slug→identifier
 * core (round t31-r10-8).
 *
 * The slug's segments (wp_connectors_slug_segments()) are cased per
 * segment — acronyms keep their documented casing ('openai' -> 'OpenAi',
 * per docs/CONVENTIONS.md) — joined by the caller's glue, and
 * underscored when the result starts with a digit (the t31-r3-5
 * legal-label rule: a PHP label may not start with a digit, so
 * '3cx-oauth' derives '_3cxOauth' / '_3CX_OAUTH', never a spelling a
 * namespace or a bare constant reference could not declare).
 *
 * The two identifiers this repo derives — the namespace segment
 * ('my-plugin' -> 'MyPlugin') and the version-constant stem
 * ('my-plugin' -> 'MY_PLUGIN') — were hand-maintained twins beside each
 * other, synchronized twice by hand (t31-r5-8's digit rule, t31-r5-12's
 * dot separator) before this core existed; a future rule change lands
 * once here and both spellings follow by construction. The underscore
 * glue upper-cases its segments (the constant stem is all-caps; the
 * acronym casing folds away under it). Every case fold is the
 * LOCALE-INDEPENDENT ASCII one (t31-r11-6): ucfirst() and strtoupper()
 * consult LC_CTYPE exactly like strtolower(), and the derived spellings
 * are keyed vocabulary — 'zai' must derive 'ZAI_VERSION'/'ZaiOauth' in
 * every process, Turkish dotted-I rule included.
 *
 * @param string $slug Plugin slug.
 * @param string $glue Join between segments ('' for the camel-cased
 *                     namespace spelling, '_' for the constant stem).
 * @return string The derived identifier (digit-initial spellings prefixed '_').
 */
function wp_connectors_identifier_from_slug($slug, $glue)
{
    $acronyms = array( 'openai' => 'OpenAi' );

    $parts = array();
    foreach (wp_connectors_slug_segments($slug) as $segment) {
        // The locale-independent ucfirst: the segment is ASCII-folded
        // already, so upper-casing its FIRST BYTE through the explicit
        // table is the whole operation (t31-r11-6).
        $parts[] = isset($acronyms[ $segment ]) ? $acronyms[ $segment ] : wp_connectors_ascii_upper(substr($segment, 0, 1)) . substr($segment, 1);
    }
    if ('_' === $glue) {
        $parts = array_map('wp_connectors_ascii_upper', $parts);
    }
    $identifier = implode($glue, $parts);

    // Legal-label fix (t31-r3-5): underscore a digit-initial derivation.
    return '' !== $identifier && ctype_digit($identifier[0]) ? '_' . $identifier : $identifier;
}

/**
 * Derives the plugin namespace segment from the slug (openai-oauth -> OpenAiOauth).
 *
 * The camel-glue spelling of the ONE slug→identifier core
 * (wp_connectors_identifier_from_slug(), round t31-r10-8) — shared by
 * bin/build.php (shared-code namespace rewriting),
 * bin/check-conventions.php (expected autoloader prefix), and the test
 * bootstrap (dev autoloader). Every consumer gets the same legal
 * segment by construction; the constant-stem twin derives from the same
 * core.
 *
 * @param string $slug Plugin slug.
 * @return string
 */
function wp_connectors_namespace_suffix_from_slug($slug)
{
    return wp_connectors_identifier_from_slug($slug, '');
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
    /*
     * The label agreement spans every slug spelling whose naive name is
     * not a legal identifier: '.' becomes '_' like '-' (t31-r5-12 —
     * 'my.plugin' derived 'MY.PLUGIN_VERSION', bare-code-unreachable at
     * exit 0), and a digit-initial result is underscored exactly like
     * the namespace derivation (t31-r5-8, the t31-r3-5 rule's twin:
     * '3CX_OAUTH_VERSION' defined fine but every bare reference was a
     * lexer error). The derivation rides the ONE slug→identifier core
     * now (t31-r10-8): the underscore-glue spelling of
     * wp_connectors_identifier_from_slug(), the same core the
     * namespace suffix derives from — the hand-spelled twin is gone,
     * and the two labels cannot drift apart again.
     */
    $constantName = wp_connectors_identifier_from_slug($slug, '_') . '_VERSION';
    if (! preg_match('/define\(\s*[\'"]' . preg_quote($constantName, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $source, $constantMatch)) {
        $violations[] = sprintf('%s: main file must define constant %s.', $slug, $constantName);
    } elseif (isset($headers['version']) && $constantMatch[1] !== $headers['version']) {
        $violations[] = sprintf('%s: %s (%s) does not match header Version (%s).', $slug, $constantName, $constantMatch[1], $headers['version']);
    }

    return $violations;
}

/**
 * Whether this process is the script's own CLI run — and, when it is,
 * the CLI diagnostics idiom, applied (review round t31-r12-11).
 *
 * ONE spelling of the guard + diagnostics shape that four bin/ entry
 * scripts (build, check-conventions, lint-php, inspect-artifact) wore
 * as four hand-maintained copies of the t31-r9-4/t31-r10-3 idiom: at
 * file top the error_reporting/display_errors calls executed in every
 * process that merely REQUIRED the file too, so a php-cli host with
 * display_errors off had it flipped on process-wide just by loading a
 * library — a class each script fixed independently, one spelling away
 * from being reintroduced. The helper answers the guard question
 * (realpath($argv[0]) equals the given script path) and applies the
 * diagnostics (E_ALL + display_errors '1', the glm17-16 CLI posture)
 * ONLY on the CLI run; a requiring process sees neither. A new bin/
 * entry script takes this helper, never a hand-rolled copy.
 *
 * @param string $script The entry file's own __FILE__.
 * @return bool True when this process is the script's CLI run (with the CLI diagnostics applied).
 */
function wp_connectors_cli_entry($script)
{
    $argv = $_SERVER['argv'] ?? null;
    if (PHP_SAPI !== 'cli' || null === $argv || realpath((string) ($argv[0] ?? '')) !== $script) {
        return false;
    }
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    return true;
}
