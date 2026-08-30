<?php
/**
 * WordPress API stubs for the wp-connectors test harness.
 *
 * Emulates the subset of the WordPress 7.0 plugin API that connector code
 * uses: hooks (with priorities), options/transients (with autoload flags),
 * cron, users/capabilities, nonces, escaping/sanitization, i18n, and the
 * wp_remote_* HTTP API.
 *
 * Two deliberate safety properties:
 *
 * 1. Outbound wp_remote_* requests FAIL unless a test installs a mock via the
 *    `pre_http_request` filter (mirroring core's real short-circuit hook).
 *    The suite can therefore never contact a live provider by accident.
 * 2. Everything reads the deterministic clock in WpHarness when frozen.
 *
 * This file mirrors core function signatures, so it intentionally does not
 * follow every repo coding-standard rule; it is excluded from PHPCS style
 * checks (still covered by `composer lint` and PHPCompatibility).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

require_once __DIR__ . '/WpHarness.php';

/*
 * -------------------------------------------------------------------------
 * Hooks (filters/actions).
 * -------------------------------------------------------------------------
 */

function wp_connectors_harness_add($tag, $callback, $priority, $accepted_args)
{
    $key = WpHarness::callbackKey($callback);
    if (! isset(WpHarness::$filters[ $tag ])) {
        WpHarness::$filters[ $tag ] = array();
    }
    if (! isset(WpHarness::$filters[ $tag ][ $priority ])) {
        WpHarness::$filters[ $tag ][ $priority ] = array();
    }
    foreach (WpHarness::$filters[ $tag ][ $priority ] as $existing) {
        if ($existing['key'] === $key) {
            return true; // Identical registration is idempotent.
        }
    }
    WpHarness::$filters[ $tag ][ $priority ][] = array(
        'callback' => $callback,
        'accepted_args' => max(0, (int) $accepted_args),
        'key' => $key,
    );
    ksort(WpHarness::$filters[ $tag ], SORT_NUMERIC);

    return true;
}

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
{
    return wp_connectors_harness_add($tag, $callback, $priority, $accepted_args);
}

function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
{
    return wp_connectors_harness_add($tag, $callback, $priority, $accepted_args);
}

function remove_filter($tag, $callback, $priority = 10)
{
    $key = WpHarness::callbackKey($callback);
    if (! isset(WpHarness::$filters[ $tag ][ $priority ])) {
        return false;
    }
    foreach (WpHarness::$filters[ $tag ][ $priority ] as $index => $existing) {
        if ($existing['key'] === $key) {
            unset(WpHarness::$filters[ $tag ][ $priority ][ $index ]);
            WpHarness::$filters[ $tag ][ $priority ] = array_values(WpHarness::$filters[ $tag ][ $priority ]);
            if (WpHarness::$filters[ $tag ][ $priority ] === array()) {
                unset(WpHarness::$filters[ $tag ][ $priority ]);
            }

            return true;
        }
    }

    return false;
}

function remove_action($tag, $callback, $priority = 10)
{
    return remove_filter($tag, $callback, $priority);
}

function remove_all_filters($tag, $priority = false)
{
    if (false === $priority) {
        unset(WpHarness::$filters[ $tag ]);

        return true;
    }
    unset(WpHarness::$filters[ $tag ][ (int) $priority ]);

    return true;
}

function remove_all_actions($tag, $priority = false)
{
    return remove_all_filters($tag, $priority);
}

function has_filter($tag, $callback = false)
{
    if (! isset(WpHarness::$filters[ $tag ])) {
        return false;
    }
    if (false === $callback) {
        return count(WpHarness::$filters[ $tag ]) > 0;
    }
    $key = WpHarness::callbackKey($callback);
    foreach (WpHarness::$filters[ $tag ] as $priority => $callbacks) {
        foreach ($callbacks as $existing) {
            if ($existing['key'] === $key) {
                return (int) $priority; // Core returns the priority.
            }
        }
    }

    return false;
}

