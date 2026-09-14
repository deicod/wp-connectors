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
     * The rewrite postcondition's ONE survivor pattern (round t31-r4, K1).
     *
     * TOTAL by construction — it is not a list of spellings the rewriter
     * knows, it is the negation of the ONE property the rewrite owes:
     * after rewriting, the output contains ZERO occurrences of the
     * source namespace `Deicod\WpConnectors\Shared`, in ANY spelling.
     * Three totality dimensions, each closing a round-3/4 defect class:
     *
     * - Case-INsensitive (`/i`): PHP namespaces resolve case-insensitively,
     *   so `use deicod\wpconnectors\shared\Clock;` is the source namespace
     *   at runtime while no exact-case probe ever saw it (the t31-r3-2
     *   consciously-accepted posture, closed by totality).
     * - Whitespace-tolerant BETWEEN the segments: a string-literal or
     *   docblock spelling may break the line (`…\WpConnectors\` + newline
     *   + `Shared\…`), which defeats every contiguous probe and the
     *   sweep's per-line whitelist alike (t31-r4-5, reproduced
     *   end-to-end at exit 0).
     * - Brace-aware: a group-use MEMBER carries `Shared` at a member
     *   position (`use Deicod\WpConnectors\{Shared\Clock};`, t31-r4-4)
     *   where the namespace substring never appears contiguously. The
     *   brace alternative matches `Shared` only at a member boundary
     *   (immediately after `{` or after a member separator), so the
     *   REWRITTEN member (`{<Suffix>\Shared\…}` — `Shared` preceded by
     *   the suffix's backslash) never re-trips it.
     *
     * The property is sound by construction because the rewritten target
     * `…\WpConnectors\<Suffix>\Shared` cannot contain the source
     * spelling: the validated, non-empty `<Suffix>` segment always sits
     * between `WpConnectors\` and `Shared` (pinned empirically over the
     * whole shared tree by the rewrite soundness test).
     *
     * PUBLIC and single-owner: the architecture sweep's
     * no-cross-namespace-reference gate rides the same pattern, so what
     * the build refuses and what the sweep flags cannot drift (one
     * vocabulary, two consumers).
     *
     * Honest boundary (verifier round t31-r4, ledgered): SPLIT-composed
     * spellings — the namespace assembled at runtime from concatenated
     * or interpolated string pieces — are outside a spelling-level
     * scan's charter (the pieces are ordinary string fragments; only
     * their runtime VALUE names the namespace). No shared source
     * composes the namespace dynamically today; re-open if one ever
     * does (the fix shape is a no-dynamic-class-resolution gate, not a
     * wider pattern).
     *
     * @var string
     */
    const SHARED_NAMESPACE_SURVIVOR_PATTERN = '/(?<![A-Za-z0-9_])Deicod\\s*\\\\\\s*WpConnectors\\s*\\\\\\s*(?:Shared(?![A-Za-z0-9_])|\\{(?:[^;]*?[\\s,{])?Shared(?![A-Za-z0-9_]))/i';

    /** Development paths never shipped inside a plugin zip. */
    const EXCLUDED_PATHS = array(
        '.git', '.github', '.gitignore', '.gitattributes', '.editorconfig',
        'vendor', 'node_modules', 'dist', 'tools', 'tests', 'test',
        'composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.xml.dist',
        'phpcs.xml', 'phpcs.xml.dist', 'phpstan.neon', 'phpstan.neon.dist',
        '.phpunit.result.cache', '.phpcs-cache.json', 'phpcs-cache.json',
        '.phpunit.cache', 'package.json', 'package-lock.json', 'Makefile',
        'webpack.config.js', 'vite.config.js',
        'build.json', '.distignore',
    );

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
     * (SHARED_NAMESPACE_SURVIVOR_PATTERN), not a spelling list — zero
     * occurrences of the source namespace in the output, case-insensitive,
     * whitespace-tolerant, brace-aware — and the rewrite extends to
     * group-use MEMBER spellings (t31-r4-4). The patterns do the work;
     * the total scan guarantees that what they miss refuses the build
     * instead of shipping.
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
         * Postcondition (t31-r4 K1, superseding the t31-r3-2 per-spelling
         * probe): the rewrite's contract is ONE total property — the
         * OUTPUT contains zero occurrences of the source namespace, in
         * any spelling (see SHARED_NAMESPACE_SURVIVOR_PATTERN for the
         * three totality dimensions). The patterns above do the work for
         * every legal spelling this rewriter knows; anything they miss —
         * a comment-interrupted use line, a nested brace group, a
         * case-variant, a string-literal or docblock reference — REFUSES
         * the build loudly with the file and byte offset, instead of
         * shipping an import that points at a namespace which no longer
         * exists inside the plugin (silent survival was the defect class
         * this closes; per-spelling patching had missed the same seam
         * twice already). A PCRE abort refuses too (glm36-8: an abort is
         * never a clean pass). The scan runs over the FINAL bytes —
         * provenance included — so nothing that ships escapes it.
         */
        $survivor = preg_match(self::SHARED_NAMESPACE_SURVIVOR_PATTERN, $final, $hit, PREG_OFFSET_CAPTURE);
        if (false === $survivor) {
            throw new RuntimeException("build: the survivor scan aborted (PCRE) while rewriting {$sourceVersion} — an abort refuses the rewrite, never passes it");
        }
        if (1 === $survivor) {
            throw new RuntimeException("build: a spelling of Deicod\\WpConnectors\\Shared survived the rewrite in {$sourceVersion} at byte offset {$hit[0][1]} — every legal use form is rewritten here or the build refuses; a survivor means a spelling the patterns do not know, never an import that ships broken");
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
            /*
             * A symlink REFUSES the build loudly (verifier round
             * t31-r4-16, extending t31-r4-7's doctrine from the shared
             * tree to the plugin tree): the old silent skip left a
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
            $relative = str_replace($pluginDir . '/', '', $file->getPathname());
            $parts = explode('/', $relative);
            if (array_intersect($parts, self::EXCLUDED_PATHS) !== array()) {
                continue;
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
             * Duplicate keys refuse (verifier round t31-r4-17): json_decode
             * keeps the LAST spelling silently, so '{"embed_shared": true,
             * "embed_shared": false}' meant no-embed with exit 0
             * (reproduced) — the divergent-duplicate shape of the
             * silent-no-embed class. Counted over the RAW text AFTER the
             * type checks: in a config whose values passed validation the
             * quoted key spellings can only be real keys (a namespace
             * segment cannot carry a quote, and the booleans are
             * literals), so a second spelling is a duplicate by
             * construction.
             */
            foreach (array('embed_shared', 'namespace_suffix') as $schema_key) {
                $key_spellings = substr_count($rawConfig, '"' . $schema_key . '"');
                if ($key_spellings > 1) {
                    throw new RuntimeException('build: ' . $slug . ": build.json carries the key \"{$schema_key}\" {$key_spellings} times — JSON keeps the last spelling silently, and a divergent duplicate is the silent-no-embed class this seam refuses");
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

        // Stage the plugin into a normalized temp tree. The WHOLE staging
        // lifecycle — tree creation, copy, embed rewrite, and the zip —
        // runs inside one try/catch/finally (review round t31-r3-6): the
        // per-file suffix validation used to throw AFTER the stage tree
        // existed and rrmdir() only ran on the success path, so any
        // mid-build failure leaked both the staging tree and a partial
        // zip into dist/ (reproduced with an 'Evil$1' suffix). The catch
        // releases a half-written archive, removes the partial zip, and
        // rethrows; the finally tears the stage down on EVERY path.
        $stage = $distDir . '/.stage-' . $slug;
        if (is_dir($stage)) {
            self::rrmdir($stage);
        }
        mkdir($stage . '/' . $slug, 0755, true);

        $zip = null;
        $zipOpened = false;
        $zipOverwritten = false;
        try {
            $licenseFile = dirname($distDir) . '/LICENSE';
            $entries = array();
            foreach (self::collectFiles($pluginDir) as $relative) {
                self::copyNormalized($pluginDir . '/' . $relative, $stage . '/' . $slug . '/' . $relative);
                $entries[] = $slug . '/' . $relative;
            }
            if (is_file($licenseFile) && ! in_array($slug . '/LICENSE', $entries, true)) {
                self::copyNormalized($licenseFile, $stage . '/' . $slug . '/LICENSE');
                $entries[] = $slug . '/LICENSE';
            }

            // Embed the shared OAuth library when the plugin opts in (the
            // build.json the opt-in rode was already validated at the config
            // seam above — no decode, no embed decision, happens down here).
            // The file vocabulary is the ONE shared-source collector: every
            // PHP source under shared/src ships, wherever it lives — the
            // dist-tree exclusion list deliberately does NOT apply here
            // (t31-r3-4), and non-PHP files are not sources (t31-r2-18).
            if ($embedShared) {
                foreach (wp_connectors_php_source_files($sharedDir) as $relative) {
                    $source = (string) file_get_contents($sharedDir . '/' . $relative);
                    $rewritten = self::rewriteSharedNamespace($source, $pluginSuffix, 'shared/src/' . $relative);
                    $target = $stage . '/' . $slug . '/src/Shared/' . $relative;
                    @mkdir(dirname($target), 0755, true);
                    self::writeNormalized($rewritten, $target);
                    $entries[] = $slug . '/src/Shared/' . $relative;
                }
            }

            sort($entries, SORT_STRING);

            // Zip deterministically: fixed order, mtimes already normalized.
            $zip = new ZipArchive();
            if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                throw new RuntimeException("build: cannot create {$zipPath}");
            }
            $zipOpened = true;
            $zipOverwritten = true;
            foreach ($entries as $entry) {
                if (true !== $zip->addFile($stage . '/' . $entry, $entry)) {
                    throw new RuntimeException("build: cannot add {$entry} to {$zipName}");
                }
            }
            /*
             * Finalization is CHECKED (t31-r4-3): close() writes the
             * archive and returns FALSE on a failed write (an unreadable
             * staged source at read time, a destination that stopped
             * accepting) — after OVERWRITE already destroyed any
             * previous good zip at the path. The unchecked close() let
             * the failure fall through to hash_file() on a zip that was
             * not written and a blank-checksum sidecar with exit 0,
             * precisely the t31-r3-16 invariant failing at the seam its
             * re-open clause anticipated. The release flag is cleared
             * BEFORE the call: a FAILED close() has already torn the
             * archive object down (a second close() is a ValueError on
             * this runtime, empirically confirmed), while the artifact
             * set stays corrupted — the $zipOverwritten cleanup below
             * owns that half.
             */
            $zipOpened = false;
            self::closeArchiveOrThrow($zip, $zipName);
        } catch (RuntimeException $buildFailure) {
            /*
             * Scope the artifact cleanup to what THIS run wrote
             * (verifier round t31-r3-16): the old shape unlinked
             * $zipPath on every throw, so a mid-build failure BEFORE the
             * archive was opened deleted a PREVIOUS successful build's
             * zip at the same path while its .sha256 sidecar and
             * checksums.txt entry kept advertising it — an orphaned
             * checksum for an artifact that no longer exists
             * (verifier-reproduced: sidecar and manifest survived the
             * deleted zip). A failure before open() never touched the
             * artifact set and leaves it exactly as found (the last
             * good release survives a failed rebuild); a failure after
             * open() has corrupted the archive, so the partial zip, its
             * sidecar, and its manifest entry all go together.
             *
             * t31-r4-3: the release and the artifact cleanup are
             * separate facts — the release runs only while the object
             * is still open (a failed close() already destroyed it), the
             * cleanup whenever the path was overwritten.
             */
            if ($zipOpened && $zip instanceof ZipArchive) {
                // Release the half-written archive before removing it.
                $zip->close();
            }
            if ($zipOverwritten) {
                @unlink($zipPath);
                @unlink($zipPath . '.sha256');
                self::removeManifestEntry($distDir . '/checksums.txt', $zipName);
            }
            throw $buildFailure;
        } finally {
            self::rrmdir($stage);
        }

        /*
         * Checksums: per-zip sidecar file + refreshed manifest entry.
         * Every write in the publication block is CHECKED (verifier round
         * t31-r4-15): the sidecar write was the one unchecked artifact
         * seam — a blocked .sha256 path shipped a sidecar-less zip with
         * exit 0 (reproduced), the round's artifact-integrity charter
         * failing one write past t31-r4-3's checked finalization. A
         * failure here takes the whole artifact set for this zip — the
         * archive was already overwritten, so the partial zip, its
         * sidecar, and its manifest entry go together (the t31-r3-16
         * after-open contract), and the run exits non-zero.
         */
        try {
            $checksum = hash_file('sha256', $zipPath);
            if (false === $checksum) {
                throw new RuntimeException("build: cannot checksum {$zipName} — refusing to publish a sidecar for an artifact that cannot be read");
            }
            // The @ suppresses only the diagnostic (the errno notice of
            // the failed write); the FAILED RETURN is owned below — the
            // glm17-16 idiom, so a blocked path refuses through this
            // check instead of aborting through the engine's warning.
            if (false === @file_put_contents($zipPath . '.sha256', $checksum . '  ' . $zipName . "\n")) {
                throw new RuntimeException("build: cannot write the checksum sidecar for {$zipName} — a failed artifact write never exits 0 with a half-described artifact set");
            }

            $manifestPath = $distDir . '/checksums.txt';
            $manifest = self::manifestLinesWithout($manifestPath, $zipName);
            $manifest[] = $zipName . '  ' . $checksum;
            sort($manifest, SORT_STRING);
            self::writeManifestAtomically($manifestPath, $manifest);
        } catch (RuntimeException $publicationFailure) {
            @unlink($zipPath);
            @unlink($zipPath . '.sha256');
            self::removeManifestEntry($distDir . '/checksums.txt', $zipName);
            throw $publicationFailure;
        }

        return $zipPath;
    }

    /**
     * Lands the checksum manifest atomically (review round t31-r4-8).
     *
     * The manifest is PER-RUN-ATOMIC: a run updates only the entries of
     * the plugin(s) it built (manifestLinesWithout() keeps every other
     * line byte-for-byte) and lands the result with temp + rename, so a
     * crash or a mid-write failure can never leave a half-written
     * manifest behind — and never deletes one either. The CLI's old
     * pre-run unlink dropped EVERY other plugin's entry on a --slug
     * rebuild and left the manifest gone after a failing rebuild, its
     * sidecars orphaned (reproduced) — the t31-r3-16 invariant was
     * false at the CLI seam. An empty line set removes the manifest
     * outright (a blank manifest file is not a state worth keeping —
     * the removeManifestEntry contract, now riding the same writer).
     *
     * @param string        $manifestPath Absolute checksums.txt path.
     * @param list<string>  $lines        Entry lines, sorted, non-empty.
     * @return void
     * @throws RuntimeException When the manifest cannot be written.
     */
    private static function writeManifestAtomically($manifestPath, array $lines)
    {
        if ($lines === array()) {
            @unlink($manifestPath);

            return;
        }
        $temp = $manifestPath . '.tmp';
        // @: the diagnostic is suppressed, the failed return owned below
        // (glm17-16) — a blocked path refuses through the check.
        if (false === @file_put_contents($temp, implode("\n", $lines) . "\n")) {
            throw new RuntimeException("build: cannot write the checksum manifest staging file {$temp}");
        }
        if (! rename($temp, $manifestPath)) {
            @unlink($temp);
            throw new RuntimeException("build: cannot finalize the checksum manifest at {$manifestPath}");
        }
    }

    /**
     * The manifest's lines minus one zip's entry (and blanks).
     *
     * The ONE entry filter (verifier round t31-r3-16) shared by the
     * success path (which appends a fresh entry after the filter) and
     * the mid-build failure path (which removes the entry together with
     * the corrupted artifact it described) — the two cannot drift on
     * what counts as an entry line.
     *
     * @param string $manifestPath Absolute checksums.txt path.
     * @param string $zipName      Zip basename the entry names.
     * @return list<string> The surviving lines.
     */
    private static function manifestLinesWithout($manifestPath, $zipName)
    {
        $manifest = array();
        if (is_file($manifestPath)) {
            foreach (explode("\n", (string) file_get_contents($manifestPath)) as $line) {
                if ($line === '' || strpos($line, $zipName . '  ') === 0) {
                    continue;
                }
                $manifest[] = $line;
            }
        }

        return $manifest;
    }

    /**
     * Removes one zip's manifest entry — and a manifest the removal
     * empties, outright (a blank manifest file is not a state worth
     * keeping).
     *
     * @param string $manifestPath Absolute checksums.txt path.
     * @param string $zipName      Zip basename the entry names.
     * @return void
     */
    private static function removeManifestEntry($manifestPath, $zipName)
    {
        if (! is_file($manifestPath)) {
            return;
        }
        // The removal rides the same atomic writer as the success path
        // (t31-r4-8): a manifest the removal empties is removed outright,
        // one with survivors lands whole — never half-written.
        self::writeManifestAtomically($manifestPath, self::manifestLinesWithout($manifestPath, $zipName));
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
     * (review round t31-r4-3).
     *
     * ZipArchive::close() is the step that WRITES the archive — libzip
     * defers the staged sources' reads here — and it returns false on a
     * failed write (an unreadable staged source, a destination that
     * stopped accepting) while the previous good zip at the same path
     * was already destroyed by OVERWRITE at open(). The unchecked
     * $zip->close() let that failure fall through to hash_file() on a
     * zip that was never written and a blank-checksum sidecar with
     * exit 0 — the t31-r3-16 last-good-artifact invariant failing at
     * exactly the seam its re-open clause anticipated.
     *
     * A failed close() tears the archive object down with it (a second
     * close() is a ValueError on this runtime, empirically confirmed),
     * so the caller clears its release flag BEFORE calling and never
     * re-closes on this path.
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
     * @param string $from Absolute source path.
     * @param string $to   Absolute target path.
     * @return void
     */
    private static function copyNormalized($from, $to)
    {
        @mkdir(dirname($to), 0755, true);
        copy($from, $to);
        self::normalize($to);
    }

    /**
     * Writes content into the staging tree with normalized mtime/perms.
     *
     * @param string $content File content.
     * @param string $to      Absolute target path.
     * @return void
     */
    private static function writeNormalized($content, $to)
    {
        @mkdir(dirname($to), 0755, true);
        file_put_contents($to, $content);
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
