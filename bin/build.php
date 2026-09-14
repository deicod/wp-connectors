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
     * @param string $source        PHP source from shared/src.
     * @param string $pluginSuffix  Namespace segment, e.g. 'OpenAiOauth'.
     * @param string $sourceVersion Provenance string (repo-relative path/rev).
     * @return string Rewritten source ready for src/Shared/.
     * @throws RuntimeException When the namespace suffix is not a legal namespace segment, or when a spelling of the shared namespace survives the rewrite patterns.
     */
    public static function rewriteSharedNamespace($source, $pluginSuffix, $sourceVersion)
    {
        self::assertNamespaceSegment($pluginSuffix);
        $escapedVersion = str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $sourceVersion);
        $provenance = "/**\n * Generated copy of {$escapedVersion} for this plugin's private namespace.\n * Do not edit here; change the shared source and rebuild.\n */\n";
        $rewritten = (string) preg_replace(
            '/(namespace\s+)Deicod\\\\WpConnectors\\\\Shared((?:\\\\[A-Za-z0-9_]+)*\s*;)/',
            '$1Deicod\\\\WpConnectors\\\\' . $pluginSuffix . '\\\\Shared$2',
            $source
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
        $rewritten = (string) preg_replace(
            '/(?<![A-Za-z0-9_])((?:use\s+(?:function\s+|const\s+)?)\\\\?)Deicod\\\\WpConnectors\\\\Shared((?:\\\\[A-Za-z0-9_]+)*)(\s+as\s+[A-Za-z0-9_]+)?((?:\\\\)?\s*\{[^;}]*\})?\s*;/',
            '${1}Deicod\\\\WpConnectors\\\\' . $pluginSuffix . '\\\\Shared${2}${3}${4};',
            $rewritten
        );
        /*
         * Postcondition (t31-r3-2): the rewrite's contract is that NO
         * spelling of the source namespace survives. The patterns above
         * cover every legal shape this rewriter knows; anything else — a
         * comment-interrupted use line, a nested brace group — REFUSES the
         * build loudly instead of shipping a broken import (silent
         * survival was the defect this round closed). A LONGER name
         * ('...SharedStorage') is a different namespace, not a spelling
         * of this one, and the negative lookahead keeps it untouched.
         */
        if (1 === preg_match('/Deicod\\\\WpConnectors\\\\Shared(?![A-Za-z0-9_])/', $rewritten)) {
            throw new RuntimeException("build: a spelling of Deicod\\WpConnectors\\Shared survived the rewrite in {$sourceVersion} — extend rewriteSharedNamespace() for the spelling; every legal use form is rewritten here or the build refuses");
        }

        // Insert provenance directly after the open tag (never before it).
        return (string) preg_replace(
            '/^<\?php\b\s*/',
            "<?php\n\n" . $provenance . "\n",
            $rewritten,
            1
        );
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
            // Never follow symlinks: a link inside the plugin directory must
            // not package out-of-tree file contents into the zip.
            if ($file->isLink()) {
                continue;
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
         * Config seam (review round t31-r3-1): build.json is resolved and
         * validated ONCE, before any filesystem mutation. The old shape
         * decoded it inline inside the staging block, so a malformed
         * build.json (a trailing comma → json_decode() null → is_array()
         * false) SILENTLY skipped the embed_shared block — the zip shipped
         * without the shared library and the run exited 0 with a checksum,
         * a plugin that fatals on install. A build.json the loader touches
         * is a contract now: readable, and a JSON object — anything else
         * refuses the build loudly.
         */
        $embedShared = false;
        $sharedDir = '';
        $pluginSuffix = '';
        $buildConfig = $pluginDir . '/build.json';
        if (is_file($buildConfig)) {
            $rawConfig = file_get_contents($buildConfig);
            if (false === $rawConfig) {
                throw new RuntimeException("build: cannot read {$buildConfig}");
            }
            $config = json_decode($rawConfig, true);
            if (! is_array($config)) {
                $reason = JSON_ERROR_NONE === json_last_error()
                    ? 'the top-level value is not a JSON object'
                    : json_last_error_msg();
                throw new RuntimeException("build: {$slug}: build.json is malformed ({$reason}) — refusing instead of silently skipping the embed_shared configuration");
            }
            if (! empty($config['embed_shared'])) {
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
                $pluginSuffix = isset($config['namespace_suffix']) && '' !== (string) $config['namespace_suffix']
                    ? (string) $config['namespace_suffix']
                    : self::namespaceSuffixFromSlug($slug);
                self::assertNamespaceSegment($pluginSuffix);
                $embedShared = true;
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
            foreach ($entries as $entry) {
                if (true !== $zip->addFile($stage . '/' . $entry, $entry)) {
                    throw new RuntimeException("build: cannot add {$entry} to {$zipName}");
                }
            }
            $zip->close();
            $zipOpened = false;
        } catch (RuntimeException $buildFailure) {
            if ($zipOpened && $zip instanceof ZipArchive) {
                // Release the half-written archive before removing it.
                $zip->close();
            }
            @unlink($zipPath);
            throw $buildFailure;
        } finally {
            self::rrmdir($stage);
        }

        // Checksums: per-zip sidecar file + refreshed manifest entry.
        $checksum = hash_file('sha256', $zipPath);
        file_put_contents($zipPath . '.sha256', $checksum . '  ' . $zipName . "\n");

        $manifestPath = $distDir . '/checksums.txt';
        $manifest = array();
        if (is_file($manifestPath)) {
            foreach (explode("\n", (string) file_get_contents($manifestPath)) as $line) {
                if ($line === '' || strpos($line, $zipName . '  ') === 0) {
                    continue;
                }
                $manifest[] = $line;
            }
        }
        $manifest[] = $zipName . '  ' . $checksum;
        sort($manifest, SORT_STRING);
        file_put_contents($manifestPath, implode("\n", $manifest) . "\n");

        return $zipPath;
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

    // Remove stale manifest so checksums.txt always reflects exactly this run.
    @unlink($distDir . '/checksums.txt');

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
