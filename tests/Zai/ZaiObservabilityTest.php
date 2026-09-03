<?php
/**
 * Task 1.8 — observability and admin links tests.
 *
 * Proves logging is off by default, logs only method + redacted URL +
 * status + duration, can never capture credentials/prompt bodies/schemas/
 * response bodies, and that the plugin row exposes a Settings link.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use Deicod\WpConnectors\Zai\Plugin;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Settings\DebugSettings;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Support\DebugLogger;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

final class ZaiObservabilityTest extends WpConnectorsTestCase
{
    /**
     * Wired model instance.
     *
     * @return \Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel
     */
    private function model()
    {
        $this->primeZaiDiscoveryTransient();
        $model = ZaiProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        return $model;
    }

    /**
     * @return list<Message>
     */
    private function prompt()
    {
        return array(new Message(MessageRoleEnum::user(), array(new MessagePart('top secret prompt body'))));
    }

    /*
     * Off by default.
     */

    public function testLoggingIsOffByDefault()
    {
        $this->assertFalse(DebugLogger::enabled());
        $this->assertSame(array(), DebugLogger::entries());
    }

    public function testDisabledLoggerRecordsNothingForRealRequests()
    {
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiChatCompletionBody('ok', 'glm-5.3'));

        $this->model()->generateTextResult($this->prompt());

        $this->assertSame(array(), DebugLogger::entries());
        $this->assertFalse(get_option(DebugLogger::OPTION_LOG, false), 'No log option may be written while disabled.');
    }

    /*
     * Enabled logging: exact field set, redaction by construction.
     */

    public function testEnabledLoggerRecordsMethodUrlStatusDurationOnly()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiChatCompletionBody('ok', 'glm-5.3'));

        $key = FakeSecrets::apiKey();
        $this->model()->generateTextResult($this->prompt());

        $entries = DebugLogger::entries();
        $this->assertCount(1, $entries);

        $entry = $entries[0];
        $this->assertSame(
            array('method', 'url', 'status', 'duration_ms', 'at'),
            array_keys($entry),
            'Entries carry exactly method/url/status/duration/at — nothing else exists in the API shape.'
        );
        $this->assertSame('POST', $entry['method']);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4/chat/completions', $entry['url']);
        $this->assertSame(200, $entry['status']);
        $this->assertIsFloat($entry['duration_ms']);
        $this->assertGreaterThanOrEqual(0.0, $entry['duration_ms']);

        // Never the prompt, the credential, or the response body.
        $serialized = wp_json_encode($entry);
        $this->assertStringNotContainsString('top secret prompt body', $serialized);
        $this->assertRedacted($serialized, $key);
        $this->assertStringNotContainsString('Bearer', $serialized);
        $this->assertStringNotContainsString('"ok"', $serialized);
    }

    public function testQueryStringSecretsAreStrippedFromLoggedUrls()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');
        $secret = FakeSecrets::apiKey();

        DebugLogger::log('GET', 'https://api.z.ai/api/paas/v4/models?api_key=' . rawurlencode($secret) . '&x=1', 200, 2.5);

        $entry = DebugLogger::entries()[0];
        $this->assertSame('https://api.z.ai/api/paas/v4/models', $entry['url']);
        $this->assertRedacted(wp_json_encode($entry), $secret);
    }

    public function testFailedRequestsAreLoggedWithStatus()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');
        $this->queueSdkResponse(401, array(), HttpResponseFactory::openAiErrorBody('nope'));

        try {
            $this->model()->generateTextResult($this->prompt());
        } catch (Exception $e) {
            // Expected; logging must not change the thrown exception.
        }

        $entries = DebugLogger::entries();
        $entry = $entries[count($entries) - 1];
        $this->assertSame(401, $entry['status']);
        $this->assertStringNotContainsString('nope', wp_json_encode($entry), 'Response bodies are never logged.');
    }

    public function testTransportFailuresAreLoggedWithStatusZero()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');
        $this->allowUnmockedHttp = true;

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('Expected a transport exception.');
        } catch (Exception $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
        }

        $entries = DebugLogger::entries();
        $entry = $entries[count($entries) - 1];
        $this->assertSame(DebugLogger::STATUS_TRANSPORT_ERROR, $entry['status']);
    }

    public function testRingBufferIsBounded()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');

        for ($i = 0; $i < DebugLogger::MAX_ENTRIES + 10; ++$i) {
            DebugLogger::log('GET', 'https://api.z.ai/api/paas/v4/models', 200, 1.0);
        }

        $entries = DebugLogger::entries();
        $this->assertCount(DebugLogger::MAX_ENTRIES, $entries);
    }

    /*
     * Settings surface.
     */

    public function testDebugOptionSanitizesToBooleanStrings()
    {
        $this->assertSame('1', DebugSettings::sanitize_enabled('1'));
        $this->assertSame('0', DebugSettings::sanitize_enabled('0'));
        $this->assertSame('0', DebugSettings::sanitize_enabled('yes'));
        $this->assertSame('0', DebugSettings::sanitize_enabled(array('1')));
        $this->assertSame('0', DebugSettings::sanitize_enabled(null));
    }

    public function testDisablingDebugClearsTheLog()
    {
        $this->loadPlugin(__DIR__ . '/../../connectors/zai/zai.php', '\Deicod\WpConnectors\Zai\boot');

        update_option(DebugLogger::OPTION_ENABLED, '1');
        DebugLogger::log('GET', 'https://api.z.ai/api/paas/v4/models', 200, 1.0);
        $this->assertNotSame(array(), DebugLogger::entries());

        do_action('update_option_' . DebugLogger::OPTION_ENABLED, '1', '0');

        $this->assertSame(array(), DebugLogger::entries());
    }

    public function testEnablingDebugDoesNotClearTheLog()
    {
        $this->loadPlugin(__DIR__ . '/../../connectors/zai/zai.php', '\Deicod\WpConnectors\Zai\boot');

        update_option(DebugLogger::OPTION_ENABLED, '1');
        DebugLogger::log('GET', 'https://api.z.ai/api/paas/v4/models', 200, 1.0);

        do_action('update_option_' . DebugLogger::OPTION_ENABLED, '0', '1');

        $this->assertCount(1, DebugLogger::entries());
    }

    public function testEverySettingsFieldAttachesToARegisteredSection()
    {
        // Codex R6 #6: the debug field was attached to the old option-group
        // section id, which no section registers — do_settings_sections()
        // renders only fields of registered sections, so the checkbox had
        // silently disappeared from Settings → z.ai.
        $this->loadPlugin(__DIR__ . '/../../connectors/zai/zai.php', '\Deicod\WpConnectors\Zai\boot');
        $this->asAdministrator();
        do_action('admin_menu');

        $sections = WpHarness::$settings_sections[PlanRegionSettings::PAGE_SLUG] ?? array();
        $this->assertContains(PlanRegionSettings::SECTION_ID, $sections, 'The zai section must be registered.');
        $this->assertContains(Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::SECTION_ID, $sections, 'The zai_anthropic section must be registered.');

        $fields = WpHarness::$settings_fields[PlanRegionSettings::PAGE_SLUG] ?? array();
        $this->assertNotEmpty($fields, 'Fields must be registered for the settings page.');

        foreach ($fields as $sectionId => $fieldIds) {
            $this->assertContains(
                $sectionId,
                $sections,
                "Section {$sectionId} has fields but is never registered — do_settings_sections() would not render them."
            );
        }

        // The one shared debug toggle renders on the zai provider's section.
        $this->assertContains(
            DebugLogger::OPTION_ENABLED,
            $fields[PlanRegionSettings::SECTION_ID] ?? array(),
            'The debug field must render on the registered zai section.'
        );
    }

    public function testDebugOptionIsRegisteredWithTheSettingsApi()
    {
        $this->loadPlugin(__DIR__ . '/../../connectors/zai/zai.php', '\Deicod\WpConnectors\Zai\boot');
        do_action('admin_init');

        $setting = get_registered_settings()[DebugLogger::OPTION_ENABLED] ?? null;
        $this->assertNotNull($setting);
        $this->assertSame('zai_connector', $setting['group']);
        $this->assertSame('0', $setting['default']);
        $this->assertFalse($setting['show_in_rest']);
    }

    public function testLogViewerEscapesEntriesAndRequiresCapability()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');
        DebugLogger::log('GET', 'https://api.z.ai/api/paas/v4/models"><script>alert(1)</script>', 200, 1.0);

        $this->asAdministrator();
        ob_start();
        DebugSettings::render_log();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Recent z.ai requests', $output);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);

        $this->asAnonymous();
        ob_start();
        DebugSettings::render_log();
        $this->assertSame('', (string) ob_get_clean());
    }

    /*
     * Plugin-row Settings link.
     */

    public function testPluginRowGainsASettingsLink()
    {
        $links = Plugin::action_links(array('<a href="x">Deactivate</a>'));

        $this->assertCount(2, $links);
        $this->assertStringContainsString('options-general.php?page=zai-connector', $links[0]);
        $this->assertStringContainsString('Settings', $links[0]);
    }

    public function testAvailabilityAndDirectoryRequestsAreLoggedToo()
    {
        update_option(DebugLogger::OPTION_ENABLED, '1');

        $availability = new Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability();
        $availability->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue($availability->isConfigured());

        $directory = new Deicod\WpConnectors\Zai\Metadata\ZaiModelMetadataDirectory();
        $directory->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $directory->listModelMetadata();

        $statuses = array_map(static function (array $entry) {
            return $entry['method'] . ' ' . $entry['status'];
        }, DebugLogger::entries());
        $this->assertSame(array('GET 200', 'GET 200'), $statuses);
    }
    public function testTheWrapHelperIsIdempotent()
    {
        /*
         * GLM6 #13: the idempotent wrap rule (install the debug logger,
         * never double-wrap) lived copy-pasted in five setHttpTransporter()
         * overrides; the one shared helper owns it now. This pins the two
         * behaviors every override relies on: a plain transporter is
         * wrapped, the decorator itself passes through unchanged.
         */
        $plain = AiClient::defaultRegistry()->getHttpTransporter();

        $wrapped = LoggingHttpTransporter::wrap($plain);
        $this->assertInstanceOf(LoggingHttpTransporter::class, $wrapped, 'A plain transporter is wrapped.');

        $this->assertSame($wrapped, LoggingHttpTransporter::wrap($wrapped), 'The decorator itself is never double-wrapped.');
    }
}
