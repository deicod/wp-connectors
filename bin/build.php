<?php
/**
 * Standalone artifact builder.
 *
 * Assembles one self-contained zip per plugin into dist/ (gitignored):
 *
 *   php bin/build.php                          # all plugins under connectors/
 *   php bin/build.php --slug=zai               # one plugin
 *   php bin/build.php --fixture=example-connector   # a tests/fixtures/plugins plugin
 *
 * Deterministic by construction: development files are excluded, remaining
 * files are staged with a fixed mtime and permissions and zipped in sorted
 * order, the repository LICENSE is embedded, shared OAuth source is copied
 * under the plugin's own namespace when the plugin opts in via build.json,
 * and every zip gets a SHA-256 checksum (dist/checksums.txt is regenerated:
 * entries whose artifact no longer sits beside it are dropped).
 * A plugin is REFUSED when the shared convention checks fail — headers,
 * exactly one main plugin file, version constant matching the header
 * Version, self-containment, autoloader shape — so a mislabeled zip (e.g. a
 * bumped header with a stale {SLUG}_VERSION constant) is never packaged.
 * In the no-argument mode every subdirectory of connectors/ is built, so a
 * malformed connector directory fails the run instead of silently missing
 * from the release.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/plugin-tools.php';

/**
 * Build tool implementation (also unit-tested directly).
 */
final class WpConnectorsBuild
{
    /** Fixed zip timestamp epoch (2000-01-01 UTC). */
    const FIXED_MTIME = 946684800;

    /**
     * Rewrites shared-source namespace into a plugin-private namespace.
     *
     * The provenance docblock is inserted AFTER the open tag so the generated
     * file stays valid PHP even when the source starts with
     * `<?php declare(strict_types=1);`.
     *
     * Verifier round t31-r2-19: both interpolated values land in
     * preg_replace REPLACEMENT strings, where '$1'/'${1}'/'\1' are
     * backreference material — a namespace_suffix of 'Evil$1' rewrote
     * the namespace to 'Deicod\WpConnectors\Evilnamespace \Shared\Clock;'
     * and the broken file shipped into the zip with no lint gate to
     * catch it (pre-existing, byte-unchanged by the round that found
     * it; inputs are repo/plugin-author controlled). The suffix is
     * validated as a legal namespace segment (which is also
     * replacement-safe by construction) and the provenance string's
     * replacement metacharacters are escaped.
     *
     * Review round t31-r3-2: the use-rewrite covers EVERY legal spelling
     * of the shared namespace — plain, aliased, 'use function'/'use
     * const', fully qualified, exact (no sub-segment), and the
     * brace-group form — and a postcondition REFUSES the rewrite when
     * any spelling survives the patterns (a spelling the rewriter does
     * not know ships broken imports otherwise; silent survival was the
     * round's defect).
     *
     * Review round t31-r4 (K1): the postcondition is a TOTAL scan now
     * (then SHARED_NAMESPACE_SURVIVOR_PATTERN), not a spelling list — zero
     * occurrences of the source namespace in the output, case-insensitive,
     * whitespace-tolerant, brace-aware — and the rewrite extends to
     * group-use MEMBER spellings (t31-r4-4). The patterns do the work;
     * the total scan guarantees that what they miss refuses the build
     * instead of shipping.
     *
     * Review round t31-r7 (the terminal fix): the postcondition is a
     * TOKEN walk (wp_connectors_shared_family_references(), shared with
     * the architecture sweep's namespace gate), not a regex over bytes —
     * three rounds had each closed the seam one spelling away. Names are
     * reassembled across comments and whitespace (a comment can
     * INTERRUPT a name run but never contribute bytes to it, so the
     * comment-interrupted spelling dies by construction); string
     * literals are judged by their UNESCAPED runtime value (the
     * double-backslash class-string spelling); and a SIBLING under the
     * vendor prefix (Deicod\WpConnectors\<Other>…, anything not Shared
     * and not the rewritten target) refuses exactly like the source
     * namespace itself — the rewriter owns no sibling spelling, so one
     * ships pointing at a namespace that does not exist inside the
     * plugin. The patterns above remain the MECHANISM for every legal
     * spelling this rewriter owns; the token walk is the AUTHORITY that
     * guarantees what they miss refuses the build instead of shipping.
     *
     * Verifier round t31-r11-1: the rewriter owns the
     * `namespace\`-RELATIVE use spelling too (rewriteRelativeUseImports()
     * — resolved against the source declaration, then family-rewritten
     * into the fully-qualified import). The r8-2 "relatives adapt by
     * construction" premise is false in the use position: a relative
     * use statement is a parse error the engine never accepts, and the
     * spelling once rode every pattern and the postcondition's
     * rewrite-ownership carve-out into the zip at exit 0. The detector's
     * carve-out is gone in the use position, so a survivor now refuses
     * the build here as well.
     *
     * @param string $source        PHP source from shared/src.
     * @param string $pluginSuffix  Namespace segment, e.g. 'OpenAiOauth'.
     * @param string $sourceVersion Provenance string (repo-relative path/rev).
     * @return string Rewritten source ready for src/Shared/.
     * @throws RuntimeException When the namespace suffix is not a legal namespace segment, or when any spelling of the shared namespace survives the rewrite.
     */
    public static function rewriteSharedNamespace($source, $pluginSuffix, $sourceVersion)
    {
        self::assertNamespaceSegment($pluginSuffix);
        /*
         * The family vocabulary is DERIVED from the ONE owner (review
         * round t31-r9-9): wp_connectors_shared_source_namespace()
         * (bin/lib/plugin-tools.php) names the source tree, and every
         * spelling below — the namespace-declaration pattern, the
         * use-statement pattern, the group-use prefix pattern, the
         * member-leaf pattern, the replacement sides, and the target
         * the postcondition judges — derives from its segments through
         * preg_quote. The four pattern literals were independent
         * hand-spellings before, so a family rename needed synchronized
         * two-file edits the helper's single-ownership docblock promised
         * could never drift. Byte parity with the former literals holds
         * by construction: each segment preg_quoted, joined with the
         * regex spelling of one separator ('\\\\' — two bytes in a PHP
         * string); the rewrite battery pins the behavior end to end.
         */
        $family_segments = explode('\\', wp_connectors_shared_source_namespace());
        $quoted_segments = array_map(
            static function ( $segment ) {
                return preg_quote( (string) $segment, '/' );
            },
            $family_segments
        );
        $shared_pattern = implode('\\\\', $quoted_segments);
        $vendor_pattern = implode('\\\\', array_slice($quoted_segments, 0, -1));
        $shared_leaf = (string) end($quoted_segments);
        $vendor = implode('\\', array_slice($family_segments, 0, -1));
        $family_leaf = (string) end($family_segments);
        // The rewritten target's regex/replacement spelling (the suffix
        // is a validated namespace segment; preg_quote is the belt to
        // the assertNamespaceSegment braces).
        $target_escaped = $vendor_pattern . '\\\\' . preg_quote((string) $pluginSuffix, '/') . '\\\\' . $shared_leaf;

        $escapedVersion = str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $sourceVersion);
        $provenance = "/**\n * Generated copy of {$escapedVersion} for this plugin's private namespace.\n * Do not edit here; change the shared source and rebuild.\n */\n";
        /*
         * The RELATIVE use step runs FIRST, against the source's own
         * declaration (verifier round t31-r11-1): a `namespace\…`
         * spelling resolves against whatever the file declares, and the
         * bytes still declare the SOURCE tree here — after the
         * declaration rewrite below, resolution would silently judge the
         * rewritten tree (the adaptation fallacy). A relative USE
         * statement is a parse error PHP never accepts (verified on
         * 8.5.10), so nothing adapts it: the rewriter owns the spelling,
         * resolves it exactly as PHP would, applies the family rewrite
         * to the resolved name, and emits the fully-qualified rewritten
         * import in its place. Relatives that cannot resolve within the
         * family — no declaration to resolve against, an escaping
         * resolution, or a sibling under the vendor prefix — refuse
         * loudly here rather than riding verbatim into the plugin.
         */
        $rewritten = self::rewriteRelativeUseImports($source, $pluginSuffix, $sourceVersion);
        $rewritten = self::replaceOrThrow(
            preg_replace(
                '/(namespace\s+)' . $shared_pattern . '((?:\\\\[A-Za-z0-9_]+)*\s*;)/',
                '$1' . $target_escaped . '$2',
                $rewritten
            ),
            'namespace declaration rewrite',
            $sourceVersion
        );
        /*
         * use statements referencing the shared namespace, in EVERY legal
         * spelling (review round t31-r3-2): the old pattern required a
         * trailing separator after Shared, so the EXACT-namespace import
         * ('use Deicod\WpConnectors\Shared;'), its aliased form
         * ('... as SharedNs;'), and every 'use function/const' spelling
         * survived byte-identical — the embedded copy imported the source
         * namespace, which no longer exists inside the plugin: a
         * class-not-found fatal on load. One pattern carries the optional
         * function/const kind, the optional leading backslash (a fully
         * qualified import), the exact-or-sub-segmented name, the optional
         * alias, and the brace-group tail (group members are relative —
         * rewriting the prefix before '{' rewrites every member).
         */
        /*
         * The ALIAS half of this seam's grammar (OCR round 32,
         * t31-ocr32-1): the optional alias group once captured ANY
         * identifier spelling and re-emitted it verbatim — including
         * the fourteen engine-illegal ones (`as self`, `as true`,
         * `as int`, … case-insensitively; php -l-derived), the exact
         * class the relative-path alias state already refuses one
         * method below — so `use …\Shared\Clock as self;` rewrote the
         * family and re-emitted ` as self` beside it: compile-error
         * bytes in the zip at exit 0 with every gate green (driven at
         * HEAD; the postcondition judges family references, and the
         * alias rides a target-prefixed import, so it waved through).
         * The round's census rule: the alias grammar rejects what the
         * engine rejects, at EVERY seam that re-emits an alias — this
         * pattern, the group-use member callback (t31-ocr32-2/3), and
         * the relative tail walk all consult the ONE reserved-vocab
         * owner below.
         */
        /*
         * The KEYWORD axes ride the engine's case-insensitivity (OCR
         * round 35, t31-ocr35-1): PHP accepts `USE`, `Use`, `as`/`AS`,
         * `function`/`FUNCTION`, and `const`/`Const` alike, and every
         * keyword here once matched its lowercase spelling byte-exact
         * — so `use …\Shared\Clock AS Alias;` and `use FUNCTION …`
         * skipped the rewrite and refused at the postcondition, the
         * r7-9 census having covered only the `use` keyword and the
         * family-name axes (two of four). The four keyword axes match
         * through SCOPED (?i:…) groups at every pattern seam that
         * spells them — this pattern, the group-use prefix pattern,
         * and the member-kind extraction below — while the FAMILY
         * name keeps its byte-exact case-sensitive matching on
         * purpose: its case-variant spelling stays the refuse-NAMED
         * doctrine one branch below (the r24-4 boundary-aware label),
         * never an owned rewrite. The keyword's own casing rides the
         * output verbatim ($matches[1] and the captured groups re-emit
         * the caller's bytes; only the family segments rewrite).
         */
        $rewritten = self::replaceOrThrow(
            preg_replace_callback(
                '/(?<![A-Za-z0-9_])((?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?\\\\?)' . $shared_pattern . '((?:\\\\[A-Za-z0-9_]+)*)(\s+(?i:as)\s+[A-Za-z0-9_]+)?((?:\\\\)?\s*\{[^;}]*\})?\s*;/',
                static function ($matches) use ($sourceVersion, $vendor, $pluginSuffix, $family_leaf) {
                    // The optional groups are ABSENT keys (never
                    // null/'' — no PREG_UNMATCHED_AS_NULL here), the
                    // replacement-template ${n} empty-string semantics
                    // spelled by hand.
                    $alias_group = $matches[3] ?? '';
                    /*
                     * The RIDER composition (OCR round 32, the
                     * t31-ocr32-3 half over this seam): the optional
                     * alias group and the optional brace-group tail
                     * are each legal ALONE, but together — `use …\
                     * \Clock as X {Y};` — they compose into a spelling
                     * the engine rejects (an aliased import opens no
                     * group; php -l refuses), and the pattern once
                     * matched both and re-emitted both verbatim beside
                     * the rewritten name at exit 0 (driven at HEAD),
                     * the postcondition waving the target-prefixed
                     * import through. The grammar refuses the
                     * composition at the seam, never the verbatim
                     * re-emit.
                     */
                    if ('' !== $alias_group && '' !== (string) ($matches[4] ?? '')) {
                        throw new RuntimeException("build: the use statement importing the shared namespace in {$sourceVersion} carries both an alias and a brace-group tail — an aliased import opens no group, the composition is a parse error the engine rejects at compile time, and the rewrite once re-emitted both verbatim beside the rewritten name at exit 0; write the aliased import or the group, never both");
                    }
                    if ('' !== $alias_group && 1 === preg_match('/[A-Za-z0-9_]+\z/', $alias_group, $alias_id)) {
                        if (self::aliasIdentifierIsEngineIllegal($alias_id[0])) {
                            throw new RuntimeException("build: the alias of a use statement importing the shared namespace in {$sourceVersion} must be one plain identifier — '{$alias_id[0]}' is a reserved spelling the engine forbids in the slot, case-insensitively (every keyword the lexer does not spell a name), and the rewrite re-emits the alias verbatim, so the zip would ship the compile-error bytes at exit 0; write a plain identifier the engine accepts");
                        }
                    }

                    /*
                     * The FAMILY-PREFIX brace tail rides the SAME
                     * member grammar (OCR round 40, t31-ocr40-1 — the
                     * sixth use-grammar generation): a group whose
                     * PREFIX is the family itself (`use …\Shared\{Clock
                     * as self};`, or deeper through the sub-segment
                     * tail `…\Shared\Storage\{…}`) matches THIS
                     * pattern — the vendor-prefix group pattern below
                     * owns only the spelling whose brace sits
                     * immediately after the vendor prefix — and the
                     * tail once re-emitted VERBATIM beside the
                     * rewritten prefix: the members are relative to
                     * the prefix, so riding them verbatim is the
                     * correct REWRITE, but riding them unvalidated
                     * shipped the engine-illegal member spellings
                     * (`as self`, a fully-qualified member, an empty
                     * member) the member grammar exists to refuse —
                     * compile-error bytes in the zip at exit 0 with
                     * every gate green (the postcondition judges
                     * family references, and a member riding a
                     * target-prefixed prefix waves through). The
                     * census: every seam that re-emits a group BODY
                     * validates it through the ONE member-grammar
                     * owner below — the vendor-prefix group callback
                     * (which also rewrites the members' leading
                     * Shared segment) and this return (which never
                     * re-spells a member, its prefix already carrying
                     * the rewrite).
                     */
                    $brace_tail = (string) ($matches[4] ?? '');
                    if ('' !== $brace_tail) {
                        $body = (string) substr($brace_tail, (int) strpos($brace_tail, '{') + 1, -1);
                        $member_pieces = explode(',', $body);
                        foreach ($member_pieces as $member_index => $member_piece) {
                            self::groupUseMemberGrammar(trim($member_piece), $body, $member_index, count($member_pieces), $sourceVersion);
                        }
                    }

                    // The plain target spelling (the callback returns
                    // raw bytes, never a replacement template — the
                    // former $target_escaped side decoded to exactly
                    // this through the replacement parser).
                    return $matches[1] . $vendor . '\\' . $pluginSuffix . '\\' . $family_leaf . ($matches[2] ?? '') . $alias_group . $brace_tail . ';';
                },
                $rewritten
            ),
            'use-statement rewrite',
            $sourceVersion
        );
        /*
         * Group-use MEMBER spellings (round t31-r4, K1's t31-r4-4 half):
         * the prefix before '{' is Deicod\WpConnectors itself and the
         * members carry the Shared segment — `use Deicod\WpConnectors\{
         * Shared\Clock};` matched no pattern above (the namespace
         * substring never appears contiguously), so it survived the
         * rewrite, the postcondition, and the sweep's whitelist
         * byte-identical (reproduced). Members are relative to the
         * prefix, so the rewrite inserts the suffix at the member's
         * leading Shared segment; only the member-LEADING segment counts
         * (`X\Shared` is a different namespace), and an 'as' alias is
         * never REWRITTEN — a member aliased exactly as 'Shared'
         * (`Clock as Shared`, importing the DIFFERENT namespace
         * Deicod\WpConnectors\Clock) still REFUSES the build: the total
         * scan below cannot distinguish the alias's member-boundary
         * position from the namespace segment, so the doctrine is
         * fail-loud — rename the alias (verifier note t31-r4, pinned as
         * a refusal). Flat bodies only: a NESTED brace group is exotic
         * enough that the postcondition refuses it loudly rather than
         * this rewriter guessing member structure.
         */
        /*
         * The group-use PREFIX pattern's keyword axes ride the same
         * scoped case-insensitive spellings (t31-ocr35-1's sweep): the
         * plain-statement seam above owns `USE`/`FUNCTION`/`Const`
         * spellings while this one matched lowercase only, so a
         * case-variant group prefix refused at the postcondition one
         * seam over — the keyword census is one vocabulary at every
         * pattern that spells the grammar.
         */
        $rewritten = self::replaceOrThrow(
            preg_replace_callback(
                '/((?<![A-Za-z0-9_])(?i:use)\s+(?:(?i:function)\s+|(?i:const)\s+)?\\\\?' . $vendor_pattern . '\\\\)\s*(\{)([^{}]*)(\})\s*;/',
                static function ($matches) use ($pluginSuffix, $sourceVersion, $shared_leaf) {
                    $members = array();
                    $member_pieces = explode(',', $matches[3]);
                    foreach ($member_pieces as $member_index => $member_piece) {
                        /*
                         * The member grammar is validated BEFORE
                         * reassembly (OCR round 31, t31-ocr31-4; the
                         * ONE owner since t31-ocr40-1, shared with the
                         * family-prefix brace tail one pattern above):
                         * the callback once reassembled member bytes
                         * through explode/trim/implode with no
                         * refusal of its own, so every illegal member
                         * spelling normalized into a silent pass. The
                         * rewriter owns what it reassembles: each
                         * illegal shape refuses at the grammar's own
                         * seam, naming the spelling the engine
                         * rejects. THIS seam additionally rewrites the
                         * member's leading Shared segment (the members
                         * of a vendor-prefix group carry it — the
                         * family-prefix tail above never does, its
                         * prefix already ending in the family).
                         */
                        $grammar = self::groupUseMemberGrammar(trim($member_piece), $matches[3], $member_index, count($member_pieces), $sourceVersion);
                        $members[] = $grammar[0] . self::replaceOrThrow(
                            preg_replace(
                                '/^' . $shared_leaf . '(?![A-Za-z0-9_])/',
                                $pluginSuffix . '\\\\' . $shared_leaf,
                                $grammar[1]
                            ),
                            'group-use member rewrite',
                            $sourceVersion
                        ) . $grammar[2];
                    }

                    return $matches[1] . $matches[2] . implode(', ', $members) . $matches[4] . ';';
                },
                $rewritten
            ),
            'group-use member rewrite',
            $sourceVersion
        );

        /*
         * Insert provenance directly after the open tag (never before
         * it). Review round t31-r9-6: the insertion pattern was case-
         * and BOM-sensitive with no zero-match guard — '<?PHP' (a legal
         * PHP open tag; the engine matches the tag case-insensitively)
         * and a BOM-prefixed source matched nothing and shipped
         * BANNER-LESS silently (reproduced), and the "Do not edit here"
         * marker is load-bearing provenance: every generated file
         * carries it or the build says why it cannot.
         *
         * The doctrine is BANNER-IN-PLACE, not loud refusal (chosen and
         * documented): the opener's spelling and a leading BOM are the
         * source's own bytes — not the rewriter's to rewrite — and
         * every legal PHP opener can carry the marker, so the pattern
         * matches an optional BOM then the open tag case-insensitively
         * and PRESERVES the matched opener bytes verbatim (captures +
         * backreferences; only the whitespace run after the tag
         * normalizes to the banner's own \n\n, as it always did). A
         * ZERO-MATCH — a source with no open tag at the head at all —
         * refuses loudly at this seam (the postcondition's token walk
         * would only catch such a file incidentally, when its bytes
         * happen to spell the family; the banner gate owes its own
         * refusal).
         */
        $final = self::replaceOrThrow(
            preg_replace(
                '/^(\xEF\xBB\xBF)?(<\?php\b)\s*/i',
                '${1}${2}' . "\n\n" . $provenance . "\n",
                $rewritten,
                1
            ),
            'provenance insertion',
            $sourceVersion
        );
        if ($final === $rewritten) {
            throw new RuntimeException("build: cannot insert the provenance banner into {$sourceVersion} — the file carries no PHP open tag at its head, and the \"Do not edit here\" marker is load-bearing provenance that never ships silently absent");
        }

