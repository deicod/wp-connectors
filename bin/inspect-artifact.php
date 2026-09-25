<?php
/**
 * Artifact inspector: validates a built plugin zip independently.
 *
 *   php bin/inspect-artifact.php dist/connectors-example-connector-0.1.0.zip
 *
 * Rejects (non-zero exit):
 *   - zips without exactly one top-level plugin directory (including zips
 *     whose sole top-level entry is a FILE — reported as a violation, never
 *     a crash, with the temp extraction tree cleaned up on every path),
 *   - top-level names that are not real slugs or entries with '..' path
 *     segments (extraction would write outside the work dir),
 *   - more than one main plugin file with a Plugin Name header,
 *   - missing/invalid plugin headers, version-constant mismatch, wrong text
 *     domain (same rules as bin/check-conventions.php),
 *   - repo-relative includes or shared/ references (self-containment),
 *   - development files inside the zip (vendor/, tests/, composer files...)
 *     on PLUGIN-OWNED paths — the generated <slug>/src/Shared/ subtree is
 *     exempt from that vocabulary (t31-r5-5: shared/src has no exclusion
 *     concepts; build and inspect give ONE verdict), while traversal,
 *     syntax, secret, and self-containment checks still judge it,
 *   - any PHP file that does not pass `php -l` after extraction.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/plugin-tools.php';
require_once __DIR__ . '/lib/secret-scanner.php';

/**
 * Inspects a zip archive and returns violations.
 *
 * @param string $zipPath  Absolute path to the zip.
 * @param string $workDir  Extraction BASE: a pre-existing tree at this path is removed, and extraction lands in a fresh uniquely-suffixed SIBLING of it (t31-ocr10-2 — never the plantable name itself), removed on every exit.
 * @return list<string> Violation messages (empty = artifact accepted).
 */
