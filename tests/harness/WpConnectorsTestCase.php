<?php
/**
 * Base test case for all wp-connectors tests.
 *
 * Resets the WordPress API harness state between tests and installs the
 * blocking SDK HTTP client, so no test can reach the network through either
 * transport (wp_remote_* or the SDK's PSR-18 layer) unless it explicitly
 * mocks a response.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\HttpTransporter;

abstract class WpConnectorsTestCase extends TestCase
{
    /**
     * Set to true in tests that deliberately exercise the blocked/unmocked
     * HTTP path; otherwise unmocked attempts fail the run (failOnWarning).
     *
     * @var bool
     */
    protected $allowUnmockedHttp = false;

    protected function setUp(): void
    {
        parent::setUp();

        WpHarness::reset();

        // The SDK registry is a process-wide singleton: point its transporter
        // at the harness client before any provider boots. Re-installing on
        // every test also re-propagates to previously registered providers.
        AiClient::defaultRegistry()->setHttpTransporter(
            new HttpTransporter(new SdkHttpClient())
        );
    }

    /**
     * Audits unmocked HTTP attempts. Runs in assertPostConditions (NOT
     * tearDown): PHPUnit 9.6 reads $this->warnings before tearDown hooks, so
     * addWarning() there is silently discarded.
     */
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        if ($this->allowUnmockedHttp) {
            return;
        }

        $leaks = array();
        foreach (WpHarness::$http_attempts as $attempt) {
            if (empty($attempt['mocked'])) {
                $leaks[] = $attempt;
            }
        }
        foreach (WpHarness::$sdk_http_attempts as $attempt) {
            if (empty($attempt['mocked'])) {
                $leaks[] = $attempt;
            }
        }

        if ($leaks !== array()) {
            $this->addWarning(
                'Test made unmocked HTTP attempts (wp_remote_* or SDK transport): ' . wp_json_encode($leaks)
            );
        }
    }

    /*
     * ---------------------------------------------------------------
     * Deterministic clock.
     * ---------------------------------------------------------------
     */

    /**
     * Freezes the clock at a fixed unix timestamp.
     *
     * @param int $timestamp Unix timestamp.
     * @return void
     */
    protected function freezeTime($timestamp)
    {
        WpHarness::freezeTime($timestamp);
    }

    /**
     * Advances the frozen clock by N seconds.
     *
     * @param int $seconds Seconds.
     * @return void
     */
    protected function advanceTime($seconds)
    {
        WpHarness::advanceTime($seconds);
    }

    /*
     * ---------------------------------------------------------------
     * Users, capabilities, nonces.
     * ---------------------------------------------------------------
     */

    /**
     * Acts as an administrator (manage_options granted).
     *
     * @param array $caps Extra capability names to grant.
     * @return void
     */
    protected function asAdministrator(array $caps = array())
    {
        wp_set_current_user(1, 'admin', array_merge(array( 'manage_options' => true ), array_fill_keys($caps, true)));
    }

    /**
     * Acts as a logged-out visitor.
     *
     * @return void
     */
    protected function asAnonymous()
    {
        wp_set_current_user(0);
    }

    /**
     * Creates a deterministic nonce and injects it into $_REQUEST.
     *
     * @param string $action Nonce action.
     * @return string The nonce value.
     */
    protected function withValidNonce($action)
    {
        $nonce = wp_create_nonce($action);
        $_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = $nonce;

        return $nonce;
    }

    /*
     * ---------------------------------------------------------------
     * Plugin booting and the init ladder.
     * ---------------------------------------------------------------
     */

    /**
     * Loads a plugin main file exactly once per process (require-once).
     *
     * Because the harness resets the hook registry between tests, the plugin's
     * load-time add_action() calls vanish on reset. Passing the plugin's
     * idempotent boot callback (the convention: `boot()` in the plugin's root
     * namespace) re-installs its hooks after every reset; the harness dedupes
     * identical registrations, so double booting is harmless.
     *
     * @param string   $path          Absolute path to the plugin main file.
     * @param callable|string|null $boot Optional boot callback to (re)invoke.
     * @return void
     */
    protected function loadPlugin($path, $boot = null)
    {
        $path = (string) $path;
        if (! isset(WpHarness::$loaded_plugins[ $path ])) {
            WpHarness::$loaded_plugins[ $path ] = true;
            require_once $path;
        }
        if (null !== $boot) {
            $callback = is_string($boot) && ! is_callable($boot) ? trim($boot, '\\') : $boot;
            if (is_callable($callback)) {
                call_user_func($callback);
            }
        }
    }

    /**
     * Fires the init action (the harness does not auto-run any init hooks).
     *
     * @return void
     */
    protected function runInit()
    {
        do_action('init');
    }

    /*
     * ---------------------------------------------------------------
     * HTTP mocking.
     * ---------------------------------------------------------------
     */

    /**
     * Mocks the next wp_remote_* call (via core's pre_http_request filter).
     *
     * The response array uses the WP shape; body content is fully controlled
     * by the caller. Return false from the filter callback to unmock.
     *
     * @param array|WP_Error $response Response for the next request(s).
     * @param callable|null  $matcher  Optional predicate (url, args) => bool.
     * @return void
     */
    protected function mockHttpResponse($response, $matcher = null)
    {
        add_filter('pre_http_request', function ($pre, $args, $url) use ($response, $matcher) {
            if (null !== $matcher && ! $matcher($url, $args)) {
                return $pre;
            }

            return $response;
        }, 10, 3);
    }

    /**
     * Queues the next SDK-transport (PSR-18) response.
     *
     * @param int    $status  HTTP status code.
     * @param array  $headers Response headers.
     * @param string $body    Response body.
     * @return void
     */
    protected function queueSdkResponse($status, array $headers = array(), $body = '')
    {
        WpHarness::$sdk_mock_queue[] = new Response((int) $status, $headers, (string) $body);
    }

    /**
     * Primes the z.ai discovery transient for the CURRENT endpoint.
     *
     * The plugin transient is the sole discovery cache (the SDK layer is
     * bypassed), so every ZaiProvider::model() lookup re-checks discovery.
     * Tests that only mock the chat/completions transport call this first,
     * so no unexpected /models attempt disturbs their recorded requests.
     *
     * @param list<string> $ids Model IDs to advertise (default glm-5.3).
     * @return void
     */
    protected function primeZaiDiscoveryTransient(array $ids = array( 'glm-5.3' ))
    {
        set_transient(
            \Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory::CACHE_PREFIX
                . md5( \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for_current_settings()->cache_key() ),
            $ids,
            \Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory::DISCOVERY_TTL
        );
    }

    /**
     * Primes the zai_anthropic discovery transient for the CURRENT endpoint.
     *
     * Same purpose as primeZaiDiscoveryTransient() for the second provider:
     * tests that only mock the /v1/messages transport call this first, so no
     * unexpected /v1/models attempt disturbs their recorded requests.
     *
     * @param list<string> $ids Model IDs to advertise (default glm-5.3).
     * @return void
     */
    protected function primeZaiAnthropicDiscoveryTransient(array $ids = array( 'glm-5.3' ))
    {
        set_transient(
            \Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory::CACHE_PREFIX
                . md5( \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::for_current_settings()->cache_key() ),
            $ids,
            \Deicod\WpConnectors\Zai\Metadata\ZaiAnthropicModelMetadataDirectory::DISCOVERY_TTL
        );
    }

    /**
     * Recorded wp_remote_* attempts.
     *
     * @return list<array{method: string, url: string, args: array}>
     */
    protected function httpAttempts()
    {
        return WpHarness::$http_attempts;
    }

    /**
     * Recorded SDK-transport attempts.
     *
     * @return list<array{method: string, url: string, headers: array, body: ?string}>
     */
    protected function sdkHttpAttempts()
    {
        return WpHarness::$sdk_http_attempts;
    }

    /**
     * Asserts no wp_remote_* or SDK-transport attempt was made.
     *
     * @return void
     */
    protected function assertNoHttpRequests()
    {
        $this->assertSame(array(), WpHarness::$http_attempts, 'Unexpected wp_remote_* attempts.');
        $this->assertSame(array(), WpHarness::$sdk_http_attempts, 'Unexpected SDK transport attempts.');
    }

    /*
     * ---------------------------------------------------------------
     * Secret-handling assertions.
     * ---------------------------------------------------------------
     */

    /**
     * Asserts a stored option does not contain a plaintext secret.
     *
     * @param string $option   Option name.
     * @param string $secret   The plaintext secret that must not be present.
     * @param string $message  Failure message.
     * @return void
     */
    protected function assertOptionNotPlaintext($option, $secret, $message = '')
    {
        $this->assertNotSame(false, get_option($option, false), sprintf('Option "%s" is not set.', $option));
        $stored = wp_json_encode(get_option($option));
        $this->assertStringNotContainsString($secret, $stored, $message !== '' ? $message : sprintf('Option "%s" contains the plaintext secret.', $option));
    }

    /**
     * Asserts a string (log line, error message, snapshot) never contains a
     * secret or identifiable fragments of it.
     *
     * @param string $haystack The output under test.
     * @param string $secret   The secret that must be redacted.
     * @return void
     */
    protected function assertRedacted($haystack, $secret)
    {
        $this->assertStringNotContainsString($secret, $haystack);

        // Long secrets must not leak via partial echoes either.
        if (strlen($secret) > 12) {
            $this->assertStringNotContainsString(substr($secret, 0, 8), $haystack);
            $this->assertStringNotContainsString(substr($secret, -8), $haystack);
        }
    }

    /*
     * ---------------------------------------------------------------
     * Diagnostics.
     * ---------------------------------------------------------------
     */

    /**
     * Asserts no _doing_it_wrong() was recorded during the test.
     *
     * @return void
     */
    protected function assertNoDoingItWrong()
    {
        $this->assertSame(array(), WpHarness::$doing_it_wrong, 'Unexpected _doing_it_wrong recordings: ' . wp_json_encode(WpHarness::$doing_it_wrong));
    }

    /**
     * Asserts the value is a WP_Error (optionally with a specific code).
     *
     * @param mixed  $actual Value.
     * @param string $code   Optional expected error code.
     * @return void
     */
    public static function assertWPError($actual, $code = '')
    {
        self::assertInstanceOf(WP_Error::class, $actual);
        if ('' !== $code) {
            self::assertSame($code, $actual->get_error_code());
        }
    }

    /**
     * Asserts the value is not a WP_Error.
     *
     * @param mixed $actual Value.
     * @return void
     */
    public static function assertNotWPError($actual)
    {
        self::assertNotInstanceOf(WP_Error::class, $actual);
    }
}
