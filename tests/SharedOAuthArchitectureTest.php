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
     */
    private const STATIC_MUTABLE_PATTERN = '/\bstatic\s+\$[A-Za-z_]/';

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
     * Lines of one file, as (trimmed line => original) pairs.
     *
     * @param string $path File path.
     * @return list<array{0: int, 1: string}>
     */
    private function numberedLines(string $path): array
    {
        $lines = array();
        foreach (preg_split('/\R/u', (string) file_get_contents($path)) ?: array() as $index => $line) {
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

    public function testSharedSourceCarriesNoStaticMutableState(): void
    {
        foreach ($this->sharedSourceFiles() as $path) {
            foreach ($this->numberedLines($path) as [$number, $line]) {
                if (1 === preg_match(self::STATIC_MUTABLE_PATTERN, $line)) {
                    $this->fail(
                        sprintf(
                            'Static mutable state inside shared/ (pure value objects carry none): %s:%d — %s',
                            $path,
                            $number,
                            trim($line)
                        )
                    );
                }
            }
        }
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
