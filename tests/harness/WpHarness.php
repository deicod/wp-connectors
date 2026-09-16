<?php
/**
 * Global state holder for the WordPress API test harness.
 *
 * All state lives here (never in real globals) so that
 * WpConnectorsTestCase::setUp() can reset everything deterministically:
 * options, transients, scheduled events, hooks, the current user, the
 * deterministic clock, recorded HTTP attempts, and mock queues.
 *
 * This class is test infrastructure only; it is never shipped in a plugin.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

final class WpHarness
{
    /**
     * Options table emulation: name => value.
     *
     * @var array<string, mixed>
     */
    public static $options = array();

    /**
     * Option autoload flags: name => bool (true = autoloaded).
     *
     * @var array<string, bool>
     */
    public static $option_autoload = array();

    /**
     * Every option name passed to delete_option(), in order, whether or not
     * a row existed (lets tests pin needless-delete call shapes).
     *
     * @var list<string>
     */
    public static $delete_option_attempts = array();

    /**
     * Transients: name => array{value: mixed, expires_at: int|false}.
     *
     * @var array<string, array{value: mixed, expires_at: int|false}>
     */
    public static $transients = array();

    /**
     * Whether a persistent (external) object cache backs transients.
     *
     * Mirrors wp_using_ext_object_cache(): when true, transient values
     * live in the object cache and NO _transient_ rows exist in
     * wp_options — the wpdb stub therefore hides transient rows from its
     * option-name enumeration (GLM5 #12: the uninstall probe-miss sweep
     * must survive this shape through its direct deletions).
     *
     * @var bool
     */
    public static $external_object_cache = false;

    /**
     * Scheduled events, WP-style: hook => list of event arrays.
     *
     * @var array<string, list<array{timestamp: int, args: array, id: string}>>
     */
    public static $cron = array();

    /**
     * Hook registry: tag => priority => list of callbacks.
     *
     * @var array<string, array<int, list<callable>>>
     */
    public static $filters = array();

    /**
     * Currently executing action stack (innermost last).
     *
     * @var list<string>
     */
    public static $current_action_stack = array();

    /**
     * Times each action fired.
     *
     * @var array<string, int>
     */
    public static $did_actions = array();

    /**
     * Current user emulation.
     *
     * @var object|null
     */
    public static $current_user;

    /**
     * Deterministic clock: fixed unix timestamp, or null to use the real time.
     *
     * @var int|null
     */
    public static $frozen_time;

    /**
     * Site UTC offset in seconds, applied by current_time() for non-gmt
     * lookups (mirrors core's timezone-affected timestamp). Tests change it
     * to simulate a site timezone change.
     *
     * @var int
     */
    public static $utc_offset = 0;

    /**
     * Outbound wp_remote_* attempts recorded by the HTTP stubs.
     *
     * @var list<array{method: string, url: string, args: array, mocked: bool}>
     */
    public static $http_attempts = array();

    /**
     * Outbound SDK (PSR-18) requests recorded by SdkHttpClient.
     *
     * @var list<array{method: string, url: string, headers: array, body: ?string}>
     */
    public static $sdk_http_attempts = array();

    /**
     * Queued PSR-7 responses for SdkHttpClient (FIFO).
     *
     * @var list<\Psr\Http\Message\ResponseInterface>
     */
    public static $sdk_mock_queue = array();

    /**
     * Settings API registrations: option name => args.
     *
     * @var array<string, array>
     */
    public static $registered_settings = array();

    /**
     * Admin submenu pages registered via add_options_page(): slug => page data.
     *
     * @var array<string, array>
     */
    public static $admin_pages = array();

    /**
     * Settings sections registered per page (add_settings_section recording).
     *
     * @var array<string, list<string>>
     */
    public static $settings_sections = array();

    /**
     * Settings fields registered per page and section (add_settings_field recording).
     *
     * @var array<string, array<string, list<string>>>
     */
    public static $settings_fields = array();

    /**
     * Settings errors recorded via add_settings_error().
     *
     * @var list<array{setting: string, code: string, message: string, type: string}>
     */
    public static $settings_errors = array();

    /**
     * _doing_it_wrong() recordings.
     *
     * @var list<array{function: string, message: string, version: string}>
     */
    public static $doing_it_wrong = array();

    /**
     * Deterministic nonce salt (fixed so nonces are stable within a test run).
     *
     * @var string
     */
    public static $nonce_salt = 'wp-connectors-test-harness-nonce-salt';

    /**
     * Plugin main files loaded via loadPlugin(), for require-once tracking.
     *
     * @var array<string, bool>
     */
    public static $loaded_plugins = array();

    /**
     * Whether the emulated install is multisite (is_multisite()).
     *
     * @var bool
     */
    public static $is_multisite = false;

    /**
     * Extra blog IDs on the emulated network (blog 1 always exists).
     *
     * @var list<int>
     */
    public static $sites = array();

    /**
     * Current blog ID for switch_to_blog()/restore_current_blog().
     *
     * @var int
     */
    public static $current_blog_id = 1;

    /**
     * Pending switch_to_blog() returns (innermost last).
     *
     * @var list<int>
     */
    public static $blog_stack = array();

    /**
     * Arguments of every get_sites() call, in order (for pagination proofs).
     *
     * @var list<array>
     */
    public static $get_sites_queries = array();

    /**
     * Last ID slice returned by get_sites() (non-advancing-loop detection).
     *
     * @var list<int>|null
     */
    public static $last_get_sites_slice = null;

    /**
     * Consecutive get_sites() calls that returned the same ID slice.
     *
     * @var int
     */
    public static $get_sites_repeat_count = 0;

    /**
     * Parked per-blog option tables while switched away from that blog.
     *
     * @var array<int, array<string, mixed>>
     */
    public static $blog_options = array();

    /**
     * Pristine request-superglobal state, snapshotted once at harness load
     * (before any test can pollute it) and restored by reset() — full-
     * restore semantics like the options state, because tests assign
     * $_POST en bloc (code-review #13: a selective nonce-unset let
     * non-nonce POST state leak between tests in the same process).
     *
     * @var array{GET: array, POST: array, REQUEST: array}|null
     */
    public static $request_superglobals_snapshot;

    /**
     * Parked per-blog transient stores while switched away from that blog.
     *
     * @var array<int, array<string, array{value: mixed, expires_at: int|false}>>
     */
    public static $blog_transients = array();

    /**
     * Resets all mutable state. Called before every test.
     *
     * @return void
     */
    public static function reset()
    {
        self::$options = array();
        self::$option_autoload = array();
        self::$delete_option_attempts = array();
        self::$transients = array();
        self::$external_object_cache = false;
        self::$cron = array();
        self::$filters = array();
        self::$current_action_stack = array();
        self::$did_actions = array();
        self::$current_user = null;
        self::$frozen_time = null;
        self::$utc_offset = 0;
        self::$http_attempts = array();
        self::$sdk_http_attempts = array();
        self::$sdk_mock_queue = array();
        self::$doing_it_wrong = array();
        self::$registered_settings = array();
        unset($GLOBALS['wp_registered_settings']);
        self::$admin_pages = array();
        self::$settings_sections = array();
        self::$settings_fields = array();
        self::$settings_errors = array();
        self::$is_multisite = false;
        self::$sites = array();
        self::$current_blog_id = 1;
        self::$blog_stack = array();
        self::$blog_options = array();
        self::$blog_transients = array();
        self::$get_sites_queries = array();
        self::$last_get_sites_slice = null;
        self::$get_sites_repeat_count = 0;

        /*
         * Request superglobals get FULL-restore semantics (code-review
         * #13): tests assign $_POST en bloc, so unsetting only the nonce
         * keys let every other piece of request state leak between tests
         * in the same process. The pristine snapshot is taken once at
         * harness load (see snapshotRequestSuperglobals()).
         */
        if (self::$request_superglobals_snapshot !== null) {
            $_GET = self::$request_superglobals_snapshot['GET'];
            $_POST = self::$request_superglobals_snapshot['POST'];
            $_REQUEST = self::$request_superglobals_snapshot['REQUEST'];
        }
    }

    /**
     * Captures the pristine request-superglobal state for reset().
     *
     * Called once at harness load (wp-stubs.php requires this file before
     * anything else can touch the superglobals), never again — later
     * calls would snapshot a polluted state as "pristine".
     *
     * @return void
     */
    public static function snapshotRequestSuperglobals()
    {
        if (self::$request_superglobals_snapshot !== null) {
            return;
        }

        self::$request_superglobals_snapshot = array(
            'GET' => $_GET,
            'POST' => $_POST,
            'REQUEST' => $_REQUEST,
        );
    }

    /**
     * Current (deterministic) unix timestamp.
     *
     * @return int
     */
    public static function now()
    {
        if (self::$frozen_time !== null) {
            return self::$frozen_time;
        }

        return time();
    }

    /**
     * Freezes the clock at a unix timestamp.
     *
     * @param int $timestamp Unix timestamp.
     * @return void
     */
    public static function freezeTime($timestamp)
    {
        self::$frozen_time = $timestamp;
    }

    /**
     * Advances the frozen clock (no-op when the clock is live).
     *
     * @param int $seconds Seconds to advance.
     * @return void
     */
    public static function advanceTime($seconds)
    {
        if (self::$frozen_time !== null) {
            self::$frozen_time += $seconds;
        }
    }

    /**
     * Records a wp_remote_* attempt.
     *
     * @param string $method HTTP method.
     * @param string $url    Request URL.
     * @param array  $args   Request arguments.
     * @param bool   $mocked Whether a pre_http_request mock answered.
     * @return void
     */
    public static function recordHttpAttempt($method, $url, array $args, $mocked = false)
    {
        unset($args['body']);
        self::$http_attempts[] = array(
            'method' => strtoupper($method),
            'url' => $url,
            'args' => $args,
            'mocked' => (bool) $mocked,
        );
    }

    /**
     * Fires all scheduled events whose time has come, in timestamp order.
     *
     * Simulates a cron run against the deterministic clock; a fired single
     * event is removed before its hook fires, while recurring events are
     * rescheduled at timestamp + interval.
     *
     * @return int Number of events fired.
     */
    public static function runDueEvents()
    {
        $fired = 0;
        $progress = true;
        while ($progress) {
            $progress = false;
            foreach (self::$cron as $hook => $events) {
                foreach ($events as $index => $event) {
                    if ($event['timestamp'] <= self::now()) {
                        $args = $event['args'];
                        unset(self::$cron[$hook][$index]);
                        self::$cron[$hook] = array_values(self::$cron[$hook]);
                        if (self::$cron[$hook] === array()) {
                            unset(self::$cron[$hook]);
                        }
                        if (isset($event['interval']) && (int) $event['interval'] > 0) {
                            $rescheduled = $event;
                            $rescheduled['timestamp'] = $event['timestamp'] + (int) $event['interval'];
                            self::$cron[$hook][] = $rescheduled;
                        }
                        ++$fired;
                        $progress = true;
                        do_action($hook, ...$args);
                        continue 3;
                    }
                }
            }
        }

        return $fired;
    }

    /**
     * Recursively removes a directory (test helper — the ONE scratch-tree
     * removal owner, t31-ocr1-9: the former per-test twins diverged in
     * error policy; the harness policy is the loud one, and every test
     * consumes it here).
     *
     * The no-symlinks doctrine rides this twin too (verifier round
     * t31-ocr1-11, found independently by both lenses — the same round
     * that hardened bin/build.php's rrmdir must not leave the harness
     * twin walking links): a LINK at the removal root is never deleted
     * through (is_dir follows links; the iterator constructed on a
     * linked path walks the TARGET tree — the tests' predictable
     * /tmp scratch names are pre-plantable on a shared host), and a
     * linked child inside an owned tree is unlinked AS ITSELF, never
     * descended into, never rmdir'd through.
     *
     * @param string $dir Absolute directory path.
     * @return void
     */
    public static function rrmdir($dir)
    {
        if (is_link($dir)) {
            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * Recursively copies a directory tree's FILES (test helper — the ONE
     * scratch-tree copy owner, t31-ocr1-9: UnusedImportScannerTest carried
     * this private beside its own @-suppressed removeTree twin; both moved
     * here, one error policy — LOUD, like rrmdir()'s).
     *
     * The no-symlinks doctrine rides this twin too (OCR round 4,
     * t31-ocr4-3): rrmdir() (t31-ocr1-11) never follows a link — and a
     * COPY has no safe silent spelling of that rule (removal may skip a
     * link's content unseen; a copy that FOLLOWED duplicated the target
     * tree's bytes, a copy that SKIPPED silently shipped a partial
     * tree). So a link — the source root itself, or any linked entry
     * inside it, file or directory shape — REFUSES loudly, one verdict
     * path for both shapes, the copy twin of rrmdir()'s root guard.
     *
     * Preconditions and self-containment (OCR round 7, t31-ocr7-4;
     * mechanism narrative CORRECTED in t31-ocr7-8 over the refutation
     * lens's driven probes — the guard's policy stands, its first
     * justification did not): a MISSING or FILE source once reached
     * the SPL iterator constructor, whose UnexpectedValueException is
     * another library's vocabulary — the harness policy is the LOUD
     * RuntimeException naming the path, so the guard precedes
     * iteration. And a target that IS the source or sits INSIDE it is
     * refused before the lazy iterator runs. The mechanisms, as probed
     * on this engine (8.5.10, tmpfs and ext4): a SELF-COPY is a
     * silent no-op success — copy() refuses the same-file copy
     * (returns false, no warning, bytes intact) and pre-round
     * copyTree(src, src) returned normally having copied nothing, a
     * silent wrong outcome riding the engine's same-file mercy, which
     * is platform behavior, never a contract; a NESTED target writes
     * the copy INTO the source it is reading — the SPL iterator does
     * NOT re-enumerate the created target (driven: 5,000 source files
     * became exactly 10,000 — one self-polluting duplication, not an
     * unbounded loop), still wrong output landing inside the tree
     * under test. One containment check, realpath-based (the
     * not-yet-created target is judged lexically; the out-of-scope
     * class is any alias the lexical path cannot see through — a
     * '..'-woven alias, or an absent target reached through a
     * SYMLINKED ancestor, whose realpath() is false for the missing
     * leaf and whose copy would land physically inside the source —
     * every real caller passes disjoint absolute scratch trees),
     * before a single byte moves.
     *
     * @param string $from Absolute source directory.
     * @param string $to   Absolute target directory.
     * @return void
     * @throws RuntimeException When the source (or any entry in it) is a symlink, the source is missing or not a directory, or the target is the source itself or inside it.
     */
    public static function copyTree($from, $to)
    {
        if (is_link($from)) {
            throw new RuntimeException('WpHarness::copyTree() refuses a symlinked source tree — never followed, never silently skipped: ' . $from);
        }
        if (! is_dir($from)) {
            throw new RuntimeException('WpHarness::copyTree() refuses a source that is not a readable directory — the loud policy, never the SPL iterator\'s surprise: ' . $from);
        }
        $source_real = realpath($from);
        // A not-yet-created target is the copy's own normal shape;
        // realpath cannot speak for it, so the lexical path is judged
        // (absolute normalized scratch paths are the ceiling).
        $target_real = false !== realpath($to) ? realpath($to) : rtrim($to, '/');
        if ($target_real === $source_real || 0 === strpos($target_real, $source_real . '/')) {
            throw new RuntimeException('WpHarness::copyTree() refuses a target that is the source itself or inside it — a self-copy is a silent no-op success riding the engine\'s same-file mercy, and a nested target writes the copy into the very tree it reads: from ' . $from . ' into ' . $to);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                // One verdict for both shapes (t31-ocr4-3): the isLink()
                // probe precedes isDir() — a linked DIRECTORY's isDir()
                // follows the link, and the old shape-based split silently
                // skipped dir links while copy() followed file links.
                throw new RuntimeException('WpHarness::copyTree() refuses a symlinked entry — never followed, never silently skipped: ' . $file->getPathname());
            }
            if ($file->isDir()) {
                continue;
            }
            /*
             * The relative path is a 0-position prefix strip, exactly
             * once (OCR round 4, t31-ocr4-2): str_replace() strips
             * EVERY occurrence, so a source tree containing the source
             * dir's own name as a nested segment
             * (…/example-connector/vendor/example-connector/file.php)
             * silently copied to the wrong target — the first segment
             * splice ate the nested one too. A pathname the prefix does
             * NOT prefix refuses loudly (OCR round 6, t31-ocr6-4): the
             * old no-match arm kept the FULL absolute path as the
             * "relative" tail, so every file silently landed nested
             * under the target (reachable via a trailing-slash $from,
             * whose iterator pathnames never start with the
             * double-slash prefix) — the exact silent mis-nesting this
             * loud-policy copy owner exists to prevent.
             */
            $relative = $file->getPathname();
            $prefix = $from . '/';
            if (0 !== strpos($relative, $prefix)) {
                throw new RuntimeException('WpHarness::copyTree() cannot relativize ' . $relative . ' against the source prefix ' . $prefix . ' — every file would silently land nested under the target (a trailing-slash source is the reachable spelling).');
            }
            $relative = substr($relative, strlen($prefix));
            $target = $to . '/' . $relative;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            copy($file->getPathname(), $target);
        }
    }

    /**
     * Unique registration key for a callback (dedupes identical add_action calls).
     *
     * @param callable $callback Callback.
     * @return string
     */
    public static function callbackKey($callback)
    {
        if (is_string($callback)) {
            return 's:' . $callback;
        }
        if (is_array($callback) && count($callback) === 2) {
            $target = $callback[0];
            $target_key = is_object($target) ? 'o:' . spl_object_id($target) : 's:' . $target;

            return 'a:' . $target_key . '::' . (is_string($callback[1]) ? $callback[1] : 'closure');
        }
        if ($callback instanceof Closure) {
            return 'c:' . spl_object_id($callback);
        }

        return 'u:' . spl_object_id($callback);
    }
}
