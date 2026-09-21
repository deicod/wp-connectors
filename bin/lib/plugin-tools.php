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

/*
 * The PHP LABEL byte classes — the ONE owner of the label grammar every
 * pattern seam that matches an identifier spells (OCR round 59,
 * t31-ocr59-2): PHP labels admit the high bytes \x80-\xff in every
 * position ('Grüß' is a legal class name — php -l accepts it in a name,
 * a sub-segment, and an alias slot alike, verified on this engine), and
 * the member-name grammar of bin/build.php's groupUseMemberGrammar()
 * already spoke the full set while sibling seams of the same grammar
 * spelled ASCII-only classes — a legal high-byte import failed those
 * patterns, rode verbatim, and refused at the postcondition with the
 * anonymous-survivor message instead of the rewrite the engine accepts
 * it for. Every seam that matches label BYTES references these classes
 * now — the census of aligned seams:
 *
 * - bin/build.php: the namespace-declaration tail, the use-statement
 *   pattern's sub-segment tail and alias group, the alias-id extraction
 *   the reserved-vocab oracle consults, the group-use member grammar's
 *   alias shape and member NAME (the derivation source);
 * - bin/check-conventions.php: the unused-import scanner's statement
 *   patterns, member alias parse, and member NAME shape guard (the
 *   \w classes — ASCII in PCRE's byte mode — that once left a
 *   high-byte import INVISIBLE to the gate, the r46-9
 *   silent-false-negative class; the shape guard's straggler closed
 *   at t31-ocr60-2);
 * - here: the sibling pattern's continuation segment.
 *
 * bin/inspect-artifact.php, bin/lib/secret-scanner.php,
 * bin/lint-php.php, and bin/scan-secrets.php carry NO label-class seam
 * of this grammar (census-verified — their byte classes are slug, path,
 * and token-literal spellings). The BOUNDARY lookarounds once stayed
 * the r46/r49 word-byte census on purpose (they guard where an ASCII
 * family spelling ENDS, "a different question from what a label may
 * contain") — but once the high bytes became label CONTENT that
 * purpose inverted: a boundary that passes at 0xC3 reads a segment as
 * ending mid-segment, and OCR round 60 (t31-ocr60-1/3/4/5) swept the
 * boundary lookarounds of the mention checks, build.php's
 * statement-start anchor and member-leaf rewrite, and BOTH text-lens
 * pattern generators to the LABEL_BYTES class — every boundary a
 * label-shaped name can now carry is judged over the bytes the label
 * grammar admits.
 */