function has_action($tag, $callback = false)
{
    return has_filter($tag, $callback);
}

function apply_filters($tag, $value, ...$args)
{
    if (! isset(WpHarness::$filters[ $tag ])) {
        return $value;
    }

    WpHarness::$current_action_stack[] = $tag;
    try {
        foreach (WpHarness::$filters[ $tag ] as $callbacks) {
            foreach ($callbacks as $entry) {
                $call_args = array_merge(array( $value ), $args);
                $call_args = array_slice($call_args, 0, max(1, $entry['accepted_args']));
                $value = call_user_func_array($entry['callback'], $call_args);
            }
        }
    } finally {
        array_pop(WpHarness::$current_action_stack);
    }

    return $value;
}

function do_action($tag, ...$args)
{
    if (! isset(WpHarness::$did_actions[ $tag ])) {
        WpHarness::$did_actions[ $tag ] = 0;
    }
    ++WpHarness::$did_actions[ $tag ];
    WpHarness::$current_action_stack[] = $tag;

    try {
        if (isset(WpHarness::$filters[ $tag ])) {
            foreach (WpHarness::$filters[ $tag ] as $callbacks) {
                foreach ($callbacks as $entry) {
                    $call_args = array_slice($args, 0, $entry['accepted_args']);
                    call_user_func_array($entry['callback'], $call_args);
                }
            }
        }
    } finally {
        array_pop(WpHarness::$current_action_stack);
    }
}

function current_filter()
{
    $count = count(WpHarness::$current_action_stack);

    return $count === 0 ? false : WpHarness::$current_action_stack[ $count - 1 ];
}

function doing_action($tag = null)
{
    // Core semantics: membership anywhere in the stack, not just the top.
    if (WpHarness::$current_action_stack === array()) {
        return false;
    }

    return null === $tag ? true : in_array($tag, WpHarness::$current_action_stack, true);
}

function doing_filter($tag = null)
{
    return doing_action($tag);
}

function did_action($tag)
{
    return isset(WpHarness::$did_actions[ $tag ]) ? WpHarness::$did_actions[ $tag ] : 0;
}

/*
 * -------------------------------------------------------------------------
 * Options, site options, transients.
 * -------------------------------------------------------------------------
 */

function get_option($option, $default = false)
{
    if (array_key_exists($option, WpHarness::$options)) {
        return WpHarness::$options[ $option ];
    }

    return $default;
}

function update_option($option, $value, $autoload = null)
{
    $old = array_key_exists($option, WpHarness::$options) ? WpHarness::$options[ $option ] : false;

    // Core semantics: no update (and no hooks) when the value is unchanged.
    if ($old === $value && null === $autoload) {
        return false;
    }

    WpHarness::$options[ $option ] = $value;
    if (null !== $autoload) {
        WpHarness::$option_autoload[ $option ] = (bool) $autoload;
    } elseif (! array_key_exists($option, WpHarness::$option_autoload)) {
        WpHarness::$option_autoload[ $option ] = true;
    }

    if ($old !== $value) {
        do_action("update_option_{$option}", $old, $value);
        do_action('updated_option', $option, $old, $value);
        do_action('update_option', $option, $old, $value);
    }

    return true;
}

function add_option($option, $value = '', $deprecated = '', $autoload = null)
{
    if (array_key_exists($option, WpHarness::$options)) {
        return false;
    }
    WpHarness::$options[ $option ] = $value;
    WpHarness::$option_autoload[ $option ] = null === $autoload ? true : (bool) $autoload;

    return true;
}

function delete_option($option)
{
    if (! array_key_exists($option, WpHarness::$options)) {
        return false;
    }
    unset(WpHarness::$options[ $option ], WpHarness::$option_autoload[ $option ]);

    return true;
}

function get_site_option($option, $default = false)
{
    return get_option($option, $default);
}

function update_site_option($option, $value)
{
    return update_option($option, $value);
}

function delete_site_option($option)
{
    return delete_option($option);
}

