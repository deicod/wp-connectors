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
 * - Rewrite safety: the build-time namespace rewrite (record 0005)
 *   touches only `namespace` and `use` lines, so the source may spell
 *   its own namespace ONLY on those lines — an inline fully-qualified
 *   reference would survive the rewrite broken.
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

final class SharedOAuthArchitectureTest extends WpConnectorsTestCase
{
    /**
     * Banned WordPress reach: functions, hooks, options, globals,
     * constants — everything that would smuggle a host dependency into
     * the shared source. Case-insensitive (PHP calls are).
     */
    private const WP_TOKEN_PATTERN = '/(?:\bwp_[a-z0-9_]+|\b(?:apply_filters|do_action|add_action|add_filter|remove_action|remove_filter|_doing_it_wrong|current_time|current_user_can|get_current_user_id|get_current_blog_id|is_multisite|is_wp_error|get_option|update_option|add_option|delete_option|get_blog_option|update_blog_option|delete_blog_option|get_site_option|update_site_option|delete_site_option|switch_to_blog|restore_current_blog|get_transient|set_transient|delete_transient|get_user_meta|update_user_meta|register_setting|add_settings_(?:section|field)|add_submenu_page|register_(?:activation|deactivation|uninstall)_hook|plugin_dir_path|plugins_url|admin_url|network_admin_url|self_admin_url|site_url|home_url|get_site_url|get_home_url|add_query_arg|remove_query_arg|load_plugin_textdomain|check_admin_referer|check_ajax_referer|esc_[a-z0-9_]+|sanitize_[a-z0-9_]+|wpdb|wp_error)\b|\b__\s*\(|\b(?:AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT|ABSPATH|WPINC|WP_CONTENT_DIR|WP_PLUGIN_DIR|WPMU_PLUGIN_DIR)\b)/i';

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
     */
    private function sharedSourceFiles(): array
    {
        $root = realpath(__DIR__ . '/../shared/src');
        $this->assertNotFalse($root, 'shared/src must exist — the contracts live there.');

        $files = array();
        foreach (wp_connectors_php_source_files($root) as $relative) {
            $files[] = $root . '/' . $relative;
        }

        return $files;
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
     * @param string $path File path.
     * @return string File contents.
     */
    private function fileContents(string $path): string
    {
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

        return $contents;
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

    public function testSharedSourceSpellsItsNamespaceOnlyOnRewritableLines(): void
    {
        // bin/build.php's rewrite touches exactly two line shapes: the
        // namespace declaration and `use ...` imports — EVERY legal use
        // spelling (plain, aliased, function, const, fully qualified,
        // exact, brace-group; the rewriter's spelling battery is pinned
        // in BuildArtifactsTest::testSharedNamespaceRewrite), and its
        // postcondition refuses any spelling the patterns do not know
        // (review round t31-r3-2), so nothing whitelisted here can ship
        // un-rewritten. Any OTHER occurrence of the FQ namespace would
        // survive pointing at a namespace that no longer exists inside
        // the plugin copy.
        foreach ($this->sharedSourceFiles() as $path) {
            foreach ($this->numberedLines($path) as [$number, $line]) {
                if (false === strpos($line, 'Deicod\\WpConnectors\\Shared')) {
                    continue;
                }
                $trimmed = ltrim($line);
                if (0 === strpos($trimmed, 'namespace ') || 0 === strpos($trimmed, 'use ')) {
                    continue;
                }

                $this->fail(
                    sprintf(
                        'Inline namespace spelling breaks the build-time rewrite (only namespace/use lines are rewritten): %s:%d — %s',
                        $path,
                        $number,
                        trim($line)
                    )
                );
            }
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

        $burner = tempnam(sys_get_temp_dir(), 'wpct-pcre-burner-');
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
        unlink($burner);

        // The clean direction: padding-only content of the same scale
        // passes (the refusal is the abort, not the size).
        $clean = tempnam(sys_get_temp_dir(), 'wpct-pcre-clean-');
        file_put_contents($clean, '<?php' . str_pad('// prose about static behaviour and nothing else ', 10000, 'x'));
        $gate->invoke($this, $clean);
        unlink($clean);
    }

    /**
     * Fix-round pin (t31-r3-9), both directions: the sweep's file
     * vocabulary was its own case-SENSITIVE '.php' filter — the same
     * spelling the build's embed filter used — so a '.PHP'-spelled
     * source was silently skipped from embeds and never judged by any
     * gate (no gate ever saw it). The vocabulary is the ONE shared
     * collector now (case-insensitive extension, no exclusions): a
     * .PHP-spelled source IS collected and IS judged — a planted clock
     * read inside one fails the actual gate, and a clean one passes
     * through the same code path.
     */
    public function testTheSweepVocabularyJudgesUpperCaseSpelledPhpSources(): void
    {
        $scratch = tempnam(sys_get_temp_dir(), 'wpct-phpcase-');
        unlink($scratch);
        mkdir($scratch, 0755, true);

        try {
            file_put_contents($scratch . '/ClockMath.PHP', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class ClockMath {\n    public function stamp(): int {\n        return time();\n    }\n}\n");
            file_put_contents($scratch . '/Notes.md', "# developer notes\n");

            // The vocabulary: the .PHP source is collected (any case
            // spelling); the non-PHP note is not (t31-r2-18 unchanged).
            $this->assertSame(array('ClockMath.PHP'), wp_connectors_php_source_files($scratch));

            // And the actual gate judges the collected file: the planted
            // time() call inside the .PHP-spelled source fails.
            $gate = new \ReflectionMethod($this, 'assertNoDirectEnvironmentAccess');
            try {
                $gate->invoke($this, $scratch . '/ClockMath.PHP');
                $this->fail('A direct clock read inside a .PHP-spelled source must fail the gate, never ride past the vocabulary.');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->assertStringContainsString('ClockMath.PHP', $e->getMessage());
                $this->assertStringContainsString('time()', $e->getMessage());
            }

            // Clean direction through the same gate: a violation-free
            // .PHP-spelled source passes (the extension case, not the
            // content, is what the fix admits).
            file_put_contents($scratch . '/CleanMath.PHP', "<?php\nnamespace Deicod\\WpConnectors\\Shared;\nfinal class CleanMath {\n    public function stamp(): int {\n        return 0;\n    }\n}\n");
            $gate->invoke($this, $scratch . '/CleanMath.PHP');
            $this->assertSame(
                array('CleanMath.PHP', 'ClockMath.PHP'),
                wp_connectors_php_source_files($scratch),
                'Both .PHP spellings share the vocabulary with .php (sorted, case-preserving paths).'
            );
        } finally {
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

    public function testSharedSourceFollowsPsr4OneTypePerFile(): void
    {
        $root = realpath(__DIR__ . '/../shared/src');
        $scanned = 0;

        foreach ($this->sharedSourceFiles() as $path) {
            ++$scanned;
            $relative = substr($path, strlen($root) + 1);
            $contents = (string) file_get_contents($path);

            // Exactly one namespace declaration ...
            $namespaceMatches = array();
            preg_match_all('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $contents, $namespaceMatches);
            $this->assertCount(1, $namespaceMatches[0], $relative . ' must declare exactly one namespace.');

            // ... equal to Deicod\WpConnectors\Shared + its directory ...
            $directory = dirname($relative);
            $expectedNamespace = 'Deicod\\WpConnectors\\Shared' . ('.' === $directory ? '' : str_replace('/', '\\', '\\' . $directory));
            $this->assertSame($expectedNamespace, $namespaceMatches[1][0], $relative . ' must follow PSR-4 (namespace matches path).');

            // ... and exactly one type whose name matches the file name.
            $typeMatches = array();
            preg_match_all('/^(?:abstract\s+|final\s+)?(?:class|interface|enum)\s+([A-Za-z0-9_]+)/m', $contents, $typeMatches);
            $this->assertCount(1, $typeMatches[0], $relative . ' must declare exactly one type (one type per file).');
            $this->assertSame(basename($path, '.php'), $typeMatches[1][0], $relative . ': the type name must match the file name.');
        }

        $this->assertGreaterThanOrEqual(20, $scanned);
    }
}
