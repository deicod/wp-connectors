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
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
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

        /*
         * glm34-7: placeholders substitute ONCE, left-to-right over the
         * original query — the former sequential preg_replace() loop
         * re-scanned the already-substituted string, so a bound value
         * carrying a literal '%s' consumed the next argument's slot
         * ('a = %s AND b = %s', 'lit%s', 7) yielded "a = 'lit7' AND
         * b = %s"), misbinding arguments the real wpdb never re-reads.
         */
        $this->assertSame(
            "SELECT option_name FROM wp_options WHERE option_name LIKE 'lit%s' AND other = 7",
            $GLOBALS['wpdb']->prepare(
                'SELECT option_name FROM wp_options WHERE option_name LIKE %s AND other = %d',
                'lit%s',
                7
            ),
            'A placeholder-like token inside a bound value never consumes the next argument slot.'
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


    public function testUnchangedValuesCompareOverSerializedEqualityCoreArm()
    {
        /*
         * glm17-8: core's unchanged compare is identity OR
         * maybe_serialize() equality (option.php:923, pinned 7.1.1) —
         * two equal-VALUED but non-identical arrays/objects are
         * UNCHANGED: no write, no hooks, no autoload flip (ticket
         * #38903's own class). The stub rode identity alone (driven
         * red at HEAD: an equal-valued ArrayObject save wrote the
         * second instance and fired the update family).
         */
        $make = static function () {
            return new ArrayObject(array( 'k' => 'v' ));
        };
        $first = $make();
        $this->assertTrue(update_option('glm17_obj_opt', $first));
        $this->assertSame(0, did_action('update_option_glm17_obj_opt'), 'staging: the first save rode the add family, never the update one.');

        $second = $make();
        $this->assertNotSame($first, $second, 'staging: the pair must be two distinct instances — the serialized-equality arm this leg drives.');

        $this->assertFalse(update_option('glm17_obj_opt', $second), 'An equal-valued non-identical object answers UNCHANGED, core\'s maybe_serialize arm (red at HEAD: true, the write completed).');
        $this->assertSame(0, did_action('update_option_glm17_obj_opt'), 'No update hooks fired over the unchanged value (red at HEAD: the specific hook fired).');
        $this->assertSame(0, did_action('updated_option'), 'The closing hook stayed silent too.');
        /*
         * CORRECTED (glm18-8): the leg once asserted the stored
         * INSTANCE was the caller's own $first (assertSame) — the
         * harness's reference-storage artifact, falsified by core's
         * own head clone (option.php:882-884): core never stores the
         * caller's instance, it stores serialized bytes, and the
         * harness's parity shape is the detached equal-valued copy.
         * "Nothing wrote" still names the leg: the stored row carries
         * the FIRST value, never the second instance.
         */
        $this->assertEquals($first, get_option('glm17_obj_opt'), 'Nothing wrote — the stored row still carries the FIRST value, a detached equal-valued copy of it (red at HEAD: the second instance stored).');
        $this->assertNotSame($second, get_option('glm17_obj_opt'), 'The second instance is not the stored row.');

        // The control: a genuinely different value still updates.
        $this->assertTrue(update_option('glm17_obj_opt', array( 'k' => 'changed' )));
        $this->assertSame(array( 'k' => 'changed' ), get_option('glm17_obj_opt'));
    }

    public function testTheExistingRowAddStillRunsTheSanitizerCoreOrder()
    {
        /*
         * glm17-9: core sanitizes at the TRUE head of add_option() —
         * BEFORE the exists-guard (option.php:1113 precedes :1121,
         * pinned 7.1.1) — so the guard's no-op add still counts the
         * add-head run. The stub had the guard first, silently
         * skipping the sanitizer on the existing-row path (driven red
         * at HEAD: the callback ran zero times over the no-op add).
         */
        $runs = 0;
        register_setting('glm17', 'glm17_head_opt', array(
            'sanitize_callback' => static function ($v) use (&$runs) {
                ++$runs;

                return $v;
            },
        ));

        update_option('glm17_head_opt', 'stored');
        $this->assertSame(2, $runs, 'staging: the first save ran the sanitizer at BOTH heads (glm16-5\'s pin).');

        $this->assertFalse(add_option('glm17_head_opt', 'attempt'), 'The non-false duplicate keeps the silent no-op (glm17-4\'s guard).');
        $this->assertSame(3, $runs, 'The add HEAD ran before the guard — the no-op add still sanitizes (red at HEAD: the guard returned first, 2).');
        $this->assertSame('stored', get_option('glm17_head_opt'), 'The no-op wrote nothing, as before.');
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

    public function testTheWpdbGetColStubFailsLoudOnUnrecognizedQueries()
    {
        /*
         * glm29-15: the stub used to answer array() for any query not
         * matching its single recognized shape, so negative assertions
         * over a drifted query passed vacuously on fabricated empty
         * results — the silently-compliant direction. An unrecognized
         * shape throws now; extending the stub to a new query family is
         * a conscious edit, never a silent empty.
         */
        $refusal = $this->refusalOf(
            fn() => $GLOBALS['wpdb']->get_col('SELECT option_name FROM wp_options WHERE option_name LIKE \'x%\' ORDER BY option_name'),
            'An unrecognized get_col() query shape must throw.', \RuntimeException::class
        );
        $this->assertStringContainsString('unsupported query shape', $refusal->getMessage());
        $this->assertStringContainsString('ORDER BY', $refusal->getMessage(), 'The diagnostic names the query it refused.');
    }

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

    /**
     * SDK credential isolation, part 1 (glm30-1): wires a credential
     * through BOTH leak channels — the registry's per-provider map and
     * the process-wide provider instances — exactly the way the mapping
     * suites do. MUST run before
     * testSdkProviderCredentialsDoNotLeakBetweenTests; the dependency
     * annotation on that test keeps the pair ordered under
     * --order-by=random.
     */
    public function testSdkProviderCredentialPollutionForTheNextTest()
    {
        \Deicod\WpConnectors\Zai\Plugin::register(AiClient::defaultRegistry());

        $key = FakeSecrets::apiKey();
        $authentication = new ApiKeyRequestAuthentication($key);

        // Channel 1: the registry map (re-applied by any later
        // registerProvider(), stamped onto new model instances).
        AiClient::defaultRegistry()->setProviderRequestAuthentication(
            \Deicod\WpConnectors\Zai\Provider\ZaiProvider::PROVIDER_ID,
            $authentication
        );

        // Channel 2: a cached provider instance directly (the
        // setRequestAuthentication() shape several suites use on a
        // different surface than the registry wiring).
        \Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider::modelMetadataDirectory()
            ->setRequestAuthentication($authentication);

        $this->assertSame(
            $authentication,
            AiClient::defaultRegistry()->getProviderRequestAuthentication(
                \Deicod\WpConnectors\Zai\Provider\ZaiProvider::PROVIDER_ID
            ),
            'The registry map holds the wired credential before the reset.'
        );
        $this->assertSame(
            $key,
            \Deicod\WpConnectors\Zai\Provider\ZaiProvider::availability()->getRequestAuthentication()->getApiKey(),
            'The process-wide availability instance carries the wired credential.'
        );
    }

    /**
     * Part 2: setUp() must have erased both channels by now (glm30-1).
     * Before the reset, the credential rode every later test in the same
     * PHP process — under the pipeline's --order-by=random ordering a
     * test asserting the no-credential path failed as a pure function of
     * execution order.
     *
     * @depends testSdkProviderCredentialPollutionForTheNextTest
     */
    public function testSdkProviderCredentialsDoNotLeakBetweenTests()
    {
        $this->assertNull(
            AiClient::defaultRegistry()->getProviderRequestAuthentication(
                \Deicod\WpConnectors\Zai\Provider\ZaiProvider::PROVIDER_ID
            ),
            'The registry authentication map must not leak between tests.'
        );

        foreach (array(
            \Deicod\WpConnectors\Zai\Provider\ZaiProvider::class,
            \Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider::class,
        ) as $provider_class) {
            foreach (array('availability', 'modelMetadataDirectory') as $factory) {
                try {
                    $provider_class::$factory()->getRequestAuthentication();
                    $this->fail("{$provider_class}::{$factory}() still carries a credential from a previous test.");
                } catch (\WordPress\AiClient\Common\Exception\RuntimeException $e) {
                    // The SDK trait's unwired throw — the pre-wiring state.
                }
            }
        }
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

    public function testDueEventsFireInTimestampOrderAndAnOverdueRecurringEventFiresOnce()
    {
        /*
         * glm14-7: the docblock promised timestamp order while the
         * scan fired whichever due event the hook-registration walk
         * hit first (driven: a later-registered event at ts=200 fired
         * BEFORE an earlier-due one at ts=100), and a far-overdue
         * recurring event replayed once per missed interval — rescheduled
         * at timestamp+interval, still in the past, re-found by the
         * progress loop — where core's wp_reschedule_event fires ONCE
         * and recomputes the next due from NOW.
         */
        $this->freezeTime(1700000000);

        $order = array();
        add_action('zz_glm14_event', static function () use (&$order) {
            $order[] = 'zz';
        });
        add_action('aa_glm14_event', static function () use (&$order) {
            $order[] = 'aa';
        });
        wp_schedule_single_event(1700000200, 'zz_glm14_event');
        wp_schedule_single_event(1700000100, 'aa_glm14_event');

        $this->advanceTime(1000);
        $this->assertSame(2, WpHarness::runDueEvents());
        $this->assertSame(array( 'aa', 'zz' ), $order, 'Due events fire in timestamp order (red at HEAD: registration order, zz first).');

        $hourly = 0;
        add_action('glm14_hourly_event', static function () use (&$hourly) {
            ++$hourly;
        });
        wp_schedule_event(1700000000 - 86400, 'hourly', 'glm14_hourly_event');

        $this->assertSame(1, WpHarness::runDueEvents(), 'A day-overdue hourly event fires exactly once (red at HEAD: replayed once per missed interval).');
        $this->assertSame(1, $hourly);
        /*
         * glm15-5: core's wp_reschedule_event() GRID-ALIGNS the next
         * due — now + (interval − ((now − ts) % interval)) — keeping the
         * schedule's phase over its own grid (with these pinned numbers:
         * ts 1699913600, hourly, now 1700001000 → 1700003600). The
         * glm14-7 pin asserted 1700004600 (the now+interval DRIFT
         * spelling) mislabeled as core semantics — corrected with the
         * driven number.
         */
        $this->assertSame(1700003600, wp_next_scheduled('glm14_hourly_event'), 'The next due is grid-aligned to the schedule\'s phase, core\'s wp_reschedule_event() arithmetic (glm14-7 pinned the now+interval drift spelling here).');
    }

    public function testAReschedulingHandlerTerminatesAndMidRunEventsDefer()
    {
        /*
         * glm15-4: the while(true) rescan re-found every mid-run insert,
         * so a handler re-scheduling an already-due event hung the suite
         * forever (driven red at HEAD: timeout 10, exit 124) and an
         * event scheduled MID-RUN fired in the SAME call where core's
         * wp_cron() snapshots the queue and defers to the next tick.
         * The pass is a snapshot now: only entry-captured events fire,
         * one bounded pass.
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        $rearm = true;
        add_action('glm15_resched', static function () use (&$fires, &$rearm) {
            ++$fires;
            if ($rearm) {
                wp_schedule_single_event(1700000000, 'glm15_resched');
                $rearm = false;
            }
        });
        wp_schedule_single_event(1700000000, 'glm15_resched');

        $this->assertSame(1, WpHarness::runDueEvents(), 'A re-scheduling handler fires its event once and the pass terminates (red at HEAD: the unbounded rescan hung — driven at timeout 10, exit 124).');
        $this->assertSame(1, $fires);
        $this->assertSame(1700000000, wp_next_scheduled('glm15_resched'), 'The re-armed event (scheduled mid-run) is not in the snapshot.');
        $this->assertSame(1, WpHarness::runDueEvents(), 'The re-armed event fires on the NEXT call.');
        $this->assertSame(2, $fires);
        $this->assertFalse(wp_next_scheduled('glm15_resched'));

        // A fresh mid-run schedule defers the same way (core's shape).
        $deferred = 0;
        add_action('glm15_first', static function () {
            wp_schedule_single_event(WpHarness::now(), 'glm15_second');
        });
        add_action('glm15_second', static function () use (&$deferred) {
            ++$deferred;
        });
        wp_schedule_single_event(WpHarness::now(), 'glm15_first');

        $this->assertSame(1, WpHarness::runDueEvents(), 'glm15_first fires; glm15_second (scheduled mid-run) is not in the snapshot.');
        $this->assertSame(0, $deferred, 'A mid-run-scheduled event never fires in the call that scheduled it (red at HEAD: it fired in the same call).');
        $this->assertSame(1, WpHarness::runDueEvents(), 'The deferred event fires on the next tick.');
        $this->assertSame(1, $deferred);
    }

    public function testASnapshotReadFailureAnswersAsItselfNeverAsDrift()
    {
        /*
         * glm14-8: the snapshot compare read through a laundering pair
         * — (string) file_get_contents() + json_decode() cast — so an
         * unreadable snapshot became '' and a corrupt one became
         * null -> [], BOTH misreporting as 'Captured request drifted
         * from snapshot' and sending the operator hunting a
         * request-drift regression that does not exist. The read and
         * the decode own their failure now, naming the file and the
         * json error (the laundering-read class fixed at every
         * sibling read this change set has touched).
         */
        $dir = sys_get_temp_dir() . '/wp-connectors-snapshot-read-' . uniqid('', true);
        $this->assertTrue(mkdir($dir, 0755, true), "staging: {$dir} must create — a staging failure fails as staging, never as the snapshot verdict.");
        $probe = new class($dir) extends WpConnectorsTestCase {
            public function __construct(string $dir)
            {
                parent::__construct('glm14SnapshotProbe');
                $this->snapshot_dir = $dir;
            }

            public function probe(string $name, string $url, array $body): void
            {
                $this->assertMatchesSnapshot($name, $url, $body);
            }

            protected function snapshotDirectory(): string
            {
                return $this->snapshot_dir;
            }

            /** @var string */
            private $snapshot_dir;
        };

        try {
            // The healthy control: a matching committed snapshot passes.
            $healthy = '{"url": "https://x.test/a", "body": {"k": "v"}}' . "\n";
            $this->assertNotFalse(file_put_contents($dir . '/healthy.json', $healthy), 'staging: the healthy snapshot must write.');
            $probe->probe('healthy', 'https://x.test/a', array('k' => 'v'));

            // A truncated snapshot answers the corrupt-snapshot failure, never drift.
            $this->assertNotFalse(file_put_contents($dir . '/truncated.json', '{"url": "https://x.test/a", "body": '), 'staging: the truncated snapshot must write.');
            try {
                $probe->probe('truncated', 'https://x.test/a', array('k' => 'v'));
                $this->fail('A corrupt snapshot must fail the comparison as corrupt, never pass.');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->assertStringContainsString('is corrupt (json:', $e->getMessage(), 'red at HEAD: the failure read "drifted from snapshot" over the null decode.');
            }

            // An unreadable snapshot answers the unreadable failure, never drift.
            $this->skipChmod0000LegOnRootRunner('the unreadable-snapshot leg');
            $this->assertNotFalse(file_put_contents($dir . '/unreadable.json', $healthy), 'staging: the unreadable snapshot must write.');
            $this->assertTrue(chmod($dir . '/unreadable.json', 0000), 'staging: the unreadable snapshot must lock.');
            try {
                $probe->probe('unreadable', 'https://x.test/a', array('k' => 'v'));
                $this->fail('An unreadable snapshot must fail the comparison as unreadable, never pass.');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->assertStringContainsString('is unreadable', $e->getMessage(), 'red at HEAD: the failure read "drifted from snapshot" over the false read.');
            }
        } finally {
            @chmod($dir . '/unreadable.json', 0644);
            foreach ((glob($dir . '/*') ?: array()) as $entry) {
                @unlink($entry);
            }
            @rmdir($dir);
        }
    }

    public function testTheRegisteredSanitizeCallbackRunsOnTheSettingsSavePath()
    {
        /*
         * glm14-10 wired the callback and added the sanitize_option()
         * primitive on the premise that core's own update_option() does
         * not sanitize — and this regression MASKED the gap by calling
         * sanitize_option() manually before update_option(), green over
         * a stub whose save path still skipped the callback. glm15-3:
         * the premise is FALSE (WP 7.1.1 core calls sanitize_option()
         * at the head of BOTH update_option() — option.php:886 — and
         * add_option() — :1113, driven), and the stub owns the head-of
         * sanitize now. This pin DROPS the manual call: the
         * function-level save itself must run the registered callback
         * and store its answer (red at HEAD: runs=0, the raw value
         * stored). glm16-5 CORRECTED the round-15 runs=1 spec: core
         * sanitizes at BOTH heads on a first save — update_option()'s
         * head AND the add_option() it delegates to — so the first
         * save counts TWO runs (structural through the delegation,
         * never a forced double call) and every subsequent save one.
         */
        $runs = 0;
        register_setting('glm15_group', 'glm15_opt', array(
            'sanitize_callback' => static function ( $value ) use ( &$runs ) {
                ++$runs;

                return \is_string( $value ) ? \trim( $value ) : null;
            },
        ));

        // The add path (the first save): BOTH heads run — the sanitized value stored.
        $this->assertTrue(update_option('glm15_opt', '  padded  '));
        $this->assertSame(2, $runs, 'The first save runs the registered callback at both heads, core\'s both-heads shape (the round-15 runs=1 spec corrected — glm16-5).');
        $this->assertSame('padded', get_option('glm15_opt'), 'The stored value is the sanitized one, never the raw input.');

        // The update path: one head, one run.
        $this->assertTrue(update_option('glm15_opt', '  tighter  '));
        $this->assertSame(3, $runs, 'The update path runs the registered callback exactly once per save (red at HEAD: never consulted).');
        $this->assertSame('tighter', get_option('glm15_opt'));

        // The unchanged shape: a save whose SANITIZED value equals the
        // stored value refuses — no write, no hooks (glm23-8 on core's
        // sanitize-then-compare order; the sanitizer still ran).
        $this->assertFalse(update_option('glm15_opt', '  tighter  '));
        $this->assertSame(4, $runs, 'The refused unchanged save ran the sanitizer; it fired no hooks and wrote nothing.');
        $this->assertSame('tighter', get_option('glm15_opt'));

        /*
         * The null guard stays the save-path CALLER's (core's
         * options.php shape): the primitive passes a null answer
         * through, and the function-level API stores the filter's
         * answer like any other — the options.php emulation refuses
         * on null before it ever calls update_option().
         */
        $this->assertNull(sanitize_option('glm15_opt', array( 'not', 'a', 'string' )), 'A null answer passes through, never swallowed.');
        $this->assertTrue(update_option('glm15_opt', array( 'not', 'a', 'string' )));
        $this->assertNull(get_option('glm15_opt'), 'The function-level API stores the filter\'s answer (null included); the null GUARD is the options.php caller\'s, never the function\'s.');
    }

    public function testUpdateOptionSanitizesBeforeComparingCoreOrder()
    {
        /*
         * glm16-3: core sanitizes at the head, THEN compares — the
         * stub's raw-compare-first never consulted the sanitizer over a
         * raw-equal save (runs=0 where core runs it and writes the
         * sanitized answer), and a false-RETURNING callback completed
         * an ADD core refuses outright (the sanitized false compares
         * equal to the missing-row false: one refusal, no hooks, no
         * write).
         */
        $runs = 0;
        register_setting('glm16_group', 'glm16_raw', array(
            'sanitize_callback' => static function ( $value ) use ( &$runs ) {
                ++$runs;

                return $value . 'X';
            },
        ));

        // A first save: both heads run (glm16-5's structural shape) —
        // the appending callback applies at each head, core's own
        // double-apply on a first save.
        $this->assertTrue(update_option('glm16_raw', 'a'));
        $this->assertSame(2, $runs, 'The first save sanitizes at both heads — the delegation rides, never a forced double call.');
        $this->assertSame('aXX', get_option('glm16_raw'));

        // The raw-equal save the sanitizer CHANGES: core runs the
        // sanitizer and WRITES the answer (red at HEAD: the raw compare
        // refused with runs unchanged and nothing written).
        $this->assertTrue(update_option('glm16_raw', 'aXX'));
        $this->assertSame(3, $runs, 'A raw-equal save still consults the sanitizer — the raw input never compares (red at HEAD: runs stayed 2 over the pair).');
        $this->assertSame('aXXX', get_option('glm16_raw'), 'The sanitized value is what compares and writes (red at HEAD: refused, the stored value unchanged).');

        // A false-returning callback: the sanitized false equals the
        // missing-row false — core refuses with no ADD, no hooks, no
        // write (red at HEAD: the ADD completed, fired, and stored).
        $fired = 0;
        add_action('add_option_glm16_falsey', static function () use (&$fired) {
            ++$fired;
        });
        register_setting('glm16_group', 'glm16_falsey', array(
            'sanitize_callback' => static function () {
                return false;
            },
        ));

        $this->assertFalse(update_option('glm16_falsey', 'v'), 'The false answer refuses the save (red at HEAD: the delegation completed and answered true).');
        $this->assertSame(0, $fired, 'No add hooks fire over the refusal (red at HEAD: the add family fired).');
    }

    public function testAFirstSaveRunsTheSanitizerAtBothHeadsCoreShape()
    {
        /*
         * glm16-5: core sanitizes TWICE on a first save — at
         * update_option()'s head AND at the add_option() it delegates
         * to — and the round-15 spec demanded callback_runs=1 over the
         * missing-row delegation. The spec was wrong; core parity
         * wins. The stub matches structurally: the delegation rides,
         * never a forced double call, so the count falls out of the
         * two heads themselves.
         */
        $runs = 0;
        register_setting('glm16_group', 'glm16_both', array(
            'sanitize_callback' => static function ( $value ) use ( &$runs ) {
                ++$runs;

                return \is_string( $value ) ? \trim( $value ) : $value;
            },
        ));

        // The first save (a missing row): BOTH heads — count 2 (red at
        // HEAD: 1, the round-15 runs=1 spec).
        $this->assertTrue(update_option('glm16_both', '  first  '));
        $this->assertSame(2, $runs, 'A first save runs the registered callback at both heads, core\'s own shape (the round-15 runs=1 spec corrected).');
        $this->assertSame('first', get_option('glm16_both'));

        // Subsequent saves: ONE head — count 3.
        $this->assertTrue(update_option('glm16_both', '  second  '));
        $this->assertSame(3, $runs, 'A subsequent save runs the callback exactly once.');
        $this->assertSame('second', get_option('glm16_both'));

        // The direct add_option() call is the second head alone: ONE run.
        $runs = 0;
        register_setting('glm16_group', 'glm16_direct', array(
            'sanitize_callback' => static function ( $value ) use ( &$runs ) {
                ++$runs;

                return \is_string( $value ) ? \trim( $value ) : $value;
            },
        ));
        $this->assertTrue(add_option('glm16_direct', '  direct  '));
        $this->assertSame(1, $runs, 'A direct add_option() call is one head, one run — the delegation is what doubles the first save.');
        $this->assertSame('direct', get_option('glm16_direct'));

        // The glm15-3 stored-' raw ' leg stays sanitized: a raw-equal
        // save still runs the callback and refuses on the answer
        // (the counter carries the direct leg's single run above).
        $this->assertFalse(update_option('glm16_both', 'second'));
        $this->assertSame(2, $runs, 'The raw-equal save consulted the sanitizer (glm16-3\'s core order) and refused on the sanitized compare.');
    }

    public function testUnregisterSettingRemovesTheSanitizeHook()
    {
        /*
         * glm15-8: unregister_setting() dropped the registry row but
         * left the sanitize callback ON the filter core's registration
         * mechanism wires (glm14-10) — register(A), unregister,
         * register(B) answered A still riding beside B (driven red at
         * HEAD: 'vAB' where core answers 'vB' — the unregistered
         * sanitizer kept shaping every later save).
         */
        $first = static function ( $value ) {
            return $value . 'A';
        };
        register_setting('glm15_group', 'glm15_opt', array( 'sanitize_callback' => $first ));
        unregister_setting('glm15_group', 'glm15_opt');
        register_setting('glm15_group', 'glm15_opt', array( 'sanitize_callback' => static function ( $value ) {
            return $value . 'B';
        } ));

        $this->assertSame('vB', sanitize_option('glm15_opt', 'v'), 'The unregistered callback is gone from the hook (red at HEAD: vAB — A still rode beside B).');
        $this->assertTrue(update_option('glm15_opt', 'v'));
        /*
         * glm16-5: both heads apply on a first save — 'v' carries B at
         * update_option()'s head and again at the delegated
         * add_option()'s ('vBB'), core's own double-apply; the
         * round-15 'vB' expectation rode the corrected runs=1 spec.
         * The unregistered A is what must stay gone either way.
         */
        $this->assertSame('vBB', get_option('glm15_opt'), 'The save path stores only the REGISTERED callback\'s answer, applied at both heads (red at HEAD: vAB stored).');

        // Unregistering a setting that never carried a callback is a clean no-op.
        $this->assertTrue(unregister_setting('glm15_group', 'glm15_never_registered'));
    }

    public function testCronSchedulesApplyTheFilterRefuseUnknownRecurrencesAndCarryWeekly()
    {
        /*
         * glm15-9: the schedules map diverged from core three ways —
         * the 'cron_schedules' filter was never applied (a plugin's
         * custom schedule invisible at resolution time), an unknown
         * recurrence was ACCEPTED (wp_schedule_event(..., 'weekly',
         * ...) returned true with interval 0, firing once and never
         * rescheduling — core returns false), and 'weekly'/
         * WEEK_IN_SECONDS were absent (the constant reference a fatal
         * at HEAD).
         */
        $this->freezeTime(1700000000);

        // (a) The filter applies at schedule-time resolution, and the
        // defaults merge OVER a same-key filter entry (core's order).
        add_filter('cron_schedules', static function ( $schedules ) {
            $schedules['glm15_custom'] = array( 'interval' => 123 );
            $schedules['daily'] = array( 'interval' => 999999 );

            return $schedules;
        });
        $schedules = wp_get_schedules();
        $this->assertSame(123, $schedules['glm15_custom']['interval'], 'The cron_schedules filter is applied (red at HEAD: the custom entry never answered).');
        $this->assertSame(DAY_IN_SECONDS, $schedules['daily']['interval'], 'The defaults merge over the filter — a plugin never clobbers a default spelling.');
        $this->assertTrue(wp_schedule_event(1700000000 + 2 * WEEK_IN_SECONDS, 'glm15_custom', 'glm15_custom_hook'), 'A custom schedule resolves through the filter (scheduled past this leg\'s window).');

        // (b) An unknown recurrence refuses and never enters the queue.
        $this->assertFalse(wp_schedule_event(1700000100, 'glm15_unknown', 'glm15_hook'), 'An unknown recurrence refuses (red at HEAD: accepted with interval 0).');
        $this->assertFalse(wp_next_scheduled('glm15_hook'), 'The refused event never entered the queue.');

        // (c) 'weekly'/WEEK_IN_SECONDS are core's own defaults now.
        $this->assertSame(7 * DAY_IN_SECONDS, WEEK_IN_SECONDS, 'WEEK_IN_SECONDS exists at core\'s own value (red at HEAD: undefined).');
        $this->assertSame(WEEK_IN_SECONDS, wp_get_schedules()['weekly']['interval'], 'The weekly schedule joins the default set.');
        $this->assertTrue(wp_schedule_event(1700000000, 'weekly', 'glm15_weekly'));
        $this->advanceTime(WEEK_IN_SECONDS);
        $this->assertSame(1, WpHarness::runDueEvents(), 'The weekly event fires once when due.');
        $this->assertSame(1700000000 + 2 * WEEK_IN_SECONDS, wp_next_scheduled('glm15_weekly'), 'The weekly recurrence reschedules on its own interval (grid-aligned, glm15-5).');
    }

    public function testAddQueryArgKeepsTheFragmentAtTheTail()
    {
        /*
         * glm15-11: add_query_arg() mishandled '#fragment' — the
         * fragment was swallowed into the last param's value
         * ('code=1#frag' parsed as the VALUE '1#frag', re-encoded
         * '%23frag') or new params appended INSIDE the fragment
         * ('cb#frag?p=v'). Zero callers today, but OAuth redirect
         * URLs are where fragments live — closed preemptively,
         * core's own shape: the fragment splits off before param
         * parsing and re-appends LAST.
         */
        $this->assertSame(
            'https://x.test/cb?code=1&p=v#frag',
            add_query_arg(array( 'p' => 'v' ), 'https://x.test/cb?code=1#frag'),
            'The fragment survives a new param and stays at the tail (red at HEAD: swallowed into the value, re-encoded %23frag).'
        );

        $this->assertSame(
            'https://x.test/cb?p=v#frag',
            add_query_arg(array( 'p' => 'v' ), 'https://x.test/cb#frag'),
            'A URL with no query gains one BEFORE the fragment (red at HEAD: the param appended inside the fragment).'
        );

        // The no-fragment shapes stay byte-identical.
        $this->assertSame('https://x.test/cb?code=1&p=v', add_query_arg(array( 'p' => 'v' ), 'https://x.test/cb?code=1'));
    }

    public function testWpRemoteRequestDefaultsToGetCoreNotPost()
    {
        /*
         * glm15-12: wp_remote_request() defaulted the method to POST
         * where core's WP_Http::request defaults GET — the recorded
         * attempt (and any pre_http_request mock's view of $args)
         * named POST for every default call, green-testing a generic
         * REST client against a divergent method.
         */
        $this->allowUnmockedHttp = true;

        wp_remote_request('https://api.example.test/generic');
        $this->assertSame('GET', $this->httpAttempts()[0]['method'], 'The default method is GET, core\'s own (red at HEAD: POST).');

        wp_remote_request('https://api.example.test/generic', array( 'method' => 'DELETE' ));
        $this->assertSame('DELETE', $this->httpAttempts()[1]['method'], 'An explicit method rides unchanged.');

        /*
         * glm16-9: the default lands BEFORE the filter — a
         * pre_http_request mock observes core's shape ('GET' in
         * $args), never the method-less array the round-15 fix handed
         * it (red at HEAD: null).
         */
        $seen = null;
        add_filter('pre_http_request', static function ( $pre, $args ) use ( &$seen ) {
            $seen = $args['method'] ?? null;

            return $pre;
        }, 10, 2);
        wp_remote_request('https://api.example.test/generic');
        $this->assertSame('GET', $seen, 'The defaulted method reaches the pre_http_request mock, core\'s order (red at HEAD: null — the default never landed in $args).');
    }

    public function testIdenticalCronEntriesReplaceAndSinglesDedupeWithinTenMinutes()
    {
        /*
         * glm15-13: identical reschedules APPENDED where core's keyed
         * cron array REPLACES (the pair double-fired in one tick —
         * driven at HEAD), and singles lacked core's 10-minute
         * duplicate window (only the exact-timestamp spelling
         * deduped).
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        add_action('glm15_dup', static function () use (&$fires) {
            ++$fires;
        });
        wp_schedule_event(1700000060, 'hourly', 'glm15_dup');
        wp_schedule_event(1700000060, 'hourly', 'glm15_dup');

        $this->advanceTime(120);
        $this->assertSame(1, WpHarness::runDueEvents(), 'The identical recurring pair is ONE event (red at HEAD: appended, fired twice in one tick).');
        $this->assertSame(1, $fires);

        // Singles: an identical single within core's 10-minute window
        // dedupes — and the skip answers FALSE, core's own return
        // (glm16-7; red at HEAD: true).
        $this->assertTrue(wp_schedule_single_event(1700000300, 'glm15_single'));
        $this->assertFalse(wp_schedule_single_event(1700000600, 'glm15_single'), 'The duplicate single answers FALSE, core\'s own return (red at HEAD: true).');
        $this->assertCount(1, wp_get_scheduled_events('glm15_single'), 'An identical single within the 10-minute window dedupes (red at HEAD: two entries).');

        /*
         * glm17-5: the window is core's TWO-SIDED BAND on the NEW
         * event's timestamp (cron.php:135-145, pinned 7.1.1) — the
         * round-16 one-sided floor over the EXISTING single's age
         * answered the wrong shape both directions (driven): min = 0
         * whenever the new ts sits within ten minutes of now (every
         * PAST identical single counts, however old — the round-16
         * '10:01 old stacks' pin was wrong for near-future saves),
         * else ts - 10 min (a far-future single never dedupes against
         * a near-future existing one); max = now + 10 min for a past
         * new ts, else ts + 10 min.
         */
        $this->advanceTime(840); // now 1700000960; the existing single (ts 1700000300) is 660 seconds old — 11 minutes.
        // Near-future save, 11-minute-old existing single: min = 0
        // counts it — dedupes (red at HEAD: the floor stacked it).
        $this->assertFalse(wp_schedule_single_event(1700001020, 'glm15_single'), 'A near-future save dedupes against a past identical single of ANY age — min = 0 (red at HEAD: the 11-minute-old one stacked).');
        $this->assertCount(1, wp_get_scheduled_events('glm15_single'));

        // Far-future save, near-future existing single — the min = ts -
        // 10 min arm, its own hook so the old single never feeds it:
        // band schedules (red at HEAD: the floor deduped it).
        $this->assertTrue(wp_schedule_single_event(1700001260, 'glm17_far'), 'staging: the near-future existing single lands.');
        $this->assertTrue(wp_schedule_single_event(1700002460, 'glm17_far'), 'A single 20 minutes past a near-future existing one never dedupes against it — min = ts - 10 min excludes the existing ts (red at HEAD: the floor deduped it).');
        $this->assertCount(2, wp_get_scheduled_events('glm17_far'), 'Beyond the band the single is its own event.');

        // Future-near control: an existing single INSIDE the band
        // dedupes (the glm15-13 shape, band-invariant).
        $this->assertFalse(wp_schedule_single_event(1700002520, 'glm17_far'), 'A near-future existing single inside the band dedupes.');
        $this->assertCount(2, wp_get_scheduled_events('glm17_far'));
    }

    public function testTheGenericAddOptionHookFiresBeforeTheWriteCoreOrder()
    {
        /*
         * glm18-9: core fires the GENERIC 'add_option' action BEFORE
         * the INSERT (option.php:1140's do_action precedes :1142's
         * query, pinned 7.1.1) — an observer at the hook reads the row
         * through get_option() as core reads it, the OLD value (false
         * for a first add). The stub wrote first, so the observer read
         * the NEW value (driven); the specific and closing hooks stay
         * post-write, where their observers read the written row.
         */
        $seen_generic = 'never';
        add_action('add_option', static function ($option) use (&$seen_generic) {
            $seen_generic = get_option($option);
        });
        $seen_specific = 'never';
        add_action('add_option_glm18_order', static function ($option) use (&$seen_specific) {
            $seen_specific = get_option($option);
        });

        $this->assertTrue(add_option('glm18_order', 'v1'));
        $this->assertFalse($seen_generic, 'The generic-hook observer reads the OLD row — the write has not happened yet (red at HEAD: the new value).');
        $this->assertSame('v1', $seen_specific, 'The specific-hook observer reads the WRITTEN row, core\'s post-write order.');

        // The stored-false re-add rides the same order: the row still
        // reads false at the generic hook, and the write lands after.
        WpHarness::$options['glm18_order'] = false;
        $seen_generic = 'never';
        $this->assertTrue(add_option('glm18_order', 'v2'), 'staging: the stored-false re-add completes (glm17-4\'s shape).');
        $this->assertFalse($seen_generic, 'The generic-hook observer reads the stored-false row as core does at the pre-write hook.');
        $this->assertSame('v2', get_option('glm18_order'), 'The write lands after the generic hook.');
    }

    public function testAMutatedStoredObjectReSaveCompletesThroughTheHeadClone()
    {
        /*
         * glm18-8: core clones an object value at update_option()'s
         * head (option.php:882-884, pinned 7.1.1) — the harness stores
         * live references, so a caller mutating the object they saved
         * and re-saving it hit the $old === $value identity arm with
         * the SAME reference on both sides: false, zero hooks, where
         * core's detached copy completes with the full hook family
         * (driven at HEAD). The head clone detaches the stored row;
         * the unchanged re-save keeps core's silent false (the glm17-8
         * maybe_serialize arm, over detached copies now).
         */
        $obj = new stdClass();
        $obj->v = 1;
        $this->assertTrue(update_option('glm18_obj', $obj), 'staging: the first save persists the row.');

        $fired = array();
        add_action('update_option', static function () use (&$fired) {
            $fired[] = 'generic';
        });
        add_action('update_option_glm18_obj', static function () use (&$fired) {
            $fired[] = 'specific';
        });
        add_action('updated_option', static function () use (&$fired) {
            $fired[] = 'updated';
        });

        $obj->v = 2;
        $this->assertTrue(update_option('glm18_obj', $obj), 'The mutate-in-place re-save completes — the head clone detached the stored row from the caller\'s reference (red at HEAD: the identity arm saw the same reference, false).');
        $this->assertSame(array( 'generic', 'specific', 'updated' ), $fired, 'The full hook family fires for the re-save (red at HEAD: zero hooks).');
        $this->assertSame(2, get_option('glm18_obj')->v, 'The row carries the mutated value.');

        $fired = array();
        $this->assertFalse(update_option('glm18_obj', $obj), 'The UNCHANGED re-save keeps core\'s silent false — maybe_serialize equality over the detached copies.');
        $this->assertSame(array(), $fired, 'No hook fires for the unchanged re-save.');
    }

    public function testADirectAddDetachesTheStoredRowToo()
    {
        /*
         * glm19-4: core clones at BOTH heads (option.php:1108-1110
         * beside glm18-8's :882-884, pinned 7.1.1) — the stub's
         * direct-add path stored the caller's live reference, so a
         * mutate-in-place re-save through update_option() compared the
         * caller's reference against itself-as-stored and answered
         * false with ZERO hooks where core's add-time detached copy
         * completes with the full family (driven — glm18-8's exact
         * class through the other entry point).
         */
        $obj = new stdClass();
        $obj->v = 1;
        $this->assertTrue(add_option('glm19_add_obj', $obj), 'staging: the direct add persists the row.');

        $fired = array();
        add_action('update_option', static function () use (&$fired) {
            $fired[] = 'generic';
        });
        add_action('update_option_glm19_add_obj', static function () use (&$fired) {
            $fired[] = 'specific';
        });
        add_action('updated_option', static function () use (&$fired) {
            $fired[] = 'updated';
        });

        $obj->v = 2;
        $this->assertTrue(update_option('glm19_add_obj', $obj), 'The direct-add mutate-in-place re-save completes — the stored row detached at the add head too (red at HEAD: the stored live reference made the unchanged compare true — false, zero hooks).');
        $this->assertSame(array( 'generic', 'specific', 'updated' ), $fired, 'The full hook family fires for the re-save (red at HEAD: zero hooks).');
        $this->assertSame(2, get_option('glm19_add_obj')->v, 'The row carries the mutated value.');
    }

    public function testANestedMutationReachesNothingTheStoredRowIsSerializedEqual()
    {
        /*
         * glm19-5: the head clone is SHALLOW (core's own spelling at
         * both heads) but core's row is serialized BYTES at the
         * database layer — the stored copy shares NO nested reference
         * with the caller. The harness's shallow clone kept nested
         * objects shared: a nested mutation reached the stored row,
         * the re-save compared serialize-equal, and the save answered
         * false with ZERO hooks where core's serialized row completes
         * with the full family (driven).
         */
        $obj = new stdClass();
        $obj->nested = new stdClass();
        $obj->nested->v = 1;
        $this->assertTrue(update_option('glm19_nested_obj', $obj), 'staging: the first save persists the row.');

        $fired = array();
        add_action('update_option', static function () use (&$fired) {
            $fired[] = 'generic';
        });
        add_action('update_option_glm19_nested_obj', static function () use (&$fired) {
            $fired[] = 'specific';
        });
        add_action('updated_option', static function () use (&$fired) {
            $fired[] = 'updated';
        });

        $obj->nested->v = 2;
        $this->assertTrue(update_option('glm19_nested_obj', $obj), 'The nested-mutation re-save completes — the stored row is serialized-equal, never shared (red at HEAD: the shallow clone kept the nested object shared — serialize-equal, false, zero hooks).');
        $this->assertSame(array( 'generic', 'specific', 'updated' ), $fired, 'The full hook family fires for the re-save (red at HEAD: zero hooks).');
        $this->assertSame(2, get_option('glm19_nested_obj')->nested->v, 'The row carries the mutated value.');

        // The stored row is the harness's own copy: the caller's later
        // nested mutations reach nothing, and the UNCHANGED re-save
        // keeps core's silent false over the detached pair.
        $obj->nested->v = 3;
        $this->assertSame(2, get_option('glm19_nested_obj')->nested->v, 'The stored nested object is detached — the caller\'s later mutations reach nothing.');
        $this->assertTrue(update_option('glm19_nested_obj', $obj), 'The now-differing re-save completes — serialized-equality over detached copies decides.');
        $this->assertSame(3, get_option('glm19_nested_obj')->nested->v);
        $fired = array();
        $this->assertFalse(update_option('glm19_nested_obj', $obj), 'The UNCHANGED re-save keeps core\'s silent false.');
        $this->assertSame(array(), $fired, 'No hook fires for the unchanged re-save.');
    }

    public function testNonFiniteTimestampsRefuseAtTheScheduleGuard()
    {
        /*
         * glm19-7: INF and NAN pass the raw-value guard — is_numeric
         * answers true for both, neither compares <= 0 — and the
         * harness queued zombies: the raw INF NEVER FIRED (INF > now
         * forever) while core's key truncation lands the row at key
         * 0, firing every pass, never the claimed time (driven at
         * HEAD: queued). No honest schedule exists for either — the
         * guard refuses non-finite values.
         */
        $this->freezeTime(1700000000);

        $this->assertFalse(wp_schedule_single_event(INF, 'glm19_inf'), 'INF refuses — no honest schedule exists (red at HEAD: queued, a never-firing zombie).');
        $this->assertFalse(wp_schedule_single_event(NAN, 'glm19_nan'), 'NAN refuses likewise (red at HEAD: queued).');
        $this->assertSame(array(), wp_get_scheduled_events('glm19_inf'), 'Nothing lands for either spelling.');
        $this->assertSame(array(), wp_get_scheduled_events('glm19_nan'), 'Nothing lands for NAN either.');
        $this->assertSame(0, WpHarness::runDueEvents(), 'No due pass fires anything.');
    }

    public function testTheSingleHeadGuardJudgesTheRawValueNeverAPreCast()
    {
        /*
         * glm18-7: the single's standing (int) coercion rode AHEAD of
         * the glm17-14 guard, inverting core on both sides of the
         * 'Make sure timestamp is a positive integer' judge
         * (cron.php:48-60: '! is_numeric( $timestamp ) || $timestamp
         * <= 0' on the RAW value) — a true or a '60abc' coerced to a
         * positive integer and QUEUED where core refuses (is_numeric
         * answers false for both before any cast), and a 0.5 coerced
         * to 0 and REFUSED where core schedules the event.
         *
         * CORRECTED (glm19-6): the round-18 half of this pin — "core
         * schedules the event and keeps the fractional timestamp
         * downstream" — is FALSE against the pinned 7.1.1. Core's
         * guard passes the raw 0.5, but the row lands at
         * $crons[0.5], and a PHP array KEY truncates the float: key
         * 0. wp_next_scheduled() reconstructs from the key and its
         * '! $next' falsy guard (cron.php:825) answers FALSE for the
         * key-0 row — never 0.5 — and wp_unschedule_event()'s own key
         * fold still cancels it. The queued row rides the
         * int-truncated timestamp now, core's own key shape; the
         * stranded-cancellation shape dies with it.
         */
        $this->freezeTime(1700000000);

        $this->assertFalse(wp_schedule_single_event(true, 'glm18_raw'), 'A boolean true refuses — is_numeric answers false before any cast (red at HEAD: coerced to 1, queued).');
        $this->assertFalse(wp_schedule_single_event('60abc', 'glm18_raw'), 'A glued numeric string refuses likewise (red at HEAD: coerced to 60, queued).');
        $this->assertSame(array(), wp_get_scheduled_events('glm18_raw'), 'Nothing lands in the queue for either spelling (red at HEAD: two rows).');

        $this->assertTrue(wp_schedule_single_event(0.5, 'glm18_raw_half'), 'A fractional positive timestamp schedules — the raw-value guard passes it (red at the round-16 HEAD: coerced to 0, refused).');
        $queued = wp_get_scheduled_events('glm18_raw_half');
        $this->assertCount(1, $queued, 'staging: the fractional schedule lands one row.');
        $this->assertSame(0, $queued[0]['timestamp'], 'The row lands at the INT-TRUNCATED key — core\'s $crons[ts] key shape (red at the round-18 pin: the raw 0.5, glm18-7\'s fractional-acceptance premise falsified).');
        $this->assertSame(false, wp_next_scheduled('glm18_raw_half'), 'Core\'s falsy-key guard: a key-0 row is invisible to the next-event query — false, never the 0.5 the round-18 pin asserted.');
        $this->assertTrue(wp_unschedule_event(0.5, 'glm18_raw_half'), 'The key-0 row cancels through the int-folded compare — the stranded-cancellation shape dies (red at the round-18 shape: the raw 0.5 row never matched).');
        $this->assertSame(array(), wp_get_scheduled_events('glm18_raw_half'), 'The row is gone.');
    }

    public function testNonPositiveTimestampsRefuseAtBothScheduleEntryPoints()
    {
        /*
         * glm18-6: wp_schedule_event() lacked the head guard
         * glm17-14 pinned at the single entry point — core answers the
         * SAME 'Make sure timestamp is a positive integer' false at
         * BOTH heads (cron.php:48-60 and :252-263, pinned 7.1.1),
         * before anything is keyed. The stub queued a RECURRING event
         * at ts 0 or below: a due-now row that fired and re-armed
         * forever (driven) where core never keys the event at all.
         */
        $this->freezeTime(1700000000);

        $this->assertFalse(wp_schedule_event(0, 'hourly', 'glm18_neg'), 'ts=0 recurring refuses, core\'s own head guard (red at HEAD: queued, true).');
        $this->assertFalse(wp_schedule_event(-1, 'hourly', 'glm18_neg'), 'ts=-1 recurring refuses likewise (red at HEAD: queued).');
        $this->assertSame(array(), wp_get_scheduled_events('glm18_neg'), 'Nothing lands in the queue for either spelling (red at HEAD: two due-now rows).');
        $this->assertSame(0, WpHarness::runDueEvents(), 'No due pass fires anything.');

        // The single entry point keeps glm17-14's refusal.
        $this->assertFalse(wp_schedule_single_event(0, 'glm18_neg_single'));
        $this->assertFalse(wp_schedule_single_event(-1, 'glm18_neg_single'));
    }

    public function testCollidedReschedulesReplaceKeyedNeverAppend()
    {
        /*
         * glm18-5: the walk's reschedule write raw-APPENDED where
         * core's cron array keys the row ($crons[ts][hook][md5(args)],
         * cron.php:323, pinned 7.1.1) and REPLACES. Two due hourly
         * members of one recurrence one period apart both re-arm onto
         * the SAME grid timestamp — now + (interval − ((now − ts) %
         * interval)) collapses the modulo for ts values a whole period
         * apart — so the append left both rows standing and the pair
         * double-fired on every later pass forever (driven: pass 1
         * fired 2, pass 2 fired 2; core answers 1 row, 1 fire). The
         * unfinished half of glm15-13's keyed-replace class, closed at
         * the walk's own write.
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        add_action('glm18_grid', static function ($m) use (&$fires) {
            ++$fires;
        });
        $this->assertTrue(wp_schedule_event(1700000000 - 3600, 'hourly', 'glm18_grid', array( 'm' => 1 )), 'staging: the first collided member must schedule.');
        $this->assertTrue(wp_schedule_event(1700000000 - 7200, 'hourly', 'glm18_grid', array( 'm' => 1 )), 'staging: the second collided member must schedule (a whole period apart — its own keyed row).');

        $this->assertSame(2, WpHarness::runDueEvents(), 'Both due members fire on the collision pass — core fires both too.');
        $this->assertSame(2, $fires);
        $this->assertCount(1, wp_get_scheduled_events('glm18_grid'), 'The two grid-collided re-arms are ONE keyed row (red at HEAD: the append left two).');

        $this->advanceTime(3600);
        $this->assertSame(1, WpHarness::runDueEvents(), 'The next due pass fires the keyed row once (red at HEAD: the appended twin double-fired).');
        $this->assertSame(3, $fires);
    }

    public function testAStoredFalseRowCompletesCoreSOnDuplicateKeyUpdateShape()
    {
        /*
         * glm15-14: update_option()'s delegation predicate
         * (array_key_exists) diverged from core's routing — a row
         * STORED AS FALSE is indistinguishable from a missing row
         * through core's get_option() (both answer false), so core
         * routes the save to the ADD family while the harness routed
         * UPDATE (driven red at HEAD: update_option_ fired for a
         * stored-false save).
         *
         * glm16-4 then closed the seam with a COLLISION premise —
         * core's INSERT dies on the duplicate key, a silent no-op —
         * and glm17-4 FALSIFIES that premise against the pinned WP
         * 7.1.1 (pre-verified at the local reference): the INSERT
         * rides ON DUPLICATE KEY UPDATE (option.php:1142), and the
         * guard returns early only for a row that reads NON-false.
         * The stored-false add COMPLETES observably, exactly like a
         * first save: the ADD family fires, the row writes, true (the
         * famous false-stored footgun is that the save masquerades as
         * an add — never that it silently does nothing).
         */
        update_option('glm15_false_opt', 'initial');
        update_option('glm15_false_opt', false);

        $fired = array();
        add_action('update_option_glm15_false_opt', static function () use (&$fired) {
            $fired[] = 'update';
        });
        add_action('updated_option', static function () use (&$fired) {
            $fired[] = 'updated';
        });
        add_action('add_option_glm15_false_opt', static function () use (&$fired) {
            $fired[] = 'add';
        });
        add_action('added_option', static function () use (&$fired) {
            $fired[] = 'added';
        });

        $this->assertTrue(update_option('glm15_false_opt', 'value'), 'A stored-false save completes through the delegated ADD — hooks, write, true (red at HEAD: glm16-4\'s silent false).');
        $this->assertSame(array( 'add', 'added' ), $fired, 'The ADD family fires for the stored-false save (red at HEAD: no family fired).');
        $this->assertSame('value', get_option('glm15_false_opt'), 'The ON DUPLICATE KEY UPDATE shape writes the row (red at HEAD: nothing wrote).');

        // The direct call over the stored-false row: the same completion.
        WpHarness::$options['glm15_false_opt'] = false;
        $fired = array();
        $this->assertTrue(add_option('glm15_false_opt', 'again'), 'A direct add over a stored-false row completes too (red at HEAD: silent false).');
        $this->assertSame(array( 'add', 'added' ), $fired);
        $this->assertSame('again', get_option('glm15_false_opt'));

        // The stored NON-false duplicate keeps the early-return silence —
        // core's guard answer for a row that reads through get_option().
        WpHarness::$options['glm15_false_opt'] = 'rewritten';
        $fired = array();
        $this->assertFalse(add_option('glm15_false_opt', 'nope'), 'A direct add over a stored non-false row is core\'s silent no-op.');
        $this->assertSame(array(), $fired, 'The non-false duplicate fires nothing.');
        $this->assertSame('rewritten', get_option('glm15_false_opt'));

        // The control: a stored non-false value keeps the UPDATE routing.
        $fired = array();
        $this->assertTrue(update_option('glm15_false_opt', 'next'));
        $this->assertSame(array( 'update', 'updated' ), $fired, 'A stored non-false value keeps the UPDATE family.');
        $this->assertSame('next', get_option('glm15_false_opt'));
    }

    public function testASingleOverAnIdenticalRecurringEntryAnswersFalseAndTheRecurrenceSurvives()
    {
        /*
         * glm16-6 claimed the single's keyed write REPLACES an
         * identical-key recurring entry — and glm17-6 falsifies the
         * shape against the pinned core: the duplicate check core
         * runs BEFORE any keyed write is recurrence-BLIND
         * (isset($crons[ts][$hook][md5(args)]) — no recurrence term),
         * and a same-timestamp identical-key entry is ALWAYS inside
         * core's two-sided band (min <= ts <= max by construction),
         * so a single scheduled over an identical-key RECURRING entry
         * answers FALSE with the recurring row untouched: the single
         * never overwrites a recurrence core keeps (driven red at
         * HEAD: true, the recurring entry's interval stripped, the
         * reschedule dead).
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        add_action('glm16_over', static function () use (&$fires) {
            ++$fires;
        });
        wp_schedule_event(1700000060, 'hourly', 'glm16_over');
        $this->assertFalse(wp_schedule_single_event(1700000060, 'glm16_over'), 'The single over the identical-key recurring entry answers FALSE, core\'s duplicate skip (red at HEAD: true, the keyed replace).');

        // The recurring row survives untouched — its interval intact,
        // its fire once, its reschedule standing.
        $events = wp_get_scheduled_events('glm16_over');
        $this->assertCount(1, $events, 'No twin was written (red at HEAD: the pair as two entries).');
        $this->assertSame(3600, $events[0]['interval'], 'The recurring entry keeps its interval (red at HEAD: the interval stripped by the replace).');

        $this->advanceTime(120);
        $this->assertSame(1, WpHarness::runDueEvents());
        $this->assertSame(1, $fires);
        $this->assertSame(1700003660, wp_next_scheduled('glm16_over'), 'The recurrence rescheduled on its grid (red at HEAD: false — the replaced single left nothing to reschedule).');
    }

    public function testAnUnscheduledSnapshotMemberStillFiresCoreShape()
    {
        /*
         * glm16-8: runDueEvents() skipped a handler's
         * unschedule-then-reschedule of a not-yet-fired snapshot member
         * — the by-id relocation treated 'absent from the live list' as
         * 'suppressed' — where core's wp_cron() walks its captured copy
         * and fires UNCONDITIONALLY (the docblock had claimed the skip
         * as core's snapshot semantics; the claim was wrong). Driven:
         * the member fires once in THIS pass, the handler's reschedule
         * standing for the next.
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        add_action('glm16_victim', static function () use (&$fires) {
            ++$fires;
        });
        add_action('glm16_actor', static function () {
            // Fires first (earlier due): unschedules the victim and
            // reschedules it for the next window.
            wp_unschedule_event(1700000000, 'glm16_victim');
            wp_schedule_single_event(1700003600, 'glm16_victim');
        });
        wp_schedule_single_event(1700000000 - 60, 'glm16_actor');
        wp_schedule_single_event(1700000000, 'glm16_victim');

        $this->assertSame(2, WpHarness::runDueEvents(), 'Both snapshot members fire — the actor first, the victim STILL fires after its unschedule (red at HEAD: 1, the victim suppressed).');
        $this->assertSame(1, $fires, 'The victim fired exactly once in THIS pass (red at HEAD: suppressed, zero).');
        $this->assertSame(1700003600, wp_next_scheduled('glm16_victim'), 'The handler\'s reschedule stands for the next pass.');
    }

    public function testAMidWalkCancelledRecurringMemberStillReschedulesCoreShape()
    {
        /*
         * glm17-7: the recurring reschedule gated on the live
         * re-location — a handler that unscheduled a not-yet-fired
         * recurring member mid-walk left the re-arm dead (nothing to
         * re-locate), a permanently cancelled recurrence where core's
         * wp-cron.php reschedules UNCONDITIONALLY from the captured
         * copy before it even attempts the unschedule: the
         * cancellation stops the FIRE, never the RESCHEDULE (driven
         * red at HEAD: wp_next_scheduled answered false after the
         * pass).
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        add_action('glm17_recur', static function () use (&$fires) {
            ++$fires;
        });
        add_action('glm17_cancel_actor', static function () {
            // Fires first (earlier due): cancels the recurring victim.
            wp_unschedule_event(1700000000, 'glm17_recur');
        });
        wp_schedule_single_event(1700000000 - 60, 'glm17_cancel_actor');
        wp_schedule_event(1700000000, 'hourly', 'glm17_recur');

        $this->assertSame(2, WpHarness::runDueEvents(), 'Both snapshot members fire — the actor and the cancelled victim (glm16-8\'s own doctrine).');
        $this->assertSame(1, $fires);
        $this->assertSame(1700003600, wp_next_scheduled('glm17_recur'), 'The mid-walk-cancelled recurring member RESCHEDULED from the captured copy — the cancellation stopped the fire-target row, never the recurrence (red at HEAD: false, the re-arm gated on the failed re-location).');
    }

    public function testAnUnscheduleThenReAddOfTheIdenticalKeyEventFiresOnce()
    {
        /*
         * glm17-10: the walk's removal rode a synthetic per-entry id —
         * a handler's unschedule-then-re-add of the IDENTICAL-KEY
         * event (same timestamp, same args) wrote the key back under a
         * FRESH id, the by-id re-location read 'absent', nothing was
         * removed, and the re-added twin fired AGAIN on the next pass
         * (driven red at HEAD: the double fire). Core's cron array is
         * keyed [ts][hook][md5(args)] and wp-cron.php unschedules BY
         * THAT KEY, so the walk's own removal consumes the re-added
         * row — one fire across passes, the synthetic id deleted with
         * the spelling.
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        add_action('glm17_readd', static function () use (&$fires) {
            ++$fires;
        });
        add_action('glm17_readd_actor', static function () {
            // Fires first (earlier due): removes the victim and
            // re-adds the IDENTICAL-KEY event.
            wp_unschedule_event(1700000000, 'glm17_readd');
            wp_schedule_single_event(1700000000, 'glm17_readd');
        });
        wp_schedule_single_event(1700000000 - 60, 'glm17_readd_actor');
        wp_schedule_single_event(1700000000, 'glm17_readd');

        $this->assertSame(2, WpHarness::runDueEvents(), 'Both snapshot members fire — the actor and the victim (the fire rides the snapshot, glm16-8).');
        $this->assertSame(1, $fires, 'The victim fired once in THIS pass.');

        // The re-added twin was CONSUMED by the walk's key removal —
        // the next pass has nothing left to fire (red at HEAD: the
        // twin survived under its fresh id and fired again).
        $this->assertSame(array(), wp_get_scheduled_events('glm17_readd'), 'The re-added identical-key row was consumed by the walk\'s own keyed removal (red at HEAD: the twin standing).');
        $this->assertSame(0, WpHarness::runDueEvents(), 'The next pass fires nothing (red at HEAD: 1, the twin).');
        $this->assertSame(1, $fires, 'ONE fire across both passes (red at HEAD: 2, the double fire).');
    }

    public function testWpScheduleSingleEventRefusesNonPositiveTimestampsCoreGuard()
    {
        /*
         * glm17-14: core's head guard — 'Make sure timestamp is a
         * positive integer' (cron.php:48-60, pinned 7.1.1): a
         * timestamp at or below zero answers FALSE, never a queued
         * event. The stub queued both (driven red at HEAD: ts=0 and
         * ts=-1 answered true, two due-now entries).
         */
        $this->freezeTime(1700000000);

        $this->assertFalse(wp_schedule_single_event(0, 'glm17_ts'), 'ts=0 answers false, core\'s own guard (red at HEAD: true, queued).');
        $this->assertFalse(wp_schedule_single_event(-1, 'glm17_ts'), 'ts=-1 answers false likewise (red at HEAD: true, queued).');
        $this->assertSame(array(), wp_get_scheduled_events('glm17_ts'), 'Nothing queued for either spelling (red at HEAD: two due-now entries).');
    }

    public function testEqualValuedObjectArgsAreOneCronEventCoreDigest()
    {
        /*
         * glm16-12: the cron args comparisons rode PHP's identity
         * (===) where core's key is md5(serialize($args)) — two
         * equal-VALUED but non-identical object args answered
         * 'different' at every key site (the keyed replace, the
         * singles dedupe, wp_next_scheduled, wp_unschedule_event) and
         * stacked twin entries that double-fired in one tick where
         * core's keyed array answers one event (driven red at HEAD).
         */
        $this->freezeTime(1700000000);

        $fires = 0;
        add_action('glm16_digest', static function () use (&$fires) {
            ++$fires;
        });
        $make = static function () {
            return new ArrayObject(array( 'k' => 'v' ));
        };
        $first = $make();
        $second = $make();
        $this->assertNotSame($first, $second, 'staging: the pair must be two distinct instances — the identity compare this leg drives.');

        // The keyed replace answers ONE entry over the equal-valued
        // pair (red at HEAD: appended, double-fired).
        $this->assertTrue(wp_schedule_event(1700000300, 'hourly', 'glm16_digest', array( $first )));
        $this->assertTrue(wp_schedule_event(1700000300, 'hourly', 'glm16_digest', array( $second )));
        $this->advanceTime(400);
        $this->assertSame(1, WpHarness::runDueEvents(), 'The equal-valued pair is ONE event (red at HEAD: two entries, double-fired in one tick).');
        $this->assertSame(1, $fires);

        // wp_next_scheduled finds the rescheduled occurrence by VALUE.
        $next = wp_next_scheduled('glm16_digest', array( $second ));
        $this->assertNotFalse($next, 'wp_next_scheduled answers over the equal-valued args (red at HEAD: false — identity missed the entry).');

        // And the unschedule with a third equal-valued instance removes it.
        $this->assertTrue(wp_unschedule_event($next, 'glm16_digest', array( $make() )), 'The digest-keyed unschedule removes the entry over any equal-valued spelling (red at HEAD: false).');
        $this->assertFalse(wp_next_scheduled('glm16_digest', array( $first )));

        // The singles dedupe rides the same digest: an equal-valued
        // twin within core's window answers FALSE.
        $this->assertTrue(wp_schedule_single_event(1700000500, 'glm16_single_val', array( $make() )));
        $this->assertFalse(wp_schedule_single_event(1700000600, 'glm16_single_val', array( $make() )), 'The duplicate single is recognized over the digest (red at HEAD: appended as distinct).');
        $this->assertCount(1, wp_get_scheduled_events('glm16_single_val'));
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

    /**
     * OCR-round-10 pin (t31-ocr10-5): the shared zip reader's open gate
     * is `=== true`, never assertTrue() — ZipArchive::open() returns a
     * TRUTHY ER_* int on failure (driven: a corrupt archive returns
     * ER_NOZIP=19), so the boolean gate passed it and the helper handed
     * back [] over numFiles=0, every entry assertion vacuously green.
     * A corrupt zip fails the gate loudly, naming the ER_* code.
     */
    public function testTheZipEntryNamesOpenGateFailsLoudlyOnACorruptArchive()
    {
        // Random-suffixed scratch name (t31-ocr12-8, the canSymlink
        // probe's own shape): a pid-only suffix is enumerable on a
        // shared host, and a pre-planted file at the predicted path is
        // read as "the corrupt archive" — a neighbor's plant steering
        // this leg's fixture (the naming shape t31-ocr10-18 rejects).
        $corrupt = sys_get_temp_dir() . '/wpct-corrupt-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            // The staging write asserts its own success (t31-ocr29-10,
            // the t31-ocr27-9 doctrine) and rides INSIDE the try whose
            // finally unlinks it (t31-ocr55-7, the staging-inside-try
            // sweep): the write once sat before the try, so a partial
            // write's staging assert stranded the scratch zip in the
            // shared temp root with no finally in scope — the finally
            // owns every exit from the first write on.
            $this->assertNotFalse(
                file_put_contents($corrupt, 'this is not a zip archive'),
                'staging: the corrupt archive must write — a staging failure fails as staging, never as the ER_NOZIP verdict (an unwritten scratch file answers ER_NOENT instead).'
            );

            // The refusal-verdict owner (t31-ocr15-7): the hand-rolled
            // $caught=null/try/catch/fail-if-null shape was this helper's
            // own inline twin — the family its original catch declared
            // rides the third parameter.
            $caught = $this->refusalOf(
                fn() => $this->zipEntryNames($corrupt),
                'A corrupt zip must fail the open gate loudly — pre-fix the truthy ER_NOZIP passed assertTrue() and the helper returned [].',
                PHPUnit\Framework\AssertionFailedError::class
            );
            $this->assertStringContainsString('ER_NOZIP', $caught->getMessage(), 'The failure names the ER_* code.');
            $this->assertStringContainsString($corrupt, $caught->getMessage(), 'The failure names the archive path.');
        } finally {
            @unlink($corrupt);
        }
    }

    /**
     * OCR-round-19 pin (t31-ocr19-1; the finding's floor premise was
     * REFUTED in-round — driven on a real 8.2.33/libzip engine, the
     * omitted-flags open() returns ER_NOENT on a missing file and
     * creates nothing, and the php-src record knows no 8.3 default
     * change, so the strict gate was already loud everywhere): the
     * leg pins the CONTRACT the reader stands on — a missing zip
     * fails the gate loudly naming the path and the ER_* code, and
     * the read never creates the file. The explicit RDONLY flag
     * rides the open as the read site's own statement of intent.
     */
    public function testTheZipEntryNamesOpenGateFailsLoudlyOnAMissingArchive()
    {
        // Random-suffixed scratch name (t31-ocr12-8): a predictable
        // spelling is pre-plantable on a shared host, and the planted
        // file would turn "missing" into "corrupt" under this leg.
        $missing = sys_get_temp_dir() . '/wpct-missing-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $caught = $this->refusalOf(
                fn() => $this->zipEntryNames($missing),
                'A missing zip must fail the open gate loudly — a vacuous [] over a missing archive is the exact shape the strict gate exists to make impossible.',
                PHPUnit\Framework\AssertionFailedError::class
            );
            $this->assertStringContainsString('ER_NOENT', $caught->getMessage(), 'The failure names the ER_* code of an absent archive.');
            $this->assertStringContainsString($missing, $caught->getMessage(), 'The failure names the archive path.');

            /*
             * Judged BEFORE the finally's unlink (OCR round 22,
             * t31-ocr22-7): post-try the pin was unfalsifiable — the
             * unlink had already removed whatever a creating helper
             * left behind, so a zipEntryNames() that created the
             * archive passed the never-created pin invisible. The
             * verdict rides the state the open actually left, with
             * the cleanup still guaranteed on every exit path.
             */
            $this->assertFileDoesNotExist($missing, 'The read-mode open never creates the archive — the pinned intent RDONLY states, held on every engine in range.');
        } finally {
            @unlink($missing);
        }
    }

    /**
     * OCR-round-21 pin (t31-ocr21-1; the round's floor premise
     * REFUTED by the verifier pass — corrected in-round): the
     * finding claimed an 8.3-cycle-only registration; the vendor
     * record reads the opposite (php.net: available as of PHP 7.4.3
     * / PECL zip 1.17.1 when the zip extension is built against
     * libzip >= 1.0.0; the php-src PHP-8.2 stub registers it
     * identically; the repo's own r19 drive ran the bare-constant
     * reader on a real 8.2.33 engine to ER_NOENT, never \Error —
     * no PHP-8.2 engine fatals). What survives is the BUILD corner
     * the vendor record does name: a zip extension built against
     * libzip < 1.0.0 compiles no RDONLY, and the bare constant
     * fatals exactly those engines — the guarded spelling rides the
     * flag where defined, 0 on that corner, both stylistic per the
     * r19 refutation (omitted flags never create-on-open on any
     * support-range engine) and both loud through the strict open
     * gate.
     *
     * The undefined arm cannot be DRIVEN on an engine that defines
     * the constant (the verifier's four escape attempts all die —
     * namespaced shadowing leaves the global spelling defined,
     * class_alias fatals, disable_classes cannot touch extension
     * classes, and no runkit/uopz rides this build), so the pin is
     * the SHAPE: the reader's source carries the guarded expression
     * verbatim, and the defined arm is what the two legs above
     * drive on this engine (every open they take passes through
     * the guard). Re-open rule: an engine whose zip extension is
     * built against libzip < 1.0.0 (never "a PHP 8.2 engine") —
     * the reader must open real archives there, never \Error.
     */
    public function testTheZipReaderRidesTheGuardedRdonlySpelling()
    {
        $source = (string) file_get_contents(__DIR__ . '/harness/WpConnectorsTestCase.php');
        /*
         * Whitespace-normalized before comparing (OCR round 29,
         * t31-ocr29-10): the pin once matched the harness source
         * VERBATIM, so any mechanical reformat — a line wrap, a
         * spacing change — reddened it with no behavioral defect,
         * the source-shape pin brittle to the layout it never owned.
         * The pin owns the guarded spelling's TOKENS. Since OCR
         * round 60 (t31-ocr60-7) the tolerance rides the \s*-class
         * PATTERN this repo's layout-tolerant pins already own (the
         * BuildArtifactsTest t31-ocr16-15d idiom), never the
         * collapse-to-one-space half measure: the collapse kept ONE
         * space per run, so the zero-space needle failed against
         * 'defined( 'ZipArchive::RDONLY' )' — this repo's own
         * prevailing style (bootstrap.php writes 'is_dir(
         * $shared_src )') — and a mechanical formatting pass would
         * have reddened the pin over a behaviorally-neutral
         * reformat, the exact brittleness t31-ocr29-10 set out to
         * retire. The \s* classes tolerate ANY spacing (zero, one,
         * a wrap) while a reorder, a rename, or a dropped guard
         * still reddens; an aborted match answers 0 and the
         * preg_match pin fails loud — never a vacuous pass.
         */
        $guarded = "/defined\(\s*'ZipArchive::RDONLY'\s*\)\s*\?\s*ZipArchive::RDONLY\s*:\s*0/";
        $this->assertSame(
            1,
            preg_match($guarded, $source),
            'The zip reader must spell the open flag through the guard: RDONLY where the engine defines it, 0 on the libzip < 1.0.0 build corner — the bare constant fatals exactly those engines, no PHP version boundary (t31-ocr21-1; whitespace-insensitive per t31-ocr29-10, any spacing per t31-ocr60-7).'
        );
        // Construction-evident, the respaced leg: the repo-style
        // spelling matches the same pattern the real source does.
        $this->assertSame(
            1,
            preg_match($guarded, "defined( 'ZipArchive::RDONLY' ) ? ZipArchive::RDONLY : 0"),
            'The pin tolerates the repo\'s own prevailing spacing — a mechanical reformat never reddens it (red at HEAD: the collapsed haystack kept one space per run and the zero-space needle failed this spelling).'
        );
    }

    /**
     * OCR-round-65 pin (t31-ocr65-4): the bootstrap's Shared-fixture
     * guard covers the PARTIAL checkout. The t31-ocr57-5 guard
     * modeled two states — shared/src fully absent, fully present —
     * but a sparse checkout or a mid-rebase worktree carries the
     * DIRECTORY with one contract file missing: the autoloader
     * registers, the unconditional requires run, and the fixture's
     * implements clause resolves its interface at LOAD time through
     * an autoloader that maps it to exactly the missing file — a
     * whole-suite BOOTSTRAP fatal (every connector suite down) over
     * the one checkout shape between the guard's two states. The
     * requires gate on the INTERFACE FILES now (bootstrap.php's own
     * census); this sim drives the REAL bootstrap in a child over a
     * scratch checkout whose shared/src is copied MINUS
     * Clock/ClockInterface.php — vendor/bin/harness symlinked to
     * the real tree (the sim never copies the vendored SDK), the
     * bootstrap itself copied as a real file so __DIR__ resolves
     * INSIDE the scratch, no connectors/ directory (the glob's own
     * empty answer). The probe script reads the degradation shape
     * from WITHIN the booted engine: the missing piece's fixture
     * class simply ABSENT (its require skipped — the per-test
     * missing-fixture shape the guard documents) while the PRESENT
     * half still loads through its own interface — per-file
     * degradation, never all-or-nothing, never the fatal (red at
     * HEAD: 'Interface ClockInterface not found', exit 255,
     * driven).
     */
    public function testTheBootstrapSurvivesAPartialSharedCheckout()
    {
        if (! WpHarness::canSpawnChildren()) {
            $this->markTestSkipped('This host cannot spawn child processes (exec/escapeshellarg in disable_functions) — the partial-checkout sim runs the real bootstrap in a child engine.');
        }
        if (! WpHarness::canSymlink()) {
            $this->markTestSkipped('This host cannot create symlinks — the sim links vendor/bin/harness into the scratch checkout instead of copying the vendored SDK.');
        }
        $repo = dirname(__DIR__);
        $scratch = sys_get_temp_dir() . '/wpct-bootstrap-partial-' . uniqid('', true);
        try {
            /*
             * The staging asserts its own landing (the t31-ocr53-9
             * doctrine): a failed stage fails as staging, never as
             * the boot verdict the child exists to answer.
             */
            $this->assertTrue(mkdir($scratch . '/tests', 0755, true), "staging: the scratch tests tree must create — a staging failure fails as staging, never as the boot verdict.");
            $this->assertTrue(symlink($repo . '/vendor', $scratch . '/vendor'), "staging: the vendored SDK must link into the scratch — a staging failure fails as staging, never as the boot verdict.");
            $this->assertTrue(symlink($repo . '/bin', $scratch . '/bin'), "staging: bin/ must link into the scratch (the bootstrap requires plugin-tools.php through it) — a staging failure fails as staging, never as the boot verdict.");
            $this->assertTrue(symlink($repo . '/tests/harness', $scratch . '/tests/harness'), "staging: harness/ must link into the scratch — a staging failure fails as staging, never as the boot verdict.");
            $this->assertTrue(copy($repo . '/tests/bootstrap.php', $scratch . '/tests/bootstrap.php'), "staging: the bootstrap must copy as a real file (a symlinked copy would resolve __DIR__ back into the live tree) — a staging failure fails as staging, never as the boot verdict.");
            // The PARTIAL shared/src: the real tree's files, minus
            // exactly the one interface the Clock fixture
            // implements (copyTree is files-only — the shape's own
            // owner, no link ever rides the real tree).
            WpHarness::copyTree($repo . '/shared/src', $scratch . '/shared/src');
            $this->assertFileExists($scratch . '/shared/src/Clock/ClockInterface.php', 'staging: the interface copy must land before the partial shape removes it — a failed copy is staging, never the boot verdict.');
            $this->assertTrue(unlink($scratch . '/shared/src/Clock/ClockInterface.php'), 'staging: the interface file must remove — the partial-checkout shape is the staging this leg pins, and a failed removal answers as staging.');
            $this->assertNotFalse(file_put_contents($scratch . '/tests/probe.php', '<?php
require __DIR__ . "/bootstrap.php";
echo class_exists("DeterministicClock") ? "CLOCK-PRESENT\n" : "CLOCK-ABSENT\n";
echo class_exists("InMemoryTokenStorage") ? "TOKEN-PRESENT\n" : "TOKEN-ABSENT\n";
'), 'staging: the probe script must write — a staging failure fails as staging, never as the boot verdict.');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scratch . '/tests/probe.php') . ' 2>&1', $output, $exit);
            $rendered = implode("\n", $output);
            $this->assertSame(0, $exit, "A PARTIAL shared/src boots the bootstrap clean — the missing interface degrades per-test, never a whole-suite bootstrap fatal (red at HEAD: 'Interface ClockInterface not found'). The child said: {$rendered}");
            $this->assertStringContainsString('CLOCK-ABSENT', $rendered, 'The missing piece\'s fixture class is absent — its require skipped, the documented per-test missing-fixture shape for exactly the missing piece.');
            $this->assertStringContainsString('TOKEN-PRESENT', $rendered, 'The PRESENT half still loads through its own present interface — the degradation is per-file, never all-or-nothing.');
        } finally {
            WpHarness::releaseScratch($scratch);
        }
    }
}
