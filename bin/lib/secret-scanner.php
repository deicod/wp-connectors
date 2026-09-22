<?php
/**
 * Secret-pattern scanner (shared by bin/scan-secrets.php and the artifact
 * inspector).
 *
 * Detects live-credential shapes (API keys, OAuth tokens, JWTs, private
 * keys) in files. Exemptions are structured, never a bare word on the line:
 * a line is skipped only when it carries the strict "secrets:allow" marker
 * in an actual comment — never as string-literal contents, and never in
 * any string-data region (glm16-1: the marker judge reads the ONE
 * token-masked view, wp_connectors_mask_string_contents(), which owns
 * every region class — quoted interiors, heredoc/nowdoc bodies however
 * nested, halt-compiler tails, ?>-bounded inline HTML) — and a match is
 * skipped only when the matched VALUE itself is recognizable as a fake
 * (placeholder shapes, well-known dummy segments) — see
 * wp_connectors_allow_marker_pattern() and
 * wp_connectors_is_recognizably_fake_secret().
 *
 * Findings deliberately never include the matched text — only file, line,
 * and pattern — so the scanner itself can never leak a secret into logs.
 *
 * SELF-CONTAINED LOAD PATH (OCR round 3, t31-ocr3-5): the repo-walk
 * prune judges segments through the ONE fold mechanic
 * (wp_connectors_segment_is_named(), t31-ocr1-5), which lives in this
 * library's sibling — the vocabulary owner — and the dependency is
 * declared HERE, by require_once, so requiring THIS file alone yields
 * a working scanner (tests/SecureFixturesTest.php requires exactly
 * this one file; pre-round that load pattern worked only when the
 * test bootstrap had happened to load plugin-tools first, and a
 * consumer requiring only the scanner fataled mid-scan on the first
 * walked entry). The direction is one-way by construction:
 * plugin-tools.php requires nothing from this library and stays
 * dependency-free; a consumer requiring both, in either order, is
 * unaffected (require_once both ways).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/plugin-tools.php';

/**
 * Returns the secret patterns this repository guards against.
 *
 * Each entry: name => [ regex, description ].
 *
 * @return array<string, list<string>>
 */
function wp_connectors_secret_patterns()
{
    return array(
        'private-key' => array( '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/', 'Private key material' ),
        'github-token' => array( '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/', 'GitHub token' ),
        'openai-anthropic-key' => array( '/\bsk-(?:ant-|proj-)?(?:api3?-)?[A-Za-z0-9_-]{20,}\b/', 'OpenAI/Anthropic API key' ),
        'xai-key' => array( '/\bxai-[A-Za-z0-9]{20,}\b/', 'xAI API key' ),
        'zai-key' => array( '/\b[a-f0-9]{32}\.[a-f0-9]{16}\b/', 'z.ai / bigmodel.cn API key' ),
        'aws-key' => array( '/\bAKIA[0-9A-Z]{16}\b/', 'AWS access key ID' ),
        'google-key' => array( '/\bAIza[0-9A-Za-z_-]{35}\b/', 'Google API key' ),
        'slack-token' => array( '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/', 'Slack token' ),
        'jwt' => array( '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}\b/', 'JWT token' ),
        'bearer-token' => array( '/\bBearer\s+[A-Za-z0-9._+\/-]{24,}/i', 'HTTP Bearer token' ),
    );
}

/**
 * The strict line-exemption marker for deliberate fixture/example secrets.
 *
 * A line is exempt ONLY when the marker appears inside a comment on that
 * line: `$key = '…'; // secrets:allow` (also `#`, `/* … *\/`, and ` * `
 * docblock continuations) — where "inside a comment" means OUTSIDE any
 * string literal (wp_connectors_line_without_string_literals()). A bare
 * word like "fixture" anywhere on the line exempts nothing — a real
 * credential sitting on a line that merely mentions "fixture" must still
 * be flagged.
 *
 * @return string PCRE pattern matching the marker inside a comment.
 */
function wp_connectors_allow_marker_pattern()
{
    return '/(?:^|\s)(?:\/\/|#|\/\*|\*)\s*secrets:allow\b/';
}

/**
 * Removes PHP string-literal CONTENTS from a source line — the NON-PHP
 * payload arm of the marker judge (glm16-1).
 *
 * Single- and double-quoted literals (escape-aware within the line) are
 * replaced by empty shells, so comment-marker detection can never honor a
 * marker that is itself string content — `$v = 'sk-…' . '// secrets:allow';`
 * carries a live value plus a lookalike marker and must stay flaggable.
 * Deliberately line-based (no tokenizer): a payload carrying no '<?'
 * anywhere can lex no PHP tokens at all (every open-tag spelling starts
 * with it), so the whole-file masked view has nothing to see and the
 * documented tolerance stands — markers in prose payloads (.txt/.md
 * fixtures) stay honored exactly as the line-local grammar always read
 * them (the pinned glm15-1 doctrine: non-PHP payloads, no tokens, no
 * behavior change). Every PHP-BEARING payload rides
 * wp_connectors_mask_string_contents() instead — the ONE owner that sees
 * the multi-line region classes (nested heredoc bodies, multi-line
 * quoted interiors, halt-compiler tails, ?>-bounded inline HTML) a
 * line-local lens cannot.
 *
 * @param string $line One line of source.
 * @return string The line with string contents blanked out.
 */
