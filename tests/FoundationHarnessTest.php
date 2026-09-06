<?php
/**
 * Test-harness acceptance tests (Task 0.3).
 *
 * Proves the two M0 bootstrap guarantees — plugin registration timing on the
 * init ladder, and outbound HTTP being blocked unless mocked — plus the
 * reset/determinism properties the rest of the suite relies on.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

final class FoundationHarnessTest extends WpConnectorsTestCase
{
    private const PLUGIN_FILE = __DIR__ . '/fixtures/plugins/example-connector/example-connector.php';

    private const BOOT = '\Deicod\WpConnectors\ExampleConnector\boot';

    /*
     * Acceptance: plugin registration timing.
     */

    /**
     * Core option-write semantics (code-review GLM1 #7): a first save with
     * no option row delegates to add_option() and fires ONLY the
     * add_option_ hook family; real updates fire the update family.
     */
    public function testWpdbPrepareSubstitutesBoundValuesVerbatim()
    {
        /*
         * glm20-9: preg_replace() processes $n backreference tokens
         * inside replacement strings even when the pattern has no
         * capture groups, so a bound value containing '$1' was silently
         * consumed by the stub ('_transient_x$1probe%' became
         * '_transient_xprobe%') where core wpdb::prepare() substitutes
         * verbatim — a test binding such a value would fail (or pass a
         * wrong assertion) for a reason impossible against real wpdb.
         */
        $like = "SELECT option_name FROM wp_options WHERE option_name LIKE %s";

        $this->assertSame(
            "SELECT option_name FROM wp_options WHERE option_name LIKE '_transient_x\$1probe%'",
            $GLOBALS['wpdb']->prepare($like, '_transient_x$1probe%'),
            'A $1 token in a bound value substitutes verbatim, never as a backreference.'
        );
        $this->assertSame(
            "SELECT option_name FROM wp_options WHERE option_name LIKE 'a\$0b'",
            $GLOBALS['wpdb']->prepare($like, 'a$0b'),
            'A $0 token (the whole-match splice) substitutes verbatim too.'
        );

        /*
         * The backslash collapse get_col()'s LIKE-to-regex conversion
         * relies on is unchanged: addslashes() doubles the backslash,
         * the replacement processing un-doubles it.
         */
        $this->assertSame(
            "SELECT option_name FROM wp_options WHERE option_name LIKE 'a\\b'",
            $GLOBALS['wpdb']->prepare($like, 'a\b'),
            'The addslashes/replace backslash round trip keeps producing a single backslash.'
        );
    }

    public function testSanitizeKeyMirrorsCoreScalarSemantics()
    {
        /*
         * GLM3 #8: core sanitizes only SCALAR keys — an array POST value
         * yields '' (never a coerced string, never a TypeError), and the
         * sanitize_key filter still fires with the original value. The
         * stub's earlier (string) cast masked array inputs, so the
         * settings guard could not be tested against them.
         */
        $seen = null;
        add_filter('sanitize_key', function ($sanitized, $raw) use (&$seen) {
            $seen = $raw;
            return $sanitized;
        }, 10, 2);

        $this->assertSame('', sanitize_key(array('x')), 'A non-scalar key returns the empty string, as in core.');
        $this->assertSame(array('x'), $seen, 'The filter still receives the original raw value.');
        $this->assertSame('abc_def-1', sanitize_key('ABC_def-1!'));
        $this->assertSame('42', sanitize_key(42));
    }

    public function testUpdateOptionOnAMissingRowDelegatesToAddOptionHooks()
    {
        update_option('wpct_probe_opt', 'first');

        $this->assertSame(1, did_action('add_option_wpct_probe_opt'), 'The add-option hook family fires for a first save.');
        $this->assertSame(0, did_action('update_option_wpct_probe_opt'), 'The update hooks must NOT fire when no row existed.');
        $this->assertSame(0, did_action('updated_option'));
        $this->assertSame('first', get_option('wpct_probe_opt'));

        update_option('wpct_probe_opt', 'second');

        $this->assertSame(1, did_action('update_option_wpct_probe_opt'), 'A real update fires the specific update hook.');
        $this->assertSame(1, did_action('updated_option'));
        $this->assertSame('second', get_option('wpct_probe_opt'));
    }

    public function testUpdateOptionShortCircuitsUnchangedValuesBeforeAutoloadHandling()
    {
        /*
         * glm23-8 (review round 23, finding 8): core returns false
         * BEFORE any autoload handling whenever the new value equals
         * the old — the old stub's `null === $autoload` condition let
         * an unchanged-value save with an explicit autoload argument
         * rewrite the row, flip the recorded autoload, and return true
         * where production returns false with no write at all. Both
         * arities of the call keep the short-circuit.
         */
        add_option('wpct_probe_autoload', 'keep', '', false);

        $this->assertFalse(update_option('wpct_probe_autoload', 'keep', true), 'An unchanged value returns false regardless of the autoload argument.');
        $this->assertSame('keep', get_option('wpct_probe_autoload'), 'No write happened.');
        $this->assertSame(0, did_action('update_option_wpct_probe_autoload'), 'No update hooks fired.');
        $this->assertFalse(WpHarness::$option_autoload['wpct_probe_autoload'], 'The recorded autoload did not flip.');

        $this->assertFalse(update_option('wpct_probe_autoload', 'keep'), 'The null-autoload arity keeps the same short-circuit.');
        $this->assertSame(0, did_action('update_option_wpct_probe_autoload'));
    }


    public function testUpdateOptionHookOrderAndArityMatchCore()
    {
        /*
         * glm23-9 (review round 23, finding 9): core fires the GENERIC
         * 'update_option' hook FIRST and pre-write with ($option,
         * $old_value, $value); then the specific
         * update_option_{$option} hook with THREE args ($old_value,
         * $value, $option); then 'updated_option'. The old stub fired
         * the specific hook first with two args and the generic LAST,
         * so code under test hooking the generic to observe
         * pre-invalidation state ran on the wrong side of the plugin's
         * per-option handlers, and a 3-arg specific registration
         * reading $option died on a missing argument.
         */
        update_option('wpct_probe_order', 'first');

        $calls = array();
        add_action('update_option', static function (...$args) use (&$calls) {
            $calls[] = array('generic', $args, get_option('wpct_probe_order'));
        }, 10, 3);
        add_action('update_option_wpct_probe_order', static function (...$args) use (&$calls) {
            $calls[] = array('specific', $args, get_option('wpct_probe_order'));
        }, 10, 3);
        add_action('updated_option', static function (...$args) use (&$calls) {
            $calls[] = array('updated', $args, get_option('wpct_probe_order'));
        }, 10, 3);

        update_option('wpct_probe_order', 'second');

        $this->assertSame(array(
            array('generic', array('wpct_probe_order', 'first', 'second'), 'first'),
            array('specific', array('first', 'second', 'wpct_probe_order'), 'second'),
            array('updated', array('wpct_probe_order', 'first', 'second'), 'second'),
        ), $calls);
    }

    /**
     * Request-superglobal isolation, part 1 (code-review #13): pollutes
     * $_POST/$_GET/$_REQUEST en bloc exactly the way settings tests do.
     * MUST run before testRequestSuperglobalsAreRestoredBetweenTests —
     * PHPUnit executes same-class tests in declaration order.
     */
    public function testRequestSuperglobalPollutionForTheNextTest()
    {
        $_POST = array('option_page' => 'zai_connector', 'plan' => 'general', '_wpnonce' => 'stale');
        $_GET = array('page' => 'zai-connector');
        $_REQUEST = $_POST;

        $this->assertSame('zai_connector', $_POST['option_page']);
    }

    /**
     * Part 2: setUp()'s WpHarness::reset() must have restored the pristine
     * bootstrap snapshot by now. The previous reset() only unset nonce
     * keys, so the en-bloc assignment above leaked between tests
     * (code-review #13) — an order-dependent failure waiting for
     * --filter runs, suite reordering, or newly added tests.
     */
    public function testRequestSuperglobalsAreRestoredBetweenTests()
    {
        $this->assertArrayNotHasKey('option_page', $_POST, '$_POST must not leak between tests.');
        $this->assertArrayNotHasKey('plan', $_POST, 'Non-nonce POST state must not leak either.');
        $this->assertArrayNotHasKey('page', $_GET);
        $this->assertSame(WpHarness::$request_superglobals_snapshot['POST'], $_POST, '$_POST must equal the pristine bootstrap snapshot.');
        $this->assertSame(WpHarness::$request_superglobals_snapshot['GET'], $_GET, '$_GET must equal the pristine bootstrap snapshot.');
        $this->assertSame(WpHarness::$request_superglobals_snapshot['REQUEST'], $_REQUEST, '$_REQUEST must equal the pristine bootstrap snapshot.');
    }

    /**
     * The provider registered by the plugin at init priority 5 must be
     * visible to a core-style observer at init priority 15 (where
     * _wp_connectors_init() performs auto-discovery in WP 7.0).
     */
    public function testPluginRegistersBeforeCoreConnectorDiscovery()
    {
        $this->loadPlugin(self::PLUGIN_FILE, self::BOOT);

        $registeredAtPriority15 = null;
        $registeredAtPriority20 = null;
        add_action('init', static function () use (&$registeredAtPriority15) {
            $registeredAtPriority15 = AiClient::defaultRegistry()->hasProvider('example');
        }, 15, 0);
        add_action('init', static function () use (&$registeredAtPriority20) {
            $registeredAtPriority20 = AiClient::defaultRegistry()->hasProvider('example');
        }, 20, 0);

        $this->runInit();

        $this->assertTrue($registeredAtPriority15, 'Provider was NOT registered when core discovery (init 15) ran.');
        $this->assertTrue($registeredAtPriority20);
        $this->assertSame(1, did_action('init'));
    }

    /**
     * Duplicate init execution must not double-register or warn (the
     * hasProvider() guard keeps registration idempotent).
     */
    public function testDuplicateInitExecutionIsIdempotent()
    {
        $this->loadPlugin(self::PLUGIN_FILE, self::BOOT);

        $this->runInit();
        $this->runInit();

        $this->assertTrue(AiClient::defaultRegistry()->hasProvider('example'));
        $this->assertSame(2, did_action('init'));
        $this->assertNoDoingItWrong();
    }

    /**
     * Provider metadata must describe the auth method core derives the
     * api-key setting name from (record 0001).
     */
    public function testFixtureProviderMetadataMatchesCoreExpectations()
    {
        $this->loadPlugin(self::PLUGIN_FILE, self::BOOT);
        $this->runInit();

        $metadata = AiClient::defaultRegistry()
            ->getProviderClassName('example')::metadata();

        $this->assertSame('example', $metadata->getId());
        $this->assertNotNull($metadata->getAuthenticationMethod());
        $this->assertTrue($metadata->getAuthenticationMethod()->isApiKey());

        // Core formula: connectors_ai_{id}_api_key (no hyphens in this ID).
        $this->assertSame('connectors_ai_example_api_key', 'connectors_ai_' . str_replace('-', '_', $metadata->getId()) . '_api_key');
    }

    /*
     * Acceptance: outbound HTTP is blocked unless mocked.
     */

    public function testOutboundHttpIsBlockedUnlessMocked()
    {
        // This test deliberately exercises the blocked path.
        $this->allowUnmockedHttp = true;
        $this->asAdministrator();

        $blocked = wp_remote_get('https://api.z.ai/api/paas/v4/models');
        $this->assertWPError($blocked);
        $this->assertSame('http_request_blocked', $blocked->get_error_code());

        // The attempt was recorded (so tests can also assert "no request happened").
        $this->assertCount(1, $this->httpAttempts());
        $this->assertSame('GET', $this->httpAttempts()[0]['method']);

        // With a mock installed through the WordPress hook, the call succeeds.
        $this->mockHttpResponse(array(
            'response' => array('code' => 200, 'message' => 'OK'),
            'body' => '{"object":"list","data":[]}',
            'headers' => array(),
        ));
        $ok = wp_remote_get('https://api.z.ai/api/paas/v4/models');
        $this->assertNotWPError($ok);
        $this->assertSame(200, wp_remote_retrieve_response_code($ok));
        $this->assertSame('{"object":"list","data":[]}', wp_remote_retrieve_body($ok));
    }

    public function testSdkTransportIsBlockedUnlessQueued()
    {
        // This test deliberately exercises the blocked SDK transport path.
        $this->allowUnmockedHttp = true;
        $transporter = AiClient::defaultRegistry()->getHttpTransporter();
        $this->assertInstanceOf(HttpTransporter::class, $transporter);

        $request = new Request(HttpMethodEnum::GET(), 'https://api.example.test/v1/models');

        // The SDK wraps harness-level blocks in its own NetworkException,
        // which is what connector code will actually observe.
        $this->expectException(\WordPress\AiClient\Providers\Http\Exception\NetworkException::class);
        $this->expectExceptionMessage('blocked by the wp-connectors test harness');
        $transporter->send($request);
    }

    public function testSdkTransportServesQueuedMockAndRecordsAttempt()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"data":[]}');

        $response = AiClient::defaultRegistry()->getHttpTransporter()->send(
            new Request(HttpMethodEnum::GET(), 'https://api.example.test/v1/models')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"data":[]}', (string) $response->getBody());
        $this->assertSame(array('data' => array()), $response->getData());

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);
        $this->assertSame('https://api.example.test/v1/models', $attempts[0]['url']);
    }

    /*
     * Reset and determinism guarantees.
     */

    public function testOptionsAndCronResetBetweenTests()
    {
        // Whatever a previous test left behind (see the sibling test below),
        // setUp() has already wiped it.
        $this->assertSame(array(), WpHarness::$options);
        $this->assertSame(array(), WpHarness::$cron);
        $this->assertNoHttpRequests();

        // This test deliberately leaves state behind.
        update_option('zai_connector_test_marker', 'leftover');
        wp_schedule_single_event(WpHarness::now() + 600, 'test_leftover_event');
    }

    /**
     * @depends testOptionsAndCronResetBetweenTests
     */
    public function testPreviousTestStateDoesNotLeak()
    {
        $this->assertArrayNotHasKey('zai_connector_test_marker', WpHarness::$options);
        $this->assertFalse(wp_next_scheduled('test_leftover_event'));
    }

    public function testDeterministicClockDrivesTransientsAndCron()
    {
        $this->freezeTime(1700000000);

        set_transient('test_transient', 'value', 60);
        $this->assertSame('value', get_transient('test_transient'));

        $fired = 0;
        add_action('test_clock_event', static function () use (&$fired) {
            ++$fired;
        });
        wp_schedule_single_event(1700000000 + 120, 'test_clock_event');
        $this->assertSame(1700000120, wp_next_scheduled('test_clock_event'));

        $this->advanceTime(61);
        $this->assertFalse(get_transient('test_transient'), 'Transient must expire with the frozen clock.');
        $this->assertSame(0, WpHarness::runDueEvents(), 'Event is not due yet.');

        $this->advanceTime(60);
        $this->assertSame(1, WpHarness::runDueEvents());
        $this->assertSame(1, $fired);
        $this->assertFalse(wp_next_scheduled('test_clock_event'));
    }

    public function testCurrentTimeMysqlHonorsGmtAndTheSiteOffset()
    {
        /*
         * glm20-12: the stub's 'mysql' branch returned UTC
         * unconditionally while the 'timestamp' branch honored
         * $gmt/WpHarness::$utc_offset — core's current_time('mysql')
         * renders LOCAL time for the non-gmt form, so the harness could
         * never exercise local-time mysql timestamps and a test could
         * pass against code that writes divergent strings in production
         * on any non-UTC site.
         */
        WpHarness::$utc_offset = 2 * HOUR_IN_SECONDS;

        $local = current_time('mysql');
        $gmt = current_time('mysql', true);

        $this->assertSame(
            gmdate('Y-m-d H:i:s', WpHarness::now() + 2 * HOUR_IN_SECONDS),
            $local,
            'The non-gmt mysql form renders the site-local time.'
        );
        $this->assertSame(gmdate('Y-m-d H:i:s', WpHarness::now()), $gmt, 'The gmt mysql form stays offset-free.');
        $this->assertSame(
            gmdate('Y-m-d H:i:s', WpHarness::now() + 2 * HOUR_IN_SECONDS),
            gmdate('Y-m-d H:i:s', current_time('timestamp')),
            'The mysql and timestamp forms agree on the offset, exactly like core.'
        );
    }

    public function testNoncesAreDeterministicAndUserBound()
    {
        $this->asAdministrator();
        $first = wp_create_nonce('test_action');
        $second = wp_create_nonce('test_action');

        $this->assertSame($first, $second);
        $this->assertTrue((bool) wp_verify_nonce($first, 'test_action'));
        $this->assertFalse((bool) wp_verify_nonce('tampered123', 'test_action'));

        $this->asAnonymous();
        $this->assertNotSame($first, wp_create_nonce('test_action'));
    }

    public function testCapabilityGateDistinguishesUsers()
    {
        $this->asAnonymous();
        $this->assertFalse(current_user_can('manage_options'));

        $this->asAdministrator();
        $this->assertTrue(current_user_can('manage_options'));
    }

    public function testAdminRefererChecksNonce()
    {
        $this->asAdministrator();

        $this->assertFalse(check_admin_referer('test_action'));

        $this->withValidNonce('test_action');
        $this->assertTrue(check_admin_referer('test_action'));
    }

    /*
     * Secret-handling helper.
     */

    public function testEncryptedOptionAssertionDetectsPlaintextSecrets()
    {
        $secret = 'sk-fixture-not-a-real-key-0123456789abcdef';

        // A base64-style envelope passes.
        update_option('fixture_envelope', array(
            'v' => 1,
            'ciphertext' => base64_encode('opaque-bytes-not-the-secret'),
        ));
        $this->assertOptionNotPlaintext('fixture_envelope', $secret);

        // Storing the plaintext must fail the assertion.
        update_option('fixture_envelope', $secret);
        try {
            $this->assertOptionNotPlaintext('fixture_envelope', $secret);
            $this->fail('assertOptionNotPlaintext did not detect a plaintext secret.');
        } catch (PHPUnit\Framework\ExpectationFailedException $e) {
            // Expected.
        }
    }
}