function get_transient($transient)
{
    if (! array_key_exists($transient, WpHarness::$transients)) {
        return false;
    }
    $entry = WpHarness::$transients[ $transient ];
    if (false !== $entry['expires_at'] && $entry['expires_at'] < WpHarness::now()) {
        unset(WpHarness::$transients[ $transient ]);

        return false;
    }

    return $entry['value'];
}

function set_transient($transient, $value, $expiration = 0)
{
    if ($expiration > 0) {
        $expires_at = WpHarness::now() + $expiration;
    } elseif ($expiration < 0) {
        // Core treats a negative TTL as already expired.
        $expires_at = WpHarness::now() - 1;
    } else {
        $expires_at = false;
    }
    WpHarness::$transients[ $transient ] = array(
        'value' => $value,
        'expires_at' => $expires_at,
    );

    return true;
}

function delete_transient($transient)
{
    unset(WpHarness::$transients[ $transient ]);

    return true;
}

/*
 * -------------------------------------------------------------------------
 * Cron / scheduled events.
 * -------------------------------------------------------------------------
 */

function wp_schedule_single_event($timestamp, $hook, $args = array())
{
    foreach (WpHarness::$cron[ $hook ] ?? array() as $event) {
        if ($event['timestamp'] === (int) $timestamp && $event['args'] === $args && ! isset($event['interval'])) {
            return true; // Duplicate single event, matching core behavior.
        }
    }
    WpHarness::$cron[ $hook ][] = array(
        'timestamp' => (int) $timestamp,
        'args' => $args,
        'id' => $hook . '-' . count(WpHarness::$cron[ $hook ] ?? array()) . '-' . wp_connectors_harness_uid(),
    );

    return true;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array())
{
    $intervals = wp_get_schedules();
    $interval = isset($intervals[ $recurrence ]) ? (int) $intervals[ $recurrence ]['interval'] : 0;
    WpHarness::$cron[ $hook ][] = array(
        'timestamp' => (int) $timestamp,
        'args' => $args,
        'interval' => $interval,
        'id' => $hook . '-' . count(WpHarness::$cron[ $hook ] ?? array()) . '-' . wp_connectors_harness_uid(),
    );

    return true;
}

function wp_next_scheduled($hook, $args = array())
{
    $best = false;
    foreach (WpHarness::$cron[ $hook ] ?? array() as $event) {
        if ($event['args'] !== $args) {
            continue;
        }
        if (false === $best || $event['timestamp'] < $best) {
            $best = $event['timestamp'];
        }
    }

    return $best;
}

function wp_get_scheduled_events($hook = null)
{
    if (null === $hook) {
        $all = array();
        foreach (WpHarness::$cron as $events) {
            foreach ($events as $event) {
                $all[] = $event;
            }
        }

        return $all;
    }

    return WpHarness::$cron[ $hook ] ?? array();
}

function wp_unschedule_event($timestamp, $hook, $args = array())
{
    foreach (WpHarness::$cron[ $hook ] ?? array() as $index => $event) {
        if ($event['timestamp'] === (int) $timestamp && $event['args'] === $args) {
            unset(WpHarness::$cron[ $hook ][ $index ]);
            WpHarness::$cron[ $hook ] = array_values(WpHarness::$cron[ $hook ]);

            return true;
        }
    }

    return false;
}

function wp_clear_scheduled_hook($hook)
{
    $count = 0;
    foreach (array_keys(WpHarness::$cron) as $candidate) {
        if ($candidate === $hook) {
            $count += count(WpHarness::$cron[ $hook ]);
            unset(WpHarness::$cron[ $hook ]);
        }
    }

    return $count;
}

