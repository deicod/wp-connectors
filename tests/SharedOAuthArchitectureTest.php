<?php
/**
 * Architecture sweep for the shared OAuth contracts (Task 3.1).
 *
 * Proves the shared source is provider-neutral, host-free, and
 * build-rewrite-safe:
 *
 * - ZERO WordPress reach: no host function calls, hooks, option API,
 *   database object, or auth-salt constants anywhere under shared/src —
 *   WordPress is reached only through the ports (clock, HTTP transport,
 *   token storage).
 * - Provider neutrality: no provider names in code, defaults, or docs
 *   of the generic classes (provider config belongs to the per-plugin
 *   directories).
 * - Rewrite safety (round t31-r7, token-level): the build-time
 *   namespace rewrite (record 0005) touches only `namespace` and `use`
 *   statements, so the source may reference the Deicod\WpConnectors
 *   family ONLY as its own namespace tree and ONLY in those two
 *   rewritable positions — detected by the ONE token detector the
 *   build's postcondition also rides (names reassembled across
 *   comments, string literals judged by their unescaped value,
 *   siblings under the vendor prefix banned outright).
 * - No static mutable state (pure value objects).
 * - PSR-4 discipline: one type per file, path matching namespace, file
 *   name matching the declared type name.
 *
 * Mutation-tested: a planted add_action() call, a planted provider
 * name, a planted inline FQCN reference, and a planted static property
 * each fail their sweep (verified during bring-up, floor82-2's
 * discipline). Non-vacuity is guarded both by a swept-file floor and by
 * anchor files the sweep must contain.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

require_once __DIR__ . '/../bin/build.php';

final class SharedOAuthArchitectureTest extends WpConnectorsTestCase
{
    /**
     * Banned WordPress reach: functions, hooks, options, globals,
     * constants — everything that would smuggle a host dependency into
     * the shared source. Case-insensitive (PHP calls are).
     *
     * Review round t31-r4-11, the mechanism half: the closed stems end
     * in a LETTER-aware lookahead, not '\b' — '\b' treats '_' as a word
     * character, so the stems could never match their own '_'-suffixed
     * twins (apply_filters_ref_array/do_action_ref_array are the WP
     * spellings; the mechanism bug was verified, not curated). The
     * lookahead still refuses letter-extended lookalikes
     * ('apply_filterss' stays clean) and the leading '\b' still fences
     * prefixed names ('my_apply_filters' stays clean); '_' extensions
     * over-block in the safe direction (a dev rephrases).
     *
     * Review round t31-r4-11, the curation half (adjudicated): the
     * obviously-WP-conditional/admin surface functions a source could
     * plausibly reach for — is_admin, get_bloginfo, is_user_logged_in,
     * get_locale — join the vocabulary now; the REST of the curation
     * posture is the t31-r2-7 doctrine (spelling-curated, a spelling
     * joins when someone writes one), recorded in the ledger for this
     * round. wp_-prefixed names need no closed stem (the open
     * '\bwp_[a-z0-9_]+' class owns them).
     */
    private const WP_TOKEN_PATTERN = '/(?:\bwp_[a-z0-9_]+|\b(?:apply_filters|do_action|add_action|add_filter|remove_action|remove_filter|_doing_it_wrong|current_time|current_user_can|get_current_user_id|get_current_blog_id|is_admin|is_multisite|is_user_logged_in|is_wp_error|get_bloginfo|get_locale|get_option|update_option|add_option|delete_option|get_blog_option|update_blog_option|delete_blog_option|get_site_option|update_site_option|delete_site_option|switch_to_blog|restore_current_blog|get_transient|set_transient|delete_transient|get_user_meta|update_user_meta|register_setting|add_settings_(?:section|field)|add_submenu_page|register_(?:activation|deactivation|uninstall)_hook|plugin_dir_path|plugins_url|admin_url|network_admin_url|self_admin_url|site_url|home_url|get_site_url|get_home_url|add_query_arg|remove_query_arg|load_plugin_textdomain|check_admin_referer|check_ajax_referer|esc_[a-z0-9_]+|sanitize_[a-z0-9_]+|wpdb|wp_error)(?![A-Za-z])|\b__\s*\(|\b(?:AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT|ABSPATH|WPINC|WP_CONTENT_DIR|WP_PLUGIN_DIR|WPMU_PLUGIN_DIR)\b)/i';

    /**
     * Provider names the generic classes must not carry (word-bounded,
     * case-insensitive; z.ai spelled both ways).
     */
    private const PROVIDER_NAME_PATTERN = '/(?:\bz\.ai\b|\b(?:openai|xai|grok|anthropic|claude|codex|zai)\b)/i';

    /**
     * Static mutable state (pure value objects carry none).
     *
     * Covers the typed and untyped spellings with visibility on either
     * side (the PHP 8-idiomatic `private static int $counter = 0;`
     * bypassed the old `\bstatic\s+\$` form); the run also crosses one
     * level of balanced parentheses so DNF compound types
     * (`public static (A&B)|null $x;`, verifier round t31-r1-17)
     * cannot hide behind the type's own punctuation. `static
     * function`/`fn` (static methods and closures — legal,
     * immutable-state-free) are excluded; everything else from the
     * keyword to a variable is a hit. `readonly` cannot combine with
     * `static` (a fatal at compile time), so no exemption exists to
     * carve.
     */
    private const STATIC_MUTABLE_PATTERN = '/\bstatic(?!\s+function\b)(?!\s+fn\b)\s+(?:[^$;={}()]|\([^)]*\))*\$/';

    /**
     * Direct clock/environment reach (t31-r2-7): the PHP function
     * spellings that read the host clock or environment directly —
     * bypassing the clock port — plus the environment superglobals.
     *
     * Case-insensitive for the CALL names (PHP calls are), scoped so
     * the superglobals stay case-sensitive ($globals is an ordinary
     * variable, $GLOBALS the superglobal). The clock-read twins of
     * date() ride along (gmdate/mktime/idate/strftime, and the
     * no-argument clock readers getdate/localtime — verifier round
     * t31-r2-17, the floor82-2 'holes a recollection-assembled list
     * misses' class again) and putenv joins getenv (an environment
     * WRITE is the same seam); strtotime and date_create stay legal —
     * they are string-parse shapes, and the 'now'-reading spellings
     * they share are the port implementation's own (SystemClock's
     * DateTimeImmutable('now')), which a spelling-level sweep cannot
     * and should not ban. Prose naming a call shape is rewritten,
     * never exempted (the floor82-2 idiom).
     */
    private const DIRECT_ENVIRONMENT_PATTERN = '/\b(?i:time|microtime|hrtime|date|gmdate|mktime|idate|strftime|getdate|localtime|getenv|putenv)\s*\(|\$(?:_SERVER|_ENV|GLOBALS)\b/';

    /**
     * @return list<string> Absolute paths of every PHP file under shared/src.
     *
     * The file vocabulary is the ONE shared-source collector
     * (wp_connectors_php_source_files(), review round t31-r3-9): the
     * sweep judges exactly the file set the build's embed collection
     * ships — case-insensitive extension, no exclusion segments — so a
     * '.PHP'-spelled source can no longer be invisible to every gate
     * while development loads it, and a source under shared/src/tools/
     * can no longer sweep clean while the zip drops it (t31-r3-4's
     * class). One vocabulary, two consumers, no drift.
     *
     * Cached per process run (t31-r3-13): the sweep's consumers
     * re-walked shared/src up to seven times per run (every gate test
     * calls this). The tree is repo content, immutable for the
     * process's lifetime, so the walk happens once and every consumer
     * sees the same list.
     */
    private function sharedSourceFiles(): array
    {
        static $cached = null;

        $root = realpath(__DIR__ . '/../shared/src');
        $this->assertNotFalse($root, 'shared/src must exist — the contracts live there.');

        if (null === $cached) {
            $files = array();
            foreach (wp_connectors_php_source_files($root) as $relative) {
                $files[] = $root . '/' . $relative;
            }
            $cached = $files;
        }

        return $cached;
    }

    /**
     * The whole contents of one swept file, read LOUDLY.
     *
     * The shared read for every whole-file gate (t31-r2-5): an
     * unreadable path AND a file_get_contents() false return both fail
     * naming the file — a silent '' cast would sweep the file as
     * contentless for every gate that rides this reader (the t31-r1-21
     * doctrine, one more layer down).
     *
     * Successful reads are cached per path per process run (t31-r3-13):
     * the gates re-read each swept file roughly six times per run, and
     * the content a gate judges must be the same content every gate
     * judges — one read, one cached copy. Failed reads never cache (a
     * re-invocation re-probes, which is what the loud-failure pins
     * drive); scratch files are written before their first read, so no
     * path serves stale bytes.
     *
     * @param string $path File path.
     * @return string File contents.
     */
    private function fileContents(string $path): string
    {
        /** @var array<string, string> $readCache */
        static $readCache = array();

        if (isset($readCache[$path])) {
            return $readCache[$path];
        }
        if (!is_readable($path)) {
            $this->fail(
                sprintf(
                    'The architecture sweep cannot read %s — an unreadable swept file must fail loudly, never sweep as contentless lines.',
                    $path
                )
            );
        }

        $contents = @file_get_contents($path);
        if (false === $contents || ('' === $contents && !is_file($path))) {
            // The read is @-suppressed because ITS diagnostic (the
            // errno notice of a failed read) would surface as a test
            // error before this named failure could name the file —
            // the glm17-16 idiom: suppress the diagnostic, own the
            // failed return. The failure itself has TWO runtime
            // spellings: false on most unreadable paths, and '' on
            // PHP 8.5's directory reads (the errno-21 shape degrades
            // to an empty string, indistinguishable from a false
            // return without the guard) — a non-regular path is never
            // a legitimately-empty swept file, so both refuse.
            $this->fail(
                sprintf(
                    'The architecture sweep cannot read %s — the read itself failed (vanished or blocked between the readability probe and the read), and a silent empty sweep is never acceptable.',
                    $path
                )
            );
        }

        return $readCache[$path] = $contents;
    }

    /**
     * Lines of one file, as (line number => line) pairs.
     *
     * Reading failures are LOUD, never silent: an unreadable file
     * (chmod 000, vanished mid-sweep), a read that fails BETWEEN the
     * readability probe and the read itself (review round t31-r2-9 —
     * the old probe-then-(string)-cast shape swept such a file as ONE
     * contentless line), and a PCRE abort (one invalid UTF-8 byte under
     * /\R/u) all fail naming the file — the silent fallbacks would
     * sweep the file as zero-or-one contentless lines, every gate
     * would skip it, and the non-vacuity counts would stay green (the
     * glm36-8 doctrine applied one layer below by t31-r1-7, and one
     * layer above by t31-r1-21). The read rides the shared loud reader
     * (fileContents()), which owns both failure checks once.
     *
     * @param string $path File path.
     * @return list<array{0: int, 1: string}>
     */
    private function numberedLines(string $path): array
    {
        $split = preg_split('/\R/u', $this->fileContents($path));
        if (false === $split) {
            $this->fail(
                sprintf(
                    'The architecture sweep could not split %s into UTF-8 lines (PCRE abort) — a swept file must be valid UTF-8, or the sweep silently skips every gate for it.',
                    $path
                )
            );
        }

        $lines = array();
        foreach ($split as $index => $line) {
            $lines[] = array($index + 1, $line);
        }

        return $lines;
    }

    /**
     * ZERO WordPress reach — applied to the WHOLE FILE (t31-r3-8).
     *
     * The old per-line application (the shape the static/clock gates
     * left behind in t31-r2-5/16) was blind to any spelling that breaks
     * across lines ('$saved = __' / '( 'save' );' — the pattern's \s*
     * legitimately spans the break), and a PCRE abort read as a clean
     * file (`1 === preg_match` sees the abort's false as "no match").
     * Whole-file application through the shared helper closes both:
     * multiline spellings match, aborts refuse (t31-r2-16's doctrine),
     * and the diagnostic still names the file and the line the match
     * starts on.
     */
    public function testSharedSourceContainsZeroWordPressReach(): void
    {
        $files = $this->sharedSourceFiles();

        // Non-vacuity: a real tree, not an empty root — and the anchor
        // contracts must be inside the sweep.
        $this->assertGreaterThanOrEqual(20, count($files), 'The sweep must see the real contract tree.');
        $basenames = array_map('basename', $files);
        foreach (array('AccessTokenSet.php', 'ClockInterface.php', 'HttpTransportInterface.php', 'StoredGrant.php', 'TokenStorageInterface.php', 'OAuthRuntimeException.php') as $anchor) {
            $this->assertContains($anchor, $basenames, 'The sweep must include ' . $anchor);
        }

        foreach ($files as $path) {
            $this->assertPatternAbsentWholeFile(
                $path,
                self::WP_TOKEN_PATTERN,
                'WordPress reach inside shared/ (WordPress is reached only through the ports)'
            );
        }
    }

    /**
     * Review-round pin (t31-r1-7): the line reader a shared/src gate
     * rides fails LOUDLY on a file PCRE cannot decode — the old silent
     * `?: array()` fallback swept such a file as ZERO lines, all gates
     * skipped it, and the non-vacuity counts stayed green (reproduced
     * with a planted add_action in a corrupted file during bring-up).
     * Verifier-round extension (t31-r1-21): an UNREADABLE file failed
     * silently too — file_get_contents() false cast to '' swept as one
     * contentless line, all gates skipping the file the same way.
     */
    public function testAUtf8UndecodableFileFailsTheLineReaderLoudly(): void
    {
        $path = realpath(__DIR__ . '/fixtures/sweep-corruption/invalid-utf8-byte.txt');
        $this->assertNotFalse($path, 'The corruption fixture must exist.');

        try {
            (new \ReflectionMethod($this, 'numberedLines'))->invoke($this, $path);
            $this->fail('A file the line reader cannot decode must fail loudly, never sweep as zero lines.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('invalid-utf8-byte.txt', $e->getMessage());
            $this->assertStringContainsString('PCRE abort', $e->getMessage());
        }
    }

    public function testAnUnreadableFileFailsTheLineReaderLoudly(): void
    {
        try {
            (new \ReflectionMethod($this, 'numberedLines'))->invoke($this, __DIR__ . '/fixtures/sweep-corruption/vanished-file.php');
            $this->fail('A file the reader cannot open must fail loudly, never sweep as one contentless line.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('cannot read', $e->getMessage());
            $this->assertStringContainsString('vanished-file.php', $e->getMessage());
        }
    }

    /**
     * Fix-round pin (t31-r2-9): the read-failure class the is_readable
     * probe cannot see — a path that PASSES the probe but fails the
     * read itself (reproduced at HEAD with a readable directory: the
     * (string) cast of file_get_contents()'s false swept it as ONE
     * contentless line, [1 => '']). The line reader rides the shared
     * loud reader now, whose failed-return check owns exactly this
     * TOCTOU-narrowed shape — under BOTH runtime spellings: false
     * (/proc/self/map_files, the probe-passing unreadable file shape)
     * and the '' a PHP 8.5 directory read degrades to. The canary
     * records its flag OUTSIDE the catch (the glm29-16 discipline —
     * a fail() sentinel inside a catch of AssertionFailedError is
     * swallowed by its own catch).
     */
    public function testAReadableButUnreadablePathFailsTheLineReaderLoudly(): void
    {
        $reader = new \ReflectionMethod($this, 'numberedLines');

        // The '' spelling: a readable directory (errno-21 read failure
        // that 8.5 returns as an empty string).
        $directory = realpath(__DIR__ . '/fixtures/sweep-corruption');
        $this->assertNotFalse($directory, 'The sweep-corruption fixture directory must exist.');
        $this->assertTrue(is_readable($directory), 'The shape must pass the readability probe for the pin to exercise the read failure, not the probe.');

        $failed = null;
        try {
            $reader->invoke($this, $directory);
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $failed = $e->getMessage();
        }
        $this->assertNotNull($failed, 'A path whose read fails after passing the readability probe must fail loudly, never sweep as contentless lines.');
        $this->assertStringContainsString('read itself failed', $failed);
        $this->assertStringContainsString('sweep-corruption', $failed);

        // The false spelling: a procfs node that passes the readability
        // probe but refuses the read.
        $procNode = '/proc/self/map_files';
        if (is_readable($procNode) && !is_file($procNode)) {
            $failed = null;
            try {
                $reader->invoke($this, $procNode);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $failed = $e->getMessage();
            }
            $this->assertNotNull($failed, 'A readable-but-refusing procfs node must fail loudly through the same reader.');
            $this->assertStringContainsString('read itself failed', $failed);
        }
    }

    /**
     * Provider neutrality — whole-file like every other gate (t31-r3-8),
     * so the provider-name pattern cannot be the one gate a PCRE abort
     * reads as clean, and the shared/README.md ride-along is judged by
     * the same code path as the sources.
     */
    public function testSharedSourceAndReadmeNameNoProviders(): void
    {
        $paths = array_merge($this->sharedSourceFiles(), array(realpath(__DIR__ . '/../shared/README.md')));
        $this->assertGreaterThanOrEqual(21, count($paths));

        foreach ($paths as $path) {
            $this->assertPatternAbsentWholeFile(
                (string) $path,
                self::PROVIDER_NAME_PATTERN,
                'Provider name in the provider-neutral shared source (provider config belongs to the per-plugin directories)'
            );
        }
    }

    /**
     * The namespace gate, at the TOKEN level (t31-r7's terminal fix,
     * superseding t31-r4 K1's blank-then-regex gate and its
     * every-use-statement whitelist).
     *
     * The sweep's charter, restated over the ONE detector the build's
     * rewrite postcondition also rides
     * (wp_connectors_shared_family_references(), one vocabulary, two
     * consumers — what the sweep flags and what the build refuses
     * cannot drift): a shared source may reference the
     * Deicod\WpConnectors family ONLY as its OWN namespace tree
     * (Deicod\WpConnectors\Shared…) and ONLY in the two rewritable
     * positions (a namespace declaration, a use statement). Everything
     * else fails the gate loudly:
     *
     * - a SIBLING under the vendor prefix (Deicod\WpConnectors\<Other>…,
     *   the bare vendor prefix included — finding 5 narrows the old
     *   whitelist, which blanked EVERY use statement, to what the
     *   rewriter actually owns: the rewrite never touches a sibling
     *   import, so it ships pointing at a namespace that does not exist
     *   inside the plugin);
     * - the own namespace in a NON-rewritable position (code, string,
     *   comment, inline HTML — a comment can no longer hide the
     *   spelling: the token walk reassembles name runs across comments,
     *   and the comment's own TEXT is judged too);
     * - a string literal whose runtime VALUE names the family (the
     *   double-backslash class-string spelling, judged unescaped).
     *
     * The build's postcondition is the release guarantee over the same
     * findings (zero family references outside the rewritten target
     * prefix); this gate is the dev-time hygiene half.
     */
    public function testSharedSourceReferencesItsNamespaceTreeOnlyInRewritableForms(): void
    {
        $files = $this->sharedSourceFiles();
        $this->assertGreaterThanOrEqual(20, count($files), 'The namespace gate must see the real contract tree.');

        // Non-vacuity, both directions: the tree really carries family
        // references (every file DECLARES its namespace; most import
        // siblings of their own tree), and every one of them is an
        // own-rooted declaration or import.
        $family = 0;
        foreach ($files as $path) {
            $this->assertFamilyReferencesStayRewritable($path);
            foreach (wp_connectors_shared_family_references($this->fileContents($path)) as $reference) {
                ++$family;
            }
        }
        $this->assertGreaterThanOrEqual(count($files), $family, 'Every swept file must declare its own namespace — the gate judges real family references, not an empty set.');
    }

    /**
     * The enumerated import vocabulary of the legal tree (t31-r7: "the
     * current shared tree is the ground truth for what's legal —
     * enumerate and pin").
     *
     * Beyond the family, a shared source may import ONLY the platform
     * classes the tree already uses. A new import — a vendor package, a
     * WordPress shim, anything — fails this pin until it is
     * deliberately added here (fail-loud curation, the WP-reach
     * vocabulary's own doctrine), keeping the shared source
     * dependency-free by construction rather than by recall.
     */
    public function testTheSharedTreeSImportVocabularyIsTheEnumeratedLegalSet(): void
    {
        $legal_platform = array('DateTimeImmutable', 'DateTimeZone', 'InvalidArgumentException', 'RuntimeException', 'Throwable');
        $own_lower = strtolower(wp_connectors_shared_source_namespace());

        $platform = array();
        $own = 0;
        foreach ($this->sharedSourceFiles() as $path) {
            foreach (wp_connectors_use_statement_names($this->fileContents($path)) as $name) {
                $lower = strtolower($name);
                if ($lower === $own_lower || 0 === strpos($lower, $own_lower . '\\')) {
                    ++$own;

                    continue;
                }
                $platform[] = $name;
            }
        }
        sort($platform);

        $this->assertNotSame(array(), $platform, 'The enumeration pin must be non-vacuous: the legal tree carries platform imports.');
        $this->assertSame(
            array(),
            array_values(array_diff($platform, $legal_platform)),
            sprintf(
                'A shared source imports beyond the enumerated platform set (%s): found %s. A new import joins the tree only by being deliberately pinned here — the same fail-loud curation the WP-reach vocabulary rides.',
                implode(', ', $legal_platform),
                implode(', ', array_values(array_unique(array_diff($platform, $legal_platform))))
            )
        );
        $this->assertGreaterThan(0, $own, 'The own-tree import count must be non-vacuous.');
    }

    /**
     * Round-7 pin, end-to-end through the ACTUAL gate, both directions:
     * every spelling class the regex rounds missed one-at-a-time now
     * fails at the token level — the t31-r4-5 multiline string (still
     * failing, now via the string lens), the comment-interrupted use
     * (t31-r7-1: the comment can only interrupt the run, never hide the
     * name), the sibling import and the group-use member carrying one
     * (t31-r7-2), the double-backslash class-string (t31-r7-4, judged by
     * its unescaped VALUE), the docblock @throws (the comment's own text
     * judged), the inline FQCN and the bare vendor-prefix import (the
     * narrowed whitelist, t31-r7-5). The clean direction: everything the
     * rewriter OWNS stays clean — own declarations and imports, group
     * members, platform imports — and a real swept source passes.
     */
    public function testTheNamespaceGateCatchesEveryUnownedFamilyReferenceEndToEnd(): void
    {
        $gate = new \ReflectionMethod($this, 'assertFamilyReferencesStayRewritable');

        // The carried-forward t31-r4-5 fixture: a string literal broken
        // across lines, still failing with the file and line named.
        $fixture = realpath(__DIR__ . '/fixtures/sweep-corruption/namespace-multiline.php');
        $this->assertNotFalse($fixture, 'The namespace-multiline fixture must exist.');

        try {
            $gate->invoke($this, $fixture);
            $this->fail('A namespace spelling broken across lines outside a rewritable statement must fail the gate.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('namespace-multiline.php:24', $e->getMessage());
            $this->assertStringContainsString("return 'Deicod\\WpConnectors\\", $e->getMessage());
        }

        $offenders = array(
            'comment-interrupted use' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod/* pick one */\\WpConnectors\\Shared\\Clock;\ninterface InterruptedFixture\n{\n}\n",
            'whitespace-interrupted use, after the separator (t31-r7-6)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\\nShared\\Clock;\ninterface WhitespaceFixture\n{\n}\n",
            'whitespace-interrupted code reference, after the separator (t31-r7-6)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface WhitespaceCodeFixture\n{\n    public function name(): string;\n}\nfinal class WhitespaceCodeCarrier\n{\n    public function name(): string\n    {\n        return \\Deicod\\WpConnectors\\\nShared\\Clock::class;\n    }\n}\n",
            'sibling import' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Zai\\ApiClient;\ninterface SiblingFixture\n{\n}\n",
            'double-backslash class-string' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface ClassStringFixture\n{\n    public function name(): string;\n}\nfinal class Carrier\n{\n    public function name(): string\n    {\n        return 'Deicod\\\\WpConnectors\\\\Shared\\\\Clock';\n    }\n}\n",
            'group-use member carrying a sibling' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\{Shared\\Clock, Zai\\Api};\ninterface GroupSiblingFixture\n{\n}\n",
            'docblock @throws reference' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n/**\n * @throws \\Deicod\\WpConnectors\\Shared\\Exception\\OAuthRuntimeException\n */\ninterface DocblockFixture\n{\n}\n",
            'inline fully-qualified reference' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface InlineFixture\n{\n    public function name(): string;\n}\nfinal class InlineCarrier\n{\n    public function name(): string\n    {\n        return \\Deicod\\WpConnectors\\Shared\\Clock::class;\n    }\n}\n",
            'bare vendor-prefix import' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors;\ninterface BarePrefixFixture\n{\n}\n",
            'dangling as eats the next reference (t31-r7-7)' => "<?php\nnamespace A;\nuse Foo\\Bar as;\n\$x = \\Deicod\\WpConnectors\\Shared\\Clock::class;\n",
            'dangling as survives the close tag (t31-r8-1)' => "<?php\nnamespace A;\nuse Foo\\Bar as ?>\ninline HTML\n<?php\n\$x = \\Deicod\\WpConnectors\\Shared\\Clock::class;\n",
            'relative operator escaping into the family (t31-r8-2)' => "<?php\nnamespace Deicod;\n\$x = namespace\\WpConnectors\\Shared\\Clock::class;\n",
            'docblock naming a sibling (t31-r8-3)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n/**\n * @throws \\Deicod\\WpConnectors\\Zai\\ApiClient\n */\ninterface DocSiblingFixture\n{\n}\n",
            'source-spelled TARGET-rooted code reference (t31-r7-8)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ninterface TargetCodeFixture\n{\n    public function name(): string;\n}\nfinal class TargetCodeCarrier\n{\n    public function name(): string\n    {\n        return \\class_exists(\\Deicod\\WpConnectors\\ExampleConnector\\Shared\\Clock\\Ghost::class);\n    }\n}\n",
            'docblock naming the rewrite TARGET (t31-r7-8)' => "<?php\nnamespace Deicod\\WpConnectors\\Shared;\n/**\n * @throws \\Deicod\\WpConnectors\\ExampleConnector\\Shared\\Clock\\Ghost\n */\ninterface TargetDocblockFixture\n{\n}\n",
        );
        /*
         * One FRESH scratch path per shape: the shared loud reader caches
         * contents per path (t31-r3-13), so rewriting one path across
         * iterations would judge every later shape against the FIRST
         * shape's cached bytes — the loop would prove nothing past row
         * one.
         */
        foreach ($offenders as $label => $source) {
            $scratch = tempnam(sys_get_temp_dir(), 'wpct-ns-gate-');
            try {
                file_put_contents($scratch, $source);
                try {
                    $gate->invoke($this, $scratch);
                    $this->fail("A family reference the rewrite does not own ({$label}) must fail the gate, never ride the old every-use-statement whitelist.");
                } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                    $this->assertStringContainsString('Deicod\\WpConnectors', $e->getMessage(), "The refusal must name the reference ({$label}).");
                    $this->assertStringContainsString(basename($scratch), $e->getMessage(), "The refusal must name the file ({$label}).");
                }
            } finally {
                unlink($scratch);
            }
        }

        // The clean direction, through the same gate on its own fresh
        // path: everything the rewriter owns (own declaration,
        // plain/group/function own imports) plus the enumerated platform
        // imports and a relative `namespace\` operator — no finding
        // (t31-r8-2 makes that operator LOAD-BEARING: it resolves
        // against the own-root declaration and adapts through the
        // rewrite, so it must stay clean while its escaping twin above
        // refuses).
        $clean = tempnam(sys_get_temp_dir(), 'wpct-ns-gate-clean-');
        try {
            file_put_contents(
                $clean,
                "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nuse Deicod\\WpConnectors\\Shared\\Clock;\nuse Deicod\\WpConnectors\\Shared\\Http\\{HeaderMap, Url as U};\nuse function Deicod\\WpConnectors\\Shared\\Clock\\now;\nuse DateTimeImmutable;\nuse InvalidArgumentException;\ninterface FormsFixture\n{\n    public function now(): \\DateTimeImmutable;\n    public function self(): namespace\\FormsFixture;\n}\n"
            );
            $gate->invoke($this, $clean);
        } finally {
            unlink($clean);
        }

        // A real swept source passes the same gate.
        $gate->invoke($this, (string) realpath(__DIR__ . '/../shared/src/Http/HeaderMap.php'));
    }

    /**
     * The namespace gate for ONE file, two layers over the ONE detector:
     * every family reference must be an own-namespace declaration or
     * import (the position/rooting policy below), and the file must
     * REWRITE clean through the build's own postcondition (the ownership
     * half — a use statement is legal exactly when the rewrite DID
     * rewrite it). Anything else fails with the file, line, reference,
     * and the reason named.
     *
     * Private and path-parameterized so the mutation discipline can
     * drive the ACTUAL gate on planted fixtures (both directions).
     *
     * @param string $path Absolute file path.
     * @return void
     */
    private function assertFamilyReferencesStayRewritable(string $path): void
    {
        $contents = $this->fileContents($path);
        $own_lower = strtolower(wp_connectors_shared_source_namespace());

        foreach (wp_connectors_shared_family_references($contents) as $reference) {
            if ('pcre-abort' === $reference['kind']) {
                $this->fail(
                    sprintf(
                        'The namespace gate aborted (PCRE) on %s — an abort is a REFUSAL, never a clean pass.',
                        $path
                    )
                );
            }

            $reason = '';
            if ('declaration' !== $reference['kind'] && 'use' !== $reference['kind']) {
                $reason = sprintf('the %s position is not rewritable (only namespace declarations and use statements are rewritten)', $reference['kind']);
            } elseif ($reference['lower'] !== $own_lower && 0 !== strpos($reference['lower'], $own_lower . '\\')) {
                $reason = sprintf('%s is not the shared tree\'s own namespace — a sibling (or the bare vendor prefix) under Deicod\\WpConnectors, which the rewrite never touches and whose classes do not exist inside a plugin\'s private tree', $reference['name']);
            }
            if ('' === $reason) {
                continue;
            }

            $original_line = '';
            foreach ($this->numberedLines($path) as [$number, $text]) {
                if ($number === $reference['line']) {
                    $original_line = trim($text);
                    break;
                }
            }
            $this->fail(
                sprintf(
                    'A shared-namespace-family reference the rewrite does not own breaks the build-time rewrite: %s:%d — %s (%s): %s',
                    $path,
                    $reference['line'],
                    $reference['name'],
                    $reference['kind'],
                    $original_line
                )
            );
        }

        /*
         * The OWNERSHIP half of the narrowed whitelist (t31-r7-5): a use
         * statement is legal exactly when the rewrite DID rewrite it —
         * verified by REWRITING and holding the build's own
         * postcondition over the output (the token walk on the rewritten
         * bytes). This makes the sweep and the build give ONE verdict by
         * construction: a use spelling the rewriter's patterns miss (a
         * comment-interrupted import, say) fails here at dev time with
         * the same refusal the build would raise, instead of riding a
         * whitelist that never asked whether the rewrite succeeds.
         */
        try {
            WpConnectorsBuild::rewriteSharedNamespace($contents, 'ExampleConnector', $path);
        } catch (\RuntimeException $e) {
            $this->fail(
                sprintf(
                    'A namespace spelling the rewriter does not own breaks the build-time rewrite (the sweep and the build give one verdict): %s — %s',
                    $path,
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * No static mutable state — applied to the WHOLE FILE (t31-r2-5).
     *
     * The old per-line application was blind to any property spelling
     * that breaks across lines ('private static\n    int $counter;'),
     * which the pattern (whose \s+ legitimately spans the break) then
     * matched only as a joined string in the battery — never through
     * the actual gate. Whole-file application closes that; a prose hit
     * ('static …' running into a '$' before any statement punctuation)
     * would over-block in the safe direction, the posture this pattern
     * already carries per its ledgered residuals.
     */
    public function testSharedSourceCarriesNoStaticMutableState(): void
    {
        $this->assertGreaterThanOrEqual(20, count($this->sharedSourceFiles()), 'The static-mutable sweep must see the real contract tree.');

        foreach ($this->sharedSourceFiles() as $path) {
            $this->assertCarriesNoStaticMutableState($path);
        }
    }

    /**
     * The static-mutable gate for ONE file: whole-content match, with
     * the diagnostic located to the line the match starts on.
     *
     * Private and path-parameterized so the mutation discipline can
     * drive the ACTUAL gate on a planted fixture (both directions).
     *
     * @param string $path Absolute file path.
     * @return void
     */
    private function assertCarriesNoStaticMutableState(string $path): void
    {
        $this->assertPatternAbsentWholeFile(
            $path,
            self::STATIC_MUTABLE_PATTERN,
            'Static mutable state inside shared/ (pure value objects carry none)'
        );
    }

    /**
     * The clock/environment gate for ONE file: same whole-file shape
     * (a call spelling may break between name and paren across lines —
     * the t31-r2-5 lesson applies to call shapes too), same
     * line-located diagnostic, fixture-drivable for the mutation
     * discipline.
     *
     * @param string $path Absolute file path.
     * @return void
     */
    private function assertNoDirectEnvironmentAccess(string $path): void
    {
        $this->assertPatternAbsentWholeFile(
            $path,
            self::DIRECT_ENVIRONMENT_PATTERN,
            'Direct clock/environment access inside shared/ (the clock port owns time reads; configuration owns environment reads)'
        );
    }

    /**
     * Whole-content pattern gate shared by every whole-file sweep:
     * matches the ENTIRE file (multiline spellings included), fails
     * naming the file and the line the match starts on — and a PCRE
     * ABORT refuses the file (verifier round t31-r2-16, the glm36-8
     * doctrine the round itself applies at the production ban gates):
     * the static-mutable pattern's nested star exhausts the recursion
     * limit on roughly 100 KB subjects, and the first form's
     * `1 === preg_match(...)` read the abort's false return as
     * "clean" — the fail-open twin of the burner-statement class
     * glm36-8 closed, reachable only through the whole-file move
     * (t31-r2-5; a single source line was never near the limit).
     *
     * @param string $path    Absolute file path.
     * @param string $pattern The banned-content pattern.
     * @param string $label   Failure label naming the violated rule.
     * @return void
     */
    private function assertPatternAbsentWholeFile(string $path, string $pattern, string $label): void
    {
        $contents = $this->fileContents($path);
        $match = array();
        $result = preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE);
        if (false === $result) {
            $this->fail(
                sprintf(
                    '%s: the sweep could not match %s against the whole file (PCRE abort: %s) — an abort is a REFUSAL, never a clean pass.',
                    $label,
                    $path,
                    preg_last_error_msg()
                )
            );
        }
        if (1 === $result) {
            $before = substr($contents, 0, $match[0][1]);
            $line_start = false === ($last_newline = strrpos($before, "\n")) ? 0 : $last_newline + 1;
            $line_end = (int) strpos($contents . "\n", "\n", $line_start);
            $this->fail(
                sprintf(
                    '%s: %s:%d — %s',
                    $label,
                    $path,
                    substr_count($before, "\n") + 1,
                    trim(substr($contents, $line_start, $line_end - $line_start))
                )
            );
        }
    }

    /**
     * Review-round pin (t31-r1-8): the static-mutable pattern must
     * catch EVERY property spelling — typed, untyped, nullable, union,
     * qualified, visibility on either side, multiline — while leaving
     * the legal statics (methods, closures, late-static-binding) alone.
     * Each row is a mutation: a pattern regression fails its row.
     */
    public function testStaticMutablePatternCatchesEveryPropertySpelling(): void
    {
        $pattern = (new \ReflectionClass(self::class))->getConstant('STATIC_MUTABLE_PATTERN');

        $mustFlag = array(
            'private static int $counter = 0;',
            'private static ?string $label = null;',
            'static $x = 1;',
            'protected static array $cache = array();',
            'public static int|false $flag;',
            'private static \\Foo\\Bar $service;',
            'static private $y;',
            'public static int $a, $b = 2;',
            'public static (A&B)|null $dnf = null;',
            "private static\n    int \$multiline;",
        );
        foreach ($mustFlag as $spelling) {
            $this->assertSame(1, preg_match($pattern, $spelling), 'The pattern must flag: ' . $spelling);
        }

        $mustNotFlag = array(
            'public static function mask( ?string $value ): string',
            'static function () use ( $x ) {}',
            'static fn($x) => $x + 1;',
            'return static::SOME_CONST . $suffix;',
            'new static($arg);',
            'abstract static function f($a);',
        );
        foreach ($mustNotFlag as $spelling) {
            $this->assertSame(0, preg_match($pattern, $spelling), 'The pattern must not flag: ' . $spelling);
        }
    }

    /**
     * Fix-round pin (t31-r2-5), end-to-end through the ACTUAL gate: a
     * property spelling broken across lines ('private static' and the
     * typed variable on different lines) must fail the gate with the
     * file and line named — the old per-line application never saw it
     * (verified empirically at HEAD: every line of the fixture is
     * pattern-clean; the whole file is not). The clean direction rides
     * a real shared source file, so the gate provably runs content
     * through, not past, the fixture class below.
     */
    public function testTheStaticMutableGateCatchesMultilineSpellingsEndToEnd(): void
    {
        $gate = new \ReflectionMethod($this, 'assertCarriesNoStaticMutableState');

        $fixture = realpath(__DIR__ . '/fixtures/sweep-corruption/static-multiline.php');
        $this->assertNotFalse($fixture, 'The multiline-static fixture must exist.');

        try {
            $gate->invoke($this, $fixture);
            $this->fail('A multiline static-property spelling must fail the actual gate, never pass line-by-line.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('static-multiline.php:14', $e->getMessage());
            $this->assertStringContainsString('private static', $e->getMessage());
        }

        // The legal statics of a real swept file stay clean through the
        // same code path (Url.php spells no statics at all; HeaderMap's
        // statics are consts and methods).
        $gate->invoke($this, realpath(__DIR__ . '/../shared/src/Http/Url.php'));
        $gate->invoke($this, realpath(__DIR__ . '/../shared/src/Http/HeaderMap.php'));

        // And the pattern-level mutation twin, whole-file shaped: the
        // joined string the battery pins, planted in file position.
        $this->assertSame(1, preg_match(self::STATIC_MUTABLE_PATTERN, "class A {\n    private static\n    int \$multiline;\n}\n"));
    }

    /**
     * Fix-round pin (t31-r2-7): shared/README claims the host is
     * reached ONLY through the ports — but nothing banned the direct
     * PHP clock/environment spellings (time(), microtime(), hrtime(),
     * date(), getenv(), $_SERVER/$GLOBALS, and their twins). The tree
     * was clean by convention; this gate keeps it so (whole-file
     * application, so a call split between name and paren cannot
     * slip the gate either).
     */
    public function testSharedSourceReachesClockAndEnvironmentOnlyThroughThePorts(): void
    {
        $files = $this->sharedSourceFiles();
        $this->assertGreaterThanOrEqual(20, count($files), 'The clock/environment sweep must see the real contract tree.');

        foreach ($files as $path) {
            $this->assertNoDirectEnvironmentAccess($path);
        }
    }

    /**
     * The vocabulary battery (t31-r2-7), both directions: every
     * banned spelling flags (calls case-insensitively — PHP calls are;
     * superglobals case-sensitively — $globals is an ordinary
     * variable), and the legal lookalikes stay clean.
     */
    public function testDirectEnvironmentPatternMatchesExactlyTheVocabulary(): void
    {
        $pattern = (new \ReflectionClass(self::class))->getConstant('DIRECT_ENVIRONMENT_PATTERN');

        $mustFlag = array(
            '$expires = time();',
            '$t = Time();',
            'hrtime(true)',
            'microtime()',
            "date( 'Y' )",
            'getenv("PROXY_URL")',
            'PUTENV("LC_ALL=C")',
            'mktime(0, 0, 0)',
            'getdate()',
            'GETDATE()',
            'localtime()',
            "\$_SERVER['REQUEST_TIME']",
            '$GLOBALS[\'offenders\']',
            '$env = $_ENV;',
        );
        foreach ($mustFlag as $spelling) {
            $this->assertSame(1, preg_match($pattern, $spelling), 'The pattern must flag: ' . $spelling);
        }

        $mustNotFlag = array(
            'runtime()',
            'update()',
            'strtotime($value)',
            'date_create_from_format($format, $value)',
            '$timestamp',
            'DateTimeImmutable',
            'elapsed seconds, UTC-projected,',
        );
        foreach ($mustNotFlag as $spelling) {
            $this->assertSame(0, preg_match($pattern, $spelling), 'The pattern must not flag: ' . $spelling);
        }
    }

    /**
     * End-to-end through the ACTUAL gate (t31-r2-7): a planted time()
     * call in a VO-shaped file fails with file:line; the port
     * implementation's own legal spelling (SystemClock's
     * DateTimeImmutable('now')) passes the same code path — the sweep
     * bans the seam, not the clock itself.
     */
    public function testAPlantedDirectClockReadFailsTheGateEndToEnd(): void
    {
        $gate = new \ReflectionMethod($this, 'assertNoDirectEnvironmentAccess');

        $fixture = realpath(__DIR__ . '/fixtures/sweep-corruption/planted-clock.php');
        $this->assertNotFalse($fixture, 'The planted-clock fixture must exist.');

        try {
            $gate->invoke($this, $fixture);
            $this->fail('A direct time() call inside a swept file must fail the gate with file:line.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('planted-clock.php:14', $e->getMessage());
            $this->assertStringContainsString('time()', $e->getMessage());
        }

        $gate->invoke($this, realpath(__DIR__ . '/../shared/src/Clock/SystemClock.php'));
    }

    /**
     * Verifier-round pin (t31-r2-16): the whole-file gate read its
     * match as `1 === preg_match(...)` — a PCRE abort (the
     * static-mutable pattern's nested star exhausts the recursion
     * limit near 100 KB, a size whole-file application alone makes
     * reachable) returned false and the file passed SILENTLY while
     * carrying a real violation. The abort is a REFUSAL now, the
     * glm36-8 doctrine the production ban gates already spell. The
     * burner is generated at runtime (a ~100 KB hostile shape is not
     * a fixture the tree should carry); the canary records its flag
     * OUTSIDE the catch (glm29-16).
     */
    public function testAPcreAbortRefusesTheWholeFileGateNeverPassesIt(): void
    {
        $gate = new \ReflectionMethod($this, 'assertCarriesNoStaticMutableState');
        $violation = "\nfinal class Burner\n{\n    private static\n        int \$counter;\n}\n";

        /*
         * Scratch hygiene (t31-r3-11): the burner and the clean twin are
         * tempnam files whose names match NO tearDown glob — an assertion
         * failure between creation and unlink leaked them into /tmp. The
         * lifecycles ride try/finally now, on every exit path.
         */
        $burner = tempnam(sys_get_temp_dir(), 'wpct-pcre-burner-');
        try {
            file_put_contents($burner, '<?php' . str_pad('// ', 50000, 'x') . "\n static " . str_pad('', 50000, 'y') . $violation);

            $aborted = false;
            try {
                $gate->invoke($this, $burner);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $aborted = true;
                $this->assertStringContainsString('PCRE abort', $e->getMessage(), 'The abort refusal must name itself, not masquerade as a located hit or a clean pass.');
                $this->assertStringContainsString(basename($burner), $e->getMessage());
            }
            $this->assertTrue($aborted, 'A file that trips the PCRE recursion limit must be REFUSED, never swept as clean (the verifier reproduced a real violation passing silently behind a burner).');
        } finally {
            unlink($burner);
        }

        // The clean direction: padding-only content of the same scale
        // passes (the refusal is the abort, not the size).
        $clean = tempnam(sys_get_temp_dir(), 'wpct-pcre-clean-');
        try {
            file_put_contents($clean, '<?php' . str_pad('// prose about static behaviour and nothing else ', 10000, 'x'));
            $gate->invoke($this, $clean);
        } finally {
            unlink($clean);
        }
    }

    /**
     * Fix-round pin (t31-r3-9), SUPERSEDED by t31-r5-3's casing
     * doctrine and restated: the sweep's file vocabulary was its own
     * case-SENSITIVE '.php' filter, so a '.PHP'-spelled source was
     * silently skipped from embeds and never judged by any gate (no
     * gate ever saw it). r3-9/r4-9 made the shared collector accept
     * any casing; t31-r5-3 narrows THIS tree again — the collector
     * refuses the non-canonical casing loudly (the shipped autoloader
     * probes lowercase '.php', so a shipped .PHP copy is a class no
     * loader reaches), which is strictly stronger than both the
     * silently-skipped and the silently-dead ships. The CONTENT-gate
     * half of the r3-9 pin survives unchanged below: the gates that
     * judge EXISTING files (driven directly here, as the
     * self-containment walker drives them in plugin trees) still judge
     * any casing — the refusal is a collector doctrine, not a
     * classifier blind spot.
     */
    public function testTheSweepVocabularyRefusesNonCanonicalExtensionCasing(): void
    {
        $scratch = tempnam(sys_get_temp_dir(), 'wpct-phpcase-');
        unlink($scratch);
        mkdir($scratch, 0755, true);

        try {
            file_put_contents($scratch . '/ClockMath.PHP', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {\n    public function stamp(): int {\n        return time();\n    }\n}\n");
            file_put_contents($scratch . '/Notes.md', "# developer notes\n");

            // The vocabulary refuses the non-canonical casing loudly,
            // naming the file — never silently skipped (r3-9's defect),
            // never silently shipped-dead (r5-3's defect).
            try {
                wp_connectors_php_source_files($scratch);
                $this->fail('A non-canonical extension casing must refuse the shared-source vocabulary, never ride it silently in either direction.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('non-canonical extension', $e->getMessage());
                $this->assertStringContainsString('ClockMath.PHP', $e->getMessage());
            }

            // The content gates stay case-insensitive for EXISTING files
            // (the classify half of the r3-9/r4-9 owner, driven the way
            // the plugin-tree walkers drive it): a planted clock read
            // inside the .PHP-spelled file still fails the gate.
            $gate = new \ReflectionMethod($this, 'assertNoDirectEnvironmentAccess');
            try {
                $gate->invoke($this, $scratch . '/ClockMath.PHP');
                $this->fail('A direct clock read inside a .PHP-spelled file must fail the gate — the refusal doctrine never blinds the classifier.');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->assertStringContainsString('ClockMath.PHP', $e->getMessage());
                $this->assertStringContainsString('time()', $e->getMessage());
            }

            // Clean direction through the same gate, and the canonical
            // spelling collects normally beside the note (t31-r2-18).
            file_put_contents($scratch . '/CleanMath.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class CleanMath {\n    public function stamp(): int {\n        return 0;\n    }\n}\n");
            $gate->invoke($this, $scratch . '/CleanMath.php');
            unlink($scratch . '/ClockMath.PHP');
            $this->assertSame(
                array('CleanMath.php'),
                wp_connectors_php_source_files($scratch),
                'The canonical spelling collects; the non-PHP note does not.'
            );
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r4-9): ONE case-insensitive owner judges the
     * php extension — wp_connectors_is_php_source() for collect and
     * classify, wp_connectors_basename_without_php_extension() for the
     * strip — so the sweep vocabulary, the PSR-4 gate's type-name stem,
     * and the self-containment walker can never disagree about what
     * counts as a PHP source (the exact-case spellings let a '.PHP'
     * file be collected by one gate, mis-stemmed by another, and skipped
     * by a third).
     */
    public function testThePhpExtensionJudgmentHasOneCaseInsensitiveOwner(): void
    {
        // The predicate: extension '.php' in ANY case, and nothing else.
        foreach (array('Url.php', 'Url.PHP', 'Url.Php', 'Url.pHp', 'dir/Url.PHP') as $is_source) {
            $this->assertTrue(wp_connectors_is_php_source($is_source), "{$is_source} is a PHP source in every extension case.");
        }
        foreach (array('Notes.md', 'Url.phps', 'Url.php5', 'php', 'Url.pph', '') as $not_source) {
            $this->assertFalse(wp_connectors_is_php_source($not_source), "{$not_source} is not a PHP source.");
        }

        // The stem: any-case extension stripped, non-sources unchanged.
        $this->assertSame('ClockMath', wp_connectors_basename_without_php_extension('shared/src/ClockMath.PHP'));
        $this->assertSame('Url', wp_connectors_basename_without_php_extension('Http/Url.php'));
        $this->assertSame('notes.md', wp_connectors_basename_without_php_extension('/x/y/notes.md'), 'A non-source keeps its basename.');

        // End-to-end consistency on one tree (restated for t31-r5-3's
        // casing doctrine): the canonical spelling collects, its stem
        // strips the extension, and the declared type matches the stem
        // — collect, strip, and classify agree on the same file. The
        // '.PHP' spelling no longer collects (the shared tree refuses
        // it loudly); the stem/predicate legs above keep proving the
        // JUDGMENT owner stays case-insensitive for the gates that
        // judge existing files.
        $scratch = tempnam(sys_get_temp_dir(), 'wpct-phpowner-');
        unlink($scratch);
        mkdir($scratch, 0755, true);
        try {
            file_put_contents($scratch . '/ClockMath.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath\n{\n}\n");
            $collected = wp_connectors_php_source_files($scratch);
            $this->assertSame(array('ClockMath.php'), $collected, 'The vocabulary collects the canonical spelling.');

            $path = $scratch . '/' . $collected[0];
            $this->assertSame('ClockMath', wp_connectors_basename_without_php_extension($path), 'The stem strips the extension.');
            $typeMatches = array();
            $this->assertSame(1, preg_match_all('/^(?:abstract\s+|final\s+)?(?:class|interface|enum)\s+([A-Za-z0-9_]+)/m', $this->fileContents($path), $typeMatches));
            $this->assertSame(wp_connectors_basename_without_php_extension($path), $typeMatches[1][0], 'The PSR-4 type-name comparison rides the same stem.');
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r4-7): a symlink inside the shared source tree
     * REFUSES the collector's walk loudly. The old silent skip was the
     * no-symlinks doctrine's quiet half — a symlinked directory under
     * shared/src loaded in development (the dev autoloader maps class
     * names straight onto paths), was invisible to this sweep (same
     * walk), and missed every zip (reproduced). Both link shapes
     * refuse, naming the link and its target.
     */
    public function testASymlinkInTheSharedSourceTreeRefusesTheCollectorLoudly(): void
    {
        $scratch = tempnam(sys_get_temp_dir(), 'wpct-symlink-src-');
        unlink($scratch);
        mkdir($scratch . '/Clock', 0755, true);
        mkdir($scratch . '/Linked', 0755, true);

        try {
            file_put_contents($scratch . '/Clock/ClockInterface.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Clock;\ninterface ClockInterface {}\n");
            file_put_contents($scratch . '/Linked/LinkedSource.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared\\Linked;\ninterface LinkedSource {}\n");

            // Clean direction first: no links, plain collection.
            $this->assertSame(
                array('Clock/ClockInterface.php', 'Linked/LinkedSource.php'),
                wp_connectors_php_source_files($scratch),
                'A link-free source tree collects unchanged.'
            );

            // A symlinked FILE refuses, naming link and target.
            symlink($scratch . '/Linked/LinkedSource.php', $scratch . '/LinkedFile.php');
            try {
                wp_connectors_php_source_files($scratch);
                $this->fail('A symlinked file inside the shared source tree must refuse the walk, never skip silently.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('symlink', $e->getMessage());
                $this->assertStringContainsString('LinkedFile.php', $e->getMessage());
                $this->assertStringContainsString('LinkedSource.php', $e->getMessage());
            }
            unlink($scratch . '/LinkedFile.php');

            // A symlinked DIRECTORY refuses the same way — this is the
            // reproduced shape: loads in dev, invisible to the sweep,
            // missing from every zip.
            symlink($scratch . '/Linked', $scratch . '/LinkedDir');
            try {
                wp_connectors_php_source_files($scratch);
                $this->fail('A symlinked directory inside the shared source tree must refuse the walk, never skip silently.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('symlink', $e->getMessage());
                $this->assertStringContainsString('LinkedDir', $e->getMessage());
            }
        } finally {
            // The linked DIRECTORY must go as a link (unlink), never as a
            // directory — rrmdir walks into it otherwise.
            if (is_link($scratch . '/LinkedDir')) {
                unlink($scratch . '/LinkedDir');
            }
            WpHarness::rrmdir($scratch);
        }
    }

    /**
     * Fix-round pin (t31-r3-8), end-to-end through the ACTUAL gate: the
     * WP-reach and provider-name patterns were still applied per line
     * while the static/clock gates moved to whole-file application
     * (t31-r2-5/16) — a translation call split between name and argument
     * list ('$saved = __' / '( 'save' );', demonstrated during the
     * round) matched NO single line and a PCRE abort read as clean.
     * Both gates ride the shared whole-file helper now; the multiline
     * spelling fails with file:line, the planted provider name fails
     * the same way, and the clean real sources pass through the same
     * code path.
     */
    public function testTheWpReachAndProviderGatesCatchMultilineSpellingsEndToEnd(): void
    {
        $wpFixture = realpath(__DIR__ . '/fixtures/sweep-corruption/wp-reach-multiline.php');
        $this->assertNotFalse($wpFixture, 'The multiline WP-reach fixture must exist.');

        try {
            $this->assertPatternAbsentWholeFile(
                $wpFixture,
                self::WP_TOKEN_PATTERN,
                'WordPress reach inside shared/ (WordPress is reached only through the ports)'
            );
            $this->fail('A WordPress reach spelled across lines must fail the actual gate, never pass line-by-line.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('wp-reach-multiline.php:16', $e->getMessage());
            $this->assertStringContainsString('$saved = __', $e->getMessage());
        }

        // Clean direction through the same gate: a real swept source
        // with no WordPress reach passes.
        $this->assertPatternAbsentWholeFile(
            (string) realpath(__DIR__ . '/../shared/src/Token/AccessTokenSet.php'),
            self::WP_TOKEN_PATTERN,
            'WordPress reach inside shared/ (WordPress is reached only through the ports)'
        );

        $providerFixture = realpath(__DIR__ . '/fixtures/sweep-corruption/provider-name.php');
        $this->assertNotFalse($providerFixture, 'The provider-name fixture must exist.');

        try {
            $this->assertPatternAbsentWholeFile(
                $providerFixture,
                self::PROVIDER_NAME_PATTERN,
                'Provider name in the provider-neutral shared source (provider config belongs to the per-plugin directories)'
            );
            $this->fail('A planted provider name must fail the provider gate.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('provider-name.php:15', $e->getMessage());
            $this->assertStringContainsString('claude', $e->getMessage());
        }

        // Clean direction: the provider-neutral real source passes the
        // same gate.
        $this->assertPatternAbsentWholeFile(
            (string) realpath(__DIR__ . '/../shared/src/Token/AccessTokenSet.php'),
            self::PROVIDER_NAME_PATTERN,
            'Provider name in the provider-neutral shared source (provider config belongs to the per-plugin directories)'
        );
    }

    /**
     * Fix-round pin (t31-r4-11), the mechanism half: the closed stems
     * end in a letter-aware lookahead, not '\b' — '\b' treats '_' as a
     * word character, so apply_filters/do_action could never match
     * their own ref_array twins (verified mechanism bug: the WP
     * spellings bypassed the gate while the plain stems stayed
     * flagged). '_' extensions over-block in the safe direction
     * (is_admin matches is_admin_bar_showing — a WP function besides);
     * letter extensions and prefixed names stay clean. The curation
     * half (is_admin, get_bloginfo, is_user_logged_in, get_locale
     * added; the spelling-curated posture ledgered) rides the same
     * pattern.
     */
    public function testTheWpReachStemsMatchTheirSuffixedTwins(): void
    {
        $pattern = (new \ReflectionClass(self::class))->getConstant('WP_TOKEN_PATTERN');

        $mustFlag = array(
            'apply_filters_ref_array' => 'apply_filters_ref_array( \'x\', array() );',
            'do_action_ref_array' => 'do_action_ref_array( \'x\', array() );',
            'DO_ACTION_REF_ARRAY' => 'DO_ACTION_REF_ARRAY( \'x\', array() );',
            'apply_filters plain' => 'apply_filters( \'x\', 1 );',
            'is_admin' => 'if ( is_admin() ) {}',
            'get_bloginfo' => '$n = get_bloginfo( \'name\' );',
            'is_user_logged_in' => 'if ( is_user_logged_in() ) {}',
            'get_locale' => '$locale = get_locale();',
            'is_admin_bar_showing (the twin over-match, safe direction)' => 'if ( is_admin_bar_showing() ) {}',
        );
        foreach ($mustFlag as $label => $code) {
            $this->assertSame(1, preg_match($pattern, $code), "The WP-reach vocabulary must flag: {$label}.");
        }

        $mustNotFlag = array(
            'prefixed name' => 'my_apply_filters( $value );',
            'letter-suffixed name' => 'apply_filterss( $value );',
            'embedded stem' => 'reapply_filters( $value );',
            'admin_url with letter tail' => '$u = admin_urlish( $path );',
            'unrelated similar call' => 'apply_the_filters( $value );',
        );
        foreach ($mustNotFlag as $label => $code) {
            $this->assertSame(0, preg_match($pattern, $code), "The vocabulary must not flag: {$label}.");
        }

        // End-to-end through the ACTUAL whole-file gate: a planted
        // ref_array twin in a swept-shaped file fails with file:line.
        $scratch = tempnam(sys_get_temp_dir(), 'wpct-ref-array-');
        try {
            file_put_contents($scratch, "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class RefArrayFixture\n{\n    public function fan_out(): void\n    {\n        apply_filters_ref_array( 'shared_hook', array( 1 ) );\n    }\n}\n");
            try {
                $this->assertPatternAbsentWholeFile(
                    $scratch,
                    self::WP_TOKEN_PATTERN,
                    'WordPress reach inside shared/ (WordPress is reached only through the ports)'
                );
                $this->fail('A _-suffixed twin of a banned stem must fail the actual gate, never ride the \\b boundary past it.');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->assertStringContainsString('apply_filters_ref_array', $e->getMessage());
                $this->assertStringContainsString(basename($scratch), $e->getMessage());
            }
        } finally {
            unlink($scratch);
        }
    }

    public function testSharedSourceFollowsPsr4OneTypePerFile(): void
    {
        $root = realpath(__DIR__ . '/../shared/src');
        $scanned = 0;

        foreach ($this->sharedSourceFiles() as $path) {
            ++$scanned;
            $relative = substr($path, strlen($root) + 1);
            // The read rides the shared LOUD reader (t31-r3-13): the raw
            // (string) file_get_contents() degraded an unreadable swept
            // file to '' — one contentless namespace match, a quiet
            // assertCount failure at best, never the named-file failure
            // every other gate in this sweep reports (the t31-r2-9
            // doctrine, one layer up from the line reader).
            $contents = $this->fileContents($path);

            // Exactly one namespace declaration ...
            $namespaceMatches = array();
            preg_match_all('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $contents, $namespaceMatches);
            $this->assertCount(1, $namespaceMatches[0], $relative . ' must declare exactly one namespace.');

            // ... equal to Deicod\WpConnectors\Shared + its directory ...
            $directory = dirname($relative);
            $expectedNamespace = 'Deicod\\WpConnectors\\Shared' . ('.' === $directory ? '' : str_replace('/', '\\', '\\' . $directory));
            $this->assertSame($expectedNamespace, $namespaceMatches[1][0], $relative . ' must follow PSR-4 (namespace matches path).');

            // ... and exactly one type whose name matches the file name.
            $this->assertOneTypeMatchingFileName($path, $relative);
        }

        $this->assertGreaterThanOrEqual(20, $scanned);
    }

    /**
     * The one-type-per-file gate for ONE file (t31-r4-10: the type
     * vocabulary is class, readonly class, interface, trait, and enum).
     *
     * The old pattern knew only (abstract|final) class/interface/enum:
     * a `trait` declaration was invisible — a class+trait file passed
     * as "exactly one type" — and a `readonly class` (legal since PHP
     * 8.2, this repo's floor) counted as no type at all. The modifier
     * run is a `*` over (abstract|final|readonly) in any order and
     * count; off-vocabulary combinations are lint's domain, the sweep
     * counts declared TYPES. The name comparison rides the ONE
     * case-insensitive extension-strip owner (t31-r4-9).
     *
     * Private and path-parameterized so the mutation discipline drives
     * the ACTUAL gate on planted fixtures (both directions).
     *
     * @param string $path     Absolute file path.
     * @param string $relative Path relative to the swept root (diagnostics).
     * @return void
     */
    private function assertOneTypeMatchingFileName(string $path, string $relative): void
    {
        $contents = $this->fileContents($path);
        $typeMatches = array();
        $result = preg_match_all('/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $contents, $typeMatches);
        $this->assertNotFalse($result, $relative . ': the type scan aborted (PCRE) — an abort is a REFUSAL, never a clean count.');
        $this->assertCount(1, $typeMatches[0], $relative . ' must declare exactly one type (one type per file).');
        $this->assertSame(wp_connectors_basename_without_php_extension($path), $typeMatches[1][0], $relative . ': the type name must match the file name.');
    }

    /**
     * Fix-round pin (t31-r4-10): the type vocabulary covers every type
     * spelling — each declaration counts as exactly one type and
     * captures its name — and the gate enforces one-type-per-file
     * through the ACTUAL helper: a class+trait file fails (the trait
     * was invisible to the old pattern), a trait-only and a
     * readonly-class source pass with the file-name match intact (the
     * readonly spelling counted as no type before).
     */
    public function testTheTypeVocabularyCoversEveryTypeSpelling(): void
    {
        $pattern = '/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m';

        $spellings = array(
            'class' => 'class ClockMath',
            'abstract class' => 'abstract class ClockMath',
            'final class' => 'final class ClockMath',
            'readonly class' => 'readonly class ClockMath',
            'final readonly class' => 'final readonly class ClockMath',
            'readonly final class' => 'readonly final class ClockMath',
            'interface' => 'interface ClockMath',
            'trait' => 'trait ClockMath',
            'enum' => 'enum ClockMath',
        );
        foreach ($spellings as $label => $declaration) {
            $matches = array();
            $this->assertSame(1, preg_match_all($pattern, $declaration . "\n{\n}\n", $matches), "The vocabulary must count the spelling exactly once: {$label}.");
            $this->assertSame('ClockMath', $matches[1][0], "The vocabulary must capture the type name: {$label}.");
        }

        // Column-0 lookalikes that are not type declarations.
        foreach (array('classified text', 'classified;') as $not_a_type) {
            $this->assertSame(0, preg_match_all($pattern, $not_a_type . "\n"), "A non-declaration must not count: {$not_a_type}.");
        }

        // End-to-end through the ACTUAL gate.
        $gate = new \ReflectionMethod($this, 'assertOneTypeMatchingFileName');
        $scratch = tempnam(sys_get_temp_dir(), 'wpct-type-vocab-');
        unlink($scratch);
        mkdir($scratch, 0755, true);
        try {
            file_put_contents($scratch . '/Token.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nreadonly class Token\n{\n}\n");
            $gate->invoke($this, $scratch . '/Token.php', 'Token.php');

            // The '.PHP' spelling rides the same gate (t31-r4-9's stem).
            file_put_contents($scratch . '/ClockTrait.PHP', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\ntrait ClockTrait\n{\n}\n");
            $gate->invoke($this, $scratch . '/ClockTrait.PHP', 'ClockTrait.PHP');

            // A class PLUS a trait in one file fails one-type-per-file —
            // the trait was invisible to the old vocabulary.
            file_put_contents($scratch . '/Mate.php', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nclass Mate\n{\n}\ntrait MateHelpers\n{\n}\n");
            try {
                $gate->invoke($this, $scratch . '/Mate.php', 'Mate.php');
                $this->fail('A class+trait file must fail one-type-per-file now that the vocabulary sees traits.');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->assertStringContainsString('exactly one type', $e->getMessage());
                $this->assertStringContainsString('Mate.php', $e->getMessage());
            }
        } finally {
            WpHarness::rrmdir($scratch);
        }
    }
}
