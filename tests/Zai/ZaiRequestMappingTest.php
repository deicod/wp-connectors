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
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel;
use Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel;

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
         *
         * GLM4 #4 narrowed the tolerance to the WIRE-INERT options: the
         * request builder never forwards top-k, so setTopK(0) stays a
         * neutral no-op (this test). The options it DOES forward whenever
         * non-null (presence/frequency penalty, logprobs, top logprobs)
         * reject even neutral values — see the next test: they would
         * otherwise ship ("top_logprobs": 0 et al.) and buy the generic
         * upstream error this guard exists to pre-empt.
         */
        $config = ModelConfig::fromArray(array());
        $config->setTopK(0);

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        $this->assertSame('glm-5.3', $body['model'], 'The request must proceed with wire-inert falsy option values.');
        $this->assertArrayNotHasKey('top_k', $body, 'A set-but-never-forwarded option must not appear on the wire.');
    }

    /**
     * @dataProvider provideWireForwardedNeutralOptionValues
     */
    public function testWireForwardedNeutralOptionValuesAreRejectedBeforeTransport(callable $setter)
    {
        /*
         * GLM4 #4: setLogprobs(false), setTopLogprobs(0),
         * setPresencePenalty(0.0), setFrequencyPenalty(0.0) passed the
         * !empty() guard — but the OpenAI-compatible request builder
         * forwards every NON-NULL value of these options, so the neutral
         * values shipped verbatim ("logprobs": false, "top_logprobs": 0,
         * "presence_penalty": 0) and a spec-faithful endpoint rejected
         * top_logprobs without logprobs=true with the GENERIC upstream
         * error. The pinning test this replaces asserted only that the
         * request proceeded — never that the keys were absent. Now the
         * guard rejects the explicitly-set value typed, before transport.
         */
        $config = ModelConfig::fromArray(array());
        $setter($config);

        $this->assertRejectedBeforeTransport($config, 'would still be sent to the API');
    }

    /**
     * @return array<string, list<callable>>
     */
    public function provideWireForwardedNeutralOptionValues()
    {
        return array(
            'logprobs=false' => array(static function (ModelConfig $config): void {
                $config->setLogprobs(false);
            }),
            'topLogprobs=0' => array(static function (ModelConfig $config): void {
                $config->setTopLogprobs(0);
            }),
            'presencePenalty=0.0' => array(static function (ModelConfig $config): void {
                $config->setPresencePenalty(0.0);
            }),
            'frequencyPenalty=0.0' => array(static function (ModelConfig $config): void {
                $config->setFrequencyPenalty(0.0);
            }),
        );
    }

    public function testAnthropicParsedToolCallReplaysThroughThisSurfaceWithObjectNess()
    {
        /*
         * GLM1 #2 verifier pin: a FunctionCall parsed by the zai_anthropic
         * surface carries stdClass markers for nested empty/numeric-keyed
         * objects; replaying it through THIS (OpenAI) surface must preserve
         * object-ness too — the vendor mapping serializes the args with
         * json_encode(), which encodes nested stdClass as JSON objects.
         */
        $this->primeZaiAnthropicDiscoveryTransient();
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'),
            '{"id":"msg_x","type":"message","role":"assistant","content":[{"type":"tool_use","id":"toolu_x","name":"search","input":{"filter":{},"tags":{"0":"x"},"q":"lit"}}],"stop_reason":"tool_use","usage":{"input_tokens":1,"output_tokens":1}}');

        $anthropicModel = \Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider::model('glm-5.3');
        $anthropicModel->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $anthropicModel->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        $call = $anthropicModel->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ))->toMessage()->getParts()[0]->getFunctionCall();
        $this->assertNotNull($call);

        // Replay through THIS surface: the wire's tool-call arguments
        // string must carry the same object shapes.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::openAiChatCompletionBody('ok', 'glm-5.3'));
        $this->model()->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart($call))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('toolu_x', 'search', array('ok' => true))))),
        ));

        $attempts = $this->sdkHttpAttempts();
        $this->assertStringContainsString(
            '"arguments":"{\"filter\":{},\"tags\":{\"0\":\"x\"},\"q\":\"lit\"}"',
            (string) $attempts[1]['body'],
            'The OpenAI-surface replay must preserve the anthropic-parsed object shapes.'
        );
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

    public function testUnencodableOutboundToolArgumentsAreRejectedBeforeTransport()
    {
        /*
         * Verifier round on GLM5 #2: the replay guard ran on INBOUND
         * parses only — caller-supplied FunctionCall arguments traveled
         * through the SDK parent's plain json_encode(), whose false
         * silently shipped "arguments": false on a generation that then
         * SUCCEEDED. The outbound mapper guards with the same shared
         * ToolArgsReplayGuard now, typed pre-transport.
         */
        $this->primeZaiDiscoveryTransient();
        $model = ZaiProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('t1', 'tool', array('v' => INF))))),
        );

        try {
            $model->generateTextResult($prompt);
            $this->fail('Unencodable outbound tool arguments must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not replay tool call arguments', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /*
     * GLM6 #5: wire-value encodability guards (the GLM3 #4/GLM4 #1
     * oracle, ported from the zai_anthropic surface).
     */

    public function testAnInvalidUtf8TextPartIsRejectedBeforeTransport()
    {
        /*
         * The SDK parent's request mapping copies the caller's text part
         * to the wire unvalidated, so an invalid-UTF-8 string detonated
         * in the transport's whole-request json_encode as an untyped
         * JsonException — the mapper's catch-all surfaced it as the
         * generic 500 instead of this typed pre-transport rejection.
         */
        try {
            $this->model()->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart("Scraped \xB1\x31 garbage"))),
            ));
            $this->fail('An invalid-UTF-8 text part must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider could not JSON-encode a message text part', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAThoughtChannelTextPartIsNotEncodabilityGuarded()
    {
        // GLM6 #5 guard: thought parts never ship (the parent drops them
        // from the content mapping), so guarding them would over-reject.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiChatCompletionBody('ok', 'glm-5.3'));

        $this->model()->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(
                new MessagePart("Reasoning \xB1\x31 noise", \WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::thought()),
                new MessagePart('go'),
            )),
        ));

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(1, $attempts);
        $this->assertStringNotContainsString("\xB1", (string) $attempts[0]['body'], 'The thought part must not ship.');
    }

    public function testAnInvalidUtf8SystemInstructionIsRejectedBeforeTransport()
    {
        $config = ModelConfig::fromArray(array());
        $config->setSystemInstruction("System \xB1\x31 garbage");

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An invalid-UTF-8 system instruction must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider could not JSON-encode the system instruction', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @dataProvider provideMalformedStopSequenceEntries
     */
    public function testMalformedStopSequenceEntriesAreRejectedBeforeTransport($sequences, $label)
    {
        /*
         * GLM7 #7: the guard checked only JSON-encodability, so non-string
         * and empty entries ([''], [0], ['END', null]) encoded fine and
         * shipped verbatim ("stop":[""] reaches the wire because the SDK
         * setter checks only list-ness) — the endpoint answered 400 with
         * the generic misattributed message instead of the twin's typed
         * pre-transport rejection (GLM3 #3 parity).
         */
        $config = ModelConfig::fromArray(array());
        $config->setStopSequences($sequences);

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail("Malformed stop sequence entries must be rejected before transport: {$label}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider requires every stop sequence to be a non-empty string.', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideMalformedStopSequenceEntries()
    {
        return array(
            'empty string entry' => array(array(''), 'empty string entry'),
            'integer entry' => array(array(0), 'integer entry'),
            'null entry' => array(array('END', null), 'null entry'),
        );
    }

    public function testAnInvalidUtf8StopSequenceIsRejectedBeforeTransport()
    {
        $config = ModelConfig::fromArray(array());
        $config->setStopSequences(array("END\xB1\x31"));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An invalid-UTF-8 stop sequence must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider could not JSON-encode a stop sequence', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAnInvalidUtf8ToolDeclarationIsRejectedBeforeTransport()
    {
        /*
         * The declared name and description ride the tools member
         * verbatim through the parent's prepareToolsParam() — only the
         * parameter schema was shape-guarded (never encodability).
         */
        $config = ModelConfig::fromArray(array());
        $config->setFunctionDeclarations(array(
            new FunctionDeclaration('get_weather', "desc with \xB1\x31 invalid utf-8", array('type' => 'object')),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An invalid-UTF-8 tool declaration must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider could not JSON-encode a declared tool function description', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testUnencodableToolResultsAreRejectedBeforeTransport()
    {
        /*
         * The SDK parent serializes the function response with plain
         * json_encode() and string-casts the failure, so an unencodable
         * tool result silently shipped as "content": false — telling the
         * model the tool returned no output, the exact corruption class
         * R18's guard fixed on the zai_anthropic surface.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_r', 'get_weather', array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_r', 'get_weather', NAN)))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An unencodable tool result must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider could not JSON-encode a tool result', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @dataProvider provideIdentitylessToolResults
     */
    public function testIdentitylessReplayedToolResultsAreRejectedBeforeTransport($response, $label)
    {
        /*
         * GLM9 #4: guard_wire_values() validated the tool-result VALUE
         * but never its id — a null (or empty) id shipped to the wire as
         * "tool_call_id": null (the SDK parent copies it unvalidated)
         * and failed upstream as the generic 400 'rejected the request'
         * message, where the zai_anthropic twin and this walk's own
         * FunctionCall ids (GLM7 #7) reject the identical shape typed
         * before transport.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_q', 'get_weather', array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart($response))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail("A {$label} must be rejected before transport.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider requires every function-response part to carry the non-empty tool call id it answers.', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideIdentitylessToolResults()
    {
        return array(
            'null id' => array(new FunctionResponse(null, 'get_weather', array('temp' => 21)), 'null-id tool result'),
            'empty id' => array(new FunctionResponse('', 'get_weather', array('temp' => 21)), 'empty-id tool result'),
        );
    }

    public function testTheSharedIdentityGuardsServeBothSurfaces()
    {
        /*
         * GLM9 #12: the stop-sequence and tool-identity rules ride the
         * one shared JsonEncodeGuard, parameterized by provider label —
         * the loops this extraction replaced had already drifted once
         * (GLM3 #3 landed on zai_anthropic only; GLM7 #7 re-landed on
         * zai). The same malformed input now rejects with the identical
         * message modulo the label on both surfaces, by construction.
         */
        foreach (array('zai', 'zai_anthropic') as $label) {
            try {
                Deicod\WpConnectors\Zai\Support\JsonEncodeGuard::must_encode_stop_sequences(array(0), $label);
                $this->fail("[{$label}] A non-string stop-sequence entry must reject.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame("The {$label} provider requires every stop sequence to be a non-empty string.", $e->getMessage());
            }

            try {
                Deicod\WpConnectors\Zai\Support\JsonEncodeGuard::must_encode_tool_call_identity(new FunctionCall(null, 'tool', array('v' => 1)), $label);
                $this->fail("[{$label}] A null-id tool call must reject.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame("The {$label} provider requires every function-call part to carry a non-empty id and name.", $e->getMessage());
            }

            try {
                Deicod\WpConnectors\Zai\Support\JsonEncodeGuard::must_encode_tool_result_identity(new FunctionResponse(null, 'tool', array('ok' => true)), 'tool call id', 'a tool result id', $label);
                $this->fail("[{$label}] A null-id tool result must reject.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame("The {$label} provider requires every function-response part to carry the non-empty tool call id it answers.", $e->getMessage());
            }
        }

        // The valid shapes pass untouched through both labels.
        foreach (array('zai', 'zai_anthropic') as $label) {
            Deicod\WpConnectors\Zai\Support\JsonEncodeGuard::must_encode_stop_sequences(array('END'), $label);
            Deicod\WpConnectors\Zai\Support\JsonEncodeGuard::must_encode_tool_call_identity(new FunctionCall('call_1', 'tool', array('v' => 1)), $label);
            Deicod\WpConnectors\Zai\Support\JsonEncodeGuard::must_encode_tool_result_identity(new FunctionResponse('call_1', 'tool', array('ok' => true)), 'tool call id', 'a tool result id', $label);
        }

        $this->addToAssertionCount(1);
    }

    public function testTheDecodedFastPathAgreesWithTheFullOracle()
    {
        /*
         * GLM9 #13: the inbound acceptance points call the replay guard
         * on json_decode() products — the parse, both SSE acceptance
         * points, and the arguments of every replayed historical tool
         * call — so the guard ran its encode→decode→re-encode round
         * trip on already-validated immutable values, O(K·S)
         * serialization per request growing with the conversation. The
         * structural fast path decides decode-origin values without
         * building any string. Equivalence pin: across a matrix of
         * decode-origin values (the hazards included), the fast path
         * returns exactly what the full oracle returns.
         */
        $json_values = array(
            'plain object' => '{"city":"Oslo","temp":21}',
            'nested empty shapes' => '{"a":{},"b":{"c":[]}}',
            'numeric-keyed object' => '{"0":"x","1":"y"}',
            'ordinary float' => '{"v":1.5}',
            'negative zero' => '{"v":-0.0}',
            'unicode string' => '{"note":"héllo ✨"}',
            'php-int-max integer' => '{"v":9223372036854775807}',
            'INF (1e999)' => '{"v":1e999}',
            'out-of-range integral float' => '{"v":9.3e18}',
            'empty object' => '{}',
            'null member' => '{"v":null}',
        );

        foreach ($json_values as $label => $json) {
            $decoded = json_decode($json);
            $this->assertNotNull($decoded, "[{$label}] the fixture must decode.");

            $this->assertSame(
                Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard::is_replayable($decoded),
                Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard::is_replayable_decoded($decoded),
                "[{$label}] the decoded fast path must agree with the full oracle."
            );
        }

        // The decode-origin hazards reject through the fast path alone,
        // and the ordinary shapes pass.
        $guard = 'Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard';
        $this->assertFalse($guard::is_replayable_decoded(json_decode('{"v":1e999}')), 'INF (the 1e999 decode) must reject.');
        $this->assertFalse($guard::is_replayable_decoded(json_decode('{"v":9.3e18}')), 'An out-of-range integral float must reject.');
        $this->assertTrue($guard::is_replayable_decoded(json_decode('{"v":1.5}')), 'An ordinary float replays.');
    }

    public function testUnencodableToolCallIdentitiesAreRejectedBeforeTransport()
    {
        // The id and name ride the tool_calls member verbatim; both are
        // guarded like every other caller-authored wire string.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall("call_\xB1\x31", 'tool', array('v' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An invalid-UTF-8 tool call id must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider could not JSON-encode a tool call id', $e->getMessage());
        }

        $this->assertNoHttpRequests();

        WpHarness::$sdk_http_attempts = array();

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_ok', "tool_\xB1\x31", array('v' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An invalid-UTF-8 tool call name must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider could not JSON-encode a tool call name', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @dataProvider provideIdentitylessToolCalls
     */
    public function testIdentitylessReplayedToolCallsAreRejectedBeforeTransport($call, $label)
    {
        /*
         * GLM7 #7: the (string) casts let a null id (or name) pass the
         * encodability guard while null itself rode the tool_calls member
         * verbatim — the typed rejection the zai_anthropic twin's Codex
         * R9 #3 gives the identical shape.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart($call))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail("A {$label} must be rejected before transport.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai provider requires every function-call part to carry a non-empty id and name.', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideIdentitylessToolCalls()
    {
        return array(
            'null id' => array(new FunctionCall(null, 'tool', array('v' => 1)), 'null-id tool call'),
            'empty id' => array(new FunctionCall('', 'tool', array('v' => 1)), 'empty-id tool call'),
            'null name' => array(new FunctionCall('call_ok', null, array('v' => 1)), 'null-name tool call'),
            'empty name' => array(new FunctionCall('call_ok', '', array('v' => 1)), 'empty-name tool call'),
        );
    }

    /**
     * @dataProvider provideUnencodableConfigOptions
     */
    public function testUnencodableConfigOptionsAreRejectedBeforeTransport(callable $setter, string $needle)
    {
        /*
         * Verifier round on GLM6 #5: the SDK parent ships temperature,
         * top_p, and the response_format schema verbatim — a NAN float or
         * an unencodable schema member detonated in the transport's
         * whole-request json_encode as the untyped JsonException (generic
         * 500), where every other caller-authored wire value rejects
         * typed. The walk guards them like the rest.
         */
        $config = ModelConfig::fromArray(array());
        $setter($config);

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An unencodable config option must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideUnencodableConfigOptions()
    {
        return array(
            'NAN temperature' => array(static function (ModelConfig $config): void {
                $config->setTemperature(NAN);
            }, 'The zai provider could not JSON-encode the temperature option'),
            'NAN top_p' => array(static function (ModelConfig $config): void {
                $config->setTopP(NAN);
            }, 'The zai provider could not JSON-encode the top_p option'),
            'unencodable output schema' => array(static function (ModelConfig $config): void {
                $config->setOutputMimeType('application/json');
                $config->setOutputSchema(array('properties' => array('bad' => "bin\xB1\x31ary")));
            }, 'The zai provider could not JSON-encode the configured output schema'),
        );
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
        // refuses generation on this surface too. (GLM5 #11: the wired
        // model key is a save-time candidate, so its binding normalizes
        // to the 'database' identity at construction.)
        $this->primeZaiDiscoveryTransient();
        $key = FakeSecrets::apiKey();
        $model = ZaiProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        $binding = hash('sha256', 'database|' . \Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint::for_current_settings()->cache_key() . '|' . $key);
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

    public function testEachSurfaceNamesItselfOneWayInRejectionMessages()
    {
        /*
         * GLM10 #9: each surface's guards and rejections interpolate ONE
         * provider label — ridden on the availability owner's
         * REFUSAL_LABEL — and the model sources carry no bare label
         * literals. The zai surface previously mixed 'z.ai' (advertised
         * guards, ResponseException labels) and 'zai' (JsonEncodeGuard
         * sites) across ~25 sites; the anthropic surface mixed 'z.ai'
         * and 'zai_anthropic' across ~40 — one surface's user-facing
         * rejections named the provider two different ways.
         */
        $this->assertSame(
            ZaiProviderAvailability::REFUSAL_LABEL,
            (new \ReflectionClass(ZaiTextGenerationModel::class))->getConstant('PROVIDER_LABEL'),
            'The zai model rides the availability owner\'s label.'
        );
        $this->assertSame(
            ZaiAnthropicProviderAvailability::REFUSAL_LABEL,
            (new \ReflectionClass(ZaiAnthropicTextGenerationModel::class))->getConstant('PROVIDER_LABEL'),
            'The zai_anthropic model rides the availability owner\'s label.'
        );

        // The drift guard: no bare label literals remain in either model.
        foreach (array(
            ZaiTextGenerationModel::class => array("z.ai", 'zai'),
            ZaiAnthropicTextGenerationModel::class => array("z.ai", 'zai_anthropic'),
        ) as $model => $labels) {
            $source = (string) file_get_contents((new \ReflectionClass($model))->getFileName());
            foreach ($labels as $label) {
                $this->assertSame(
                    0,
                    preg_match('/[\'"]' . preg_quote($label, '/') . '[\'"],/', $source),
                    "{$model} must interpolate the label constant, not the bare literal '{$label}'."
                );
            }
        }
    }
}
