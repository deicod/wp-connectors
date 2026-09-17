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
        file_put_contents($corrupt, 'this is not a zip archive');
        try {
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
        } finally {
            @unlink($missing);
        }
        $this->assertFileDoesNotExist($missing, 'The read-mode open never creates the archive — the pinned intent RDONLY states, held on every engine in range.');
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
        $this->assertStringContainsString(
            "defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0",
            $source,
            'The zip reader must spell the open flag through the guard: RDONLY where the engine defines it, 0 on the libzip < 1.0.0 build corner — the bare constant fatals exactly those engines, no PHP version boundary (t31-ocr21-1).'
        );
    }
}