function wp_connectors_line_without_string_literals($line)
{
    // glm15-2: the ONE house grammar owner — never an inline copy (the
    // four copies had drifted into three variants).
    return (string) preg_replace(
        wp_connectors_quoted_literal_grammar(),
        "''",
        $line
    );
}

/**
 * Whether a matched secret VALUE is recognizable as a fake.
 *
 * Recognizable fakes are verifiable from the value itself: placeholder
 * shapes wrapped entirely in ${…} or <…>, well-known dummy segments
 * bounded by separators (sk-proj-TEST-…, YOUR_API_KEY, test-key-…,
 * wpct_fixture_…, not-a-real-…), and obvious sequential filler. Anything
 * else must be treated as live. This mirrors the line-level marker rule:
 * prose words around the value never exempt it, only the value's own shape
 * can.
 *
 * @param string $value The matched secret text (never reported verbatim).
 * @return bool True when the value is verifiably not a live credential.
 */
function wp_connectors_is_recognizably_fake_secret($value)
{
    if (preg_match('/^\$\{[^}]+\}$/', $value) || preg_match('/^<[^>]+>$/', $value)) {
        return true;
    }
    if (preg_match('/(?:^|[-_\s])(?:not-a-real|notareal|test-value|test|example|dummy|sample|fixture|placeholder|your|fake|redacted|wpct)(?:[-_\s]|$)/i', $value)) {
        return true;
    }

    return (bool) preg_match('/0123456789abcdef|abcdefgh/i', $value);
}

/**
 * Parses one memory_limit spelling to its byte count, WIDTH-AWARE
 * (glm17-13).
 *
 * The scale is computed in float and SATURATED at PHP_INT_MAX: an
 * integer multiply of a limit whose scaled bytes exceed the host's
 * integer width answered garbage through the cast ('4G' on a 32-bit
 * build — a wrapped count driving the headroom to 0 and EVERY scan
 * into the loud refusal; any >8EiB spelling likewise on 64-bit),
 * where the honest reading of a limit larger than the process can
 * address is the bound-off class: it can never fatal the token pass.
 * The same saturation path answers an unparseable spelling — the
 * bound is off, never misjudged. In-width values (up to 2^53 bytes,
 * every real limit) stay exact.
 *
 * @param string $limit The raw ini spelling (e.g. '128M', '2G', '-1').
 * @return int The byte count; PHP_INT_MAX when over-width or unparseable.
 */
function wp_connectors_memory_limit_to_bytes($limit)
{
    if (1 !== preg_match('/\A(\d+)\s*([kmg]?)(?:b)?\z/i', trim((string) $limit), $m)) {
        return PHP_INT_MAX;
    }
    $count = (float) $m[1];
    $unit  = strtolower($m[2]);
    $multiplier = 'g' === $unit ? 1073741824.0 : ('m' === $unit ? 1048576.0 : ('k' === $unit ? 1024.0 : 1.0));
    if ($count * $multiplier >= (float) PHP_INT_MAX) {
        return PHP_INT_MAX;
    }

    return (int) ($count * $multiplier);
}

/**
 * The byte headroom a token pass may spend before the process's own
 * memory limit would fatal it (glm16-2).
 *
 * token_get_all() materializes the whole stream at once: measured on
 * this engine (PHP 8.5), a dense ~1.9 MB source needs ~98x its own
 * bytes in token arrays — ~186 MB against the 128M default limit — the
 * fatal-without-a-verdict class glm14-3/glm14-6 closed for the LINE
 * scan, reopened by the glm16-1 mask ride. The headroom is the parsed
 * memory_limit minus live usage; an unlimited (-1/empty) limit answers
 * PHP_INT_MAX, and the parse itself is width-aware (glm17-13, the
 * helper above) — the bound is off, never misjudged.
 *
 * @return int Bytes available before the limit.
 */
function wp_connectors_scan_token_memory_headroom()
{
    $limit = (string) ini_get('memory_limit');
    if ('' === $limit || '-1' === $limit) {
        return PHP_INT_MAX;
    }

    return max(0, wp_connectors_memory_limit_to_bytes($limit) - memory_get_usage());
}

/**
 * Whether a payload carries an INI-independent sample open at BYTE
 * level (glm19-1) — the pre-screen that gates a text-family payload's
 * token passes.
 *
 * The region walk itself rides the tokenizer now (the engine's own
 * close), but the token-memory census must judge BEFORE any token
 * pass, so this boolean carries the old walk's open classification:
 * '<?=' or '<?php' with core's follower class ([ \t\r\n] or end of
 * input). A payload without one never reaches a token pass at all —
 * exactly the pre-screening shape the byte walk gave.
 *
 * @param string $contents File contents.
 * @return bool True when an INI-independent open spelling exists.
 */
function wp_connectors_payload_has_sample_open($contents)
{
    $at = 0;
    while (false !== ($open = strpos($contents, '<?', $at))) {
        $after = $open + 2;
        if ('=' === ($contents[ $after ] ?? '')) {
            return true;
        }
        if ('php' === wp_connectors_ascii_lower((string) substr($contents, $after, 3))) {
            $follower = $contents[ $after + 3 ] ?? '';
            if ('' === $follower || str_contains(" \t\r\n", $follower)) {
                return true;
            }
        }
        $at = $after;
    }

    return false;
}

