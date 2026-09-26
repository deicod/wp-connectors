<?php
/**
 * PHP syntax check (php -l) over all repository PHP sources.
 *
 * Excludes the gate's OWN named subset of the development-entry
 * vocabulary — generated and third-party trees (vendor/, tools/,
 * dist/, node_modules/, the cache spellings) in any casing — below
 * each walked root. Tests trees are NOT excluded: they are this
 * gate's charge, at the root and nested. Exits non-zero if any file
 * fails to parse.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/plugin-tools.php';

if (wp_connectors_cli_entry(__FILE__)) {
    /*
     * The guard + diagnostics are the ONE helper (t31-r12-11); this
     * file's former hand-rolled copy (t31-r10-3, the t31-r9-4 class —
     * at file top the diagnostics AND the whole walk AND the exit()
     * executed in every requiring process) is gone.
     */

    /*
     * glm23-13: the declared root set rides its ONE owner
     * (wp_connectors_lint_roots(), bin/lib/plugin-tools.php) — the
     * hand-spelled array here was one of six sites naming the walk's
     * roots, so a fifth root or a rename changed the walk alone and
     * every staged leg silently certified a tree the walk no longer
     * names (the certify-less-than-declared shape glm22-10 closed).
     */
    $roots = wp_connectors_lint_roots(__DIR__ . '/..');

    /*
     * The lint gate's OWN exclusion subset, named here (t31-ocr8-7):
     * generated and third-party trees only — the old hand-rolled
     * list's entries plus both cache spellings the r12-9 drift round
     * proved reachable. Deliberately NOT carried from the vocabulary:
     * 'tests'/'test'/'.github' and the config FILE names — those are
     * release-exclusion concerns, and a nested tests-named tree under
     * a connector root is this gate's own charge (the vocabulary ride
     * silently dropped it from coverage).
     */
    $lint_excludes = array('vendor', 'node_modules', 'tools', 'dist', '.git', '.phpunit.cache', 'phpunit.cache');

    $files = array();
    /*
     * The walk fences unreadable entries the way the repo's other
     * iterators do (OCR round 30, t31-ocr30-3 — the scan_paths
     * walk-unfenced residual head, landed at its owner): a directory
     * entry this process cannot OPEN (a permission-bearing entry —
     * shapes this repo's own adversarial tests plant) aborts the bare
     * walk with the iterator's own UnexpectedValueException, from the
     * constructor or mid-recursion (getChildren()), and nothing caught
     * it — the whole lint run died at exit 255 as an uncaught SPL
     * fatal instead of naming the unreadable entry (driven at HEAD).
     * The fence is the siblings' shape (check-conventions' glm17-17
     * conversion, inspect-artifact's t31-ocr24-2 walk): the
     * CONSTRUCTION rides the try (the ocr23 rd-1 doctrine — the
     * iterator's first open is the seam's own first statement), the
     * abort converts to the gate's FAIL vocabulary naming the root,
     * and the files collected from the readable trees before the
     * refusal are kept — the partial count stays loud, the exit
     * fails, and the unreadable tree is named, never a stack trace.
     */
    $walk_refusals = 0;
    foreach ($roots as $root) {
        /*
         * t31-glm64-3 [R64-8, driven — the R63-1 root-link shape at
         * the lint gate's own seats]: a resolving symlinked declared
         * root was followed and the walk linted the out-of-tree tree
         * through the link, the conventions census owner refusing
         * the identical shape loudly. A link root answers the loud
         * refusal.
         */
        if (is_link($root)) {
            fwrite(STDERR, "lint-php: {$root} is a symlink — the lint walk never reads through a link; make the root a real directory\n");
            ++$walk_refusals;
            continue;
        }
        if (!is_dir($root)) {
            /*
             * glm22-10 (the glm21-3 silent-skip class at the lint
             * sibling): a declared root that names nothing was
             * SILENTLY skipped — the walk said 'N file(s) checked'
             * exit 0 over a tree 3 of whose 4 declared roots were
             * absent, certifying coverage it did not walk (the exact
             * shape the scanner sibling refuses loudly: a root that
             * names nothing must never read clean). The refusal is a
             * counted walk-refusal in the gate's own FAIL vocabulary
             * — the summary and the exit both name the red's source.
             */
            fwrite(STDERR, "lint-php: FAIL {$root}: declared root not found — the lint walk refuses to certify a tree it did not walk.\n");
            ++$walk_refusals;
            continue;
        }
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                // The extension judgment rides the ONE case-insensitive owner
                // (verifier note on t31-r4-9): a '.PHP'-spelled source is as
                // loadable as any other and must not escape the lint gate.
                if (! wp_connectors_is_php_source($file->getPathname())) {
                    continue;
                }
                /*
                 * The exclusion rides the gate's OWN NAMED SUBSET of the
                 * development-entry vocabulary (review round t31-r12-9 put
                 * the walk on the vocabulary's owner fold; OCR round 8,
                 * t31-ocr8-7, took the subset back): the full vocabulary
                 * is a RELEASE-exclusion list, and riding it whole
                 * silently narrowed lint COVERAGE — the vocabulary's
                 * 'tests'/'test'/'.github' entries made a future
                 * connectors/<slug>/tests/ tree escape php -l, though a
                 * connector's own tests are this gate's charge exactly as
                 * the tests ROOT is (nothing in the old hand-rolled list
                 * ever excluded them). The subset below is the generated
                 * and third-party class only — the old list's entries
                 * plus both cache spellings the r12-9 drift round proved
                 * reachable — judged through the ONE fold owner the
                 * vocabulary docblock prescribes for subset consumers,
                 * never a byte-exact twin.
                 */
                /*
                 * The below-root offset rides the sibling's rtrim spelling
                 * (bin/lib/secret-scanner.php, OCR round 14, t31-ocr14-4):
                 * a root handed in with a trailing separator made
                 * strlen($root) + 1 one PAST the pathname prefix (the
                 * iterator normalizes the double slash away), so every
                 * relative path lost its first byte — 'vendor/x.php' read
                 * as 'endor/x.php' and the exclusion judge saw shifted
                 * segments (green before only by accident: no excluded
                 * name is one byte away from a real one). rtrim also keeps
                 * the degenerate '/' root correct (offset 1 over the
                 * absolute pathname). The strip is the DUAL-SEPARATOR
                 * class since t31-ocr31-5 moved the sibling to
                 * rtrim($root, '/\\') — this site spells the same class
                 * (the ocr36-3 parity sweep: the native-separator-only
                 * strip kept a '/'-suffixed root on a '\' host one byte
                 * over), judging the spelling CLASS never the host it
                 * runs on; on a POSIX host the arithmetic is
                 * byte-identical to the former rtrim.
                 */
                $relative = (string) substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1);
                foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
                    if (wp_connectors_segment_is_named($segment, $lint_excludes)) {
                        continue 2;
                    }
                }
                /*
                 * Only REGULAR files lint (t31-ocr10-7): the walk runs
                 * LEAVES_ONLY, so a symlink-to-directory is yielded as a
                 * leaf — a '*.php'-named dir link passes the extension
                 * owner and reaches `php -l <dir>`, which passes VACUOUSLY
                 * (driven: exit 0, "No syntax errors detected" over a
                 * directory) while the linked tree's real sources escape
                 * the gate unseen.
                 *
                 * A link answers the LOUD refusal now, never the silent
                 * skip (OCR round 50, t31-ocr50-7 — the no-symlinks
                 * doctrine, this seam): the t31-ocr10-7 skip claimed
                 * "the sibling collectors' parity", but collectFiles()
                 * and wp_connectors_php_source_files() THROW on links —
                 * they do not skip — and pre-change a symlinked source
                 * reached `php -l`, which FOLLOWS the link: the skip was
                 * a coverage regression (a linked source silently
                 * escaped the gate). The refusal rides the walk's FAIL
                 * vocabulary (the ocr30-3 shape, one seam over: a
                 * counted refusal, the exit red, the tree still walked
                 * so the regular sources keep their verdicts); the
                 * exclusion judgment above stays FIRST — a link under a
                 * third-party tree the gate never charges is not this
                 * doctrine's charge either.
                 */
                if ($file->isLink()) {
                    /*
                     * The read owns its Throwable shapes (OCR round 57,
                     * t31-ocr57-3): getLinkTarget() throws
                     * RuntimeException on error (the link removed
                     * between the iterator's yield and this readlink,
                     * NFS ESTALE, Windows directory-symlink shapes) and
                     * answers false on some builds — while the walk's
                     * fence below catches only UnexpectedValueException,
                     * so the RuntimeException escaped as an uncaught
                     * fatal (exit 255, no FAIL line, no summary) from
                     * the diagnostic ITSELF: the exact uncaught-fatal
                     * class the r30-3 fence converts into a counted
                     * refusal ('never a stack trace'). The refusal now
                     * names the unreadable target in the walk's own
                     * FAIL vocabulary, the row stays counted, the exit
                     * red — and the false return degrades to the same
                     * named refusal instead of an empty '' target.
                     * Construction-evident, not driven: the race window
                     * (a link removed between the iterator's lstat and
                     * this readlink) is owned end-to-end inside the
                     * spawned child with no staging hook a battery can
                     * plant deterministically; the readable-link
                     * battery of t31-ocr50-7 stays the green control
                     * over the surviving happy path. ONE owner since
                     * t31-glm52-9 — this inline try/catch was the
                     * third spelling of the doctrine the two IIFE
                     * twins rode.
                     */
                    $link_target = wp_connectors_link_target_or_unreadable($file);
                    fwrite(STDERR, sprintf(
                        "lint-php: FAIL %s: symlinked source (%s -> %s) — the no-symlinks doctrine refuses the charge instead of silently skipping a linked source that pre-change reached php -l\n",
                        $root,
                        wp_connectors_printable($file->getPathname()),
                        wp_connectors_printable($link_target)
                    ));
                    ++$walk_refusals;
                    continue;
                }
                if (! $file->isFile()) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        } catch (UnexpectedValueException $walk_refusal) {
            fwrite(STDERR, "lint-php: FAIL {$root}: unreadable subdirectory — the lint walk aborted (" . wp_connectors_printable($walk_refusal->getMessage()) . ").\n");
            ++$walk_refusals;
        }
    }

    sort($files);
    if ($files === array()) {
        fwrite(STDERR, "lint-php: no PHP files found (unexpected)\n");
        exit(1);
    }

    $failures = 0;
    /*
     * t31-glm47-9 [R47-10 - the hoist the round-46 drift record
     * armed]: the fleet rides its ONE owner
     * (wp_connectors_pooled_php_lint_verdicts) - the ~140-line twin
     * bin/lint-php.php and bin/inspect-artifact.php had already
     * drifted five commits deep (glm44-6, glm45-7, and glm46-3 each
     * sweeping one seat then the other). This gate renders its own
     * messages: the raw php -l output through the printable owner,
     * the no-verdict refusal in this gate's FAIL vocabulary - one
     * rendering for the serial and pooled arms alike now (the
     * serial/pooled rendering split the round-46 records carried
     * closes with the twin).
     */
    foreach (wp_connectors_pooled_php_lint_verdicts($files, 'wpct-lint-') as $index => $verdict) {
        $path = $files[ $index ];
        if (null === $verdict) {
            ++$failures;
            fwrite(STDERR, 'lint-php: FAIL ' . wp_connectors_printable($path) . ": no pooled lint verdict — the batched engine never answered (a POSIX host without xargs(1) answers its own loud failure here, the glm20-6 timeout(1) doctrine).\n");

            continue;
        }
        if ('0' !== $verdict['exit']) {
            /*
             * t31-glm41-4 [R41-13, the OUTPUT seam]: every diagnostic
             * this gate prints interpolates WALKED-ENTRY bytes (php
             * -l's own output embeds the walked path; the no-verdict
             * refusal names it; the symlink and walk-abort refusals
             * name the entry and the iterator's own message) - and a
             * legal filename byte set (an embedded newline, the
             * r12-15/R37-5 class) forges WHOLE LINES into the log.
             * Every seam renders through wp_connectors_printable (C0/
             * DEL/bidi controls become spaces, the r13-4 map), the
             * verdict bytes themselves untouched - only their PRINT
             * is swept.
             */
            ++$failures;
            fwrite(STDERR, wp_connectors_printable(rtrim($verdict['output'])) . "\n");
        }
    }

    /*
     * The summary names every count the EXIT verdict folds in (OCR
     * round 43, t31-ocr43-7): the refusal-only shape once printed
     * '0 failure(s)' while exiting 1 — the printed line and the
     * verdict disagreeing about where the red came from. The refusal
     * count rides the same line (both numbers when both nonzero, the
     * zero spelling when the verdict is clean).
     */
    printf("lint-php: %d file(s) checked, %d failure(s), %d walk refusal(s)\n", count($files), $failures, $walk_refusals);
    exit($failures === 0 && $walk_refusals === 0 ? 0 : 1);
}