function wp_get_schedules()
{
    return array(
        'hourly' => array( 'interval' => HOUR_IN_SECONDS ),
        'twicedaily' => array( 'interval' => 12 * HOUR_IN_SECONDS ),
        'daily' => array( 'interval' => DAY_IN_SECONDS ),
    );
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

function wp_connectors_harness_uid()
{
    static $counter = 0;

    return 'e' . (++$counter);
}

/*
 * -------------------------------------------------------------------------
 * Users and capabilities.
 * -------------------------------------------------------------------------
 */

class WP_User
{
    public $ID = 0;
    public $user_login = '';
    public $display_name = '';
    public $caps = array();

    public function __construct($id = 0, $login = '', array $caps = array())
    {
        $this->ID = (int) $id;
        $this->user_login = $login;
        $this->display_name = $login;
        // Accept both a cap list ('cap_a') and a cap map ('cap_a' => true).
        if (isset($caps[0])) {
            $caps = array_fill_keys($caps, true);
        }
        $this->caps = $caps;
    }

    public function has_cap($capability)
    {
        return ! empty($this->caps[ $capability ]);
    }

    public function add_cap($capability)
    {
        $this->caps[ $capability ] = true;
    }
}

/**
 * Sets the current user. Harness extension: the third argument grants
 * capabilities directly (core signature has two parameters).
 *
 * @param int    $id   User ID (0 = logged out).
 * @param string $name User login name.
 * @param array  $caps Capabilities to grant.
 * @return WP_User
 */
function wp_set_current_user($id, $name = '', array $caps = array( 'manage_options' ))
{
    WpHarness::$current_user = new WP_User($id, $name, $id === 0 ? array() : $caps);

    return WpHarness::$current_user;
}

function wp_get_current_user()
{
    if (null === WpHarness::$current_user) {
        WpHarness::$current_user = new WP_User(0);
    }

    return WpHarness::$current_user;
}

function get_current_user_id()
{
    return wp_get_current_user()->ID;
}

function current_user_can($capability, ...$args)
{
    return wp_get_current_user()->has_cap($capability);
}

/*
 * -------------------------------------------------------------------------
 * Multisite (site iteration and per-site option scope).
 * -------------------------------------------------------------------------
 */

function is_multisite()
{
    return WpHarness::$is_multisite;
}

function get_current_blog_id()
{
    return WpHarness::$current_blog_id;
}

function is_main_site($site_id = null)
{
    return (null === $site_id ? WpHarness::$current_blog_id : (int) $site_id) === 1;
}

/**
 * Switches the emulated blog context, parking the current blog's
 * options/transients so each blog has its own tables (core semantics:
 * get_option()/delete_option() are site-scoped).
 *
 * @param int  $new_blog   Blog ID to switch to.
 * @param mixed $deprecated Unused (core signature compatibility).
 * @return true
 */
function switch_to_blog($new_blog, $deprecated = null)
{
    unset($deprecated);
    $new_blog = (int) $new_blog;

    WpHarness::$blog_options[ WpHarness::$current_blog_id ] = WpHarness::$options;
    WpHarness::$blog_transients[ WpHarness::$current_blog_id ] = WpHarness::$transients;
    WpHarness::$blog_stack[] = WpHarness::$current_blog_id;

    WpHarness::$current_blog_id = $new_blog;
    WpHarness::$options = WpHarness::$blog_options[ $new_blog ] ?? array();
    WpHarness::$transients = WpHarness::$blog_transients[ $new_blog ] ?? array();
    unset(WpHarness::$blog_options[ $new_blog ], WpHarness::$blog_transients[ $new_blog ]);

    return true;
}

/**
 * Restores the blog context switched away from by switch_to_blog().
 *
 * @return bool True on success, false when the switch stack is empty.
 */
function restore_current_blog()
{
    $previous = array_pop(WpHarness::$blog_stack);
    if (null === $previous) {
        return false;
    }

    WpHarness::$blog_options[ WpHarness::$current_blog_id ] = WpHarness::$options;
    WpHarness::$blog_transients[ WpHarness::$current_blog_id ] = WpHarness::$transients;

    WpHarness::$current_blog_id = $previous;
    WpHarness::$options = WpHarness::$blog_options[ $previous ] ?? array();
    WpHarness::$transients = WpHarness::$blog_transients[ $previous ] ?? array();
    unset(WpHarness::$blog_options[ $previous ], WpHarness::$blog_transients[ $previous ]);

    return true;
}

/**
 * Emulates get_sites(): blog IDs (blog 1 plus WpHarness::$sites), paged.
 *
 * Supports the arguments the plugin code uses: 'fields' => 'ids',
 * 'number', 'paged' (and legacy 'offset'). Without 'fields' => 'ids',
 * returns stdClass-like site objects with id/blog_id.
 *
 * @param array|string $args Query arguments.
 * @return list<int>|list<object>
 */
function get_sites($args = array())
{
    $args = wp_parse_args($args);

    $ids = array_values(array_unique(array_merge(array(1), WpHarness::$sites)));
    sort($ids, SORT_NUMERIC);

    $number = isset($args['number']) ? (int) $args['number'] : 100;
    $offset = 0;
    if (isset($args['offset'])) {
        $offset = (int) $args['offset'];
    } elseif (isset($args['paged'])) {
        $offset = (max(1, (int) $args['paged']) - 1) * $number;
    }
    if ($number > 0) {
        $ids = array_slice($ids, $offset, $number);
    }

    if (isset($args['fields']) && 'ids' === $args['fields']) {
        return $ids;
    }

    return array_map(
        static function ($id) {
            return (object) array('id' => (int) $id, 'blog_id' => (int) $id, 'network_id' => 1, 'public' => 1);
        },
        $ids
    );
}

/*
 * -------------------------------------------------------------------------
 * Nonces.
 * -------------------------------------------------------------------------
 */

function wp_create_nonce($action = -1)
{
    $user = wp_get_current_user();

    return substr(hash_hmac('sha256', $action . '|' . $user->ID . '|harness-session', WpHarness::$nonce_salt), 0, 12);
}

function wp_verify_nonce($nonce, $action = -1)
{
    return is_string($nonce) && hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function check_admin_referer($action = -1, $query_arg = '_wpnonce')
{
    $nonce = isset($_REQUEST[ $query_arg ]) ? (string) wp_unslash($_REQUEST[ $query_arg ]) : '';
    if ($nonce !== '' && wp_verify_nonce($nonce, $action)) {
        return true;
    }
    WpHarness::$doing_it_wrong[] = array(
        'function' => 'check_admin_referer',
        'message' => 'Nonce verification failed in the test harness.',
        'version' => '0.0.0',
    );

    return false;
}

function check_ajax_referer($action = -1, $query_arg = false, $die = true)
{
    if (false === $query_arg) {
        $query_arg = '_ajax_nonce';
    }

    return check_admin_referer($action, $query_arg);
}

function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
{
    $nonce = wp_create_nonce($action);
    $html = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($nonce) . '" />';
    if ($referer) {
        $html .= '<input type="hidden" name="_wp_http_referer" value="mock" />';
    }
    if ($echo) {
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    return $html;
}

function wp_nonce_url($url, $action = -1, $name = '_wpnonce')
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . $name . '=' . wp_create_nonce($action);
}

/*
 * -------------------------------------------------------------------------
 * Escaping and sanitization.
 * -------------------------------------------------------------------------
 */

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', true);
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', true);
}

function esc_url($url)
{
    $url = (string) $url;
    if (preg_match('/^https?:\/\//i', $url) !== 1) {
        return '';
    }

    return filter_var($url, FILTER_SANITIZE_URL);
}

function esc_url_raw($url)
{
    return esc_url($url);
}

function esc_textarea($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', true);
}

/*
 * -------------------------------------------------------------------------
 * Form helpers (selected/checked disabled-state echoes).
 * -------------------------------------------------------------------------
 */

function wp_connectors_harness_selected($helper, $current = true, $echo = true, $attribute = 'selected')
{
    $result = ((string) $helper === (string) $current) ? " $attribute='$attribute'" : '';
    if ($echo) {
        echo $result; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    return $result;
}

function selected($helper, $current = true, $echo = true)
{
    return wp_connectors_harness_selected($helper, $current, $echo, 'selected');
}

function checked($helper, $current = true, $echo = true)
{
    return wp_connectors_harness_selected($helper, $current, $echo, 'checked');
}

function disabled($helper, $current = true, $echo = true)
{
    return wp_connectors_harness_selected($helper, $current, $echo, 'disabled');
}

function sanitize_text_field($str)
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
}

function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function sanitize_email($email)
{
    return filter_var((string) $email, FILTER_SANITIZE_EMAIL);
}

function absint($maybeint)
{
    return abs((int) $maybeint);
}

function wp_json_encode($data, $options = 0)
{
    $json = json_encode($data, $options);
    if (false === $json) {
        return 'null';
    }

    return $json;
}

function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return stripslashes((string) $value);
}