/**
 * The PHP sample REGIONS of a text-family payload (glm18-1/glm18-11).
 *
 * The open spellings are the engine's INI-independent ones — '<?=' and
 * '<?php' with core's own follower class ([ \t\r\n] or end of input; a
 * glued '<?phpecho' is inline HTML under the production-default INI,
 * the t31-ocr64-1 doctrine). The close rides the TOKENIZER, the
 * masker's own pass (glm19-1): the engine's lexer is the one owner of
 * where PHP mode ends, so a '?>' spelled inside a quoted or heredoc
 * interior does not close the region — the byte-level scan this
 * replaces split it there and the code after the in-string close fell
 * to the line-local arm, reopening the glm18-1 laundering class
 * through the region walk. Every matched open-close pair is a region;
 * an open with no close names the unclosed TAIL (open→EOF) the engine
 * lexes as code — the tail region glm18-1 routed onto the masked
 * view. The INI-dependent spellings (a bare '<?', the glued opener)
 * stay the recorded lexer-refused-opener corner, never a new class
 * here — on a short_open_tag host those bytes lex as PHP mode to the
 * tokenizer, and the walk simply does not open a region at them.
 *
 * @param string $contents File contents.
 * @return list<array{int, int}> The sorted inclusive [start, end] byte spans.
 */
function wp_connectors_php_sample_regions($contents)
{
    $regions = array();
    $tokens = token_get_all($contents);
    $at = 0;
    for ($i = 0, $n = count($tokens); $i < $n; ++$i) {
        $token = $tokens[ $i ];
        $text = is_array($token) ? $token[1] : $token;
        $id = is_array($token) ? $token[0] : null;
        $opens = T_OPEN_TAG_WITH_ECHO === $id;
        if (! $opens && T_OPEN_TAG === $id) {
            $follower = (string) substr($text, 5);
            $opens = 'php' === wp_connectors_ascii_lower((string) substr($text, 2, 3))
                && ('' === $follower || str_contains(" \t\r\n", $follower));
        }
        if (! $opens) {
            $at += strlen($text);
            continue;
        }
        // The region closes at the next close TAG the engine lexes —
        // never at an in-string close spelling — or runs to EOF.
        $pos = $at + strlen($text);
        $end = strlen($contents) - 1;
        $close = $n;
        for ($j = $i + 1; $j < $n; ++$j) {
            $inner = $tokens[ $j ];
            if (T_CLOSE_TAG === (is_array($inner) ? $inner[0] : null)) {
                $end = $pos + 1; // Inclusive through the '>' byte.
                $close = $j;
                break;
            }
            $pos += strlen(is_array($inner) ? $inner[1] : $inner);
        }
        $regions[] = array( $at, $end );
        if ($n === $close) {
            break; // The unclosed tail runs to EOF.
        }
        $i = $close;
        $at = $pos + strlen(is_array($tokens[ $close ]) ? $tokens[ $close ][1] : $tokens[ $close ]);
    }

    return $regions;
}

/**
 * One line's PER-ARM views under PAIR-BOUNDED routing (glm18-11,
 * glm19-2): the bytes inside a sample region read the token-masked
 * view (string data blanked, the marker judge's honest lens for CODE
 * bytes), the bytes outside keep the line-local arm — a prose marker
 * beside a mentioned sample stays a real prose marker.
 *
 * The arms answer separately, never composed: the line-skip does not
 * cross a region boundary — a marker in the prose bytes exempts only
 * prose matches, a marker in the region's code view (a real comment
 * the masker never blanks) exempts only the region's bytes.
 *
 * glm19-11: the walk rides a by-ref REGION CURSOR — the regions are
 * sorted and the line starts are monotonic, so a region closed on an
 * earlier line is dead for every later line and the cursor consumes
 * it for good, each line's walk starting where the last line stopped:
 * O(lines + regions) over the whole payload, never the O(lines ×
 * regions) re-walk from index 0 that answered a 23,000-pair payload
 * in ~13.9 s (measured twice independently).
 *
 * The masked view is same-length by construction, so its bytes slice
 * 1:1 against the line's, and each served span is the line-relative
 * inclusive byte range that rode the masked view.
 *
 * @param string                $line          One source line.
 * @param int                   $line_start    The line's byte offset in the payload.
 * @param list<array{int, int}> $regions       Sorted inclusive sample spans.
 * @param string                $masked        The payload's token-masked view.
 * @param int                   $region_cursor The shared walk cursor (first
 *                                            region not yet consumed; advanced in place).
 * @return array{prose: string, code: string, spans: list<array{int, int}>}
 *         The line-local view of the outside bytes, the masked view of
 *         the region bytes, and the line-relative region spans.
 */
