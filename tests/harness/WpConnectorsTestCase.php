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
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

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
     * glm15-12: the transient id rides the endpoint layer's one owner
     * (discovery_cache_id()) — the hand-composed CACHE_PREFIX . md5()
     * mirror this harness carried was the composition copy the whole
     * repo had already migrated off; if the composition ever changes,
     * the primed transients must stop matching what the directories
     * read in the same edit, not ~31 call sites later.
     *
     * @param list<string> $ids Model IDs to advertise (default glm-5.3).
     * @return void
     */
    protected function primeZaiDiscoveryTransient(array $ids = array( 'glm-5.3' ))
    {
        $endpoint = \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for_current_settings();

        set_transient(
            \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::discovery_cache_id( $endpoint->plan(), $endpoint->region() ),
            $ids,
            \Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory::DISCOVERY_TTL
        );
    }

    /**
     * Primes the zai_anthropic discovery transient for the CURRENT endpoint.
     *
     * Same purpose as primeZaiDiscoveryTransient() for the second provider:
     * tests that only mock the /v1/messages transport call this first, so no
     * unexpected /v1/models attempt disturbs their recorded requests. The
     * transient id rides the endpoint layer's one owner too (glm15-12).
     *
     * @param list<string> $ids Model IDs to advertise (default glm-5.3).
     * @return void
     */
    protected function primeZaiAnthropicDiscoveryTransient(array $ids = array( 'glm-5.3' ))
    {
        $endpoint = \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::for_current_settings();

        set_transient(
            \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::discovery_cache_id( $endpoint->plan(), $endpoint->region() ),
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
     * Directory-suite helpers (glm15-19: the selectEndpoint()/idList()
     * twins lived privately in both directory suites, one settings class
     * apart, with docblocks already drifted from their assertions).
     * ---------------------------------------------------------------
     */

    /**
     * Writes one surface's plan/region options — the endpoint selection
     * the directories' discovery-cache ids key on.
     *
     * @param string $settings_class The surface's settings class (PlanRegionSettings::class / ZaiAnthropicPlanRegionSettings::class).
     * @param string $plan           One of the surface's plans.
     * @param string $region         One of the surface's regions.
     * @return void
     */
    protected function selectEndpoint(string $settings_class, string $plan, string $region)
    {
        update_option($settings_class::OPTION_PLAN, $plan);
        update_option($settings_class::OPTION_REGION, $region);
    }

    /**
     * The model IDs of a metadata list.
     *
     * @param list<ModelMetadata> $models
     * @return list<string>
     */
    protected function idList(array $models): array
    {
        return array_map(static function (ModelMetadata $m) {
            return $m->getId();
        }, $models);
    }

    /*
     * ---------------------------------------------------------------
     * WP core source loading (the core-path mapping suites).
     * ---------------------------------------------------------------
     */

    /**
     * Loads the real core prompt builder class file.
     *
     * glm15-18: this loader was a byte-identical copy in both surface
     * mapping suites; a fix to the core-source lookup path or skip
     * condition landing on one suite only made the zai and zai_anthropic
     * core-builder tests silently skip or run against different core
     * checkouts, diverging coverage of the same ErrorMapper/core-code
     * path.
     *
     * @return class-string<WP_AI_Client_Prompt_Builder>
     */
    protected function corePromptBuilderClass(): string
    {
        $home = (string) getenv('HOME');
        $wpRoot = (string) (getenv('WP_CONNECTORS_TEST_WP_ROOT') ?: ($home !== '' ? $home . '/wp-ai-research/wordpress' : ''));
        $file = $wpRoot . '/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php';

        if ('' === $wpRoot || ! is_file($file)) {
            $this->markTestSkipped('WP core source not found (set WP_CONNECTORS_TEST_WP_ROOT to the WordPress checkout).');
        }

        require_once $file;

        return 'WP_AI_Client_Prompt_Builder';
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

    /*
     * ---------------------------------------------------------------
     * Request capture, snapshots, and pre-transport rejections
     * (GLM12 #14 — hoisted from the byte-identical twin copies the two
     * mapping suites carried, whose captureRequest had already forked
     * once on the try/catch).
     * ---------------------------------------------------------------
     */

    /**
     * Directory holding this suite's committed request snapshots.
     *
     * The default is the shared snapshots directory; a suite with its
     * own snapshot family overrides (the surface mapping suites use
     * fixtures/snapshots/zai and fixtures/snapshots/zai-anthropic).
     *
     * @return string Absolute path, no trailing slash.
     */
    protected function snapshotDirectory(): string
    {
        return __DIR__ . '/../fixtures/snapshots';
    }

    /**
     * Queues the one successful response captureRequest() drives its
     * generation with. A suite whose surface parses a specific protocol
     * overrides with that protocol's success body.
     *
     * @return void
     */
    protected function queueCaptureResponse()
    {
        $this->queueSdkResponse(200, array(), '');
    }

    /**
     * The wired model the pre-transport rejection helper drives. A suite
     * using assertRejectedBeforeTransport() overrides with its own
     * provider's model (discovery transient primed, harness transporter,
     * fixture key); the loud default catches a missing override instead
     * of silently testing nothing.
     *
     * @param ModelConfig|null $config Optional model configuration.
     * @return object The wired model.
     */
    protected function snapshotTestModel(?ModelConfig $config = null)
    {
        throw new RuntimeException(get_class($this) . ' must provide the rejection-helper model.');
    }

    /**
     * Runs one generation and returns [url, decodedBody, headers] of the
     * single recorded request.
     *
     * GLM12 #14: the twin copies are reconciled on the explicit
     * pre-transport-failure branch — an InvalidArgumentException from
     * the guarded mapping surfaces as a named failure, not an uncaught
     * error.
     *
     * @param list<Message> $prompt Prompt to send.
     * @param object        $model  A wired text-generation model.
     * @return array{0: string, 1: array, 2: array}
     */
    protected function captureRequest(array $prompt, $model): array
    {
        $this->queueCaptureResponse();

        try {
            $model->generateTextResult($prompt);
        } catch (InvalidArgumentException $e) {
            // The captured body is still available; surface it for debugging.
            $this->fail('Request failed pre-transport or in parsing: ' . $e->getMessage());
        }

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);

        return array(
            $attempts[0]['url'],
            (array) json_decode((string) $attempts[0]['body'], true),
            $attempts[0]['headers'],
        );
    }

    /**
     * Asserts the captured request equals the committed snapshot.
     *
     * Snapshots are created on first run (the test skips that run and
     * must be re-run to verify); headers are excluded by construction
     * and the credential invariants are asserted regardless.
     *
     * @param string $name  Snapshot name.
     * @param string $url   Request URL.
     * @param array  $body  Decoded request body.
     * @return void
     */
    protected function assertMatchesSnapshot(string $name, string $url, array $body)
    {
        $path = $this->snapshotDirectory() . '/' . $name . '.json';
        $snapshot = array('url' => $url, 'body' => $body);

        if (!is_file($path)) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->markTestSkipped("Snapshot {$name} created; re-run to verify.");
        }

        $this->assertSame(
            $snapshot,
            (array) json_decode((string) file_get_contents($path), true),
            "Captured request drifted from snapshot {$name}."
        );

        // Snapshots never contain credentials (headers are excluded by
        // construction; assert the invariant anyway).
        $this->assertStringNotContainsString('Bearer', (string) file_get_contents($path));
        $this->assertStringNotContainsString('Authorization', (string) file_get_contents($path));
    }

    /**
     * Asserts the configuration is rejected before any transport work,
     * with the given needle in its message.
     *
     * @param ModelConfig $config The configuration under test.
     * @param string      $needle Expected message fragment.
     * @return void
     */
    protected function assertRejectedBeforeTransport(ModelConfig $config, string $needle)
    {
        try {
            $this->snapshotTestModel($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail("Config containing '{$needle}' must be rejected.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }
}
