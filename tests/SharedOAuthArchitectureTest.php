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
     * date() ride along (gmdate/mktime/idate/strftime) and putenv joins
     * getenv (an environment WRITE is the same seam); strtotime and
     * date_create stay legal — they are string-parse shapes, and the
     * 'now'-reading spellings they share are the port implementation's
     * own (SystemClock's DateTimeImmutable('now')), which a
     * spelling-level sweep cannot and should not ban. Prose naming a
     * call shape is rewritten, never exempted (the floor82-2 idiom).
     */
    private const DIRECT_ENVIRONMENT_PATTERN = '/\b(?i:time|microtime|hrtime|date|gmdate|mktime|idate|strftime|getenv|putenv)\s*\(|\$(?:_SERVER|_ENV|GLOBALS)\b/';

    /**
     * @return list<string> Absolute paths of every PHP file under shared/src.
     */
    private function sharedSourceFiles(): array
    {
        $root = realpath(__DIR__ . '/../shared/src');
        $this->assertNotFalse($root, 'shared/src must exist — the contracts live there.');

        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && '.php' === substr($file->getPathname(), -4)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

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

        $contents = file_get_contents($path);
        if (false === $contents) {
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
     * (chmod 000, vanished mid-sweep) and a PCRE abort (one invalid
     * UTF-8 byte under /\R/u) both fail naming the file — the old
     * silent fallbacks swept the file as zero-or-one contentless
     * lines, every gate skipped it, and the non-vacuity counts stayed
     * green (the glm36-8 doctrine applied one layer below by t31-r1-7,
     * and one layer above by t31-r1-21: a file_get_contents() false
     * cast to '' swept as a single contentless line).
     *
     * @param string $path File path.
     * @return list<array{0: int, 1: string}>
     */
    private function numberedLines(string $path): array
    {
        if (!is_readable($path)) {
            $this->fail(
                sprintf(
                    'The architecture sweep cannot read %s — an unreadable swept file must fail loudly, never sweep as contentless lines.',
                    $path
                )
            );
        }

        $split = preg_split('/\R/u', (string) file_get_contents($path));
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
            foreach ($this->numberedLines($path) as [$number, $line]) {
                if (1 === preg_match(self::WP_TOKEN_PATTERN, $line, $matches)) {
                    $this->fail(
                        sprintf(
                            'WordPress reach inside shared/ (WordPress is reached only through the ports): %s:%d — %s',
                            $path,
                            $number,
                            trim($line)
                        )
                    );
                }
            }
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

    public function testSharedSourceAndReadmeNameNoProviders(): void
    {
        $paths = array_merge($this->sharedSourceFiles(), array(realpath(__DIR__ . '/../shared/README.md')));
        $this->assertGreaterThanOrEqual(21, count($paths));

        foreach ($paths as $path) {
            foreach ($this->numberedLines((string) $path) as [$number, $line]) {
                if (1 === preg_match(self::PROVIDER_NAME_PATTERN, $line)) {
                    $this->fail(
                        sprintf(
                            'Provider name in the provider-neutral shared source (provider config belongs to the per-plugin directories): %s:%d — %s',
                            $path,
                            $number,
                            trim($line)
                        )
                    );
                }
            }
        }
    }

    public function testSharedSourceSpellsItsNamespaceOnlyOnRewritableLines(): void
    {
        // bin/build.php's rewrite touches exactly two spellings: the
        // namespace declaration and `use Deicod\WpConnectors\Shared\...`
        // imports. Any OTHER occurrence of the FQ namespace would
        // survive the rewrite pointing at a namespace that no longer
        // exists inside the plugin copy.
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
     * naming the file and the line the match starts on.
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
        if (1 === preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
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
