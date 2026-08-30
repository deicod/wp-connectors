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