function wp_connectors_inspect_artifact($zipPath, $workDir)
{
    $violations = array();
    $zip = new ZipArchive();
    if (true !== $zip->open($zipPath)) {
        return array( sprintf('inspect: cannot open %s as a zip archive.', wp_connectors_printable(basename($zipPath))) );
    }

    // Forbidden entries are matched on whole path segments/files, so entries
    // like "assets/latest/x.png" are never false-rejected. The vocabulary
    // and its CASE FOLDING are the ONE shared development-entry judgment
    // (t31-r5-10, t31-r6-3) — the builder's collector and this inspector
    // cannot drift on what a dev entry is (they had: the dotless
    // 'phpunit.cache' shipped through builds the inspector rejected; and
    // the byte-exact comparison let case variants — 'Tests/', 'Build.json',
    // 'VENDOR' — ship AND pass inspection, both gates agreeing on the wrong
    // verdict). A basename is a segment, so one any-part check covers both
    // the former segment and file lists.

    $topDirs = array();
    $sawDirectoryEntry = false;
    $traversalEntries = array();
    $nearSourceEntries = array();
    $streamSeparatorEntries = array();
    $seenEntryNames = array();
    $seenFoldedNames = array();
    $reportedDuplicateEntries = array();
    $reportedDevEntries = array();
    /*
     * The traversal fold's char set derives ONCE per archive (OCR
     * round 52, t31-ocr52-6): the fold below once re-derived
     * $nonDotJunk per ENTRY and a fresh str_split char array per
     * SEGMENT — ~N·(K+1) rebuilds of constants over one walk. The
     * hoisted array is byte-identical to the rebuilt spelling (the
     * same edge-junk owner, the same dot strip).
     */
    $nonDotJunkChars = str_split(str_replace('.', '', wp_connectors_path_edge_junk()), 1);
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = (string) $zip->getNameIndex($i);
        $parts = explode('/', $name);
        /*
         * The duplicate-entry fence (verifier round t31-r12-15, the
         * security lens's HIGH): a hostile zip may carry one entry
         * name MORE THAN ONCE — the ZipArchive WRITER refuses to
         * produce that shape (same-name writes replace), but the
         * READER counts every copy and extractTo() keeps only the
         * LAST: the extraction returns TRUE, every content check below
         * judges the landed bytes, and the first copy's bytes (the
         * webshell, the live key) are judged by nobody — ACCEPTED at 0
         * violations (reproduced on a real built zip). Byte-exact AND
         * case-folded duplicates refuse: on a case-insensitive
         * extraction target ('Assets/logo.png' beside
         * 'assets/logo.png') one silently overwrites the other — the
         * r6 deferred collision class's INSPECTOR half, consumed here;
         * the builder-side fence over the collected entry set (and its
         * directory prefixes) stays the r6 line's own round.
         *
         * The fold composes BOTH owners (OCR round 11, t31-ocr11-3):
         * case through wp_connectors_ascii_lower() AND the TRAILING
         * edge-junk byte class through wp_connectors_path_edge_junk()
         * — stripped per path SEGMENT, the way Windows path
         * normalization itself folds ('logo.png.' and 'logo.png '
         * beside 'logo.png' collide at extraction exactly like a case
         * variant, the class the codebase's own development-entry
         * comparison has carried since t31-r6-5). Trailing side only,
         * per component — the LEADING side stays (a leading dot is
         * content, '.git' the vocabulary's own spelling). The fold
         * also COLLAPSES '.' and empty segments (t31-ocr11-24, the
         * round's verifier lens): at extraction 'p/./logo.png',
         * 'p//logo.png', and 'p/logo.png' name the SAME file on every
         * host (driven on this one: extractTo() returns true with one
         * file landed, the first copy's bytes judged by nobody), so
         * the key drops them — a dots-only run of two or more dots
         * stays outside the fold, the traversal refusal below owns
         * it.
         */
        $folded_name = wp_connectors_ascii_lower(implode('/', array_filter(array_map(
            static function ( $segment ) use ( $nonDotJunkChars ) {
                /*
                 * A DOTS-ONLY segment of two or more dots rides the
                 * key VERBATIM (OCR round 48, t31-ocr48-3, for the
                 * exact '..' spelling; widened to the whole class by
                 * OCR round 49, t31-ocr49-6): the edge-junk rtrim
                 * collapses a dots-only segment to '' and the filter
                 * below drops it, which once folded 'p/../a.php' onto
                 * 'p/a.php' — and 'p/.../a.php' the same way one
                 * spelling over — a zip carrying it beside the plain
                 * spelling answering a spurious case-fold duplicate
                 * line BESIDE its traversal rejection. The census
                 * contract above ("'..' stays outside the fold, the
                 * traversal refusal below owns it") owns the parent
                 * class, not the one spelling: the traversal screen's
                 * own predicate judges any dots-only run of two or
                 * more dots the parent token (t31-ocr16-1's resolved
                 * fold), so EVERY such segment is excluded from the
                 * fold's collapsing vocabulary and answers the
                 * traversal refusal on its own key. A lone '.' and
                 * the empty segment keep collapsing (t31-ocr11-24 —
                 * at extraction they NAME the same file, a genuine
                 * duplicate), and junk-carrying segments still fold
                 * their junk exactly as before.
                 *
                 * The exclusion OWNS the traversal-junk class, judged
                 * by the traversal predicate's own spelling (OCR
                 * round 67, t31-ocr67-2): the verbatim branch once
                 * tested trim($segment, '.') — leading/trailing DOTS
                 * only — so any edge-junk byte BLOCKED the exclusion
                 * and the segment fell to the rtrim below, which
                 * strips the junk AND the dots (the dot rides the
                 * edge-junk class) down to '' — the filter drops it,
                 * 'p/.. /x.php' folded onto 'p/x.php', and a zip
                 * carrying both answered a case-fold-duplicate line
                 * whose premise is factually WRONG beside its
                 * traversal rejection: the spelling escapes the tree
                 * on a normalizing host (the traversal predicate —
                 * junk stripped ANYWHERE in the segment, then a
                 * dots-only remainder of two or more dots — judges
                 * exactly those spellings the parent token, the
                 * r27-1 fold). One class, both censuses agree: the
                 * predicate below is the traversal screen's own,
                 * spelled over the same hoisted $nonDotJunkChars.
                 */
                $junk_folded = str_replace($nonDotJunkChars, '', (string) $segment);
                if ('' === rtrim($junk_folded, '.') && strlen($junk_folded) >= 2) {
                    return $segment;
                }
                return rtrim((string) $segment, wp_connectors_path_edge_junk());
            },
            explode('/', $name)
        ), static function ( $segment ) {
            return '' !== $segment && '.' !== $segment;
        })));
        $is_byte_duplicate = isset($seenEntryNames[$name]);
        if ($is_byte_duplicate) {
            /*
             * The EMISSION is deduped per name too (OCR round 27,
             * t31-ocr27-4): the r26-5 comment claimed "deduped per
             * name" while the branch answered N−1 identical lines
             * for a name carried N times — the fix had closed the
             * two-fences class, not the N−1 emission class, and the
             * comment overclaimed (the round's own comment-vs-
             * behavior drift lens). One offense, one line: the third
             * and every later copy of the same bytes answers nothing
             * the second copy did not.
             */
            if (! isset($reportedDuplicateEntries[$name])) {
                $reportedDuplicateEntries[$name] = true;
                $violations[] = sprintf(
                    'inspect: zip carries the entry name "%s" more than once — extraction keeps only one copy, so the other bytes are judged by nobody.',
                    wp_connectors_printable($name)
                );
            }
        } else {
            $seenEntryNames[$name] = true;
        }
        /*
         * The verdict is DEDUPED per name (OCR round 26, t31-ocr26-5):
         * identical bytes fold identically, so a byte-exact duplicate
         * once tripped BOTH fences for the same name — one offense,
         * two verdict lines (a triple copy answered four). The first
         * fence wins; the folded fence judges only the copies the
         * byte fence did not name (a case-fold twin is a DIFFERENT
         * offense and keeps its own line). The byte-duplicate branch
         * needs no fold marking either: its key was marked by the
         * first copy of the same bytes.
         */
        if (! $is_byte_duplicate && isset($seenFoldedNames[$folded_name])) {
            $violations[] = sprintf(
                'inspect: zip carries case-fold duplicate entry names ("%s") — on a normalizing extraction target (case-insensitive, or Windows trailing dot/space stripping per component) one silently overwrites the other.',
                wp_connectors_printable($name)
            );
        } elseif (! $is_byte_duplicate) {
            $seenFoldedNames[$folded_name] = true;
        }
        $topDirs[ $parts[0] ] = true;
        // An entry with a second path segment proves the top-level name is
        // (also) a directory; a zip of only "file.php"-style entries has a
        // FILE at the top level, which directory-based checks below cannot
        // traverse (RecursiveDirectoryIterator would throw).
        if (count($parts) > 1) {
            $sawDirectoryEntry = true;
        }
        // No entry may carry a '..' path segment anywhere: extracting such
        // an entry writes outside the work dir (host traversal). Backslash
        // separators are rejected too — harmless literal characters on Linux,
        // but path separators under PHP on Windows. (Applies to EVERY entry,
        // embedded or not — this is host safety, not a tree doctrine.)
        /*
         * The refusal owns every spelling that RESOLVES to '..' (OCR
         * round 16, t31-ocr16-1, the security lens): the byte-exact
         * in_array judged only the plain spelling, while the very
         * edge-junk fold the duplicate fence rides strips trailing
         * junk per segment — so '.. ' and '...' (driven: accepted at
         * 0 traversal violations) are the PARENT token to every
         * path-normalizing host (Windows strips trailing spaces,
         * then collapses a dots-only run of two or more dots onto
         * '..' at extraction) and the extraction writes outside the
         * work dir through a spelling the fence's own vocabulary
         * already knows collapses. The fold is derived from the ONE
         * edge-junk owner (its class minus the dot — the dot is the
         * run's own byte, stripped and COUNTED, never part of the
         * junk). The junk folds out of the segment ANYWHERE it sits
         * (OCR round 27, t31-ocr27-1, the fence-
         * generation treadmill's next spelling): the round-16 cut
         * stripped TRAILING junk only, so junk BETWEEN the dots
         * ('. .', '..<tab>..', '..<0x01>.') survived with the
         * parent token intact after the host strips its own side of
         * the class — the RESOLVED segment is what the predicate
         * judges now: fold every junk byte out, then a DOTS-ONLY
         * remainder of two or more dots is the parent token (content
         * spellings keep their verdict — 'x..', '..x', 'a.b' still
         * judge as content, their non-junk bytes survive the fold).
         */
        $hasTraversalSegment = false;
        foreach ($parts as $part) {
            $folded = str_replace($nonDotJunkChars, '', (string) $part);
            if ('' === rtrim($folded, '.') && strlen($folded) >= 2) {
                $hasTraversalSegment = true;

                break;
            }
        }
        if ($hasTraversalSegment || strpos($name, '\\') !== false) {
            /*
             * First-verdict-wins per name at the collector (OCR round
             * 32, t31-ocr32-5 — the t31-ocr27-4 doctrine, this
             * collector): the byte-duplicate fence above does not
             * `continue`, so every COPY of a traversal name once
             * pushed its own identical entry and the emission below
             * answered N lines for N copies — one offense, one line;
             * a name carried N times escapes once, loudly.
             */
            $traversalEntries[ $name ] = true;
        }
        /*
         * The near-source PHP fence (OCR round 20, t31-ocr20-1, the
         * security lens — the r16 edge-junk class at the EXTRACTION
         * fence, closed at the traversal fence in that same round):
         * wp_connectors_is_php_source() judges the template tails
         * ('.php'/'.phtml', glm14-4), so an entry whose segment hides
         * the extension behind
         * TRAILING edge junk ('shell.php ', 'shell.php.',
         * 'shell.php\x01') is a PHP source to every
         * path-normalizing extraction target (Windows strips
         * trailing dots, spaces, and controls per component — the
         * exact fold the duplicate-entry fence above already rides)
         * while every gate judged it as not one: the entry EXTRACTED
         * into the tree, the syntax loop's extension lens skipped it,
         * and the artifact ACCEPTED at 0 violations carrying a file
         * that lands as a live .php source on the folding host
         * (driven red at HEAD). The shared-source collector has
         * refused the same spelling since t31-r5-14 (the near-source
         * fence over development trees); the EXTRACTION fence — where
         * the names are ARCHIVE-CONTROLLED, the hostile surface —
         * refused nothing. The judgment rides the ONE near-source
         * owner (wp_connectors_segment_is_near_source_php(), the
         * collector's own composition extracted to one predicate by
         * the round's verifier pass): the RAW lens first (a plain
         * .php segment is an ordinary source, judged by the syntax
         * loop below), and only a segment that is NOT a source raw
         * but IS one through the ONE edge-junk fold (trailing side
         * only, per segment — the leading side stays, the fold
         * doctrine's own line) refuses — never extraction, the whole
         * artifact refuses BEFORE extractTo() runs (the traversal
         * refusal's shape: a fold the host applies is a fold the
         * fence must judge).
         */
        foreach ($parts as $part) {
            if (wp_connectors_segment_is_near_source_php($part)) {
                // Keyed like the traversal collector (t31-ocr32-5):
                // first-verdict-wins per name — one offense, one line.
                $nearSourceEntries[ $name ] = true;

                break;
            }
        }
        /*
         * The NTFS STREAM-SEPARATOR fence (OCR round 62, t31-ocr62-2,
         * the security lens — ':' at every fold/lens seam the
         * pre-extraction fences ride): the edge-junk class is
         * C0+DEL+dot and the ':' grammar screen owns only the
         * top-level slug, so '<slug>/src/shell.php:$DATA' (and
         * '<slug>/x.php:hidden') passed every fence — the raw lens
         * saw a non-'.php' tail, the edge-junk rtrim never strips
         * ':', the duplicate fold keyed it apart from the plain
         * 'shell.php' spelling, and the traversal fold is
         * dots-only. On a Windows/NTFS extraction target that name
         * lands the bytes in an ALTERNATE DATA STREAM of the
         * colon-free file — for the ':$DATA' spelling the MAIN
         * stream of shell.php — plugin-reachable content no content
         * gate judged under the entry's own separator-bearing
         * spelling. ONE arm owns the class at the NAME, upstream of
         * all four seams: ':' in any NON-DRIVE-LETTER position of
         * the relative entry name refuses — the drive-letter
         * spelling ('C:/…', the bare 'C:') is ABSOLUTE-path
         * grammar, the r39-2 bare-drive fence's own vocabulary in
         * the rrmdir owner below, and its relative-zip twin dies at
         * the slug grammar screen (':' outside [A-Za-z0-9_.-]) with
         * the verdict that screen has always answered — both named
         * here so the exemption never reads as an oversight (the
         * driven control below keeps that verdict unchanged).
         *
         * The builder owns the class at COLLECTION since t31-ocr63-3
         * (bin/build.php's collectFiles(), its own loud refusal over
         * the walk-collected relatives — one class, two owners, one
         * verdict, no drive-letter exemption needed on the relative
         * side): the builder never ships the shape anymore. This
         * fence STAYS as defense in depth — the inspector judges
         * ARCHIVE-CONTROLLED names, a surface the builder's own tree
         * never reaches.
         */
        $stream_probe = 1 === preg_match('/\A[A-Za-z]:/', $name) ? (string) substr($name, 2) : $name;
        if (strpos($stream_probe, ':') !== false) {
            // Keyed like the traversal collector (t31-ocr32-5):
            // first-verdict-wins per name — one offense, one line.
            $streamSeparatorEntries[ $name ] = true;
        }
        /*
         * The forbidden-entry vocabulary is a PLUGIN-OWNED-path concept
         * (review round t31-r5-5): the generated <slug>/src/Shared/
         * subtree is embedded from shared/src, which has NO exclusion
         * concepts (the t31-r3-4 doctrine — every PHP source ships,
         * wherever it lives, and the architecture sweep gates that
         * tree) — so a shared source under shared/src/tools/ shipped by
         * design was rejected here, and build and inspect gave
         * contradictory verdicts no CI run could satisfy (reproduced).
         * ONE verdict now: the segment/file check exempts the embedded
         * subtree. No attack path opens: a plugin hiding its own code
         * under src/Shared/tools/ cannot reach a zip through the build
         * (collectFiles drops excluded segments; a plugin-owned Shared
         * path colliding with an embed destination refuses the build,
         * t31-r5-1), and the embedded copy is generated from
         * sweep-gated shared/src. Every OTHER check — traversal,
         * headers, syntax (php -l), secrets, self-containment — still
         * judges every entry, embedded or not.
         */
        /*
         * The embed-territory judgment rides the ONE owner
         * (t31-r12-10, corrected t31-r12-16):
         * wp_connectors_is_embed_destination() matches the CANONICAL
         * prefix the builder generates — a case-variant spelling is
         * foreign (the builder's case-insensitive fence refuses the
         * whole tree that carries one), so its segments judge by the
         * vocabulary below, per the t31-r12-3 signal doctrine.
         */
        /*
         * The exemption requires the top-level name to NOT be a
         * development entry itself (t31-ocr10-8): the name is
         * archive-controlled, and a hostile zip whose single top-level
         * dir IS a dev entry ('vendor/src/Shared/…' — driven) exempted
         * everything under it from dev-entry classification, the
         * vocabulary judged through its ONE owner. The embed territory
         * only exists under a REAL plugin slug.
         */
        $isEmbeddedShared = ! wp_connectors_is_development_entry($parts[0]) && wp_connectors_is_embed_destination($name, $parts[0]);
        // Segment check (whole path components; a basename is one),
        // judged by the ONE comparison owner — case-insensitively
        // (t31-r6-3), the same fold the builder's collector excludes
        // by, so the two gates give ONE verdict in every casing.
        $isForbidden = false;
        if (! $isEmbeddedShared) {
            foreach ($parts as $part) {
                if (wp_connectors_is_development_entry($part)) {
                    $isForbidden = true;

                    break;
                }
            }
        }
        if ($isForbidden) {
            /*
             * Every verdict line that interpolates archive-controlled
             * text renders through the ONE printable seam (round
             * t31-r13-1, the security lens, reproduced): an entry name
             * survives getNameIndex() BYTE-EXACT — newline included —
             * on this runtime (neither ZipArchive side sanitizes
             * control bytes in names; probed again this round, and the
             * r12 ledger's sanitization premise corrected), so a raw
             * interpolation printed a FORGED verdict line beside the
             * real REJECTED one (the driver's repro: entry name
             * '…/vendor/x\ninspect: FORGED-LINE-ACCEPTED (0
             * violations)\n.php'). The dev-entry line here, the
             * top-dir list, the invalid-slug and traversal refusals,
             * the not-a-plugin-directory refusal, the php -l failure,
             * and the secret findings all interpolate entry-derived
             * bytes and all ride the seam now; the printable body
             * still names the offending entry.
             */
            /*
             * The EMISSION is deduped per name (OCR round 42,
             * t31-ocr42-4 — the t31-ocr27-4 doctrine, this collector):
             * the byte-duplicate fence above does not `continue`, so
             * every COPY of a dev-segment name once pushed its own
             * identical line and a name carried N times answered N
             * dev-entry lines beside its ONE duplicate line — one
             * offense, one line, the keyed collectors' census
             * (t31-ocr32-5) swept to the last per-occurrence pusher.
             */
            if (! isset($reportedDevEntries[$name])) {
                $reportedDevEntries[$name] = true;
                $violations[] = sprintf('inspect: zip contains development entry "%s".', wp_connectors_printable($name));
            }
        }
    }
    $zip->close();

    if (count($topDirs) !== 1) {
        $violations[] = sprintf(
            'inspect: zip must contain exactly one top-level plugin directory, found: %s.',
            implode(', ', array_map('wp_connectors_printable', array_keys($topDirs)))
        );

        return $violations;
    }
    $slug = (string) array_key_first($topDirs);
    if ($slug === '' ) {
        $violations[] = 'inspect: zip has an empty top-level directory name.';

        return $violations;
    }
    // The root name must be a real slug BEFORE anything is built from it:
    // '../payload.php'-style entries make $workDir . '/..' point at HOST
    // paths outside the extraction dir, so every check below would traverse
    // (and extraction would write) outside the work dir.
    /*
     * The anchor is D-anchored (OCR round 67, t31-ocr67-1): without
     * D, PCRE's '$' also asserts immediately BEFORE a final newline,
     * so a top-level directory spelled 'slug\n' PASSED the grammar
     * and every fence below it — the fold owners strip the \n to a
     * harmless 'slug', the traversal fold is dots-only, the tail
     * lens sees '.php', no ':' or dev vocabulary anywhere — and
     * extractTo() landed a REAL directory named 'slug\n' (a newline
     * is a legal filename byte), the tree every later walk then
     * read through \n-bearing relatives (driven: the run answered
     * a header verdict, never the slug refusal). D pins the '$' to
     * the very end; the drive-letter twin (the C: spelling) keeps
     * its own refusal below unchanged. The round's census of this
     * file's grammar anchors: ONE '$' rider existed — this screen;
     * the other anchors are \A/\z-spelled (the rrmdir owner's two
     * drive/root fences), immune to the pre-newline fold by
     * construction.
     */
    if ($slug === '.' || $slug === '..' || ! preg_match('/^[A-Za-z0-9_.-]+$/D', $slug)) {
        // The name that FAILED the grammar prints through the seam (see
        // the dev-entry site): pre-grammar, its bytes are unjudged.
        $violations[] = sprintf('inspect: invalid top-level plugin directory name "%s".', wp_connectors_printable($slug));

        return $violations;
    }
    if ($traversalEntries !== array()) {
        // array_keys: the collector is keyed per name (t31-ocr32-5) —
        // one line per offending name, never one per copy.
        foreach (array_keys($traversalEntries) as $name) {
            $violations[] = sprintf('inspect: zip entry "%s" escapes the extraction directory.', wp_connectors_printable($name));
        }

        return $violations;
    }
    // The near-source refusal owns the same pre-extraction shape: such an
    // artifact is refused whole, never extracted-and-judged (the entry
    // name rides the printable seam — a control byte in the spelling is
    // hostile input, see the dev-entry site).
    if ($nearSourceEntries !== array()) {
        // array_keys: the collector is keyed per name (t31-ocr32-5) —
        // one line per offending name, never one per copy.
        foreach (array_keys($nearSourceEntries) as $name) {
            $violations[] = sprintf(
                'inspect: zip entry "%s" is a NEAR-SOURCE PHP spelling (trailing whitespace, control byte, or dot hides the extension) — every normalizing extraction target (Windows strips trailing dots and spaces per component) lands it as a live .php source while every gate judged it as not one; write the plain .php name.',
                wp_connectors_printable($name)
            );
        }

        return $violations;
    }
    // The stream-separator refusal owns the same pre-extraction shape
    // (t31-ocr62-2, its census at the collector): the artifact is
    // refused whole, never extracted-and-judged — the entry name
    // rides the printable seam (a control byte in the spelling is
    // hostile input, see the dev-entry site).
    if ($streamSeparatorEntries !== array()) {
        // array_keys: the collector is keyed per name (t31-ocr32-5) —
        // one line per offending name, never one per copy.
        foreach (array_keys($streamSeparatorEntries) as $name) {
            $violations[] = sprintf(
                'inspect: zip entry "%s" carries a stream separator (\':\') — on a Windows/NTFS extraction target the name resolves into an alternate data stream of the colon-free file (the \':$DATA\' spelling its MAIN stream), plugin-reachable bytes no content gate judged under the separator-bearing spelling; write the colon-free name.',
                wp_connectors_printable($name)
            );
        }

        return $violations;
    }
    if (! $sawDirectoryEntry) {
        // Reject root-file archives BEFORE any directory traversal: the sole
        // top-level entry is a file (e.g. plugin.php), not a plugin folder.
        $violations[] = sprintf(
            'inspect: zip must contain exactly one top-level plugin directory; the sole entry "%s" is a file.',
            $slug
        );

        return $violations;
    }

    // Extract independently and validate the real tree. Everything below
    // runs inside try/finally so the temp tree is removed on EVERY path.
    if (is_dir($workDir)) {
        /*
         * The per-entry removal refusal converts to the VERDICT
         * vocabulary here (t31-ocr32-4): the pre-extraction reclaim
         * failing loudly is a judgment-shape failure of the run's own
         * work directory, and the artifact is judged whole or not at
         * all — never an uncaught fatal's exit 255 with no verdict
         * (the ocr24-2 channel doctrine).
         */
        try {
            wp_connectors_inspect_rrmdir($workDir);
        } catch (RuntimeException $reclaim_refusal) {
            $violations[] = sprintf(
                'inspect: cannot reclaim the pre-existing work directory %s — %s; the artifact is judged whole or not at all.',
                wp_connectors_printable($workDir),
                wp_connectors_printable($reclaim_refusal->getMessage())
            );

            return $violations;
        }
    }
    /*
     * The extraction dir is UNIQUE-OWNED (t31-ocr10-2, the WRITE half
     * of the planted-link threat t31-ocr9-10 closed for deletion): the
     * workDir spellings are fixed and predictable — the CLI's
     * sys_get_temp_dir() . '/wp-connectors-inspect-' . getmypid() (pids
     * enumerable), the tests' dist/.inspect-* literals — so a symlink
     * PRE-PLANTED at the name made is_dir() follow it, mkdir() fail
     * ("File exists", the warning leaking raw), and extractTo() WRITE
     * through the link into the attacker's chosen tree (driven pre-fix:
     * the extracted plugin dir landed inside the victim tree). A random
     * unique suffix cannot be pre-planted; the rrmdir link guard below
     * stays as depth. mkdir() is the race-free creation (a pre-existing
     * name of any kind fails it, retried on a fresh suffix).
     */
    /*
     * The retry loop's own failure premise is CAPTURED, never leaked
     * (t31-ocr10-17, the verifier's refutation lens — the r12-19
     * doctrine one screen below, applied to the loop that shares its
     * screen): on an unwritable parent the 16 retries each raised a
     * RAW 'mkdir(): Permission denied' warning to output before the
     * polite refusal printed (driven). The capture is
     * exception-safe (the r12-20 finally) and the refusal names the
     * captured reason through the printable seam.
     */
    $extractDir = '';
    $create_reason = '';
    set_error_handler(static function ( $errno, $errstr ) use ( &$create_reason ) {
        $create_reason = wp_connectors_printable((string) $errstr);

        return true;
    });
    try {
        for ($attempt = 0; $attempt < 16 && '' === $extractDir; ++$attempt) {
            $candidate = $workDir . '-' . bin2hex(random_bytes(8));
            /*
             * NON-recursive by premise (OCR round 67, t31-ocr67-3 —
             * comment-vs-behavior drift): the recursive flag once
             * made mkdir() return TRUE over a pre-existing DIRECTORY
             * (only files/links fail it), so $extractDir could bind
             * to a tree that already stood at the suffixed name —
             * only the CSPRNG suffix kept the shape unreachable,
             * while the census comment above claimed "a pre-existing
             * name of any kind fails it". Without the flag the claim
             * is TRUE (any pre-existing name of any kind fails,
             * retried on a fresh suffix); the parent dirname() of
             * $workDir exists by construction (the temp root, dist/),
             * so recursion bought nothing.
             */
            if (mkdir($candidate, 0755)) {
                $extractDir = $candidate;
            }
        }
    } finally {
        restore_error_handler();
    }
    if ('' === $extractDir) {
        $violations[] = sprintf(
            'inspect: cannot create a unique extraction directory under %s — %s; the artifact is judged whole or not at all.',
            wp_connectors_printable($workDir),
            '' !== $create_reason ? $create_reason : 'creation returned failure without a diagnostic'
        );

        return $violations;
    }
    try {
        /*
         * Both extraction returns are OWNED (review round t31-r12-1): the
         * unchecked open()/extractTo() pair let a PARTIAL extraction
         * inspect green — a zip carrying an entry whose name exceeds the
         * filesystem's NAME_MAX (reproduced: a 300-byte path component)
         * makes extractTo() return false mid-tree, the engine warning
         * leaked raw to output, and every check below ran over whatever
         * subset HAD extracted while the never-extracted remainder (the
         * webshell and the live key behind it) was judged by nobody —
         * ACCEPTED, 0 violations, exit 0. A failure to open or extract
         * the whole archive is now a refusal naming the reason (the
         * captured engine diagnostic — silenced, never leaked raw), and
         * NO content check runs over a partial tree: the artifact is
         * judged whole or not at all.
         */
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath)) {
            $violations[] = sprintf('inspect: cannot open %s as a zip archive for independent extraction.', wp_connectors_printable(basename($zipPath)));

            return $violations;
        }
        /*
         * The captured diagnostic renders through the ONE printable
         * seam (verifier round t31-r12-19, the security lens): the
         * engine warning interpolates the hostile ENTRY NAME, and a
         * 250+-byte component with an embedded newline (or an ANSI
         * escape) otherwise forges lines — or rewrites the terminal —
         * inside the inspector's own STDERR output. The refusal still
         * names the reason; the reason can no longer forge one.
         */
        $extract_reason = '';
        set_error_handler(static function ( $errno, $errstr ) use ( &$extract_reason ) {
            $extract_reason = wp_connectors_printable((string) $errstr);

            return true;
        });
        /*
         * The capture is EXCEPTION-SAFE (verifier round t31-r12-20, the
         * security lens): anything extractTo() throws between the two
         * handler calls (a ValueError shape some 8.5 builds raise on an
         * unusable destination; this runtime's engine answers warn and
         * false — the r12-20 pin's own probed note) otherwise leaves
         * the swallow-all capture handler installed for the REST of
         * the process, silently suppressing every later warning,
         * notice, and deprecation. The restore rides a finally.
         *
         * The throw itself is OWNED as a verdict too (OCR round 39,
         * t31-ocr39-1): the r12-20 finally restored the handler but
         * owned nothing else — a throw escaped uncaught past it, past
         * $zip->close(), and past every verdict surface below: the CLI
         * died at exit 255 with an engine stack trace and no verdict,
         * and the test call site aborted its whole battery. The catch
         * rides INSIDE the try statement whose finally restores the
         * handler — the catch's return runs that finally exactly once;
         * a nested finally of its own would restore twice — and the
         * throw answers the SAME whole-or-not-at-all refusal the false
         * return answers, the reason rendered through the ONE printable
         * seam (the r12-19 doctrine: an engine message can interpolate
         * archive-controlled bytes).
         */
        try {
            $extracted = $zip->extractTo($extractDir);
        } catch (Throwable $extract_throw) {
            $violations[] = sprintf(
                'inspect: cannot extract %s — %s; the artifact is judged whole or not at all, never over a partial extraction tree.',
                wp_connectors_printable(basename($zipPath)),
                wp_connectors_printable($extract_throw->getMessage())
            );
            /*
             * The handle closes on the THROW path too (OCR round 67,
             * t31-ocr67-4): the return once ran before the close
             * below, so the ZipArchive stayed open until scope
             * teardown — every sibling path (the open refusal, the
             * false-return path, the green walk) closes explicitly,
             * and an open read handle holds the zip file past the
             * verdict on filesystems that count such things.
             */
            $zip->close();

            return $violations;
        } finally {
            restore_error_handler();
        }
        $zip->close();
        if (true !== $extracted) {
            $violations[] = sprintf(
                'inspect: cannot extract %s — %s; the artifact is judged whole or not at all, never over a partial extraction tree.',
                wp_connectors_printable(basename($zipPath)),
                '' !== $extract_reason ? $extract_reason : 'extraction returned failure without a diagnostic'
            );

            return $violations;
        }

        $pluginDir = $extractDir . '/' . $slug;
        if (! is_dir($pluginDir)) {
            $violations[] = sprintf('inspect: the single top-level entry "%s" is not a plugin directory.', wp_connectors_printable($slug));

            return $violations;
        }

        /*
         * The MERGED helper lines render through the ONE printable
         * seam at the merge (verifier round t31-r13-4, raised
         * independently by both lenses): the helpers interpolate
         * archive-controlled text — main-file basenames, header
         * values, the version-constant value, landed paths, include
         * statements — and r13-1's seam covered only the lines the
         * inspector spells itself, so a newline-bearing landed name or
         * a CR/ESC-bearing header value forged lines beside the real
         * REJECTED verdict (reproduced). The helpers stay pure
         * producers (build and the conventions gate render them over
         * the repo's own trusted bytes); the INSPECTOR is the
         * hostile-input surface, so the seam rides its merge points.
         */
        $mainFiles = wp_connectors_find_main_plugin_files($pluginDir);
        if ($mainFiles === array()) {
            $violations[] = sprintf('inspect: %s: no main plugin file with a Plugin Name header.', $slug);
        } else {
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_main_file_violations($pluginDir, $mainFiles)));
            $headers = wp_connectors_parse_plugin_headers($mainFiles[0]);
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_duplicate_header_violations($mainFiles[0], $slug)));
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_header_violations($headers, $slug)));
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_version_constant_violations($pluginDir, $headers, $mainFiles)));
            $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_autoloader_violations($pluginDir)));
        }
        $violations = array_merge($violations, wp_connectors_printable_lines(wp_connectors_self_containment_violations($pluginDir)));

        // Every PHP file must pass a syntax check after independent extraction.
        /*
         * glm28-12: the syntax walk rides the POOLED fleet (the
         * glm21-14/15 pool precedents, the lint gate's own pooled twin
         * one round over at glm28-11 — the second consumer of the
         * shape; the repo's THIRD-consumer hoist threshold names when
         * the fleet core becomes ONE owner). The walk once spawned
         * ONE ENGINE PER FILE serially — 2.35 s of a 2.50 s
         * inspection over a real zip (~94% spawn cost, measured at
         * HEAD); every probe is tree-independent, so the walk now
         * COLLECTS the files and one xargs -0 -n2 -P8 sh -c fleet
         * lints them, each verdict recorded beside its INDEX (php
         * -l's own output plus its exit code). The runner's trailing
         * echo is LOAD-BEARING (glm21-15's documented idiom): php -l
         * refuses parse errors at exit 255, a status bare xargs
         * ABORTS on — the echo absorbs it, the fleet keeps walking.
         * A POSIX host without xargs(1) answers the walk's own loud
         * failure at the missing verdict file (the refusal class
         * below), never a silent pass; non-POSIX hosts keep the
         * serial loop. The verdict sentence is byte-identical.
         */
        $syntax_verdicts = static function (array $syntax_files) use ($extractDir): array {
            $violations = array();
            /*
             * t31-glm47-9 [R47-10 - the hoist the round-46 drift
             * record armed]: the fleet rides its ONE owner
             * (wp_connectors_pooled_php_lint_verdicts) - this seat's
             * twin sibling deleted (the ~140-line copy that had
             * already drifted five commits deep across the two
             * gates). This seat renders its own messages: the
             * extract-relative path through the printable owner -
             * one rendering for the serial and pooled arms alike
             * now (the serial/pooled rendering split the round-46
             * records carried closes with the twin).
             */
            foreach (wp_connectors_pooled_php_lint_verdicts($syntax_files, 'wpct-inspect-') as $index => $verdict) {
                $path = $syntax_files[ $index ];
                if (null === $verdict) {
                    $violations[] = sprintf('inspect: %s failed php -l: %s', wp_connectors_printable(str_replace($extractDir . '/', '', $path)), 'no pooled lint verdict — the batched engine never answered (a POSIX host without xargs(1) answers its own loud failure here, the glm20-6 timeout(1) doctrine)');

                    continue;
                }
                if ('0' !== $verdict['exit']) {
                    $violations[] = sprintf('inspect: %s failed php -l: %s', wp_connectors_printable(str_replace($extractDir . '/', '', $path)), wp_connectors_printable(implode(' ', array_values(array_filter(explode("\n", rtrim($verdict['output'])), static function ( $line ) { return '' !== $line; })))));
                }
            }

            return $violations;
        };
        /*
         * The walk rides the glm31-4 fence every sibling walker in this
         * change set already carries (OCR round 24, t31-ocr24-2): a
         * directory entry this process cannot OPEN — a
         * permission-bearing entry a hostile zip lands, an environment
         * whose landing tree refuses the opendir — aborts the bare walk
         * with the iterator's own UnexpectedValueException, and the
         * CLI call site catches nothing: the inspector died at exit
         * 255 with NO verdict, and the artifact escaped judgment. The
         * CONSTRUCTION rides the same try (the ocr23 rd-1 doctrine:
         * the fence owns the iteration seam's own first statement).
         * The refusal converts to the verdict vocabulary — mirroring
         * the self-containment walker's shape — and RETURNS: the
         * artifact is judged whole or not at all (the r12-1 doctrine
         * at the walk seam — the secret scan below never judges a
         * partially readable tree), and the early return is what
         * keeps the verdict channel whole end-to-end, because the
         * scan's own walk inside secret-scanner.php is this round's
         * LEDGERED residual (unfenced there; fenced here, one seam
         * ahead of it for every refusal shape). The partial php -l
         * results collected before a mid-walk refusal are kept.
         */
        try {
            $syntax_files = array();
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                // The extension judgment rides the ONE case-insensitive owner
                // (verifier note on t31-r4-9): a '.PHP'-spelled entry ships in
                // the zip and must pass the post-extraction syntax check like
                // any other source — the exact-case check let a parse-broken
                // .PHP file through inspection clean.
                if (! wp_connectors_is_php_source($file->getPathname())) {
                    continue;
                }
                $syntax_files[] = $file->getPathname();
            }
        } catch (UnexpectedValueException $walk_refusal) {
            /*
             * The partial results collected before a mid-walk refusal
             * are kept (the fence's own recorded behavior): the files
             * the walk did reach keep their syntax verdicts, the
             * refusal rides after them, and the artifact is judged
             * whole or not at all.
             */
            $violations = array_merge($violations, $syntax_verdicts($syntax_files));
            $violations[] = sprintf(
                'inspect: cannot walk %s for the post-extraction syntax check — %s; the artifact is judged whole or not at all, never over a partially readable tree.',
                $slug,
                wp_connectors_printable((string) $walk_refusal->getMessage())
            );

            return $violations;
        }
        // Both interpolations of every verdict carry the LANDED entry bytes
        // (a newline is a legal filename character here, and the engine's
        // own diagnostic echoes the same path) — both ride the seam (see
        // the dev-entry site).
        $violations = array_merge($violations, $syntax_verdicts($syntax_files));

        /*
         * No development credentials inside artifacts — the scan runs
         * UNPRUNED (review round t31-r12-3, closing the round-6 ledger
         * line): wp_connectors_scan_paths()'s dev-segment prune is a
         * repository-walk concept, and the tree a zip actually ships has
         * no dev segments by construction (the builder's collector drops
         * them by the one development-entry vocabulary) — so a pruned
         * segment inside an extracted artifact is itself the anomaly,
         * never a reason to stop reading. The src/Shared dev-entry
         * exemption above composes with this exactly: it exempts
         * CLASSIFICATION (a shared source may live under src/Shared/
         * tools/), while this scan still judges every file the artifact
         * ships under it — the r6 HIGH had a live key at
         * <slug>/src/Shared/vendor/keys.txt inspect ACCEPTED while the
         * identical key one directory up rejected (reproduced).
         */
        foreach (wp_connectors_scan_paths(array( $pluginDir ), false) as $secretFinding) {
            // The finding's path carries the landed entry bytes (see the
            // dev-entry site for the seam doctrine).
            $violations[] = 'inspect: ' . wp_connectors_printable(str_replace($extractDir . '/', '', $secretFinding));
        }

        return $violations;
    } finally {
        /*
         * The teardown degrade (the t31-ocr23-2 silent contract,
         * unchanged): the per-entry removal failure was ANSWERED at
         * the seam (the named refusal with the printable path,
         * t31-ocr32-4) and the raw engine warning never escapes; a
         * rethrow from this finally would REPLACE the artifact
         * verdict in flight (the t31-ocr23-1 class), so the partial
         * removal stands and the unique-suffixed residue stays for
         * the OS temp sweep (the ocr24-3 vocabulary).
         */
        try {
            wp_connectors_inspect_rrmdir($extractDir);
        } catch (RuntimeException $reclaim_refusal) {
            // Intentionally degraded — see the comment above.
        }
    }
}