function wp_slash($value)
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return addslashes((string) $value);
}

function wp_parse_args($args, $defaults = array())
{
    if (is_object($args)) {
        $args = get_object_vars($args);
    }
    if (! is_array($args)) {
        $args = array();
    }

    return array_merge($defaults, $args);
}

function trailingslashit($value)
{
    return untrailingslashit($value) . '/';
}

function untrailingslashit($value)
{
    return rtrim((string) $value, '/');
}

function wp_parse_url($url, $component = -1)
{
    return parse_url((string) $url, $component);
}

function add_query_arg(...$args)
{
    if (1 === count($args) && is_array($args[0])) {
        $query = $args[0];
        $url = '';
    } elseif (2 === count($args) && ! is_array($args[0])) {
        // Core resolves the two-scalar form against the current request URI.
        $query = array( (string) $args[0] => $args[1] );
        $url = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    } elseif (2 === count($args)) {
        $query = $args[0];
        $url = $args[1];
    } else {
        $query = array( (string) $args[0] => $args[1] );
        $url = $args[2];
    }

    $parts = explode('?', $url, 2);
    $path = $parts[0];
    $params = array();
    if (isset($parts[1])) {
        parse_str($parts[1], $params);
    }
    foreach ($query as $key => $value) {
        $params[ (string) $key ] = $value; // Replace, do not duplicate.
    }

    return $path . ($params === array() ? '' : '?' . http_build_query($params));
}

