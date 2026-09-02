<?php
/**
 * Task 1.6 — chat-completions request mapping tests.
 *
 * Request snapshots (minimal, conversation, structured output, tool,
 * multimodal-text) are committed under tests/fixtures/snapshots/zai/ with
 * credentials excluded by construction: only the URL and JSON body are
 * stored, never headers. Pre-transport rejection of unsupported
 * option/model combinations is proven by the absence of any HTTP attempt.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;

final class ZaiRequestMappingTest extends WpConnectorsTestCase
{
    /**
     * Model instance wired to the harness transport with a fixture key.
     *
     * @param ModelConfig|null $config Optional model configuration.
     * @return \Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel
     */
    private function model(?ModelConfig $config = null)
    {
        $this->primeZaiDiscoveryTransient();
        $model = ZaiProvider::model('glm-5.3', $config);
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        return $model;
    }

    /**
     * Runs one generation and returns [url, decodedBody, headers] of the
     * single recorded request.
     *
     * @param list<Message>                                       $prompt
     * @param \Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel $model
     * @return array{0: string, 1: array, 2: array}
     */
    private function captureRequest(array $prompt, $model): array
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::openAiChatCompletionBody('ok', 'glm-5.3'));
        $model->generateTextResult($prompt);

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
     * @param string $name  Snapshot name.
     * @param string $url   Request URL.
     * @param array  $body  Decoded request body.
     * @return void
     */
    private function assertMatchesSnapshot(string $name, string $url, array $body)
    {
        $path = __DIR__ . '/../fixtures/snapshots/zai/' . $name . '.json';
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

    /*
     * Snapshots.
     */

    public function testMinimalRequestSnapshot()
    {
        list($url, $body, $headers) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('Say hi.')))),
            $this->model()
        );

        $this->assertSame('https://api.z.ai/api/coding/paas/v4/chat/completions', $url);
        $this->assertSame('glm-5.3', $body['model']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertSame(array(array('type' => 'text', 'text' => 'Say hi.')), $body['messages'][0]['content']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertArrayNotHasKey('stream', $body);

        // The live request carries the Bearer credential...
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertMatchesSnapshot('minimal', $url, $body);
    }

    public function testConversationRequestSnapshot()
    {
        $config = ModelConfig::fromArray(array(
            'systemInstruction' => 'You are terse.',
            'maxTokens' => 128,
            'temperature' => 0.2,
            'topP' => 0.9,
            'stopSequences' => array('END'),
        ));

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Hello.'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart('Hi!'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Bye.'))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model($config));

        $this->assertSame(array(
            array('role' => 'system', 'content' => array(array('type' => 'text', 'text' => 'You are terse.'))),
            array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => 'Hello.'))),
            array('role' => 'assistant', 'content' => array(array('type' => 'text', 'text' => 'Hi!'))),
            array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => 'Bye.'))),
        ), $body['messages']);
        $this->assertSame(128, $body['max_tokens']);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertSame(0.9, $body['top_p']);
        $this->assertSame(array('END'), $body['stop']);

        $this->assertMatchesSnapshot('conversation', $url, $body);
    }

    public function testStructuredOutputRequestSnapshot()
    {
        $config = ModelConfig::fromArray(array(
            'outputMimeType' => 'application/json',
            'outputSchema' => array(
                'type' => 'object',
                'properties' => array('answer' => array('type' => 'string')),
                'required' => array('answer'),
            ),
        ));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('Capital of France?')))),
            $this->model($config)
        );

        $this->assertSame(
            array(
                'type' => 'json_schema',
                'json_schema' => array(
                    'type' => 'object',
                    'properties' => array('answer' => array('type' => 'string')),
                    'required' => array('answer'),
                ),
            ),
            $body['response_format']
        );

        $this->assertMatchesSnapshot('structured-output', $url, $body);
    }

    public function testToolRoundTripRequestSnapshot()
    {
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('get_weather', 'Get the weather for a city', array(
                    'type' => 'object',
                    'properties' => array('city' => array('type' => 'string')),
                    'required' => array('city'),
                )))->toArray(),
            ),
        ));

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Weather in Paris?'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_1', 'get_weather', array('city' => 'Paris'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_1', 'get_weather', array('temp_c' => 21))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model($config));

        $this->assertSame(array(
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'get_weather',
                    'description' => 'Get the weather for a city',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array('city' => array('type' => 'string')),
                        'required' => array('city'),
                    ),
                ),
            ),
        ), $body['tools']);
        // Assistant tool_calls turn into a tool_calls member...
        $this->assertSame('call_1', $body['messages'][1]['tool_calls'][0]['id']);
        $this->assertSame('get_weather', $body['messages'][1]['tool_calls'][0]['function']['name']);
        $this->assertSame('{"city":"Paris"}', $body['messages'][1]['tool_calls'][0]['function']['arguments']);
        // ...and the function response into a role:tool message.
        $this->assertSame('tool', $body['messages'][2]['role']);
        $this->assertSame('call_1', $body['messages'][2]['tool_call_id']);

        $this->assertMatchesSnapshot('tool', $url, $body);
    }

    public function testMultimodalTextRequestSnapshot()
    {
        // Multiple text parts in one message (text-only multimodality): both
        // parts map to separate content entries.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(
                new MessagePart('Summarize the following document.'),
                new MessagePart('Chapter 1: It was a dark and stormy night...'),
            )),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame(array(
            array('type' => 'text', 'text' => 'Summarize the following document.'),
            array('type' => 'text', 'text' => 'Chapter 1: It was a dark and stormy night...'),
        ), $body['messages'][0]['content']);

        $this->assertMatchesSnapshot('multimodal-text', $url, $body);
    }

    public function testRequestTargetsTheConfiguredPlanRegionEndpoint()
    {
        update_option('zai_connector_zai_plan', 'general');
        update_option('zai_connector_zai_region', 'cn');

        list($url) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model()
        );

        $this->assertSame('https://open.bigmodel.cn/api/paas/v4/chat/completions', $url);
    }

    /*
     * Pre-transport rejection of unsupported option/model combinations.
     */

    public function testImageInputIsRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new File('https://fixture.test/pic.png', 'image/png')),
            )),
        );

        $e = null;
        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An image part must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('text input', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testCandidateCountIsRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('candidateCount' => 2)),
            'candidateCount'
        );
    }

    public function testSamplingPenaltiesAreRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('presencePenalty' => 0.5)),
            'presence penalty'
        );
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('frequencyPenalty' => 0.5)),
            'frequency penalty'
        );
    }

    public function testTopKIsRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('topK' => 40)),
            'top-k'
        );
    }

    public function testLogprobsAreRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('logprobs' => true)),
            'logprobs'
        );
    }

    public function testImageOutputModalityIsRejectedBeforeTransport()
    {
        $config = ModelConfig::fromArray(array());
        $config->setOutputModalities(array(
            WordPress\AiClient\Messages\Enums\ModalityEnum::text(),
            WordPress\AiClient\Messages\Enums\ModalityEnum::image(),
        ));

        $this->assertRejectedBeforeTransport($config, 'text output modalities');
    }

    public function testUnsupportedOutputMimeTypeIsRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('outputMimeType' => 'image/png')),
            'outputMimeType'
        );
    }

    public function testCustomOptionsAreRejectedBeforeTransport()
    {
        $config = ModelConfig::fromArray(array());
        $config->setCustomOption('thinking', array('type' => 'enabled'));

        $this->assertRejectedBeforeTransport($config, 'custom options');
    }

    public function testTextOnlyOutputModalitiesAreAccepted()
    {
        $config = ModelConfig::fromArray(array());
        $config->setOutputModalities(array(WordPress\AiClient\Messages\Enums\ModalityEnum::text()));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        // A single text modality produces no `modalities` parameter.
        $this->assertArrayNotHasKey('modalities', $body);
    }

    public function testExplicitZeroOptionValuesAreNeutralNotUnsupported()
    {
        /*
         * Code-review GLM1 #12: reject_unsupported_options treated 0, '0',
         * 0.0 and '' as "option set" while null/[]/false were tolerated —
         * the setters are non-nullable, so explicitly NEUTRALIZING a
         * previously set option with the only neutral value available
         * (setTopK(0), setPresencePenalty(0.0)) hard-failed the request
         * while setLogprobs(false) passed. Every falsy flavor is now
         * equally "not set".
         */
        $config = ModelConfig::fromArray(array());
        $config->setTopK(0);
        $config->setTopLogprobs(0);
        $config->setPresencePenalty(0.0);
        $config->setFrequencyPenalty(0.0);
        $config->setLogprobs(false);

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        $this->assertSame('glm-5.3', $body['model'], 'The request must proceed with falsy-neutral option values.');
    }

    public function testTheSharedGuardTreatsEveryFalsyFlavorAsNotSet()
    {
        // Direct unit coverage of the flavors the typed setters cannot
        // express ('0', ''), plus a truthy control that still rejects.
        \Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard::reject_unsupported(array(
            'topK' => '0',
            'presencePenalty' => 0.0,
            'frequencyPenalty' => 0,
            'logprobs' => false,
            'webSearch' => '',
            'outputFileType' => array(),
            'outputSpeechVoice' => null,
        ), 'zai_anthropic');
        $this->assertNoHttpRequests(); // flavor: no transport concern, just no crash

        try {
            \Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard::reject_unsupported(array('presencePenalty' => 0.5), 'zai_anthropic');
            $this->fail('A truthy unsupported value must still be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('presence penalty', $e->getMessage());
        }
    }

    /**
     * Asserts the config is rejected before any HTTP attempt.
     *
     * @param ModelConfig $config
     * @param string      $needle Expected message fragment.
     * @return void
     */
    private function assertRejectedBeforeTransport(ModelConfig $config, string $needle)
    {
        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail("Config containing '{$needle}' must be rejected.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /*
     * Credential refusal gate (R19/R20, extended to this surface by
     * code-review GLM1 #1).
     */

    public function testGenerationIsRefusedWhileTheCredentialIsRegionPending()
    {
        /*
         * The R19 refusal gate was wired only into the zai_anthropic
         * surface; this (OpenAI) surface's generation still authenticated a
         * region-pending credential against the newly selected endpoint —
         * exactly the cross-region disclosure the gate exists to block.
         */
        $this->primeZaiDiscoveryTransient();
        $key = FakeSecrets::apiKey();
        $model = ZaiProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        update_option(\Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::REGION_PENDING_OPTION, array(
            'region' => \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for_current_settings()->region(),
            'fingerprint' => hash('sha256', $key),
        ));

        try {
            $model->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('Generation must be refused while the credential is region-pending.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('pending revalidation after a region switch', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testGenerationIsRefusedWhileAMatchingInvalidVerdictExists()
    {
        // A definitive invalid verdict for the exact key+endpoint binding
        // refuses generation on this surface too.
        $this->primeZaiDiscoveryTransient();
        $key = FakeSecrets::apiKey();
        $model = ZaiProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        $binding = hash('sha256', 'runtime|' . \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for_current_settings()->cache_key() . '|' . $key);
        update_option(\Deicod\WpConnectors\Zai\Settings\PlanRegionSettings::STATE_OPTION, array(
            'binding' => $binding,
            'valid' => 'invalid',
            'checked_at' => time(),
            'clock' => 'utc',
        ));

        try {
            $model->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('Generation must be refused while a matching invalid verdict exists.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('rejected for the selected endpoint', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }
}
