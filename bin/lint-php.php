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

    $roots = array(__DIR__ . '/../connectors', __DIR__ . '/../shared', __DIR__ . '/../bin', __DIR__ . '/../tests');

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
        if (!is_dir($root)) {
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
                 * Only REGULAR files lint (t31-ocr10-7): the walk runs
                 * LEAVES_ONLY, so a symlink-to-directory is yielded as a
                 * leaf — a '*.php'-named dir link passes the extension
                 * owner and reaches `php -l <dir>`, which passes VACUOUSLY
                 * (driven: exit 0, "No syntax errors detected" over a
                 * directory) while the linked tree's real sources escape
                 * the gate unseen. Non-regular files skip, the sibling
                 * collectors' parity (is_link || ! is_file).
                 */
                if ($file->isLink() || ! $file->isFile()) {
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
                $files[] = $file->getPathname();
            }
        } catch (UnexpectedValueException $walk_refusal) {
            fwrite(STDERR, "lint-php: FAIL {$root}: unreadable subdirectory — the lint walk aborted ({$walk_refusal->getMessage()}).\n");
            ++$walk_refusals;
        }
    }

    sort($files);
    if ($files === array()) {
        fwrite(STDERR, "lint-php: no PHP files found (unexpected)\n");
        exit(1);
    }

    $php = escapeshellarg(PHP_BINARY);
    $failures = 0;
    foreach ($files as $path) {
        $output = array();
        $exit = 0;
        exec(sprintf('%s -l %s 2>&1', $php, escapeshellarg($path)), $output, $exit);
        if ($exit !== 0) {
            ++$failures;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
    }

    printf("lint-php: %d file(s) checked, %d failure(s)\n", count($files), $failures);
    exit($failures === 0 && $walk_refusals === 0 ? 0 : 1);
}
