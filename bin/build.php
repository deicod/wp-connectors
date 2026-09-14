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
 * and every zip gets a SHA-256 checksum (dist/checksums.txt is regenerated).
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

error_reporting(E_ALL);
ini_set('display_errors', '1');

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
     * @param string $source        PHP source from shared/src.
     * @param string $pluginSuffix  Namespace segment, e.g. 'OpenAiOauth'.
     * @param string $sourceVersion Provenance string (repo-relative path/rev).
     * @return string Rewritten source ready for src/Shared/.
     * @throws RuntimeException When the namespace suffix is not a legal namespace segment, or when any spelling of the shared namespace survives the rewrite.
     */
    public static function rewriteSharedNamespace($source, $pluginSuffix, $sourceVersion)
    {
        self::assertNamespaceSegment($pluginSuffix);
        $escapedVersion = str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $sourceVersion);
        $provenance = "/**\n * Generated copy of {$escapedVersion} for this plugin's private namespace.\n * Do not edit here; change the shared source and rebuild.\n */\n";
        $rewritten = self::replaceOrThrow(
            preg_replace(
                '/(namespace\s+)Deicod\\\\WpConnectors\\\\Shared((?:\\\\[A-Za-z0-9_]+)*\s*;)/',
                '$1Deicod\\\\WpConnectors\\\\' . $pluginSuffix . '\\\\Shared$2',
                $source
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
        $rewritten = self::replaceOrThrow(
            preg_replace(
                '/(?<![A-Za-z0-9_])((?:use\s+(?:function\s+|const\s+)?)\\\\?)Deicod\\\\WpConnectors\\\\Shared((?:\\\\[A-Za-z0-9_]+)*)(\s+as\s+[A-Za-z0-9_]+)?((?:\\\\)?\s*\{[^;}]*\})?\s*;/',
                '${1}Deicod\\\\WpConnectors\\\\' . $pluginSuffix . '\\\\Shared${2}${3}${4};',
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
        $rewritten = self::replaceOrThrow(
            preg_replace_callback(
                '/((?<![A-Za-z0-9_])use\s+(?:function\s+|const\s+)?\\\\?Deicod\\\\WpConnectors\\\\)\s*(\{)([^{}]*)(\})\s*;/',
                static function ($matches) use ($pluginSuffix, $sourceVersion) {
                    $members = array();
                    foreach (explode(',', $matches[3]) as $member) {
                        $member = trim($member);
                        $tail = '';
                        if (1 === preg_match('/^(.+?)\s+as\s+([A-Za-z0-9_]+)$/', $member, $alias_parts)) {
                            $member = $alias_parts[1];
                            $tail = ' as ' . $alias_parts[2];
                        }
                        $kind = '';
                        if (1 === preg_match('/^(?:function|const)\s+/', $member, $member_kind)) {
                            $kind = $member_kind[0];
                            $member = (string) substr($member, strlen($member_kind[0]));
                        }
                        $members[] = $kind . self::replaceOrThrow(
                            preg_replace(
                                '/^Shared(?![A-Za-z0-9_])/',
                                $pluginSuffix . '\\\\Shared',
                                $member
                            ),
                            'group-use member rewrite',
                            $sourceVersion
                        ) . $tail;
                    }

                    return $matches[1] . $matches[2] . implode(', ', $members) . $matches[4] . ';';
                },
                $rewritten
            ),
            'group-use member rewrite',
            $sourceVersion
        );

        // Insert provenance directly after the open tag (never before it).
        $final = self::replaceOrThrow(
            preg_replace(
                '/^<\?php\b\s*/',
                "<?php\n\n" . $provenance . "\n",
                $rewritten,
                1
            ),
            'provenance insertion',
            $sourceVersion
        );

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
        $target = 'Deicod\\WpConnectors\\' . $pluginSuffix . '\\Shared';
        $target_lower = strtolower($target);
        foreach (wp_connectors_shared_family_references($final, $target) as $reference) {
            if ('pcre-abort' === $reference['kind']) {
                throw new RuntimeException("build: the namespace-reference scan aborted (PCRE) while rewriting {$sourceVersion} — an abort refuses the rewrite, never passes it");
            }
            $is_target = $reference['lower'] === $target_lower || 0 === strpos($reference['lower'], $target_lower . '\\');
            if ($is_target && ('declaration' === $reference['kind'] || 'use' === $reference['kind'])) {
                continue;
            }
            throw new RuntimeException(sprintf(
                'build: the reference %s (%s position) survived the rewrite in %s at byte offset %d — after the rewrite the embedded copy may reference only the plugin-private target Deicod\\WpConnectors\\%s\\Shared…, so every other reference to the shared-namespace family points at a namespace that does not exist inside the plugin; every legal use form is rewritten here or the build refuses, never an import that ships broken',
                $reference['name'],
                $reference['kind'],
                $sourceVersion,
                $reference['offset'],
                $pluginSuffix
            ));
        }

        return $final;
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
     */
    public static function collectFiles($pluginDir)
    {
        $files = array();
        $pluginDir = rtrim($pluginDir, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $relative = str_replace($pluginDir . '/', '', $file->getPathname());
            $parts = explode('/', $relative);
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
             */
            $excluded = false;
            foreach ($parts as $part) {
                if (wp_connectors_is_development_entry($part)) {
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
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Builds one plugin zip.
     *
     * @param string $pluginDir Absolute plugin source directory.
     * @param string $distDir   Absolute dist directory.
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
                    throw new RuntimeException("build: {$slug}: build.json namespace_suffix '{$pluginSuffix}' does not match the slug-derived autoloader prefix Deicod\\WpConnectors\\{$derivedSuffix}\\ the plugin ships (src/autoload.php binds it, the conventions gate enforces it, and the build emits no autoloader of its own) — the shared library would embed under Deicod\\WpConnectors\\{$pluginSuffix}\\Shared, a namespace nothing loads: every gate green, the plugin fataled on install. Set namespace_suffix to '{$derivedSuffix}' or drop the key");
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
         * by checked RENAMES at the very end (descriptors first, the
         * archive LAST). Every failure this run can construct then
         * leaves the prior artifact set byte-untouched BY CONSTRUCTION:
         * the half-built product lives at a temp path the finally below
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
         * class cannot exist on this side of the seam); EIO/ENOSPC-class
         * rename failures past the pre-flight are the honest boundary.
         */
        $stage = $distDir . '/.stage-' . $slug;
        if (is_dir($stage)) {
            self::rrmdir($stage);
        }
        mkdir($stage . '/' . $slug, 0755, true);

        $zipTemp = $distDir . '/.' . $zipName . '.tmp-' . getmypid();
        $sidecarTemp = $zipTemp . '.sha256';
        $manifestPath = $distDir . '/checksums.txt';
        $manifestTemp = false;
        $manifestLock = null;
        try {
            $licenseFile = dirname($distDir) . '/LICENSE';
            $entries = array();
            foreach (self::collectFiles($pluginDir) as $relative) {
                self::copyNormalized($pluginDir . '/' . $relative, $stage . '/' . $slug . '/' . $relative);
                $entries[] = $slug . '/' . $relative;
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
             * two territories, one comparison (strcasecmp over the
             * collected entries): generated destinations REFUSE a
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
                foreach ($entries as $existing_entry) {
                    if (0 === strcasecmp($existing_entry, $slug . '/LICENSE')) {
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
                     */
                    $destination = $slug . '/src/Shared/' . $relative;
                    foreach ($entries as $existing_entry) {
                        if (0 === strcasecmp($existing_entry, $destination)) {
                            throw new RuntimeException("build: {$slug} owns {$existing_entry} — a case-insensitive collision with the generated embed copy {$destination}; src/Shared/ is build-generated (build.json embed_shared), so remove or rename the plugin's own file");
                        }
                    }
                    $source = self::readSharedSource($sharedDir, $relative);
                    $rewritten = self::rewriteSharedNamespace($source, $pluginSuffix, 'shared/src/' . $relative);
                    $target = $stage . '/' . $slug . '/src/Shared/' . $relative;
                    @mkdir(dirname($target), 0755, true);
                    self::writeNormalized($rewritten, $target);
                    $entries[] = $slug . '/src/Shared/' . $relative;
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

            // Landing: descriptors first, the archive LAST.
            self::landArtifact($sidecarTemp, $zipPath . '.sha256', "the checksum sidecar for {$zipName}");
            self::landArtifact($manifestTemp, $manifestPath, "the checksum manifest for {$zipName}");
            self::landArtifact($zipTemp, $zipPath, "the archive {$zipName}");
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
     * line byte-for-byte) and lands whole — but the staging file is now
     * tempnam()-unique: the fixed '<manifest>.tmp' spelling made two
     * concurrent builds interleave their stage writes (the round's
     * two-process race), whichever rename landed last shipping a mix of
     * both runs' lines. A unique stage breaks the interleaving; the final
     * rename is still atomic, so a crash can never leave a half-written
     * manifest behind, and never deletes one either (the t31-r4-8
     * no-pre-run-wipe contract, unchanged). tempnam() creates 0600; the
     * manifest is a published artifact and lands 0644 like the sidecar.
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
        // (glm17-16) — a blocked path refuses through the check.
        $temp = @tempnam($distDir, '.checksums-');
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
     * every other line byte-for-byte, so the two halves of the merge
     * cannot drift on what counts as an entry line. (The mid-build
     * failure-path consumer — the entry scrub that removed a corrupted
     * artifact's entry after the fact — died with t31-r5-S: a failure
     * now never touches the manifest the run did not land.)
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
     * @param string $dir Absolute directory path.
     * @return void
     */
    private static function rrmdir($dir)
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}

/*
 * ---------------------------------------------------------------------
 * CLI entry point (guarded so tests can require this file for the class).
 * ---------------------------------------------------------------------
 */

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
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
     * survive partial rebuilds and failed runs whole. The manifest is a
     * record of what was built and checksummed, not an inventory: a zip
     * deleted out-of-band leaves its entry behind (verifier note,
     * ledgered) — the two pinned invariants are that no run destroys an
     * entry it did not build and no failure leaves a half-written file.
     */

    $failed = false;
    foreach ($targets as $target) {
        try {
            $zipPath = WpConnectorsBuild::buildPlugin($target, $distDir);
            echo 'build: ' . basename($zipPath) . ' sha256=' . hash_file('sha256', $zipPath) . "\n";
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            $failed = true;
        }
    }

    exit($failed ? 1 : 0);
}
