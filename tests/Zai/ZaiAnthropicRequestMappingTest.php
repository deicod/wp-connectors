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
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request as SdkRequest;
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

    /*
     * GLM12 #14: the capture/snapshot/reject helpers live once in
     * WpConnectorsTestCase; this suite provides its surface's three
     * facts (snapshot directory, capture success body, rejection
     * model).
     */

    /**
     * @return string
     */
    protected function snapshotDirectory(): string
    {
        return __DIR__ . '/../fixtures/snapshots/zai-anthropic';
    }

    /**
     * @return void
     */
    protected function queueCaptureResponse()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('ok'));
    }

    /**
     * @param ModelConfig|null $config
     * @return ZaiAnthropicTextGenerationModel
     */
    protected function snapshotTestModel(?ModelConfig $config = null)
    {
        return $this->model($config);
    }

    /*
     * Snapshots.
     */

    public function testTheHappyPathRunsOneEncodabilityPassOverTheAssembledPayload()
    {        /*
         * glm15-5 (source pin — the efficiency contract, twin of the zai
         * surface's glm13-11/glm14-4 pin): the per-member encodability
         * walk no longer runs eagerly on every request. The
         * request-build chokepoint encodes the assembled payload ONCE
         * and invokes the per-member walk only to ATTRIBUTE a failure,
         * preserving the precise messages the encodability tests above
         * still assert. The eager exceptions are the ones the mapping
         * TRANSFORMS need: the tool-result response and output-schema
         * guidance encodes (their strings ride the wire) and the tool
         * schema's recursion guard (the normalize transform below it
         * would fatal on a self-referential structure no net can
         * reject). The behavioral pin for the ride is
         * testTheGenerationRequestRidesTheNetsPreEncodedBody().
         */
        $source = (string) file_get_contents(
            __DIR__ . '/../../connectors/zai/src/Models/ZaiAnthropicTextGenerationModel.php'
        );

        $this->assertStringContainsString(
            '$encoded = json_encode( $params );',
            $source,
            'The request build must encode the payload exactly once, into the variable that becomes the request body.'
        );
        $this->assertStringContainsString(
            '$data = $this->guard_assembled_params( $params );',
            $source,
            'generateTextResult() hands the assembled params to the one-encode net.'
        );
        $this->assertStringContainsString(
            '$this->guard_wire_values( \is_array( $this->generation_prompt ) ? $this->generation_prompt : array() );',
            $source,
            'The per-member encodability walk runs only as the attribution pass on net failure.'
        );
        $this->assertSame(
            0,
            preg_match_all('/must_encode\( \$text, \'a message text part\'/', $source),
            'No eager per-text-part encodability pre-pass may remain at the mapping site.'
        );
        $this->assertSame(
            1,
            preg_match_all('/must_encode\( \$system_instruction, \'the system instruction\'/', $source),
            'The system-instruction encodability check exists exactly once: the attribution walk, not an eager mapping-site pre-pass.'
        );
    }

    public function testTheGenerationRequestRidesTheNetsPreEncodedBody()
    {
        /*
         * glm15-5 (behavioral pin — the glm14-4 ride ported): the net's
         * ONE json_encode is handed to the Request as its body string
         * (Request stores a string $data as the raw body; getBody()
         * returns it as-is), so the transport's send-time re-encode —
         * the second whole-payload serialization every zai_anthropic
         * generation paid — is gone. getData() is null on the ridden
         * request and the wire body decodes to exactly the params the
         * mapping assembled (the snapshot suite pins the wire shape
         * already).
         */
        $transporter = new class implements \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface {
            /**
             * @var \WordPress\AiClient\Providers\Http\DTO\Request|null
             */
            public $captured_request;

            public function send(\WordPress\AiClient\Providers\Http\DTO\Request $request, ?\WordPress\AiClient\Providers\Http\DTO\RequestOptions $options = null): \WordPress\AiClient\Providers\Http\DTO\Response
            {
                $this->captured_request = $request;

                return new \WordPress\AiClient\Providers\Http\DTO\Response(200, array(), HttpResponseFactory::anthropicMessagesBody('ride ok'));
            }
        };

        $model = $this->model();
        $model->setHttpTransporter($transporter);

        $result = $model->generateTextResult(array(new Message(MessageRoleEnum::user(), array(new MessagePart('Say hi.')))));

        $captured = $transporter->captured_request;

        $this->assertSame('ride ok', $result->toText());
        $this->assertNotNull($captured, 'The capturing transporter must have received the request.');
        $this->assertNull($captured->getData(), 'The params array must not ride the Request; the pre-encoded body string does (glm15-5).');

        $decoded = (array) json_decode((string) $captured->getBody(), true);

        $this->assertSame('glm-5.3', $decoded['model']);
        $this->assertSame('user', $decoded['messages'][0]['role']);
        $this->assertSame(array(array('type' => 'text', 'text' => 'Say hi.')), $decoded['messages'][0]['content']);
    }

    public function testTheGenerationRouteOwnerMatchesTheWireRequest()
    {
        /*
         * glm15-4 (twin of the zai surface's GENERATION_ROUTE pin): the
         * endpoint's generation_url() is the plan-dependent Messages
         * route, and the CAPTURED wire URL must equal it — the live
         * probe's generation-route evidence rides this owner, so the
         * pin keeps the evidence channel honest against a route change.
         */
        list($url) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('Say hi.')))),
            $this->model()
        );

        $this->assertSame(
            \Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint::for_current_settings()->generation_url(),
            $url,
            'The endpoint-owned generation route must be the URL the wire request actually uses.'
        );
    }

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
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_2', 'ping', array('ok' => 1))))),
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

    public function testEmptyObjectMapMembersInsideASchemaNormalizeToObjects()
    {
        /*
         * GLM8 #6: the empty-object normalization fired only when the
         * WHOLE parameters schema was null/[], so a non-empty schema
         * carrying an empty-array member at an object-demanding keyword
         * (['type'=>'object','properties'=>[],'required'=>[]]) shipped
         * "properties":[] on the wire where the protocol's meta-schema
         * wants an object. The object-map keywords (properties here;
         * patternProperties/definitions/$defs below) normalize
         * recursively at every depth; the list-valued 'required' keeps
         * its schema-valid [].
         */
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('get_weather', 'Get the weather', array(
                    'type' => 'object',
                    'properties' => array(),
                    'required' => array(),
                )))->toArray(),
                (new FunctionDeclaration('nested', 'Nested maps', array(
                    'type' => 'object',
                    'properties' => array(
                        'filter' => array(
                            'type' => 'object',
                            'properties' => array(),
                            'patternProperties' => array(),
                        ),
                    ),
                    'definitions' => array(),
                    '$defs' => array(),
                )))->toArray(),
            ),
        ));

        $this->model($config)->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];

        // The empty object maps encode as {} — never [].
        $this->assertStringContainsString('"properties":{}', $raw, 'An empty properties member must encode as an object.');
        $this->assertStringContainsString('"required":[]', $raw, 'The list-valued required member keeps its schema-valid empty list.');
        $this->assertStringContainsString('"patternProperties":{}', $raw, 'Nested object-map keywords normalize too.');
        $this->assertStringContainsString('"definitions":{}', $raw, 'The definitions map normalizes.');
        $this->assertStringContainsString('"$defs":{}', $raw, 'The $defs map normalizes.');
        $this->assertStringNotContainsString('"properties":[]', $raw, 'No object-map member may encode as [].');
        $this->assertStringNotContainsString('"required":{}', $raw, 'The list-valued required member must not become an object.');
    }

    public function testDataValuedSchemaKeywordsPassThroughTheObjectMapWalkUntouched()
    {
        /*
         * GLM8 #6 verifier round: the walk descended into the
         * data-bearing annotation keywords too, silently converting an
         * empty list literally named 'properties' inside a DEFAULT value
         * (or examples/const) to {} — the caller's default/example data
         * shipped altered with no upstream error to surface the change.
         * Schema positions still normalize; data keywords pass verbatim.
         */
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('manage_settings', 'Manages settings', array(
                    'type' => 'object',
                    'properties' => array(
                        'settings' => array(
                            'type' => 'object',
                            'default' => array('properties' => array(), 'tags' => array()),
                            'examples' => array(array('definitions' => array())),
                            'const' => array('$defs' => array()),
                        ),
                    ),
                )))->toArray(),
            ),
        ));

        $this->model($config)->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];

        // The DATA values keep their empty lists verbatim...
        $this->assertStringContainsString('"default":{"properties":[],"tags":[]}', $raw, 'A default value passes through untouched.');
        $this->assertStringContainsString('"examples":[{"definitions":[]}]', $raw, 'An examples value passes through untouched.');
        $this->assertStringContainsString('"const":{"$defs":[]}', $raw, 'A const value passes through untouched.');
        // ...while the enclosing SCHEMA position still normalizes.
        $this->assertStringContainsString('"properties":{"settings":', $raw, 'The enclosing schema positions are unaffected.');
    }

    public function testEmptyArraySubschemasAtSchemaValuedPositionsNormalizeToObjects()
    {
        /*
         * GLM10 #6: GLM8 #6 normalized the four object-MAP keywords only,
         * so an empty-array SUBSCHEMA at every other schema-valued
         * position — a property value of [], items: [], an allOf element
         * — shipped on the wire as JSON [] where the Messages
         * input_schema meta-schema demands an object, surfacing a strict
         * endpoint's 400 as the generic misattributed upstream client
         * error. Every schema-valued position normalizes now; the
         * list-valued keywords keep their (schema-valid) empty lists and
         * the data-valued annotation keywords stay verbatim (pinned
         * above).
         */
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('search', 'Search', array(
                    'type' => 'object',
                    'properties' => array(
                        'filters' => array('type' => 'array', 'items' => array()),
                        'anything' => array(),
                        'meta' => array('type' => 'object', 'additionalProperties' => array()),
                    ),
                    'allOf' => array(array()),
                    'anyOf' => array(),
                    'items' => array(array('type' => 'object'), array()),
                )))->toArray(),
            ),
        ));

        $this->model($config)->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];

        $this->assertStringContainsString('"items":{}', $raw, 'An empty items subschema encodes as the empty-object schema.');
        $this->assertStringContainsString('"anything":{}', $raw, 'An empty property value is an empty subschema, not an empty list.');
        $this->assertStringContainsString('"additionalProperties":{}', $raw, 'An empty additionalProperties subschema encodes as {}.');
        $this->assertStringContainsString('"allOf":[{}]', $raw, 'A subschema-list element encodes as {}.');
        $this->assertStringContainsString('"anyOf":[]', $raw, 'The list-valued anyOf keeps its empty list — the list itself is not a subschema.');
        $this->assertStringContainsString('"items":[{"type":"object"},{}]', $raw, 'The legacy tuple form of items normalizes every element as a subschema.');
        $this->assertStringNotContainsString('"anything":[]', $raw, 'No subschema-valued member may encode as [].');
    }

    public function testAnEmptyItemsWithAnAdditionalItemsSiblingKeepsItsTupleSemantics()
    {
        /*
         * Verifier round on GLM10 #6: an empty items with a sibling
         * additionalItems is the empty TUPLE ('every position is
         * additional') — converting it to {} would silently make the
         * additionalItems constraint inert, weakening the caller's
         * declared schema. It keeps its [] verbatim (the same treatment
         * non-empty tuples get); only an additionalItems-less empty
         * items is the empty schema {} (pinned above).
         */
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('tag_all', 'Tags every element', array(
                    'type' => 'array',
                    'items' => array(),
                    'additionalItems' => array('type' => 'string'),
                )))->toArray(),
            ),
        ));

        $this->model($config)->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];

        $this->assertStringContainsString('"items":[]', $raw, 'The empty tuple keeps its list shape when additionalItems declares the tuple semantics.');
        $this->assertStringContainsString('"additionalItems":{"type":"string"}', $raw, 'The additionalItems constraint ships verbatim.');
        $this->assertStringNotContainsString('"items":{}', $raw, 'The empty-tuple-to-{} conversion must not silently drop the additionalItems constraint.');
    }

    public function testTheSchemaWalkersListTestRidesTheSharedPredicate()
    {
        /*
         * GLM12 #13: the schema walker's list decision rode a PRIVATE
         * is_schema_list() copy beside the shared JsonShape::is_list()
         * this same file already calls — two list-ness predicates in one
         * file (fuzz-verified identical, including the empty array), so
         * a future rule change would land on the shared predicate while
         * the walker kept the stale copy. The merge is behavior-
         * preserving by construction (the tuple tests above are the
         * behavioral pins); this pin holds the mechanism: one predicate,
         * and the walker calls it.
         */
        $source = (string) file_get_contents(
            __DIR__ . '/../../connectors/zai/src/Models/ZaiAnthropicTextGenerationModel.php'
        );

        $this->assertSame(
            0,
            preg_match('/function is_schema_list/', $source),
            'No private list predicate may coexist with the shared JsonShape::is_list().'
        );
        $this->assertStringContainsString(
            'JsonShape::is_list( $member )',
            $source,
            'The schema walker rides the shared predicate.'
        );
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
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_b', 'ping', array('ok' => 1))))),
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
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_c', 'pick', array('picked' => 'a'))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame(array('first' => 'a', 1 => 'b'), $body['messages'][1]['content'][0]['input']);
    }

    /**
     * @dataProvider provideScalarToolArguments
     */
    public function testScalarToolArgumentsAreRejectedBeforeTransport($args, $label)
    {
        /*
         * GLM2 #2: a scalar input is not a JSON object — previously it
         * passed the list-only check and shipped as e.g. "input":"Oslo"
         * (an upstream 400 with the generic client-error surface), and a
         * NAN float detonated in the transport's whole-request JSON
         * encode as a raw JsonException.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_s', 'ping', $args)))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail("Scalar tool arguments ({$label}) must be rejected.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('JSON object', $e->getMessage());
            $this->assertStringContainsString('scalar', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function provideScalarToolArguments()
    {
        return array(
            'non-empty string' => array('Oslo', 'string'),
            'integer' => array(3, 'integer'),
            'float' => array(3.14, 'float'),
            'NAN' => array(NAN, 'NAN float'),
            'INF' => array(INF, 'INF float'),
            'boolean' => array(true, 'boolean'),
        );
    }

    public function testAnEmptyStringArgumentMeansNoArguments()
    {
        // The empty string is an absent-arguments shape some histories
        // carry; it normalizes to the {} no-argument call exactly like
        // null and the empty array.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_e', 'ping', '')))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_e', 'ping', array('ok' => 1))))),
        );

        $this->model()->generateTextResult($prompt);

        $raw = (string) $this->sdkHttpAttempts()[0]['body'];
        $this->assertStringContainsString('"input":{}', $raw, 'An empty-string argument normalizes to the empty object.');
        $this->assertStringNotContainsString('"input":""', $raw, 'The empty string must never reach the wire as the input value.');
    }

    public function testAnObjectToolArgumentPassesUntouched()
    {
        // A stdClass argument (the inbound parser's TOP-LEVEL object-ness
        // preservation for numeric-keyed inputs, GLM1 #2) already IS a
        // JSON object: the GLM2 #2 scalar rejection must not catch it.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('ok'));

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_o', 'pick', (object) array('0' => 'x'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_o', 'pick', array('picked' => 'x'))))),
        );

        $this->model()->generateTextResult($prompt);

        $this->assertStringContainsString(
            '"input":{"0":"x"}',
            (string) $this->sdkHttpAttempts()[0]['body'],
            'A stdClass argument encodes as the JSON object it already is.'
        );
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
            /*
             * GLM4 #1: under production wp_json_encode() this value was
             * lossily rescued and shipped (the model was told altered tool
             * output) — only the strict test stub made the old guard fire.
             * The raw json_encode() oracle rejects it on real sites too.
             */
            'invalid UTF-8 value' => array("binary \xB1\x31 result", 'invalid UTF-8 value'),
        );
    }

    /**
     * @dataProvider provideUnencodableToolArguments
     */
    public function testUnencodableToolArgumentsAreRejectedBeforeTransport($args, $label)
    {
        /*
         * GLM4 #1: the tool_use input passed every SHAPE check (object
         * form) but its VALUES were never encodability-checked — NAN,
         * invalid UTF-8, or a recursive structure reached the transport
         * and threw its whole-request JsonException as the generic 500
         * (zai_error) instead of this typed pre-transport rejection, the
         * exact divergence GLM3 #4 closed for text parts.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_args', 'get_weather', $args)))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail("[{$label}] Unencodable tool arguments must be rejected before transport.");
        } catch (InvalidArgumentException $e) {
            /*
             * GLM7 #11: the separate must_encode pass is gone — the
             * replay guard's own first branch proves unencodability
             * (single serialization), so its message carries both
             * failure modes.
             */
            $this->assertStringContainsString('could not replay tool arguments', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testPrecisionLossToolArgumentsAreRejectedBeforeTransport()
    {
        /*
         * GLM6 #8: the outbound path applied only the ENCODABILITY
         * oracle — an integral float beyond PHP_INT_MAX encodes fine
         * ('{"count":9.3e+18}') so it shipped silently, while the zai
         * surface's outbound mapper and this surface's own inbound
         * parser reject the identical value typed. The shared replay
         * guard closes the gap: the replay-poisoning contract holds on
         * every path.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_pl', 'get_weather', array('count' => 9.3e18))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A precision-loss tool argument must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not replay tool arguments', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testOrdinaryFloatToolArgumentsStillShip()
    {
        // GLM6 #8 guard: only genuinely replay-breaking values reject —
        // ordinary floats (integral below the platform int range
        // included) keep shipping.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_fl', 'get_weather', array('temp_c' => 21.5, 'count' => 9007199254740992.0))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_fl', 'get_weather', array('ok' => true))))),
        );

        list(, $body) = $this->captureRequest($prompt, $this->model());

        $input = $body['messages'][1]['content'][0]['input'];
        $this->assertSame(21.5, $input['temp_c']);
        // JSON carries no int/float distinction: the integral float ships
        // numerically intact (it round-trips the body decode as an int).
        $this->assertEquals(9007199254740992.0, $input['count']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideUnencodableToolArguments()
    {
        $recursive = array('city' => 'Oslo');
        $recursive['self'] = &$recursive;

        return array(
            'NAN argument value' => array(array('amount' => NAN), 'NAN argument value'),
            'invalid UTF-8 argument value' => array(array('city' => "Os\xB1\x31lo"), 'invalid UTF-8 argument value'),
            'recursive arguments' => array($recursive, 'recursive arguments'),
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

    public function testParsedToolInputRoundTripsNestedObjectsOnReplay()
    {
        /*
         * Code-review GLM1 #2: tool-call arguments lost JSON object-ness
         * below the top level — the inbound tool_use input was stored from
         * the associative decode, and the outbound replay normalized only a
         * wholly-empty TOP-LEVEL array to stdClass, so nested empty objects
         * and numeric-keyed objects silently re-encoded as JSON lists on
         * the wire (verified: "filter":[] instead of "filter":{}).
         */
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'),
            '{"id":"msg_tool_obj","type":"message","role":"assistant","content":[{"type":"tool_use","id":"toolu_obj","name":"search","input":{"filter":{},"tags":{"0":"x"},"q":"lit"}}],"stop_reason":"tool_use","usage":{"input_tokens":1,"output_tokens":1}}');

        $result = $this->model()->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));
        $call = $result->toMessage()->getParts()[0]->getFunctionCall();
        $this->assertNotNull($call);

        // The parse must PRESERVE object-ness at every level: the nested
        // empty object and the numeric-keyed object stay objects.
        $args = $call->getArgs();
        $this->assertIsArray($args, 'A mixed-key top-level object stays an array for consumer ergonomics.');
        $this->assertInstanceOf(\stdClass::class, $args['filter'], 'A nested empty object must stay an object, not collapse to [].');
        $this->assertInstanceOf(\stdClass::class, $args['tags'], 'A nested numeric-keyed object must stay an object, not re-encode as a list.');

        // Replay the assistant turn: the wire must carry the SAME shapes.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('ok'));
        $this->model()->generateTextResult(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart($call))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('toolu_obj', 'search', array('ok' => true))))),
        ));

        $attempts = $this->sdkHttpAttempts();
        $this->assertCount(2, $attempts);
        $this->assertStringContainsString(
            '"input":{"filter":{},"tags":{"0":"x"},"q":"lit"}',
            (string) $attempts[1]['body'],
            'The replayed tool_use input must preserve nested object-ness byte-for-byte.'
        );
    }

    public function testExplicitlyClearedToolsAndStopSequencesAreOmitted()
    {
        /*
         * Code-review GLM1 #4: the emission guards only checked
         * is_array(), so explicitly-cleared lists (setters accept [] —
         * array_is_list is true for it) were forwarded as "tools":[] /
         * "stop_sequences":[], and the Messages API rejects an empty
         * tools array with a 400. Empty is semantically "not set" for
         * both fields: omitted.
         */
        $config = ModelConfig::fromArray(array());
        $config->setFunctionDeclarations(array());
        $config->setStopSequences(array());

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        $this->assertArrayNotHasKey('tools', $body, 'An empty tools array must be omitted, not forwarded.');
        $this->assertArrayNotHasKey('stop_sequences', $body, 'An empty stop_sequences array must be omitted, not forwarded.');
    }

    /**
     * @dataProvider provideMalformedStopSequences
     */
    public function testMalformedStopSequenceEntriesAreRejectedBeforeTransport(array $sequences)
    {
        /*
         * GLM3 #3: the SDK setter checks only list-ness, so non-string
         * and empty-string entries reached the wire and failed upstream
         * with the generic misattributed 400 message — the typed
         * pre-transport rejection applies to every entry, like the
         * neighboring malformed-input checks.
         */
        $config = ModelConfig::fromArray(array());
        $config->setStopSequences($sequences);

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('Malformed stop sequence entries must be rejected before transport: ' . wp_json_encode($sequences));
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires every stop sequence to be a non-empty string', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function provideMalformedStopSequences()
    {
        return array(
            'integer entry' => array(array(0)),
            'empty string entry' => array(array('')),
            'null after a valid entry' => array(array('END', null)),
            'list entry' => array(array(array('END'))),
        );
    }

    public function testAnInvalidUtf8TextPartIsRejectedBeforeTransport()
    {
        /*
         * GLM3 #4: a binary-mangled string passed every is_string check
         * and threw a raw JsonException from the transport's
         * whole-request encode, surfacing as the generic 500 (zai_error)
         * — while the same unencodable value inside a tool result got the
         * precise typed 400 rejection (R18). Checked with the RAW
         * json_encode() oracle (verifier round: core's wp_json_encode()
         * lossily rescues invalid UTF-8 and never returns false for a
         * string, so a guard on it would be dead code in production);
         * mb_check_encoding() would require ext-mbstring, which
         * WordPress does not guarantee.
         */
        try {
            $this->model()->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart("binary \xB1\x31 mangled"))),
            ));
            $this->fail('An invalid-UTF-8 text part must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not JSON-encode a message text part', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAnInvalidUtf8TextPartSurfacesAsTheTypedInvalidRequestError()
    {
        // GLM3 #4: the direct-use WP_Error boundary maps the rejection to
        // zai_invalid_request (400), not the generic zai_error 500 the
        // raw JsonException produced.
        $error = $this->model()->generate_text(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart("binary \xB1\x31 mangled"))),
        ));

        $this->assertWPError($error, 'zai_invalid_request');
    }

    public function testAnInvalidUtf8SystemInstructionIsRejectedBeforeTransport()
    {
        // GLM3 #4: the system member is a wire string — same typed
        // rejection as text parts.
        $config = ModelConfig::fromArray(array());
        $config->setSystemInstruction("system \xB1\x31");

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An invalid-UTF-8 system instruction must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not JSON-encode the system instruction', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAnInvalidUtf8StopSequenceIsRejectedBeforeTransport()
    {
        // GLM3 #4: stop sequences are wire strings too.
        $config = ModelConfig::fromArray(array());
        $config->setStopSequences(array('END', "stop \xB1\x31"));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An invalid-UTF-8 stop sequence must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not JSON-encode a stop sequence', $e->getMessage());
        }

        $this->assertNoHttpRequests();
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
        // GLM5 #11: the runtime (save-time candidate) source normalizes to the
        // database identity at binding construction.
        $binding = hash('sha256', 'database|' . $endpoint->cache_key() . '|' . $key);

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

    public function testAnUnboundModelWithInvalidOptionsYieldsTheOptionRejection()
    {
        /*
         * GLM2 #3: the credential gate called getRequestAuthentication()
         * unguarded, so an unbound model (no wired auth — the direct
         * ZaiAnthropicProvider::model() path) carrying invalid options
         * threw the SDK's binding RuntimeException BEFORE validate_request()
         * could reject them; the OpenAI twin's gate guards exactly this
         * divergence and is the model for the fix. The gate now skips an
         * unbound model, so the typed option rejection wins.
         */
        $config = ModelConfig::fromArray(array('candidateCount' => 2));
        $this->primeZaiAnthropicDiscoveryTransient();
        $model = ZaiAnthropicProvider::model('glm-5.3', $config);
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        // Deliberately NO setRequestAuthentication(): the model is unbound.

        try {
            $model->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('An unbound model with invalid options must still yield the typed option rejection.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('candidateCount', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * A foreign (non-API-key) authentication wiring for the GLM3 #9 tests.
     *
     * @return RequestAuthenticationInterface
     */
    private function foreignAuthentication()
    {
        return new class implements RequestAuthenticationInterface {
            public function authenticateRequest(SdkRequest $request): SdkRequest
            {
                return $request;
            }

            public static function getJsonSchema(): array
            {
                return array();
            }
        };
    }

    public function testAForeignWiringWithInvalidOptionsYieldsTheOptionRejection()
    {
        /*
         * GLM3 #9: the gate consulted its own wrap()-ing
         * getRequestAuthentication() override, so a foreign
         * RequestAuthenticationInterface wiring threw the wrap failure
         * BEFORE validate_request() — a wiring failure misattributed as
         * a 400 zai_invalid_request that also stole the typed option
         * rejection's precedence. The gate now reads the RAW wired
         * instance (the OpenAI twin's pattern): the gate skips foreign
         * wiring, and the option rejection wins.
         */
        $config = ModelConfig::fromArray(array('candidateCount' => 2));
        $this->primeZaiAnthropicDiscoveryTransient();
        $model = ZaiAnthropicProvider::model('glm-5.3', $config);
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication($this->foreignAuthentication());

        try {
            $model->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail('A foreign wiring with invalid options must still yield the typed option rejection.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('candidateCount', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAForeignWiringSurfacesAsTheBindingErrorNotAnInvalidRequest()
    {
        /*
         * GLM3 #9: with VALID options, the foreign-wiring failure
         * surfaces at request-build time in the binding-failure
         * RuntimeException family — ErrorMapper maps it to 500
         * zai_error, not the 400 zai_invalid_request the wrap
         * InvalidArgumentException produced before.
         */
        $this->primeZaiAnthropicDiscoveryTransient();
        $model = ZaiAnthropicProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication($this->foreignAuthentication());

        $error = $model->generate_text(array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
        ));

        $this->assertWPError($error, 'zai_error');
        $this->assertStringContainsString('API-key authentication', $error->get_error_message(), 'The wiring failure message must identify the binding problem.');
        $this->assertNoHttpRequests();
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

    /**
     * @dataProvider provideUnencodableToolSchemas
     */
    public function testUnencodableToolSchemasAreRejectedBeforeTransport($schema, $label)
    {
        /*
         * R20 (inline 3907008524): an unencodable parameter schema used to
         * surface in the transport's whole-request serialization; the
         * adapter's pre-transport configuration error is the right
         * channel (as for output schemas and tool results).
         */
        $config = ModelConfig::fromArray(array(
            'functionDeclarations' => array(
                (new FunctionDeclaration('ping', 'Pings', $schema))->toArray(),
            ),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            ));
            $this->fail("[{$label}] An unencodable tool parameter schema must be rejected.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('could not JSON-encode a declared tool parameter schema', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideUnencodableToolSchemas()
    {
        $recursive = array('type' => 'object');
        $recursive['self'] = &$recursive;

        return array(
            'NAN inside the schema' => array(array('type' => NAN), 'NAN inside the schema'),
            'invalid UTF-8 inside the schema' => array(array('type' => "\xB1\x31"), 'invalid UTF-8 inside the schema'),
            'resource inside the schema' => array(array('type' => fopen('php://memory', 'r')), 'resource inside the schema'),
            'recursive schema' => array($recursive, 'recursive schema'),
        );
    }

    public function testAnInvalidUtf8ToolDeclarationNameIsRejectedBeforeTransport()
    {
        // GLM6 #9: the declared name rides the tools member verbatim —
        // encodability-guarded like every other wire string.
        $config = ModelConfig::fromArray(array());
        $config->setFunctionDeclarations(array(
            new FunctionDeclaration("tool_\xB1\x31", 'Looks up the weather.', array('type' => 'object')),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An invalid-UTF-8 declared tool name must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai_anthropic provider could not JSON-encode a declared tool function name', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAnInvalidUtf8ToolDeclarationDescriptionIsRejectedBeforeTransport()
    {
        /*
         * GLM6 #9: the description was only ever EMPTINESS-free — an
         * unencodable one (from a DB row, say) reached the transport and
         * its whole-request encode threw the raw JsonException the
         * mapper's catch-all turned into the generic 500, instead of the
         * typed 400 every neighboring wire string receives.
         */
        $config = ModelConfig::fromArray(array());
        $config->setFunctionDeclarations(array(
            new FunctionDeclaration('get_weather', "desc with \xB1\x31 invalid utf-8", array('type' => 'object')),
        ));

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An invalid-UTF-8 declared tool description must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai_anthropic provider could not JSON-encode a declared tool function description', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testInvalidUtf8ReplayedToolUseIdentitiesAreRejectedBeforeTransport()
    {
        // GLM6 #9: the replayed tool_use id/name are wire strings too.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall("call_\xB1\x31", 'get_weather', array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse("call_\xB1\x31", 'get_weather', array('ok' => true))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An invalid-UTF-8 tool call id must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai_anthropic provider could not JSON-encode a tool call id', $e->getMessage());
        }

        $this->assertNoHttpRequests();

        WpHarness::$sdk_http_attempts = array();

        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_ok', "get_weather_\xB1\x31", array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('call_ok', "get_weather_\xB1\x31", array('ok' => true))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An invalid-UTF-8 tool call name must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai_anthropic provider could not JSON-encode a tool call name', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testAnInvalidUtf8ToolResultIdIsRejectedBeforeTransport()
    {
        // GLM6 #9: the tool_result tool_use_id is a wire string like the
        // rest — encodability-guarded, not just emptiness-checked. (The
        // answered call itself stays valid, isolating THIS guard; the
        // identity guards above cover the call side.)
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('call_ok2', 'get_weather', array('city' => 'Oslo'))))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse("call_r_\xB1\x31", 'get_weather', array('ok' => true))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('An invalid-UTF-8 tool result id must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai_anthropic provider could not JSON-encode a tool result tool_use id', $e->getMessage());
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

    public function testAHistoryEndingOnAnAssistantToolTurnIsRejectedBeforeTransport()
    {
        /*
         * GLM2 #1: the Messages API requires every tool_use to be followed
         * by its tool_result blocks, so a history ending on the open
         * assistant tool turn itself is invalid — previously it shipped and
         * failed as an upstream 400 with the generic client-error surface
         * instead of this typed pre-transport rejection.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('tail_call', 'ping', array())))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A history ending on an unanswered assistant tool turn must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('end of the conversation', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testATrailingAssistantToolTurnAfterACompleteRoundIsRejected()
    {
        // The completed first round stays valid; only the trailing
        // unanswered second round fires the end-of-history rejection.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('done', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('done', 'ping', array('r' => 1))))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('open', 'pong', array())))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A trailing unanswered assistant tool turn must be rejected after a complete round too.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('end of the conversation', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }

    public function testATrailingAssistantTextTurnStillShips()
    {
        // A trailing assistant turn WITHOUT tool calls is the protocol's
        // prefill shape and stays legitimate — the GLM2 #1 rejection is
        // scoped to unanswered tool turns only.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('Start a haiku about caches.'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart('Stale data whispers'))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('assistant', $body['messages'][1]['role']);
        $this->assertSame('Stale data whispers', $body['messages'][1]['content'][0]['text']);
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
                $this->assertStringContainsString('unique across the conversation', $e->getMessage());
            }
        }

        $this->assertNoHttpRequests();

        /*
         * GLM5 #6: ID reuse ACROSS turns (different assistant messages)
         * used to stay legal — the duplicate map reset at every role
         * change, so two different, properly answered assistant turns
         * shipped with the SAME identity (ambiguous tool-result
         * correlation; a strict upstream implementation 400s). The
         * uniqueness scope spans the whole conversation now.
         */
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('reuse', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('reuse', 'ping', array('r' => 1))))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('reuse', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('reuse', 'ping', array('r' => 2))))),
        );

        try {
            $this->model()->generateTextResult($prompt);
            $this->fail('A duplicate tool call id across different assistant turns must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unique across the conversation', $e->getMessage());
        }

        $this->assertNoHttpRequests();

        // DIFFERENT ids across turns keep replaying fine.
        $prompt = array(
            new Message(MessageRoleEnum::user(), array(new MessagePart('go'))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('r1', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('r1', 'ping', array('r' => 1))))),
            new Message(MessageRoleEnum::model(), array(new MessagePart(new FunctionCall('r2', 'ping', array())))),
            new Message(MessageRoleEnum::user(), array(new MessagePart(new FunctionResponse('r2', 'ping', array('r' => 2))))),
        );

        list($url, $body) = $this->captureRequest($prompt, $this->model());

        $this->assertSame('r1', $body['messages'][1]['content'][0]['id']);
        $this->assertSame('r2', $body['messages'][3]['content'][0]['id']);
        $this->assertSame('r1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('r2', $body['messages'][4]['content'][0]['tool_use_id'], 'Distinct cross-turn ids stay legal.');
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
            $this->assertStringContainsString('unique across the conversation', $e->getMessage());
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

    public function testOutOfRangeTemperatureAndTopPAreRejectedBeforeTransport()
    {
        /*
         * Code-review GLM1 #8: temperature/top_p were forwarded verbatim
         * with no bounds check, although values the SDK and the OpenAI
         * surface accept (temperature up to 2.0) violate the Anthropic
         * Messages protocol's 0..1 range — surfacing only as an upstream
         * 400 with the generic misattributed message. Typed pre-transport
         * rejection citing the range; no silent clamping.
         */
        $this->assertRejectedBeforeTransport(ModelConfig::fromArray(array('temperature' => 1.5)), 'temperature between 0 and 1');
        $this->assertRejectedBeforeTransport(ModelConfig::fromArray(array('temperature' => 2.0)), 'temperature between 0 and 1');
        $this->assertRejectedBeforeTransport(ModelConfig::fromArray(array('temperature' => -0.1)), 'temperature between 0 and 1');
        $this->assertRejectedBeforeTransport(ModelConfig::fromArray(array('topP' => 1.5)), 'top_p between 0 and 1');
        $this->assertRejectedBeforeTransport(ModelConfig::fromArray(array('topP' => -1)), 'top_p between 0 and 1');
    }

    public function testNanTemperatureAndTopPAreRejectedBeforeTransport()
    {
        /*
         * GLM2 #4: NAN compares false against BOTH range bounds, so it
         * slipped the GLM1 #8 guard and reached the transport, where the
         * whole-request JSON encode threw a raw 'Inf and NaN cannot be
         * JSON encoded' JsonException instead of the typed rejection the
         * guard exists to produce.
         */
        $this->assertRejectedBeforeTransport(ModelConfig::fromArray(array('temperature' => fdiv(0, 0))), 'temperature between 0 and 1');
        $this->assertRejectedBeforeTransport(ModelConfig::fromArray(array('topP' => fdiv(0, 0))), 'top_p between 0 and 1');
    }

    public function testBoundaryTemperatureAndTopPAreForwardedVerbatim()
    {
        // The closed interval is legal: 0.0 and 1.0 forward verbatim
        // (0.0 is falsy — the guard must use explicit comparisons).
        $config = ModelConfig::fromArray(array('temperature' => 0.0, 'topP' => 1.0));

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        // JSON round trip: boundary floats re-decode as ints — assert
        // presence and exact numeric value.
        $this->assertSame(0, $body['temperature']);
        $this->assertSame(1, $body['top_p']);
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
         * GLM4 #4 narrowed the tolerance to the WIRE-INERT options: this
         * surface never emits unsupported parameters at all, and the
         * shared guard keeps the zai twin's rule that the options the
         * OpenAI request builder forwards (presence/frequency penalty,
         * logprobs, top logprobs) reject even neutral values — see the
         * zai surface's provider test. The rule is shared so a config
         * accepted by one surface is accepted by the other.
         */
        $config = ModelConfig::fromArray(array());
        $config->setTopK(0);

        list($url, $body) = $this->captureRequest(
            array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi')))),
            $this->model($config)
        );

        $this->assertSame('glm-5.3', $body['model'], 'The request must proceed with wire-inert falsy option values.');
    }

    public function testTheSharedGuardTreatsWireInertFalsyFlavorsAsNotSet()
    {
        /*
         * Direct unit coverage of the flavors the typed setters cannot
         * express ('0', '') for the WIRE-INERT options — the request
         * builder never forwards these, so a set falsy value is a no-op.
         * GLM4 #4: the wire-FORWARDED falsy flavors (presence penalty
         * 0.0, logprobs false, ...) now reject instead — they would
         * ship on the zai surface — plus a truthy control that still
         * rejects.
         *
         * GLM7 #15: the rejection RULE stays shared, but each surface's
         * justification is truthful — the zai surface's builder forwards
         * the keys ('would still be sent to the API'), this surface's
         * never emits them ('one option contract').
         */
        \Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard::reject_unsupported(array(
            'topK' => '0',
            'webSearch' => '',
            'outputFileType' => array(),
            'outputSpeechVoice' => null,
        ), 'zai_anthropic', false);

        foreach (array(
            'presencePenalty' => 0.0,
            'frequencyPenalty' => 0,
            'logprobs' => false,
            'topLogprobs' => 0,
        ) as $key => $value) {
            try {
                \Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard::reject_unsupported(array($key => $value), 'zai_anthropic', false);
                $this->fail("An explicitly-set falsy {$key} must be rejected on this surface too (one option contract).");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('one option contract', $e->getMessage());
                $this->assertStringNotContainsString('would still be sent to the API', $e->getMessage(), 'This surface never emits the forwarded keys — the forwarding justification would be false.');
            }

            try {
                \Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard::reject_unsupported(array($key => $value), 'z.ai');
                $this->fail("An explicitly-set falsy {$key} must be rejected on the zai surface (it would ship).");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('would still be sent to the API', $e->getMessage());
            }
        }

        try {
            \Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard::reject_unsupported(array('presencePenalty' => 0.5), 'zai_anthropic', false);
            $this->fail('A truthy unsupported value must still be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('presence penalty', $e->getMessage());
        }
    }

    public function testAFalsyForwardedOptionOnTheAnthropicSurfaceRejectsWithTheTruthfulMessage()
    {
        /*
         * GLM7 #15 (model-level): a caller neutralizing a previously-set
         * option on this surface gets the truthful justification — the
         * rejection exists for the cross-surface contract, not a wire
         * forwarding this surface's builder never performs.
         */
        $config = ModelConfig::fromArray(array());
        $config->setPresencePenalty(0.0);

        try {
            $this->model($config)->generateTextResult(array(
                new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))),
            ));
            $this->fail('An explicitly-set falsy forwarded option must be rejected before transport.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('The zai_anthropic provider does not support presence penalty', $e->getMessage());
            $this->assertStringContainsString('one option contract', $e->getMessage());
            $this->assertStringNotContainsString('would still be sent to the API', $e->getMessage());
        }

        $this->assertNoHttpRequests();
    }
}
