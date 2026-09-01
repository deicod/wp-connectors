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
     * Model instance wired to the harness transport with an EXACT key.
     *
     * For credential-state tests that bind flags/verdicts to a specific
     * key (FakeSecrets::apiKey() is random per call, so the model must
     * carry the captured value). The discovery transient is primed so
     * exactly ONE request is recorded.
     *
     * @param string $key The exact API key the model authenticates with.
     * @return ZaiAnthropicTextGenerationModel
     */
    private function modelWithKey(string $key)
    {
        $this->primeZaiAnthropicDiscoveryTransient();
        $model = ZaiAnthropicProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication($key));

        return $model;
    }

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

    /**
     * @dataProvider provideUnencodableOutputSchemas
     */
    public function testUnencodableOutputSchemasAreRejectedBeforeTransport($schema, $label)
    {
        /*
         * R19 (inline 3906739372): the encode failure was string-cast to
         * '' — the guidance ended in "JSON Schema: " and the model
         * produced unconstrained output despite the caller's schema.
         */
        $config = ModelConfig::fromArray(array(
            'outputMimeType' => 'application/json',
            'outputSchema' => $schema,
        ));

        try {
            $this->model($config)->generateTextResult(
                array(new Message(MessageRoleEnum::user(), array(new MessagePart('Capital of France?'))))
            );
            $this->fail("[{$label}] An unencodable output schema must be rejected.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not JSON-encode the configured output schema', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideUnencodableOutputSchemas()
    {
        $recursive = array('type' => 'object');
        $recursive['self'] = &$recursive;

        return array(
            'NAN inside the schema' => array(array('type' => NAN), 'NAN inside the schema'),
            'recursive schema' => array($recursive, 'recursive schema'),
            'invalid UTF-8 inside the schema' => array(array('type' => "\xB1\x31"), 'invalid UTF-8 inside the schema'),
        );
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

    public function testAnOutputSchemaAloneRequestsJsonGuidance()
    {
        // Codex R1 finding 4: outputSchema is advertised independently of
        // outputMimeType — supplying it alone must not silently discard
        // the schema into an unconstrained request.
        $schema = array(
            'type' => 'object',
            'properties' => array('answer' => array('type' => 'string')),
            'required' => array('answer'),
        );

        $config = ModelConfig::fromArray(array(
            'outputSchema' => $schema,
        ));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('Capital of France?')))),
            $this->model($config)
        );

        $this->assertArrayHasKey('system', $body, 'The schema alone must produce JSON guidance.');
        $this->assertStringContainsString('single JSON value', $body['system']);
        $this->assertStringContainsString((string) wp_json_encode($schema), $body['system'], 'The schema must be embedded.');

        $this->assertMatchesSnapshot('schema-only-structured-output', $url, $body);
    }

    public function testATextPlainMimeDoesNotDiscardAnExplicitSchema()
    {
        $schema = array('type' => 'object');

        $config = ModelConfig::fromArray(array(
            'outputMimeType' => 'text/plain',
            'outputSchema' => $schema,
        ));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        $this->assertStringContainsString('single JSON value', $body['system'], 'An explicit schema wins over a plain-text mime.');
    }

    public function testNeitherSignalProducesNoGuidance()
    {
        $config = ModelConfig::fromArray(array('outputMimeType' => 'text/plain'));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        $this->assertArrayNotHasKey('system', $body, 'No JSON signal, no guidance.');
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

    public function testAnEmptyArrayParameterSchemaNormalizesToAnObject()
    {
        // Codex R1 finding 5: a parameterless declaration whose parameters
        // are array() (not null) must normalize like a missing schema —
        // input_schema encodes as {}, never [].
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('ping', 'Pings', array()))->toArray(),
            ),
        ));

        $this->model($config)->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];
        $this->assertStringContainsString('"input_schema":{"type":"object","properties":{}}', $raw, 'The empty-array schema normalizes to the empty-object schema.');
        $this->assertStringNotContainsString('"input_schema":[]', $raw, 'input_schema must never encode as [].');
    }

    public function testSequentialArrayToolArgumentsAreRejectedBeforeTransport()
    {
        // Codex R4 #4: a FunctionCall from chat history carrying a
        // non-empty sequential array encodes input as a JSON LIST — the
        // Messages protocol requires an object, so reject before transport.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Weather?'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_9', 'get_weather', array('Oslo'))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('Sequential-array tool arguments must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('JSON object', $e->getMessage());
            $this->assertStringContainsString('list', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testObjectShapedAndEmptyToolArgumentsStillEncode()
    {
        // String-keyed arrays (incl. numeric-STRING keys) encode as JSON
        // objects and pass; empty arrays keep the {} normalization.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart(new FunctionCall('call_a', 'get_weather', array('city' => 'Oslo'))),
            )),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_a', 'get_weather', array('temp_c' => 21))))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_b', 'ping', array())))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame(array('city' => 'Oslo'), $body['messages'][1]['content'][0]['input']);
        $rawBody = (string) $this->sdkHttpAttempts()[0]['body'];
        $this->assertStringContainsString('"input":{}', $rawBody, 'Empty args still normalize to the empty object on the wire.');
        $this->assertStringNotContainsString('"input":[]', $rawBody);

    }

    public function testMixedKeyToolArgumentsStillEncodeAsObjects()
    {
        // A mixed-key array is NOT a list (json_encode emits an object),
        // so it passes the shape check untouched.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_c', 'pick', array('first' => 'a', 1 => 'b'))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame(array('first' => 'a', 1 => 'b'), $body['messages'][1]['content'][0]['input']);
    }

    /**
     * @dataProvider provideUnencodableToolResults
     */
    public function testUnencodableToolResultsAreRejectedBeforeTransport($response, $label)
    {
        /*
         * R18 (inline 3906485711): wp_json_encode() failure on a tool
         * result was string-cast to '' — the request succeeded while
         * telling the model the tool returned nothing. Rejected pre-
         * transport now, in the tool-result validation channel.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_enc', 'get_weather', array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_enc', 'get_weather', $response)))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail("[{$label}] An unencodable tool result must be rejected.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not JSON-encode a tool result', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideUnencodableToolResults()
    {
        $recursive = array('ok' => 1);
        $recursive['self'] = &$recursive;

        return array(
            'NAN value' => array(NAN, 'NAN value'),
            'resource value' => array(fopen('php://memory', 'r'), 'resource value'),
            'recursive array' => array($recursive, 'recursive array'),
        );
    }

    public function testEncodableToolResultsStillSerialize()
    {
        // Guard: ordinary payloads keep their exact JSON content.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_ok', 'get_weather', array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_ok', 'get_weather', array('temp_c' => 21))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('{"temp_c":21}', $body['messages'][2]['content'][0]['content'], 'The tool_result carries the exact JSON.' );
    }

    public function testGenerationIsRefusedWhileTheCredentialIsRegionPending()
    {
        /*
         * R19 (inline 3906739389): an env-sourced credential cannot be
         * deleted by a region switch — the settings layer marks it
         * region-pending, and the direct-generation path must honor that
         * state instead of authenticating unconditionally.
         */
        $model = $this->modelWithKey($key = FakeSecrets::apiKey());
        $region = \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::for_current_settings()->region();
        update_option(\Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::REGION_PENDING_OPTION, array(
            'region' => $region,
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
        // R19 (inline 3906739389): a definitive invalid verdict for the
        // exact key+endpoint binding refuses generation.
        $model = $this->modelWithKey($key = FakeSecrets::apiKey());
        $endpoint = \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::for_current_settings();
        $binding = hash('sha256', 'runtime|' . $endpoint->cache_key() . '|' . $key);

        update_option(\Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings::STATE_OPTION, array(
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

    public function testGenerationProceedsUnderValidCredentialState()
    {
        // Guard: with no pending flag and no invalid verdict, generation
        // authenticates as before (a queued response completes).
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('Allowed.'));

        $text = $this->model()->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ))->toText();

        $this->assertSame('Allowed.', $text);
    }

    public function testEmptyDeclaredToolNamesAreRejectedBeforeTransport()
    {
        /*
         * Codex R18 #2: the declaration path had no identity validation —
         * an empty-name FunctionDeclaration reached the endpoint's tools
         * array only to fail with an upstream 400. The DTO coerces names
         * to string, so '' is the constructible empty identity.
         */
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('', 'Nameless', null))->toArray(),
            ),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('An empty declared tool name must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty name', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAnEmptyNameAmongValidDeclarationsIsRejectedFirst()
    {
        // First-bad-wins: a valid sibling does not launder the empty one.
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('valid_tool', 'Valid', null))->toArray(),
                (new FunctionDeclaration('', 'Nameless', null))->toArray(),
            ),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('An empty declared tool name must be rejected even beside valid declarations.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty name', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testDuplicateDeclaredToolNamesAreRejectedBeforeTransport()
    {
        /*
         * R18 (inline 3906485728): a tool_use names only the function —
         * two declarations under one name make the selection ambiguous.
         */
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('get_weather', 'Weather in metric', array('type' => 'object', 'properties' => array('city' => array('type' => 'string')))))->toArray(),
                (new FunctionDeclaration('get_weather', 'Weather in imperial', array('type' => 'object', 'properties' => array('city' => array('type' => 'string')))))->toArray(),
            ),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('Duplicate declared tool names must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unique names', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAllValidDeclarationsStillEmitTheirNames()
    {
        // Guard: multi-tool configs keep the exact names in the tools
        // array — only the empty identity is new.
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('get_weather', 'Weather', array('type' => 'object', 'properties' => array('city' => array('type' => 'string')))))->toArray(),
                (new FunctionDeclaration('ping', 'Pings', null))->toArray(),
            ),
        ));

        list($url, $body) = $this->captureRequest(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ), $this->model($config));

        $this->assertSame('get_weather', $body['tools'][0]['name']);
        $this->assertSame('ping', $body['tools'][1]['name']);
        $this->assertCount(2, $body['tools']);
    }

    public function testSequentialArrayParameterSchemasAreRejectedBeforeTransport()
    {
        // Codex R7 #3: a non-empty SEQUENTIAL parameter array serializes
        // input_schema as a JSON LIST — the tools contract requires an
        // object, so reject before transport instead of a 400 upstream.
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('pick', 'Picks', array('type', 'string')))->toArray(),
            ),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('A sequential-array parameter schema must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('JSON object', $e->getMessage());
            $this->assertStringContainsString('non-empty list', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testObjectShapedParameterSchemasStillPass()
    {
        // String-keyed and mixed-key schemas encode as objects and pass;
        // null and empty keep their {} normalization (R1 #5).
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('get_weather', 'Weather', array(
                    'type' => 'object',
                    'properties' => array('city' => array('type' => 'string')),
                )))->toArray(),
                (new FunctionDeclaration('pick', 'Picks', array('first' => 'a', 1 => 'b')))->toArray(),
            ),
        ));

        $this->model($config)->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];
        $this->assertStringContainsString('"input_schema":{"type":"object","properties":{"city":{"type":"string"}}}', $raw);
        $this->assertStringContainsString('"input_schema":{"first":"a","1":"b"}', $raw, 'A mixed-key schema encodes as an object member.');
        $this->assertStringNotContainsString('"input_schema":[', $raw);
    }

    public function testAnUnmatchedToolResultIsRejectedBeforeTransport()
    {
        // Codex R8 #5: a tool_result whose ID answers no preceding tool_use
        // reached the API and failed with a 400 instead of the advertised
        // pre-transport validation.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Weather?'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_real', 'get_weather', array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_stale', 'get_weather', array('temp_c' => 21))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An unmatched tool result must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('preceding assistant tool call', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testADuplicateToolResultIsRejectedBeforeTransport()
    {
        // Each tool_use ID may be answered exactly once.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_x', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('call_x', 'ping', array('ok' => 1))),
                new MessagePart(new FunctionResponse('call_x', 'ping', array('ok' => 2))),
            )),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A duplicate tool result must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('preceding assistant tool call', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAToolResultAnsweringALaterToolCallIsRejected()
    {
        // Order matters: answering a tool_use that appears AFTER the
        // result is just as unmatched.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_later', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_later', 'ping', array('ok' => 1))))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_later2', 'ping', array())))),
        );

        // Reorder: result for call_later2 arrives before its tool_use.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_first', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_future', 'ping', array('ok' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A tool result answering a later tool call must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('preceding assistant tool call', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testSequentialToolRoundsStillPass()
    {
        // Two complete rounds — every result answers its own outstanding
        // call, in order.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('c1', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('c1', 'ping', array('r' => 1))))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('c2', 'pong', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('c2', 'pong', array('r' => 2))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('c1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('c2', $body['messages'][4]['content'][0]['tool_use_id']);
    }

    public function testAToolResultAfterAnInterveningUserTurnIsRejected()
    {
        // Codex R9 #1 at the WIRE level (R11 #1 refinement): the result
        // must sit in the user turn IMMEDIATELY following the coalesced
        // assistant tool-use turn. An intervening USER turn answers
        // nothing and closes the window — a later result is stale, and the
        // unanswered call surfaces as the R10 #1 partial rejection.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_j', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('A different user matter.'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart('More.'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_j', 'ping', array('ok' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A stale tool result several turns later must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('partially answered', $e->getMessage(), 'The unanswered call surfaces as the partial rejection before the stale result is even reached.');
        }

        $this->assertNoHttpRequests();
    }

    public function testAnInterveningAssistantTextMessageCoalescesNotStales()
    {
        // Codex R11 #1: an intervening ASSISTANT text SDK message coalesces
        // with the tool turn into ONE wire assistant turn — the following
        // user result is wire-adjacent and valid, no longer an SDK-level
        // 'intervening turn'.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_i', 'ping', array())))),
            new Message(MessageRoleEnum::model(), array(new MessagePart('An intervening assistant turn.'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_i', 'ping', array('ok' => 1))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('tool_use', $body['messages'][1]['content'][0]['type']);
        $this->assertSame('text', $body['messages'][1]['content'][1]['type'], 'The assistant text coalesces into the tool turn.');
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
    }

    public function testAMultiToolAssistantTurnAnsweredInOneUserTurnStillPasses()
    {
        // Codex R9 #1 multi-tool case: one assistant turn opens several
        // tool_use blocks; the NEXT user turn answers them all.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Both, please.'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart(new FunctionCall('m1', 'get_weather', array('city' => 'Oslo'))),
                new MessagePart(new FunctionCall('m2', 'get_time', array('zone' => 'CET'))),
            )),
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('m1', 'get_weather', array('temp_c' => 21))),
                new MessagePart(new FunctionResponse('m2', 'get_time', array('hour' => 14))),
            )),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertCount(2, $body['messages'][2]['content']);
        $this->assertSame('m1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('m2', $body['messages'][2]['content'][1]['tool_use_id']);
    }

    public function testAMultiToolTurnPartiallyAnsweredThenResumedIsRejected()
    {
        // Both tool_use blocks opened by ONE assistant turn must be
        // answered by the SAME next user turn. R10 #1 made the partial
        // answer itself the rejection point (this fixture answers only p1),
        // so a fully-answered first turn is used to prove the later result
        // is still stale.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart(new FunctionCall('p1', 'ping', array())),
                new MessagePart(new FunctionCall('p2', 'pong', array())),
            )),
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('p1', 'ping', array('r' => 1))),
                new MessagePart(new FunctionResponse('p2', 'pong', array('r' => 2))),
            )),
            new Message(MessageRoleEnum::model(), array(new MessagePart('Continuing.'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('p1', 'ping', array('r' => 3))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A re-answered (stale) tool id must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('preceding assistant tool call', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @dataProvider provideEmptyToolIdentities
     */
    public function testEmptyToolCallIdentitiesAreRejectedBeforeTransport($call, $needle)
    {
        // Codex R9 #3: '' passes the null-only guard and emitted a tool_use
        // block with an empty identity — Messages requires non-empty ids
        // and names (upstream 400).
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart($call))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An empty tool identity must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideEmptyToolIdentities()
    {
        return array(
            'empty id' => array(new FunctionCall('', 'ping', array()), 'non-empty id and name'),
            'empty name' => array(new FunctionCall('call_e', '', array()), 'non-empty id and name'),
        );
    }

    public function testAnEmptyToolResultIdIsRejectedBeforeTransport()
    {
        // A user FunctionResponse with '' id (the SDK DTO permits it in
        // user messages) must fail the non-empty check before transport.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_r', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('', 'ping', array('ok' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An empty tool-result id must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty tool_use id', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testMergedAssistantToolMessagesFormOneAnswerableTurn()
    {
        // Codex R11 #1 (supersedes the R9 SDK-level window-replacement
        // probe): assistant(A) → assistant(B) are ADJACENT same-role SDK
        // messages that coalesce into ONE wire assistant turn — its user
        // turn must answer BOTH calls. Answering only B is a partially
        // answered wire turn (R10 #1), not a window replacement.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_a', 'ping', array())))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_b', 'pong', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_b', 'pong', array('r' => 2))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A partially answered merged tool turn must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('partially answered', $e->getMessage());
        }

        $this->assertNoHttpRequests();

        // Fully answered across the two adjacent messages: one valid wire
        // turn.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Both.'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('a1', 'ping', array())))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('b1', 'pong', array())))),
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('a1', 'ping', array('r' => 1))),
                new MessagePart(new FunctionResponse('b1', 'pong', array('r' => 2))),
            )),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('a1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('b1', $body['messages'][2]['content'][1]['tool_use_id']);
    }

    public function testAPartiallyAnsweredToolTurnIsRejectedBeforeTransport()
    {
        // Codex R10 #1: the assistant turn opens two calls; the next user
        // turn answers only one — the unanswered remainder was silently
        // discarded and the (400-failing) history sent.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Both.'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart(new FunctionCall('q1', 'ping', array())),
                new MessagePart(new FunctionCall('q2', 'pong', array())),
            )),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('q1', 'ping', array('r' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A partially answered tool turn must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('answer every tool call', $e->getMessage());
            $this->assertStringContainsString('partially answered', $e->getMessage());
        }

        $this->assertNoHttpRequests();

        // Zero answers is equally partial.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('q3', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Not a result.'))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A fully unanswered tool turn followed by a plain user turn must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('partially answered', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testADuplicateToolCallIdInOneTurnIsRejectedBeforeTransport()
    {
        // Codex R10 #2: two tool_use blocks with the same ID — identical
        // names or different — are ambiguous identities; a single result
        // satisfied linkage while the wire carried duplicates.
        foreach (array('ping', 'pong') as $second_name) {
            $prompt = array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
                new Message(MessageRoleEnum::model(), array(
                    new MessagePart(new FunctionCall('dup', 'ping', array())),
                    new MessagePart(new FunctionCall('dup', $second_name, array())),
                )),
            );

            try {
                $this->model()->generateTextResult($prompt);
                $this->fail("A duplicate tool call id (second name {$second_name}) must be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('unique within an assistant message', $e->getMessage());
            }
        }

        $this->assertNoHttpRequests();

        // ID reuse ACROSS turns (different assistant messages) stays legal
        // (R9 probe area — do not regress).
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('reuse', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('reuse', 'ping', array('r' => 1))))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('reuse', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('reuse', 'ping', array('r' => 2))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('reuse', $body['messages'][1]['content'][0]['id']);
        $this->assertSame('reuse', $body['messages'][4]['content'][0]['tool_use_id'], 'Cross-turn reuse stays legal.');
    }

    public function testADuplicateToolCallIdAcrossCoalescedAssistantMessagesIsRejected()
    {
        // Verifier probe on Codex R10 #2: two ADJACENT assistant Messages
        // coalesce into ONE wire turn — the per-message check missed the
        // duplicate while the wire carried two tool_use blocks with the
        // same identity.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('dup', 'ping', array())))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('dup', 'pong', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('dup', 'ping', array('r' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A duplicate tool call id across coalesced assistant messages must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unique within an assistant message', $e->getMessage());
        }

        $this->assertNoHttpRequests();

        // The same-role coalescing boundary is the reset: a DIFFERENT id
        // in the adjacent message stays legal — and under the R11 #1
        // wire-level completeness rule, both ids form ONE answerable turn.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('a1', 'ping', array())))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('b1', 'pong', array())))),
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('a1', 'ping', array('r' => 1))),
                new MessagePart(new FunctionResponse('b1', 'pong', array('r' => 2))),
            )),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('a1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('b1', $body['messages'][2]['content'][1]['tool_use_id'], 'One wire turn, both answered — valid.');
    }

    public function testASplitAnswerAcrossAdjacentUserMessagesIsOneWireTurn()
    {
        // Codex R11 #1 core case: results for a multi-tool assistant turn
        // split across ADJACENT SDK user messages coalesce into ONE
        // wire-level user turn containing every result — previously the
        // completeness check ran after the first SDK message and rejected
        // the still-outstanding call.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Both, split.'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart(new FunctionCall('s1', 'get_weather', array('city' => 'Oslo'))),
                new MessagePart(new FunctionCall('s2', 'get_time', array('zone' => 'CET'))),
            )),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('s1', 'get_weather', array('temp_c' => 21))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('s2', 'get_time', array('hour' => 14))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        // ONE wire user turn carrying both results, in order.
        $this->assertCount(3, $body['messages']);
        $this->assertSame('user', $body['messages'][2]['role']);
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('s1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('s2', $body['messages'][2]['content'][1]['tool_use_id']);

        // Still missing a result after the FULL coalesced turn → rejected.
        WpHarness::$sdk_http_attempts = array();
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Split, incomplete.'))),
            new Message(MessageRoleEnum::model(), array(
                new MessagePart(new FunctionCall('u1', 'ping', array())),
                new MessagePart(new FunctionCall('u2', 'pong', array())),
            )),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('u1', 'ping', array('r' => 1))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Plain continuation text.'))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A still-incomplete answer after full coalescing must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('partially answered', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testTextBeforeACoalescedToolResultIsRejected()
    {
        // Codex R12 #2 (a): a text Message adjacent-before a
        // FunctionResponse Message merges into a user wire turn with text
        // before the tool_result — Anthropic requires results first.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('o1', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Here you go:'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('o1', 'ping', array('ok' => 1))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('Text before a coalesced tool result must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('precede text blocks', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testTextBeforeAToolResultInOneMessageIsRejected()
    {
        // Codex R12 #2 (b): the same invalid order inside a single SDK
        // message's block array.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('o2', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(
                new MessagePart('Result follows:'),
                new MessagePart(new FunctionResponse('o2', 'ping', array('ok' => 1))),
            )),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('Text before a tool result within one message must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('precede text blocks', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testToolResultsFirstThenTextIsAcceptedInOrder()
    {
        // Codex R12 #2 (iii): results first, then trailing text — valid,
        // and the wire payload keeps that order.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('o3', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('o3', 'ping', array('ok' => 1))),
                new MessagePart('Thanks, continuing.'),
            )),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('text', $body['messages'][2]['content'][1]['type'], 'Trailing text after the results keeps its position.');
    }

    public function testATextOnlyUserTurnUnrelatedToToolsIsUnaffected()
    {
        // Codex R12 #2 (iv): plain user text with no tool_result anywhere
        // is not an answering turn — no ordering judgment applies.
        $config = ModelConfig::fromArray(array('systemInstruction' => 'You are terse.'));

        list($url, $body) = $this->captureRequest(
            array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('Hello.'))),
                new Message(MessageRoleEnum::user(), array(new MessagePart('More context.')),
                ),
            ),
            $this->model($config)
        );

        $this->assertSame(array(
            array('role' => 'user', 'content' => array(
                array('type' => 'text', 'text' => 'Hello.'),
                array('type' => 'text', 'text' => 'More context.'),
            )),
        ), $body['messages']);
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

    /**
     * Builds a Message whose parts bypass the SDK DTO's own role check.
     *
     * The SDK's Message constructor ALREADY rejects a FunctionCall in a
     * user message and a FunctionResponse in a model message, so the
     * malformed history the finding describes can only arrive from an SDK
     * bypass (a relaxed future DTO, a different SDK version, unserialized
     * state). Swapping the private parts via the repo's established
     * reflection pattern simulates exactly that; the provider-level guard
     * must reject it before transport.
     *
     * @param MessageRoleEnum $role The (valid) role to construct with.
     * @param list<MessagePart> $bypassParts The incompatible parts to inject.
     * @return Message
     */
    private function messageWithBypassedParts(MessageRoleEnum $role, array $bypassParts): Message
    {
        $message = new Message($role, array(new MessagePart('placeholder')));

        return Closure::bind(
            static function () use ($message, $bypassParts): Message {
                $message->parts = $bypassParts;

                return $message;
            },
            null,
            Message::class
        )();
    }

    public function testAFunctionCallInAUserMessageIsRejectedBeforeTransport()
    {
        // Codex R5 #1: tool_use belongs in assistant turns — a user turn
        // carrying it would 400 upstream.
        $prompt = array(
            $this->messageWithBypassedParts(MessageRoleEnum::user(), array(
                new MessagePart(new FunctionCall('call_u', 'ping', array())),
            )),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A function call in a user message must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('assistant messages', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAFunctionResponseInAnAssistantMessageIsRejectedBeforeTransport()
    {
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            $this->messageWithBypassedParts(MessageRoleEnum::model(), array(
                new MessagePart(new FunctionResponse('call_v', 'ping', array('ok' => 1))),
            )),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A function response in an assistant message must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('user messages', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testEmptyTextPartsAreDroppedBeforeTransport()
    {
        // Codex R4 #2: the Messages protocol rejects empty text blocks —
        // blank parts must be dropped, not encoded.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(
                new MessagePart(''),
                new MessagePart('Real content.'),
                new MessagePart(''),
            )),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame(array(
            array('type' => 'text', 'text' => 'Real content.'),
        ), $body['messages'][0]['content'], 'Only the non-empty text block may be encoded.');
    }

    public function testAMessageOfOnlyEmptyTextPartsIsRejectedBeforeTransport()
    {
        // With every visible part dropped, the message has no translatable
        // content — the existing pre-transport rejection applies.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart(''))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An all-empty message must be rejected.');
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

    public function testConsecutiveSameRoleMessagesAreCoalescedIntoOneTurn()
    {
        // Codex R1 finding 2: the Messages protocol combines adjacent turns
        // of the same role — such histories must coalesce into a valid
        // request, not be rejected.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('One.'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Two.'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart('Hi.'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart('Again.'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Three.'))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame(array(
            array('role' => 'user', 'content' => array(
                array('type' => 'text', 'text' => 'One.'),
                array('type' => 'text', 'text' => 'Two.'),
            )),
            array('role' => 'assistant', 'content' => array(
                array('type' => 'text', 'text' => 'Hi.'),
                array('type' => 'text', 'text' => 'Again.'),
            )),
            array('role' => 'user', 'content' => array(
                array('type' => 'text', 'text' => 'Three.'),
            )),
        ), $body['messages'], 'Adjacent same-role turns merge, blocks in order; roles still alternate after coalescing.');

        $this->assertMatchesSnapshot('coalesced-history', $url, $body);
    }

    public function testAGenericChatHistoryWithRepeatedUserTurnsRoundTrips()
    {
        // The shape generic histories produce (system note, question,
        // clarification, answer, follow-up).
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('What is X?'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('Context: it is a test.'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart('X is ...'))),
            new Message(MessageRoleEnum::user(), array(new MessagePart('And Y?'))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertCount(3, $body['messages']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertCount(2, $body['messages'][0]['content']);
        $this->assertSame('And Y?', $body['messages'][2]['content'][0]['text']);
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
            $this->assertStringContainsString('non-empty id and name', $e->getMessage());
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