        /*
         * Postcondition (t31-r4 K1's regex scan, superseded by round
         * t31-r7's TOKEN detector — the terminal fix after the same seam
         * stayed "one spelling away" through three regex rounds): the
         * rewrite's contract is ONE total property — the OUTPUT
         * references the shared-namespace family ONLY under the
         * rewritten target prefix. The detector
         * (wp_connectors_shared_family_references(), the ONE
         * implementation this postcondition and the architecture
         * sweep's namespace gate both ride — one vocabulary, two
         * consumers) walks the token stream: name runs are reassembled
         * across comments and whitespace, so a comment can only
         * INTERRUPT a name, never hide one (the comment-interrupted use
         * spelling dies by construction, t31-r7-1); string literals are
         * judged by their UNESCAPED runtime VALUE (the double-backslash
         * class-string spelling, t31-r7-4); and every family reference
         * that is not the rewritten target — the source namespace
         * itself, or a SIBLING under Deicod\WpConnectors\<Other>…
         * (t31-r7-2; the rewriter owns no sibling spelling, so one
         * ships pointing at a namespace that does not exist inside the
         * plugin) — REFUSES the build loudly with the file, the byte
         * offset, the resolved name, and the position kind. The target
         * prefix is legal ONLY in the two positions the rewrite itself
         * produces — declarations and use statements (verifier round
         * t31-r7-8: the r7-K postcondition waved target-rooted CODE and
         * STRING references through, so a hand-authored source spelling
         * the building plugin's own target prefix — a dangling class
         * reference — shipped at exit 0 while the sweep refused the
         * same file, verdict drift; measured over the real tree ×
         * every suffix shape, rewritten outputs carry ONLY declaration
         * and use kinds, so the code/string allow kinds had no
         * legitimate producer). The detector also judges the TARGET
         * spelling at its text lens (the prefix is passed in), so a
         * docblock naming the target refuses too. Comments and inline
         * HTML refuse in every spelling: the provenance docblock
         * inserted above is family-free, so nothing legitimate is
         * lost. A PCRE abort in the detector's text lens refuses too
         * (glm36-8: an abort is never a clean pass). The scan runs
         * over the FINAL bytes — provenance included — so nothing that
         * ships escapes it.
         */
        // The rewritten target, derived (t31-r9-9) — never a fifth
        // hand-spelling of the family. The fold is the ASCII owner
        // (t31-ocr10-4): it compares against the detector's 'lower'
        // twins, and both sides must fold through ONE table for the
        // verdict to survive a locale (the r11-6 doctrine).
        $target = $vendor . '\\' . $pluginSuffix . '\\' . $family_leaf;
        $target_lower = wp_connectors_ascii_lower($target);
        foreach (wp_connectors_shared_family_references($final, $target) as $reference) {
            if ('pcre-abort' === $reference['kind']) {
                throw new RuntimeException("build: the namespace-reference scan aborted (PCRE) while rewriting {$sourceVersion} — an abort refuses the rewrite, never passes it");
            }
            $is_target = $reference['lower'] === $target_lower || 0 === strpos($reference['lower'], $target_lower . '\\');
            if ($is_target && ('declaration' === $reference['kind'] || 'use' === $reference['kind'])) {
                continue;
            }
            /*
             * The NAMED-refusal seam for legal import spellings the
             * rewriter does not OWN (OCR round 7, t31-ocr7-2): comma
             * lists, close-tag termination, comments inside the
             * statement — legal PHP (php -l-verified) the use-statement
             * pattern's byte grammar cannot see, which once surfaced as
             * the ANONYMOUS postcondition refusal below ("a spelling
             * survived", class unnamed). The seam doctrine is own-or-
             * refuse-named; the classifier runs ONLY on this throw
             * path, so green builds pay nothing, and its sentence
             * rides the anonymous text (the postcondition stays the
             * total authority — the class names what it caught).
             */
            $spelling_class = 'use' === $reference['kind']
                ? self::unownedUseImportSpellingClass($final, $reference['offset'], $reference['name'])
                : null;
            throw new RuntimeException(sprintf(
                'build: the reference %s (%s position) survived the rewrite in %s at byte offset %d — after the rewrite the embedded copy may reference only the plugin-private target %s…, so every other reference to the shared-namespace family points at a namespace that does not exist inside the plugin; every legal use form is rewritten here or the build refuses, never an import that ships broken%s',
                $reference['name'],
                $reference['kind'],
                $sourceVersion,
                $reference['offset'],
                // The target's OWN derived spelling (t31-ocr11-11) —
                // display rides the same derivation the verdict judged.
                $target,
                null !== $spelling_class ? '; spelling class the rewrite does not own: ' . $spelling_class : ''
            ));
        }