function wp_connectors_sample_region_line_view($line, $line_start, array $regions, $masked, &$region_cursor)
{
    $len = strlen($line);
    $prose = '';
    $code = '';
    $spans = array();
    $cursor = 0;
    $count = count($regions);
    while ($region_cursor < $count) {
        $region = $regions[ $region_cursor ];
        $start = $region[0] - $line_start;
        if ($start >= $len) {
            break; // The region begins on a later line — every later one too (sorted).
        }
        $end = $region[1] - $line_start; // Inclusive, line-relative.
        if ($end < $cursor) {
            // The region closed before this line's walk position —
            // dead for every later line too: consumed for good.
            ++$region_cursor;
            continue;
        }
        if ($start > $cursor) {
            $prose .= wp_connectors_line_without_string_literals((string) substr($line, $cursor, $start - $cursor));
        }
        $from = max($start, $cursor);
        $through = min($end, $len - 1);
        $code .= (string) substr($masked, $line_start + $from, $through - $from + 1);
        $spans[] = array( $from, $through );
        $cursor = $through + 1;
        if ($cursor >= $len) {
            return array( 'prose' => $prose, 'code' => $code, 'spans' => $spans );
        }
        // The region closed inside this line — dead for every later one.
        // (A region reaching the line's last byte stays at the cursor:
        // the next line's dead-region arm consumes it, and one that
        // SPANS past the line must be served again there.)
        ++$region_cursor;
    }

    return array(
        'prose' => $prose . wp_connectors_line_without_string_literals((string) substr($line, $cursor)),
        'code' => $code,
        'spans' => $spans,
    );
}

/**
 * Whether a payload's HEAD opens PHP — a directly-named file's content
 * shape (glm18-2).
 *
 * The operator naming one file on the command line owns that choice
 * (the glm14-3 no-cap doctrine's own premise), so the file-root arm
 * judges the bytes, not the extension: a payload whose head (after
 * leading whitespace) is an INI-independent open tag — '<?=' or '<?php'
 * with core's follower class — is a PHP script whatever its name
 * spells. The INI-dependent spellings (a bare '<?', a glued
 * '<?phpecho') stay the recorded lexer-refused-opener corner, never a
 * new class here.
 *
 * @param string $contents File contents.
 * @return bool True when the payload's head opens PHP.
 */
function wp_connectors_head_opens_php($contents)
{
    $at = strspn((string) $contents, " \t\r\n");
    if ('<?' !== substr((string) $contents, $at, 2)) {
        return false;
    }
    $after = $at + 2;
    if ('=' === ($contents[ $after ] ?? '')) {
        return true;
    }
    if ('php' !== wp_connectors_ascii_lower((string) substr((string) $contents, $after, 3))) {
        return false;
    }
    $follower = $contents[ $after + 3 ] ?? '';

    return '' === $follower || str_contains(" \t\r\n", $follower);
}

/**
 * The HOST engine's actual open-tag lexing, probed once (glm18-4).
 *
 * Whether a bare '<?' opens PHP mode is the short_open_tag INI — ON on
 * dev boxes, OFF on the production default — and the token-memory
 * census must charge only the spans THIS engine would really tokenize:
 * 23k '<?xml-stylesheet …?>' processing instructions in a 1.84 MB
 * document lex as ONE inline-HTML run on a default host (measured
 * token cost ~1x, ~1.8 MB) but were charged the dense ~98x factor as
 * 'spans', answering the loud refusal over bytes the tokenizer never
 * opens. The probe is the engine's own answer — two tiny
 * token_get_all() calls, cached for the process — never an INI-string
 * re-derivation; '<?php' with core's follower class is INI-independent
 * and never rides the probe.
 *
 * @return array{bare: bool, echo: bool} Whether the engine opens a
 *         bare '<?' spelling, and whether it opens '<?='.
 */
function wp_connectors_engine_opener_lexing()
{
    static $probe = null;
    if (null === $probe) {
        $probe = array( 'bare' => false, 'echo' => false );
        foreach (token_get_all('<?x') as $token) {
            if (T_OPEN_TAG === (is_array($token) ? $token[0] : null)) {
                $probe['bare'] = true;
                break;
            }
        }
        foreach (token_get_all('<?=') as $token) {
            if (T_OPEN_TAG_WITH_ECHO === (is_array($token) ? $token[0] : null)) {
                $probe['echo'] = true;
                break;
            }
        }
    }

    return $probe;
}

/**
 * Scans one file's contents for secret patterns.
 *
 * @param string $contents     File contents.
 * @param string $label        File label for findings (path or zip entry).
 * @param bool   $named_target True when the caller named this one file
 *                             directly (the scan_paths file-root arm) —
 *                             a php-headed payload then rides the CODE
 *                             routing whatever its extension spells
 *                             (glm18-2). The walk never sets it.
 * @return list<string> Findings ("<label>:<line> <name> (<description>)").
 */