/**
 * Recursively removes a directory.
 *
 * The no-symlinks doctrine the two sibling owners already carry
 * (bin/build.php's rrmdir, t31-r3-era; tests/harness/WpHarness.php's,
 * t31-ocr1-11 — and this third owner skipped it, found by the round-9
 * refutation lens, t31-ocr9-10): a LINK at the removal root is never
 * deleted through (is_dir() follows links; the iterator constructed
 * on a linked path walks the TARGET tree — the workDir names here are
 * fixed and predictable, pre-plantable on a shared host, and the lens
 * DROVE a planted link emptying the victim tree through this owner),
 * and a linked child inside an owned tree is unlinked AS ITSELF,
 * never rmdir'd through (isDir() follows links — the ! isLink() guard
 * keeps the linked dir on the unlink branch).
 *
 * The link probe reads the TAIL-STRIPPED spelling and the ROOT has
 * its own clause (t31-ocr10-15, the round-10 verifier's refutation
 * lens — r9-10's own ledgered re-open condition FIRED): the workDir
 * is a PUBLIC parameter of wp_connectors_inspect_artifact(), so a
 * caller-controlled spelling reaches this owner, and driven pre-fix
 * a trailing '/', '/.', or '/..' family tail forced stat THROUGH a
 * planted link ('link/.' EMPTIED the victim tree past the plain
 * guard) while a root-collapsing spelling walked the filesystem
 * root's children. Only the PROBE strips the tails (a stripped '/..'
 * names a different directory, the ocr9-1 doctrine — the walk keeps
 * the caller's spelling). This owner's verdict vocabulary is the
 * silent return (a production finally must not throw), so the root
 * refuses silently too — the extraction lands in the unique dir
 * regardless.
 *
 * The '/..' tail refuses over REAL directories too (OCR round 26,
 * t31-ocr26-3): the raw spelling names the caller-named root's
 * PARENT — territory this owner never walks (the probe's own
 * doctrine, extended from the link channel to every tail
 * spelling); '/' and '/.' name the root itself and keep the walk —
 * on the STRIPPED spelling (t31-ocr26-4): a 'dir/.' tail once
 * emptied the children through the iterator and leaked the root
 * itself to rmdir('dir/.')'s EINVAL, contradicting the ocr24-3
 * root-reclaim claim this docblock states.
 *
 * A per-entry removal FAILURE answers the named refusal (OCR round
 * 32, t31-ocr32-4): the walk's rmdir()/unlink() returns are owned
 * (the ocr30-4 pattern), because the paths they name are
 * archive-controlled on this owner's tree and the raw engine
 * warning is a diagnostics channel the printable seam owns. The
 * WALK-REFUSAL shapes above (a subdirectory the iterator cannot
 * OPEN, mid-recursion or at construction) keep the silent degrade
 * — the production finally must not throw — and the two callers of
 * the per-entry refusal own its conversion: the pre-extraction
 * reclaim converts it to a violation (the verdict vocabulary), and
 * the teardown finally swallows it (a rethrow would replace the
 * artifact verdict in flight — the t31-ocr23-1 class).
 *
 * @param string $dir Absolute directory path.
 * @return void
 * @throws RuntimeException When a per-entry removal fails and the caller owns the conversion (t31-ocr32-4).
 */