const WP_CONNECTORS_LABEL_HEAD_BYTES = 'A-Za-z_\x80-\xff';
const WP_CONNECTORS_LABEL_BYTES = 'A-Za-z0-9_\x80-\xff';

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
 * glm14-6: the memo's retention is BOUNDED (FIFO by insertion order,
 * 24 MB of retained view bytes) — the glm25-8 fix landed the memo with
 * no eviction, so a process walking many trees retained THREE full
 * copies of every PHP byte it ever read for its whole lifetime, and
 * the artifact inspector rides this provider over EXTRACTED
 * (hostile-controlled) trees: a zip shipping ~40 MB of .php entries
 * retained ~120 MB against the 128M default memory_limit and the
 * inspector died at exit 255 with NO verdict (measured by the review,
 * re-driven this round). The bound sits above this repository's whole
 * PHP tree (~5.3 MB of sources ≈ 16 MB of views), so the repo-wide
 * checks keep their single-tokenize-per-file purpose; a walk over a
 * larger tree evicts oldest-first and re-tokenizes on re-consult —
 * correct, just slower, never verdict-less. A SINGLE file whose own
 * triple exceeds the bound still enters (the analysis of one file
 * needs all three views simultaneously; refusing to memoize it would
 * only re-blank the next consult) — that transient single-file class
 * is the analysis's own memory floor, not the accumulation this bound
 * kills.
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
    /** @var int $retained */
    static $retained = 0;

    $source = @file_get_contents($path);
    if (false === $source) {
        return null;
    }

    $key = $path . "\0" . md5($source);
    if (isset($views[$key])) {
        return $views[$key];
    }

    $code = wp_connectors_strip_comments($source);
    $triple = array(
        'source' => $source,
        'code' => $code,
        'masked' => wp_connectors_mask_string_contents($code),
    );
    $entry_bytes = strlen($source) + strlen($code) + strlen($triple['masked']);
    while ($views !== array() && $retained + $entry_bytes > 24 * 1024 * 1024) {
        $oldest = (string) array_key_first($views);
        $retained -= strlen($views[$oldest]['source']) + strlen($views[$oldest]['code']) + strlen($views[$oldest]['masked']);
        unset($views[$oldest]);
    }
    $views[$key] = $triple;
    $retained += $entry_bytes;

    return $triple;
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
 * The leaf/stem boundaries ride the ONE label byte class (OCR round
 * 60, t31-ocr60-5 — the r59 widening's follow-on at the text lens):
 * the ASCII lookaheads passed at a high byte, so '…\WpConnectors\
 * Sharedü' — a DISTINCT sibling segment — matched the leaf arm
 * MID-SEGMENT and reported a finding under the TRUNCATED own-namespace
 * name, while the name walk judges whole segments. The label-class
 * lookarounds keep the two lenses of the one detector agreeing on
 * every spelling the grammar admits.
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
    // The boundary classes derive from the LABEL_BYTES owner
    // (t31-ocr60-5): label content, never a segment boundary.
    $not_label_byte = '(?![' . WP_CONNECTORS_LABEL_BYTES . '])';

    return '/(?<![' . WP_CONNECTORS_LABEL_BYTES . '])' . $stem . '\\s*\\\\\\s*(?:' . $leaf . $not_label_byte . '|\\{(?:[^;]*?[\\s,{])?' . $leaf . $not_label_byte . ')/i';
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
    // The fold rides the ASCII owner (t31-ocr11-12): it feeds the
    // family predicate, and every family-feeding fold must survive a
    // locale (the r11-6/ocr10-4 doctrine) — strtolower is
    // locale-consulting on exactly the 'İ'-class bytes that doctrine
    // exists for.
    $own_lower = wp_connectors_ascii_lower(wp_connectors_shared_source_namespace());
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

    /*
     * The exclusion tails' boundary derives from the LABEL_BYTES owner
     * (t31-ocr60-5): 'shared' followed by a high byte is NOT the
     * excluded source tail — 'Sharedü' is a sibling the continuation
     * segment owns whole — so the exclusion must FAIL there exactly
     * as the leaf arm's own boundary does, or the sibling pattern
     * would stay silent over a spelling the name lens reports.
     */
    $excluded = array();
    foreach ( $excluded_tails as $tail ) {
        $excluded[] = implode($separator, array_map(
            static function ( $segment ) {
                return preg_quote( wp_connectors_ascii_lower( (string) $segment ), '/' );
            },
            explode( '\\', (string) $tail )
        )) . '(?![' . WP_CONNECTORS_LABEL_BYTES . '])';
    }

    /*
     * The exclusion lookahead exists only when a tail does (OCR
     * round 16, t31-ocr16-8): an empty $excluded_tails built the
     * lookahead over an EMPTY alternation — '(?!' . $separator .
     * '(?:))' — and an empty alternation matches at every position,
     * so the negative lookahead failed at every SEPARATOR-following
     * position and the pattern silently degraded below its baseline
     * (driven: the bare vendor stem matched while the stem plus a
     * sibling continuation — the vocabulary's own core spelling —
     * did NOT; the with-tails pattern matches both). No tails, no
     * clause: the pattern then IS the baseline (stem, optional
     * continuation) with nothing excluded.
     */
    $exclusion_lookahead = array() === $excluded ? '' : '(?!' . $separator . '(?:' . implode('|', $excluded) . '))';

    /*
     * The continuation segment spells the ONE label byte class (OCR
     * round 59, t31-ocr59-2 — the census comment at
     * WP_CONNECTORS_LABEL_* lists this seam): a sibling's next segment
     * is a PHP label, and a high-byte one ('…\WpConnectors\Grüß') once
     * truncated at the first high byte, reporting a name no segment
     * spells. The stem's OWN boundaries ride the same class since OCR
     * round 60 (t31-ocr60-5): the text lens judges whole segments,
     * agreeing with the name walk on every spelling the grammar
     * admits.
     */
    return '/(?<![' . WP_CONNECTORS_LABEL_BYTES . '])' . $stem . '(?![' . WP_CONNECTORS_LABEL_BYTES . '])' . $exclusion_lookahead . '(?:' . $separator . '[' . WP_CONNECTORS_LABEL_HEAD_BYTES . '][' . WP_CONNECTORS_LABEL_BYTES . ']*)?/i';
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
 * The index of the first non-trivia token at or before a position, or
 * null — the backward twin of wp_connectors_next_code_token_index()
 * (OCR round 3, t31-ocr3-4).
 *
 * The use-rewrite walk needed to ask what code token PRECEDES a
 * relative keyword inside an open use statement: the relative operator
 * is only the grammar's as the import's LEADING name (directly after
 * `use`, `use function`, or `use const`), and the judgment is
 * positional — the previous code token says which element the keyword
 * stands at. Hand-rolling the backward trivia walk at the consumer
 * would be a second copy of the trivia vocabulary its forward twin
 * owns; the twin lives beside the sibling, one owner.
 *
 * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
 * @param int                                             $from   Index to start at (inclusive).
 * @return int|null The previous code-token index, or null before the start.
 */
function wp_connectors_previous_code_token_index(array $tokens, $from)
{
    for ($i = $from; $i >= 0; --$i) {
        $id = is_array($tokens[ $i ]) ? $tokens[ $i ][0] : null;
        if (null !== $id && (T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * Whether the `use` keyword token at an index OPENS a namespace import
 * statement — the closure-use fence, ONE owner since t31-r13-3.
 *
 * A closure's lexical `use (` is not a namespace import (its binding
 * list never carries one); every OTHER `use` opens an import statement.
 * The fence is the first code token past the trivia: an open
 * parenthesis is the lexical spelling; anything else (a name, a
 * separator, the `function`/`const` kind keywords) is the import's own
 * first token. The name walks that classify and rewrite use statements
 * — the detector's classification walk and the builder's use-rewrite
 * walk — shared this fence as two hand-rolled copies until the hoist
 * (the defect history is one copy drifting at a time: r7-7, r8-1,
 * r8-10, r11-10); both consume this one owner now.
 *
 * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
 * @param int                                             $at     Index of the T_USE token.
 * @return bool True when the token opens a `use` IMPORT statement.
 */
function wp_connectors_use_opens_import(array $tokens, $at)
{
    $follower = wp_connectors_next_code_token_index($tokens, $at + 1);

    return null !== $follower && '(' !== $tokens[ $follower ];
}

/**
 * Whether a token is a boundary of an open `use` statement — the
 * statement-boundary SET (verifier round t31-r8-1; ONE owner since
 * t31-r13-3).
 *
 * ';' plus every PHP-mode tag boundary. A close tag IS a statement
 * terminator — the engine implies the semicolon at '?>' — but the
 * pre-r8-1 reset named only the ';' spelling, so a hostile
 * `use Foo\Bar as ?>` left the alias skip armed across the tag and
 * into the re-entered code, where it silently ATE the next name run:
 * a family reference there became invisible to both gates
 * (adversarially confirmed: pre-round REFUSE, round exit-0 ship). The
 * re-entry tags can never occur inside a live import statement — an
 * open tag only ever follows a close tag or starts the file — so
 * resetting at them too is the invariant worn on both sides: a mode
 * boundary IS a statement boundary, whichever side of it the walk
 * stands.
 *
 * @param mixed    $token The raw token (a single-byte string or an array token).
 * @param int|null $id    The token's id, null for single-byte tokens.
 * @return bool True for ';' and every open/close tag token.
 */
function wp_connectors_is_use_statement_boundary($token, $id)
{
    return ';' === $token || T_CLOSE_TAG === $id || T_OPEN_TAG === $id || T_OPEN_TAG_WITH_ECHO === $id;
}

/**
 * Whether the `namespace` keyword token at an index carries one of the
 * two LEGAL declaration name shapes — a bare name (T_STRING) or an
 * unqualified sequence (T_NAME_QUALIFIED) — the declaration-shape
 * predicate (verifier round t31-r8-10; ONE owner since t31-r13-3).
 *
 * Only these two open a declaration a walk may resolve against:
 * `namespace \X;` (T_NAME_FULLY_QUALIFIED) and
 * `namespace namespace\X;` are parse-error spellings whose names still
 * assemble — classifying them as declarations let the invalid spelling
 * CORRUPT the file's in-effect namespace, and a family-resolving
 * relative after it then resolved against the junk base and laundered
 * past both gates (reproduced: rewrite shipped where the control file
 * refused). In the detector they fall to 'code' positions, where a
 * family spelling refuses in every consumer; in the rewriter's
 * declaration ledger they are simply not declarations (a relative
 * after one resolves against the PREVIOUS legal declaration in
 * effect, or refuses when none is).
 *
 * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
 * @param int                                             $at     Index of the T_NAMESPACE token.
 * @return bool True when the keyword opens a legal declaration shape.
 */
function wp_connectors_namespace_opens_declaration(array $tokens, $at)
{
    $follower = wp_connectors_next_code_token_index($tokens, $at + 1);
    $follower_id = null !== $follower && is_array($tokens[ $follower ]) ? $tokens[ $follower ][0] : null;

    return T_STRING === $follower_id || T_NAME_QUALIFIED === $follower_id;
}

/**
 * The file's namespace declarations in effect order, legal shapes only,
 * each with its braced-block expiry — the ONE declaration ledger (OCR
 * round 7, t31-ocr7-1).
 *
 * The ledger this function owns was born in the rewriter's resolution
 * walk (bin/build.php, rewriteRelativeUseImports()) and carried its
 * braced-block expiry from OCR round 4 (t31-ocr4-5): after
 * `namespace X { … }` PHP is GLOBAL scope, so the block's closing brace
 * offset ends the declaration — recorded through the ONE brace-matching
 * owner (wp_connectors_matching_brace_end()) over the string-masked
 * view, so a '}' in a string or comment cannot counterfeit the close
 * (its unbalanced policy — EOF, never under-bounds — is inherited
 * verbatim). INLINE HTML is not the block's grammar either (verifier
 * round t31-ocr4-9): a close tag inside a braced block exits PHP mode
 * and the block CONTINUES at re-entry — only a CODE '}' closes it — so
 * the inline-HTML spans are blanked in the ledger's own masked view,
 * never in the ONE masker (its conventions consumers see inline HTML
 * deliberately; this judgment is the ledger's).
 *
 * The DETECTOR's resolution walk (wp_connectors_shared_family_references())
 * kept its own hand-rolled twin of this ledger — an incremental
 * "latest declaration reference wins" that never expired a braced
 * block: after `namespace X { … }` the walk left X in effect to EOF,
 * contradicting the PHP resolution it documents ("the one PHP itself
 * performs") and drifting from the rewriter's ledger one braced file
 * at a time — the ocr4-5 defect class reprised in the sibling ledger
 * (the r4 fix owned only build.php's). Both consumers ride THIS owner
 * now: one resolution semantics at the sweep, the build postcondition,
 * and the relative-use rewrite, by construction.
 *
 * A `namespace` keyword inside an OPEN USE STATEMENT never opens a
 * declaration (t31-ocr11-21, the round's verifier lens): the
 * interrupted relative-member spelling (`use P\{namespace Wp…}`,
 * keyword + name with the separator dropped) once landed in the
 * ledger as a declaration — the shape predicate judged the follower,
 * not the enclosing statement — and the corrupted base laundered
 * LATER relatives exactly like the r8-10 junk spelling (`use
 * namespace Foo;` reading as `namespace Foo;` re-based every
 * following resolution). Both spellings are parse errors PHP never
 * accepts (inside a use statement the bare keyword is the relative
 * operator mid-spelling, never a declaration keyword), so the ledger
 * skips them: declarations are file-level statements, and one
 * vocabulary across both consumers is the contract — every verdict
 * over the bytes themselves stays a refusal somewhere on the chain
 * (the rewriter's group-use-MEMBER and interrupted-keyword refusals
 * own them there).
 *
 * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
 * @param string                                          $source The source bytes the tokens lexed (the masked view derives from them).
 * @return list<array{offset: int, display: string, lower: string, expires: int|null}>
 *         Declarations in source order: the T_NAMESPACE keyword's byte
 *         offset, the declared name as spelled and lowercased, and the
 *         closing-brace byte offset a braced block expires at (null for
 *         an unbraced declaration — in effect to EOF or the next one).
 */
function wp_connectors_namespace_declaration_ledger(array $tokens, $source)
{
    $declarations = array();
    $count = count($tokens);
    $offset = 0;
    $masked = null;
    $use_open = false;
    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[ $i ];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $token_offset = $offset;
        $offset += strlen($text);
        if (T_USE === $id) {
            // The closure-use fence rides its ONE owner; a use
            // statement (import or trait adaptation) is a region no
            // file-level declaration can open inside of.
            $use_open = wp_connectors_use_opens_import($tokens, $i);

            continue;
        }
        if ($use_open) {
            /*
             * A namespace keyword met inside an open use statement is
             * the INTERRUPTED relative spelling (t31-ocr11-21) — never
             * a declaration, and never a base corruption for the
             * relatives that follow; the statement's boundary (the ONE
             * owner's set) ends the region.
             */
            if (wp_connectors_is_use_statement_boundary($token, $id)) {
                $use_open = false;
            }

            continue;
        }
        if (T_NAMESPACE !== $id) {
            continue;
        }
        if (! wp_connectors_namespace_opens_declaration($tokens, $i)) {
            continue;
        }
        $run = wp_connectors_name_run($tokens, wp_connectors_next_code_token_index($tokens, $i + 1));
        $expires = null;
        $after_run = wp_connectors_next_code_token_index($tokens, $run['end'] + 1);
        if (null !== $after_run && '{' === $tokens[ $after_run ]) {
            /*
             * The opening brace's byte offset: the running $offset
             * counter has already summed every token through the
             * namespace keyword (OCR round 31, t31-ocr31-7 — the loop
             * once re-summed the WHOLE token stream from index 0,
             * O(file) per braced declaration, O(K²) over K
             * declarations), so the name run's own tokens are all that
             * remain between it and the brace.
             */
            $brace_offset = $offset;
            for ($j = $i + 1; $j < $after_run; ++$j) {
                $brace_offset += strlen(is_array($tokens[ $j ]) ? $tokens[ $j ][1] : $tokens[ $j ]);
            }
            if (null === $masked) {
                $masked = wp_connectors_mask_string_contents(wp_connectors_strip_comments($source));
                // The inline-HTML blanking in the LEDGER's own view
                // (t31-ocr4-9): an HTML '{'/'}' is not the block's
                // grammar, so its bytes must not counterfeit either
                // brace direction here.
                $at = 0;
                foreach ($tokens as $html_token) {
                    $html_len = strlen(is_array($html_token) ? $html_token[1] : $html_token);
                    if (T_INLINE_HTML === (is_array($html_token) ? $html_token[0] : null)) {
                        $masked = substr($masked, 0, $at) . str_repeat(' ', $html_len) . substr($masked, $at + $html_len);
                    }
                    $at += $html_len;
                }
            }
            $expires = wp_connectors_matching_brace_end($masked, $brace_offset);
        }
        $declarations[] = array(
            'offset' => $token_offset,
            'display' => $run['name'],
            'lower' => wp_connectors_ascii_lower($run['name']),
            'expires' => $expires,
        );
    }

    return $declarations;
}

/**
 * The declaration a byte offset resolves against — the ledger's query
 * seam (t31-ocr7-1): a relative spelling resolves against the
 * declaration IN EFFECT where it stands, not the file's first.
 *
 * A braced block whose closing brace passed leaves GLOBAL scope in
 * effect (null) until a later declaration supersedes it — the expiry
 * semantics t31-ocr4-5 gave the rewriter's ledger, worn on the one
 * owner both consumers ride.
 *
 * @param list<array{offset: int, display: string, lower: string, expires: int|null}> $ledger
 *        The ledger (wp_connectors_namespace_declaration_ledger()).
 * @return Closure(int): ?array The entry in effect at an offset, or null (global scope).
 */
function wp_connectors_declaration_in_effect(array $ledger)
{
    return static function (int $at_offset) use ($ledger): ?array {
        $entry = null;
        foreach ($ledger as $declaration) {
            if ($declaration['offset'] > $at_offset) {
                break;
            }
            if (null !== $declaration['expires'] && $at_offset > $declaration['expires']) {
                // The braced block closed — global scope follows, not
                // the previous declaration (t31-ocr4-5).
                $entry = null;
                continue;
            }
            $entry = $declaration;
        }

        return $entry;
    };
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
    $relative_member_pending = false;
    $absolute_member_pending = false;

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[ $i ];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $token_offset = $offset;
        $offset += strlen($text);

        if (T_USE === $id) {
            // The closure-use fence rides its ONE owner
            // (wp_connectors_use_opens_import()); every other `use`
            // opens an import statement whose FIRST name is the
            // group-prefix candidate.
            $use_open = wp_connectors_use_opens_import($tokens, $i);
            $group_prefix = null;
            $group_member_seen = false;
            $group_brace_depth = 0;
            $awaiting_group_prefix = true;
            $skip_alias = false;
            $adaptation_block = false;
            $relative_member_pending = false;
            $absolute_member_pending = false;

            continue;
        }
        if (T_NAMESPACE === $id) {
            /*
             * Inside an open use statement the bare keyword is never
             * legal PHP (the r11-10 rewriter doctrine), so every
             * T_NAMESPACE met there is a RELATIVE-member spelling in
             * progress — the INTERRUPTED relative (t31-ocr11-21, the
             * round-11 verifier's refutation lens over t31-ocr11-1's
             * first cut): trivia between the keyword and the name
             * drops the fused T_NAME_RELATIVE token, the pieces arrive
             * as keyword + separator + name, and the name run alone
             * read as an ordinary member — the group prefix composed
             * it (`Psr\Log\WpConnectors\…`) or its fully-qualified
             * spelling reported un-resolved, and a family-resolving
             * member laundered past the detector at zero references
             * while the rewriter (token-id-keyed, case-independent)
             * refused the same bytes. The keyword ARMS the pending
             * flag; the run branch re-attaches the `namespace\`
             * prefix and the relative judgment rides it.
             */
            if ($use_open) {
                $relative_member_pending = true;
                $declaration_pending = false;

                continue;
            }
            // The legal-shape judgment rides its ONE owner
            // (wp_connectors_namespace_opens_declaration(), the r8-10
            // rule); the parse-error spellings fall to 'code'
            // positions below, where a family spelling refuses in
            // every consumer.
            $declaration_pending = wp_connectors_namespace_opens_declaration($tokens, $i);

            continue;
        }

        if (null === $id || ! wp_connectors_is_name_token_id($id)) {
            if ($use_open) {
                /*
                 * The statement-boundary SET rides its ONE owner
                 * (wp_connectors_is_use_statement_boundary(), the
                 * t31-r8-1 rule): ';' plus every PHP-mode tag boundary.
                 */
                if (wp_connectors_is_use_statement_boundary($token, $id)) {
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
                             * reports at least one name, and a body of
                             * only non-composing members (absolute-only,
                             * t31-ocr11-4) carries no composed spelling
                             * for the prefix — the fence trips there
                             * too, and the prefix reports itself,
                             * judged exactly once.
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
                /*
                 * The INTERRUPTED-ABSOLUTE arm (OCR round 28,
                 * t31-ocr28-2): a leading separator standing APART from
                 * its name (`\ Deicod\WpConnectors\…`, trivia between)
                 * lexes as a standalone T_NS_SEPARATOR the non-name
                 * branch consumed and the name run arrived WITHOUT its
                 * leading backslash — `$is_absolute_run` read false, the
                 * group prefix COMPOSED the member (the r10-9 laundering
                 * verdict: `Prefix\Deicod\…`, a name no family predicate
                 * matches, driven red at HEAD at zero references while
                 * the glued twin reported). A standalone separator the
                 * run assembly did not swallow ARMS the absolute
                 * expectation — a run only ever starts at a name token,
                 * so a separator followed (modulo trivia) by a name is
                 * LEADING by construction, and any other separator (a
                 * dangling `use \ ;`, the `use Prefix \ {` brace join)
                 * dies at the guard below before a name can follow it.
                 */
                if (T_NS_SEPARATOR === $id) {
                    $absolute_member_pending = true;
                }
                /*
                 * The interrupted-relative arm dies with its own
                 * grammar (t31-ocr11-21): only TRIVIA and the SEPARATOR
                 * may stand between the keyword and its name (the run
                 * assembly's own tolerance); anything else — a comma,
                 * a brace, a boundary, an `as` — is a dangling keyword
                 * whose next name is an ordinary member again. The
                 * absolute twin dies at the same guard, the same
                 * grammar: only trivia may stand between the LEADING
                 * separator and its name.
                 */
                if (T_NS_SEPARATOR !== $id && T_WHITESPACE !== $id && T_COMMENT !== $id && T_DOC_COMMENT !== $id) {
                    $relative_member_pending = false;
                    $absolute_member_pending = false;
                }

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
        /*
         * A RELATIVE run (the keyword's T_NAME_RELATIVE token, or the
         * INTERRUPTED keyword's pending arm) resolves against the
         * file's DECLARED namespace, never a group prefix (OCR round
         * 11, t31-ocr11-1): PHP's relative operator ignores the
         * group's prefix entirely, so composing `use
         * Psr\Log\{namespace\WpConnectors\…}` spelled
         * `Psr\Log\namespace\WpConnectors\…` — a name no family
         * predicate matches and no resolution owns — while the member
         * actually resolves under the declaration (`Deicod\…`, the
         * family). The member reports UN-composed, its `namespace\`
         * spelling intact, and the detector resolves it through the
         * declaration ledger exactly like every other relative (the
         * use position keeps no carve-out — t31-r11-1).
         *
         * RELATIVENESS rides the TOKEN ID plus the arm, never a byte
         * comparison (t31-ocr11-21, the round's verifier lens over
         * the first cut): the lexer emits T_NAME_RELATIVE for the
         * keyword in ANY case (`NAMESPACE\…` fused — the keyword is
         * case-insensitive PHP), and the case-sensitive strpos missed
         * every uppercase spelling, composing the member and
         * laundering it; the interrupted spelling (the keyword, trivia,
         * then the name — whitespace or a comment between) arrives as
         * keyword + separator + name,
         * the arm carries the relativeness across the trivia, and the
         * `namespace\` prefix is re-attached to the report so the
         * detector's resolution sees the operator it must resolve.
         */
        $is_absolute_run = '\\' === ($run['name'][0] ?? '') || $absolute_member_pending;
        if ($absolute_member_pending) {
            $absolute_member_pending = false;
        }
        $is_relative_run = T_NAME_RELATIVE === $id || $relative_member_pending;
        if ($relative_member_pending) {
            $display = 'namespace\\' . ltrim($display, '\\');
            $relative_member_pending = false;
        }
        /*
         * QUALIFIEDNESS includes the RELATIVE arm (OCR round 20,
         * t31-ocr20-2): the alias skip below judged qualifiedness on
         * the RAW run name alone, so an interrupted `namespace\`
         * relative in the ALIAS slot whose tail arrived as a bare
         * single-segment T_STRING — the separator its OWN token with
         * trivia AFTER it (`use Foo as namespace \ WpConnectors;`,
         * the comment and newline twins; the GLUED spelling lexes the
         * whole tail as T_NAME_FULLY_QUALIFIED and never hit the
         * hole) — was silently EATEN as the alias while its
         * re-attached spelling (the arm above) resolves against the
         * declaration into the family: zero references, the exact
         * r10-9 laundering verdict drift (an alias IS a bare
         * identifier — a relative spelling never is one, in any
         * casing or interruption, and reports like its glued twin).
         * The interrupted-ABSOLUTE arm rides the same clause (OCR
         * round 28, t31-ocr28-2): its single-segment tail arrives
         * bare (`as \ WpConnectors`), and only the pending arm
         * carries the leading separator the glued twin bakes into
         * its T_NAME_FULLY_QUALIFIED bytes.
         */
        $is_qualified_run = false !== strpos((string) $run['name'], '\\') || $is_relative_run || $is_absolute_run;
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
                        $group_prefix = wp_connectors_ascii_lower($display);
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
                if (null !== $group_prefix && ! $is_absolute_run && ! $is_relative_run && ! $alias_position) {
                    $display = $group_prefix_display . '\\' . $display;
                }
            }
        } elseif ($declaration_pending) {
            $kind = 'declaration';
        }
        $declaration_pending = false;

        /*
         * The fence arms only on a member the prefix actually COMPOSED
         * (OCR round 11, t31-ocr11-4): an ABSOLUTE member deliberately
         * reports un-composed (t31-r10-9) and a RELATIVE one resolves
         * against the declaration, never the prefix (t31-ocr11-1) —
         * either once armed the flag, so a body nothing composes kept
         * the fence silent and the PREFIX's own spelling was judged
         * nowhere: `use Deicod\WpConnectors\{\Zai\Api};` reported only
         * `Zai\Api` while the family-spelled prefix itself went
         * unreported through every gate (driven red at HEAD: zero
         * family references). Arming on composition keeps ONE verdict
         * path: a composed member carries the prefix's spelling into
         * its own report (`Prefix\Member` — the family predicate
         * judges it there), and a body nothing composes (empty,
         * absolute-only, relative-only, qualified-alias-only) trips
         * the fence and the prefix reports itself.
         */
        if ($use_open && null !== $group_prefix && ! $adaptation_block && ! $is_absolute_run && ! $is_relative_run && ! $alias_position) {
            $group_member_seen = true;
        }

        $references[] = array(
            'name' => $display,
            'lower' => wp_connectors_ascii_lower($display),
            'kind' => $kind,
            'offset' => $token_offset,
            'line' => $token_line,
        );
    }

    /*
     * EOF flushes the open group state (OCR round 27, t31-ocr27-3):
     * the empty-body fence fired only at the boundary handlers —
     * the ';' / close-tag handler and the group's own '}' close —
     * so a `use Prefix\{` or `use Prefix\{\Member` truncated at
     * end-of-file never met either one and the prefix was dropped
     * without its report (red at HEAD: a family-spelled prefix at
     * EOF judged by no gate, the walk's totality owing the invalid
     * file every legal position owes). EOF is the last boundary:
     * the same fence, the same single report, the prefix judged
     * exactly once — the boundary handlers null the prefix when
     * they fire, so the flush can never double-report.
     */
    if (null !== $group_prefix && ! $group_member_seen) {
        $references[] = array(
            'name' => $group_prefix_display,
            'lower' => $group_prefix,
            'kind' => 'use',
            'offset' => $group_prefix_offset,
            'line' => $group_prefix_line,
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
 * its bytes. Single-quoted literals resolve \' and \\ only (NOT nothing:
 * a body carrying a double backslash or an escaped quote computes to
 * fewer bytes than it spells); double-quoted (and heredoc) literals
 * resolve the full escape table (octal, hex, \u{...}, and the standard
 * one-character escapes; an unknown escape keeps both bytes). Nowdoc
 * bodies resolve NO escapes at all — there is no quote spelling for
 * that here, so the caller-side contract is to never route a nowdoc
 * body through this function: use the raw body itself (the heredoc
 * caller's own shape — unescape('"', $body) for a heredoc, $body
 * verbatim for a nowdoc). Following the former "single-quote
 * semantics of nothing to do" advice would resolve \\ → \ and \' → '
 * over nowdoc bytes PHP keeps verbatim, corrupting exactly the bodies
 * whose distinguishing feature is that nothing resolves (OCR round 27,
 * t31-ocr27-6 — the guidance was wrong since round 7 while the only
 * caller did it right).
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
        /*
         * The hex arm reads BOTH cases (OCR round 31, t31-ocr31-2, the
         * refuted-premise shape — the ocr28-4 doctrine): the round's
         * finding claimed the engine recognizes only lowercase \x, so
         * '\X41' should keep its four literal bytes — DRIVEN AT FIX
         * TIME on the runner engine (8.5.10), the double-quoted
         * "\X41" computes 'A' exactly like its lowercase twin (the
         * scanner's hex handler consults the case-folded byte), and
         * decoding \X invents nothing: REFUSING to decode it would.
         * The pin in the battery drives the engine itself as the
         * oracle over both spellings — a future engine generation
         * that stops resolving \X fails that pin loudly, naming the
         * drift, and this branch narrows with it.
         */
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
                $digits = substr($inner, $i + 2, $close - $i - 2);
                /*
                 * The legal NUL escape rides the range (OCR round 16,
                 * t31-ocr16-7): \u{0} is a codepoint the engine
                 * resolves (to the NUL byte — eval-verified), and the
                 * exclusive `> 0` guard dropped it into the
                 * unrecognized-escape branch, keeping the literal
                 * '\u{0}' bytes in the value. The judgment is the
                 * HEX-DIGIT-ALONE check plus the range — anything made
                 * of hex digits only, up to 0x10ffff, resolves; the
                 * EMPTY braces spelling (\u{}, a compile error the
                 * engine never resolves) and the over-range spellings
                 * stay in the unrecognized branch, their literal bytes
                 * kept.
                 *
                 * The hex check is VALIDATED BEFORE hexdec() (OCR round
                 * 23, t31-ocr23-4): hexdec() ignores every non-hex
                 * byte it meets — '\u{zz}' converted to 0 (the NUL
                 * byte), '\u{1z}' to 1, '\u{ 41 }' to 0x41 — while the
                 * engine refuses every such spelling at compile time
                 * (php -l-verified: Invalid UTF-8 codepoint escape
                 * sequence), so the model diverged from the lexer it
                 * exists to mirror, inventing values for literals no
                 * runtime ever computes. The conversion also
                 * DEPRECATES on non-hex input (8.5's 'Invalid
                 * characters passed' notice — the r11-8 octal doctrine,
                 * a notice raised mid-gate). The non-hex spellings join
                 * the unrecognized branch: the engine's own refusal
                 * spelling kept, byte for byte.
                 */
                if ('' !== $digits && ctype_xdigit($digits)) {
                    /*
                     * hexdec() rides UNCAST (the round's verifier
                     * close, rd-2): a digit run past the int range
                     * ('\u{FFFFFFFFFFFFFFFF}', 2^63 and over) answers
                     * a FLOAT, and the former (int) cast collapsed it
                     * to 0 — the range check read the COLLAPSED int,
                     * the over-magnitude spelling resolved as the NUL
                     * byte, and the cast itself raised 'the float … is
                     * not representable as an int' MID-GATE (the
                     * r11-8 class) while the engine refuses every
                     * over-magnitude spelling at compile time
                     * (php -l-verified, driven). The comparison keeps
                     * the FLOAT — an over-magnitude run answers a
                     * float over 0x10ffff, refuses the range, and
                     * stays literal; the encode path below is reached
                     * only by values the range already bounded, every
                     * one an int.
                     */
                    $codepoint = hexdec($digits);
                    /*
                     * SURROGATES (0xD800–0xDFFF) pass the range
                     * deliberately (OCR round 28, t31-ocr28-4 — the
                     * finding's premise driven and REFUTED): the round
                     * claimed the engine refuses the surrogate class at
                     * compile time "with the very error this comment
                     * cites", but the DRIVEN engine (8.5.10, php -l and
                     * runtime, byte-hexed) compiles '\u{D800}' clean and
                     * computes its raw three-byte spelling (ED A0 80)
                     * — the RFC-era refusal the Unicode-escape RFC
                     * spelled was lifted upstream, and only the
                     * over-range and non-hex spellings still refuse.
                     * The model mirrors the ENGINE, never the RFC (the
                     * ocr23-4 charter, both directions): keeping the
                     * surrogate class literal would invent a refusal
                     * the running engine does not give — the ocr23-4
                     * defect class inverted. The pin drives the engine
                     * ITSELF as the oracle (the ocr16-7 eval idiom), so
                     * the day an engine generation refuses the class
                     * again the pin names the drift, not a silent
                     * model.
                     */
                    if ($codepoint <= 0x10ffff) {
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
    /*
     * The comparison folds are the LOCALE-INDEPENDENT ASCII owner
     * (t31-ocr10-4, the r11-6 doctrine brought to this seam): the
     * spellings judged are case-variant HOSTILE bytes, and
     * strtolower() maps each byte through the C library's tolower()
     * — a question about the engine and the process locale (glibc's
     * tr_* maps 'I' to the dotless ı at the libc level, probed on
     * this host), so a verdict riding it can flip by locale. Every
     * fold that feeds a family verdict — these roots, the 'lower'
     * twins both lenses emit, the value-lens folds, the ledger and
     * group-prefix twins in the walk, the staging gate's root check,
     * and build.php's consumer-side comparisons — rides
     * wp_connectors_ascii_lower(): one table, one verdict in every
     * locale.
     */
    $own_lower = wp_connectors_ascii_lower(wp_connectors_shared_source_namespace());
    // The vendor prefix is everything of the own namespace before its
    // final segment — derived, never spelled twice.
    $vendor_lower = substr($own_lower, 0, (int) strrpos($own_lower, '\\'));
    $is_family = static function (string $lower) use ($vendor_lower): bool {
        return $lower === $vendor_lower || 0 === strpos($lower, $vendor_lower . '\\');
    };
    $target_lower = null;
    if (null !== $target_namespace && (string) $target_namespace !== '') {
        $target_lower = wp_connectors_ascii_lower(ltrim((string) $target_namespace, '\\'));
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
    /*
     * The declaration base rides the ONE ledger with braced-block
     * expiry (OCR round 7, t31-ocr7-1): this walk's own incremental
     * tracking kept a braced `namespace X { … }` in effect to EOF — the
     * ocr4-5 defect class in the sibling ledger (the r4 fix owned only
     * the rewriter's). A relative resolves against the declaration IN
     * EFFECT at its offset, one semantics with the rewriter's
     * resolution walk (and with PHP: global scope after the block).
     */
    $declaration_in_effect = wp_connectors_declaration_in_effect(
        wp_connectors_namespace_declaration_ledger($tokens, $source)
    );
    foreach (wp_connectors_name_references_from_tokens($tokens) as $reference) {
        if (0 === strpos($reference['lower'], 'namespace\\')) {
            $tail_lower = substr($reference['lower'], strlen('namespace\\'));
            $declaration = $declaration_in_effect($reference['offset']);
            $declared_lower = null;
            $declared_display = '';
            if (null !== $declaration) {
                $declared_lower = $declaration['lower'];
                $declared_display = $declaration['display'];
            }
            $resolved_lower = (null !== $declared_lower ? $declared_lower . '\\' : '') . $tail_lower;
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
                    'name' => (null !== $declared_lower ? $declared_display . '\\' : '') . substr($reference['name'], strlen('namespace\\')),
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
     * The text lens's line derivation (t31-r8-11): the engine's own
     * line semantics for the terminators a PHP file carries (\n,
     * \r\n, and a lone \r), and the reader the sweep's numberedLines()
     * splits by — a "\n"-only count drifted from the name lens's
     * token lines on lone-\r files, quoting the wrong source line in
     * the refusal diagnostics. The class is spelled EXACTLY (OCR
     * round 33, t31-ocr33-2): PCRE's \R is BROADER than the
     * tokenizer's terminators — it also matches \v (0x0B), \f
     * (0x0C), and \x85, which token_get_all() counts as plain
     * whitespace, never line breaks — so a \v/\f byte in an earlier
     * string literal or comment inflated every line the lens
     * reported after it (red at HEAD: a docblock on line 3 over two
     * such bytes reported line 5). The alternation order keeps \r\n
     * one terminator, never two. The count stays MATCH-precise
     * (lines are read at the finding's offset, not the token's
     * start — a finding deep inside a long docblock names its own
     * line).
     */
    $line_of = static function (int $offset) use ($source): int {
        /*
         * A PCRE abort is never a silent line 1 (OCR round 28,
         * t31-ocr28-5 — the lens guard's own doctrine, glm36-8): the
         * false return rode the arithmetic as false + 1 = 1, and a
         * line-count abort at any depth reported the finding on the
         * file's FIRST line — a misattribution the refusal
         * diagnostics quote. Line 0 is the named unknowable for a
         * 1-based field (no finding ever rides it on a well engine,
         * the guard's own charter: it answers the abort the day the
         * engine refuses, exactly the sibling shape the lens's
         * pcre-abort row carries).
         */
        $lines = preg_match_all('/\r\n|\r|\n/', substr($source, 0, $offset), $line_matches);

        return false === $lines ? 0 : $lines + 1;
    };
    $push_text_finding = function (string $kind, int $offset, string $spelling) use (&$references, $line_of): void {
        $references[] = array(
            'name' => $spelling,
            'lower' => 'pcre-abort' === $kind ? '' : wp_connectors_ascii_lower(ltrim($spelling, '\\')),
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
    /*
     * The heredoc state is a STACK (OCR round 31, t31-ocr31-1): the
     * lexer genuinely produces a T_START_HEREDOC while another heredoc
     * is still open — a heredoc nested inside the outer body's
     * interpolation ({$a[<<<K … K]}, tokenized and driven on this
     * engine) — and the four scalars this lens once carried were
     * CLOBBERED by the inner open: the outer body's chunks collected
     * before the nesting were lost with no flush (red at HEAD: the
     * outer finding dropped while the nested body's survived), the
     * outer's dynamic mark reset, the offsets re-anchored to the
     * inner's start. Each open heredoc carries its own frame now
     * (chunks, offset, quote, dynamic); the label closes the
     * INNERMOST open frame (the lexer's own pairing), and EOF flushes
     * every frame still open, innermost first — the t31-ocr28-1 EOF
     * doctrine rides for every stack level.
     */
    $heredoc_stack = array();
    /*
     * The heredoc FLUSH rides its ONE owner (OCR round 28, t31-ocr28-1):
     * the body once lived inline under T_END_HEREDOC alone, so a source
     * TRUNCATED inside the heredoc — no closing label ever tokenized —
     * met no flush and the lens dropped every finding the body carried
     * (driven red at HEAD: zero references where the terminated twin
     * reports), the same totality gap the name walk's group-prefix EOF
     * flush closed one round earlier (t31-ocr27-3) missed in the
     * sibling LENS of this same detector. The closure is the LENS half
     * (every judgment the body owes, over the state it is handed); the
     * loop keeps the STATE half and resets it at the handler — so the
     * label boundary and EOF call the same judgments and can never
     * drift apart.
     */
    $flush_heredoc = function (array $frame) use ($text_lens, $push_text_finding, $is_family): void {
        $heredoc_chunks = $frame['chunks'];
        $heredoc_offset = $frame['offset'];
        $heredoc_quote = $frame['quote'];
        $heredoc_dynamic = $frame['dynamic'];
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
            // The same leading-backslash tolerance the quoted
            // literal's value lens rides (t31-ocr20-3) — a heredoc
            // resolves the full escape table, so the fully-qualified
            // family value can arrive through an escape here too.
            if ($is_family(wp_connectors_ascii_lower(ltrim($value, '\\')))) {
                $push_text_finding('string', $heredoc_offset, $value);
            }
        }
    };
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
            /*
             * The leading-backslash tolerance every other family fold
             * carries (OCR round 20, t31-ocr20-3): the value lens
             * judged the RAW computed value, so a literal whose value
             * is the FULLY-QUALIFIED family name (\Deicod\…) matched
             * no predicate — the one spelling the text lens cannot
             * rescue either when the backslash arrives through an
             * escape (octal \134, hex \x5C). The lens folds through
             * the same ltrim the target fold and the text finding's
             * 'lower' twin ride.
             */
            if ($is_family(wp_connectors_ascii_lower(ltrim($value, '\\')))) {
                $push_text_finding('string', $token_offset, $value);
            }

            continue;
        }
        if (T_START_HEREDOC === $id) {
            $heredoc_stack[] = array(
                'chunks' => array(),
                'offset' => $token_offset,
                /*
                 * The classification reads the quote DELIMITERS — the
                 * first quote byte after '<<<' and the optional leading
                 * whitespace — the engine's own rule (single-quoted
                 * label = nowdoc; unquoted and double-quoted =
                 * heredoc). The round-35 finding claimed a label
                 * "legally carries an apostrophe" (<<<"E'OT") and a
                 * strpos over the whole token misread it as nowdoc —
                 * PREMISE REFUTED, driven at both legs (the r21
                 * doctrine, in-round): labels are IDENTIFIERS
                 * ([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*), the
                 * ASCII apostrophe (0x27) is not a label byte, and
                 * php -l refuses the spelling at the opener — the
                 * misclassified token never exists; the legal quote-
                 * LIKE class (high bytes, e.g. U+2019 '’') never
                 * trips a strpos that searches the 0x27 byte no legal
                 * label carries (driven: <<<"E’OT" with \104eicod…
                 * classifies heredoc and the value lens catches it at
                 * HEAD). The delimiter spelling stands as the engine
                 * rule itself — the anchored reading cannot drift
                 * with the label vocabulary the way a byte scan over
                 * the whole opener could — and the pin (the r20-3
                 * battery's opener-spelling rows) holds the
                 * classification to the engine's verdict.
                 */
                'quote' => 1 === preg_match('/\A<<<\s*\'/', $text) ? "'" : '"',
                'dynamic' => false,
            );

            continue;
        }
        if (T_END_HEREDOC === $id) {
            if ($heredoc_stack) {
                $flush_heredoc(array_pop($heredoc_stack));
            }

            continue;
        }
        if (T_ENCAPSED_AND_WHITESPACE === $id) {
            if ($heredoc_stack) {
                $heredoc_stack[ count($heredoc_stack) - 1 ]['chunks'][] = array($text, $token_offset);
            } else {
                // A chunk of an INTERPOLATED string: its value is
                // runtime-built (the ledgered boundary), but its TEXT is
                // still judged — byte parity with K1's whole-file scan.
                $text_lens('string', $text, $token_offset);
            }

            continue;
        }
        // Interpolation pieces inside a heredoc mark its value dynamic
        // (the INNERMOST open frame — the one whose body they sit in).
        if ($heredoc_stack && (T_VARIABLE === $id || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id)) {
            $heredoc_stack[ count($heredoc_stack) - 1 ]['dynamic'] = true;
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
    /*
     * EOF is the heredoc's last boundary (t31-ocr28-1): a source cut
     * inside the body never tokenizes T_END_HEREDOC, and without this
     * flush the open state died with the loop — the body's findings
     * dropped without their report (red at HEAD). The label handler
     * and EOF share the ONE flush closure above; the state resets keep
     * the two spellings one verdict path, exactly as the name walk's
     * group-prefix EOF twin rides the same fence as its ';'.
     */
    while ($heredoc_stack) {
        $flush_heredoc(array_pop($heredoc_stack));
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
        wp_connectors_quoted_literal_grammar(),
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
 * The ONE house quote grammar matching a PHP quoted string literal,
 * EMPTY literals included (glm15-2).
 *
 * Escape-aware ('\\.' pairs walk inside, so a backslash-escaped closing
 * quote does not end the literal — the glm14-1 requirement) and
 * empty-inclusive: the quantifier is '*', never '+'. glm15-2's driven
 * case is why '+.' is forbidden here — wp_connectors_quoted_literals()
 * rode a '+' copy new in glm14-1, and an expression like
 * `__DIR__ . "" . "/sub/../../outside.php"` (php -l clean, escapes at
 * runtime) matched ZERO literals as themselves: the empty literal's
 * closing quote PAIRED with the next literal's opening quote, the
 * traversal literal never captured, zero violations through every
 * self-containment gate. The '*' quantifier makes the empty literal
 * match ITSELF, so pairing cannot cross literal boundaries. The /s
 * modifier keeps a backslash-newline inside a multi-line literal from
 * splitting it (the wider spelling two of the four former inline copies
 * already rode; a single line carries no newline for it to touch).
 *
 * FOUR inline copies with THREE variants consolidated into this owner:
 * wp_connectors_line_without_string_literals() (the secret scanner's
 * marker blanker), wp_connectors_include_runtime_segments(),
 * wp_connectors_blank_quoted_strings(), and wp_connectors_quoted_literals()
 * — the house grammar is spelled ONCE, so the variants can never drift
 * apart again.
 *
 * @return string PCRE pattern matching one single- or double-quoted
 *                literal, escape-aware, empty literals included.
 */
function wp_connectors_quoted_literal_grammar()
{
    return '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s';
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
        wp_connectors_quoted_literal_grammar(),
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
 * quote character (glm29-3), as their RUNTIME VALUES (glm14-1).
 *
 * The old quote-blind capture (`[\'"]([^\'"]+)[\'"]`) lost which quote
 * opened a literal, so interpolation judgments could not tell a
 * double-quoted runtime-built string from a single-quoted static one —
 * the laundering hole the interpolation predicate below closes.
 *
 * glm14-1: the capture is ESCAPE-AWARE (the house quote grammar the
 * blanking helpers already ride — `\\.` pairs walk inside the literal,
 * so a backslash-escaped closing quote does not end it) and the inner
 * text is DECODED — escaped quotes and escaped backslashes become
 * their single bytes. The old class stopped the match AT an escaped
 * quote, so every byte after it was invisible to every self-
 * containment include gate: `require __DIR__ . '/a\'./../../../outside.php';`
 * (php -l clean; the runtime value resolves outside the plugin dir)
 * extracted only the truncated head `/a\` — no '..' segment, no escape
 * walk, zero violations — while the escape-aware runtime-segment
 * blanker erased the whole literal, so no layer ever saw the
 * traversal. Decoding is required for the same reason: judged on the
 * raw escaped bytes the truncated-head traversal still composes
 * inside. Only quote/backslash escapes decode — single-quoted PHP
 * keeps every other escape raw, and double-quoted control decodes
 * contribute only inert bytes to a containment walk.
 *
 * glm15-2: the pattern is the ONE house grammar owner
 * (wp_connectors_quoted_literal_grammar()) — this seam's inline copy
 * was the '+'-quantifier variant, and an empty literal ('' or "")
 * could not match as itself, so its closing quote PAIRED with the NEXT
 * literal's opening quote and the traversal literal beside it was
 * never captured: `require __DIR__ . "" . "/sub/../../outside.php";`
 * (php -l clean, escapes at runtime) answered ZERO violations through
 * every gate (driven). The '*' grammar makes the empty literal match
 * ITSELF; the pairing bug dies with the consolidation.
 *
 * @param string $expression Include-target expression or statement.
 * @return list<array{0: string, 1: string}> [opening quote, runtime value] pairs.
 */
function wp_connectors_quoted_literals($expression)
{
    $literals = array();
    if (preg_match_all(wp_connectors_quoted_literal_grammar(), $expression, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $literals[] = array(
                $match[0][0],
                (string) preg_replace_callback(
                    '/\\\\[\'"\\\\]/',
                    static function ($pair) {
                        return $pair[0][1];
                    },
                    substr($match[0], 1, -1)
                ),
            );
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
 * @throws RuntimeException When $scanRoot is not an absolute directory path
 *         resolving inside $pluginDir — the boundary guard's deliberate
 *         channel (t31-ocr23-3: build's catch holds RuntimeException, so
 *         the firing boundary reaches the build's named exit-1 verdict
 *         instead of an uncaught fatal exiting 255; every other refusal
 *         this walk can raise rides the returned violation list).
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
    /*
     * The scan root is VALIDATED at the boundary (OCR round 11,
     * t31-ocr11-14): the docblock invariant — absolute and inside
     * $pluginDir — held only by caller discipline, and a relative or
     * outside root silently walked foreign territory under the
     * plugin-dir anchor (driven red at HEAD: an outside root walked
     * and answered zero violations). The spelling must be absolute,
     * and its RESOLUTION must sit inside the plugin directory's —
     * realpath on both sides, so a '..'-woven spelling cannot pass
     * lexically and walk physically elsewhere; the iterator requires
     * both to exist regardless. The root must also NAME A DIRECTORY
     * (t31-ocr11-26, the round's verifier lens): a FILE inside the
     * plugin passed the containment check and died in the iterator
     * constructor's UnexpectedValueException — the engine's
     * vocabulary on a boundary the guard owns.
     */
    if (null !== $scanRoot) {
        /*
         * The guard speaks BOTH separator spellings (OCR round 42,
         * t31-ocr42-3 — the t31-ocr40-2/ocr41-1 dual-separator doctrine,
         * this owner): absoluteness and containment were judged with
         * POSIX-only spellings — a '\'-separator host answered realpath()
         * in its own join ('C:\repo\plugin\…'), so the '/'-anchored
         * absoluteness arm refused every LEGAL root and the '/'-joined
         * containment needle never matched the backslash-joined
         * haystack, falsely refusing build.php's composed-tree gate on
         * the whole platform class. The absoluteness judgment is a
         * SPELLING-CLASS judgment (leading '/', a drive-letter prefix,
         * or a UNC double backslash — never platform-gated: on POSIX a
         * 'C:\…' or '\\…' spelling simply fails realpath below); the
         * rtrim strips both separators (the r31-5 class list — on POSIX
         * the '\' arm costs residue only for a path literally named
         * with trailing backslash bytes, the ocr29-3 trade); the
         * containment needle joins through whichever separator
         * realpath() itself answered with. POSIX rides byte-identical
         * (the '/' arms answer exactly as before; the new arms are
         * dead there — the drivable class is the '\' host itself,
         * unreachable from this runner).
         */
        $scanRoot = rtrim((string) $scanRoot, '/\\');
        $plugin_real = realpath($pluginDir);
        $scan_real = realpath($scanRoot);
        if ('' === $scanRoot
            || ('/' !== $scanRoot[0] && 1 !== preg_match('/\A[A-Za-z]:/', $scanRoot) && 0 !== strpos($scanRoot, '\\\\'))
            || false === $plugin_real || false === $scan_real
            || ! is_dir($scan_real)
            || ($scan_real !== $plugin_real && 0 !== strpos($scan_real, $plugin_real . '/') && 0 !== strpos($scan_real, $plugin_real . '\\'))) {
            /*
             * The refusal carries the CHANNEL's own class (OCR round
             * 23, t31-ocr23-3): it once threw
             * InvalidArgumentException — a LogicException, outside
             * build's `catch (RuntimeException)` — breaking the
             * failure channel the glm31-4 sibling comment below
             * deliberately preserves (build's refusing
             * RuntimeException carries this walk's every other
             * refusal; UnexpectedValueException already means
             * something else there, the guarded abort). A firing
             * boundary was an uncaught fatal exiting 255 where every
             * sibling refusal reaches the build's named exit-1
             * verdict; the boundary speaks the channel's vocabulary
             * now.
             */
            throw new RuntimeException(sprintf(
                'the scan root must be an absolute DIRECTORY path inside the plugin directory, never a relative, outside, or non-directory walk under the plugin anchor — scan root: %s; plugin directory: %s',
                $scanRoot,
                $pluginDir
            ));
        }
    } else {
        $scanRoot = $pluginDir;
    }
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
     *
     * The CONSTRUCTION rides the same try (OCR round 24, t31-ocr24-2's
     * census completion — the ocr23 rd-1 doctrine applied to this owner
     * one round later): the iterator is built LAZILY on the scan root,
     * and a root this process cannot open throws from the constructor
     * BEFORE the foreach, one seam over the walk the fence below was
     * shaped for — the shared scan's own fence never fired for that
     * shape (driven: the inspector died at exit 255 through exactly
     * this construction while the php -l walk one screen down sat
     * already fenced). The scan root's is_dir() validation above does
     * not cover it — is_dir() stats, never opendirs.
     */
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
        );
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
    /*
     * glm15-10: the read owns its failure (the glm14-2 doctrine,
     * swept to the pair this round caught still laundering) — the
     * (string) cast turned a chmod-0000 src/autoload.php into '' and
     * the strip then answered three MISATTRIBUTED verdicts (must
     * register a PSR-4 autoloader; exactly one; must bind the
     * slug-derived prefix) over bytes nobody read. A false read is
     * the gate's own loud FAIL naming the file; every consumer — the
     * conventions gate, the build, the inspector — derives its
     * refusal from this list. The @ suppresses only the engine's
     * E_WARNING (the ocr30-4 doctrine): the loud refusal is the
     * violation line.
     */
    $source = @file_get_contents($autoload);
    if (false === $source) {
        $violations[] = sprintf('%s: src/autoload.php is unreadable — the autoloader check cannot run', $slug);

        return $violations;
    }
    $code = wp_connectors_strip_comments($source);
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
 * (review round t31-r4-9; the template class completed glm14-4).
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
 * glm14-4: the class is the ENGINE'S TEMPLATE EXTENSIONS — '.php' and
 * '.phtml', both case-insensitive — reopening the r6 ledger line
 * ("the scanner's extension allowlist misses .php5/.inc/.phtml in
 * every channel — do not re-flag without a real producer"): the
 * producer arrived (driven by the glm14 review, red at HEAD), a
 * 'form.phtml' entry with a parse error plus a live-shaped token
 * passing inspect-artifact ACCEPTED while the identical bytes as
 * 'form.php' were REJECTED — the '.php'-tail-only judgment exempted it
 * from the post-extraction php -l walk AND the secret scan's
 * extension allowlist, both channels at once. Widening the ONE owner
 * closes every channel at once (the near-source fold, the collectors,
 * the walkers) — the r6 line's own "in every channel" read as the
 * fix shape. '.php5'/'.php7'/'.inc' stay OUT until a driven producer
 * ships one (the r6 producer bar, restated; legacy distro configs
 * alone are not a producer).
 *
 * @param string $path File path or name (only the tail is judged).
 * @return bool True when the name ends in '.php' or '.phtml' in any case.
 */
function wp_connectors_is_php_source($path)
{
    $lowered = wp_connectors_ascii_lower((string) $path);

    return '.php' === substr($lowered, -4) || '.phtml' === substr($lowered, -6);
}

/**
 * The basename with the (any-case) PHP template extension stripped —
 * the ONE extension-strip owner (review round t31-r4-9; the template
 * class completed glm14-4).
 *
 * basename($path, '.php') strips only the exact-case suffix, so a
 * '.PHP'-spelled source kept its extension and the PSR-4 gate compared
 * a type name against 'ClockMath.PHP' — a misleading failure naming
 * the wrong defect. A name that is not a PHP source (per the ONE
 * predicate above) returns its basename unchanged. A '.phtml' source
 * strips its own six-byte tail (glm14-4 — a fixed four-byte strip
 * would leave the stem 'form.' for 'form.phtml').
 *
 * @param string $path File path or name.
 * @return string The basename, extension-stripped when it is a '.php' or '.phtml' in any case.
 */
function wp_connectors_basename_without_php_extension($path)
{
    $basename = basename((string) $path);

    if ('.phtml' === substr(wp_connectors_ascii_lower($basename), -6)) {
        return substr($basename, 0, -6);
    }

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
 * The class is a CONSTANT and is computed ONCE per process (OCR
 * round 52, t31-ocr52-6): the consumers judge per SEGMENT (the
 * inspector's case-fold and traversal folds, the near-source
 * predicate, the development-entry fold), so a per-call rebuild of
 * the range/array_map/implode spelling was ~N·(K+1) rebuilds of the
 * same 35 bytes over one archive walk. The static cache is
 * byte-identical to the rebuilt spelling by construction.
 *
 * @return string The strip charlist.
 */
function wp_connectors_path_edge_junk()
{
    static $junk = null;
    if (null === $junk) {
        $junk = implode('', array_map('chr', range(0, 0x20))) . ".\x7F";
    }

    return $junk;
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
 * at a consumer. (The fold MECHANIC is
 * wp_connectors_segment_is_named()'s, t31-ocr1-5: a consumer judging
 * a SUBSET of this vocabulary shares the same fold through that
 * owner, never a byte-exact twin.)
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
 * Whether a path segment IS one of the given names, judged by the ONE
 * fold the development-entry vocabulary rides (OCR round 1, t31-ocr1-5).
 *
 * The fold mechanic — trailing-edge-junk strip, then case-insensitive
 * compare — was welded inside the vocabulary owner, so a consumer that
 * needs "is this segment one of THESE names" (the secret scanner's
 * repo-walk prune, a documented SUBSET of the vocabulary) had no owner
 * to consume and hand-spelled a byte-exact twin (array_intersect): a
 * case-variant 'VENDOR/' or 'Tools/' was a development entry to the
 * builder, inspector, and lint (all folded) while the repo walk still
 * descended it. The mechanic lives HERE, one owner: the vocabulary
 * judgment below delegates to it, and subset consumers judge by the
 * same fold — same trailing-junk strip (the ONE edge-junk owner's
 * class; the LEADING side stays, the vocabulary's own dot-led names),
 * same case-insensitive compare — through the ONE ASCII fold owner
 * (wp_connectors_ascii_lower(), t31-ocr13-1): strcasecmp() consults
 * the engine's locale mapping (the r11-6/ocr10-4 doctrine), and this
 * judgment feeds the release gates' verdicts — never a byte-exact
 * twin at a consumer, never a locale-consulting fold at the owner.
 *
 * @param string $segment One path segment (a basename is one).
 * @param list<string> $names Canonical spellings to judge against.
 * @return bool True when the segment matches one of the names in any case.
 */
function wp_connectors_segment_is_named($segment, array $names)
{
    $segment = wp_connectors_ascii_lower(rtrim((string) $segment, wp_connectors_path_edge_junk()));
    foreach ($names as $name) {
        if ($segment === wp_connectors_ascii_lower((string) $name)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a path segment's TRAILING edge junk hides a '.php' extension
 * — the ONE near-source composition (OCR round 20: t31-ocr20-1's
 * extraction fence, extracted to one owner by the round's verifier
 * pass over the refutation lens's doctrine finding).
 *
 * The judgment owns exactly the DISAGREEMENT class: the raw spelling is
 * not a PHP source while the trailing-edge-junk-folded spelling is one
 * ('shell.php ', 'shell.php.', 'shell.php\x01' — bytes every
 * path-normalizing extraction target lands as a live .php source). A
 * segment that IS a source raw returns FALSE — it is an ordinary
 * source, every gate's charge; the fold rides the ONE edge-junk
 * owner's class on the trailing side only, the leading side stays (the
 * fold doctrine's own line: a leading dot is content). Every refusal
 * channel rides this predicate — the shared-source collector's
 * near-source fence (t31-r5-14/r6-4, over the basename, throwing), the
 * artifact inspector's extraction fence (t31-ocr20-1, over every
 * segment, the violation line), and the plugin-tree collector's
 * exclusion (t31-ocr23-8, over every segment, the silent skip that
 * keeps build and inspect answering one verdict) — the channel
 * differs, the judgment does not; the byte class can never drift
 * between them.
 *
 * @param string $segment One path segment (a basename is one).
 * @return bool True when the trailing fold turns the segment into a '.php' source.
 */
function wp_connectors_segment_is_near_source_php($segment)
{
    $folded = rtrim((string) $segment, wp_connectors_path_edge_junk());

    return '' !== $folded && ! wp_connectors_is_php_source($segment) && wp_connectors_is_php_source($folded);
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
 * destroy the '.git' family). The fold itself is
 * wp_connectors_segment_is_named()'s (t31-ocr1-5): the vocabulary is
 * this judgment's own charge, the MECHANIC is shared with subset
 * consumers through that one owner.
 *
 * @param string $segment One path segment (a basename is one).
 * @return bool True when the segment matches a vocabulary name in any case.
 */
function wp_connectors_is_development_entry($segment)
{
    return wp_connectors_segment_is_named($segment, wp_connectors_development_entry_names());
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
 * this prefix. The two FOLD ROLES stay distinct BY DOCTRINE
 * (t31-r12-16): the writer's collision fence folds case (any
 * case-variant of a generated destination refuses the build — the
 * stricter collision semantics), while the inspector's exemption below
 * matches the canonical spelling only (a case-variant is foreign, and
 * its segments judge by the development-entry vocabulary).
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
 * the CANONICAL spelling only (review round t31-r12-10, corrected by
 * its verifier round t31-r12-16).
 *
 * The exemption this feeds (the inspector exempts the embedded subtree
 * from the development-entry vocabulary, t31-r5-5) matches the prefix
 * the builder actually GENERATES — byte-exact — because a case-variant
 * spelling of the territory is foreign by the builder's own doctrine:
 * the writer's collision fence is the case-insensitive half (a
 * plugin-owned case-variant of an embed destination REFUSES the whole
 * build), so no builder-produced zip ever carries 'SRC/SHARED/…', and
 * an artifact that does is exactly the "a dev segment appearing in an
 * artifact IS the signal" posture of t31-r12-3 — its segments judge by
 * the vocabulary. (The first cut of t31-r12-10 folded the EXEMPTION
 * with the fence; the verifier lenses reproduced the regression — a
 * hostile zip's 'zai/SRC/SHARED/composer.json' went REJECTED →
 * ACCEPTED at exit 0 — and the fold came back out: the fence's fold is
 * the writer's collision check, never the inspector's exemption.)
 * Content screens (traversal, syntax, secrets, self-containment)
 * judge every entry under the exempted territory regardless.
 *
 * @param string $entry Zip entry path.
 * @param string $slug  The archive's top-level plugin directory.
 * @return bool True when the entry sits under the canonically-spelled embed destination.
 */
function wp_connectors_is_embed_destination($entry, $slug)
{
    return 0 === strpos((string) $entry, wp_connectors_embed_destination_prefix($slug));
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
 *                          non-canonical extension casing, a
 *                          near-source spelling (an edge byte hiding
 *                          the extension or riding a path segment),
 *                          or a subdirectory the walk cannot list.
 */
function wp_connectors_php_source_files($dir)
{
    $files = array();
    /*
     * The collector twins' DUAL-SEPARATOR spelling (OCR round 40,
     * t31-ocr40-2 — the r31-5 doctrine, swept to both collectors; the
     * census comment rides bin/build.php's collectFiles(), this
     * method's twin): the root strip and every below-root segment
     * split below speak BOTH separator spellings —
     * RecursiveDirectoryIterator joins child pathnames through the
     * NATIVE separator, so a '/'-only strip left the "relative" path
     * absolute on a '\' host and every judgment over it mis-segmented
     * (the near-source leading-side walk, the extension checks, the
     * PSR-4 casing fence). POSIX rides byte-identical; the '\' arm
     * costs residue only for a path literally named with trailing
     * backslash bytes (the ocr29-3 trade).
     */
    $dir = rtrim((string) $dir, '/\\');
    /*
     * The walk fences its recursion boundary (OCR round 36,
     * t31-ocr36-2 — the collectFiles census one file over): an
     * unreadable SUBDIRECTORY mid-tree (a chmod-000 child) aborts
     * the descent in the SPL iterator's own
     * UnexpectedValueException — another library's vocabulary
     * answering this collector's refusal (the t31-ocr33-6 class).
     * The construction rides the try (a source root this process
     * cannot open throws from the constructor, the ocr23 rd-1
     * doctrine); the abort converts to the walk's own refusal with
     * the SPL message riding parenthetically (it is what names the
     * path); the per-entry refusals inside are RuntimeExceptions and
     * pass the fence untouched.
     */
    try {
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
            $relative = (string) substr($file->getPathname(), strlen($dir) + 1);
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
                if (wp_connectors_segment_is_near_source_php(basename($relative))) {
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
            foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
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
            $root_lower_segments = explode('\\', wp_connectors_ascii_lower(wp_connectors_shared_source_namespace()));
            $declared_segments = explode('\\', ltrim($declared_namespace, '\\'));
            if (count($declared_segments) < count($root_lower_segments)
                || array_map('wp_connectors_ascii_lower', array_slice($declared_segments, 0, count($root_lower_segments))) !== $root_lower_segments) {
                throw new RuntimeException(sprintf(
                    'shared source %s declares %s — not the shared tree root %s the embed rewrites and stages under src/Shared/, so its staged path maps no autoloadable class; declare the tree root (or deeper) in it',
                    $dir . '/' . $relative,
                    $declared_namespace,
                    wp_connectors_shared_source_namespace()
                ));
            }
            $below_root = array_slice($declared_segments, count($root_lower_segments));
            $directories = array();
            foreach (explode(DIRECTORY_SEPARATOR, dirname($relative)) as $segment) {
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
    } catch (UnexpectedValueException $walk_refusal) {
        throw new RuntimeException(sprintf(
            'shared source tree carries a subdirectory that cannot be listed (%s) — the walk fences its recursion boundary and answers its own refusal, never the SPL iterator\'s vocabulary (the t31-ocr33-6 fence, swept to the collector census)',
            $walk_refusal->getMessage()
        ));
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
 * Renders bytes for a diagnostic line with every line-forging byte
 * neutralized (verifier round t31-r12-15, from the security lens's
 * forged-output finding).
 *
 * Violation messages interpolate ARCHIVE-CONTROLLED text — entry names,
 * captured engine diagnostics — and a hostile zip legally carries a
 * 250+-byte entry-name component with an embedded newline (or an ANSI
 * escape: terminal rewriting) in it. The inspector's own lines are
 * diagnostics a human scans, so the interpolations print through this
 * ONE seam: every C0 control and DEL becomes a space (a newline can
 * no longer start a line that reads as a different verdict; an ESC
 * sequence can no longer rewrite the terminal). The printable body —
 * including multibyte UTF-8 — rides verbatim.
 *
 * The Unicode bidi/format controls join the substitution vocabulary
 * (OCR round 50, t31-ocr50-5, security — the same forged-output lens):
 * the URL and header surfaces REFUSE the class by their own screens,
 * but this seam's input must RENDER, so U+202A-202E (the embedding
 * and override pair LRE/RLE/PDF/LRO/RLO — U+202E RLO is the classic
 * filename-spoof byte), U+200E/U+200F (LRM/RLM), and U+2066-2069
 * (the isolate quartet LRI/RLI/FSI/PDI) neutralize to a space beside
 * the C0 class — a crafted entry name can no longer visually REORDER
 * its own diagnostic line. The map rides explicit byte sequences
 * (array-form strtr matches the longest key first, so the multibyte
 * entries win over any single byte of their own spelling); every
 * sequence is pure non-ASCII bytes, so an ASCII-only diagnostic is
 * byte-identical under the new vocabulary.
 *
 * The map is computed ONCE per process (OCR round 55, t31-ocr55-3 —
 * the t31-ocr52-6 doctrine, this file's own hoisting idiom): the
 * seam is called per entry in the archive walk and per violation
 * line, so the per-call rebuild of the 43-entry substitution map
 * (range + array_map + array_combine + array_fill + array_fill_keys
 * + array_merge) was dozens of constant-map builds per hostile
 * archive. The static cache is byte-identical to the rebuilt
 * spelling by construction.
 *
 * @param string $value The bytes about to interpolate into a diagnostic.
 * @return string The same bytes with every C0 control, DEL, and bidi/format control as a space.
 */
function wp_connectors_printable($value)
{
    static $map = null;
    if (null === $map) {
        $map = array_merge(
            array_combine(array_map('chr', array_merge(range(0, 31), array(127))), array_fill(0, 33, ' ')),
            array_fill_keys(array(
                "\u{200E}", "\u{200F}",
                "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
                "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",
            ), ' ')
        );
    }

    return (string) strtr((string) $value, $map);
}

/**
 * Renders a LIST of diagnostic lines through the ONE printable seam —
 * the list twin of wp_connectors_printable() (verifier round
 * t31-r13-4, raised independently by both lenses).
 *
 * The inspector merges violation lines from the shared helpers —
 * main-file basenames, header values, the version-constant value, the
 * self-containment walk's landed paths and include statements — every
 * one of them archive-controlled text (landed file names survive
 * extraction byte-exact; header and code values are the artifact's
 * own content). The helpers are pure PRODUCERS (the conventions gate
 * and the builder render them over the repo's own trusted bytes); the
 * inspector is the hostile-input surface, so it renders what it
 * merges through the seam at the merge — one line each, and no helper
 * grows a second opinion about rendering.
 *
 * @param list<string> $lines The violation lines about to merge into a report.
 * @return list<string> The same lines with every C0 control and DEL as a space.
 */
function wp_connectors_printable_lines(array $lines)
{
    return array_map('wp_connectors_printable', $lines);
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
    /*
     * glm15-10: the read owns its failure — the autoloader reader's
     * own sibling (the (string) cast laundered a false read into ''
     * and the constant probe below answered its misattributed
     * 'must define constant' verdict over unread bytes).
     */
    $source = @file_get_contents($mainFile);
    if (false === $source) {
        $violations[] = sprintf('%s: the main plugin file is unreadable — the version-constant check cannot run', $slug);

        return $violations;
    }
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
    /*
     * The AUTO-GLOBAL $argv, never $_SERVER['argv'] (verifier round
     * t31-r12-17, the correctness lens's finding): under a
     * variables_order ini without "S" (GPC is a documented spelling)
     * $_SERVER stays unpopulated — $_SERVER['argv'] read NULL, the
     * guard answered false, and every entry script became a SILENT
     * EXIT-0 NO-OP (reproduced: `php -d variables_order=GPC
     * bin/inspect-artifact.php x.zip` printed nothing and exited 0 —
     * the ACCEPTED contract — instead of inspecting). The auto-global
     * is populated in every CLI process regardless of that ini (the
     * CLI SAPI forces it, register_argc_argv included — verified).
     */
    global $argv;
    if (PHP_SAPI !== 'cli' || realpath((string) $argv[0]) !== $script) {
        return false;
    }
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    return true;
}

/**
 * The CLI argument vector — the auto-global, function-scoped so every
 * consumer reads it provably defined (verifier round t31-r12-17).
 *
 * Populated in every CLI process regardless of the variables_order and
 * register_argc_argv inis (the CLI SAPI forces it). Consumers that
 * need the args call this beside wp_connectors_cli_entry()'s true
 * branch, never a bare file-scope $argv (which a static analyzer
 * cannot prove defined once the guard moved into the helper).
 *
 * The list<string> contract holds OUTSIDE a CLI process too (OCR
 * round 11, t31-ocr11-15): a web/fpm SAPI leaves the auto-global
 * undefined, and the bare return raised an "Undefined $argv" warning
 * and returned null — the isset guard answers the empty list instead,
 * so the helper's type is true everywhere (the warning class the
 * entry guard's own history closed, r12-17).
 *
 * @return list<string> The argv (program name first; empty outside a CLI process).
 */
function wp_connectors_cli_args()
{
    // The read goes through the symbol table, not the `global`
    // binding (t31-ocr11-15): the auto-global is undefined outside a
    // CLI process, and a `global $argv` statement binds a NULL the
    // analyzer must model as the always-populated CLI vector — the
    // offset read states the truth (the vector is there or it is not)
    // for both the engine and the analyzer.
    return isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : array();
}
