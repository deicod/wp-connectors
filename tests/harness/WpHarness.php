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
     * full-chain reach.
     *
     * The anchor is the harness's OWN TERRITORY, generalized (OCR
     * round 23, t31-ocr23-6 over the r19 rule): the r19 carve-out
     * exempted only the components of the temp spelling, so every
     * OTHER absolute chain kept the full walk from '/' — and a
     * host-layout link ABOVE a source root outside the temp tree (the
     * repository's own sources on a host whose upper layout resolves
     * through links — NIS/automount homes, a symlinked checkout root)
     * fired the planted-link verdict on a legal tree: copyTree
     * refused the source, rrmdir skipped the cleanup, the r19
     * false-refusal class one territory over. The probe judges the
     * territory the harness OWNS now — the deepest anchor of the
     * passed chain the harness itself vouches for: a component is
     * probed IFF its chain-so-far sits strictly BENEATH one of the
     * two anchors, the temp spelling (r19) and the REPOSITORY ROOT
     * (this round — the tree these helpers live in, whose dist-side
     * scratch batteries plant links and keep the doctrine's full
     * reach). The components of either anchor's own spelling, and
     * every chain outside both, are the host's layout — never the
     * planted-link class (the ocr17-2 threat was always the
     * harness's PREDICTABLE names inside its own territories); a
     * caller spelling a foreign upper chain through a link is
     * spelling the host's own layout the way the engine hands every
     * process /var/… through /var, and the entry-level guards below
     * the root keep their own vocabulary regardless (a linked child
     * unlinks as itself, never followed). A RELATIVE spelling rides
     * the same condition — its '.'-led chains never sit beneath an
     * anchor, so the walk exempts them (the consumers' contracts say
     * absolute; the relative arm stays the best-effort vocabulary,
     * and no committed leg pins a relative link chain).
     *
     * The comparisons and the carry speak ONE vocabulary (OCR round
     * 32, t31-ocr32-8): on a non-POSIX host the anchors answer in
     * the host's own separator spelling while the carry is
     * '/'-joined, so the prefix compares could never match and the
     * probe answered the HOST, never the path. The path and both
     * anchors fold through the ONE comparison owner there
     * (posix_comparison_vocabulary, the ocr29-4 arm — identity on
     * POSIX, where a legal '\' filename byte stays, the r29-3
     * doctrine), and a drive-letter absolute spelling joins the
     * '/'-rooted arm (the ocr28-3 predicate) with the anchor
     * prefixes gaining the carry's leading '/'. The returned probe
     * spelling is the folded '/'-joined one — is_link() resolves
     * both spellings on a separator host. The path's fold precedes
     * the TAIL STRIPS (OCR round 33, t31-ocr33-4): the strips judge
     * '/'-spelled tails only, so on a '\' host the fold must land
     * before them or '\..'/'\.'/trailing-'\' never match.
     *
     * @param string $path The path as the caller spelled it.
     * @return string The spelling an is_link() probe can trust.
     */
    private static function link_probe_spelling($path)
    {
        /*
         * Fold FIRST, strip SECOND (OCR round 33, t31-ocr33-4 — the
         * order-of-operations correction at the ocr32-8 fold's own
         * owner): the tail strips once ran BEFORE the non-POSIX
         * fold, so on a '\' host the tails arrived backslash-spelled
         * — '\..', '\.', a trailing '\' — while
         * same_directory_spelling() rtrims '/' only and the while
         * loop tests '/..' only: the tails never matched, the probe
         * read a tail-bearing spelling, and the anchor walk below
         * judged the wrong chain. The path folds through the ocr32-8
         * vocabulary at the HEAD, and every strip below sees
         * '/'-spelled tails; the POSIX host rides the identity (a
         * legal '\' filename byte stays, the r29-3 doctrine),
         * byte-unchanged.
         */
        $posix = self::isPosixHost();
        if (! $posix) {
            $path = str_replace('\\', '/', $path);
        }
        $path = self::same_directory_spelling($path);
        while ('/..' === substr($path, -3)) {
            $path = self::same_directory_spelling(rtrim(substr($path, 0, -3), '/'));
        }
        /*
         * The anchor comparisons speak ONE vocabulary (OCR round 32,
         * t31-ocr32-8 — the ocr28-3/ocr29-4 platform gate doctrine,
         * at this owner): the carry below is '/'-joined by
         * construction, but the two anchors answered in the HOST's
         * own separator spelling — sys_get_temp_dir() and
         * dirname(__DIR__, 2) are backslash-joined on a '\' host —
         * so every prefix comparison judged two vocabularies against
         * each other and could NEVER match: the probe answered the
         * HOST, never the path (every component "outside both
         * anchors", the mid-chain link walk silently off — the r17-2
         * full-chain reach gone for every path the host spells). The
         * path's own fold lives at the head (t31-ocr33-4); both
         * anchors fold here through the ONE comparison owner
         * (posix_comparison_vocabulary, the ocr29-4 arm). A
         * drive-letter absolute spelling joins the '/'-rooted arm
         * there (the ocr28-3 predicate): its carry spells '/C:/…',
         * so the anchor prefixes gain the same leading '/' — the
         * comparison holds in one vocabulary for every absolute
         * spelling a host hands its processes. The returned probe
         * spelling is the folded '/'-joined one (is_link resolves
         * both spellings on a separator host); construction-evident
         * — no test sim flips DIRECTORY_SEPARATOR (the ocr28-3
         * boundary), and the POSIX link batteries below pin the
         * identity side.
         */
        $temp = rtrim(self::posix_comparison_vocabulary(sys_get_temp_dir()), '/');
        // The second anchor: the repository root, spelled as these
        // helpers themselves are (every in-repo consumer derives its
        // non-temp paths from the same spelling). The DEGENERATE
        // spelling is normalized (OCR round 26, t31-ocr26-7): a
        // repository root AT the filesystem root made the prefix
        // needle '$repo . \'/\'' spell '//', which no $carry
        // (single-slash joins) ever starts with — $beneath_repo
        // stayed false for every component and the repo territory
        // was silently absent (the temp anchor's own degenerate
        // guard — '' !== $temp — already models the shape; this
        // one lacked its twin). At '/' the prefix is '/' itself:
        // every absolute carry sits beneath the root repo, exactly
        // the territory doctrine's claim for it. Construction-
        // evident — driving it needs a checkout at '/<dir>/…'
        // (a root-writable host; the driven sim stays the
        // unprivileged-host ceiling, the ocr25-4 residual's own
        // class).
        $repo = self::posix_comparison_vocabulary(dirname(__DIR__, 2));
        $repo_prefix = '/' === $repo ? '/' : $repo . '/';
        if ($posix) {
            $temp_prefix = $temp . '/';
        } else {
            // The '/'-joined carry of a drive-letter spelling leads
            // with the separator ('/C:/…'); the anchors join it there
            // (the fold's own ltrim, a no-op for '/…'-spelled anchors
            // that already lead the way the carry spells them).
            $temp_prefix = '/' . ltrim($temp, '/') . '/';
            $repo_prefix = '/' === $repo ? '/' : '/' . ltrim($repo, '/') . '/';
        }
        $absolute = '/' === ($path[0] ?? '') || (! $posix && 1 === preg_match('/\A[A-Za-z]:/', (string) $path));
        $carry = $absolute ? '' : '.';
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment) {
                continue;
            }
            $carry .= '/' . $segment;
            $beneath_temp = '' !== $temp && 0 === strpos($carry, $temp_prefix);
            $beneath_repo = 0 === strpos($carry, $repo_prefix);
            if (! $beneath_temp && ! $beneath_repo) {
                // Outside both anchors — a component of either anchor
                // spelling's own chain (r19's temp rule, the r23 repo
                // twin), or at/above/outside the territories entirely:
                // the host's layout, never the planted-link class.
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
     * Whether this host can spawn child processes through the shell
     * vocabulary — the ONE capability owner every exec/escapeshellarg
     * guard in the battery consults (t31-ocr21-4: the pair was
     * duplicated near-verbatim at every spawn consumer — five named
     * sites and more riding inline — the twin shape the one-owner
     * doctrine exists to close; a future arm added to one hand-rolled
     * copy would silently fork the guards' verdicts). The PAIR
     * (exec, escapeshellarg) is the floor; a consumer that spawns
     * through more than exec() names its extra (proc_open, the
     * t31-ocr17-10 leg) as its own variadic argument.
     *
     * Unlike canSymlink() the signal IS function_exists: symlink()
     * exists on Windows without the privilege to use it (a probe is
     * the only honest answer there), but exec()/escapeshellarg() are
     * plain functions whose absence under disable_functions is the
     * whole capability story — nothing to probe beyond the listing.
     * The skip MESSAGE stays at each call site, naming its own
     * subject and what already passed; this owner answers only the
     * boolean.
     *
     * @param string ...$functions Extra spawn-function names the consumer rides beyond the pair (e.g. 'proc_open').
     * @return bool True when exec, escapeshellarg, and every named extra exist.
     */
    public static function canSpawnChildren(string ...$functions): bool
    {
        foreach ($functions as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return function_exists('exec') && function_exists('escapeshellarg');
    }

    /**
     * Whether this host's platform is the POSIX one — the ONE owner of
     * the platform-separator premise (hoisted at its THIRD consumer,
     * OCR round 23: t31-ocr22-2 established the repo's first platform
     * probe inline at the POSIX-root battery, t31-ocr23-5 added the
     * second at the redirected-TMPDIR sim and censused the decision —
     * inline while the judgment is a bare constant compare — and
     * t31-ocr23-6's sim made the third, the recorded hoist threshold).
     *
     * Unlike the capability probes this answers the PLATFORM, never a
     * capability: the consumers premise POSIX-hosted vocabulary (root
     * resolution per the t31-ocr11-2 doctrine; TMPDIR-driven temp
     * resolution) — different premises over the same constant, which
     * is why the owner answers only the boolean and every call site
     * keeps naming ITS OWN premise in the skip message (the
     * canSpawnChildren shape: site messages, one owner).
     *
     * @return bool True when DIRECTORY_SEPARATOR is the POSIX '/'.
     */
    public static function isPosixHost(): bool
    {
        return '/' === DIRECTORY_SEPARATOR;
    }

    /**
     * Whether this host RESOLVES path spellings case-insensitively —
     * the ONE owner of the path-case premise (OCR round 35,
     * t31-ocr35-5), derived like isPosixHost() above it: PROBED, never
     * assumed from the platform boolean. This spelling judges the
     * TEMP volume (the scratch trees' own); the containment verdicts
     * derive per VOLUME through the same probe core below
     * (t31-ocr36-5).
     *
     * macOS is the motivating shape: a POSIX host passing every
     * isPosixHost() gate whose filesystem (and class loading through
     * it) resolves '/scratch/SRC' and '/scratch/src' to the SAME tree
     * — so a byte-wise containment verdict is wrong there exactly
     * where it is right on Linux. The probe plants a MIXED-CASE file
     * in the judged base (the canSymlink shape: random-suffixed,
     * never pid-enumerable or pre-plantable) and asks file_exists()
     * for a case-VARIANT spelling of it: existence of the variant is
     * the host's own answer. The answer is CACHED per volume (the
     * probe is filesystem work) — MEASURED answers only
     * (t31-ocr38-2): a base that cannot be planted answers the
     * conservative case-sensitive verdict UNMEASURED, never cached,
     * so one failed plant cannot poison a case-insensitive volume's
     * later derivations.
     *
     * @return bool True when a case-variant spelling of an existing file exists.
     */
    public static function isCaseInsensitivePathHost(): bool
    {
        return self::caseProbeAnswer(sys_get_temp_dir());
    }

    /**
     * The probe core: the case answer for ONE volume, judged by
     * planting in the volume's SCRATCH REPRESENTATIVE — the base
     * itself when it sits under the temp root, else the temp root
     * when it shares the volume's device, never the judged tree
     * itself (OCR round 36, t31-ocr36-5; the representative fence,
     * t31-ocr40-5).
     *
     * Case resolution is a PER-VOLUME property, and the r35 probe
     * consulted ONE host-wide cached answer probed exclusively in
     * sys_get_temp_dir() — but this suite's copyTree shapes
     * routinely span volumes (repo-rooted sources into temp-rooted
     * targets), and DERIVE FIRST confirms the shapes CAN diverge on
     * the hosts the suite serves: macOS installs happily onto a
     * case-SENSITIVE APFS volume (the dev setup that catches
     * case-sensitivity bugs) while the system volume holding /tmp is
     * the default case-insensitive one — and the inverse is one
     * TMPDIR redirect away (a redirect this suite itself simulates),
     * temp landing on any volume the user chooses. A host-wide
     * answer folds repo-rooted verdicts by the TEMP volume's answer —
     * false refusals one way, the r35 self-copy class surviving the
     * other. The answer is derived at the volume each verdict judges,
     * cached by the volume's stat() device id (host truth; Linux's
     * uniformly case-sensitive volumes answer false everywhere, every
     * verdict riding unchanged there).
     *
     * @param string $base An existing directory on the volume to judge.
     * @return bool True when a case-variant spelling of an existing file exists on that volume.
     */
    private static function caseProbeAnswer(string $base): bool
    {
        $stat = @stat($base);
        if (false === $stat) {
            // The base cannot be stat'ed — no probe is possible, the
            // case-sensitive arm answers (the r35 premise, kept).
            return false;
        }
        $volume = (string) $stat['dev'];
        if (\array_key_exists($volume, self::$case_insensitive_volumes)) {
            return self::$case_insensitive_volumes[ $volume ];
        }
        /*
         * The plant anchor is the volume's SCRATCH REPRESENTATIVE,
         * never the judged tree itself (OCR round 40, t31-ocr40-5):
         * the given base is the nearest EXISTING ancestor of whatever
         * path the derivation first judged, and for the suite's
         * copyTree shapes that is routinely a REPOSITORY-rooted source
         * tree (copyTree() from tests/fixtures/plugins) — the probe
         * once planted wpct-pathcase-* DIRECTLY INTO the judged tree,
         * a junk window inside the repository on every measurement
         * and, for a probe whose process died between its plant and
         * the bare @unlink (a killed run, a fatal one process over),
         * residue the repo tree never reclaims (driven: a killed
         * child's wpct-pathcase-* inside the fixtures tree). The
         * representative is the base itself when the base already
         * sits under the engine's temp root (a scratch base plants
         * where it scratches — the temp-under probes keep their
         * plant, so the r38-2 planted-fail shape keeps its
         * write-denial subject), else the temp root when it sits ON
         * the judged volume (the same stat() device id the cache
         * keys by — the volume answer derives from its own
         * representative, measured on the volume it judges), else NO
         * plant at all: the conservative case-sensitive verdict
         * answers UNMEASURED and UNCACHED (the r38-2 doctrine)
         * rather than planting outside scratch. A finally-unlink
         * fence alone was weighed and declined: nothing between the
         * plant and the unlink can throw in-process (the measurement
         * is file_exists()), and the crash class this closes is
         * process death, which no finally survives — the residue
         * belongs in scratch or nowhere.
         */
        $plant_base = $base;
        $temp_real = self::posix_comparison_vocabulary((string) realpath(sys_get_temp_dir()));
        $base_real = self::posix_comparison_vocabulary((string) realpath($base));
        if ($base_real !== $temp_real && 0 !== strpos($base_real, $temp_real . '/')) {
            $temp_stat = @stat(sys_get_temp_dir());
            if (false === $temp_stat || (string) $temp_stat['dev'] !== $volume) {
                return false;
            }
            $plant_base = sys_get_temp_dir();
        }
        $stem = 'wpct-pathcase-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $probe = $plant_base . '/' . $stem . 'AbC.probe';
        $variant = $plant_base . '/' . $stem . 'aBc.probe';
        $planted = false !== @file_put_contents($probe, 'case probe');
        if (! $planted) {
            /*
             * A failed plant is NEVER a measured answer (OCR round 38,
             * t31-ocr38-2): the unchecked arm once wrote the
             * unmeasured false into the per-volume cache — on a
             * case-insensitive volume (the exact host class this
             * machinery serves) one failed plant (a read-only probe
             * base, ENOSPC, quota) poisoned every later derivation on
             * that volume for the whole process. The conservative
             * case-sensitive verdict answers UNMEASURED — the
             * containment refusals err byte-wise, the correct verdict
             * wherever the variant spelling names a different file —
             * and the cache holds only measured answers, so a later
             * call with a plantable base re-measures.
             */
            return false;
        }
        $answer = file_exists($variant);
        @unlink($probe);

        return self::$case_insensitive_volumes[ $volume ] = $answer;
    }

    /**
     * The case answer for the VOLUME a resolved path resolves on —
     * the per-volume derivation's path-facing arm (t31-ocr36-5): the
     * probe plants in the path's nearest EXISTING ancestor (a
     * not-yet-created target resolves on the volume its anchor sits
     * on), never at a filesystem root the walk cannot write.
     *
     * @param string $resolved A comparison-vocabulary ('/'-joined) path.
     * @return bool True when that path's volume resolves case-insensitively.
     */
    private static function pathVolumeResolvesCaseInsensitively(string $resolved): bool
    {
        $probe_dir = $resolved;
        while (true) {
            if (is_dir($probe_dir)) {
                break;
            }
            $parent = dirname($probe_dir);
            // A fixed point ('/' — or a drive root on a '\' host) with
            // nothing existing below it: the ocr16-5 sentinel refuses
            // such chains before the folds run, so this arm answers
            // the case-sensitive default rather than probing a root.
            if ($parent === $probe_dir) {
                return false;
            }
            $probe_dir = $parent;
        }

        return self::caseProbeAnswer($probe_dir);
    }

    /**
     * The per-volume case probe's cached answers — HOST truth, never
     * test state: reset() does not touch them (the host's filesystem
     * does not reset between tests), and no test sim can flip one (a
     * planted probe file answers the real question — a plant is the
     * host's own case behavior), keyed by the volume the answer
     * judges.
     *
     * @var array<string, bool>
     */
    private static $case_insensitive_volumes = array();

    /**
     * A realpath() OUTPUT in the comparison vocabulary the containment
     * verdicts speak — the platform owner's sibling arm (OCR round 29,
     * t31-ocr29-4).
     *
     * The containment comparisons join their needles with '/' ('$a .
     * "/"'), and on a non-POSIX host realpath() ANSWERS in the host's
     * own separator vocabulary (backslash-joined), so the prefix
     * compares could never match — containment either always-refused
     * or always-passed, the verdict judging vocabulary noise. The one
     * vocabulary at the owner: realpath output is normalized to the
     * POSIX spelling before any containment judgment reads it. On a
     * POSIX host the arm is the IDENTITY (a legal '\' in a filename
     * stays — realpath never spells it a separator there), so the
     * POSIX behavior rides byte-identical; the non-POSIX verdicts are
     * construction-evident (DIRECTORY_SEPARATOR, a constant no test
     * sim flips — the t31-ocr28-3 doctrine).
     *
     * @param string $resolved A realpath() answer (never false — the callers gate first).
     * @return string The same path, '/'-joined.
     */
    private static function posix_comparison_vocabulary(string $resolved): string
    {
        if (self::isPosixHost()) {
            return $resolved;
        }

        return str_replace('\\', '/', $resolved);
    }

    /**
     * A resolved path in the CASE vocabulary the containment verdicts
     * speak on a case-insensitive host — the platform owner's sibling
     * arm (OCR round 35, t31-ocr35-5, beside posix_comparison_vocabulary
     * above).
     *
     * The containment verdicts compare byte-wise, which is CORRECT
     * exactly where the host resolves paths case-sensitively — but
     * macOS is a POSIX host passing every isPosixHost() gate whose
     * filesystem (and class loading through it) resolves a case-variant
     * target ('/scratch/SRC' beside a source '/scratch/src') to the
     * SAME tree, so the byte-wise verdicts passed the exact self-copy
     * and mirror shapes the guard exists to kill (DERIVE FIRST — the
     * ledger's platform doctrine, never a blind case-insensitive
     * compare). Each side's behavior is DERIVED through the per-volume
     * probe arm (pathVolumeResolvesCaseInsensitively(), t31-ocr36-5 —
     * case resolution is a VOLUME property, and the suite's shapes
     * span volumes): a side folds through ITS OWN volume's answer, so
     * same-volume sides stay consistent under one answer while
     * cross-volume sides are different trees regardless; where every
     * volume resolves case-sensitively (this runner: Linux) the arm is
     * the IDENTITY and every byte-wise verdict rides unchanged —
     * construction-evident, the fold's driven red living only on a
     * case-insensitive volume. The fold is the ASCII table through
     * strtr (locale-independent, the Turkish-locale pins' own
     * vocabulary — never strtolower).
     *
     * @param string $resolved A realpath()-derived answer (already folded through posix_comparison_vocabulary()).
     * @return string The same path, ASCII-case-folded on a case-insensitive volume; byte-identical otherwise.
     */
    private static function case_insensitive_containment_fold(string $resolved): string
    {
        if (! self::pathVolumeResolvesCaseInsensitively($resolved)) {
            return $resolved;
        }

        return strtr($resolved, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * Whether a RESOLVED, comparison-folded path is a universal
     * container root — the POSIX root '/' or a DRIVE root ('C:/', the
     * folded spelling of realpath()'s answer on a '\' host) — the ONE
     * container judgment both tree owners ride (OCR round 33,
     * t31-ocr33-5).
     *
     * The universal-container refusals were POSIX-spelling-only: on a
     * non-POSIX host realpath() answers 'C:\', never '/', so
     * rrmdir()'s raw `'/' === $dir_real` and copyTree's
     * `'/' === $source_real`/mirror comparison (the latter already
     * folded through the ONE comparison owner) all read false over
     * the DRIVE ROOT — rrmdir('C:\') passed every probe and the walk
     * deleted THE DRIVE ROOT's children (the t31-ocr10-1 shape the
     * guard exists to kill), the copy twin reading the same container
     * as a copyable source or an every-source-containing target. The
     * container class is BOTH spellings: the POSIX root and the
     * drive-letter root, with or without the trailing separator.
     * Construction-evident on this POSIX runner (DIRECTORY_SEPARATOR,
     * a constant no test sim flips — the t31-ocr28-3 doctrine); the
     * POSIX '/' refusals ride unchanged beneath the same comparison.
     *
     * The SUPER-ROOT member (OCR round 34, t31-ocr34-3): POSIX leaves
     * EXACTLY two leading slashes implementation-defined, and the
     * SysV-lineage libcs PRESERVE the spelling — realpath('//')
     * answering '//' — so on those hosts a resolved answer could
     * carry '//' past the '/' compare and copyTree('//', …) walked
     * the root as a copyable source. The spelling is DERIVED from the
     * host's own probe, never the literal (the r29-6 doctrine — the
     * same both-answers acknowledgment the HarnessCopyTreeTest
     * precondition's assertContains(['/', '//']) carries, now
     * answered by the guard itself): on an engine whose realpath
     * collapses the probe (this one: '/' for both spellings, driven)
     * the arm subsumes into the '/' compare and rides inert — and no
     * $resolved can carry '//' there anyway, every input this
     * predicate reads being a realpath output. The inspector's own
     * root fence carries the census note (t31-ocr34-1).
     *
     * @param string $resolved A realpath()-derived answer, folded through posix_comparison_vocabulary().
     * @return bool True when the path is a universal container root.
     */
    private static function resolvesToUniversalContainer(string $resolved): bool
    {
        if ('/' === $resolved || 1 === preg_match('/\A[A-Za-z]:\/?\z/', $resolved)) {
            return true;
        }
        $super_root = realpath('//');

        return false !== $super_root && $resolved === self::posix_comparison_vocabulary($super_root);
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
     * @throws RuntimeException When the spelling collapses to the filesystem root (t31-ocr10-1) — the universal tree is never a scratch dir — its realpath resolution fails (t31-ocr11-20), a removal's IO return fails (t31-ocr32-7: the walk owns its returns — a stranded shape refuses loudly, never the engine's raw warning), or a subdirectory mid-tree cannot be listed (t31-ocr33-6: the recursion boundary is fenced — the SPL iterator's vocabulary never answers a harness refusal).
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
        /*
         * The resolution folds through the ONE comparison owner
         * (t31-ocr33-5): the container judgment below and the walk
         * after it read one vocabulary — the POSIX host rides the
         * identity, byte-unchanged.
         */
        $dir_real = self::posix_comparison_vocabulary($dir_real);
        if (self::resolvesToUniversalContainer($dir_real)) {
            throw new RuntimeException('WpHarness::rrmdir() refuses a spelling that collapses to the filesystem ROOT — the universal tree is never a scratch dir: ' . $caller_spelling);
        }
        /*
         * The WALK rides the COLLAPSED spelling (OCR round 22's
         * verifier pass, rd-1 + sc-1 over t31-ocr22-6): the
         * iterator's child pathnames spell from the root it is built
         * on, and over a '/..'-bearing caller spelling every pathname
         * carried the tail ('parent/sub/../f') — CHILD_FIRST order
         * consumes the tail's own component (sub), and every item
         * after it resolved through the dead component: ENOENT, the
         * siblings stranded, the final rmdir 'not empty' (driven both
         * shapes at the round's HEAD: the single tail at the yield
         * order that meets sub first, and the DOUBLE tail
         * 'sub/../..' whose mid-walk sub removal killed every later
         * '/..'-routed pathname). Built on $dir_real no pathname
         * carries a consumable '..' at any yield order — the same
         * normalization the final rmdir below received, over the same
         * tree the probes judged (realpath resolved it while it
         * stood).
         */
        /*
         * The walk fences its RECURSION BOUNDARY (OCR round 33,
         * t31-ocr33-6 — the r30-3 class the lint gate closed, the
         * harness twin): hasChildren() passes on stat alone, so an
         * unreadable SUBDIRECTORY mid-tree (a chmod-000 child) was
         * reached by the descent — RecursiveIteratorIterator's
         * getChildren() opens it — and the walk died in the SPL
         * iterator's own UnexpectedValueException, from the
         * constructor or mid-recursion: another library's vocabulary
         * answering a harness refusal. The construction rides the try
         * (the ocr23 rd-1 doctrine); the abort converts to the
         * harness's own refusal, the SPL message riding
         * parenthetically (it is what names the path — the lint
         * sibling's shape), and the partial removal stands for the
         * caller's finally (the loud policy's own residue
         * vocabulary). The per-entry refusals below are
         * RuntimeExceptions — they pass the fence untouched.
         */
        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir_real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                /*
                 * The walk owns its IO returns (OCR round 32,
                 * t31-ocr32-7 — the ocr30-4 pattern, the copy twin's
                 * own doctrine): the per-entry unlink()/rmdir() calls
                 * once ran unchecked, so a stranded 0555/0444 shape
                 * or a removal race answered with a RAW E_WARNING —
                 * under PHPUnit (failOnWarning) an exception wearing
                 * PHPUnit's vocabulary, outside it raw bytes — never
                 * the harness's own refusal. The @ suppresses only
                 * the diagnostic; the FAILED RETURN answers the loud
                 * policy refusal naming the path, and the tree is
                 * reclaimed or refused loudly (the partial removal
                 * stands for the caller's finally).
                 */
                if ($item->isDir() && ! $item->isLink()) {
                    if (! @rmdir($item->getPathname())) {
                        throw new RuntimeException('WpHarness::rrmdir() refuses a tree whose directory cannot be removed — the walk owns its IO returns, never the engine\'s raw warning vocabulary (the ocr30-4 doctrine): ' . $item->getPathname());
                    }
                } elseif (! @unlink($item->getPathname())) {
                    throw new RuntimeException('WpHarness::rrmdir() refuses a tree whose file cannot be removed — the walk owns its IO returns, never the engine\'s raw warning vocabulary (the ocr30-4 doctrine): ' . $item->getPathname());
                }
            }
        } catch (UnexpectedValueException $walk_refusal) {
            throw new RuntimeException('WpHarness::rrmdir() refuses a tree whose subdirectory cannot be listed — the walk fences the recursion boundary, never the SPL iterator\'s vocabulary (the t31-ocr30-3 fence, the harness twin): ' . $walk_refusal->getMessage());
        }
        /*
         * The final rmdir receives the COLLAPSED spelling (OCR round
         * 22, t31-ocr22-6): the walk spelling keeps a '/..' tail
         * (same_directory_spelling() strips only the same-directory
         * tails), and by the walk's end the component that tail names
         * no longer exists — rmdir('scratch/sub/..') resolved through
         * a 'sub' the walk itself removed, failed ENOENT, and the
         * walked tree LEAKED its top directory per call (driven at
         * HEAD: children emptied, the parent stranded under the
         * warning). $dir_real is the guard family's own normalization
         * (resolved before the walk, while the tree still stood) — it
         * names the walked directory itself. Its return is OWNED the
         * walk's way (t31-ocr32-7): the tree is empty by here, so a
         * refusal is the parent's write bit or a race — named loudly,
         * never the raw E_WARNING either.
         */
        if (! @rmdir($dir_real)) {
            throw new RuntimeException('WpHarness::rrmdir() cannot remove the emptied tree root — the parent refused the removal (its write bit, or a race), and the walk owns the return: ' . $caller_spelling);
        }
    }

    /**
     * The suite's ONE STDERR writer — the STREAM resolved, never the
     * CLI-only STDERR constant (OCR round 38, t31-ocr38-4; hoisted to
     * one spelling for both its sites by OCR round 39, t31-ocr39-6):
     * STDERR is defined by the CLI SAPI only, and in any other SAPI
     * (cgi, fpm, a worker) a bare fwrite(STDERR, …) raises an
     * undefined-constant Error — from INSIDE the release guard's own
     * catch, the t31-ocr33-7 verdict-replacement defect re-opened by
     * its own diagnostic, and from any mid-test notice the suite
     * writes beside it. php://stderr answers in every SAPI; a stream
     * that cannot be opened (fd 2 closed) degrades silently to no
     * diagnostic, never a throw. The release guard below and every
     * loud skip/notice the tests write ride this one spelling.
     *
     * @param string $message The diagnostic to write (the caller spells its own trailing newline).
     * @return void
     */
    public static function stderrNotice(string $message): void
    {
        $stderr = @fopen('php://stderr', 'w');
        if (false !== $stderr) {
            fwrite($stderr, $message);
        }
    }

    /*
     * Each docblock sits directly above its OWN declaration (OCR
     * round 40, t31-ocr40-4): only the LAST docblock before a
     * declaration attaches — releaseScratch()'s contract rode
     * orphaned above stderrNotice()'s own docblock, so the method
     * shipped with no API documentation while its whole census was
     * dead text.
     */
    /**
     * The guarded scratch release (OCR round 34, t31-ocr34-4 — the
     * t31-ocr33-7 doctrine, swept to the whole census): rrmdir()'s
     * contract is the LOUD throw, and PHP REPLACES — never chains — an
     * in-flight exception when finally throws, so a BARE cleanup call
     * lets an environmental teardown failure (NFS/quota/antivirus
     * lock, stranded permission bits) supersede the caller's REAL
     * verdict at the finally line, the assertion's message discarded
     * exactly where diagnosis matters most — an uncaught exception
     * wearing the caller's frame. ONE owner now serves every RELEASE
     * call site: the guard surfaces the environmental failure on
     * STDERR (loud, visible in the run's output, never silent) and
     * lets the verdict ride untouched; rrmdir() keeps its loud
     * contract untouched, and the calls whose SUBJECT is the throw —
     * the refusalOf closures and the link/walk legs under test —
     * still invoke it bare by design.
     *
     * The census (every release caller rides this owner):
     * HarnessCopyTreeTest (its former private twin, deleted in the
     * same sweep), BuildArtifactsTest, BuildSeamPropertyTest,
     * SecureFixturesTest, SharedOAuthContractsHttpTest,
     * SharedOAuthArchitectureTest, ToolchainSmokeTest,
     * UnusedImportScannerTest, SelfContainmentCompoundWritesTest —
     * startup reclaims, mid-phase subtree removals, and finally
     * teardowns alike, the whole release class one owner.
     *
     * @param string ...$trees Absolute scratch trees to release, in order.
     * @return void
     */
    public static function releaseScratch(string ...$trees): void
    {
        foreach ($trees as $tree) {
            try {
                self::rrmdir($tree);
            } catch (\Throwable $environmental) {
                self::stderrNotice('scratch release failed for ' . $tree . ': ' . $environmental->getMessage() . "\n");
            }
        }
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
     * @throws RuntimeException When the source (or any entry in it) is a symlink, the source is missing, not a directory, unlistable (t31-ocr32-9 — the gate probes the readability the iterator itself needs), or collapsed to the filesystem root (t31-ocr12-3), the target is the source itself, inside it, or contains it, or a relative target's working directory cannot be resolved (t31-ocr11-5), or a subdirectory of the source cannot be listed mid-walk (t31-ocr34-2 — the recursion boundary is fenced, the rrmdir twin's own vocabulary, never the SPL iterator's).
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
        /*
         * The gate probes READABILITY, not just kind (OCR round 32,
         * t31-ocr32-9): an existing but UNLISTABLE source directory
         * (mode 0000, or a read bit without the search bit) once
         * passed is_dir() and died in the SPL constructor's
         * UnexpectedValueException — another library's vocabulary
         * answering a harness refusal, the ocr7-4/ocr10-9 doctrine's
         * own class one permission shape over. opendir() is the exact
         * capability the iterator's own construction needs (the probe
         * this file's batteries already use for the same shape); the
         * @ keeps the engine's diagnostic out of the channel (the
         * ocr30-4 idiom), and the failed probe answers the harness's
         * own refusal naming the path.
         */
        $source_probe = @opendir($from);
        if (false !== $source_probe) {
            closedir($source_probe);
        }
        if (! is_dir($from) || false === $source_probe) {
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
         * The containment verdicts' one vocabulary (t31-ocr29-4): the
         * source's realpath answer joins the comparisons in the POSIX
         * spelling — a backslash-joined answer on a separator host
         * could never match a '/'-joined needle, and containment
         * judged vocabulary noise. The POSIX host rides the identity.
         */
        $source_real = self::posix_comparison_vocabulary($source_real);
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
        /*
         * The container judgment rides the ONE owner — '/' and the
         * drive-root spelling alike (t31-ocr33-5): a '\' host's
         * realpath answers 'C:/', never '/', and the universal
         * container must refuse by CLASS, not by one spelling of it.
         */
        if (self::resolvesToUniversalContainer($source_real)) {
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
            /*
             * The platform gate at the owner (OCR round 28, t31-ocr28-3):
             * the cwd-prepend arm below premises POSIX spelling — "a
             * target not starting with '/' is relative" — and on a
             * non-POSIX host that premise is FALSE for the host's own
             * absolute spellings: a drive-letter target ('C:\Temp\dst')
             * or a UNC root ('\\server\share\dst') is ABSOLUTE on the
             * host it names, and cwd-prepending it sent the resolution
             * loop into the collapse with a spelling no host resolves —
             * the containment verdicts judged garbage (realpath()'s
             * backslash output joined by '/' separators). The gate is
             * the harness's ONE platform owner (isPosixHost(), the
             * t31-ocr23-6 hoist) consulted at the arm that carries the
             * premise, and the refusal names the platform premise —
             * never a cwd-prepend over an absolute spelling. On the
             * POSIX host the arm stays exact: there the spelling IS
             * relative (a legal, if odd, directory name), and the
             * prepend is the correct judgment.
             */
            if (! self::isPosixHost() && (1 === preg_match('/\A[A-Za-z]:/', $to_walk) || 0 === strpos($to_walk, '\\\\'))) {
                throw new RuntimeException('WpHarness::copyTree() refuses a Windows-absolute target on a non-POSIX host — the containment walk premises POSIX spelling and would cwd-prepend an absolute drive/UNC spelling into a path no host resolves: ' . $to);
            }
            $cwd = getcwd();
            if (false === $cwd) {
                throw new RuntimeException('WpHarness::copyTree() refuses a relative target while the working directory cannot be resolved — the containment walk has no base to judge against: ' . $to);
            }
            /*
             * The RELATIVE twin of the platform gate (OCR round 36,
             * t31-ocr36-4): the gate above refuses only the ABSOLUTE
             * drive/UNC spellings, so a backslash-spelled RELATIVE
             * target — the platform's own natural spelling
             * ('sub\dst') — passed into the cwd-prepend and mingled
             * vocabularies ('C:\repo/a\src\dst'), a mixed-separator
             * spelling the resolution loop's dirname/explode/'/'
             * judgments below read as one giant segment. The walk
             * must never see mixed-separator paths (the containment
             * doctrine): both the relative spelling and the cwd (a
             * host-native getcwd() answer) fold through the ONE
             * comparison-vocabulary owner before the prepend, and the
             * COPY itself keeps the caller's spelling — the landing
             * is unchanged, only the judgment reads the one
             * vocabulary (the t31-ocr11-5 doctrine). On the POSIX
             * host the fold is the IDENTITY and the prepend rides
             * byte-unchanged.
             */
            if (! self::isPosixHost()) {
                $to_walk = self::posix_comparison_vocabulary($to_walk);
                $cwd = self::posix_comparison_vocabulary($cwd);
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
                // The verdicts' one vocabulary rides the anchor's
                // answer too (t31-ocr29-4) — the collapse below joins
                // the remainder with '/', and a backslash-joined
                // anchor would fold into one giant segment.
                $ancestor_real = self::posix_comparison_vocabulary($ancestor_real);
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
                /*
                 * The collapse speaks the ANCHOR's own shape (OCR
                 * round 38, t31-ocr38-3): the unconditional '/'
                 * prepend composed only on POSIX, where every
                 * realpath() answer carries a leading separator — on
                 * a separator host the folded anchor is a realpath()
                 * output with NONE ('C:/repo'), and the prepend
                 * folded the resolution into '/C:/repo/sub/dst', a
                 * spelling no host resolves, the loop's equality and
                 * containment verdicts then answering over vocabulary
                 * noise. The prefix derives from the anchor: '/' where
                 * the anchor carries one, '' where it does not (the
                 * drive anchor joins through its segments alone).
                 * POSIX byte-unchanged (the anchor always '/' there).
                 */
                $resolved = ('/' === $ancestor_real[0] ? '/' : '') . implode('/', $collapsed);
            } else {
                $resolved = rtrim($to_walk, '/');
            }
            if ($resolved === $to_walk || '/' === $resolved) {
                $target_real = $resolved;
                break;
            }
            $to_walk = $resolved;
        }
        /*
         * The containment verdicts speak the CASE vocabulary the host
         * speaks (OCR round 35, t31-ocr35-5): both sides fold through
         * the probe-derived arm (the case_insensitive_containment_fold
         * owner) before comparing — the IDENTITY, byte-unchanged, on a
         * case-sensitive host (this runner: Linux, where a case-variant
         * spelling names a DIFFERENT tree and the copy proceeds), and
         * the ASCII fold on a case-insensitive one (macOS resolves the
         * variant to the SAME tree — the self-copy/mirror shapes the
         * guard exists to kill, red only there; the engine-premise
         * shape the r34-3 note records, pinned green-both-sides by the
         * copy battery's case-variant leg).
         */
        $target_compare = self::case_insensitive_containment_fold($target_real);
        $source_compare = self::case_insensitive_containment_fold($source_real);
        if ($target_compare === $source_compare || 0 === strpos($target_compare, $source_compare . '/')) {
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
        if (self::resolvesToUniversalContainer($target_real) || 0 === strpos($source_compare, $target_compare . '/')) {
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
        /*
         * The walk fences its RECURSION BOUNDARY (OCR round 34,
         * t31-ocr34-2 — the t31-ocr33-6 fence rrmdir()'s walk gained,
         * the copy twin this round's sweep closed): hasChildren()
         * passes on stat alone, so an unreadable SUBDIRECTORY
         * mid-tree (a chmod-000 child) was reached by the descent —
         * RecursiveIteratorIterator's getChildren() opens it — and
         * the walk died in the SPL iterator's own
         * UnexpectedValueException, from the constructor or
         * mid-recursion: another library's vocabulary answering a
         * harness refusal, while the ocr32-9 opendir gate probes only
         * the SOURCE ROOT's readability. The construction rides the
         * try (the ocr23 rd-1 doctrine); the abort converts to the
         * harness's own refusal, the SPL message riding
         * parenthetically (it is what names the path), and every
         * landing already made stands for the caller's finally. The
         * per-entry refusals inside are RuntimeExceptions — they pass
         * the fence untouched.
         */
        try {
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
                 *
                 * The prefix speaks the ITERATOR'S OWN JOIN (OCR round
                 * 39, t31-ocr39-4 — the ocr28-8/ocr30-7 doctrine this
                 * file's own gates state, missed at the production
                 * seam): RecursiveDirectoryIterator joins child
                 * pathnames through the NATIVE separator, and the
                 * prefix math was '/'-joined — on a host whose platform
                 * separator is not the POSIX one, $from.'/' never
                 * prefixes any pathname and the FIRST leaf trips the
                 * cannot-relativize refusal below: the copy owner dead
                 * on arrival on the very host class the platform
                 * vocabulary exists to serve. The prefix derives from
                 * DIRECTORY_SEPARATOR now; on the POSIX host it IS '/'
                 * and every byte rides unchanged.
                 */
                $relative = $file->getPathname();
                $prefix = $from . DIRECTORY_SEPARATOR;
                if (0 !== strpos($relative, $prefix)) {
                    throw new RuntimeException('WpHarness::copyTree() cannot relativize ' . $relative . ' against the source prefix ' . $prefix . ' — every file would silently land nested under the target (a trailing-slash source is the reachable spelling).');
                }
                $relative = substr($relative, strlen($prefix));
                $target = $to . '/' . $relative;
                /*
                 * The landing loop owns its IO returns (OCR round 30,
                 * t31-ocr30-4): mkdir()/copy() failures once escaped the
                 * contract two ways — under PHPUnit (failOnWarning +
                 * convertWarningsToExceptions) the raw E_WARNING became an
                 * exception wearing PHPUnit's vocabulary, and outside it
                 * the raw warning rode while copyTree() RETURNED NORMALLY
                 * having moved nothing — a mid-landing IO failure (EACCES,
                 * ENOSPC, path-length) is never either verdict. The @
                 * suppresses only the diagnostic (the builder's
                 * copyNormalized shape); the FAILED RETURN is owned here,
                 * answering the harness's own refusal vocabulary naming
                 * the operation and the path.
                 */
                if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true)) {
                    throw new RuntimeException('WpHarness::copyTree() refuses a landing whose directory cannot be created — the mkdir failed at the path it owns: ' . dirname($target));
                }
                if (! @copy($file->getPathname(), $target)) {
                    throw new RuntimeException('WpHarness::copyTree() refuses a landing whose file cannot be copied — the copy failed mid-landing, and a partial tree never reads as a normal return: ' . $file->getPathname() . ' into ' . $target);
                }
            }
        } catch (UnexpectedValueException $walk_refusal) {
            throw new RuntimeException('WpHarness::copyTree() refuses a source whose subdirectory cannot be listed — the walk fences the recursion boundary, never the SPL iterator\'s vocabulary (the t31-ocr33-6 fence, the copy twin): ' . $walk_refusal->getMessage());
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
     * @throws \Exception When the attempt does not throw ($expectation), or throws outside the pinned family — AssertionFailedError where PHPUnit is loaded (t31-ocr25-9's verdict owner), the base \Exception in a bare child engine.
     */
    public static function refusalOf(callable $attempt, string $expectation, string $family): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $e) {
            if (! $e instanceof $family) {
                throw self::verdict(
                    'The refusal class is outside the family this site pins (expected ' . $family . ', got ' . get_class($e) . ') — the original catch declared ' . $family . ', and a stray Error carrying the fragments would otherwise pass silently (t31-ocr9-3).',
                    $e
                );
            }

            return $e;
        }

        throw self::verdict($expectation);
    }

    /**
     * One assertion verdict, resolving without PHPUnit (OCR round 25,
     * t31-ocr25-9): refusalOf() hard-referenced
     * \PHPUnit\Framework\AssertionFailedError at throw time while this
     * file is consumed by PHPUnit-less child engines — the
     * redirected-TMPDIR sims require WpHarness.php into a bare
     * `php -r` — lazily safe only for as long as no child leg ever
     * called a throwing path; the first one would answer the
     * class-not-found \Error INSTEAD of the verdict (driven by the
     * round's verifier: a child catching \Throwable echoes the Error
     * as if it were the verdict at exit 0 — a silent mis-answer, worse
     * than a fatal, which only a narrower catch produces). Where
     * PHPUnit is loaded the verdict stays exactly the assertion
     * failure its
     * channel expects; a bare engine gets the base \Exception carrying
     * the same message — deliberately NOT RuntimeException, the family
     * the guarded calls themselves throw, which a child's own catch
     * would conflate with a genuine refusal. The previous exception
     * rides the chain either way (the t31-ocr19-4 contract).
     *
     * @param string      $message  The verdict's message.
     * @param \Throwable|null $previous The original exception, when the verdict is a family mismatch.
     * @return \Exception The verdict to throw.
     */
    private static function verdict(string $message, ?\Throwable $previous = null): \Exception
    {
        if (class_exists(\PHPUnit\Framework\AssertionFailedError::class)) {
            return new \PHPUnit\Framework\AssertionFailedError($message, 0, $previous);
        }

        return new \Exception($message, 0, $previous);
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