function wp_connectors_inspect_rrmdir($dir)
{
    $probe = $dir;
    $parent_walking = false;
    /*
     * BOTH separator spellings strip (OCR round 29, t31-ocr29-3 —
     * the DIRECTORY_SEPARATOR class the round's platform family
     * named): the loop below fenced only '/'-spelled tails, so a
     * backslash tail ('dir\..', the NATIVE spelling on a host whose
     * separator is '\') survived the probe and the walk rode the
     * caller's raw spelling into the territory the tail names — the
     * ocr10-15/ocr26-3/4 "every tail spelling" doctrine one
     * separator short of its own claim. The fence judges the
     * spelling CLASS, never the host it runs on: on a POSIX host a
     * backslash tail is an inert byte in an odd filename, and
     * refusing it costs RESIDUE for that pathological spelling (the
     * silent return's own vocabulary — residue over victim, the
     * ocr24-3 trade); on a host where '\' IS the separator the same
     * fence holds the parent tree. The POSIX drive rides the
     * tail-spelling battery; the Windows-native parent-walk is
     * construction-evident (DIRECTORY_SEPARATOR, a platform constant
     * no test sim flips — the ocr28-3 doctrine).
     */
    if ('/' !== $probe) {
        $probe = rtrim($probe, '/\\');
        while (true) {
            $tail = substr($probe, -2);
            if ('/.' === $tail || '\\.' === $tail) {
                $probe = rtrim(substr($probe, 0, -2), '/\\');
                continue;
            }
            $tail = substr($probe, -3);
            if ('/..' === $tail || '\\..' === $tail) {
                $probe = rtrim(substr($probe, 0, -3), '/\\');
                $parent_walking = true;
                continue;
            }
            break;
        }
    }
    /*
     * A parent-walking tail over a REAL directory refuses too (OCR
     * round 26, t31-ocr26-3 — the same territory doctrine the link
     * probe above owns, one spelling over): the fences judge the
     * STRIPPED probe while the walk below rides the caller's RAW
     * spelling, and 'root/..' resolves to the root's PARENT —
     * driven pre-fix, a caller-controlled 'dist/.inspect-x/..'
     * over a real (non-link) directory walked and EMPTIED the
     * parent tree (dist/ itself) through the tail, past the link
     * fence and the realpath root fence alike. The walk stays
     * inside the caller-named root for every tail spelling: a
     * tail that names OUTSIDE it is never walked. The verdict
     * vocabulary is the silent return (the root clause's own — a
     * production finally must not throw).
     *
     * The WHOLE-PATH degenerates refuse the same way (OCR round
     * 27, t31-ocr27-2): '..', './', and '.' carry no '/..' tail,
     * so the strip loop left them with $parent_walking unset —
     * and '..' then passed is_link()/is_dir()/realpath!=='/' on
     * any host whose CWD is not directly beneath the root, and
     * the walk EMPTIED the parent of the CALLER'S CWD (driven
     * pre-fix from a child CWD: the parent tree's every entry
     * removed through the spelling). A degenerate whole-path
     * names no caller-named root at all ('.' names the CWD
     * itself, '..' its parent — territory this owner was never
     * handed); every one refuses, never walks. '' rides the same
     * fence: it is the '/..'/'/.' strips' own residue, a spelling
     * that names nothing.
     *
     * The BARE DRIVE-LETTER spelling refuses the same way (OCR
     * round 39, t31-ocr39-2): 'C:' and 'C:/' survive the strip
     * loop as the bare drive letter alone (rtrim keeps it — no
     * trailing separator, no tail to strip), and is_dir('C:') is
     * TRUE on Windows: the walk would name the DRIVE ROOT, a
     * directory the caller never named — the same degenerate
     * whole-path class this fence exists to kill. The drive-root
     * vocabulary is the spelling set the ocr33-5 root clause
     * below already names; judged here on the STRIPPED probe,
     * before any is_dir() can follow the spelling. On a POSIX
     * host the cost is residue for the pathological
     * drive-letter-NAMED entry (the ocr29-3 trade, one spelling
     * over), driven red at HEAD through a literal 'C:'
     * directory: the walk emptied it.
     */
    if ($parent_walking || '' === $probe || '.' === $probe || '..' === $probe || 1 === preg_match('/\A[A-Za-z]:\z/', $probe)) {
        return;
    }
    if (is_link($probe)) {
        return;
    }
    /*
     * The walk and the reclaim both ride the STRIPPED spelling (OCR
     * round 26, t31-ocr26-4): the is_dir probe, the realpath root
     * fence, the iterator, and the final @rmdir once read the
     * caller's RAW spelling, so a 'dir/.' tail removed the children
     * through the iterator (which normalizes) and then @rmdir
     * ('dir/.') failed EINVAL — the root leaked, contradicting the
     * ocr24-3 root-reclaim claim this owner's own docblock states.
     * After the parent-walking refusal every surviving tail
     * spelling ('/', '/.') names the root itself; the stripped
     * spelling names the same directory without the engine's
     * rmdir() tail refusal.
     */
    if (! is_dir($probe)) {
        return;
    }
    /*
     * The ROOT fence owns BOTH spellings of the universal container
     * (OCR round 34, t31-ocr34-1 — the owner round 33's sweep missed):
     * t31-ocr33-5 taught the container CLASS to the harness's one
     * predicate, but this owner kept comparing '/' against realpath()'s
     * raw answer — dead twice over on a '\' host, where the answer
     * over a drive root is 'C:\', never '/': the inspector's rrmdir
     * over a drive root passed the fence and the walk emptied THE
     * DRIVE ROOT's children, the exact t31-ocr10-1 shape the fence
     * exists to kill. The census of the universal-container class —
     * THREE owners: WpHarness::resolvesToUniversalContainer(), the one
     * predicate serving rrmdir()'s root refusal, copyTree()'s
     * source-side refusal, and its mirror clause (t31-ocr33-5), and
     * this inspector's own fence here; any future root-collapse guard
     * joins the predicate or spells the whole class itself. The class
     * spelling at this owner: the answer folded through the separator
     * vocabulary (identity on POSIX, the ocr29-4 doctrine) and judged
     * as '/' or a drive-letter root with or without the trailing
     * separator. The SUPER-ROOT member (realpath('//') preserved as
     * '//' on SysV-lineage libcs) rides the harness predicate's
     * derived arm (t31-ocr34-3); this owner's stripped probe never
     * carries a root-separator spelling that far — its degenerates
     * answer the empty-probe fence above first.
     */
    $root_real = realpath($probe);
    if (false !== $root_real) {
        $root_folded = str_replace('\\', '/', $root_real);
        if ('/' === $root_folded || 1 === preg_match('/\A[A-Za-z]:\/?\z/', $root_folded)) {
            return;
        }
    }
    try {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($probe, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /*
             * The walk owns its IO returns (OCR round 32, t31-ocr32-4 —
             * the ocr30-4 pattern at this owner's own vocabulary): the
             * per-entry rmdir()/unlink() calls run over the EXTRACTED
             * HOSTILE TREE, and every path they interpolate into an
             * engine warning is archive-controlled — the one
             * diagnostics channel the printable seam had left off (a
             * stranded 0555/0444 shape or a removal race once answered
             * with a raw E_WARNING: under PHPUnit an exception wearing
             * PHPUnit's vocabulary, outside it the raw bytes — the
             * exact two-way escape ocr30-4 closed for the copy twin).
             * The @ suppresses only the diagnostic; the FAILED RETURN
             * answers the named refusal below, the path rendered
             * through the ONE printable seam (the sibling owners walk
             * builder-created or test-scratch trees; this one does
             * not). The CALLSITES own the conversion: the
             * pre-extraction reclaim converts to the verdict
             * vocabulary, and the teardown finally degrades silently
             * (its own contract — a rethrow there would replace the
             * artifact verdict in flight, the t31-ocr23-1 class).
             */
            if ($item->isDir() && ! $item->isLink()) {
                if (! @rmdir($item->getPathname())) {
                    throw new RuntimeException('inspect: cannot reclaim the extraction tree — rmdir() failed at ' . wp_connectors_printable($item->getPathname()) . '; the walk owns its IO failures here, never the engine\'s raw warning over archive-controlled bytes, and the partial removal stands for the OS temp sweep');
                }
            } elseif (! @unlink($item->getPathname())) {
                throw new RuntimeException('inspect: cannot reclaim the extraction tree — unlink() failed at ' . wp_connectors_printable($item->getPathname()) . '; the walk owns its IO failures here, never the engine\'s raw warning over archive-controlled bytes, and the partial removal stands for the OS temp sweep');
            }
        }
    } catch (UnexpectedValueException $walk_refusal) {
        /*
         * A subdirectory the iterator cannot OPEN mid-recursion aborts
         * the walk (glm31-4's class, fenced at the shared scan; the
         * removal twins got the same guard this round — build's at its
         * finally, this one at the docblock's own silent contract): an
         * uncaught throw here is the engine's vocabulary on the one
         * seam whose contract names the silent return. The removal up
         * to the refusal stands, the unopened subtree stays, and
         * wp_connectors_inspect_artifact()'s verdict surface is
         * untouched (OCR round 23, t31-ocr23-2, driven). The
         * CONSTRUCTION rides the same guard (the round's verifier
         * pass, rd-1): a removal ROOT this process cannot open throws
         * from the iterator's own constructor, one shape over the
         * walk's refusal (driven at HEAD).
         *
         * The catch FALLS THROUGH now (OCR round 24, t31-ocr24-3):
         * the early return leaked the removal ROOT itself beside the
         * unopened subtree, and the residue justification true for
         * build's twin (its stage sweep revisits whatever stands)
         * never held here — this owner's extraction dirs are unique
         * random-suffixed trees NOTHING ever revisits: one leaked
         * temp tree per refusal, accumulating across runs. The final
         * @rmdir is the best-effort reclaim: a no-op when children
         * remain (the unopenable-subtree residue stays by doctrine),
         * and it RECLAIMS the root whenever it is empty-able — rmdir
         * needs the PARENT's write bit, never the target's read bit,
         * so even a locked EMPTY root goes. The silent verdict is
         * unchanged (the @ keeps the engine's noise out of it) — and
         * the reclaim names the STRIPPED spelling (t31-ocr26-4 above:
         * the raw 'dir/.' tail answers EINVAL from rmdir(), leaking
         * the root the walk had just emptied).
         */
    }
    @rmdir($probe);
}