/*
 * -------------------------------------------------------------------------
 * i18n.
 * -------------------------------------------------------------------------
 */

function __($text, $domain = 'default')
{
    return (string) $text;
}

function _x($text, $context, $domain = 'default')
{
    return (string) $text;
}

function _e($text, $domain = 'default')
{
    echo (string) $text; // phpcs:ignore WordPress.Security.EscapeOutput
}

function esc_html__($text, $domain = 'default')
{
    return esc_html(__($text, $domain));
}

function esc_attr__($text, $domain = 'default')
{
    return esc_attr(__($text, $domain));
}

function esc_html_e($text, $domain = 'default')
{
    echo esc_html(__($text, $domain)); // phpcs:ignore WordPress.Security.EscapeOutput
}

function esc_attr_e($text, $domain = 'default')
{
    echo esc_attr(__($text, $domain)); // phpcs:ignore WordPress.Security.EscapeOutput
}

function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false)
{
    return true;
}

/*
 * -------------------------------------------------------------------------
 * HTTP (wp_remote_*) — blocked unless mocked via pre_http_request.
 * -------------------------------------------------------------------------
 */

function wp_remote_request($url, $args = array())
{
    $method = isset($args['method']) ? $args['method'] : 'POST';
    $pre = apply_filters('pre_http_request', false, (array) $args, (string) $url);
    WpHarness::recordHttpAttempt($method, (string) $url, (array) $args, false !== $pre);
    if (false !== $pre) {
        return $pre;
    }

    return new WP_Error(
        'http_request_blocked',
        'Outbound HTTP is blocked by the wp-connectors test harness. Install a mock via the pre_http_request filter.'
    );
}