function wp_connectors_scan_string($contents, $label, $named_target = false)
{
    $findings = array();
    $allowMarker = wp_connectors_allow_marker_pattern();
    /*
     * glm16-2: the ride owns its memory bound. The same strpos gate is
     * the pre-gate (every non-PHP payload never reaches the tokenizer
     * at all — the census the glm15-1 fix would have gated on '<<<'
     * is gone, and the mask cares about quotes, not heredocs); for a
     * PHP-bearing source the COST the token pass can spend is driven
     * by the PHP-MODE SPANS — a prose run between tags is one
     * T_INLINE_HTML token, so a markdown ledger carrying small code
     * samples tokenizes at its samples' cost, not its megabytes. The
     * span walk is byte-honest (measured dense-worst-case factor:
     * ~98x the span); a span set whose estimate would not fit the
     * parsed limit answers the LOUD refusal in the glm14-2 vocabulary,
     * the 2-MB loud-skip doctrine's own shape — never a silent fatal
     * mid-scan, never a verdict reading clean over bytes the scan
     * could not tokenize. The walk's one ceiling: a '?>' spelled
     * INSIDE a string or comment splits a span the lexer keeps whole,
     * so a file deliberately WOVEN with in-string close tags could
     * under-refuse — the exact pre-round fatal class, and a shape no
     * honest producer ships.
     *
     * glm17-2: the bound rides the SUM of the spans, never the largest
     * alone — token_get_all() materializes the WHOLE token stream at
     * once, so 24 dense ~100 KB spans (~2.4 MB, every one of them
     * under any per-span bound) paid the SUM (~235 MB) against the
     * default 128M limit and FATALED with no verdict (driven in a
     * child process at HEAD); the largest-span spelling passed the
     * gate on exactly the shape the gate exists to refuse.
     */
    /*
     * glm17-3: the pre-gate is extension- and shape-aware. The bare
     * '<?' probe routed every text-family payload carrying an '<?xml'
     * declaration or a fenced, unclosed php sample onto the masked
     * view — where the masker blanks the prose as inline HTML and a
     * legitimately marked fixture's marker vanished (driven: a marked
     * .md answered 1 finding at HEAD, 0 before the mask ride; the
     * >1.3 MB unclosed-sample .md answered the token-memory refusal
     * where the line scan always ran clean). The masked view is for
     * CODE payloads — a .php/.phtml label, or an extension-less label
     * (the in-process spellings) — which keep the bare '<?' probe
     * exactly as before. A TEXT-family extension (anything else the
     * walk allowlists: .md/.txt/.svg/.xml/...) reaches the tokenizer
     * only for a genuine embedded sample — a '<?php' open WITH a
     * matching '?>' close after it, the one shape that can carry real
     * multi-line string regions to launder; bare '<?xml' declarations,
     * '<?=' short-echo samples, and fence-delimited unclosed samples
     * keep the line-local tolerance arm exactly as the pre-diff
     * behavior read them (a matched-close sample's marker-in-data
     * still launders correctly through the mask — pinned below).
     *
     * glm18-1 CORRECTS the unclosed-sample half of that claim: a text
     * family payload whose unclosed '<?php'/'<?=' sample carries a
     * marker inside a multi-line string interior LAUNDERED through the
     * line-local arm — the interior line carries no quote bytes, so
     * the line-local lens honored the marker and a live key beside it
     * scanned to zero findings (driven at HEAD; 1 at base, where the
     * bare '<?' probe routed the whole payload onto the masked view).
     * The unclosed tail IS code the engine lexes, so it rides the
     * masked view from its open tag's line onward while the prose
     * above keeps the line-local arm — and because the masker now
     * tokenizes the tail, the tail rides the token-memory census like
     * every matched sample (the >1.3 MB unclosed .md leg's clean
     * verdict was purchased by never tokenizing the tail; the honest
     * worst-case bound on a tokenized tail is the loud refusal,
     * glm17-2's own recorded 'no honest factor passes 1.4 MB while
     * refusing 2.4 MB' premise).
     *
     * glm18-11 completes the routing to PAIR-BOUNDED for the matched
     * class too, closing the recorded-residual false positive: prose
     * MERELY MENTIONING a complete '<?php … ?>' pair routed the WHOLE
     * file onto the masked view, where the mention's surrounding prose
     * blanked as inline HTML and a legitimately marked fixture's
     * marker vanished (identical at base, pre-existing, unrecorded —
     * the marked-fixture false positive survived for this spelling).
     * The region walk (wp_connectors_php_sample_regions()) owns the
     * routing for every text-family shape now: the sample regions —
     * matched pairs AND the unclosed tail — ride the masked view, the
     * bytes outside them keep the line-local arm.
     */
    $label_ext = strtolower((string) pathinfo($label, PATHINFO_EXTENSION));
    /*
     * glm18-2: a DIRECTLY-NAMED file is judged by content shape, not
     * extension — 'scan-secrets.php config.inc' over pure-PHP bytes
     * fed an extension-aware gate (the glm17-3 text-family routing)
     * that never saw '<?php'-without-'?>' spellings honestly: the
     * short-echo-headed script laundered its string interiors through
     * the line-local arm (driven). Explicitly named = operator intent;
     * the php-headed payload rides the CODE arm whatever its name
     * spells, while text content keeps glm17-3's benign extension
     * routing exactly.
     */
    $php_family = '' === $label_ext || 'php' === $label_ext || 'phtml' === $label_ext
        || ($named_target && wp_connectors_head_opens_php($contents));
    /*
     * glm19-1: the region walk rides the tokenizer now, so the census
     * judges BEFORE it — a text-family payload reaches any token pass
     * only through the byte-level INI-independent open pre-screen
     * (wp_connectors_payload_has_sample_open(), the old walk's open
     * classification as a boolean), and the census rides ahead of the
     * walk exactly as it rides ahead of the mask. A payload the
     * pre-screen refuses never tokenizes at all — the pre-screening
     * shape the byte walk itself gave.
     */
    $has_php = false;
    if ($php_family) {
        $has_php = false !== strpos($contents, '<?');
    } elseif (wp_connectors_payload_has_sample_open($contents)) {
        $has_php = true;
    }
    if ($has_php) {
        /*
         * glm18-4: every span the census charges must be a span THIS
         * engine would really tokenize — the walk classifies each '<?'
         * spelling against the host's probed open-tag lexing
         * (wp_connectors_engine_opener_lexing(), short_open_tag-aware):
         * '<?php' with core's follower class opens under every INI, a
         * bare '<?' (an '<?xml' processing instruction included) and a
         * glued '<?phpecho' only where the engine's probe says the
         * short spelling opens. A non-opener's bytes — and its '?>',
         * prose on a default host — never enter the total.
         */
        $opener = wp_connectors_engine_opener_lexing();
        $span_total = 0;
        $at = 0;
        while (false !== ($open = strpos($contents, '<?', $at))) {
            $after = $open + 2;
            if ('=' === ($contents[ $after ] ?? '')) {
                $opens = $opener['bare'] || $opener['echo'];
            } elseif ('php' === wp_connectors_ascii_lower((string) substr($contents, $after, 3))) {
                $follower = $contents[ $after + 3 ] ?? '';
                $opens = '' === $follower || str_contains(" \t\r\n", $follower) || $opener['bare'];
            } else {
                $opens = $opener['bare'];
            }
            if (! $opens) {
                $at = $after;
                continue;
            }
            $close = strpos($contents, '?>', $after);
            $end = false === $close ? strlen($contents) : $close;
            $span_total += $end - $open;
            $at = false === $close ? strlen($contents) : $close + 2;
        }
        if ($span_total * 98 > wp_connectors_scan_token_memory_headroom()) {
            return array( sprintf('%s: over the secret-scan token-memory bound — the secret scan cannot run', $label) );
        }
    }
    /*
     * glm16-1: the marker judge reads the ONE token-masked view —
     * wp_connectors_mask_string_contents() owns every string-data
     * region class in a single length-preserving pass: quoted-literal
     * interiors (multi-line included), heredoc/nowdoc bodies however
     * NESTED through {$...} interpolation, __halt_compiler() tails, and
     * ?>-bounded inline HTML. The glm15-1 heredoc census this replaces
     * failed open four ways (driven): a NESTED heredoc clobbered its
     * single-boolean state machine (only the inner body marked — live
     * keys on outer body lines answered zero findings through the CLI
     * gate); every other token-visible data region laundered; the EOF
     * branch was off by one (an unterminated heredoc without a trailing
     * newline marked ZERO body lines — byte-identical contents ± one
     * newline flipped the verdict); and a lexer-refused opener
     * ('<?phpecho') read as pure prose. The masker is offset-based and
     * nesting-aware by construction — none of those classes reach it —
     * and the fail-closed direction survives: an unterminated heredoc
     * blanks through EOF whatever the trailing byte.
     *
     * glm17-1: the mask is LINE-PRESERVING (interior newlines stay
     * newlines through the blanking), so the explode below answers one
     * view line per source line and $views[$index] is the SAME line's
     * code view — the mask once swallowed interior newlines into
     * spaces, leaving fewer view lines than $lines, so every line past
     * the first multi-line region shifted UP into an earlier line's
     * view and a code marker lines BELOW a live key exempted it
     * (driven: a multi-line string plus a marker three lines down
     * scanned to zero findings).
     *
     * A payload carrying no '<?' anywhere can lex no PHP tokens at all
     * (every open-tag spelling starts with those two bytes), so it
     * keeps the line-local tolerance arm instead — the pinned glm15-1
     * doctrine that non-PHP payloads (.txt/.md fixtures) answer no
     * tokens and no behavior change. glm17-3 widens that arm to the
     * text-family shapes with no matched '<?php'...'?>' sample — the
     * pre-gate above owns the routing, glm18-1 splits the
     * unclosed-sample class out of it, and glm18-11 completes the
     * split for the matched class: the SAMPLE REGIONS' lines ride the
     * masked view, the bytes outside them keep the line-local arm (a
     * marker in REAL code beside a sample stays honored in both —
     * comments are not string data, the masker never blanks them).
     */
    $views = null;
    $masked_view = null;
    $regions = null;
    if ($has_php) {
        // glm19-1: the region walk tokenizes, so it rides AFTER the
        // census refusal above — never a fatal where the bound answers
        // the loud refusal.
        if (! $php_family) {
            $regions = wp_connectors_php_sample_regions($contents);
        }
        $masked_view = wp_connectors_mask_string_contents($contents);
        if (null === $regions) {
            $views = explode("\n", $masked_view);
        }
    }
    $lines = explode("\n", $contents);
    $line_start = 0;
    // glm19-11: the by-ref region cursor — each line's compositor walk
    // starts where the last line stopped (O(lines + regions) over the
    // whole payload, never the per-line re-walk from index 0).
    $region_cursor = 0;
    foreach ($lines as $index => $line) {
        /*
         * Markers count only in REAL comments: the judge reads the
         * masked view of the code bytes, so a marker that is itself
         * string data — quoted contents, a heredoc body, an HTML
         * region — cannot exempt the live secret sitting next to it;
         * the marker must sit in CODE.
         *
         * glm19-2: the exemption is PER-ARM — the composed view fed
         * the WHOLE line to the marker judge, so a prose marker
         * OUTSIDE a region exempted a key INSIDE it on a mixed line
         * (driven): the line-skip crossed the region boundary. A
         * match inside a region's bytes is exempt only by a marker in
         * the REGION's code view; a match in the prose bytes keeps
         * the line-local arm's own marker — never crossed in either
         * direction.
         */
        if (null !== $views) {
            $prose_view = '';
            $code_view = $views[ $index ] ?? '';
            $spans = '' === $line ? array() : array( array( 0, strlen($line) - 1 ) );
        } elseif (null !== $regions) {
            $arm = wp_connectors_sample_region_line_view($line, $line_start, $regions, $masked_view, $region_cursor);
            $prose_view = $arm['prose'];
            $code_view = $arm['code'];
            $spans = $arm['spans'];
        } else {
            $prose_view = wp_connectors_line_without_string_literals($line);
            $code_view = '';
            $spans = array();
        }
        // glm18-11: the region view walks byte offsets — advance past
        // the line (+ its newline) before any `continue` below.
        $line_start += strlen($line) + 1;
        $prose_marker = 1 === preg_match($allowMarker, $prose_view);
        $code_marker = 1 === preg_match($allowMarker, $code_view);
        foreach (wp_connectors_secret_patterns() as $name => $pattern) {
            if (preg_match_all($pattern[0], $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($matches[0] as $match) {
                if (wp_connectors_is_recognizably_fake_secret($match[0])) {
                    continue;
                }
                $in_code = false;
                foreach ($spans as $span) {
                    if ($match[1] >= $span[0] && $match[1] <= $span[1]) {
                        $in_code = true;
                        break;
                    }
                }
                if ($in_code ? $code_marker : $prose_marker) {
                    continue;
                }
                // Never include the matched text in the finding.
                $findings[] = sprintf('%s:%d %s (%s)', $label, $index + 1, $name, $pattern[1]);

                break;
            }
        }
    }

    return $findings;
}

/**
 * Recursively scans files under given roots.
 *
 * The segment prune is a DEV-TREE concept (review round t31-r12-3,
 * closing the round-6 ledger line's named fix shape): the repository
 * scan skips segments no source ever lives in — but a scan of an
 * EXTRACTED ARTIFACT must never prune inside it. The builder never
 * ships dev segments (the collector drops them by the one
 * development-entry vocabulary), so a 'vendor'-shaped segment inside a
 * shipped tree is not a place to skip reading, it IS the signal: the
 * r6 HIGH had a live key at <slug>/src/Shared/vendor/keys.txt inspect
 * ACCEPTED while the identical key at src/Shared/keys.txt rejected
 * (reproduced) — the src/Shared dev-entry exemption exempts
 * CLASSIFICATION, never the content judgment. Artifact scans pass
 * false; the repository scan keeps the prune. (The prune list itself
 * stays the repo walk's own traversal concept — a SUBSET of the one
 * development-entry vocabulary, not a second vocabulary: pruning more
 * of it would blind the repo scan to root config files it covers by
 * contract. The SUBSET is judged by the vocabulary's own fold —
 * wp_connectors_segment_is_named(), the mechanic
 * wp_connectors_is_development_entry() rides (t31-ocr1-5) — so a
 * case-variant 'VENDOR/' or 'Tools/' prunes exactly where the folded
 * gates judge it a development entry, and 'Tests/' stays scanned:
 * a vocabulary member the subset does not name is this scan's charge,
 * not its skip. Never a byte-exact array_intersect twin here. The
 * judgment rides segments BELOW the root only (t31-ocr1-12): the
 * scan root's ANCESTORS are not this walk's dev tree, and judging
 * them let a dev-named checkout ancestor blind the whole scan.)
 *
 * @param list<string> $roots               Absolute paths (files or directories).
 * @param bool         $prune_dev_segments  Whether to skip development-tree segments (the repository scan's concept; artifact scans never prune).
 * @return list<string> Findings — credential matches, plus one
 *                      "unreadable file — the secret scan cannot run"
 *                      line per file whose read failed (glm14-2) and
 *                      one "over the 2 MB secret-scan size limit" line
 *                      per over-limit file the walk skipped (glm14-3):
 *                      the scan verdict is never clean over bytes it
 *                      did not read; both consumers — the CLI's exit
 *                      code and the inspector's violations — derive
 *                      their refusal from this list.
 */
function wp_connectors_scan_paths(array $roots, bool $prune_dev_segments = true)
{
    $findings = array();
    $excluded = $prune_dev_segments ? array( '.git', 'vendor', 'node_modules', 'dist', 'tools', '.phpunit.cache' ) : array();
    foreach ($roots as $root) {
        if (is_file($root)) {
            /*
             * glm14-2: the read owns its failure — the old (string)
             * cast laundered a false read (permission denial, a file
             * vanished mid-walk) into an empty string, so a chmod-000
             * file carrying a live token scanned to "0 finding(s)"
             * exit 0 while every sibling gate treats the same shape
             * as a loud FAIL (check-conventions' unreadable-file
             * violation; php -l's exit 1). The failure IS a finding:
             * the CLI's exit code and the inspector's verdict are
             * both derived from this list, so both consumers refuse
             * with zero changes. The @ suppresses only the engine's
             * E_WARNING — the loud refusal is the finding line, the
             * ocr30-4 doctrine.
             */
            $contents = @file_get_contents($root);
            if (false === $contents) {
                $findings[] = sprintf('%s: unreadable file — the secret scan cannot run', $root);
                continue;
            }
            // glm18-2: the operator named this one file — content shape
            // outranks the extension (a php-headed 'config.inc' rides
            // the CODE arm).
            $findings = array_merge($findings, wp_connectors_scan_string($contents, $root, true));
            continue;
        }
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        // The prune judges segments BELOW the root only (verifier round
        // t31-ocr1-12) — the lint walk's shape: the full pathname's
        // ANCESTORS are not this walk's dev tree, and judging them let a
        // 'dist'-shaped checkout ancestor blind the whole scan silently
        // (reproduced: 0 findings under a Dist/ ancestor, 1 under Dst/
        // — the exact-case shape was pre-round, the fold widened it to
        // every casing).
        /*
         * The prefix arithmetic strips BOTH separator spellings (OCR
         * round 31, t31-ocr31-5, the t31-ocr29-3 vocabulary class):
         * rtrim($root, DIRECTORY_SEPARATOR) consulted only the native
         * one, so a '/'-suffixed root on a separator host kept its
         * trailing separator in the length while the iterator below
         * treats '/' as a separator there too — $below_root one byte
         * over, and every walked relative lost its first byte. The
         * strip judges the spelling CLASS, never the host it runs on:
         * on POSIX the '\' arm costs residue only for a path
         * literally named with a trailing backslash byte (the
         * ocr29-3 trade, residue over victim), and on this POSIX host
         * the arithmetic is byte-identical to the former rtrim.
         */
        $below_root = strlen(rtrim($root, '/\\')) + 1;
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            // The segment walk exists ONLY to prune, and pruning is
            // constant for the whole walk: with $prune_dev_segments
            // false (the artifact scan) the inner guard was provably
            // never true, yet the explode+segment loop still ran per
            // file — skipped entirely now (t31-ocr9-6; behavior
            // identical: nothing prunes either way).
            if ($prune_dev_segments) {
                $parts = explode(DIRECTORY_SEPARATOR, (string) substr($file->getPathname(), $below_root));
                foreach ($parts as $part) {
                    if (wp_connectors_segment_is_named($part, $excluded)) {
                        continue 2;
                    }
                }
            }
            if (! $file->isFile()) {
                continue;
            }
            if ($file->getSize() > 2 * 1024 * 1024) {
                /*
                 * glm14-3: the 2 MB cap is a deliberate memory bound —
                 * the scan is line-based over the whole file's
                 * contents, so the bound exists to keep a hostile
                 * multi-gigabyte entry from exhausting the process
                 * before a verdict lands (the file-root arm above has
                 * no cap because a caller naming one file owns that
                 * choice; the WALK judges trees it did not choose).
                 * The skip is LOUD, never silent: an over-limit file
                 * answers a finding line in the glm14-2 vocabulary, so
                 * the verdict never reads clean over bytes the scan
                 * did not read — a zip shipping a >2 MB entry now
                 * fails the inspector's credential screen instead of
                 * passing ACCEPTED (driven red at HEAD: exactly 1
                 * finding, the oversized twin invisible).
                 */
                $findings[] = sprintf('%s: over the 2 MB secret-scan size limit — the secret scan cannot run', $file->getPathname());
                continue;
            }
            /*
             * glm14-4: 'phtml' joins the allowlist the same round the
             * ONE is-a-source owner (wp_connectors_is_php_source())
             * gained the template class — the r6 ledger line's reopen
             * condition ("a real producer") was met by a driven
             * 'form.phtml' entry carrying a live token past this
             * screen. '.php5'/'.php7'/'.inc' stay out until a driven
             * producer ships one (the r6 bar).
             */
            $extension = strtolower($file->getExtension());
            if ($extension !== '' && ! in_array($extension, array( 'php', 'phtml', 'js', 'json', 'txt', 'md', 'xml', 'yml', 'yaml', 'neon', 'env', 'ini', 'dist', 'po', 'svg', 'sh', 'go', 'conf', 'config', 'properties', 'pem', 'key', 'toml' ), true)) {
                continue;
            }
            /*
             * glm14-2 (the walk arm of the file-root arm above): a
             * false read is a finding, never a laundered empty scan.
             */
            $contents = @file_get_contents($file->getPathname());
            if (false === $contents) {
                $findings[] = sprintf('%s: unreadable file — the secret scan cannot run', $file->getPathname());
                continue;
            }
            $findings = array_merge($findings, wp_connectors_scan_string($contents, $file->getPathname()));
        }
    }

    return $findings;
}
