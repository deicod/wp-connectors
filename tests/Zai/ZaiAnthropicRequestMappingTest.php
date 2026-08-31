<?php
/**
 * Task 2.5 — Messages request mapping tests.
 *
 * Request snapshots (minimal, conversation, structured output, tool,
 * multimodal-text) are committed under tests/fixtures/snapshots/zai-anthropic/
 * with credentials excluded by construction: only the URL and JSON body are
 * stored, never headers. Pre-transport rejection of unsupported
 * option/model combinations and Messages role-order violations is proven by
 * the absence of any HTTP attempt.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;

final class ZaiAnthropicRequestMappingTest extends WpConnectorsTestCase
{
    /**
     * Model instance wired to the harness transport with a fixture key.
     *
     * The discovery transient is primed so exactly ONE request is recorded.
     *
     * @param ModelConfig|null $config Optional model configuration.
     * @return ZaiAnthropicTextGenerationModel
     */
    private function model(?ModelConfig $config = null)
    {
        $this->primeZaiAnthropicDiscoveryTransient();
        $model = ZaiAnthropicProvider::model('glm-5.3', $config);
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        return $model;
    }

    /**
     * Runs one generation and returns [url, decodedBody, headers] of the
     * single recorded request.
     *
     * @param list<Message>             $prompt
     * @param ZaiAnthropicTextGenerationModel $model
     * @return array{0: string, 1: array, 2: array}
     */
    private function captureRequest(array $prompt, $model): array
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('ok'));

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
     * @param string $name  Snapshot name.
     * @param string $url   Request URL.
     * @param array  $body  Decoded request body.
     * @return void
     */
    private function assertMatchesSnapshot(string $name, string $url, array $body)
    {
        $path = __DIR__ . '/../fixtures/snapshots/zai-anthropic/' . $name . '.json';
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

        $this->assertSame('https://api.z.ai/api/anthropic/v1/messages', $url);
        $this->assertSame('glm-5.3', $body['model']);
        $this->assertSame(4096, $body['max_tokens'], 'max_tokens is required by the protocol: the default applies when omitted.');
        $this->assertSame(array(
            array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => 'Say hi.'))),
        ), $body['messages']);
        $this->assertArrayNotHasKey('system', $body);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('stream', $body);

        // The live request carries the credential; the snapshot never does.
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

        $this->assertSame('You are terse.', $body['system']);
        $this->assertSame(array(
            array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => 'Hello.'))),
            array('role' => 'assistant', 'content' => array(array('type' => 'text', 'text' => 'Hi!'))),
            array('role' => 'user', 'content' => array(array('type' => 'text', 'text' => 'Bye.'))),
        ), $body['messages']);
        $this->assertSame(128, $body['max_tokens']);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertSame(0.9, $body['top_p']);
        $this->assertSame(array('END'), $body['stop_sequences']);

        $this->assertMatchesSnapshot('conversation', $url, $body);
    }

    public function testStructuredOutputRequestSnapshot()
    {
        $schema = array(
            'type' => 'object',
            'properties' => array('answer' => array('type' => 'string')),
            'required' => array('answer'),
        );

        $config = ModelConfig::fromArray(array(
            'outputMimeType' => 'application/json',
            'outputSchema' => $schema,
        ));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('Capital of France?')))),
            $this->model($config)
        );

        // Structured output travels as JSON guidance appended to the system
        // prompt (native output_format is unverified on z.ai).
        $this->assertStringContainsString('single JSON value', $body['system']);
        $this->assertStringContainsString('conform to this JSON Schema', $body['system']);
        $this->assertStringContainsString((string) wp_json_encode($schema), $body['system']);
        $this->assertArrayNotHasKey('output_format', $body, 'No unverified native structured-output parameter is sent.');
        $this->assertArrayNotHasKey('response_format', $body);

        $this->assertMatchesSnapshot('structured-output', $url, $body);
    }

    public function testJsonMimeTypeWithoutSchemaStillGuides()
    {
        $config = ModelConfig::fromArray(array('outputMimeType' => 'application/json'));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('Give me a config.')))),
            $this->model($config)
        );

        $this->assertStringContainsString('single JSON value', $body['system']);
        $this->assertStringNotContainsString('JSON Schema', $body['system']);
    }

    public function testJsonGuidanceAppendsToAnExistingSystemInstruction()
    {
        $config = ModelConfig::fromArray(array(
            'systemInstruction' => 'You are terse.',
            'outputMimeType' => 'application/json',
            'outputSchema' => array('type' => 'object'),
        ));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        $this->assertStringStartsWith('You are terse.', $body['system']);
        $this->assertStringContainsString("\n\n", $body['system']);
        $this->assertStringContainsString('single JSON value', $body['system']);
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
                'name' => 'get_weather',
                'description' => 'Get the weather for a city',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array('city' => array('type' => 'string')),
                    'required' => array('city'),
                ),
            ),
        ), $body['tools']);

        // Assistant tool call becomes a tool_use content block...
        $this->assertSame('assistant', $body['messages'][1]['role']);
        $this->assertSame(array(
            'type' => 'tool_use',
            'id' => 'call_1',
            'name' => 'get_weather',
            'input' => array('city' => 'Paris'),
        ), $body['messages'][1]['content'][0]);

        // ...and the function response a tool_result block in a user turn.
        $this->assertSame('user', $body['messages'][2]['role']);
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('call_1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('{"temp_c":21}', $body['messages'][2]['content'][0]['content']);

        $this->assertMatchesSnapshot('tool', $url, $body);
    }

    public function testEmptyToolArgumentsEncodeAsAnEmptyJsonObject()
    {
        // The official-plugin normalization: PHP's empty array would encode
        // as [] but the protocol requires {} — visible in the raw request
        // body, since the decoded form of {} is indistinguishable from [].
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_2', 'ping', null)))),
        );
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('ping', 'Pings', null))->toArray(),
            ),
        ));

        $this->model($config)->generateTextResult($prompt);

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];
        $this->assertStringContainsString('"input":{}', $raw, 'tool_use input must encode as an empty object for empty args.');
        $this->assertStringNotContainsString('"input":[]', $raw, 'tool_use input must never encode as [].');

        // A tool without a parameters schema carries the empty-object
        // input_schema, also as an object.
        $this->assertStringContainsString('"properties":{}', $raw, 'A schema-less tool declaration gets an empty-object properties member.');
    }

    public function testMultimodalTextRequestSnapshot()
    {
        // Multiple text parts in one message (text-only multimodality): both
        // parts map to separate content blocks.
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

    public function testAThoughtOnlyMessageIsRejectedBeforeTransport()
    {
        // A replayed assistant turn carrying ONLY a thinking block has no
        // translatable content left: an empty text block would be a
        // guaranteed upstream 400, so the request must fail HERE (review
        // finding).
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Hello.'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart('only reasoning', WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::thought()),
            )),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A thought-only message must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at least one translatable', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAPartlessMessageIsRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::user(), array()),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A message without parts must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at least one translatable', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testThoughtPartsAreDroppedFromOutboundMessages()
    {
        // Model reasoning echoes carry no user intent and cannot be replayed
        // as thinking blocks without signatures; they are dropped, leaving
        // the visible text intact.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Hello.'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart('reasoning trace', WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::thought()),
                new MessagePart('Hi!'),
            )),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame(array(array('type' => 'text', 'text' => 'Hi!')), $body['messages'][1]['content']);
        $this->assertStringNotContainsString('reasoning trace', (string) wp_json_encode($body));
    }

    public function testRequestTargetsTheConfiguredPlanRegionEndpoint()
    {
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_PLAN, 'general');
        update_option(ZaiAnthropicPlanRegionSettings::OPTION_REGION, 'cn');

        list($url) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model()
        );

        $this->assertSame('https://open.bigmodel.cn/api/anthropic/v1/messages', $url);
    }

    public function testAnExplicitMaxTokensValueWinsOverTheDefault()
    {
        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model(ModelConfig::fromArray(array('maxTokens' => 64)))
        );

        $this->assertSame(64, $body['max_tokens']);
    }

    /*
     * Pre-transport rejection: unsupported option/model combinations.
     */

    public function testImageInputIsRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new File('https://fixture.test/pic.png', 'image/png')),
            )),
        );

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

    public function testWebSearchIsRejectedBeforeTransport()
    {
        $config = ModelConfig::fromArray(array());
        $config->setWebSearch(new WordPress\AiClient\Tools\DTO\WebSearch());

        $this->assertRejectedBeforeTransport($config, 'web search');
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

    public function testNonPositiveMaxTokensIsRejectedBeforeTransport()
    {
        $this->assertRejectedBeforeTransport(
            ModelConfig::fromArray(array('maxTokens' => 0)),
            'maxTokens'
        );
    }

    /*
     * Pre-transport rejection: Messages role-order violations (Task 2.5).
     */

    public function testAnEmptyPromptIsRejectedBeforeTransport()
    {
        try {
            $this->model()->generateTextResult(array());
            $this->fail('An empty prompt must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('at least one message', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAnAssistantFirstPromptIsRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::model(), array(new MessagePart('I go first.'))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An assistant-first prompt must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('first message', $e->getMessage());
            $this->assertStringContainsString('user role', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testConsecutiveSameRoleMessagesAreRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('One.'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Two.'))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('Consecutive same-role messages must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('alternate', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAFunctionCallPartWithoutAnIdIsRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall(null, 'ping', array())))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A function-call part without an id must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('id and a name', $e->getMessage());
        }

        $this->assertNoHttpRequests();
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
}