function wp_remote_get($url, $args = array())
{
    $args['method'] = 'GET';

    return wp_remote_request($url, $args);
}

function wp_remote_post($url, $args = array())
{
    $args['method'] = 'POST';

    return wp_remote_request($url, $args);
}

function wp_remote_head($url, $args = array())
{
    $args['method'] = 'HEAD';

    return wp_remote_request($url, $args);
}

function wp_safe_remote_request($url, $args = array())
{
    return wp_remote_request($url, $args);
}

function wp_safe_remote_get($url, $args = array())
{
    return wp_remote_get($url, $args);
}

function wp_safe_remote_post($url, $args = array())
{
    return wp_remote_post($url, $args);
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) && isset($response['body']) ? $response['body'] : '';
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_response_message($response)
{
    return is_array($response) && isset($response['response']['message']) ? $response['response']['message'] : '';
}

function wp_remote_retrieve_headers($response)
{
    return is_array($response) && isset($response['headers']) ? $response['headers'] : array();
}

function wp_remote_retrieve_header($response, $header)
{
    $headers = wp_remote_retrieve_headers($response);
    // Core looks headers up case-insensitively.
    foreach ($headers as $name => $value) {
        if (strcasecmp((string) $name, (string) $header) === 0) {
            return $value;
        }
    }

    return null;
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

/*
 * -------------------------------------------------------------------------
 * Diagnostics.
 * -------------------------------------------------------------------------
 */

function _doing_it_wrong($function, $message, $version)
{
    WpHarness::$doing_it_wrong[] = array(
        'function' => (string) $function,
        'message' => (string) $message,
        'version' => (string) $version,
    );
}

function wp_trigger_error($function, $message, $error_level = E_USER_NOTICE)
{
    _doing_it_wrong($function, $message, '0.0.0');
}

function wp_die($message = '', $title = '', $args = array())
{
    throw new RuntimeException('wp_die() called in the test harness: ' . (is_wp_error($message) ? $message->get_error_message() : (string) $message));
}

/*
 * -------------------------------------------------------------------------
 * Time.
 * -------------------------------------------------------------------------
 */

function current_time($type = 'U', $gmt = false)
{
    if ('timestamp' === $type || 'U' === $type) {
        // Non-gmt lookups carry the site's UTC offset, exactly like core;
        // gmt lookups are offset-free so stored UTC values compare cleanly.
        return WpHarness::now() + ($gmt ? 0 : WpHarness::$utc_offset);
    }
    if ('mysql' === $type) {
        return gmdate('Y-m-d H:i:s', WpHarness::now());
    }

    return WpHarness::now();
}

/*
 * -------------------------------------------------------------------------
 * URLs and plugin plumbing.
 * -------------------------------------------------------------------------
 */

function plugins_url($path = '', $plugin = '')
{
    return 'https://example.test/wp-content/plugins/' . ltrim((string) $path, '/');
}

function plugin_basename($file)
{
    $file = wp_normalize_path((string) $file);
    $plugins_dir = wp_normalize_path(ABSPATH . 'wp-content/plugins/');
    if (strpos($file, $plugins_dir) === 0) {
        $file = substr($file, strlen($plugins_dir));
    }

    return $file;
}

function plugin_dir_path($file)
{
    return trailingslashit(dirname((string) $file));
}

function plugin_dir_url($file)
{
    return plugins_url(basename(dirname((string) $file)) . '/');
}

function wp_normalize_path($path)
{
    return str_replace('\\', '/', (string) $path);
}

function home_url($path = '')
{
    return 'https://example.test/' . ltrim((string) $path, '/');
}

function admin_url($path = '')
{
    return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function self_admin_url($path = '')
{
    return admin_url($path);
}

/*
 * -------------------------------------------------------------------------
 * Settings API.
 * -------------------------------------------------------------------------
 */

function register_setting($option_group, $option_name, $args = array())
{
    WpHarness::$registered_settings[ $option_name ] = array_merge(
        array( 'group' => $option_group, 'type' => 'string', 'default' => '', 'show_in_rest' => false ),
        (array) $args
    );

    return true;
}

function unregister_setting($option_group, $option_name)
{
    unset(WpHarness::$registered_settings[ $option_name ]);

    return true;
}

/**
 * Records admin submenu pages registered via add_options_page().
 *
 * @param string   $page_title Page title.
 * @param string   $menu_title Menu title.
 * @param string   $capability Required capability.
 * @param string   $menu_slug  Menu slug.
 * @param callable $callback   Render callback.
 * @param int|null $position   Position (ignored).
 * @return string|false The hook suffix, or false when the user lacks the capability.
 */
function add_options_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null)
{
    if (! current_user_can($capability)) {
        return false;
    }

    WpHarness::$admin_pages[ $menu_slug ] = array(
        'parent' => 'options-general.php',
        'title' => $menu_title,
        'capability' => $capability,
        'callback' => $callback,
    );

    return 'settings_page_' . $menu_slug;
}

function get_registered_settings()
{
    return WpHarness::$registered_settings;
}

function add_settings_section($id, $title, $callback, $page)
{
    return true;
}

function add_settings_field($id, $title, $callback, $page, $section = 'default', $args = array())
{
    return true;
}

function settings_fields($option_group)
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($option_group) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($option_group . '-options')) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Minimal WP_Error-style admin-notices channel used by plugin save guards.
 *
 * @param string $setting Setting slug.
 * @param string $code    Error code.
 * @param string $message Message.
 * @param string $type    Notice type.
 * @return void
 */