        return $final;
    }

    /**
     * Rewrites `namespace\`-relative USE imports into the plugin-private
     * target — the t31-r11-1 seam.
     *
     * A relative USE statement (`use namespace\Foo\Bar;`) is a parse
     * error PHP never accepts on any runtime (verified on 8.5.10:
     * "syntax error, unexpected namespace-relative name"), and the
     * rewrite's other patterns do not own the `namespace\` spelling —
     * so it once rode verbatim through the rewrite, the postcondition
     * (whose r8-2 carve-out waived relatives under a rewrite-owned
     * declaration), and the sweep, and the zip shipped the parse-error
     * line at exit 0. The doctrine this method implements: THE REWRITER
     * OWNS THE SPELLING. It resolves the operator exactly as PHP does —
     * the file's declared namespace plus the relative tail — applies
     * the family rewrite to the RESOLVED name, and splices the
     * fully-qualified rewritten import over the relative spelling's
     * bytes (a fully-qualified import is legal in every STANDALONE use
     * form: plain, aliased, `use function`, `use const` — but never in
     * a group BODY, which is why the member shape refuses below).
     * Resolution happens against the SOURCE declaration, which is why
     * this step runs before the declaration rewrite.
     *
     * The operator is owned in BOTH of its lexings (verifier round
     * t31-r11-10): the fused T_NAME_RELATIVE token AND the INTERRUPTED
     * keyword — a space or comment between `namespace` and the name
     * makes the lexer emit a bare T_NAMESPACE plus pieces, an equally
     * illegal spelling that once rode this step untouched. Inside an
     * open use statement the bare keyword is owned the same way:
     * resolved across the trivia, rewritten, or refused. Outside a use
     * statement the keyword's ownership is REFUSAL (OCR round 5,
     * t31-ocr5-1): the interrupted spelling is a parse error in every
     * code position — it adapts nowhere — and the detector's walk drops
     * the bare keyword (the r8-10 rule), so this step is the only gate
     * between the bytes and a lintless zip.
     *
     * Relatives that cannot be carried through the family rewrite
     * refuse loudly, never ride: a file with no namespace declaration
     * (unresolvable), a resolution landing outside the family
     * (escaping — in the output it would silently re-resolve against
     * the REWRITTEN declaration, changing its meaning), a resolution
     * landing on a family SIBLING the rewrite owns no spelling of, the
     * group-use PREFIX shape (`use namespace\Foo\{Bar};` — a
     * parse-error spelling whose members the rewrite owns no map for),
     * any relative standing inside a group BODY
     * (`use Other\{namespace\Foo};` — the grammar forbids the
     * fully-qualified member the rewrite would emit, verifier round
     * t31-r11-9, so the rewrite owns no map for such a member either),
     * a relative in a TRAIT use position (OCR round 26, t31-ocr26-1 —
     * `class C { use namespace\X; }` is LEGAL PHP naming a trait under
     * the declaration in effect, and the splice once retargeted it
     * silently through the family map, changing which trait loads),
     * and — since OCR round 3, t31-ocr3-4 — any relative standing
     * MID-NAME or in the alias slot (`use Foo\ namespace \Bar;`,
     * `use Foo as namespace\Bar;`): the splice once started at the
     * keyword and left the preceding separator standing, so these
     * degenerate spellings shipped double-separated parse errors at
     * exit 0; the rewrite owns the operator only as the import's
     * LEADING name.
     * Within the real build none of these can occur: the shared-source
     * staging gate requires every shared file to declare a namespace
     * under the tree root, so every relative resolves inside the owned
     * tree. Code-position relatives are NOT this method's business —
     * they resolve against the file's own declaration, which the
     * rewrite rewrites, so they adapt by construction (the r8-2
     * doctrine, still true in the position where its premise holds).
     *
     * @param string $source        PHP source from shared/src (still declaring the source tree).
     * @param string $pluginSuffix  Namespace segment, e.g. 'OpenAiOauth' (already validated legal).
     * @param string $sourceVersion Provenance string (diagnostics).
     * @return string The source with every relative use import spliced to its rewritten fully-qualified form.
     * @throws RuntimeException When a relative cannot be resolved within the family, or rides a shape the rewrite owns no map for.
     */
    private static function rewriteRelativeUseImports($source, $pluginSuffix, $sourceVersion)
    {
        $family_segments = explode('\\', wp_connectors_shared_source_namespace());
        $vendor = implode('\\', array_slice($family_segments, 0, -1));
        $family_leaf = (string) end($family_segments);
        $root_lower = wp_connectors_ascii_lower(implode('\\', $family_segments));
        $vendor_lower = wp_connectors_ascii_lower($vendor);

        $tokens = token_get_all($source);
        $count = count($tokens);

        /*
         * The file's namespace declarations ride the ONE declaration
         * ledger (OCR round 7, t31-ocr7-1): a relative resolves against
         * the declaration IN EFFECT where it stands, not the file's
         * first (multi-block files), and a braced block EXPIRES at its
         * closing brace (t31-ocr4-5, this walk's own history — global
         * scope after the block; the close is matched through the ONE
         * brace-matching owner over the string-masked view with the
         * inline-HTML spans blanked in the ledger's own view,
         * t31-ocr4-9). The ledger lived here as a private of this walk
         * until the round found the DETECTOR's resolution walk running
         * a hand-rolled twin that never expired a block — the defect
         * class this fix swept to one owner
         * (wp_connectors_namespace_declaration_ledger(), beside the
         * declaration-shape predicate both rides).
         */
        $declaration_in_effect = wp_connectors_declaration_in_effect(
            wp_connectors_namespace_declaration_ledger($tokens, $source)
        );

        /*
         * Every T_NAME_RELATIVE run inside an open use statement, with
         * its byte extent — the run's tokens reassemble across trivia
         * (wp_connectors_name_run()), and the splice covers first-token
         * start through last-token end, so a separator-interrupted
         * spelling is replaced whole. Collected in one pass, spliced in
         * REVERSE byte order so earlier offsets stay true.
         */
        $splices = array();
        $use_open = false;
        $use_is_trait = false;
        $closure_use = false;
        $closure_use_depth = 0;
        $group_depth = 0;
        $context = array();
        $offset = 0;
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[ $i ];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            $token_offset = $offset;
            $offset += strlen($text);

            if (T_USE === $id) {
                // The closure-use fence rides its ONE owner — the
                // detector's own vocabulary
                // (wp_connectors_use_opens_import()).
                $use_open = wp_connectors_use_opens_import($tokens, $i);
                $group_depth = 0;
                /*
                 * The TRAIT twin of that fence (OCR round 26,
                 * t31-ocr26-1): the follower shape cannot draw the
                 * line — a NAME follower opens an import statement at
                 * the top level and a trait clause list inside a class
                 * body, the same bytes in both — so the fence derives
                 * from the brace-kind stack the classifier walk
                 * already rides (t31-ocr7-7): a use statement with an
                 * 'other' frame below it stands in a TRAIT position.
                 * The distinction is not cosmetic: the class-body
                 * relative spelling is LEGAL PHP (php -l clean; it
                 * resolves and LOADS under the declaration in
                 * effect — probed), so $use_open once armed the
                 * splice for it and the rewrite silently RETARGETED
                 * the trait reference through the family map
                 * (driven at HEAD: `class C { use namespace\Clock\
                 * \SystemClock; }` shipped `use \…\OpenAiOauth\…\Clock
                 * \SystemClock;`, a DIFFERENT trait), the exact
                 * territory breach the position fences below exist to
                 * close — the rewrite owns import statements only.
                 */
                $use_is_trait = $use_open && in_array('other', $context, true);
                /*
                 * A closure's `use (` opens the region this walk must
                 * refuse names inside (OCR round 23, t31-ocr23-7):
                 * the binding list carries VARIABLE names only, and a
                 * relative operator standing in it is a parse error
                 * in every reading (php -l: unexpected
                 * namespace-relative name, expecting variable or
                 * '&') — but the two lexer spellings took OPPOSITE
                 * verdicts: the interrupted twin (a bare T_NAMESPACE)
                 * hit the bare-keyword fence below, while the FUSED
                 * token fell through the use-statement gate
                 * (use_open false for a closure list) and rode the
                 * rewrite at exit 0 by lexer accident. The region is
                 * depth-counted so hostile bytes nesting parentheses
                 * cannot smuggle a name past the closer.
                 */
                $closure_use = ! $use_open;
                $closure_use_depth = 0;

                continue;
            }
            if ($closure_use) {
                if ('(' === $token) {
                    ++$closure_use_depth;

                    continue;
                }
                if (')' === $token) {
                    --$closure_use_depth;
                    if ($closure_use_depth <= 0) {
                        $closure_use = false;
                    }

                    continue;
                }
                if (T_NAME_RELATIVE === $id) {
                    throw new RuntimeException("build: the relative operator inside a closure use(...) list in {$sourceVersion} is not a spelling PHP accepts — a closure's lexical use list carries VARIABLE bindings only, and the fused spelling once rode the rewrite verbatim at exit 0 while its interrupted twin refused (opposite verdicts by lexer accident); write no names in the list");
                }
            }
            if (T_NAMESPACE === $id && ! $use_open) {
                /*
                 * OCR round 5 (t31-ocr5-1, widened by t31-ocr5-9 — the
                 * verifier lens's two findings on the round's own
                 * fence): outside a use statement the bare keyword is
                 * legal in EXACTLY one role — OPENING a declaration (a
                 * name, or a braced block) standing at a statement
                 * boundary. Everything else is a parse error the
                 * engine never accepts (php -l: unexpected token
                 * "namespace"), while the family detector's walk DROPS
                 * a bare keyword that opens no legal declaration (the
                 * r8-10 rule keeps it from corrupting the resolution
                 * base) and reports only the following name run — so
                 * the bytes rode the rewrite, the postcondition, and
                 * the sweep at exit 0 (reproduced: the zip ships the
                 * parse-error line; the build runs no lint gate over
                 * its output). The fence's first spelling owned only
                 * the '\'-led tail across whitespace/comments: the
                 * lens shipped `namespace ?> <?php \Junk;` (a MODE
                 * BOUNDARY between keyword and tail is trivia to the
                 * r8-1 boundary owner, and the fence was blind to it)
                 * and `$x = namespace;` / `$x = namespace Junk;` (a
                 * declaration SHAPE in an expression POSITION is the
                 * same never-legal class). The allowed set is the two
                 * legal declaration shapes plus the braced global
                 * block, and a declaration-shaped keyword must STAND
                 * at a boundary (previous code token: start-of-file,
                 * an open/close tag, ';', '{', '}') — behind '=' or a
                 * name or a separator it is mid-expression, never a
                 * declaration. Residual, named: a declaration-shaped
                 * keyword at a boundary but not first in the file
                 * (`$x = 1; namespace Foo;` — a compile-time fatal,
                 * not a parse error) rides; refusing it needs
                 * statement-seen tracking, dev-time lint owns it.
                 *
                 * OCR round 7 (t31-ocr7-3): the follower must be
                 * CODE-ADJACENT — the r5-9 widening crossed mode
                 * boundaries to judge the tail behind them, which let
                 * `namespace ?> html <?php Foo;` BIND Foo to the
                 * keyword as its declaration name (a parse error php
                 * -l rejects: the close tag terminates the bare
                 * keyword's statement, the re-entered name is a fresh
                 * statement PHP never attaches). A mode boundary ENDS
                 * the follower scan now: the boundary spelling is
                 * judged as the bare keyword alone (no follower), the
                 * fence's own named refusal — the r5-9 tails keep
                 * their verdicts ('\Junk' behind a boundary was never
                 * a declaration shape; it refuses as no-follower too).
                 */
                $follower = $i + 1;
                $follower_id = null;
                $follower_token = null;
                while ($follower < $count) {
                    $probe = $tokens[ $follower ];
                    $probe_id = is_array($probe) ? $probe[0] : null;
                    // Single-byte tokens (a null id) are CODE here:
                    // only the trivia classes skip.
                    if (T_WHITESPACE === $probe_id || T_COMMENT === $probe_id || T_DOC_COMMENT === $probe_id) {
                        ++$follower;

                        continue;
                    }
                    if (T_CLOSE_TAG === $probe_id || T_OPEN_TAG === $probe_id || T_OPEN_TAG_WITH_ECHO === $probe_id || T_INLINE_HTML === $probe_id) {
                        // The keyword's follower never stands across a
                        // mode boundary (t31-ocr7-3) — no follower, the
                        // bare keyword's own verdict.
                        break;
                    }
                    $follower_id = $probe_id;
                    $follower_token = $probe;

                    break;
                }
                $opens_shape = T_STRING === $follower_id || T_NAME_QUALIFIED === $follower_id || '{' === $follower_token;
                if ($opens_shape) {
                    // The backward twin of the mid-name walk's own
                    // trivia vocabulary: the keyword's previous CODE
                    // token decides whether it stands at a boundary.
                    $previous = wp_connectors_previous_code_token_index($tokens, $i - 1);
                    $previous_id = null !== $previous && is_array($tokens[ $previous ]) ? $tokens[ $previous ][0] : null;
                    $previous_token = null !== $previous ? $tokens[ $previous ] : null;
                    $at_boundary = null === $previous
                        || ';' === $previous_token || '{' === $previous_token || '}' === $previous_token
                        || T_OPEN_TAG === $previous_id || T_CLOSE_TAG === $previous_id;
                    if ($at_boundary) {
                        continue;
                    }
                }
                throw new RuntimeException("build: the bare 'namespace' keyword outside a use statement in {$sourceVersion} is not a spelling PHP accepts — outside one the keyword only ever opens a declaration (a name, or a braced block) standing at a statement boundary; the build runs no lint gate over the zip's bytes, so the rewrite refuses the parse-error spelling rather than shipping it at exit 0; write the family spelling");
            }
            /*
             * The frame rule over the use-statement boundary (OCR
             * round 30, t31-ocr30-1/2 — the census over every ';'
             * consumer of the ONE boundary owner): the ';'/tag set
             * terminates the use statement only at the statement's
             * OWN depth. A TRAIT-ADAPTATION body (`use T { m as n; }`)
             * is grammar-required to carry ';' INSIDE its braces, and
             * that inner terminator once fired the reset while
             * $group_depth === 1 — the reset disarmed mid-adaptation,
             * the adaptation's closing '}' was then judged OUTSIDE a
             * use statement and popped the enclosing class's 'other'
             * frame off the brace-kind stack, and every use statement
             * after the class drew its trait fence (t31-ocr26-1) from
             * a corrupted stack: driven red at HEAD,
             * `class C { use T { m as n; } use namespace\Clock\
             * \SystemClock; }` shipped the second use SPLICED to the
             * family map — a DIFFERENT trait, exit 0, the exact
             * retarget the trait fence exists to refuse. The
             * adaptation-inner ';' rides the adaptation's frame now
             * (skipped, never a reset), and the '}' that closes the
             * adaptation IS the trait use's terminator — the grammar
             * gives that spelling no trailing ';' — so the statement
             * state resets there, at its own closing brace, and the
             * brace-kind stack stays balanced through the block.
             *
             * The census, every other boundary consumer judged against
             * the same two frames: the TAIL rider judgment below (the
             * 934/985 pair) only ever runs at the statement's own
             * depth — a relative member INSIDE a group/adaptation
             * body is refused at the $group_depth fence above before
             * the tail walk starts; the declaration ledger's walk
             * (plugin-tools.php) resets at the same ';' set, but its
             * region exists to suppress FILE-LEVEL declarations, and
             * none can stand inside an adaptation body — the bare
             * keyword there either precedes the first inner ';' (the
             * r11-21 skip already owns it) or fails the
             * declaration-shape predicate, and every such spelling is
             * a parse error the rewriter's own fences refuse before
             * the ledger's verdict ships; the classifier walk
             * (unownedUseImportSpellingClass) carries the same
             * early-close and rides the same frame rule (its fix
             * below).
             */
            if ($use_open && wp_connectors_is_use_statement_boundary($token, $id)) {
                if ($group_depth > 0) {
                    // The adaptation-inner ';' rides the adaptation's
                    // frame, never the use reset.
                    continue;
                }
                $use_open = false;
                $use_is_trait = false;
                $group_depth = 0;

                continue;
            }
            if ($use_open && '{' === $token) {
                ++$group_depth;

                continue;
            }
            if ($use_open && '}' === $token) {
                --$group_depth;
                if ($group_depth <= 0) {
                    // The '}' closing the adaptation body terminates
                    // the trait use statement (no ';' follows it).
                    $group_depth = 0;
                    $use_open = false;
                    $use_is_trait = false;
                }

                continue;
            }
            if (! $use_open && ('{' === $token || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id)) {
                // The brace-kind stack (the t31-ocr7-7 vocabulary the
                // classifier walk rides): interpolation braces push
                // their own 'other' frame (t31-ocr16-10) so a plain
                // '}' never pops a frame that was never pushed.
                $context[] = '{' === $token
                    ? (self::braceOpensNamespaceBlock($tokens, $i) ? 'namespace' : 'other')
                    : 'other';

                continue;
            }
            if (! $use_open && '}' === $token && $context !== array()) {
                array_pop($context);
            }
            if (! ($use_open && (T_NAME_RELATIVE === $id || T_NAMESPACE === $id))) {
                continue;
            }

            /*
             * Normalize BOTH spellings of the relative operator to one
             * shape (verifier round t31-r11-10, raised by both lenses):
             * the FUSED token (namespace backslash Foo) and the
             * INTERRUPTED keyword — a space or COMMENT between the
             * keyword and the name makes the lexer drop the fused
             * token, so the old name-token-only keying let these ride
             * verbatim: parse-error bytes shipped at exit 0. Inside an
             * OPEN USE STATEMENT the bare keyword is never legal PHP —
             * the operator only ever reaches the parser as the fused
             * token — so every T_NAMESPACE met here is a parse-error
             * spelling this step owns.
             */
            $fused = T_NAME_RELATIVE === $id;
            // The trigger's own token index: the walk reassigns $i to
            // the name run's end below, and the mid-name judgment (the
            // backward walk at the bottom) must judge what precedes the
            // KEYWORD, not what precedes the run.
            $trigger_index = $i;
            $run_index = $i;
            if (! $fused) {
                /*
                 * The separator is REQUIRED (OCR round 16,
                 * t31-ocr16-9): the optional-separator shape accepted
                 * a name directly behind the keyword, so the
                 * SEPARATOR-LESS spelling — `use namespace Clock;`,
                 * a parse error the engine never accepts (php
                 * -l-verified) — was silently rewritten into a legal
                 * fully-qualified import: a parse error legalized at
                 * exit 0, the exact class this step exists to refuse.
                 * The relative operator's interrupted spelling is
                 * keyword, separator, name — trivia between them
                 * tolerated (the r11-10 shapes), the separator itself
                 * never optional.
                 */
                $follower = wp_connectors_next_code_token_index($tokens, $i + 1);
                $follower_id = null !== $follower && is_array($tokens[ $follower ]) ? $tokens[ $follower ][0] : null;
                /*
                 * The separator arrives in BOTH lexer spellings: the
                 * standalone T_NS_SEPARATOR (a comment rides between
                 * the keyword and the operator) and the one BAKED
                 * into a T_NAME_FULLY_QUALIFIED piece by the lexer
                 * itself after a whitespace/newline interruption (the
                 * name-run owner's own documented case — the leading
                 * backslash becomes part of the piece). Either is the
                 * operator; a NAME directly behind the keyword is
                 * neither, and refuses.
                 */
                if (T_NS_SEPARATOR !== $follower_id && T_NAME_FULLY_QUALIFIED !== $follower_id) {
                    throw new RuntimeException("build: the bare 'namespace' keyword inside a use statement in {$sourceVersion} is not a spelling PHP accepts — the relative operator only ever parses as one fused token, and a separator-less keyword-name spelling is a parse error the engine never accepts (the rewrite refuses it, never legalizes it); write the family spelling");
                }
                if (T_NS_SEPARATOR === $follower_id) {
                    $follower = wp_connectors_next_code_token_index($tokens, $follower + 1);
                    $follower_id = null !== $follower && is_array($tokens[ $follower ]) ? $tokens[ $follower ][0] : null;
                    if (! wp_connectors_is_name_token_id($follower_id)) {
                        throw new RuntimeException("build: the bare 'namespace' keyword inside a use statement in {$sourceVersion} is not a spelling PHP accepts — the relative operator only ever parses as one fused token; write the family spelling");
                    }
                }
                $run_index = $follower;
            }

            /*
             * The group-use MEMBER shape (verifier round t31-r11-9,
             * raised by both lenses): a relative standing INSIDE a
             * brace body (`use Other\{namespace\Clock};`) resolves
             * family by construction (declaration in effect + tail),
             * and the splice below would emit a LEADING-BACKSLASH name
             * into the member list — a spelling the grammar forbids
             * ("unexpected fully qualified name, expecting identifier
             * or namespaced name", verified on 8.5.10). The rewriter
             * would manufacture one parse error out of another and the
             * postcondition would wave it through (the absolute member
             * reports un-composed with the 'use' kind, target-prefixed
             * — the allow rule at the postcondition). Refuse loudly,
             * same as the prefix shape below: the rewrite owns no map
             * for such a member.
             */
            if ($group_depth > 0) {
                throw new RuntimeException("build: a group-use MEMBER may not be a namespace-relative spelling in {$sourceVersion} — the engine forbids the fully-qualified member the rewrite would emit, so the rewrite owns no map for such a member; write the family spelling");
            }

            $run = wp_connectors_name_run($tokens, $run_index);
            $run_end_offset = $token_offset;
            for ($k = $i; $k <= $run['end']; ++$k) {
                $run_end_offset += strlen(is_array($tokens[ $k ]) ? $tokens[ $k ][1] : $tokens[ $k ]);
            }
            $i = $run['end'];
            $offset = $run_end_offset;
            $tail_display = $fused ? (string) substr($run['name'], strlen('namespace\\')) : ltrim($run['name'], '\\');
            $spelling_display = $fused ? $run['name'] : 'namespace\\' . $tail_display;

            /*
             * The TRAIT-position refusal (OCR round 26, t31-ocr26-1 —
             * the fence is armed at the `use` keyword above): the
             * class-body spelling is legal PHP the rewrite does not
             * own — it names a TRAIT under the declaration in effect,
             * a reference the family map would silently RETARGET —
             * so it refuses like every other position the rewriter
             * does not own, never legalizes through a splice.
             */
            if ($use_is_trait) {
                throw new RuntimeException("build: the relative operator in a TRAIT use position ({$spelling_display}) in {$sourceVersion} is not the rewrite's to rewrite — a class-body `use` imports traits, and its relative spelling is LEGAL PHP naming a trait under the declaration in effect, a reference the rewrite once retargeted silently through the family map (changing which trait loads); the rewrite owns import statements only — write the trait's fully-qualified name");
            }

            /*
             * The group-use PREFIX shape (`use namespace\Foo\{…}`,
             * fused or interrupted): the rewrite owns no map for a
             * relative prefix's members.
             */
            $next = wp_connectors_next_code_token_index($tokens, $i + 1);
            if (null !== $next && T_NS_SEPARATOR === (is_array($tokens[ $next ]) ? $tokens[ $next ][0] : null)) {
                $after_separator = wp_connectors_next_code_token_index($tokens, $next + 1);
                if (null !== $after_separator && '{' === $tokens[ $after_separator ]) {
                    throw new RuntimeException("build: a group-use PREFIX may not be a namespace-relative spelling ({$spelling_display}) in {$sourceVersion} — the rewrite owns no map for such a prefix's members; write the family spelling");
                }
            }

            /*
             * MID-NAME and alias-position keywords (OCR round 3,
             * t31-ocr3-4): the relative operator parses only as the
             * import's LEADING name — the previous code token inside
             * the open statement must be the `use` keyword itself or
             * the `function`/`const` kind keywords, judged through the
             * backward twin of the walk's own trivia vocabulary. A
             * keyword behind a name or separator (`use Foo\ namespace
             * \Bar;`, and the comment-interrupted spelling of the same
             * shape) once spliced FROM the keyword and left the preceding
             * separator standing — `use Foo\ \Deicod\…`, a
             * double-separated spelling the engine rejects, shipped at
             * exit 0 through the postcondition (the glued run reports
             * target-prefixed 'use' at its second separator) — and the
             * alias slot (`use Foo as namespace\Bar;`) spliced a
             * fully-qualified name where the grammar wants an
             * identifier, the same exit-0 parse error. A mid-name
             * relative is degenerate; the rewrite owns no map for it —
             * refuse, the doctrine over the splice-the-whole-tail
             * alternative (there is no legal spelling to preserve).
             * The FUSED mid-name shape (`use Foo\namespace\Bar;`) never
             * reaches here on this engine: after a separator the lexer
             * demotes the keyword to a plain name piece, so that
             * spelling is a legal import of a non-family class and
             * rides untouched like every non-family import (probed on
             * 8.5.10).
             */
            $previous = wp_connectors_previous_code_token_index($tokens, $trigger_index - 1);
            $previous_id = null !== $previous && is_array($tokens[ $previous ]) ? $tokens[ $previous ][0] : null;
            if (T_USE !== $previous_id && T_FUNCTION !== $previous_id && T_CONST !== $previous_id) {
                throw new RuntimeException("build: a use statement's relative operator may not stand mid-name or in the alias slot ({$spelling_display}) in {$sourceVersion} — the splice once started at the keyword and left the preceding separator, shipping a double-separated parse error at exit 0; the rewrite owns the operator only as the import's leading name (use namespace\\… / use function|const namespace\\…), and a degenerate spelling gets no map — write the family spelling");
            }

            /*
             * The statement-TAIL rider judgment (OCR round 16,
             * t31-ocr16-4): the splice covers the keyword through the
             * name run — everything the statement carries AFTER the
             * run rides verbatim beside the rewritten name. `as
             * Alias` before the terminator is the one rider the
             * splice can leave standing as legal output; anything
             * else once shipped legal-LOOKING output with the
             * parse-error bytes intact (driven at HEAD:
             * `use namespace\Clock SystemClock;` exited 0 as
             * `use \…\Clock SystemClock;`, php -l rejecting the
             * shipped line; the postcondition sees only family
             * references, so the rider was judged by nobody — the
             * exact 'zip ships the parse-error line at exit 0' class
             * this step exists to refuse). The tail is judged
             * through the lexer's own boundaries now: from the run's
             * end, code token by code token, the allowed grammar is
             * an optional `as` + one identifier, terminated by the
             * statement-boundary set (the ONE boundary owner) or a
             * ',' — and the comma CONTINUES the judgment into the
             * next member (OCR round 26, t31-ocr26-2): the carve
             * once set 'terminated' AT the comma, so a FOLLOWING
             * member carrying no relative trigger of its own was
             * never judged by this walk (the main loop's trigger
             * condition skips non-relative members) — driven at
             * HEAD, `use namespace\Clock, Other\Thing SystemClock;`
             * spliced the first member and shipped the second's
             * rider bytes verbatim at exit 0, the parse-error line
             * riding BESIDE rewritten output, judged by nobody. The
             * judgment owns EVERY member of the list now: past the
             * comma a member is the kind keywords (`function`/
             * `const`) plus a name run (the same tokens the run
             * assembler consumes), an optional alias, and its own
             * comma-or-terminator — a member that is itself
             * relative keeps its own trigger through the main loop
             * in BOTH lexer spellings (the fused token rides the
             * name-run branch, the interrupted bare T_NAMESPACE its
             * own member-start branch below — t31-ocr30-5), and the
             * mid-name and trait fences judge it there.
             * Anything else — a second name, a brace without its
             * separator, an operator, an empty member, EOF without
             * a terminator — refuses loudly, never legalizes.
             */
            $tail_index = wp_connectors_next_code_token_index($tokens, $run['end'] + 1);
            $tail_expect = 'rider-or-terminator';
            $member_named = true;
            $member_await_separator = true;
            $member_separator_open = false;
            while (null !== $tail_index) {
                $tail_token = $tokens[ $tail_index ];
                $tail_id = is_array($tail_token) ? $tail_token[0] : null;
                if ('member-start' === $tail_expect) {
                    /*
                     * The member grammar is SEPARATOR-AWARE: a name
                     * piece continues the run only through a '\'
                     * (the run assembler's own vocabulary), so a
                     * SECOND name with no separator between — the
                     * rider bytes — never reads as a longer name.
                     *
                     * And a separator CONSUMED owes a name (OCR round
                     * 31, t31-ocr31-3): the lexer bakes every legal
                     * separator into the member's own name tokens
                     * (probed: `\Other` and `\Other\Thing` each lex
                     * as ONE T_NAME_FULLY_QUALIFIED token, `E\F` as
                     * one T_NAME_QUALIFIED), so a bare T_NS_SEPARATOR
                     * in the stream is an interrupted or DOUBLED
                     * spelling — and the branch below once consumed
                     * it unconditionally, with no state recording
                     * separator-consumed-with-no-name, so two
                     * engine-rejected member spellings passed the
                     * walk and shipped verbatim beside the rewritten
                     * name at exit 0 (driven red at HEAD: the double
                     * separator `use namespace\Clock, \\Other;`, and
                     * a separator dangling before the terminator,
                     * the comma, or the alias — every one a parse
                     * error php -l names "unexpected \"). The open
                     * separator answers for exactly one name piece
                     * next: not a name token (the terminator, the
                     * comma, `as`, a second separator) or a name
                     * token whose own text LEADS with '\' (the baked
                     * second separator of the `\\Other` spelling)
                     * each refuse with the engine's own verdict.
                     */
                    if ($member_separator_open) {
                        $rider_display = is_array($tail_token) ? $tail_token[1] : $tail_token;
                        if (! wp_connectors_is_name_token_id($tail_id) || '\\' === $rider_display[0]) {
                            throw new RuntimeException("build: parse-error bytes ride the relative use import ({$spelling_display}) in {$sourceVersion} — the import member's separator names no member (here: '{$rider_display}'): the lexer bakes every legal separator into the member's own name tokens, so a '\\' standing alone in the stream is a doubled or dangling spelling the engine rejects at parse time ('\\\\Other', 'Other\\;', 'Other\\, C', 'Other\\ as C') and the walk once shipped verbatim beside the rewritten name at exit 0; write the member with its separators inside one name");
                        }
                        $member_separator_open = false;
                    }
                    if (T_NS_SEPARATOR === $tail_id) {
                        $member_await_separator = false;
                        $member_separator_open = true;
                        $tail_index = wp_connectors_next_code_token_index($tokens, $tail_index + 1);

                        continue;
                    }
                    if (wp_connectors_is_name_token_id($tail_id) && (! $member_await_separator || ! $member_named)) {
                        $member_named = true;
                        $member_await_separator = true;
                        $tail_index = wp_connectors_next_code_token_index($tokens, $tail_index + 1);

                        continue;
                    }
                    /*
                     * The bare T_NAMESPACE member-start (OCR round 30,
                     * t31-ocr30-5): the INTERRUPTED twin of a fused
                     * relative member. The fused token is a name-token
                     * id, so it rides the branch above as the member's
                     * name start and its OWN trigger fires in the main
                     * loop, where the mid-name fence owns the
                     * relative-member verdict; the bare keyword is NOT
                     * a name-token id, so it once fell to the
                     * empty-member refusal below — "the comma is
                     * followed by no import member" — a mis-named
                     * verdict over a member that IS there (driven at
                     * HEAD), and the routing comment's claim ("a
                     * member that is itself relative keeps its own
                     * trigger through the main loop") held for the
                     * fused spelling only. The keyword rides as the
                     * member's name start now, so BOTH spellings of
                     * the same member take the main loop's own route
                     * and answer the SAME refusal — one shape for both
                     * lexer spellings, the r11-10 doctrine (the
                     * opposite-verdicts-by-lexer-accident class this
                     * walk closed at r11-10 and ocr23-7).
                     */
                    if (T_NAMESPACE === $tail_id && ! $member_named) {
                        $member_named = true;
                        // The interrupted spelling's follower arrives
                        // as the baked T_NAME_FULLY_QUALIFIED piece
                        // (the separator fence's own vocabulary above)
                        // — no separator is awaited between them.
                        $member_await_separator = false;
                        $tail_index = wp_connectors_next_code_token_index($tokens, $tail_index + 1);

                        continue;
                    }
                    if (! $member_named && (T_FUNCTION === $tail_id || T_CONST === $tail_id)) {
                        // The kind keywords lead a member (`use A, function B;`).
                        $tail_index = wp_connectors_next_code_token_index($tokens, $tail_index + 1);

                        continue;
                    }
                    if ($member_named) {
                        // The member is named; its tail judges in the
                        // rider state below (this token unconsumed).
                        $tail_expect = 'rider-or-terminator';
                    } else {
                        $rider_display = is_array($tail_token) ? $tail_token[1] : $tail_token;

                        throw new RuntimeException("build: parse-error bytes ride the relative use import ({$spelling_display}) in {$sourceVersion} — the comma is followed by no import member (here: '{$rider_display}'), and the rewrite owns the statement through its terminator, never a list with an empty member; write one import member per comma");
                    }
                }
                if ('rider-or-terminator' === $tail_expect) {
                    if (wp_connectors_is_use_statement_boundary($tail_token, $tail_id)) {
                        $tail_expect = 'terminated';

                        break;
                    }
                    if (',' === $tail_token) {
                        $tail_expect = 'member-start';
                        $member_named = false;
                        $member_await_separator = false;
                        $member_separator_open = false;

                        $tail_index = wp_connectors_next_code_token_index($tokens, $tail_index + 1);

                        continue;
                    }
                    if (T_AS === $tail_id) {
                        $tail_expect = 'alias-identifier';

                        $tail_index = wp_connectors_next_code_token_index($tokens, $tail_index + 1);

                        continue;
                    }
                    $rider_display = is_array($tail_token) ? $tail_token[1] : $tail_token;

                    throw new RuntimeException("build: parse-error bytes ride the relative use import ({$spelling_display}) in {$sourceVersion} — the rewrite owns the statement through its terminator, and rider bytes it cannot map (here: '{$rider_display}') ship beside the rewritten name as legal-looking output the engine then rejects; the legal tail is an optional alias ('as Name') before the terminator; write the import without the rider bytes");
                }
                if ('alias-identifier' === $tail_expect) {
                    $rider_display = is_array($tail_token) ? $tail_token[1] : $tail_token;
                    /*
                     * The identifier-slot reserved vocabulary (the
                     * refutation lens over this round's own gate,
                     * t31-ocr16-4): fourteen spellings lex as plain
                     * T_STRING yet are forbidden in the alias slot —
                     * self/parent, the three literals, and the type
                     * keywords (php -l-derived on this engine, CASE-
                     * INSENSITIVELY: 'as self'/'as True'/'as Int'
                     * all refuse). Accepting them shipped the
                     * rewritten import with the engine-illegal alias
                     * intact — this fix's own exit-0 parse-error
                     * class, caught by the round's verifier pass.
                     * The list rides the ONE reserved-vocab owner
                     * since OCR round 32 (t31-ocr32-1): the hand-
                     * rolled twin folded in, the same class refused
                     * at every seam that re-emits an alias.
                     */
                    if (T_STRING !== $tail_id || self::aliasIdentifierIsEngineIllegal($rider_display)) {
                        throw new RuntimeException("build: the alias of a relative use import ({$spelling_display}) must be one plain identifier in {$sourceVersion} — the grammar accepts nothing else in the slot (a keyword spelling, case-insensitively, included), and the rewrite refuses the spelling rather than shipping it (here: '{$rider_display}')");
                    }
                    $tail_expect = 'terminator-only';
                } elseif ('terminator-only' === $tail_expect && ',' === $tail_token) {
                    $tail_expect = 'member-start';
                    $member_named = false;
                    $member_await_separator = false;
                    $member_separator_open = false;
                } elseif (! wp_connectors_is_use_statement_boundary($tail_token, $tail_id)) {
                    $rider_display = is_array($tail_token) ? $tail_token[1] : $tail_token;

                    throw new RuntimeException("build: parse-error bytes ride the relative use import ({$spelling_display}) in {$sourceVersion} — after the alias only the terminator may follow, and rider bytes (here: '{$rider_display}') ship beside the rewritten name as legal-looking output the engine then rejects; write the import without the rider bytes");
                } else {
                    $tail_expect = 'terminated';

                    break;
                }
                $tail_index = wp_connectors_next_code_token_index($tokens, $tail_index + 1);
            }
            if ('terminated' !== $tail_expect) {
                throw new RuntimeException("build: a relative use import ({$spelling_display}) carries no terminator in {$sourceVersion} — the statement never closes, and the rewrite refuses the unterminated spelling rather than splicing a name into bytes the engine cannot parse");
            }

            $declaration = $declaration_in_effect($token_offset);
            $declared_display = null !== $declaration ? $declaration['display'] : null;
            if (null === $declared_display || '' === $declared_display) {
                throw new RuntimeException("build: the relative use import {$spelling_display} in {$sourceVersion} cannot resolve — no namespace declaration is in effect there, and a relative spelling resolves against the file's own declaration");
            }
            $resolved_display = $declared_display . '\\' . $tail_display;
            $resolved_lower = wp_connectors_ascii_lower($resolved_display);
            if ($resolved_lower !== $vendor_lower && 0 !== strpos($resolved_lower, $vendor_lower . '\\')) {
                throw new RuntimeException("build: the relative use import {$spelling_display} in {$sourceVersion} resolves to {$resolved_display}, outside the shared-namespace family — in the rewritten output it would silently re-resolve against the REWRITTEN declaration, so it refuses rather than riding with changed meaning");
            }
            /*
             * The EXACT-ROOT spelling is a MEMBER (OCR round 29,
             * t31-ocr29-2): the family check tested only the
             * prefix-with-separator form, so a relative resolving to
             * EXACTLY the family root (`use namespace\WpConnectors\
             * Shared;` under `namespace Deicod;`) passed the vendor
             * check, missed the family one, and refused as a "SIBLING"
             * — the root is not a sibling, and the mis-diagnosis
             * answered a member with the one verdict it cannot earn.
             * The root rewrites to the rewritten ROOT: no below-root
             * segment rides the splice, so no trailing separator
             * ships (the prefix form's appended separator over an
             * empty tail would emit `\…\Shared\`, a parse error).
             */
            if ($resolved_lower !== $root_lower && 0 !== strpos($resolved_lower, $root_lower . '\\')) {
                throw new RuntimeException("build: the relative use import {$spelling_display} in {$sourceVersion} resolves to {$resolved_display}, a SIBLING under the vendor prefix the rewrite owns no spelling of — write the shared tree's own namespace (or refuse by hand)");
            }
            $below_root = implode('\\', array_slice(explode('\\', $resolved_display), count($family_segments)));
            $splices[] = array(
                'start' => $token_offset,
                'end' => $run_end_offset,
                'replacement' => '\\' . $vendor . '\\' . $pluginSuffix . '\\' . $family_leaf . ('' === $below_root ? '' : '\\' . $below_root),
            );
        }

        for ($s = count($splices) - 1; $s >= 0; --$s) {
            $source = substr($source, 0, $splices[ $s ]['start']) . $splices[ $s ]['replacement'] . substr($source, $splices[ $s ]['end']);
        }

        return $source;
    }

    /**
     * A preg_replace result that must be a string, never a silent cast
     * (review round t31-r4-14).
     *
     * PCRE aborts (backtrack-limit exhaustion, a bad UTF-8 subject under
     * a /u pattern) make preg_replace()/preg_replace_callback() return
     * null; the (string) casts at the rewrite seams turned that into ''
     * — an EMPTY file written into the zip, fail-open against the
     * glm36-8 abort-as-reject doctrine (the trigger is unproven on
     * these linear patterns, but the shape was wrong: the doctrine owns
     * the seam, not the odds). Null refuses the build loudly now, named
     * with the step and the file. K1's survivor postcondition would
     * catch most empty-file outcomes afterwards, but the refuse happens
     * here, at the seam where the abort occurred — and an empty file
     * whose source carried no rewritable spelling would pass the
     * postcondition clean.
     *
     * @param string|null $result        The preg_replace() return.
     * @param string      $step          Which rewrite step (diagnostics).
     * @param string      $sourceVersion Provenance string (diagnostics).
     * @return string The replacement result, guaranteed a string.
     * @throws RuntimeException When the replacement aborted.
     */
    private static function replaceOrThrow($result, $step, $sourceVersion)
    {
        if (null === $result) {
            throw new RuntimeException("build: the {$step} aborted (PCRE) while rewriting {$sourceVersion} — an abort refuses the rewrite, never writes an empty file");
        }

        return $result;
    }

    /**
     * The spelling class of the use statement a surviving import
     * reference stands in, when the statement carries one the rewrite's
     * byte grammar does not own — OCR round 7, t31-ocr7-2.
     *
     * LEGAL import spellings exist that the use-statement pattern
     * cannot match (php -l-verified): a comma-separated list
     * (`use A\B, C\D;`), a close-tag-terminated statement
     * (`use A\B ?> html`), and a comment inside the statement — the
     * pattern's regex sees bytes, and a comma, a mode boundary, or a
     * comment breaks the `\s*;`-terminated shape it owns. Such bytes
     * once refused only at the postcondition with the ANONYMOUS
     * "spelling survived" text; the seam doctrine is own-or-refuse-
     * NAMED, so this classifier names the class on the throw path
     * (green builds never call it). The verdict was always REFUSE —
     * the family import must be rewritten or the build stops — only
     * the diagnostic was anonymous.
     *
     * The surveyed comma carve: a ',' at brace depth 0 names an import
     * LIST only when no depth-0 '{' follows in the statement —
     * `use A, B {…}` is a trait-adaptation CLAUSE list (its commas
     * precede the block), a spelling with its own ledgered doctrine,
     * and this seam does not misname it. Group-use bodies are OWNED
     * (the member rewrite carries their commas at depth ≥ 1).
     *
     * The TRAIT-CONTEXT carve (verifier-pass fix t31-ocr7-7, over the
     * r7-2 carve above — the correctness lens drove both misses): a
     * use statement inside a NON-namespace block is a TRAIT use
     * (imports live at the top level or inside a braced namespace
     * block; trait clause lists live inside class bodies), and the
     * r7-2 carve only knew the BRACED clause shape — a BRACELESS
     * clause list (`use TraitA, FamilyTrait;`, legal PHP) wore the
     * import-list class, and the comment label carried NO carve at
     * all, so an adaptation carrying a comment wore 'move the comment
     * outside the statement' — a dead errand: the identical shape
     * minus the comment still refuses (the r10-1 doctrine owns that
     * refusal; the rewriter owns no adaptation spelling). A trait use
     * reports NO class — the anonymous verdict is its doctrine's own.
     * The context is a brace-kind stack: every '{' outside a use
     * statement opens a 'namespace' block (the brace follows the
     * `namespace` keyword's declaration run) or an 'other' block; a
     * use statement with any 'other' frame below it is a trait use.
     *
     * @param string $source           The rewritten bytes (the postcondition's subject).
     * @param int    $reference_offset The surviving reference's byte offset.
     * @param string $reference_name   The surviving reference's name as spelled.
     * @return string|null The named spelling class, or null (the anonymous backstop).
     */
    private static function unownedUseImportSpellingClass($source, $reference_offset, $reference_name)
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $offset = 0;
        $in_use = false;
        $hit = false;
        $trait_context = false;
        $context = array();
        $brace_depth = 0;
        $saw_statement_brace = false;
        $saw_depth_zero_comma = false;
        $saw_comment = false;
        $terminated_by_close_tag = false;
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[ $i ];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            $token_offset = $offset;
            $offset += strlen($text);
            if (! $in_use) {
                if (T_USE === $id && wp_connectors_use_opens_import($tokens, $i)) {
                    $in_use = true;
                    $trait_context = in_array('other', $context, true);
                    $brace_depth = 0;
                    $saw_statement_brace = false;
                    $saw_depth_zero_comma = false;
                    $saw_comment = false;
                    $terminated_by_close_tag = false;
                } elseif ('{' === $token || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id) {
                    /*
                     * The brace-kind stack (t31-ocr7-7): what OPENED
                     * the block decides whether a use statement
                     * inside it is an import (top level, or a braced
                     * namespace block) or a trait clause list (any
                     * other block). The interpolation openers push
                     * their OWN frame (OCR round 16, t31-ocr16-10):
                     * a double-quoted `{$a}` lexes T_CURLY_OPEN plus
                     * a PLAIN '}' (`${a}` rides
                     * T_DOLLAR_OPEN_CURLY_BRACES the same way), and
                     * the plain closer once popped a frame that was
                     * never pushed — the stack ran one short per
                     * interpolation, an enclosing class frame fell
                     * off early, and a trait clause list AFTER an
                     * interpolation-bearing method was judged as an
                     * IMPORT (driven: the classifier handed the trait
                     * list the dead 'write one use per line' errand
                     * the t31-ocr7-7 doctrine reserves for import
                     * lists; the identical shape minus the
                     * interpolation got the anonymous trait
                     * verdict). An interpolation is never a
                     * namespace block: its frame is 'other', pushed
                     * and popped by its own braces like every other
                     * block.
                     */
                    $context[] = '{' === $token
                        ? (self::braceOpensNamespaceBlock($tokens, $i) ? 'namespace' : 'other')
                        : 'other';
                } elseif ('}' === $token && $context !== array()) {
                    array_pop($context);
                }

                continue;
            }
            if ($token_offset <= $reference_offset && $reference_offset < $offset) {
                $hit = true;
            }
            /*
             * The frame rule over the use-statement boundary, this
             * walk's arm (OCR round 30, t31-ocr30-2 — the SAME
             * early-close the rewriter's walk closed at t31-ocr30-1;
             * the census over every ';' consumer lives there): a
             * trait-adaptation body carries grammar-required ';' INSIDE
             * its braces, and that inner terminator once reset $in_use
             * while $brace_depth === 1 — the adaptation's closing '}'
             * was then judged OUTSIDE a use statement and popped the
             * enclosing CLASS's 'other' frame off the brace-kind stack,
             * and the stack mis-counted every brace that followed (a
             * trait clause list after the adaptation read as an
             * import, an import as a trait). The inner ';' rides the
             * adaptation's frame now, and the '}' that closes it IS
             * the trait use's terminator (the grammar gives that
             * spelling no trailing ';'), so the statement ends at its
             * own closing brace — break for a hit reference, reset for
             * the walk — and the brace-kind stack stays balanced.
             */
            if (wp_connectors_is_use_statement_boundary($token, $id)) {
                if ($brace_depth > 0) {
                    // The adaptation-inner boundary rides the
                    // adaptation's frame, never the use reset.
                    continue;
                }
                if ($hit) {
                    $terminated_by_close_tag = T_CLOSE_TAG === $id;

                    break;
                }
                $in_use = false;

                continue;
            }
            if ('{' === $token) {
                if (0 === $brace_depth) {
                    $saw_statement_brace = true;
                }
                ++$brace_depth;
            } elseif ('}' === $token) {
                --$brace_depth;
                if ($brace_depth <= 0) {
                    // The '}' closing the adaptation body terminates
                    // the trait use statement (no ';' follows it).
                    $brace_depth = 0;
                    if ($hit) {
                        break;
                    }
                    $in_use = false;

                    continue;
                }
            } elseif (',' === $token && 0 === $brace_depth) {
                $saw_depth_zero_comma = true;
            } elseif (T_COMMENT === $id || T_DOC_COMMENT === $id) {
                $saw_comment = true;
            }
        }
        if (! $hit) {
            return null;
        }
        if ($trait_context) {
            // A trait use carries no import class (t31-ocr7-7): the
            // anonymous verdict IS its doctrine's refusal.
            return null;
        }
        $labels = array();
        if ($saw_depth_zero_comma && ! $saw_statement_brace) {
            $labels[] = 'a comma-separated import list (use A\\B, C\\D;) — the rewrite owns one import per statement; write one use per line';
        }
        if ($terminated_by_close_tag) {
            $labels[] = 'a close-tag-terminated import (use A\\B ?> …) — the rewrite owns semicolon-terminated statements; end the import with a \';\'';
        }
        if ($saw_comment) {
            $labels[] = 'a comment inside the use statement — the rewrite owns comment-free statement bytes; move the comment outside the statement';

        }
        /*
         * The case-variant KEYWORD label is GONE (OCR round 35,
         * t31-ocr35-1): the r7-9 census covered two of the four
         * case-insensitive axes — the `use` keyword (this label) and
         * the family name (the label below) — while `as`, `function`,
         * and `const` rode unnamed, and the fix OWNED the whole
         * keyword class at the patterns (the scoped (?i:…) spellings,
         * census there) instead of refusing it. A case-variant keyword
         * can no longer reach this classifier as a refusal cause: a
         * keyword-case spelling the patterns own rewrites, and every
         * survivor that still carries one (a comma list, a close tag,
         * a comment) refuses through THAT cause — a 'write the keyword
         * lowercase' errand there would be dead exactly the way the
         * r24-4 vendor branch was (complying leaves the other cause
         * standing), so the label died with its premise. The
         * family-NAME axis keeps its label below: the patterns match
         * the declared spelling byte-exactly by design, so that errand
         * (write the family spelling) genuinely rewrites.
         */
        $family = wp_connectors_shared_source_namespace();
        $canonical = null;
        $name_lower = wp_connectors_ascii_lower($reference_name);
        /*
         * The canonical judgment is BOUNDARY-AWARE (OCR round 24,
         * t31-ocr24-4): a bare prefix match mislabeled a below-vendor
         * SIBLING whose first segment merely starts with the family
         * leaf ('…\SHAREDly\Clock', 'SharedStorage') as "a
         * case-variant spelling of the family name … write the family
         * spelling" — a dead errand: complying leaves the sibling,
         * which refuses in every casing, so the label sent the author
         * back to an identical refusal. The fold matches
         * plugin-tools' $is_family — the ONE family vocabulary's own:
         * exact, or prefix + a segment boundary.
         *
         * The VENDOR branch is DELETED (the round's verifier close,
         * rd-1 — the refutation lens's driven finding): every label
         * it could emit was the same dead errand one branch over,
         * because nothing that reaches it can become rewrite-owned by
         * re-casing — the bare vendor and every below-vendor sibling
         * refuse in EVERY casing (the detector's own $is_family fold
         * includes the vendor prefix; the survivors battery pins the
         * exact-case legs), and target-rooted uses are waived before
         * the classifier ever runs. Driven: 'use
         * DEICOD\WpConnectors\Zai\ApiClient;' wore the label while
         * the complied spelling refused identically — the author had
         * a wrong hint and then none. Shapes whose refusal is
         * doctrine carry NO class sentence (the ocr7-7 doctrine);
         * the label belongs to the FAMILY branch alone, where
         * complying genuinely rewrites.
         */
        $family_lower = wp_connectors_ascii_lower($family);
        if ($name_lower === $family_lower || 0 === strpos($name_lower, $family_lower . '\\')) {
            $canonical = $family;
        }
        if (null !== $canonical) {
            $prefix = (string) substr($reference_name, 0, strlen($canonical));
            if ($prefix !== $canonical && wp_connectors_ascii_lower($prefix) === wp_connectors_ascii_lower($canonical)) {
                $labels[] = 'a case-variant spelling of the family name (the engine resolves names case-insensitively; the rewrite\'s patterns match the declared spelling byte-exactly) — write the family spelling in its declared case';
            }
        }

        return $labels === array() ? null : implode('; ', $labels);
    }

    /**
     * Whether a '{' token at an index opens a BRACED NAMESPACE BLOCK
     * (the classifier's brace-kind stack, t31-ocr7-7) — the one block
     * kind inside which a use statement is still an IMPORT.
     *
     * The brace opens a namespace block exactly when walking back over
     * code tokens crosses only the declaration's name run (name tokens
     * and separators, `namespace X {` and `namespace Deicod \
     * \ WpConnectors {` alike) and lands on the `namespace` keyword —
     * which includes the run-less global block `namespace {`. Every
     * other brace (a class, a function, a control block) is 'other',
     * and a use statement under it is a trait clause list.
     *
     * @param array<int, array{0:int,1:string,2?:int}|string> $tokens Token stream.
     * @param int                                             $at     Index of the '{' token.
     * @return bool True when the brace opens a namespace block.
     */
    private static function braceOpensNamespaceBlock(array $tokens, $at)
    {
        $previous = wp_connectors_previous_code_token_index($tokens, $at - 1);
        while (null !== $previous) {
            $previous_id = is_array($tokens[ $previous ]) ? $tokens[ $previous ][0] : null;
            if (wp_connectors_is_name_token_id($previous_id) || T_NS_SEPARATOR === $previous_id) {
                $previous = wp_connectors_previous_code_token_index($tokens, $previous - 1);

                continue;
            }

            return T_NAMESPACE === $previous_id;
        }

        return false;
    }

    /**
     * Counts the DECODED top-level object keys of a JSON text (review
     * round t31-r5-6).
     *
     * The duplicate-key fence's one scanner: raw-text spellings cannot
     * see that 'embed_shared' and 'embed_shared' are the same key, so
     * the fence counts what json_decode() would actually keep. The
     * walk needs no error handling of its own — it runs only over text
     * that already decoded successfully (the malformed shapes refused
     * earlier at the seam). A string token is a KEY exactly when it
     * sits at nesting depth 1, inside an OBJECT frame, directly after
     * '{' or ',' — in valid JSON, ':' follows only keys, so a string
     * VALUE (''included'') is never counted; nested objects and arrays
     * push their own frames, so their keys and strings stay out of the
     * count. Each key token is json_decode()d whole, resolving every
     * escape spelling to the key it names.
     *
     * @param string $rawJson The raw build.json text (must already decode).
     * @return array<string, int> Decoded top-level key => occurrence count.
     */
    private static function decodedTopLevelKeyCounts($rawJson)
    {
        $counts = array();
        $length = strlen($rawJson);
        $frames = array();
        $await_key = false;
        for ($i = 0; $i < $length; ++$i) {
            $byte = $rawJson[ $i ];
            if ('"' === $byte) {
                // Scan the whole string token (escape-aware: '\\"' and
                // '\\\\' never close it early).
                $j = $i + 1;
                $escaped = false;
                while ($j < $length) {
                    $char = $rawJson[ $j ];
                    if ($escaped) {
                        $escaped = false;
                    } elseif ('\\' === $char) {
                        $escaped = true;
                    } elseif ('"' === $char) {
                        break;
                    }
                    ++$j;
                }
                if ($await_key && 1 === count($frames) && '{' === end($frames)) {
                    $decoded_key = json_decode(substr($rawJson, $i, $j - $i + 1));
                    if (is_string($decoded_key)) {
                        $counts[ $decoded_key ] = ($counts[ $decoded_key ] ?? 0) + 1;
                    }
                    $await_key = false;
                }
                $i = $j;

                continue;
            }
            if ('{' === $byte) {
                $frames[] = '{';
                $await_key = true;
            } elseif ('[' === $byte) {
                $frames[] = '[';
                $await_key = false;
            } elseif ('}' === $byte || ']' === $byte) {
                array_pop($frames);
                $await_key = false;
            } elseif (',' === $byte) {
                $await_key = 1 === count($frames) && '{' === end($frames);
            } elseif (':' === $byte) {
                $await_key = false;
            }
        }

        return $counts;
    }

    /**
     * Whether an identifier is one the ENGINE forbids in a use-alias
     * slot — the ONE owner of the reserved-alias vocabulary (OCR round
     * 32's census, t31-ocr32-1/2/3; the COMPLETE class since OCR round
     * 33, t31-ocr33-1).
     *
     * THE DERIVATION (a php -l oracle drove every keyword of the
     * language in the alias slot on 8.5.10, case-insensitively): the
     * reserved class is the lexer's own TWO-TOKEN distinction, and no
     * enumeration of one half can own it —
     *
     * - the SOFT half: fourteen spellings that lex as plain T_STRING
     *   yet never parse in the slot (self/parent, the three literals,
     *   the type keywords; 'as self'/'as True'/'as Int'/'as array'
     *   all refuse) — the lexer cannot tell them from a name, only
     *   the parser refuses them, so they ride the hand list below;
     * - the HARD half: every keyword that lexes as its OWN token id
     *   (array, fn, list, if, foreach, function, class, new, match,
     *   readonly, … — the oracle REFUSED every own-token keyword in
     *   the slot and accepted NONE) — DERIVED AT RUNTIME by
     *   tokenizing the candidate in the alias position: anything the
     *   lexer does not spell T_STRING there is reserved, so a future
     *   reserved word joins the class the day the engine mints it.
     *   The round's hole: the round-32 census enumerated the soft
     *   half alone, so `use …\Shared\Clock as array;` matched the
     *   alias grammar's identifier bytes and shipped parse-error
     *   bytes in the zip at exit 0 (php -l refuses every hard
     *   keyword in the slot, case-insensitively).
     *
     * The census's shared rule: the alias grammar rejects what the
     * engine rejects, at EVERY seam that re-emits an alias — the
     * use-statement pattern, the group-use member callback, and the
     * relative-use tail walk all consult THIS owner (the relative
     * walk's hand-rolled list folded into it), so a future reserved
     * word joins one owner, never three seams. Non-identifier bytes
     * answer false here (the identifier-SHAPE seams own their own
     * verdicts; this census owns only the reserved vocabulary). The
     * fold rides the ONE ASCII owner (the r11-6/ocr10-4 doctrine).
     *
     * @param string $alias Candidate alias identifier.
     * @return bool True when the engine rejects the identifier in an alias slot.
     */
    private static function aliasIdentifierIsEngineIllegal($alias)
    {
        $alias = (string) $alias;
        if (in_array(wp_connectors_ascii_lower($alias), array(
            'self', 'parent', 'true', 'false', 'null',
            'int', 'float', 'bool', 'string', 'void', 'iterable', 'object', 'mixed', 'never',
        ), true)) {
            return true;
        }
        if (1 !== preg_match('/\A[A-Za-z0-9_]+\z/', $alias)) {
            // Not identifier bytes — never this census's verdict (the
            // shape seams own the not-an-identifier refusal).
            return false;
        }
        // The HARD half, derived from the lexer at every call: a
        // keyword lexes as its own token id (never T_STRING) in the
        // alias position, case-insensitively — the one spelling of
        // the class that cannot drift from the engine.
        foreach (token_get_all("<?php use A\\B as {$alias};") as $token) {
            if (is_array($token) && T_STRING !== $token[0] && $alias === $token[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates ONE group-use member against the member grammar and
     * returns its parsed pieces — the ONE owner of the member
     * validation every seam that re-emits a group BODY rides (OCR
     * round 40, t31-ocr40-1: the vendor-prefix group callback — which
     * also rewrites the member's leading Shared segment — and the
     * plain use-statement seam's FAMILY-PREFIX brace tail, which
     * re-emits its members verbatim beside the rewritten prefix).
     *
     * The grammar is validated BEFORE any reassembly (OCR round 31,
     * t31-ocr31-4): the reassembly once normalized every illegal
     * member spelling through explode/trim/implode into a silent pass
     * that shipped the parse-error bytes verbatim at exit 0. The
     * rewriter owns what it reassembles: each illegal shape refuses
     * HERE, at the seam, naming the spelling the engine rejects —
     *
     * - the empty member (`{, Shared\Clock}`), the trailing comma
     *   (`{Shared\Clock,}`), the empty body (`{}`), and a dangling
     *   `as` (`{Shared\Clock as}`);
     * - an `as`-tail that is not ONE plain identifier (`{Shared\Clock
     *   as Foo\Bar}` — php -l accepts only a bare identifier in the
     *   slot; OCR round 32, t31-ocr32-3), extracted case-
     *   insensitively (`AS` is a legal keyword spelling that keeps
     *   riding; the tail's own shape is judged, never the keyword's
     *   case);
     * - a member ALIAS the engine forbids (`{Shared\Clock as self}`,
     *   case-insensitively — the same reserved-vocab owner
     *   t31-ocr32-1 established at the use-statement seam, the
     *   t31-ocr33-1 complete class since);
     * - a member KIND keyword (function/const, case-insensitively —
     *   t31-ocr35-1), stripped and returned so the caller's rewrite
     *   sees the bare name;
     * - a fully-qualified member (`{ \Shared\Clock as C }` — a group
     *   member resolves against the statement's prefix, so a leading
     *   backslash names no legal member; php -l: "unexpected fully
     *   qualified name"; OCR round 36, t31-ocr36-1);
     * - a member that is not a NAME at all (`{1y}`, `{Foo Bar}`,
     *   `{Foo-Bar}`, a digit-initial sub-segment — the engine's
     *   label grammar rejects each; OCR round 43, t31-ocr43-3,
     *   php -l-derived like the arm above it).
     *
     * @param string $member        The trimmed member piece to judge.
     * @param string $body          The whole brace body (refusal context).
     * @param int    $member_index  Zero-based index of the piece within the comma split.
     * @param int    $piece_count   Count of the comma split's pieces.
     * @param string $sourceVersion Provenance string (refusal context).
     * @return list<string> The parsed member: [kind prefix, name, alias tail].
     * @throws RuntimeException When the member is a spelling the engine rejects.
     */
    private static function groupUseMemberGrammar($member, $body, $member_index, $piece_count, $sourceVersion)
    {
        if ('' === $member) {
            $shape = '' === trim($body)
                ? 'an empty brace body'
                : (0 === $member_index
                    ? 'an empty member before its comma'
                    : ($member_index === $piece_count - 1
                        ? 'a trailing comma'
                        : 'an empty member between commas'));
            throw new RuntimeException("build: the group-use member grammar refuses the statement (body: '" . trim($body) . "') in {$sourceVersion} — here: {$shape}: every one of these spellings is a parse error the engine rejects at compile time, and the reassembly once normalized it through explode/trim/implode into a silent pass that shipped the parse-error bytes verbatim at exit 0; write one named member per comma, never an empty one");
        }
        if (1 === preg_match('/\bas\s*$/i', $member)) {
            throw new RuntimeException("build: the group-use member grammar refuses the statement (member: '{$member}') in {$sourceVersion} — here: an 'as' with no identifier after it: a dangling alias is a parse error the engine rejects at compile time, and the reassembly once reassembled it into rewritten output that shipped ' as}' verbatim at exit 0; write the member as 'Name as Alias' or the bare 'Name'");
        }
        $tail = '';
        if (1 === preg_match('/^(.+?)\s+as\s+(.+)$/i', $member, $alias_parts)) {
            if (self::aliasIdentifierIsEngineIllegal($alias_parts[2])) {
                throw new RuntimeException("build: the group-use member grammar refuses the statement (member: '{$member}') in {$sourceVersion} — here: the alias '{$alias_parts[2]}' is a reserved spelling the engine forbids in the slot, case-insensitively (every keyword the lexer does not spell a name), and the reassembly re-emits the alias verbatim, so the zip would ship the compile-error bytes at exit 0; write 'Name as Alias' with a plain identifier the engine accepts");
            }
            if (1 !== preg_match('/\A[A-Za-z0-9_]+\z/', $alias_parts[2])) {
                throw new RuntimeException("build: the group-use member grammar refuses the statement (member: '{$member}') in {$sourceVersion} — here: an 'as' whose tail ('{$alias_parts[2]}') is not one plain identifier: the engine accepts only a bare identifier in the alias slot, and the reassembly once re-emitted the tail verbatim beside the rewritten name at exit 0; write 'Name as Alias' with a plain identifier");
            }
            $member = $alias_parts[1];
            $tail = ' as ' . $alias_parts[2];
        }
        $kind = '';
        if (1 === preg_match('/^(?:function|const)\s+/i', $member, $member_kind)) {
            $kind = $member_kind[0];
            $member = (string) substr($member, strlen($member_kind[0]));
        }
        if ('\\' === ($member[0] ?? '')) {
            throw new RuntimeException("build: the group-use member grammar refuses the statement (member: '{$member}') in {$sourceVersion} — here: a fully-qualified member (a leading backslash): the engine rejects the spelling at compile time (a group member resolves against the statement's prefix — php -l: unexpected fully qualified name), and the leaf rewrite cannot match the leading separator, so the reassembly once re-emitted the member verbatim beside its rewritten siblings — compile-error bytes in the zip at exit 0; write the member relative to the group's prefix, never with a leading backslash");
        }
        /*
         * The member NAME's own shape (OCR round 43, t31-ocr43-3 —
         * the seventh use-grammar generation), DERIVED from the
         * engine oracle like the leading-separator verdict one arm
         * above: label segments (letter/underscore/high-byte initial,
         * then label bytes; PHP labels admit \x80-\xff) joined by
         * single backslashes — php -l accepts 'Foo\\Bar' and 'Grüß'
         * and REFUSES '1y', '9', 'Foo Bar', 'Foo-Bar', and every
         * digit-initial sub-segment ('Foo\\1b'). The grammar once
         * validated everything AROUND the name — emptiness, the
         * alias, the kind, the leading separator — while 'a member
         * that is not a name at all' passed every check and was
         * re-emitted VERBATIM by both re-emit seams (the vendor-prefix
         * group callback beside its rewritten sibling members, the
         * family-prefix brace tail beside the rewritten prefix), the
         * postcondition judging only family references and waving
         * both shapes through: compile-error bytes in the zip at
         * exit 0.
         */
        if (1 !== preg_match('/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/', $member)) {
            throw new RuntimeException("build: the group-use member grammar refuses the statement (member: '{$member}') in {$sourceVersion} — here: a member that is not a NAME: the engine accepts only label segments (never digit-initial, never a bare separator or mid-name space/hyphen — php -l refuses every spelling below), and both re-emit seams once re-emitted the bytes verbatim beside rewritten output — compile-error bytes in the zip at exit 0; write each member as relative label segments, the group's prefix carrying the rest");
        }

        return array( $kind, $member, $tail );
    }

    /**
     * Validates a namespace suffix as a legal namespace segment.
     *
     * The ONE check (review round t31-r3-6) the config seam runs up
     * front — before any filesystem mutation — and rewriteSharedNamespace()
     * keeps as defense in depth: a suffix of letters, digits, and
     * underscores (never digit-initial) is a legal namespace segment AND
     * replacement-safe by construction (it carries no backreference
     * meaning inside a preg_replace replacement, the t31-r2-19 rule).
     *
     * @param string $pluginSuffix Namespace segment to validate.
     * @return void
     * @throws RuntimeException When the suffix is not a legal namespace segment.
     */
    private static function assertNamespaceSegment($pluginSuffix)
    {
        if (1 !== preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', (string) $pluginSuffix)) {
            throw new RuntimeException("build: namespace_suffix must be a namespace segment (letters, digits, underscores; it may not start with a digit): '{$pluginSuffix}' given");
        }
    }

    /**
     * Collects shippable files (relative paths) for a plugin directory.
     *
     * @param string $pluginDir Absolute plugin directory.
     * @return list<string> Sorted relative file paths.
     * @throws RuntimeException When the tree carries a shippable
     *                          symlink, or a subdirectory the walk
     *                          cannot list (the t31-ocr36-2 fence).
     */
    public static function collectFiles($pluginDir)
    {
        $files = array();
        /*
         * THE COLLECTOR TWINS' DUAL-SEPARATOR spelling (OCR round 40,
         * t31-ocr40-2 — the r31-5 doctrine, swept to both collectors):
         * the root strip and the below-root arithmetic speak BOTH
         * separator spellings, never the native one alone —
         * RecursiveDirectoryIterator joins child pathnames through
         * the NATIVE separator, so a '/'-only strip and a '/'-only
         * segment split mis-slice on a '\' host: the str_replace
         * prefix strip failed wholesale (every "relative" kept its
         * absolute spelling), and every downstream judgment over it
         * mis-segmented — the dev-entry and near-source logic saw no
         * segments, the extension checks judged the full path, the
         * PSR-4 casing fence misfired (the collector twin one file
         * over, same class). The strip is the lint walk's arithmetic
         * (substr past the rtrimmed root's length + one separator
         * byte, whatever spelling it is); the segment split rides
         * DIRECTORY_SEPARATOR. On the POSIX host both are byte-
         * identical to the former spellings; on a '\' host the strip
         * judges the spelling CLASS, and the '\' arm costs residue
         * only for a path literally named with trailing backslash
         * bytes (the ocr29-3 trade, residue over victim).
         */
        $pluginDir = rtrim($pluginDir, '/\\');
        /*
         * THE WALK CENSUS (OCR round 36, t31-ocr36-2 — the
         * iterator-fence sweep finally whole): every
         * RecursiveDirectoryIterator walk in the change set converts
         * its construction throw and mid-recursion abort into the
         * named refusal vocabulary NOW — bin/lint-php.php's lint walk
         * (fenced at its own boundary), check-conventions.php's
         * unused-import walk (declares @throws; both call sites
         * convert), build.php's rrmdir teardown walk (the silent
         * contract, t31-ocr23-1), plugin-tools.php's
         * self-containment walk (the boundary guard converts), and
         * inspect-artifact.php's two walks (both fenced) — with the
         * ONE standing exception the ledger names (the secret
         * scanner's scan_paths walk, the residual line). THIS walk
         * and its twin wp_connectors_php_source_files() one file
         * over were the last unfenced pair: hasChildren() passes on
         * stat alone, so an unreadable SUBDIRECTORY mid-tree (a
         * chmod-000 child) aborted the descent in the SPL iterator's
         * own UnexpectedValueException — another library's
         * vocabulary answering a build refusal (the t31-ocr33-6
         * class, the collector twins). The construction rides the
         * try (the ocr23 rd-1 doctrine — a plugin root this process
         * cannot open throws from the constructor); the abort
         * converts to the build's own refusal with the SPL message
         * riding parenthetically (it is what names the path); the
         * per-entry refusals inside are RuntimeExceptions and pass
         * the fence untouched.
         */
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                $relative = (string) substr($file->getPathname(), strlen($pluginDir) + 1);
                $parts = explode(DIRECTORY_SEPARATOR, $relative);
                /*
                 * The exclusion filter runs FIRST (review round t31-r5-7):
                 * what never ships never judges the build. The symlink
                 * refusal fired before it, so a vendor/node_modules link —
                 * a composer path repo, an npm .bin shim — refused a build
                 * whose zip would have been byte-identical to one without
                 * the link (excluded paths ship nothing either way).
                 *
                 * The segment judgment rides the ONE comparison owner and
                 * folds CASE (review round t31-r6-3): the byte-exact
                 * array_intersect let 'Tests/', 'Build.json', and 'VENDOR'
                 * ship in release zips while the inspector — byte-exact
                 * itself — accepted the same entries (both gates agreed on
                 * the wrong verdict, so the one-verdict check never fired);
                 * on a case-insensitive extraction target every such name
                 * folds onto the dev entry it is one case away from.
                 *
                 * The NEAR-SOURCE class joins the exclusion (OCR round 23,
                 * t31-ocr23-8 — the r20 ledger's builder-side name fence,
                 * its named residual): a plugin-tree file whose name folds
                 * to .php only after edge-junk stripping ('notes.php.',
                 * 'x.PHP ') was packaged here while the inspector refused
                 * the same entry through the ONE near-source predicate —
                 * build shipped what inspect rejected, the fence pair
                 * inconsistent, the exact shape the r5-10 one-verdict
                 * doctrine closed for the development-entry vocabulary.
                 * The collector skips the class through the SAME judgment
                 * the inspector rejects by (the ONE near-source owner):
                 * what never ships never judges the build.
                 */
                $excluded = false;
                foreach ($parts as $part) {
                    if (wp_connectors_is_development_entry($part) || wp_connectors_segment_is_near_source_php($part)) {
                        $excluded = true;

                        break;
                    }
                }
                if ($excluded) {
                    continue;
                }
                /*
                 * A symlink REFUSES the build loudly (verifier round
                 * t31-r4-16, extending t31-r4-7's doctrine from the shared
                 * tree to the plugin tree) — scoped, since t31-r5-7, to the
                 * paths that would SHIP: the old silent skip left a
                 * divergence — a symlinked plugin source loads in development
                 * (the dev autoloader resolves link paths) and is scanned
                 * through by the self-containment walker, but silently missed
                 * the zip, so the shipped plugin fataled on the missing class
                 * at exit 0 (reproduced) — the same loads-in-dev/invisible/
                 * missing-from-every-zip class, plus the leak half the old
                 * skip pinned (out-of-tree content never packaged). Zero
                 * symlinks in the tree today; this is the doctrine made loud
                 * at both collectors.
                 */
                if ($file->isLink()) {
                    throw new RuntimeException(sprintf(
                        'plugin tree carries a symlink (%s -> %s) — the no-symlinks doctrine refuses the build instead of silently skipping a source that loads in development and misses the zip',
                        $file->getPathname(),
                        (string) $file->getLinkTarget()
                    ));
                }
                if (! $file->isFile()) {
                    continue;
                }
                $files[] = $relative;
            }
        } catch (UnexpectedValueException $walk_refusal) {
            throw new RuntimeException(sprintf(
                'build: the plugin tree carries a subdirectory that cannot be listed (%s) — the walk fences its recursion boundary and answers the build\'s own refusal, never the SPL iterator\'s vocabulary (the t31-ocr33-6 fence, swept to the collector census)',
                $walk_refusal->getMessage()
            ));
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The '/' spelling of a walk-collected relative path — the ONE
     * serialized-boundary owner (OCR round 41, t31-ocr41-1, ×2: both
     * zip legs, one commit, one defect class).
     *
     * THE CENSUS: the native separator vocabulary lives INSIDE the
     * walk — the t31-ocr40-2 dual-separator sweep made both
     * collectors WORK on '\' hosts, and the relatives they answer
     * carry the iterator's native join ('sub\file.php') — while
     * every SERIALIZED boundary speaks '/': the zip localname is
     * '/'-joined by zip spec, the collision keys fold over the
     * '/'-joined entries, and the manifest-side vocabulary the
     * inspector judges by is already '/'. Both zip legs spliced the
     * native-spelled relative verbatim into the ENTRY (the plugin
     * tree at collectFiles() and the embed destination over
     * wp_connectors_php_source_files()), so on a '\' host every
     * shipped entry name carried '\', breaking the inspector's
     * near-source check and the case-insensitive collision key. One
     * owner, both legs: $relative normalizes HERE, at the seam where
     * it composes an entry; the disk paths beside each seam keep the
     * native vocabulary (the engine resolves its own platform's
     * spelling). On the POSIX host the translation is byte-identical
     * ('/' IS the separator — construction-evident, the ocr28-3
     * doctrine: DIRECTORY_SEPARATOR is a constant no test sim
     * flips).
     *
     * @param string $relative A walk-collected relative path (native separator).
     * @return string The same path '/'-joined, for entry names.
     */
    private static function zipEntryPath($relative)
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * Builds one plugin zip.
     *
     * SOURCE LAYOUT COUPLING, stated (review round t31-r12-14, noted with
     * zero second callers): the shared library an embed_shared plugin
     * composes is read from dirname($distDir) . '/shared/src' — the
     * repository's sibling layout (dist/ and shared/ beside each other
     * under the repo root). The path is DERIVED, not a parameter, so a
     * caller handing in a dist directory from any other layout embeds
     * whatever tree happens to sit beside it: a missing directory
     * refuses loudly ("requests shared code but … does not exist"), but
     * a WRONG-content sibling embeds its PHP sources silently — the
     * loud refusal covers absence, not identity. If a second caller
     * with a different layout ever appears, parameterize the shared
     * source root explicitly (ledgered); until then this method serves
     * exactly the repository layout it lives in.
     *
     * @param string $pluginDir Absolute plugin source directory.
     * @param string $distDir   Absolute dist directory (the shared library is read from its parent's shared/src sibling).
     * @return string Absolute path of the built zip.
     * @throws RuntimeException On invalid input or I/O failure.
     */
    public static function buildPlugin($pluginDir, $distDir)
    {
        $pluginDir = rtrim($pluginDir, '/');
        $slug = basename($pluginDir);
        $mainFiles = wp_connectors_find_main_plugin_files($pluginDir);
        if ($mainFiles === array()) {
            throw new RuntimeException("build: no main plugin file with a Plugin Name header in {$pluginDir}");
        }
        $headers = wp_connectors_parse_plugin_headers($mainFiles[0]);
        $violations = array_merge(
            wp_connectors_main_file_violations($pluginDir, $mainFiles),
            wp_connectors_duplicate_header_violations($mainFiles[0], $slug),
            wp_connectors_header_violations($headers, $slug),
            wp_connectors_version_constant_violations($pluginDir, $headers, $mainFiles),
            wp_connectors_self_containment_violations($pluginDir),
            wp_connectors_autoloader_violations($pluginDir)
        );
        if ($violations !== array()) {
            throw new RuntimeException("build: refusing to package {$slug}:\n - " . implode("\n - ", $violations));
        }

        /*
         * Config seam (review round t31-r3-1, verifier round t31-r3-15,
         * then review round t31-r4 K2): build.json is resolved and
         * validated ONCE, before any filesystem mutation — and the
         * validation is a CLOSED SCHEMA, not container shape. The seam
         * had closed "silently skips the embed" one spelling at a time
         * (the decode failure, then the array top level); K2 closes the
         * CLASS: every key is known, every value is typed, and the
         * derived/explicit suffix agrees with the autoloader the plugin
         * will actually load through. Anything else refuses the build
         * loudly — a config the loader touches is a contract, and a
         * library-less or unloadable zip with exit 0 is the failure
         * mode every clause below exists to make impossible.
         */
        $embedShared = false;
        $sharedDir = '';
        $sharedSources = array();
        $pluginSuffix = '';
        $buildConfig = $pluginDir . '/build.json';
        /*
         * A SYMLINK at the config path refuses FIRST (OCR round 3,
         * t31-ocr3-3): file_exists() and is_file() both FOLLOW links, so
         * a DANGLING build.json symlink read as absent — the seam never
         * ran, $embedShared stayed false, and a library-less zip built
         * and published at exit 0 — exactly the silent-no-embed class
         * the seam exists to kill (t31-r4-17 closed the directory
         * spelling one gate below; this is its link sibling, which
         * skips the seam entirely instead of slipping the is_file()
         * gate). A RESOLVING link is refused by the same check, never
         * read through: a config the plugin does not own byte-for-byte
         * (an out-of-tree target can change out from under the release)
         * is not a config seam this build vouches for — the
         * no-symlinks doctrine (t31-r4-16) applied at the one path the
         * collector's walk never sees (build.json never ships, so the
         * shipped-path fence cannot catch it).
         */
        if (is_link($buildConfig)) {
            $target = (string) readlink($buildConfig);
            throw new RuntimeException("build: {$slug}: build.json is a symlink (-> {$target}) — dangling or resolving, a link is never silently skipped (a dangling link reads absent and the embed quietly turns off) and never read through (a link's bytes are not the plugin's own); make build.json a regular file");
        }
        if (file_exists($buildConfig)) {
            /*
             * A build.json that is not a REGULAR FILE refuses (verifier
             * round t31-r4-17): a directory at the path slipped the old
             * is_file() gate entirely — the seam never ran, the embed was
             * silently skipped, and a library-less zip shipped with exit 0
             * (reproduced) — the silent-no-embed class the seam exists to
             * kill, one spelling further out than the array top level.
             */
            if (! is_file($buildConfig)) {
                throw new RuntimeException("build: {$slug}: build.json is not a regular file — refusing instead of silently skipping the embed_shared configuration");
            }
            $rawConfig = file_get_contents($buildConfig);
            if (false === $rawConfig) {
                throw new RuntimeException("build: cannot read {$buildConfig}");
            }
            $decoded = json_decode($rawConfig);
            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new RuntimeException("build: {$slug}: build.json is malformed (" . json_last_error_msg() . ") — refusing instead of silently skipping the embed_shared configuration");
            }
            if (! is_object($decoded)) {
                throw new RuntimeException("build: {$slug}: build.json is malformed (the top-level value is not a JSON object) — refusing instead of silently skipping the embed_shared configuration");
            }
            $config = (array) $decoded;
            /*
             * Closed vocabulary (K2 / t31-r4-6): unknown keys refuse the
             * build. The container-shape checks accepted any object, so
             * {"embed_shard": true} — a typo — silently meant no-embed
             * and shipped a library-less zip with exit 0 (reproduced);
             * the JSON-boolean check below is the same class: the string
             * "false" is TRUTHY to empty(), so it embedded while reading
             * as no-embed (reproduced). Only an explicit JSON false or
             * absence opts out now.
             */
            $unknown_keys = array();
            foreach (array_keys($config) as $config_key) {
                if ('embed_shared' !== $config_key && 'namespace_suffix' !== $config_key) {
                    $unknown_keys[] = (string) $config_key;
                }
            }
            if ($unknown_keys !== array()) {
                throw new RuntimeException('build: ' . $slug . ': build.json carries unknown key(s) ' . implode(', ', $unknown_keys) . " — the closed schema is embed_shared (a JSON boolean) and namespace_suffix (a string); a typo would otherwise silently mean no-embed and ship a library-less zip with exit 0");
            }
            if (array_key_exists('embed_shared', $config) && ! is_bool($config['embed_shared'])) {
                throw new RuntimeException('build: ' . $slug . ': build.json embed_shared must be a JSON boolean (true or false) — ' . var_export($config['embed_shared'], true) . ' given (the string "false" is truthy to the old empty() check and embedded while reading as no-embed)');
            }
            /*
             * namespace_suffix is typed BEFORE any use (K2 / t31-r4-1):
             * the old (string) cast fed a JSON array through as 'Array'
             * — which PASSES the namespace-segment check and built under
             * a …\Array\Shared namespace (reproduced) — and a JSON
             * object fataled with an uncaught Error at the cast. A
             * present key must be a string AND a legal segment, whether
             * or not the embed is on (a present-but-invalid key is a
             * config error even when currently inert).
             */
            if (array_key_exists('namespace_suffix', $config)) {
                if (! is_string($config['namespace_suffix'])) {
                    throw new RuntimeException('build: ' . $slug . ': build.json namespace_suffix must be a string — ' . gettype($config['namespace_suffix']) . " given (a JSON array casts to 'Array', which passes the segment check and builds under a …\\Array\\Shared namespace; an object fataled with an uncaught Error at the cast)");
                }
                self::assertNamespaceSegment($config['namespace_suffix']);
            }
            /*
             * Duplicate keys refuse on the DECODED key (verifier round
             * t31-r4-17, whose raw-text fence this supersedes — review
             * round t31-r5-6): json_decode() keeps the LAST spelling
             * silently, so a divergent duplicate is the silent-no-embed
             * class; counting quoted RAW spellings let an escaped
             * duplicate ('{"embed_shared": true, "embed_shared":
             * false}' — the same key once decoded) last-win the config
             * into no-embed at exit 0 (verified on 8.5). The scan walks
             * the already-successfully-decoded text once, collecting
             * every TOP-LEVEL object key with its escapes resolved, so
             * any two spellings that decode alike refuse — the fence now
             * owns the class, not the spellings.
             */
            foreach (self::decodedTopLevelKeyCounts($rawConfig) as $decoded_key => $key_count) {
                if ($key_count > 1) {
                    throw new RuntimeException('build: ' . $slug . ": build.json carries the top-level key \"{$decoded_key}\" {$key_count} times — JSON keeps the last spelling silently (spellings that DECODE to the same key count as duplicates), and a divergent duplicate is the silent-no-embed class this seam refuses");
                }
            }
            $embedShared = true === ($config['embed_shared'] ?? false);
            if ($embedShared) {
                // Collect from shared/src ITSELF (review round t31-r2-12):
                // the old collection walked shared/ — the parent of the
                // source directory — shipping dev files (README.md et al.)
                // into plugin zips, and its global str_replace('src/', '')
                // mangled any nested 'src/' path segment. From $sharedDir
                // the relative paths need no strip at all: shared/src/X
                // maps onto src/Shared/X by construction.
                $sharedDir = dirname($distDir) . '/shared/src';
                if (! is_dir($sharedDir)) {
                    throw new RuntimeException("build: {$slug} requests shared code but {$sharedDir} does not exist");
                }
                /*
                 * The empty-tree fence (review round t31-r5-4): is_dir()
                 * alone let an empty (or source-less) shared/src embed
                 * NOTHING — a library-less zip built and published at
                 * exit 0 (reproduced; the collector also runs here so
                 * the symlink and casing doctrines fire at the seam,
                 * before any filesystem mutation, and the embed loop
                 * below reuses the walk instead of re-collecting).
                 */
                $sharedSources = wp_connectors_php_source_files($sharedDir);
                if ($sharedSources === array()) {
                    throw new RuntimeException("build: {$slug} requests the shared library but {$sharedDir} carries no PHP sources — a library-less zip is never silently built");
                }
                /*
                 * Autoloader cross-check (K2 / t31-r4-2): the suffix must
                 * agree with the prefix the plugin will actually map.
                 * The shipped autoloader (src/autoload.php, exactly the
                 * example connector's shape — one spl_autoload_register
                 * bound to the slug-derived prefix, enforced by
                 * wp_connectors_autoloader_violations() at this very
                 * gate) is the ONLY loader, and the build emits no
                 * autoloader of its own — so a custom suffix embedded
                 * the library under Deicod\WpConnectors\<Custom>\Shared,
                 * a namespace nothing loads: every gate stayed green and
                 * the plugin fataled on install (reproduced). The
                 * invariant is explicit now: an explicit namespace_suffix
                 * must equal the slug-derived segment, or the build
                 * refuses.
                 */
                $derivedSuffix = self::namespaceSuffixFromSlug($slug);
                $pluginSuffix = array_key_exists('namespace_suffix', $config) ? $config['namespace_suffix'] : $derivedSuffix;
                if ($pluginSuffix !== $derivedSuffix) {
                    /*
                     * The refusal's family spellings derive from the ONE
                     * owner (t31-ocr11-11) — display-only derivation, the
                     * logic already did: a hand-spelled family in the
                     * sentence drifts the day the owner's spelling changes
                     * (the r9-9 derivation doctrine, worn on diagnostics).
                     */
                    $suffix_segments = explode('\\', wp_connectors_shared_source_namespace());
                    $vendor_prefix = implode('\\', array_slice($suffix_segments, 0, -1));
                    $family_leaf = $suffix_segments[count($suffix_segments) - 1];
                    throw new RuntimeException("build: {$slug}: build.json namespace_suffix '{$pluginSuffix}' does not match the slug-derived autoloader prefix {$vendor_prefix}\\{$derivedSuffix}\\ the plugin ships (src/autoload.php binds it, the conventions gate enforces it, and the build emits no autoloader of its own) — the shared library would embed under {$vendor_prefix}\\{$pluginSuffix}\\{$family_leaf}, a namespace nothing loads: every gate green, the plugin fataled on install. Set namespace_suffix to '{$derivedSuffix}' or drop the key");
                }
                self::assertNamespaceSegment($pluginSuffix);
            }
        }

        $version = $headers['version'];
        if (! is_dir($distDir)) {
            mkdir($distDir, 0755, true);
        }
        $zipName = "connectors-{$slug}-{$version}.zip";
        $zipPath = $distDir . '/' . $zipName;

        /*
         * The publication seam (t31-r5-S, subsuming t31-r3-6, t31-r3-16,
         * t31-r4-3, and t31-r4-15): every byte of the artifact set is
         * produced and verified at a TEMP path first — the archive is
         * zipped, closed, and checksummed at a staging path; the sidecar
         * is written beside it; the manifest is merged and staged under a
         * unique tempnam — and the previous good release is replaced only
         * by checked RENAMES at the very end (the ARCHIVE FIRST, then
         * its descriptors — t31-ocr25-2: the thing the descriptors NAME
         * stands before any descriptor naming it moves, so a rename
         * refusal the pre-flight cannot see fires while NOTHING has
         * landed and the prior set stands byte-untouched). Every failure
         * this run can construct before the first rename then leaves the
         * prior artifact set byte-untouched BY CONSTRUCTION: the
         * half-built product lives at a temp path the finally below
         * releases on every exit, never at the destination. That deletes
         * the compensating apparatus the catch-based shape needed (the
         * $zipOpened/$zipOverwritten flags deciding which artifact
         * half-state a given throw had left behind, and the entry-scrub
         * catch whose own message-preservation bug history spans
         * t31-r3-16 → t31-r4-3 → this round); the one precondition the
         * renames owe — a landing target that is absent or a regular
         * file — is pre-flighted over all three targets BEFORE anything
         * lands, so the constructible landing blocker (a directory at a
         * destination path) also refuses with nothing landed. The renames
         * themselves are checked: a rename that fails after an earlier
         * one landed is loud (exit != 0), leaves every not-yet-landed
         * temp cleaned, and every landed member COMPLETE (each was
         * verified whole at its staging path — the half-written-member
         * class cannot exist on this side of the seam). Past the
         * pre-flight the honest boundary is the RENAME call itself
         * (EIO/ENOSPC, an AV lock, an immutable target), and the
         * archive-first order is what that boundary answers: the
         * artifact-refusal case lands nothing at all, while a descriptor
         * refusal leaves the new archive standing with the PRIOR
         * descriptors — stale, loudly failed, healed by the next
         * build's regeneration, never a checksum naming an artifact
         * that is not standing.
         */
        /*
         * The stage tree is PID-named (round t31-r10-4, the reopen the
         * t31-r5-11 ledger entry names): `.stage-<slug>` was SHARED
         * between concurrent builds of the same plugin, so run B's
         * startup/finally rrmdir deleted run A's in-flight stage tree
         * and A refused loudly on a spurious "cannot add … to" — build
         * survival, not artifact correctness (r5-11 adjudicated the
         * manifest, and called exactly this fix: "then the stage dir
         * wants the PID too"). `.stage-<slug>-<pid>` is unique per run;
         * the finally below releases exactly this run's tree, and the
         * startup sweep beside it reclaims only dead-PID orphans of the
         * SAME plugin — a live run's tree is never touched.
         *
         * The pid alone is PREDICTABLE, and the pre-mkdir sequence here
         * was check-then-act over that predictable spelling (OCR round
         * 42, t31-ocr42-2): the pid is enumerable (the t31-ocr10-2
         * note's own premise), the is_link probe and the sweep — a
         * full dist/ iteration WIDENING the race window — both read the
         * name before the recursive mkdir traversed it, so a planted
         * tree at the predicted spelling rode between the checks and
         * the mkdir (TOCTOU). The name owns an UNPREDICTABLE component
         * now, the mkdtemp-style random suffix (the ocr10-2 model, the
         * workDir sibling's own spelling): a planted intermediate
         * cannot predict the target, and with it the same-name reclaim
         * arm below is gone — a directory at this exact random spelling
         * is never this run's leftover, so reclaiming it would delete
         * foreign territory (the cross-run-destruction class, the
         * ocr11-16 note's own), and the checked mkdir below already
         * owns the collision with a loud refusal, nothing deleted.
         */
        $stage = $distDir . '/.stage-' . $slug . '-' . getmypid() . '-' . bin2hex(random_bytes(8));
        if (is_link($stage)) {
            // A LINK at this run's own stage name is never this code's
            // product (the build mkdirs real directories) — deleting
            // through it would destroy the TARGET tree (verifier round
            // t31-r10-10), and building through it would scatter the
            // stage into a tree the build does not own. Refuse loudly.
            throw new RuntimeException("build: {$stage} is a symlink — the staging tree must be a real directory this build owns; remove the link");
        }
        self::sweepStaleStageDirs($distDir, $slug);
        // @: the diagnostic is suppressed, the failed return owned below
        // (glm17-16) — the refusal is the build's own message.
        if (! @mkdir($stage . '/' . $slug, 0755, true)) {
            throw new RuntimeException("build: cannot create the staging tree {$stage}/{$slug} — a failed staging mkdir refuses the build, never packs into a tree it does not own");
        }

        /*
         * THE STAGING FAMILY'S RANDOM-SUFFIX CENSUS (OCR round 43,
         * t31-ocr43-2 — the r42-2 twin sweep that never landed then):
         * the zip temp and its sidecar twin were the last staging
         * names still PID-PREDICTABLE, in the same method that gave
         * $stage its bin2hex(random_bytes(8)) suffix precisely
         * because "the pid alone is PREDICTABLE … TOCTOU" — the pid
         * is enumerable, ZipArchive::open() writes THROUGH whatever
         * stands at the spelled path, and the sweep (which skips
         * links and its own pid) never fences a planted tree at the
         * predicted spelling. Both temps ride the whole family's one
         * doctrine now: an unpredictable suffix per name (the sidecar
         * inherits its twin's), nothing pre-plantable, no check-
         * then-act window of the build's own making — a fresh random
         * name has no history to collide with, so unlike $stage (a
         * full-dist check-then-act sweep it needed the belt for)
         * these seams carry no link fence to keep. The sweep's tail
         * pattern rides the suffix tail-optionally below, so stale
         * temps of BOTH spellings still reclaim by the dead-pid gate
         * alone.
         */
        $zipTemp = $distDir . '/.' . $zipName . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(8));
        $sidecarTemp = $zipTemp . '.sha256';
        $manifestPath = $distDir . '/checksums.txt';
        $manifestTemp = false;
        $manifestLock = null;
        try {
            $licenseFile = dirname($distDir) . '/LICENSE';
            $entries = array();
            foreach (self::collectFiles($pluginDir) as $relative) {
                self::copyNormalized($pluginDir . '/' . $relative, $stage . '/' . $slug . '/' . $relative);
                $entries[] = $slug . '/' . self::zipEntryPath($relative);
            }
            /*
             * The repo LICENSE injects only where the plugin does not
             * already own the destination — CASE-INSENSITIVELY (review
             * round t31-r6-1, folding the check into the collision
             * doctrine the embed fence below established in
             * t31-r5-16): the exact-case in_array let a case-variant
             * plugin 'license'/'License' ship BESIDE the injected
             * 'LICENSE' — both entries in the zip, inspection green,
             * and on a case-insensitive extraction target the plugin's
             * copy extracted second (sort order) and silently
             * overwrote the repo license (reproduced). One doctrine,
             * two territories, one comparison — the ONE ASCII fold
             * (wp_connectors_ascii_lower) over the collected entries,
             * never strcasecmp(): that fold consults the engine's
             * locale mapping (the r11-6/ocr10-4 doctrine — on a
             * locale-consulting engine a Turkish tolower('I') = 0xFD
             * reads 'LICENSE' as not-the-plugin's-license and injects
             * the repo copy beside it): generated destinations REFUSE a
             * plugin-owned collision, while this injected convenience
             * DEFERS to the plugin's own file — its license wins in
             * any casing and the repo copy is never injected beside
             * it, so the both-FILES overwrite is unconstructible.
             * (Honest boundary, verifier round t31-r6: both collision
             * fences compare collected FILE entries only — a
             * case-folding plugin DIRECTORY beside an injected or
             * generated file, 'license/notes.txt' next to the
             * injected LICENSE, still ships and fails extraction as a
             * file-vs-directory conflict on folding targets; the same
             * blind spot the r5-16 embed fence carries. Ledgered with
             * the zip-wide prefix-folding class for a future round.)
             */
            if (is_file($licenseFile)) {
                $pluginOwnsLicense = false;
                $folded_license_destination = wp_connectors_ascii_lower($slug . '/LICENSE');
                foreach ($entries as $existing_entry) {
                    if (wp_connectors_ascii_lower($existing_entry) === $folded_license_destination) {
                        $pluginOwnsLicense = true;

                        break;
                    }
                }
                if (! $pluginOwnsLicense) {
                    self::copyNormalized($licenseFile, $stage . '/' . $slug . '/LICENSE');
                    $entries[] = $slug . '/LICENSE';
                }
            }

            // Embed the shared OAuth library when the plugin opts in (the
            // build.json the opt-in rode was already validated at the config
            // seam above — no decode, no embed decision, happens down here).
            // The file vocabulary is the ONE shared-source collector: every
            // PHP source under shared/src ships, wherever it lives — the
            // dist-tree exclusion list deliberately does NOT apply here
            // (t31-r3-4), and non-PHP files are not sources (t31-r2-18).
            if ($embedShared) {
                foreach ($sharedSources as $relative) {
                    /*
                     * Destination collision REFUSES the build (review
                     * round t31-r5-1): a plugin that owns a file at an
                     * embed destination had its copy silently REPLACED
                     * by the generated one — the author's class
                     * overwritten, no warning, exit 0 (reproduced). The
                     * plugin owning a Shared path is a configuration
                     * mistake (src/Shared is generated by embed_shared),
                     * not something to override. The comparison is
                     * CASE-INSENSITIVE (verifier round t31-r5-16): a
                     * byte-exact fence let a case-variant plugin path
                     * ('src/shared/…') ship BOTH entries, and on a
                     * case-insensitive extraction target the author's
                     * un-rewritten copy extracts SECOND (sort order) and
                     * silently overwrites the generated, sweep-gated
                     * embed — the same defect one case-folding away
                     * (adversarially confirmed).
                     *
                     * The destination itself rides the ONE prefix owner
                     * (t31-r12-10): the embed-destination prefix helper
                     * is the single spelling of the embed territory,
                     * consumed by this loop and judged by the inspector
                     * through the same owner's fold. The collision fold
                     * is the ONE ASCII owner's too (t31-ocr13-1):
                     * strcasecmp() consults the engine's locale mapping
                     * (the r11-6/ocr10-4 doctrine), and this comparison
                     * feeds the build's verdict — a locale-consulting
                     * fold is a fold whose verdict is a question about
                     * the process, never a constant of the artifact.
                     */
                    $destination = wp_connectors_embed_destination_prefix($slug) . self::zipEntryPath($relative);
                    $folded_destination = wp_connectors_ascii_lower($destination);
                    foreach ($entries as $existing_entry) {
                        if (wp_connectors_ascii_lower($existing_entry) === $folded_destination) {
                            throw new RuntimeException("build: {$slug} owns {$existing_entry} — a case-insensitive collision with the generated embed copy {$destination}; src/Shared/ is build-generated (build.json embed_shared), so remove or rename the plugin's own file");
                        }
                    }
                    $source = self::readSharedSource($sharedDir, $relative);
                    $rewritten = self::rewriteSharedNamespace($source, $pluginSuffix, 'shared/src/' . $relative);
                    $target = $stage . '/' . wp_connectors_embed_destination_prefix($slug) . $relative;
                    @mkdir(dirname($target), 0755, true);
                    self::writeNormalized($rewritten, $target);
                    $entries[] = $destination;
                }
            }

            /*
             * The build's self-containment gate over the COMPOSED
             * artifact tree (review round t31-r9-2): the pre-gate at
             * the top of buildPlugin() judged the plugin DIRECTORY
             * alone, so an escaping include appended to a shared source
             * built and published at exit 0 while the inspector —
             * which scans the extracted zip, embedded src/Shared
             * subtree included — refused the same artifact (reproduced;
             * the one-verdict doctrine broken on the publish path, and
             * distinct from the r8-noted curation item: the WP-reach
             * vocabularies stay a ledgered dev-time design decision,
             * while this channel the inspector already judged). The
             * SAME gate, wp_connectors_self_containment_violations(),
             * runs over the staged tree the zip will pack, at the
             * staging path (the t31-r5-S doctrine: every byte verified
             * at its temp path, the previous good release untouched),
             * and the scan runs only when an embed composed something
             * the pre-gate had not already judged byte-for-byte:
             * without $embedShared the staged tree is a plain copy of
             * the pre-gated plugin files (plus the non-PHP LICENSE).
             *
             * The WALK is scoped to the embed destination subtree
             * (t31-r12-12, the ONE prefix owner) while the ANCHOR
             * stays the composed tree root: the plugin files beside
             * the subtree are byte-copies the pre-gate already judged
             * against the same anchor, so re-walking them re-tokenized
             * identical bytes for an identical verdict. The anchor
             * NEVER narrows with the walk — an include anchored at the
             * plugin root above the subtree is inside the artifact and
             * stays legal, exactly as the full-tree walk and the
             * inspector judged it.
             */
            if ($embedShared) {
                $stagedViolations = wp_connectors_self_containment_violations(
                    $stage . '/' . $slug,
                    $stage . '/' . wp_connectors_embed_destination_prefix($slug)
                );
                if ($stagedViolations !== array()) {
                    throw new RuntimeException("build: refusing to package {$slug} — the self-containment gate over the composed artifact tree (embedded src/Shared included):\n - " . implode("\n - ", $stagedViolations));
                }
            }

            sort($entries, SORT_STRING);

            // The archive itself is built at its staging path (fixed
            // order, mtimes already normalized): open() here can only
            // fail on the temp path, never on the previous good zip.
            $zip = new ZipArchive();
            if (true !== $zip->open($zipTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                throw new RuntimeException("build: cannot create the staging archive {$zipTemp} for {$zipName}");
            }
            try {
                foreach ($entries as $entry) {
                    if (true !== $zip->addFile($stage . '/' . $entry, $entry)) {
                        throw new RuntimeException("build: cannot add {$entry} to {$zipName}");
                    }
                }
            } catch (RuntimeException $addFailure) {
                // Release the half-written staging archive before its
                // removal; the finally below owns that. (A close() that
                // fails here is equally fatal — the temp goes either way.)
                $zip->close();
                throw $addFailure;
            }
            /*
             * Finalization is CHECKED (t31-r4-3): close() writes the
             * archive and returns FALSE on a failed write (an unreadable
             * staged source at read time — libzip defers the reads — or
             * a staging destination that stopped accepting). The old
             * unchecked close() let the failure fall through to
             * hash_file() on a zip that was not written and a
             * blank-checksum sidecar with exit 0. The failure now
             * happens at the TEMP path: the finally releases it and the
             * previous good artifact set was never touched.
             */
            self::closeArchiveOrThrow($zip, $zipName);

            $checksum = hash_file('sha256', $zipTemp);
            if (false === $checksum) {
                throw new RuntimeException("build: cannot checksum {$zipName} — refusing to publish a sidecar for an artifact that cannot be read");
            }
            // The @ suppresses only the diagnostic (the errno notice of
            // the failed write); the FAILED RETURN is owned below — the
            // glm17-16 idiom, so a blocked path refuses through this
            // check instead of aborting through the engine's warning.
            if (false === @file_put_contents($sidecarTemp, $checksum . '  ' . $zipName . "\n")) {
                throw new RuntimeException("build: cannot write the checksum sidecar for {$zipName} — a failed artifact write never exits 0 with a half-described artifact set");
            }
            /*
             * The manifest merge is a read-modify-write of a SHARED file
             * (verifier round t31-r5-11): t31-r5-S's unique temp names
             * closed the WRITE interleave, but two concurrent builds of
             * DIFFERENT plugins could still read the same pre-merge
             * manifest and the last landing silently DROPPED the other
             * run's entry — both runs exiting 0 while the manifest
             * described only one (adversarially confirmed, 30/72
             * synchronized trials). The whole read→land span now holds
             * an exclusive flock on a dedicated lock file: a concurrent
             * run blocks here and merges from the LANDED state instead
             * of racing it. The lock file is persistent dist furniture
             * (the coordination primitive, not an artifact).
             */
            $manifestLock = @fopen($distDir . '/.checksums.lock', 'c');
            if (false === $manifestLock) {
                throw new RuntimeException("build: cannot open the checksum manifest lock {$distDir}/.checksums.lock — a merge that cannot be made safe refuses");
            }
            if (! @flock($manifestLock, LOCK_EX)) {
                fclose($manifestLock);
                throw new RuntimeException("build: cannot lock the checksum manifest for {$zipName} — a failed lock never merges blindly");
            }
            $manifestTemp = self::stageManifest($distDir, $manifestPath, $zipName, $checksum);

            // Pre-flight every landing target before anything lands: the
            // one constructible rename blocker is a non-file at a
            // destination, and it must refuse while the prior set is
            // still whole (a failure one landing later would strand a
            // descriptor beside the old release it does not describe).
            foreach (array( $zipPath, $zipPath . '.sha256', $manifestPath ) as $landingTarget) {
                if (file_exists($landingTarget) && ! is_file($landingTarget)) {
                    throw new RuntimeException("build: cannot publish {$zipName} — the landing target {$landingTarget} is not a regular file");
                }
            }

            /*
             * Landing: the archive FIRST, then its descriptors (OCR
             * round 25, t31-ocr25-2 — the ordering the rename refusal
             * the pre-flight cannot rule out forced). The pre-flight
             * above rules out non-file targets only; the rename itself
             * can still refuse at the call (EIO, ENOSPC, an AV lock,
             * an immutable target), and landing descriptors FIRST
             * stranded exactly that refusal at the archive: the NEW
             * sidecar and the NEW manifest entry standing beside the
             * OLD zip — a checksum describing a release that is not
             * the artifact standing beside it, with verification
             * failing against the standing zip and no later build
             * obligated to heal it (the manifest regenerates an
             * entry for a zip that exists, never re-derives the
             * sidecar's checksum for a zip that vanished). The
             * artifact lands first now — the thing the descriptors
             * NAME stands before any descriptor naming it moves: the
             * archive rename refuses while NOTHING has landed (the
             * prior set whole, byte-identical), and a mid-sequence
             * descriptor refusal leaves only descriptors that name a
             * STANDING artifact (the prior checksum, stale beside the
             * new archive — loudly failed, healed by the next build's
             * own regeneration contract — never a checksum naming an
             * artifact that is not standing).
             */
            self::landArtifact($zipTemp, $zipPath, "the archive {$zipName}");
            self::landArtifact($sidecarTemp, $zipPath . '.sha256', "the checksum sidecar for {$zipName}");
            self::landArtifact($manifestTemp, $manifestPath, "the checksum manifest for {$zipName}");
        } finally {
            if (is_resource($manifestLock)) {
                @flock($manifestLock, LOCK_UN);
                @fclose($manifestLock);
            }
            self::rrmdir($stage);
            @unlink($zipTemp);
            @unlink($sidecarTemp);
            if (false !== $manifestTemp) {
                @unlink($manifestTemp);
            }
        }

        return $zipPath;
    }

    /**
     * Stages the merged checksum manifest at a UNIQUE temp path (t31-r5-S
     * over t31-r4-8's atomic writer).
     *
     * The manifest stays per-run-atomic — a run updates only the entries
     * of the plugin(s) it built (manifestLinesWithout() keeps every other
     * line byte-for-byte while its artifact exists beside the manifest,
     * and drops the stale remainder per t31-r12-7) and lands whole — but
     * the staging file is now
     * tempnam()-unique: the fixed '<manifest>.tmp' spelling made two
     * concurrent builds interleave their stage writes (the round's
     * two-process race), whichever rename landed last shipping a mix of
     * both runs' lines. A unique stage breaks the interleaving; the final
     * rename is still atomic, so a crash can never leave a half-written
     * manifest behind, and never deletes one either (the t31-r4-8
     * no-pre-run-wipe contract, unchanged). tempnam() creates 0600; the
     * manifest is a published artifact and lands 0644 like the sidecar.
     *
     * The staging name carries the PID (OCR round 26, t31-ocr26-8):
     * every other staging temp the build lands rides the crashed-run
     * charter (`.stage-<slug>-<pid>-<rand>`, `.<zip>.tmp-<pid>…` — sweepStale
     * -StageDirs reclaims a dead run's leftovers), while the pid-less
     * tempnam spelling this method used was the carve its own sweep
     * doc note named unattributable: a SIGKILL between staging and
     * the landing rename left `.checksums-XXXXXX` forever, reclaiming
     * one could race a LIVE run's staging. `.checksums-<pid>-<rand>`
     * rides the same charter — the sweep's pattern and liveness gate
     * own it now (a legacy pid-less spelling stays alone, still
     * unattributable).
     *
     * @param string $distDir      Absolute dist directory (staging home).
     * @param string $manifestPath Absolute checksums.txt path.
     * @param string $zipName      Zip basename the new entry names.
     * @param string $checksum     The staged archive's SHA-256.
     * @return string The staging path (caller lands it by rename).
     * @throws RuntimeException When the manifest cannot be staged.
     */
    private static function stageManifest($distDir, $manifestPath, $zipName, $checksum)
    {
        $manifest = self::manifestLinesWithout($manifestPath, $zipName);
        $manifest[] = $zipName . '  ' . $checksum;
        sort($manifest, SORT_STRING);
        // @: the diagnostic is suppressed, the failed return owned below
        // (glm17-16) — a blocked path refuses through the check. The
        // PID prefix (t31-ocr26-8, the sweep's own naming doctrine):
        // tempnam() appends its random tail AFTER the prefix, so the
        // name answers to a process — dead runs swept, live runs never
        // raced.
        $temp = @tempnam($distDir, '.checksums-' . getmypid() . '-');
        if (false === $temp) {
            throw new RuntimeException("build: cannot stage the checksum manifest for {$zipName} in {$distDir}");
        }
        if (false === @file_put_contents($temp, implode("\n", $manifest) . "\n")) {
            @unlink($temp);
            throw new RuntimeException("build: cannot write the checksum manifest staging file {$temp}");
        }
        if (! @chmod($temp, 0644)) {
            @unlink($temp);
            throw new RuntimeException("build: cannot normalize the permissions of the checksum manifest staging file {$temp}");
        }

        return $temp;
    }

    /**
     * Lands a fully-staged artifact member at its destination by rename
     * (t31-r5-S): the caller pre-flighted the target (absent or a
     * regular file), so this is a metadata move of bytes that were
     * already written and verified — the last instant at which the
     * previous good member can be replaced, and the first at which the
     * new one exists at its published path.
     *
     * @param string $temp  The staging path (gone after the rename).
     * @param string $final The destination path.
     * @param string $what  Human label (diagnostics).
     * @return void
     * @throws RuntimeException When the rename fails.
     */
    private static function landArtifact($temp, $final, $what)
    {
        // @: the diagnostic is suppressed, the failed return owned below
        // (glm17-16) — the failure is loud, never an engine warning.
        if (! @rename($temp, $final)) {
            throw new RuntimeException("build: cannot land {$what} at {$final} — every byte was verified at the staging path {$temp} and the publication rename refused");
        }
    }

    /**
     * The manifest's lines minus one zip's entry (and blanks).
     *
     * The ONE entry filter the publication merge rides (verifier round
     * t31-r3-16): a run rewrites only its own zip's entry and keeps
     * every other line byte-for-byte — SO LONG AS its artifact still
     * sits beside the manifest (t31-r12-7's regeneration prune: an
     * entry whose zip no longer exists beside checksums.txt is the
     * stale line the header's "regenerated" contract drops, never a
     * fact to preserve). (The mid-build failure-path consumer — the
     * entry scrub that removed a corrupted artifact's entry after the
     * fact — died with t31-r5-S: a failure now never touches the
     * manifest the run did not land.)
     *
     * @param string $manifestPath Absolute checksums.txt path.
     * @param string $zipName      Zip basename the entry names.
     * @return list<string> The surviving lines.
     */
    private static function manifestLinesWithout($manifestPath, $zipName)
    {
        $manifest = array();
        if (is_file($manifestPath)) {
            /*
             * The read is OWNED (verifier round t31-r5-13): the (string)
             * cast laundered a failed read (a chmod-000 manifest) into an
             * EMPTY line set, so the merge landed a manifest carrying
             * only this run's entry — every other plugin's checksum
             * silently destroyed at exit 0 (adversarially confirmed).
             * An unreadable manifest refuses the build instead; the
             * refusal precedes every landing, so the unreadable file
             * itself is left exactly as found.
             */
            // @: the diagnostic is suppressed, the failed return owned
            // below (glm17-16).
            $raw = @file_get_contents($manifestPath);
            if (false === $raw) {
                throw new RuntimeException("build: cannot read the checksum manifest {$manifestPath} — an unreadable manifest refuses the build, never silently drops every other plugin's entry");
            }
            foreach (explode("\n", $raw) as $line) {
                if ($line === '' || strpos($line, $zipName . '  ') === 0) {
                    continue;
                }
                /*
                 * Regeneration DROPS entries whose artifact no longer
                 * sits beside the manifest (review round t31-r12-7,
                 * making behavior match the header's own "checksums.txt
                 * is regenerated" contract): the merge once kept every
                 * other line byte-for-byte forever, so a connector whose
                 * zip was deleted out-of-band left a line naming an
                 * artifact that no longer exists — checksum verification
                 * then failed on every future check while every build
                 * exited 0 (reproduced). A malformed line (no
                 * 'name  checksum' shape) names no artifact and dies by
                 * the same rule. The prune runs INSIDE the merge lock,
                 * so a concurrent run only ever prunes against the
                 * LANDED artifact set.
                 *
                 * The split finds the separator FROM THE RIGHT (OCR
                 * round 14, t31-ocr14-3): the writer joins
                 * name . '  ' . checksum (64 hex bytes, no spaces), and
                 * strstr()'s FIRST-gap split read an entry name
                 * containing a double space ('double  space.zip') as
                 * 'double' — pruning a LIVE entry as stale (or keeping
                 * nothing at all where the tail named no file). The
                 * writer's own join shape is the anchor: the last '  '
                 * is the separator it wrote, whatever the name carries.
                 */
                $separator = strrpos($line, '  ');
                $entry_name = false === $separator ? false : substr($line, 0, $separator);
                if (false === $entry_name || ! is_file(dirname($manifestPath) . '/' . $entry_name)) {
                    /*
                     * The prune RECLAIMS the entry's sidecar with its
                     * line (OCR round 35, t31-ocr35-2): the r12-7
                     * prune made the manifest an inventory of STANDING
                     * artifacts, but a zip deleted out-of-band left its
                     * dist/<zip>.sha256 standing — a checksum naming a
                     * non-standing artifact, the exact class the
                     * landing order closed, one member over. The
                     * reclaim stays INSIDE the merge lock (a concurrent
                     * run only ever prunes against the LANDED artifact
                     * set) and fences itself to a plain FILE name
                     * beside the manifest: a malformed line names no
                     * artifact (never '' — a line leading with the
                     * separator), a traversal-woven name never matches
                     * its basename so nothing outside the manifest's
                     * own directory is ever a target, and a LINK is
                     * never deleted through (the sweep's own
                     * no-symlinks doctrine — unlink removes the entry
                     * itself). A sidecar the unlink cannot remove
                     * stays standing beside a dropped line — the
                     * manifest's contract (names standing artifacts)
                     * holds regardless; the sweep owns the rest.
                     */
                    if (false !== $entry_name && '' !== $entry_name && $entry_name === basename((string) $entry_name)) {
                        $sidecar = dirname($manifestPath) . '/' . $entry_name . '.sha256';
                        if (is_file($sidecar) && ! is_link($sidecar)) {
                            // A FILE, never a link (the guard above):
                            // unlink removes the entry itself.
                            @unlink($sidecar);
                        }
                    }

                    continue;
                }
                $manifest[] = $line;
            }
        }

        return $manifest;
    }

    /**
     * Derives the plugin namespace suffix from the slug (openai-oauth -> OpenAiOauth).
     *
     * Delegates to the ONE shared derivation in bin/lib/plugin-tools.php so
     * build, conventions, and the test bootstrap can never disagree (the
     * acronym casing, e.g. OpenAi, is preserved there).
     *
     * @param string $slug Plugin slug.
     * @return string
     */
    public static function namespaceSuffixFromSlug($slug)
    {
        return wp_connectors_namespace_suffix_from_slug($slug);
    }

    /**
     * The published archive's SHA-256 — the success line's own guarded
     * hash (review round t31-r12-6).
     *
     * The CLI's success echo interpolated hash_file() unchecked, so a
     * false return — the zip unreadable in the window between
     * buildPlugin() returning and the echo (deleted or chmod-000 out of
     * band) — printed 'sha256=' BLANK at exit 0: the exact blank-digest
     * conflation this file already refuses for the sidecar at its own
     * checksum step. Same guarded shape here: a hash failure refuses
     * loudly (the CLI's catch prints the reason and exits non-zero),
     * and the human-facing line never lies.
     *
     * @param string $zipPath Absolute path of the published zip.
     * @return string The lowercase hex SHA-256.
     * @throws RuntimeException When the published artifact cannot be read for hashing.
     */
    public static function publishedChecksum($zipPath)
    {
        // @: the diagnostic is suppressed, the failed return owned below
        // (glm17-16) — the refusal is the build's own message.
        $checksum = @hash_file('sha256', $zipPath);
        if (false === $checksum) {
            throw new RuntimeException("build: cannot checksum the published {$zipPath} — refusing to print a success line whose digest is blank (the artifact was unreadable at echo time)");
        }

        return $checksum;
    }

    /**
     * Finalizes the archive, refusing the build when finalization fails
     * (review round t31-r4-3; restructured by t31-r5-S).
     *
     * ZipArchive::close() is the step that WRITES the archive — libzip
     * defers the staged sources' reads here — and it returns false on a
     * failed write (an unreadable staged source, a destination that
     * stopped accepting). The unchecked $zip->close() once let that
     * failure fall through to hash_file() on a zip that was never
     * written and a blank-checksum sidecar with exit 0 — after OVERWRITE
     * had already destroyed the previous good zip at the destination.
     * The archive is produced at its STAGING path now, so a failed
     * close() leaves a temp file the finally releases and the previous
     * good artifact set untouched by construction.
     *
     * A failed close() tears the archive object down with it (a second
     * close() is a ValueError on this runtime, empirically confirmed),
     * so the caller never re-closes on this path.
     *
     * Private and seam-shaped so the pin can drive a REAL failed
     * close() through it (a staged source that is unreadable at read
     * time — the deterministic external spelling on this runtime).
     *
     * @param ZipArchive $zip     The open archive.
     * @param string     $zipName Zip basename (diagnostics only).
     * @return void
     * @throws RuntimeException When the archive cannot be finalized.
     */
    private static function closeArchiveOrThrow($zip, $zipName)
    {
        if (true !== $zip->close()) {
            throw new RuntimeException("build: cannot finalize {$zipName} — writing the archive failed (a staged source or the destination became unreadable mid-write); refusing instead of checksumming a zip that was never written");
        }
    }

    /**
     * Copies a file into the staging tree with normalized mtime/perms.
     *
     * The read is OWNED (review round t31-r5-2): copy()'s silent false
     * shipped an unreadable plugin file as a 0-byte zip entry at exit 0
     * (the t31-r3 verifier note's shape — the natural fix shape it
     * named, a loud read seam at the collection point, applied here).
     * The @ suppresses only the diagnostic (the errno notice of the
     * failed read); the FAILED RETURN is owned below (glm17-16).
     *
     * @param string $from Absolute source path.
     * @param string $to   Absolute target path.
     * @return void
     * @throws RuntimeException When the source cannot be read.
     */
    private static function copyNormalized($from, $to)
    {
        @mkdir(dirname($to), 0755, true);
        if (! @copy($from, $to)) {
            throw new RuntimeException("build: cannot copy {$from} into the staging tree — an unreadable plugin file refuses the build, never ships as a 0-byte entry");
        }
        self::normalize($to);
    }

    /**
     * The embed collection's loud read seam (review round t31-r5-2).
     *
     * A failed file_get_contents() laundered through (string) shipped
     * an unreadable shared source as a 0-byte PHP file — the survivor
     * scan passes on empty bytes, so the sidecar and manifest
     * published a library-less zip at exit 0 (sanctioned reopen of the
     * t31-r3 verifier note, reproduced as non-root with chmod 000).
     * The empty twin rides the same seam: a whitespace-only source
     * rewrites to '' without throwing (no open tag → no provenance),
     * the same silent ship without a read failure at all. Both refuse
     * the build loudly now, naming the file.
     *
     * @param string $sharedDir Absolute shared/src root.
     * @param string $relative  The source's shared/src-relative path.
     * @return string The source bytes.
     * @throws RuntimeException When the source cannot be read or carries no bytes.
     */
    private static function readSharedSource($sharedDir, $relative)
    {
        // @: the diagnostic is suppressed, the failed return owned below
        // (glm17-16) — the refusal is the build's own message.
        $source = @file_get_contents($sharedDir . '/' . $relative);
        if (false === $source) {
            throw new RuntimeException("build: cannot read the shared source {$sharedDir}/{$relative} — an unreadable shared source refuses the build, never ships as a 0-byte library file");
        }
        if ('' === trim($source)) {
            throw new RuntimeException("build: the shared source shared/src/{$relative} carries no bytes — an empty (or whitespace-only) source refuses the build, never ships as a 0-byte library file");
        }

        return $source;
    }

    /**
     * Writes content into the staging tree with normalized mtime/perms.
     *
     * Review round t31-r7-3: the write is CHECKED and the staged bytes
     * VERIFIED. file_put_contents()'s return was ignored, so a short or
     * failed write staged a truncated PHP file that zip close() happily
     * packed and published at exit 0 — making the t31-r5-S claim
     * ("every landed member was verified whole at its staging path")
     * FALSE for generated members (copied plugin files already ride
     * copyNormalized's checked copy; the archive is hashed after the
     * fact, but a truncated MEMBER inside a successfully written zip
     * checksums fine). The write layer's own word is checked now —
     * false, or a byte count short of the content (PHP folds a short
     * total into false with its "Only X of Y bytes written" diagnostic,
     * which the @ suppresses per the glm17-16 idiom) — and what LANDED
     * is re-read and length-compared, so the verified-whole claim holds
     * at the staging path for generated members exactly as it already
     * did for copied ones.
     *
     * @param string $content File content.
     * @param string $to      Absolute target path.
     * @return void
     * @throws RuntimeException When the write refuses, falls short, or
     *         lands fewer bytes than it was handed.
     */
    private static function writeNormalized($content, $to)
    {
        @mkdir(dirname($to), 0755, true);
        // @: the diagnostic is suppressed, the failed return owned below
        // (glm17-16) — the refusal is the build's own message.
        $written = @file_put_contents($to, $content);
        $expected = strlen($content);
        if (false === $written || $written !== $expected) {
            throw new RuntimeException(sprintf(
                'build: cannot write the generated file %s whole — %d bytes expected, %s; a truncated generated file never enters the archive',
                $to,
                $expected,
                false === $written ? 'the write refused or fell short (the write layer reported failure)' : "{$written} written"
            ));
        }
        /*
         * The staged-bytes verification (the r5-S claim made true): the
         * write layer reported success — the FILESYSTEM's word is the
         * size on disk, re-read past the stat cache. A filesystem that
         * accepted the bytes but kept fewer (an ENOSPC flush, a
         * truncated overlay) refuses here, before the archive opens.
         */
        clearstatcache(true, $to);
        $staged = filesize($to);
        if (false === $staged || $staged !== $expected) {
            throw new RuntimeException(sprintf(
                'build: the generated file %s did not land whole — %d bytes expected, %s on disk; a truncated generated file never enters the archive',
                $to,
                $expected,
                false === $staged ? 'the staged file is unreadable' : "{$staged} bytes"
            ));
        }
        self::normalize($to);
    }

    /**
     * Normalizes mtime and permissions of a staged file.
     *
     * @param string $path Absolute path.
     * @return void
     */
    private static function normalize($path)
    {
        chmod($path, 0644);
        touch($path, self::FIXED_MTIME);
    }

    /**
     * Recursively removes a directory.
     *
     * The no-symlinks doctrine lives HERE, at the one removal seam
     * (verifier round t31-r10-10's class, single-owner hardening OCR
     * round 1, t31-ocr1-3): the stage seams around it refuse or skip
     * links, but the finally's teardown is reachable with a LINK at
     * the stage name — a mid-build swap of the stage directory for a
     * link — and is_dir() FOLLOWS links, so the iterator constructed
     * on the linked path walks the TARGET tree and the loop empties
     * it. A link at the removal root is never deleted through: the
     * call returns, and the link stands exactly where it is.
     *
     * @param string $dir Absolute directory path.
     * @return void
     */
    private static function rrmdir($dir)
    {
        if (is_link($dir)) {
            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                /*
                 * A LINK entry is removed AS ITSELF (unlink removes the
                 * link, never its target): isDir() follows links, so a
                 * linked child would otherwise take the rmdir branch. The
                 * walk does not descend into linked children (no
                 * FOLLOW_SYMLINKS flag), so their target trees stand
                 * untouched.
                 */
                if ($item->isDir() && ! $item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
        } catch (UnexpectedValueException $walk_refusal) {
            /*
             * A subdirectory the iterator cannot OPEN mid-recursion
             * aborts the walk (glm31-4's class, fenced at the shared
             * scan one file over) — and this owner runs from the
             * finally teardown (and the startup reclaim beside it),
             * where an exception in a finally REPLACES the primary
             * failure in flight: the build answered the teardown's SPL
             * vocabulary instead of its own refusal (OCR round 23,
             * t31-ocr23-1, driven both arms). The teardown's contract
             * is the SILENT degrade (the glob() fence's own shape: an
             * engine refusal becomes "nothing more to remove", never a
             * verdict): the partial removal stands, the unopened
             * subtree stays for the sweep's next run, and the primary
             * verdict surfaces untouched. The CONSTRUCTION rides the
             * same guard (the round's verifier pass, rd-1): the
             * iterator is built LAZILY on the removal root itself, and
             * a root this process cannot open throws from the
             * constructor — one shape over the walk's refusal, the
             * same class through the same channel (driven at HEAD
             * through the reflection seam).
             */
            return;
        }
        rmdir($dir);
    }

    /**
     * Reclaims this plugin's stale stage trees before a new build stages
     * its own (round t31-r10-4).
     *
     * The PID-named stage (`.stage-<slug>-<pid>-<rand>` — the random
     * suffix t31-ocr42-2, unpredictable never pre-plantable; a legacy
     * pid-only spelling from a pre-fix crashed run still matches the
     * same tail-optional pattern) makes concurrent builds of the same
     * plugin disjoint, at the cost of a crashed run leaving its scratch
     * behind — the sweep closes that (verifier round t31-r10-13: the
     * r10-4 sweep reclaimed only the STAGE tree while the same crash
     * also left `.<zip>.tmp-<pid>` temps forever):
     *
     * - every `.stage-<slug>-<pid>[-<rand>]` DIRECTORY whose process
     *   is dead is removed; a LIVE run's tree is never touched;
     * - every `.connectors-<slug>-….zip.tmp-<pid>…` FILE (the zip temp,
     *   its sidecar twin `.sha256`, and libzip's in-window `.<rand>.part`
     *   spelling a SIGKILL leaves behind) whose process is dead is
     *   unlinked — the same crashed-run charter, the same liveness gate;
     * - every `.checksums-<pid>-…` FILE (the manifest staging temp,
     *   pid-named since OCR round 26, t31-ocr26-8) whose process is
     *   dead is unlinked on the same charter — a SIGKILL between the
     *   staging and the landing rename once left it forever, the one
     *   crashed-run scratch the sweep's own doc note carved out as
     *   unattributable;
     * - a SYMLINK never is touched (verifier round t31-r10-10: is_dir()
     *   follows links, and rrmdir through a matching-named link deleted
     *   the TARGET tree's contents — a link is never this code's
     *   product, and the sweep leaves it exactly where it stands);
     * - foreign-shaped names (the pid-less pre-r10 stage spelling, the
     *   pid-less legacy `.checksums-*` manifest temps — unattributable,
     *   so reclaiming one could race a LIVE run's staging) are left
     *   alone: nothing running this code loses by them.
     *
     * A dead pid REUSED by an unrelated live process keeps its orphan
     * until that process dies (the conservative direction: never delete
     * a possibly-live run's scratch).
     *
     * @param string $distDir Absolute dist directory (staging home).
     * @param string $slug    Plugin slug whose scratch gets swept.
     * @return void
     */
    private static function sweepStaleStageDirs($distDir, $slug)
    {
        // @: an unusable dist is the mkdir below's loud failure to own,
        // never this sweep's.
        $dir = @opendir($distDir);
        if (false === $dir) {
            return;
        }
        // The random tail (t31-ocr42-2) rides the stale-detection
        // pattern tail-optionally: the current `.stage-<slug>-<pid>-<rand>`
        // spelling and the legacy pid-only one both reclaim by the
        // dead-pid gate alone — the suffix never weakens it.
        $stage_pattern = '/^\.stage-' . preg_quote($slug, '/') . '-(\d+)(?:-[0-9a-f]+)?$/';
        // The random tail (t31-ocr43-2, the stage pattern's twin
        // shape) rides tail-optionally here too: the current
        // `.tmp-<pid>-<rand>` spelling and the legacy pid-only one
        // both reclaim by the dead-pid gate alone — the suffix never
        // weakens it (the sidecar's '.sha256' and libzip's '.part'
        // keep riding the dotted tail).
        $temp_pattern = '/^\.connectors-' . preg_quote($slug, '/') . '-.*\.zip\.tmp-(\d+)(?:-[0-9a-f]+)?(?:\..*)?$/';
        // The manifest staging temp (t31-ocr26-8): pid-prefixed, tempnam
        // tail behind it — a LEGACY pid-less spelling never matches
        // (the random tail is alnum, no dash, and need not start with
        // digits-then-dash), staying unattributable exactly as before.
        $manifest_temp_pattern = '/^\.checksums-(\d+)-/';
        try {
            while (false !== ($entry = readdir($dir))) {
                $path = $distDir . '/' . $entry;
                // The no-symlinks doctrine at the sweep seam: a link is
                // never a run's scratch, and deleting through one
                // destroys its target (t31-r10-10).
                if (is_link($path)) {
                    continue;
                }
                if (preg_match($stage_pattern, $entry, $pid_match) && is_dir($path)) {
                    $pid = (int) $pid_match[1];
                    if ($pid !== (int) getmypid() && ! self::processIsAlive($pid)) {
                        self::rrmdir($path);
                    }

                    continue;
                }
                if (preg_match($manifest_temp_pattern, $entry, $pid_match) && is_file($path)) {
                    $pid = (int) $pid_match[1];
                    if ($pid !== (int) getmypid() && ! self::processIsAlive($pid)) {
                        // A FILE, never a link (the guard above): unlink
                        // removes the entry itself.
                        @unlink($path);
                    }

                    continue;
                }
                if (preg_match($temp_pattern, $entry, $pid_match) && is_file($path)) {
                    $pid = (int) $pid_match[1];
                    if ($pid !== (int) getmypid() && ! self::processIsAlive($pid)) {
                        // A FILE, never a link (the guard above): unlink
                        // removes the entry itself.
                        @unlink($path);
                    }
                }
            }
        } finally {
            closedir($dir);
        }
    }

    /**
     * Whether a process id is alive — the sweep's liveness check.
     *
     * A VISIBLE /proc entry is alive, deterministically. An INVISIBLE
     * one is NOT a death verdict (verifier round t31-r11-2): under
     * hidepid=2 another user's /proc/<pid> is invisible to us while the
     * process runs, and the old `is_dir('/proc') ? is_dir('/proc/'.$pid)
     * : …` shortcut concluded dead from invisibility alone — the sweep
     * then rrmdired a LIVE sibling build's in-flight stage tree,
     * contradicting its own charter ("a sweep that cannot tell never
     * deletes"). Invisibility falls through to the signal-0 probe
     * through posix, whose answer IS deterministic in both directions:
     * EPERM means the process exists (alive, not ours to signal), ESRCH
     * means it is gone (dead — the only invisible-and-dead verdict this
     * method returns). When neither mechanism can tell — no entry, no
     * posix — the verdict is ALIVE: still the charter's rule.
     *
     * The /proc entry probe is injectable for the regression: the
     * default consults the filesystem; a probe forced to FALSE stands
     * for the hidepid view (the entry exists, we just cannot see it).
     *
     * @param int        $pid           Process id.
     * @param callable|null $entry_visible Optional probe: pid => bool,
     *        whether /proc/<pid> is visible as a directory (defaults to
     *        the real filesystem check).
     * @return bool True when the process is alive or liveness is undeterminable.
     */
    private static function processIsAlive($pid, $entry_visible = null)
    {
        if ($pid <= 0) {
            return false;
        }
        $entry_visible = $entry_visible ?: static function (int $probe_pid): bool {
            return is_dir('/proc/' . $probe_pid);
        };
        if ($entry_visible((int) $pid)) {
            return true;
        }
        if (function_exists('posix_kill')) {
            // 1 = EPERM: exists, not ours to signal.
            return @posix_kill($pid, 0) || 1 === posix_get_last_error();
        }

        return true;
    }
}

/*
 * ---------------------------------------------------------------------
 * CLI entry point (guarded so tests can require this file for the class).
 * ---------------------------------------------------------------------
 */

if (wp_connectors_cli_entry(__FILE__)) {
    /*
     * The guard + diagnostics are the ONE helper now (t31-r12-11): the
     * four in-diff entry scripts wore four hand-maintained copies of
     * the t31-r9-4/t31-r10-3 idiom (at file top the calls executed in
     * every requiring process — the test suite loads this file for
     * WpConnectorsBuild — flipping display_errors process-wide on
     * hosts that set it off).
     */

    $repoRoot = dirname(__DIR__);
    $distDir = $repoRoot . '/dist';
    $args = getopt('', array( 'slug::', 'fixture::' ));

    $targets = array();
    if (isset($args['fixture'])) {
        $fixtureDir = $repoRoot . '/tests/fixtures/plugins/' . (string) $args['fixture'];
        if (! is_dir($fixtureDir)) {
            fwrite(STDERR, "build: no fixture plugin named {$args['fixture']}\n");
            exit(1);
        }
        $targets[] = $fixtureDir;
    } elseif (isset($args['slug'])) {
        $pluginDir = $repoRoot . '/connectors/' . (string) $args['slug'];
        if (! is_dir($pluginDir)) {
            fwrite(STDERR, "build: no plugin named {$args['slug']}\n");
            exit(1);
        }
        $targets[] = $pluginDir;
    } else {
        // Every subdirectory is a target: a malformed connector (e.g. no
        // main-file header) must FAIL the run via buildPlugin() — never be
        // silently omitted from a release with exit 0. Explicit-slug mode
        // rejects the same directory the same way.
        foreach (glob($repoRoot . '/connectors/*', GLOB_ONLYDIR) ?: array() as $pluginDir) {
            $targets[] = $pluginDir;
        }
    }

    if ($targets === array()) {
        echo "build: no plugins to build\n";
        exit(0);
    }

    /*
     * No pre-run manifest wipe (review round t31-r4-8): the manifest is
     * PER-RUN-ATOMIC. The old unlink dropped every OTHER plugin's entry
     * on a --slug rebuild and left the manifest gone after a failing
     * rebuild, its sidecars orphaned (reproduced). Each buildPlugin()
     * run merges only its own zip's entry into whatever manifest exists
     * and lands the result atomically (temp + rename), so entries
     * survive partial rebuilds and failed runs whole — and the merge
     * PRUNES (review round t31-r12-7, superseding the old "a zip
     * deleted out-of-band leaves its entry behind" verifier note): an
     * entry whose artifact no longer sits beside the manifest is the
     * stale line the header's "checksums.txt is regenerated" contract
     * drops, so the manifest stays an inventory whose every line names
     * an existing artifact. The two pinned invariants: no run drops an
     * entry whose artifact still exists beside the manifest, and no
     * failure leaves a half-written file.
     */

    $failed = false;
    foreach ($targets as $target) {
        try {
            $zipPath = WpConnectorsBuild::buildPlugin($target, $distDir);
            // The digest is the guarded helper's (t31-r12-6): an
            // unreadable published artifact refuses through the catch —
            // exit non-zero, reason named — instead of printing a
            // blank sha256= at exit 0.
            echo 'build: ' . basename($zipPath) . ' sha256=' . WpConnectorsBuild::publishedChecksum($zipPath) . "\n";
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            $failed = true;
        }
    }

    exit($failed ? 1 : 0);
}