if (wp_connectors_cli_entry(__FILE__)) {
    /*
     * The guard + diagnostics are the ONE helper (t31-r12-11); this
     * file's former hand-rolled copy (t31-r9-4 — at file top the calls
     * executed in every requiring process; the test suite loads this
     * file for wp_connectors_inspect_artifact()) is gone. The args
     * ride the ONE argv accessor (t31-r12-17): the auto-global,
     * function-scoped so the read stays provably defined —
     * $_SERVER['argv'] is unpopulated under a variables_order ini
     * without "S", where the guard still fires.
     */

    $cliArgs = wp_connectors_cli_args();
    /*
     * t31-glm55-9 [R55-9, driven — the silent-argument-drop class the
     * branch's own CLI doctrine refuses loudly at every sibling
     * entry script]: the seat consumed only $cliArgs[1] and ignored
     * every further argument byte-silently (driven: a second path
     * that did not even EXIST produced the single-zip run's output
     * verbatim, no diagnostic naming it — an operator inspecting two
     * artifacts believes both were judged when only the first was
     * opened; build.php's per-argument validation loop three files
     * over is the intended shape). The CLI takes exactly one
     * artifact: any further argument answers the usage refusal
     * naming the first extra, never a silently-dropped judgment.
     */
    if (count($cliArgs) < 2) {
        fwrite(STDERR, "usage: php bin/inspect-artifact.php <zip>\n");
        exit(2);
    }
    if (count($cliArgs) > 2) {
        fwrite(STDERR, 'inspect: unexpected argument: ' . wp_connectors_printable((string) $cliArgs[2]) . " (this CLI inspects exactly one artifact)\n");
        exit(2);
    }
    $zipPath = (string) $cliArgs[1];
    if (! is_file($zipPath)) {
        // The path rides the printable seam (t31-ocr11-25, the round's
        // verifier lens over ocr11-13's sweep): basename() spellings
        // already ride it, and this full-path line is the same
        // caller-controlled class — a newline in the argument once
        // forged a verdict-lookalike line in the inspector's own
        // STDERR (driven by the lens).
        fwrite(STDERR, 'inspect: no such file: ' . wp_connectors_printable($zipPath) . "\n");
        exit(2);
    }
    $violations = wp_connectors_inspect_artifact($zipPath, sys_get_temp_dir() . '/wp-connectors-inspect-' . getmypid());
    foreach ($violations as $violation) {
        fwrite(STDERR, $violation . "\n");
    }
    printf("inspect: %s %s (%d violation(s))\n", wp_connectors_printable(basename($zipPath)), $violations === array() ? 'ACCEPTED' : 'REJECTED', count($violations));
    exit($violations === array() ? 0 : 1);
}