function add_settings_error($setting, $code, $message, $type = 'error')
{
    WpHarness::$settings_errors[] = array(
        'setting' => $setting,
        'code' => $code,
        'message' => $message,
        'type' => $type,
    );
}

function settings_errors($setting = '', $sanitize = false, $hide_on_update = false)
{
    return WpHarness::$settings_errors;
}

function do_settings_sections($page)
{
    return true;
}

/*
 * -------------------------------------------------------------------------
 * AI support gate (mirrors WP 7.0 wp-includes/ai-client.php).
 * -------------------------------------------------------------------------
 */

function wp_supports_ai()
{
    if (defined('WP_AI_SUPPORT') && ! WP_AI_SUPPORT) {
        return false;
    }

    return (bool) apply_filters('wp_supports_ai', true);
}

/*
 * -------------------------------------------------------------------------
 * WP_Error.
 * -------------------------------------------------------------------------
 */

class WP_Error
{
    protected $errors = array();
    protected $error_data = array();

    public function __construct($code = '', $message = '', $data = null)
    {
        if ('' !== $code) {
            $this->add($code, $message, $data);
        }
    }

    public function get_error_codes()
    {
        return array_keys($this->errors);
    }

    public function get_error_code()
    {
        $codes = $this->get_error_codes();

        return $codes === array() ? '' : $codes[0];
    }

    public function get_error_messages($code = '')
    {
        if ('' !== $code) {
            return isset($this->errors[ $code ]) ? $this->errors[ $code ] : array();
        }

        $all = array();
        foreach ($this->errors as $messages) {
            $all = array_merge($all, $messages);
        }

        return $all;
    }

    public function get_error_message($code = '')
    {
        if ('' === $code) {
            $code = $this->get_error_code();
        }

        return isset($this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
    }

    public function get_error_data($code = '')
    {
        if ('' === $code) {
            $code = $this->get_error_code();
        }

        return isset($this->error_data[ $code ]) ? $this->error_data[ $code ] : null;
    }

    public function add($code, $message, $data = null)
    {
        $this->errors[ $code ][] = (string) $message;
        if (null !== $data) {
            $this->error_data[ $code ] = $data;
        }
    }

    public function add_data($data, $code = '')
    {
        if ('' === $code) {
            $code = $this->get_error_code();
        }
        if ('' !== $code) {
            $this->error_data[ $code ] = $data;
        }
    }

    public function has_errors()
    {
        return $this->errors !== array();
    }
}
