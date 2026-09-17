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
     * The spelling a LINK probe must read: the same-directory tails —
     * trailing slashes and trailing '/.' components — AND the trailing
     * '/..' tails stripped, repeated, the root '/' itself kept.
     *
     * Each of the first two tails forces stat THROUGH a final symlink
     * (lstat never sees the link itself), so an is_link() probe on the
     * raw spelling passes a linked root straight through — the rrmdir()
     * and copyTree() guards both probe THIS spelling (t31-ocr8-2's
     * trailing slash, t31-ocr8-13's '/.' — one owner for the class).
     *
     * The '/..' tail is the third member of that family (t31-ocr9-1,
     * refuting round 8's "names the parent" carve-out with driven
     * evidence): yes, it names a parent — the LINK TARGET'S parent, so
     * rrmdir('link/..') walked and deleted THROUGH the link with a
     * strictly LARGER blast radius than the target (driven pre-fix:
     * the walk entered the target's parent and removed entries through
     * the link spelling before breaking), and copyTree('link/..')
     * copied that parent tree through it (driven). The carve-out's
     * lesson, for the ledger: a naming argument is not a driven
     * justification.
     *
     * Unlike '/' and '/.', a stripped '/..' names a DIFFERENT
     * directory, so only the PROBE reads this spelling — the WALKS
     * keep their own: rrmdir() rides same_directory_spelling(), and
     * copyTree() below keeps the caller's spelling for the walk.
     *
     * A link ANYWHERE in the chain, not only at the final tail (OCR
     * round 17, t31-ocr17-2): the strip above owns the TRAILING
     * family, so a link with components AFTER it routed the probe
     * THROUGH it — rrmdir('planted-link/sub/..') probed
     * 'planted-link/sub', whose stat follows the link to the real
     * 'sub' inside the target, and is_link() answered for a
     * directory the chain merely passes through (driven at HEAD: the
     * walk then deleted the victim tree's entries THROUGH the link
     * spelling and died mid-flight in the SPL iterator's vocabulary).
     * The probe resolves the FULL component chain now: the first
     * ancestor component that is itself a link IS the spelling an
     * is_link() probe can trust, wherever in the chain it sits. A
     * relative spelling (both consumers' contracts say absolute)
     * keeps its cwd-relative resolution — the component walk simply
     * spells its prefixes from '.'.
     *
     * The walk ANCHORS at the temp root, never at '/' (OCR round 19,
     * t31-ocr19-2): the full-chain judgment made the FIRST link in
     * the chain the verdict, and on a host whose temp spelling itself
     * crosses a system-layout link (macOS: TMPDIR lives under /var →
     * private/var, the /tmp fallback → private/tmp) that first link
     * was the host's own spelling — the probe named it for every
     * temp-rooted path, rrmdir silently SKIPPED cleanup of every
     * legal scratch tree, and copyTree refused every legal source,
     * the whole link vocabulary firing on the layout (driven here
     * through a redirected-TMPDIR child process; sys_get_temp_dir()
     * is cached per process, so the sim rides a fresh engine). The
     * anchor is the temp root — the passed chain's existing ancestor
     * the harness itself owns, the same existing-component stop the
     * copyTree ancestor walk rides: the components of the temp
     * spelling are the host's layout, never the planted-link class,
     * and everything strictly BENEATH the anchor keeps the ocr17-2
     * full-chain reach. A chain not spelled beneath the temp root (a
     * relative spelling, a foreign absolute) keeps the full-chain
     * walk — no ceiling silently re-opened.
     *
     * @param string $path The path as the caller spelled it.
     * @return string The spelling an is_link() probe can trust.
     */
    private static function link_probe_spelling($path)
    {
        $path = self::same_directory_spelling($path);
        while ('/..' === substr($path, -3)) {
            $path = self::same_directory_spelling(rtrim(substr($path, 0, -3), '/'));
        }
        $temp = rtrim(sys_get_temp_dir(), '/');
        $anchored = '' !== $temp && isset($path[0]) && '/' === $path[0] && 0 === strpos($path, $temp . '/');
        $carry = '/' === ($path[0] ?? '') ? '' : '.';
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment) {
                continue;
            }
            $carry .= '/' . $segment;
            if ($anchored && strlen($carry) <= strlen($temp)) {
                // A component of the temp spelling itself — the host's
                // layout (macOS /var), never the planted-link class.
                continue;
            }
            if (is_link($carry)) {
                return $carry;
            }
        }

        return $path;
    }

    /**
     * The spelling that names the caller's SAME directory: trailing
     * slashes and trailing '/.' components stripped, repeated, the
     * root '/' itself kept — the WALK spelling. Each of those tails
     * names the directory the caller named, so the walk may ride the
     * clean spelling (t31-ocr8-2's trailing slash, t31-ocr8-13's '/.');
     * a '/..' tail does NOT (it names the parent), so it survives here
     * and only the link probe above strips it (t31-ocr9-1).
     *
     * A spelling made of ROOT SEPARATORS ALONE ('//', '/.', '/./.')
     * collapses to '/' (the root it names), never '' (t31-ocr13-3):
     * the empty string names nothing, is_dir('') is false, and
     * rrmdir() fell through its probes to a SILENT no-op — asymmetric
     * with the loud refusals its siblings ('/' early-kept, '/..' kept
     * for the realpath root refusal) carry. Collapsing to '/' rides
     * the existing loud root refusal instead.
     *
     * @param string $path The path as the caller spelled it.
     * @return string The same directory, spelled without the tails a probe cannot trust.
     */
    private static function same_directory_spelling($path)
    {
        if ('/' === $path) {
            return $path;
        }
        $path = rtrim($path, '/');
        while ('/.' === substr($path, -2)) {
            $path = rtrim(substr($path, 0, -2), '/');
        }

        return '' === $path ? '/' : $path;
    }

    /**
     * Whether this host can create symlinks — the ONE capability
     * probe every link-bearing leg rides (OCR rounds 10-11:
     * t31-ocr10-14 established the probe over function_exists,
     * t31-ocr10-18 the random-suffixed name, t31-ocr11-9 hoisted it
     * here — the shared owner the WpConnectorsTestCase wrapper and the
     * direct harness tests both reach, the private twin deleted).
     *
     * function_exists is not the capability signal (symlink() exists
     * on Windows without the privilege to use it), and the bare
     * call's E_WARNING errors the suite (failOnWarning) — the FALSE
     * RETURN is the signal, @-suppressed. disable_functions(symlink)
     * removes the function itself, and @ cannot suppress a
     * missing-function \Error: the probe guards function_exists first
     * — capability false, a visible skip, never a FATAL of the very
     * battery the probe exists to protect. The probe name carries a
     * random suffix: a predictable, pid-enumerable name is
     * pre-plantable on a shared host, and a planted entry makes
     * symlink() fail — the probe reads false and every link-bearing
     * leg silently skips, coverage suppressed by the plant.
     *
     * @return bool True when a probe link can be created and removed.
     */
    public static function canSymlink(): bool
    {
        if (! function_exists('symlink')) {
            return false;
        }
        $probe = sys_get_temp_dir() . '/wpct-capability-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $ok = @symlink('/usr/bin/true', $probe);
        if ($ok) {
            @unlink($probe);
        }

        return $ok;
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
     * (The docblock sat orphaned above the spelling helpers' own
     * docblocks — only the LAST block before a declaration attaches,
     * so this @param/@return contract was dead text no tool ever read;
     * moved to its declaration in t31-ocr9-7.)
     *
     * @param string $dir Absolute directory path.
     * @return void
     * @throws RuntimeException When the spelling collapses to the filesystem root (t31-ocr10-1) — the universal tree is never a scratch dir — or its realpath resolution fails (t31-ocr11-20).
     */
    public static function rrmdir($dir)
    {
        /*
         * A trailing slash (or a trailing '/.' component — the same
         * class one spelling over, t31-ocr8-13 — or a trailing '/..',
         * the third member, t31-ocr9-1) defeats is_link(): the
         * engine's stat resolves THROUGH the final link, and is_dir()
         * then follows it — the walk below would empty the TARGET tree
         * ('/..' the tree of the target's PARENT — a strictly larger
         * blast radius, driven), the exact pre-plant shape the root
         * guard exists to stop (OCR round 8, t31-ocr8-2). The link
         * probe reads link_probe_spelling(), which strips all three
         * tails; the walk rides same_directory_spelling() — the
         * same-directory tails only, because a stripped '/..' would
         * name a DIFFERENT directory than the caller's path. The root
         * '/' itself survives both, and a separators-only spelling
         * ('/.', '//') collapses to that root, never '' (t31-ocr13-3)
         * — the silent no-op is not this owner's vocabulary.
         */
        $caller_spelling = $dir;
        $dir = self::same_directory_spelling($dir);
        if (is_link(self::link_probe_spelling($dir))) {
            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        /*
         * The ROOT collapse (t31-ocr10-1, the deletion twin of
         * copyTree's ocr9-9 mirror clause): same_directory_spelling()
         * keeps a '/..' tail for the walk (it names a different
         * directory), so rrmdir(sys_get_temp_dir() . '/..'), rrmdir('/'),
         * any scratch spelling collapsing to '/' passed the probes
         * above and CHILD_FIRST deleted the ROOT's children (driven:
         * rrmdir('/') walked straight into unlink()/rmdir() over the
         * filesystem root; rrmdir('scratch/sub/..') deleted the
         * PARENT's entries through the collapse). The root is judged
         * exactly as copyTree judges it — the universal tree, never a
         * scratch dir — and refused loudly naming the spelling.
         */
        /*
         * A FALSE realpath is the LOUD refusal, not the root check's
         * silent fall-through (OCR round 11, t31-ocr11-20): a TOCTOU
         * vanishing or an open_basedir wall between the is_dir() probe
         * above and this resolution made realpath() answer false,
         * `false === '/'` read as "not the root", and the walk fell
         * through to the SPL iterator — whose UnexpectedValueException
         * is another library's vocabulary wearing the harness verdict
         * (the copyTree t31-ocr10-9 twin's exact shape, one owner
         * over). Unreachable on this runner (no open_basedir, no
         * concurrent removal) — construction-evident: false never
         * reaches an iterator again.
         */
        $dir_real = realpath($dir);
        if (false === $dir_real) {
            throw new RuntimeException('WpHarness::rrmdir() refuses a spelling whose realpath resolution failed — the tree is unreadable through this process (open_basedir, or it vanished mid-call): ' . $caller_spelling);
        }
        if ('/' === $dir_real) {
            throw new RuntimeException('WpHarness::rrmdir() refuses a spelling that collapses to the filesystem ROOT — the universal tree is never a scratch dir: ' . $caller_spelling);
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
     * here, one error policy — LOUD, like rrmdir()'s). Files ONLY: the
     * walk is LEAVES_ONLY, so EMPTY source directories are silently
     * dropped (no leaf, no copy, no target directory).
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
     * under test. The MIRROR relation refuses too (t31-ocr9-2): a
     * target that CONTAINS the source lets a nested same-name segment
     * resolve the copy inside the tree being read — the symmetric
     * direction, same owner. One containment check, realpath-based,
     * before a
     * single byte moves: the not-yet-created target is judged through
     * its nearest EXISTING ancestor (t31-ocr8-3 — the former purely
     * lexical judgment could not see through a '..'-woven alias or an
     * absent target reached through a SYMLINKED ancestor; the
     * ancestor resolution closes both spellings, and the lexical
     * ceiling remains only for a chain nothing of which exists).
     *
     * @param string $from Absolute source directory.
     * @param string $to   Target directory — absolute, or relative (judged from the process cwd per t31-ocr11-5, its containment resolved through the TRUE tree the spelling names; the landing keeps the caller's spelling).
     * @return void
     * @throws RuntimeException When the source (or any entry in it) is a symlink, the source is missing, not a directory, or collapsed to the filesystem root (t31-ocr12-3), the target is the source itself, inside it, or contains it, or a relative target's working directory cannot be resolved (t31-ocr11-5).
     */
    public static function copyTree($from, $to)
    {
        /*
         * The root-link probe reads the link-probe spelling (trailing
         * slashes, trailing '/.' components, and trailing '/..' tails
         * stripped): each forces stat THROUGH the final link — '/..'
         * through the whole link to its target's PARENT (driven
         * pre-fix: copyTree('link/..') copied that parent tree
         * through the link) — and the iterator would then walk the
         * TARGET tree, the copy twin of rrmdir()'s guard, same bypass
         * family (t31-ocr8-2, the '/.' spelling closed in
         * t31-ocr8-13, the '/..' tail in t31-ocr9-1). Only the PROBE
         * normalizes: the walk below keeps seeing the spelling the
         * caller passed (a trailing-slash source keeps its own loud
         * refusal at the relativize arm, t31-ocr6-4, and a
         * '/.'-spelled REAL source keeps copying — the iterator
         * normalizes it), so the refusal message still names the
         * spelling the caller passed.
         */
        if (is_link(self::link_probe_spelling($from))) {
            throw new RuntimeException('WpHarness::copyTree() refuses a symlinked source tree — never followed, never silently skipped: ' . $from);
        }
        if (! is_dir($from)) {
            throw new RuntimeException('WpHarness::copyTree() refuses a source that is not a readable directory — the loud policy, never the SPL iterator\'s surprise: ' . $from);
        }
        $source_real = realpath($from);
        /*
         * A false realpath is the LOUD refusal, not a silent concat
         * (t31-ocr10-9): open_basedir or a TOCTOU vanishing between the
         * is_dir() gate and this resolution made $source_real === false
         * string-concat to '' — the nested-prefix comparison then read
         * '/' and refused EVERY absolute target with the WRONG
         * diagnosis (the containment message, never the resolution
         * failure that actually happened). The shape is unreachable on
         * this runner (no open_basedir, no concurrent removal); the
         * guard is construction-evident — false never reaches a
         * comparison again.
         */
        if (false === $source_real) {
            throw new RuntimeException('WpHarness::copyTree() refuses a source whose realpath resolution failed — the tree is unreadable through this process (open_basedir, or it vanished mid-call): ' . $from);
        }
        /*
         * The SOURCE-side root collapse (t31-ocr12-3, the THIRD
         * symmetry): rrmdir() refuses '/', the TARGET side refuses a
         * root-collapsed landing (t31-ocr9-9's universal-container
         * clause) — but a SOURCE spelling that resolves to '/' passed
         * every guard and walked THE WHOLE ROOT TREE (driven: '/' and
         * a temp-parent '/..' where temp sits directly beneath the
         * root). realpath() collapses the whole spelling class ('/',
         * '/.', '/..', the '/..' tails) to the one container every
         * tree lives inside; the universal container is not a
         * copyable tree, and the refusal names the caller's spelling.
         */
        if ('/' === $source_real) {
            throw new RuntimeException('WpHarness::copyTree() refuses a source collapsed to the filesystem ROOT — the universal container is not a copyable tree: ' . $from);
        }
        /*
         * A not-yet-created target is the copy's own normal shape, but
         * its LEXICAL spelling cannot see through the alias class the
         * ocr7-8 ledger named out-of-scope — a '..'-woven target, or
         * an absent target reached through a SYMLINKED ancestor
         * (realpath() is false for the missing leaf, so both rode the
         * spelling and the copy landed physically inside the source).
         * The nearest EXISTING ancestor carries the truth (t31-ocr8-3):
         * walk up to the first component that exists, resolve THAT
         * (realpath sees through links and dots alike), and re-attach
         * the not-yet-existing remainder — the remainder holds no
         * symlinks (the walk stopped at the first existing component),
         * so a lexical '.'/'..' collapse over the resolved base IS the
         * physical path. Nothing on the chain existing at all (a
         * dangling-link ancestor) keeps the lexical ceiling: absolute
         * normalized scratch paths.
         */
        /*
         * A target whose spelling names no directory (t31-ocr11-22,
         * the round's verifier lens over the ocr11-5 walk): '' and
         * root-separator-only spellings reach the walk (driven red at
         * HEAD: the landing '$to . '/' . $relative' wrote at the
         * FILESYSTEM ROOT — permission-denied warnings unprivileged,
         * real root writes as uid 0) — pre-ocr11-5 the empty spelling
         * was refused by the mirror clause's lexical accident, and
         * the absolute-spelling walk removed the accident. No
         * directory named, no copy — the refusal names both paths.
         */
        if ('' === rtrim((string) $to, '/')) {
            throw new RuntimeException('WpHarness::copyTree() refuses a target that names no directory (empty, or root separators only) — the landing would be the filesystem root: from ' . $from . ' into ' . $to);
        }
        /*
         * The containment walk judges an ABSOLUTE spelling (OCR round
         * 11, t31-ocr11-5): a RELATIVE target bottoms out at
         * dirname('dst') === '.' — a ONE-BYTE ancestor whose strlen
         * ate the first byte of the remainder ('dst' -> 'st',
         * 'sub/dst' -> 'ub/dst'), so target_real rode
         * realpath('.') . 'st', a tree the caller never named, and
         * the containment verdicts below judged (and refused — driven:
         * a legal copy whose mangled spelling collapsed onto the
         * source's parent tree) against a path that is not the
         * target. The walk spells its target from the cwd first; the
         * COPY itself keeps the caller's spelling — the landing is
         * unchanged, only the judgment reads the true tree.
         */
        $to_walk = (string) $to;
        if ('' === $to_walk || '/' !== $to_walk[0]) {
            $cwd = getcwd();
            if (false === $cwd) {
                throw new RuntimeException('WpHarness::copyTree() refuses a relative target while the working directory cannot be resolved — the containment walk has no base to judge against: ' . $to);
            }
            $to_walk = rtrim($cwd, '/') . '/' . ltrim($to_walk, '/');
        }
        /*
         * The resolution LOOP (OCR round 17, t31-ocr17-9 — the
         * round's own verifier-refutation close, driven in-round):
         * the collapse inside re-attaches the not-yet-existing
         * remainder to the resolved anchor LEXICALLY, a license that
         * rests on the t31-ocr8-3 premise "the remainder holds no
         * symlinks" — and that premise holds only BELOW the anchor.
         * A '..' in the remainder can pop the collapse ABOVE the
         * anchor it was resolved against, and the post-pop descent
         * then crosses symlink components nothing resolves: driven at
         * the round's own HEAD, copyTree($src,
         * '<anchor>/b/../../link/dst') with link -> $src collapsed to
         * '<parent>/link/dst' lexically, passed containment against
         * the unresolved spelling, stopped the landing walk AT the
         * link (is_link is a stop condition, never a resolution),
         * and RETURNED NORMALLY with the copy landed INSIDE the
         * source — the plain spelling of the same landing refuses —
         * while the FILE-link variant died in raw mkdir()/copy()
         * warnings. The collapsed resolution now walks the SAME
         * judgment the spelling walked — sentinel, file crossing,
         * dangling link, anchor realpath, collapse — and the loop
         * repeats until the judgment spelling IS its own resolution
         * (a resolution of '/' is stable too: the root is the
         * mirror clause's own universal-container verdict, not the
         * sentinel's nonexistent-chain one). Termination is
         * construction-evident: the first collapse is dot-free, so
         * the second pass's remainder is a nonexistent tail whose
         * anchor resolves every link it stops at, and the third pass
         * re-derives the same string.
         */
        while (true) {
            $ancestor = rtrim($to_walk, '/');
            while ('' !== $ancestor && '/' !== $ancestor && ! is_dir($ancestor) && ! is_link($ancestor) && ! is_file($ancestor)) {
                $ancestor = dirname($ancestor);
            }
            /*
             * The walk's '/' SENTINEL is a refusal shape, not an answer
             * (OCR round 16, t31-ocr16-5): the loop stops at '/' without
             * ever consulting the is_dir/is_link/is_file gates every
             * other stop rides, and an ancestor of '/' means NO component
             * of the named chain exists — the landing would create its
             * FIRST component directly beneath the filesystem root (the
             * ocr11-22 root-landing blast radius exactly one component
             * deeper: driven at HEAD, copyTree() into
             * '/<all-nonexistent>/dest' passed every guard and died in
             * raw mkdir()/copy() warnings — real first-level writes as
             * uid 0 — then RETURNED NORMALLY having moved nothing). The
             * sentinel gets its siblings' vocabulary: refuse loudly,
             * naming the chain and the sentinel.
             */
            if ('/' === $ancestor) {
                throw new RuntimeException('WpHarness::copyTree() refuses a target whose chain has no existing component — the ancestor walk bottomed out at the filesystem ROOT sentinel, and the landing would create the first component directly beneath it: from ' . $from . ' into ' . $to);
            }
            /*
             * A regular FILE in the target chain (t31-ocr10-10): the walk
             * above used to step PAST one (not a dir, not a link — exactly
             * its walk-on conditions), so containment was judged against
             * an ancestor ABOVE the file, passed, and the copy died later
             * in mkdir() as a raw converted E_WARNING instead of the
             * policy RuntimeException the @throws contract promises — no
             * byte moved, but the verdict wore the engine's vocabulary.
             * The walk stops at ANY existing component now; one that
             * resolves to a file (a link to one included) is a malformed
             * target chain — no directory can be created through it.
             */
            if (is_file($ancestor)) {
                throw new RuntimeException('WpHarness::copyTree() refuses a target whose chain crosses a regular FILE — no directory can be created through it: from ' . $from . ' into ' . $to . ' (the crossing component: ' . $ancestor . ')');
            }
            /*
             * The DANGLING-link twin (OCR round 16, t31-ocr16-6, the
             * ocr10-10 vocabulary-leak class reopened one shape deeper):
             * the walk stops at a link (is_link is a stop condition), but
             * is_file() FOLLOWS it — a link to a file refuses above, a
             * link to a directory resolves through realpath below, and a
             * DANGLING link answers false to both, so it fell to
             * realpath()'s false and the LEXICAL containment fallback:
             * the verdict judged a spelling, then the landing's recursive
             * mkdir died THROUGH the dangling link in raw engine
             * warnings (driven: 'mkdir(): No such file or directory',
             * copy() failing behind it, copyTree() RETURNING NORMALLY
             * having moved nothing — the silent third in the engine's
             * vocabulary, never the policy's). A link resolving to
             * nothing is a malformed chain exactly like the file: no
             * directory can be created through it, and the refusal names
             * it with the same vocabulary.
             */
            if (is_link($ancestor) && ! is_dir($ancestor) && ! is_file($ancestor)) {
                throw new RuntimeException('WpHarness::copyTree() refuses a target whose chain crosses a DANGLING symlink — the link resolves to nothing, so no directory can be created through it and the landing would die in the engine\'s vocabulary: from ' . $from . ' into ' . $to . ' (the crossing component: ' . $ancestor . ')');
            }
            $ancestor_real = realpath($ancestor);
            if (false !== $ancestor_real) {
                $remainder = substr(rtrim($to_walk, '/'), strlen($ancestor));
                $collapsed = array();
                foreach (explode('/', $ancestor_real . $remainder) as $segment) {
                    if ('' === $segment || '.' === $segment) {
                        continue;
                    }
                    if ('..' === $segment) {
                        array_pop($collapsed);
                        continue;
                    }
                    $collapsed[] = $segment;
                }
                $resolved = '/' . implode('/', $collapsed);
            } else {
                $resolved = rtrim($to_walk, '/');
            }
            if ($resolved === $to_walk || '/' === $resolved) {
                $target_real = $resolved;
                break;
            }
            $to_walk = $resolved;
        }
        if ($target_real === $source_real || 0 === strpos($target_real, $source_real . '/')) {
            throw new RuntimeException('WpHarness::copyTree() refuses a target that is the source itself or inside it — a self-copy is a silent no-op success riding the engine\'s same-file mercy, and a nested target writes the copy into the very tree it reads: from ' . $from . ' into ' . $to);
        }
        /*
         * The MIRROR relation (t31-ocr9-2): the target CONTAINS the
         * source — copyTree('/a/src', '/a') passed the check above,
         * and a nested same-name segment ('/a/src/src/file.php')
         * resolved the copy INSIDE the tree being read (driven
         * pre-fix: src/src/nested.php landed at src/nested.php, plus
         * collateral in the containing parent) — the copy twin of the
         * nested-target refusal, symmetric direction, the same
         * ancestor-resolved containment owner. The ROOT collapse
         * (t31-ocr9-9, the verifier's refutation lens): an
         * existing-ancestor target that resolves to '/' made the
         * prefix '$target_real . '/' read '//' — a string no
         * normalized path contains — so the filesystem root, an
         * ancestor of EVERY source, passed both guards and the copy
         * attempted '/<relative>' writes (driven); the root is judged
         * as the universal container now.
         */
        if ($target_real === '/' || 0 === strpos($source_real, $target_real . '/')) {
            throw new RuntimeException('WpHarness::copyTree() refuses a target that CONTAINS the source — the mirror of the nested-target refusal: a nested same-name segment would resolve the copy inside the very tree it reads, and a target collapsed to the filesystem ROOT contains every source: from ' . $from . ' into ' . $to);
        }
        /*
         * The landing policy judges the COLLAPSED resolution too (OCR
         * round 17, t31-ocr17-1): the sentinel above owns the
         * SPELLING'S chain, and a '..'-woven target can anchor the
         * ancestor walk at an EXISTING component while the collapse
         * resolves elsewhere — driven at HEAD, copyTree($src,
         * '/../dst') anchored at the existing '/..' (is_dir resolves
         * it to the root), collapsed $target_real to the first-level
         * '/dst', passed every containment clause, and died in raw
         * mkdir()/copy() warnings at the ROOT'S first level
         * (permission-denied unprivileged — real first-level writes
         * as uid 0) having RETURNED NORMALLY — the ocr16-5 sentinel's
         * exact blast radius, escaped by a spelling whose chain walked
         * to an anchor. The same first-level rule, judged wherever
         * the chain RESOLVES: nothing on the collapsed chain existing
         * means the landing would create its first component directly
         * beneath the root, so the resolution gets the sentinel's own
         * walk and vocabulary. (The t31-ocr17-9 loop above owns every
         * RESOLVABLE chain now — a stable $target_real always walks
         * to an existing anchor — so this walk's live reach is the
         * loop's UNRESOLVABLE arm, the realpath-false fallback that
         * keeps a lexical spelling; it judges that arm's landing by
         * the same rule, and stays as the resolved chain's belt.)
         */
        $landing = $target_real;
        while ('' !== $landing && '/' !== $landing && ! is_dir($landing) && ! is_link($landing) && ! is_file($landing)) {
            $landing = dirname($landing);
        }
        if ('/' === $landing) {
            throw new RuntimeException('WpHarness::copyTree() refuses a target whose RESOLVED chain has no existing component — the collapsed landing would create the first component directly beneath the filesystem root, the root sentinel\'s rule judged on the resolution rather than the spelling: from ' . $from . ' into ' . $to . ' (the collapsed resolution: ' . $target_real . ')');
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
            /*
             * Directory entries are never yielded at all: the iterator
             * runs LEAVES_ONLY (the RecursiveIteratorIterator default),
             * so the only dir-shaped yields would be LINKED dirs — and
             * the isLink() refusal above already owns those (t31-ocr8-4
             * removed the dead isDir() continue this knowledge rode).
             * The LEAVES_ONLY corollary stands: EMPTY source directories
             * are silently dropped — no leaf, no copy, no target dir.
             */
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
     * Runs one guarded call that must refuse, and returns the collected
     * exception for the caller's fragment assertions — the ONE
     * refusal-verdict owner.
     *
     * The swept shape — $this->fail() INSIDE the try, fragments asserted
     * in the catch — was broken per spelling (t31-ocr8-1): PHPUnit's
     * AssertionFailedError EXTENDS RuntimeException, so a no-throw
     * regression (the guarded call returning normally) landed the
     * fail() message IN the catch, and the fragment assertions ran
     * against the FAIL MESSAGE itself — a vacuous pass wherever the
     * message carried the fragment, a confusing re-fail over the
     * failure message everywhere else. The owner collects the
     * exception inside, fails OUTSIDE any catch, and the caller
     * asserts its fragments on the returned verdict.
     *
     * The FAMILY pin (t31-ocr9-3): the r8 sweeps converted catches that
     * declared an exception family (RuntimeException, \Exception) to a
     * \Throwable owner — silently DROPPING the family the original
     * catch enforced, so a stray TypeError/Error carrying the
     * fragments passed green (driven: a planted TypeError-with-fragment
     * kept the whole pin green at HEAD). The third parameter restores
     * the pin: each converted site passes the family its ORIGINAL
     * catch declared; \Throwable::class enforces nothing and is
     * legitimate ONLY where the original catch was itself \Throwable.
     * The parameter is REQUIRED (t31-ocr10-6): a default of
     * \Throwable::class pinned nothing, and the omission was invisible
     * at the call site.
     *
     * Hoisted here from the WpConnectorsTestCase wrapper (t31-ocr15-7,
     * the canSymlink t31-ocr11-9 shape): the plain-TestCase suites
     * (HarnessCopyTreeTest, SelfContainmentCompoundWritesTest) do not
     * extend the wrapper — one extension away is still away, and their
     * hand-rolled $caught=null/try/catch/fail-if-null shapes were the
     * verbatim twins the rounds kept having to fix twice. The wrapper
     * keeps a thin delegate so its subclasses' $this->refusalOf() call
     * sites are untouched; this static is the one implementation.
     *
     * The family-mismatch verdict CHAINS the original exception (OCR
     * round 19, t31-ocr19-4): an unexpected exception IS the signal —
     * the verdict names the pinned family and the caught class, and
     * the original rides the previous-exception chain so its real
     * message and stack trace are one __toString away in the failure
     * output, not discarded at the exact seam where diagnosis matters
     * most.
     *
     * @param callable $attempt     The guarded call, expected to throw.
     * @param string   $expectation The failure message for the no-throw case.
     * @param string   $family      The exception family the site pins — the class its original catch declared; \Throwable::class pins nothing and is legitimate only where the original catch was itself \Throwable.
     * @return \Throwable The collected refusal.
     * @throws \PHPUnit\Framework\AssertionFailedError When the attempt does not throw ($expectation), or throws outside the pinned family.
     */
    public static function refusalOf(callable $attempt, string $expectation, string $family): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $e) {
            if (! $e instanceof $family) {
                throw new \PHPUnit\Framework\AssertionFailedError(
                    'The refusal class is outside the family this site pins (expected ' . $family . ', got ' . get_class($e) . ') — the original catch declared ' . $family . ', and a stray Error carrying the fragments would otherwise pass silently (t31-ocr9-3).',
                    0,
                    $e
                );
            }

            return $e;
        }

        throw new \PHPUnit\Framework\AssertionFailedError($expectation);
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
