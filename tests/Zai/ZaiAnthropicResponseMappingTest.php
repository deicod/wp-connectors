<?php
/**
 * Task 2.6 — Messages response, streaming, and error mapping tests.
 *
 * Covers non-streaming parsing (content blocks, tool_use, thinking, stop
 * reasons, usage), the Anthropic SSE event sequence incl. interleaved
 * text/tool deltas, malformed frames, a final event without trailing blank
 * line, error events, every required error-status mapping with redaction of
 * upstream bodies (which may contain secrets), the typed WP_Error mapper,
 * and the real core-builder dispatch path.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Support\AnthropicSseAggregator;
use Deicod\WpConnectors\Zai\Support\ErrorMapper;

final class ZaiAnthropicResponseMappingTest extends WpConnectorsTestCase
{
    /**
     * Wired model instance.
     *
     * @return ZaiAnthropicTextGenerationModel
     */
    private function model()
    {
        $this->primeZaiAnthropicDiscoveryTransient();
        $model = ZaiAnthropicProvider::model('glm-5.3');
        $model->setHttpTransporter(AiClient::defaultRegistry()->getHttpTransporter());
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(FakeSecrets::apiKey()));

        return $model;
    }

    /**
     * @return list<Message>
     */
    private function prompt()
    {
        return array(new Message(MessageRoleEnum::user(), array(new MessagePart('hi'))));
    }

    /**
     * Builds a minimal valid stream around one content_block_delta payload.
     *
     * @param string $deltaJson The delta event's data payload.
     * @return string
     */
    private function streamWithDelta(string $deltaJson): string
    {
        return ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_sw","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: ' . $deltaJson . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";
    }

    /**
     * Asserts one content_block_delta payload invalidates the stream.
     *
     * @param string $deltaJson The delta event's data payload.
     * @return void
     */
    private function assertDeltaInvalidates(string $deltaJson): void
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $this->streamWithDelta($deltaJson));

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A wrong-shape content delta must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    /*
     * Non-streaming success.
     */

    public function testParsesContentStopReasonAndUsage()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), (string) wp_json_encode(array(
            'id' => 'msg_01ABC',
            'type' => 'message',
            'role' => 'assistant',
            'content' => array(array('type' => 'text', 'text' => 'Hello there.')),
            'model' => 'glm-5.3',
            'stop_reason' => 'end_turn',
            'usage' => array('input_tokens' => 7, 'output_tokens' => 3),
        )));

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hello there.', $result->toText());
        $this->assertSame('msg_01ABC', $result->getId());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
        $this->assertSame(7, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(3, $result->getTokenUsage()->getCompletionTokens());
        $this->assertSame(10, $result->getTokenUsage()->getTotalTokens());
    }

    public function testCacheTokenVariantsCountAsPromptTokens()
    {
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_cache',
            'role' => 'assistant',
            'content' => array(array('type' => 'text', 'text' => 'ok')),
            'stop_reason' => 'end_turn',
            'usage' => array('input_tokens' => 5, 'cache_creation_input_tokens' => 2, 'cache_read_input_tokens' => 10, 'output_tokens' => 4),
        )));

        $usage = $this->model()->generateTextResult($this->prompt())->getTokenUsage();

        $this->assertSame(17, $usage->getPromptTokens());
        $this->assertSame(4, $usage->getCompletionTokens());
        $this->assertSame(21, $usage->getTotalTokens());
    }

    public function testParsesThinkingAsThoughtPart()
    {
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_think',
            'role' => 'assistant',
            'content' => array(
                array('type' => 'thinking', 'thinking' => 'pondering...'),
                array('type' => 'text', 'text' => 'Answer.'),
            ),
            'stop_reason' => 'end_turn',
            'usage' => array('input_tokens' => 4, 'output_tokens' => 2),
        )));

        $parts = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts();

        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('pondering...', $parts[0]->getText());
        $this->assertSame('Answer.', $parts[1]->getText());
    }

    public function testParsesToolUseAndFinishReason()
    {
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_tool',
            'role' => 'assistant',
            'content' => array(array(
                'type' => 'tool_use',
                'id' => 'toolu_01XYZ',
                'name' => 'get_weather',
                'input' => array('city' => 'Oslo'),
            )),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 9, 'output_tokens' => 6),
        )));

        $result = $this->model()->generateTextResult($this->prompt());
        $call = $result->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertSame('toolu_01XYZ', $call->getId());
        $this->assertSame('get_weather', $call->getName());
        $this->assertSame(array('city' => 'Oslo'), $call->getArgs());
        $this->assertSame(FinishReasonEnum::toolCalls(), $result->getCandidates()[0]->getFinishReason());
    }

    public function testEmptyToolUseInputNormalizesToNullArgs()
    {
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_tool_empty',
            'role' => 'assistant',
            'content' => array(array('type' => 'tool_use', 'id' => 'toolu_02', 'name' => 'ping', 'input' => new stdClass())),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertNull($call->getArgs(), 'An empty input object means "no arguments".');
    }

    /**
     * @dataProvider provideAbsentOrNullToolInputs
     */
    public function testAbsentOrNullToolUseInputsAreRejected($inputFragment)
    {
        // Codex R7 #1 supersedes the R2 tolerance: the Messages protocol
        // REQUIRES tool_use.input (an empty call is {} alone) — an omitted
        // or explicitly-null member must not be normalized into a
        // fabricated no-argument FunctionCall.
        $this->queueSdkResponse(200, array(), '{"id":"msg_tool_absent","type":"message","role":"assistant","content":[{"type":"tool_use","id":"toolu_03","name":"ping"' . $inputFragment . '}],"stop_reason":"tool_use","usage":{"input_tokens":1,"output_tokens":1}}');

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('An absent/null input member must be rejected, got a call with args: ' . wp_json_encode($result->toMessage()->getParts()[0]->getFunctionCall()->getArgs()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('missing its input member', $e->getMessage());
        }

        // The typed boundary surfaces the same verdict.
        $this->queueSdkResponse(200, array(), '{"id":"msg_tool_absent","type":"message","role":"assistant","content":[{"type":"tool_use","id":"toolu_03","name":"ping"' . $inputFragment . '}],"stop_reason":"tool_use","usage":{"input_tokens":1,"output_tokens":1}}');
        $error = $this->model()->generate_text($this->prompt());
        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_INVALID_RESPONSE);
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideAbsentOrNullToolInputs()
    {
        return array(
            'input omitted' => array(''),
            'input explicit null' => array(',"input":null'),
        );
    }

    public function testAListToolUseInputFailsAsATypedParseError()
    {
        // Codex R2 #1: the regular (non-streaming) response path must
        // reject non-object tool arguments exactly like the R1 SSE fix —
        // passing ["PARIS"] through would fabricate arguments a consumer
        // might execute a side-effecting tool with.
        $body = (string) wp_json_encode(array(
            'id' => 'msg_tool_list',
            'role' => 'assistant',
            'content' => array(array('type' => 'tool_use', 'id' => 'toolu_04', 'name' => 'delete_file', 'input' => array('/etc/hosts'))),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A list tool input must fail, got: ' . $result->toText());
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('non-object input', $e->getMessage());
            $this->assertRedacted($e->getMessage(), '/etc/hosts');
            $this->assertStringNotContainsString('delete_file', $e->getMessage(), 'Upstream tool names must not be echoed.');
        }

        // The typed boundary surfaces the same verdict.
        $this->queueSdkResponse(200, array(), $body);
        $error = $this->model()->generate_text($this->prompt());
        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_INVALID_RESPONSE);
        $this->assertRedacted($error->get_error_message(), '/etc/hosts');
    }

    public function testAnEmptyJsonListToolUseInputFailsAsATypedParseError()
    {
        // Codex R3 #1: associative decoding collapses "input":[] and
        // "input":{} to the same empty PHP array — the empty LIST must not
        // slip through as a fabricated no-argument call.
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_tool_emptylist',
            'role' => 'assistant',
            'content' => array(array('type' => 'tool_use', 'id' => 'toolu_06', 'name' => 'delete_file', 'input' => array())),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('An empty-list tool input must fail, got a call with args: ' . wp_json_encode($result->toMessage()->getParts()[0]->getFunctionCall()->getArgs()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('non-object input', $e->getMessage());
            $this->assertStringNotContainsString('delete_file', $e->getMessage());
        }
    }

    public function testABooleanToolUseInputFailsAsATypedParseError()
    {
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_tool_bool',
            'role' => 'assistant',
            'content' => array(array('type' => 'tool_use', 'id' => 'toolu_07', 'name' => 'ping', 'input' => true)),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A boolean tool input must fail.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('non-object input', $e->getMessage());
        }
    }

    public function testAScalarToolUseInputFailsAsATypedParseError()
    {
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_tool_scalar',
            'role' => 'assistant',
            'content' => array(array('type' => 'tool_use', 'id' => 'toolu_05', 'name' => 'ping', 'input' => 'PARIS')),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A scalar tool input must fail.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('non-object input', $e->getMessage());
            $this->assertStringNotContainsString('PARIS', $e->getMessage(), 'Upstream values must not be echoed.');
        }
    }

    /**
     * @dataProvider provideStopReasons
     */
    public function testStopReasonsMapToFinishReasons($stopReason, $expected)
    {
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('x', null, $stopReason));

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame($expected, $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideStopReasons()
    {
        return array(
            'end_turn' => array('end_turn', FinishReasonEnum::stop()),
            'stop_sequence' => array('stop_sequence', FinishReasonEnum::stop()),
            'pause_turn' => array('pause_turn', FinishReasonEnum::stop()),
            'tool_use' => array('tool_use', FinishReasonEnum::toolCalls()),
            'refusal' => array('refusal', FinishReasonEnum::contentFilter()),
        );
    }

    public function testMaxTokensStopReasonThrowsTheTypedTokenLimit()
    {
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('trunc', null, 'max_tokens'));

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A max_tokens stop reason must throw.');
        } catch (TokenLimitReachedException $e) {
            $this->assertStringContainsString('token limit', $e->getMessage());
            $this->assertSame(4096, $e->getMaxTokens(), 'The typed payload carries the applied limit for consumers of the accessor.');
        }
    }

    /*
     * Malformed payloads: fixed, redacted messages.
     */

    public function testContextWindowExhaustionGetsItsOwnAdvice()
    {
        // Codex R5 #4: model_context_window_exceeded is NOT a max_tokens
        // case — raising maxTokens cannot recover it and leaves even less
        // room; the advice must be to reduce the input.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('partial', null, 'model_context_window_exceeded'));

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('Context-window exhaustion must throw.');
        } catch (TokenLimitReachedException $e) {
            $this->assertStringContainsString('context window', $e->getMessage());
            $this->assertStringContainsString('truncate the history or shorten the prompt', $e->getMessage());
            $this->assertStringNotContainsString('Raise maxTokens', $e->getMessage(), 'The maxTokens advice must stay on the max_tokens case only.');
            $this->assertNull($e->getMaxTokens(), 'No output-token limit is attributable to context-window exhaustion.');
        }

        // The typed WP_Error boundary carries the same distinction.
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('partial', null, 'model_context_window_exceeded'));
        $error = $this->model()->generate_text($this->prompt());

        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_TOKEN_LIMIT);
        $this->assertStringContainsString('context window', $error->get_error_message());
        $this->assertStringNotContainsString('configured token limit', $error->get_error_message());
    }

    public function testGenuineMaxTokensKeepsTheRaiseAdvice()
    {
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('trunc', null, 'max_tokens'));

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('max_tokens must throw.');
        } catch (TokenLimitReachedException $e) {
            $this->assertStringContainsString('Raise maxTokens', $e->getMessage());
            $this->assertSame(4096, $e->getMaxTokens());
        }
    }

    /**
     * @dataProvider provideContradictoryEnvelopeTypes
     */
    public function testAContradictoryEnvelopeTypeIsRejected($typeValue, $present)
    {
        // R6 #1 adds the explicit-null case: isset() treated "type": null
        // as an omitted member; array_key_exists() sees it as present.
        $payload = array(
            'id' => 'msg_env',
            'role' => 'assistant',
            'content' => array(array('type' => 'text', 'text' => 'Ambiguous content.')),
            'stop_reason' => 'end_turn',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        );
        if ($present) {
            $payload['type'] = $typeValue;
        }

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), (string) wp_json_encode($payload));

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A contradictory envelope type must be rejected, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('message', $e->getMessage());
            $this->assertStringNotContainsString('Ambiguous content.', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideContradictoryEnvelopeTypes()
    {
        return array(
            'type error' => array('error', true),
            'type null (explicit)' => array(null, true),
            'type non-string' => array(7, true),
            'type banana' => array('banana', true),
        );
    }

    public function testAStreamedUserRoleIsRejectedNotFabricatedAsAssistant()
    {
        // Codex R6 #2: the aggregator hardcodes role:assistant in the
        // consolidated payload, so a streamed message_start with
        // role:user slipped past the model's exact-role check — a bypass
        // the non-streaming path does not have.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_su","role":"user","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Misattributed."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A streamed user role must be rejected, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('Misattributed.', $e->getMessage());
        }
    }

    public function testAStreamedNullRoleIsRejectedAndAnOmittedRoleStaysTolerated()
    {
        // Explicit null role (array_key_exists semantics) is corrupt; an
        // omitted role keeps the documented assistant default.
        foreach (array('"role":null,', '') as $roleFragment) {
            $body = ''
                . 'event: message_start' . "\n"
                . 'data: {"type":"message_start","message":{' . $roleFragment . '"id":"msg_sr","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
                . 'event: content_block_start' . "\n"
                . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
                . 'event: content_block_delta' . "\n"
                . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Chunk."}}' . "\n\n"
                . 'event: message_delta' . "\n"
                . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

            $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

            if ('' === $roleFragment) {
                $this->assertSame('Chunk.', $this->model()->generateTextResult($this->prompt())->toText(), 'An omitted streamed role keeps the assistant default.');
            } else {
                try {
                    $this->model()->generateTextResult($this->prompt());
                    $this->fail('An explicit null streamed role must be rejected.');
                } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
                    $this->assertStringContainsString('malformed event frame', $e->getMessage());
                }
            }
        }
    }

    /**
     * @dataProvider provideMalformedIndexes
     */
    public function testMalformedContentBlockIndexesInvalidateTheStream($eventTemplate)
    {
        // Codex R6 #4: a missing or non-integer index was coerced to 0 —
        // mutating the WRONG block while the stream still succeeded.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ix","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":"Original"}}' . "\n\n"
            . $eventTemplate . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A malformed index must invalidate the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideMalformedIndexes()
    {
        return array(
            'delta index missing' => array('event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"x"}}'),
            'delta index string' => array('event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":"0","delta":{"type":"text_delta","text":"x"}}'),
            'delta index float' => array('event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":0.5,"delta":{"type":"text_delta","text":"x"}}'),
            'delta index negative' => array('event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":-1,"delta":{"type":"text_delta","text":"x"}}'),
            'start index missing' => array('event: content_block_start' . "\n" . 'data: {"type":"content_block_start","content_block":{"type":"text","text":""}}'),
            'start index string' => array('event: content_block_start' . "\n" . 'data: {"type":"content_block_start","index":"1","content_block":{"type":"text","text":""}}'),
            'stop index missing' => array('event: content_block_stop' . "\n" . 'data: {"type":"content_block_stop"}'),
            'stop index null' => array('event: content_block_stop' . "\n" . 'data: {"type":"content_block_stop","index":null}'),
        );
    }

    /**
     * @dataProvider provideConflictingDeltas
     */
    public function testConflictingDeltasInvalidateTheStream($startBlockJson, $deltaJson)
    {
        // Codex R6 #3: a delta whose type conflicts with the started
        // block's type previously appended to an incompatible accumulator
        // whose payload builder ignored the member — the stream finished
        // with stop_reason tool_use while silently omitting the tool call
        // (or discarding text/thinking content on a tool block).
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_cf","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: ' . $startBlockJson . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: ' . $deltaJson . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $parts = $result->toMessage()->getParts();
            $this->fail('A conflicting delta must invalidate the stream, got parts: ' . wp_json_encode($parts));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideConflictingDeltas()
    {
        return array(
            'json delta on text block' => array(
                '{"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
                '{"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{}"}}',
            ),
            'json delta on thinking block' => array(
                '{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}',
                '{"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{}"}}',
            ),
            'text delta on tool block' => array(
                '{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_c1","name":"ping","input":{}}}',
                '{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"sneaky"}}',
            ),
            'thinking delta on tool block' => array(
                '{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_c2","name":"ping","input":{}}}',
                '{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"hmm"}}',
            ),
            'text delta on thinking block' => array(
                '{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}',
                '{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"misplaced"}}',
            ),
            'thinking delta on text block' => array(
                '{"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
                '{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"misplaced"}}',
            ),
        );
    }

    public function testAnObjectShapedContentMemberIsRejected()
    {
        // Codex R6 #5: "content": {} collapses to the same empty PHP array
        // as an empty list under associative decoding, so it parsed as a
        // successful candidate with no parts.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"id":"msg_oc","type":"message","role":"assistant","content":{},"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}');

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('An object-shaped content member must be rejected, got parts: ' . wp_json_encode($result->toMessage()->getParts()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('JSON array', $e->getMessage());
        }

        // Numeric-keyed object: associative decode yields a non-empty
        // "list-looking" array — the raw decode still sees an object.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"id":"msg_oc2","type":"message","role":"assistant","content":{"0":{"type":"text","text":"x"}},"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}');

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A numeric-keyed object content member must be rejected.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('JSON array', $e->getMessage());
        }
    }

    public function testAnExplicitlyEmptyContentListStaysProtocolLegal()
    {
        // content: [] is protocol-legal (pre-output refusals) — only the
        // OBJECT shape is rejected.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), '{"id":"msg_ec","type":"message","role":"assistant","content":[],"stop_reason":"refusal","usage":{"input_tokens":1,"output_tokens":1}}');

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame(array(), $result->toMessage()->getParts());
        $this->assertSame(FinishReasonEnum::contentFilter(), $result->getCandidates()[0]->getFinishReason());
    }

    public function testADuplicateContentBlockStartInvalidatesTheStream()
    {
        // Codex R7 #4: a second start for the same index silently replaced
        // the accumulator — fragments collected before the duplicate were
        // discarded and the completion reported success with altered
        // content.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_dup","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"First "}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":"Replaced."}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Second."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A duplicate content_block_start must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }

        // The typed boundary surfaces the same verdict.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);
        $error = $this->model()->generate_text($this->prompt());
        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_INVALID_RESPONSE);
    }

    public function testAContradictingEventTypePairInvalidatesTheStream()
    {
        // Codex R7 #6: `event: ping` carrying a content_block_delta payload
        // was ignored as keep-alive — the answer completed with the content
        // chunk missing.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_pair","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: ping' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Lost chunk."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A contradicting event/type pair must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('Lost chunk.', $e->getMessage());
        }

        // A ping frame whose payload agrees (or has no type member) stays
        // ignorable, and agreeing pairs keep working.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_pair2","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: ping' . "\n"
            . 'data: {"type":"ping"}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"OK."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $this->assertSame('OK.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    /**
     * @dataProvider provideAbsentOrNullStartInputs
     */
    public function testAbsentOrNullStreamedStartInputsAreRejected($startBlockJson)
    {
        // Verifier sweep on Codex R7 (sibling of #1): the streamed start
        // block still normalized an absent/null input member into a
        // fabricated no-argument call — the exact shape #1 rejects on the
        // non-streaming path.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_abs","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":' . $startBlockJson . '}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('An absent/null streamed start input must be rejected, got a call: ' . wp_json_encode($result->toMessage()->getParts()[0]->getFunctionCall()->getArgs()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideAbsentOrNullStartInputs()
    {
        return array(
            'start input omitted' => array('{"type":"tool_use","id":"toolu_a1","name":"ping"}'),
            'start input explicit null' => array('{"type":"tool_use","id":"toolu_a2","name":"ping","input":null}'),
        );
    }

    public function testADeltaAfterContentBlockStopInvalidatesTheStream()
    {
        // Codex R8 #1: after content_block_stop for an index, another delta
        // for the SAME index still appended — no closed state existed.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_as","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Pre."}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Post."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A post-stop delta must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    public function testAStopForANeverStartedIndexInvalidatesTheStream()
    {
        // Codex R8 #1: a stop closing an index that was never started is
        // corrupt — nothing legitimate is being closed.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ns2","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":3}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A stop for a never-started index must fail the stream.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    public function testADataEventAfterMessageStopInvalidatesTheStream()
    {
        // Codex R8 #2: message_stop is terminal — post-termination frames
        // could still modify the returned text/args/stop reason/usage.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_pt","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Final."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"After termination."}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A post-message_stop event must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('termination.', $e->getMessage());
        }

        // A keepalive ping after message_stop stays tolerated.
        $body2 = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_pt2","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Done."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n"
            . 'event: ping' . "\n"
            . 'data: {"type":"ping"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body2);

        $this->assertSame('Done.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testAStreamWithoutMessageStartInvalidates()
    {
        // Codex R8 #3: omitting message_start fabricated an assistant
        // envelope (blank id/usage) and bypassed the R6 role validation.
        $body = ''
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"No envelope."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A stream without message_start must fail, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('No envelope.', $e->getMessage());
        }
    }

    public function testAStartBlockWithoutAValidTypeInvalidatesTheStream()
    {
        // Codex R8 #4: a missing or non-string content_block.type silently
        // became a text block that a following text_delta completed.
        foreach (array(
            '{"type":"content_block_start","index":0,"content_block":{"text":""}}',
            '{"type":"content_block_start","index":0,"content_block":{"type":7,"text":""}}',
            '{"type":"content_block_start","index":0,"content_block":{"type":null,"text":""}}',
        ) as $startJson) {
            $body = ''
                . 'event: message_start' . "\n"
                . 'data: {"type":"message_start","message":{"id":"msg_nt","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
                . 'event: content_block_start' . "\n"
                . 'data: ' . $startJson . "\n\n"
                . 'event: content_block_delta' . "\n"
                . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"x"}}' . "\n\n"
                . 'event: message_delta' . "\n"
                . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

            $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

            try {
                $this->model()->generateTextResult($this->prompt());
                $this->fail('A start block without a valid type must fail the stream: ' . $startJson);
            } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
                $this->assertStringContainsString('malformed event frame', $e->getMessage());
            }
        }
    }

    public function testADuplicateMessageDeltaInvalidatesTheStream()
    {
        // Codex R9 #2: two message_delta events — the later one silently
        // overwrote the first stop reason and usage; end_turn then
        // tool_use made the result report toolCalls() with no call.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_dd","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Answer."}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":5}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":9}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A duplicate message_delta must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }

        // The typed boundary surfaces the same verdict.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);
        $error = $this->model()->generate_text($this->prompt());
        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_INVALID_RESPONSE);
    }

    public function testAnIdenticalDuplicateMessageDeltaIsAlsoRejected()
    {
        // Even a byte-identical repeat is a corrupt stream: the protocol
        // sends exactly one message_delta, and tolerating identical
        // payloads would make acceptance depend on payload comparison.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_dd2","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"x"}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('An identical duplicate message_delta must fail the stream.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    public function testAnEmptyToolUseIdentityIsRejected()
    {
        // Codex R9 #3 inbound: '' id/name passed the isset+is_string
        // guard and produced a FunctionCall with an empty identity.
        foreach (array('id', 'name') as $member) {
            $block = array('type' => 'tool_use', 'input' => array());
            $block[ $member ] = '';
            $block[ 'id' === $member ? 'name' : 'id' ] = 'kept';

            $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
                'id' => 'msg_ei',
                'type' => 'message',
                'role' => 'assistant',
                'content' => array($block),
                'stop_reason' => 'tool_use',
                'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
            )));

            try {
                $this->model()->generateTextResult($this->prompt());
                $this->fail("An empty tool_use {$member} must be rejected.");
            } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
                $this->assertStringContainsString('identity members', $e->getMessage());
            }
        }
    }

    /**
     * @dataProvider provideInvalidMessageStartPayloads
     */
    public function testAnInvalidMessageStartPayloadInvalidatesTheStream($startDataJson)
    {
        // Codex R12 #1: a message_start without a valid message OBJECT
        // previously satisfied the completion prerequisite anyway — later
        // valid content and message_delta fabricated an assistant
        // envelope with blank id and zero input usage.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: ' . $startDataJson . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Fabricated."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('An invalid message_start payload must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('Fabricated.', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideInvalidMessageStartPayloads()
    {
        return array(
            'message member missing' => array('{"type":"message_start"}'),
            'message member null' => array('{"type":"message_start","message":null}'),
            'message member list' => array('{"type":"message_start","message":[]}'),
            'message member scalar' => array('{"type":"message_start","message":"hi"}'),
        );
    }

    public function testAValidMessageStartStillAggregatesNormally()
    {
        // Guard against regression of the R8 #3 / R6 #2 acceptance path.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ok","role":"assistant","content":[],"usage":{"input_tokens":7,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Fine."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Fine.', $result->toText());
        $this->assertSame('msg_ok', $result->getId());
        $this->assertSame(7, $result->getTokenUsage()->getPromptTokens());
    }

    /**
     * @dataProvider provideLateContentEvents
     */
    public function testContentEventsAfterMessageDeltaInvalidateTheStream($lateEventFrames)
    {
        // Codex R13 #1: content events after the final message_delta
        // mutated the accumulators — the completion succeeded with text or
        // tool args received after the final message metadata.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ld","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Before."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n"
            . $lateEventFrames
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A content event after message_delta must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideLateContentEvents()
    {
        return array(
            'late delta' => array(
                'event: content_block_delta' . "\n"
                . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"After."}}' . "\n\n",
            ),
            'late start (new index)' => array(
                'event: content_block_start' . "\n"
                . 'data: {"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}' . "\n\n",
            ),
            'late stop' => array(
                'event: content_block_stop' . "\n"
                . 'data: {"type":"content_block_stop","index":0}' . "\n\n",
            ),
        );
    }

    public function testContentEventsBeforeMessageDeltaStillAggregate()
    {
        // Guard: the normal order (all content events BEFORE the final
        // message metadata) must keep aggregating.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ok3","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Fine."}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $this->assertSame('Fine.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testASecondValidMessageStartInvalidatesTheStream()
    {
        // Codex R13 #2: a second VALID message_start overwrote the first
        // id and input usage while the content still succeeded.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"first","content":[],"usage":{"input_tokens":11,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Content."}}' . "\n\n"
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"second","content":[],"usage":{"input_tokens":99,"output_tokens":1}}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A second message_start must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }

        // The R12 invalid-payload rejection must not regress.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'),
            'event: message_start' . "\n" . 'data: {"type":"message_start"}' . "\n\n" . 'event: message_delta' . "\n" . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n");
        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('An invalid message_start payload must still fail.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    /**
     * @dataProvider provideMalformedStartMembers
     */
    public function testMalformedInitialTextOrThinkingMembersInvalidateTheStream($startJson)
    {
        // Codex R13 #3: a text/thinking start block missing its content
        // member (or with a non-string value) silently fabricated an empty
        // initial value and later deltas succeeded.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_mm","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: ' . $startJson . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Later."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A malformed start member must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideMalformedStartMembers()
    {
        return array(
            'text missing' => array('{"type":"content_block_start","index":0,"content_block":{"type":"text"}}'),
            'text int' => array('{"type":"content_block_start","index":0,"content_block":{"type":"text","text":7}}'),
            'text null' => array('{"type":"content_block_start","index":0,"content_block":{"type":"text","text":null}}'),
            'thinking missing' => array('{"type":"content_block_start","index":0,"content_block":{"type":"thinking"}}'),
            'thinking int' => array('{"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":7}}'),
        );
    }

    public function testValidInitialTextAndThinkingMembersStillAggregate()
    {
        // Guard: valid starts keep their initial values (incl. empty '').
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_vm","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":"Initial."}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" More."}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $this->assertSame('Initial. More.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testADuplicateToolUseIdInAResponseIsRejected()
    {
        // Codex R13 #4: two tool_use blocks with the same NON-EMPTY id are
        // ambiguous identities — a consumer cannot correlate results to
        // calls, and replaying the assistant turn hits this adapter's own
        // outbound duplicate-id rejection after tools may have executed.
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_dupid',
            'type' => 'message',
            'role' => 'assistant',
            'content' => array(
                array('type' => 'tool_use', 'id' => 'toolu_same', 'name' => 'ping', 'input' => new stdClass()),
                array('type' => 'tool_use', 'id' => 'toolu_same', 'name' => 'pong', 'input' => new stdClass()),
            ),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A duplicate tool_use id must be rejected, got: ' . wp_json_encode($result->toMessage()->getParts()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('same id', $e->getMessage());
        }
    }

    public function testADuplicateToolUseIdInAConsolidatedStreamIsRejected()
    {
        // The consolidated stream path funnels into the same content loop,
        // so the same verdict must hold when the duplicates arrive as two
        // distinct streamed blocks instead of one JSON body.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_dupstream","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_dup","name":"ping","input":{}}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_dup","name":"pong","input":{}}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":1}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A streamed duplicate tool_use id must be rejected, got: ' . wp_json_encode($result->toMessage()->getParts()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('same id', $e->getMessage());
        }
    }

    public function testDistinctAndSingleToolUseIdsStillPassThrough()
    {
        // Guards: distinct ids produce both FunctionCalls, and a single
        // tool_use keeps its identity — only NON-EMPTY duplicates reject.
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_two',
            'type' => 'message',
            'role' => 'assistant',
            'content' => array(
                array('type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'ping', 'input' => new stdClass()),
                array('type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'pong', 'input' => new stdClass()),
            ),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_one',
            'type' => 'message',
            'role' => 'assistant',
            'content' => array(
                array('type' => 'tool_use', 'id' => 'toolu_only', 'name' => 'ping', 'input' => array('x' => 1)),
            ),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        $two = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts();
        $this->assertCount(2, $two, 'Two DISTINCT tool_use ids must both pass through.');
        $this->assertSame('toolu_a', $two[0]->getFunctionCall()->getId());
        $this->assertSame('toolu_b', $two[1]->getFunctionCall()->getId());

        $one = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts();
        $this->assertCount(1, $one, 'A single tool_use must keep working unchanged.');
        $this->assertSame('toolu_only', $one[0]->getFunctionCall()->getId());
        $this->assertSame(array('x' => 1), $one[0]->getFunctionCall()->getArgs());
    }

    public function testAnOmittedEnvelopeTypeStaysTolerated()
    {
        // The documented tolerance applies ONLY to an omitted member.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), (string) wp_json_encode(array(
            'id' => 'msg_noenv',
            'role' => 'assistant',
            'content' => array(array('type' => 'text', 'text' => 'Fine.')),
            'stop_reason' => 'end_turn',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        $this->assertSame('Fine.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    /**
     * @dataProvider provideNonAssistantRoles
     */
    public function testNonAssistantResponseRolesAreRejected($roleValue, $present)
    {
        // Codex R5 #3: a generation response MUST be an assistant message —
        // missing, unknown, or user roles must never be fabricated into
        // assistant turns or exposed as generated user messages.
        $payload = array(
            'id' => 'msg_role',
            'type' => 'message',
            'content' => array(array('type' => 'text', 'text' => 'Never attributable.')),
            'stop_reason' => 'end_turn',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        );
        if ($present) {
            $payload['role'] = $roleValue;
        }

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), (string) wp_json_encode($payload));

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A non-assistant response role must be rejected, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('assistant response', $e->getMessage());
            $this->assertStringNotContainsString('Never attributable.', $e->getMessage(), 'Rejected content must not be echoed.');
        }

        // The typed boundary surfaces the same verdict.
        $this->queueSdkResponse(200, array(), (string) wp_json_encode($payload));
        $error = $this->model()->generate_text($this->prompt());
        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_INVALID_RESPONSE);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideNonAssistantRoles()
    {
        return array(
            'role missing' => array(null, false),
            'role user' => array('user', true),
            'role unknown' => array('system', true),
            'role non-string' => array(123, true),
        );
    }

    /**
     * @dataProvider provideMalformedBodies
     */
    public function testMalformedPayloadsFailWithFixedSafeMessages($body)
    {
        $upstream = '<img src=x onerror=alert(1)>Bearer ' . FakeSecrets::apiKey();

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A malformed payload must throw.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertRedacted($e->getMessage(), $upstream);
            $this->assertStringNotContainsString('<img', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideMalformedBodies()
    {
        return array(
            'not json' => array('not json at all'),
            'missing content' => array((string) wp_json_encode(array('id' => 'm', 'stop_reason' => 'end_turn'))),
            'content not a list' => array((string) wp_json_encode(array('content' => array('text' => 'x'), 'stop_reason' => 'end_turn'))),
            'unknown block type' => array((string) wp_json_encode(array('content' => array(array('type' => 'hologram', 'x' => 1)), 'stop_reason' => 'end_turn'))),
            'text block without text' => array((string) wp_json_encode(array('content' => array(array('type' => 'text')), 'stop_reason' => 'end_turn'))),
            'tool_use without id' => array((string) wp_json_encode(array('content' => array(array('type' => 'tool_use', 'name' => 'x', 'input' => array())), 'stop_reason' => 'end_turn'))),
            'missing stop_reason' => array((string) wp_json_encode(array('content' => array(array('type' => 'text', 'text' => 'x'))))),
            'unknown stop_reason' => array((string) wp_json_encode(array('content' => array(array('type' => 'text', 'text' => 'x')), 'stop_reason' => 'quantum_decoherence'))),
        );
    }

    /*
     * SSE aggregation: the Anthropic event sequence.
     */

    public function testStreamsSimpleTextDeltas()
    {
        $body = implode("\n\n", array(
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_s","role":"assistant","content":[],"usage":{"input_tokens":25,"output_tokens":1}}}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            '',
            'event: ping',
            'data: {"type":"ping"}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hel"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"lo world"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":15}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hello world', $result->toText());
        $this->assertSame('msg_s', $result->getId());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
        $this->assertSame(25, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(15, $result->getTokenUsage()->getCompletionTokens());
    }

    public function testStreamsInterleavedTextThinkingAndToolDeltas()
    {
        $body = implode("\n\n", array(
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_mix","role":"assistant","content":[],"usage":{"input_tokens":10,"output_tokens":1}}}',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"hmm"}}',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Checking "}}',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"weather."}}',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":1}',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":2,"content_block":{"type":"tool_use","id":"toolu_9","name":"get_weather","input":{}}}',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":2,"delta":{"type":"input_json_delta","partial_json":"{\\"city\\":"}}',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":2,"delta":{"type":"input_json_delta","partial_json":"\\"Oslo\\"}"}}',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":2}',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":42}}',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());
        $message = $result->toMessage();

        $parts = $message->getParts();
        $this->assertCount(3, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('hmm', $parts[0]->getText());
        $this->assertSame('Checking weather.', $parts[1]->getText());

        $call = $parts[2]->getFunctionCall();
        $this->assertSame('toolu_9', $call->getId());
        $this->assertSame('get_weather', $call->getName());
        $this->assertSame(array('city' => 'Oslo'), $call->getArgs(), 'input_json_delta fragments concatenate into the tool input.');
        $this->assertSame(FinishReasonEnum::toolCalls(), $result->getCandidates()[0]->getFinishReason());
        $this->assertSame(42, $result->getTokenUsage()->getCompletionTokens());
    }

    public function testStreamParserToleratesSplitFramesCrlfCommentsAndMalformedEvents()
    {
        $body = ''
            . 'event: message_start' . "\r\n"
            . 'data: {"type":"message_start","message":{"id":"msg_x","content":[],"usage":{"input_tokens":3,"output_tokens":1}}}' . "\r\n\r\n"
            . ': keep-alive comment' . "\r\n\r\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"A"}}' . "\n\n"
            . 'data: this is not json' . "\n\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"B"}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $aggregator = new AnthropicSseAggregator();
        // Feed in awkwardly-sized pieces to prove frames may split anywhere.
        foreach (str_split($body, 13) as $piece) {
            $aggregator->feed($piece);
        }
        $aggregator->finish();

        $this->assertTrue($aggregator->is_done());
        // Seven well-formed events (start, block start, two deltas, block
        // stop, message_delta, message_stop); the one bad-JSON frame counts
        // as malformed instead.
        $this->assertSame(7, $aggregator->event_count());
        $this->assertSame(1, $aggregator->malformed_count());

        $aggregated = $aggregator->aggregated();
        $this->assertSame('AB', $aggregated['content'][0]['text']);
        $this->assertSame('end_turn', $aggregated['stop_reason']);
    }

    public function testStreamWithoutTrailingBlankLineKeepsTheFinalEvent()
    {
        // A response that ends right after the last event field (no blank
        // line) must not lose that frame.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_f","content":[],"usage":{"input_tokens":2,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Tail frame."}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":4}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}';

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());
        $this->assertSame('Tail frame.', $result->toText());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
    }

    public function testEventStreamDetectedByBodyPrefixWithoutContentType()
    {
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_p","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Body-detected."}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array(), $body);

        $this->assertSame('Body-detected.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testStreamWithoutMessageStopStillAggregates()
    {
        // message_stop is conventional for termination, not required to
        // parse what already arrived.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_n","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Done."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $this->assertSame('Done.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testATruncatedStreamWithoutAStopReasonFailsSafely()
    {
        // A stream cut before message_delta delivered no stop reason:
        // fabricating end_turn would mask truncation as a clean stop
        // (review finding), so the fixed parse-error message is thrown.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_t","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Partial"}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A stream without a stop reason must throw.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('No usable message event', $e->getMessage());
            $this->assertStringNotContainsString('Partial', $e->getMessage(), 'Streamed content must not be echoed.');
        }
    }

    public function testTruncatedToolJsonFailsAsAStreamParseErrorNotAFabricatedCall()
    {
        // Codex R1 finding 1: input_json_delta fragments that combine into
        // invalid JSON must NOT degrade to a no-argument tool call — a
        // consumer could execute a side-effecting tool with wrong inputs.
$body = ''
            . 'event: message_start' . "\n" .
            'data: {"type":"message_start","message":{"id":"msg_tj","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n" .
            'event: content_block_start' . "\n" .
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_bad","name":"delete_everything","input":{}}}' . "\n\n" .
            'event: content_block_delta' . "\n" .
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"path\":"}}' . "\n\n" .
            'event: content_block_stop' . "\n" .
            'data: {"type":"content_block_stop","index":0}' . "\n\n" .
            'event: message_delta' . "\n" .
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":9}}' . "\n\n" .
            'event: message_stop' . "\n" .
            'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('Truncated tool JSON must fail, got: ' . $result->toText());
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
            $this->assertRedacted($e->getMessage(), 'delete_everything');
            $this->assertStringNotContainsString('delete_everything', $e->getMessage(), 'Upstream tool names must not be echoed.');
        }

        // The typed boundary surfaces the same verdict.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);
        $error = $this->model()->generate_text($this->prompt());
        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_INVALID_RESPONSE);
    }

    public function testNonObjectToolJsonFailsAsAStreamParseError()
    {
        // A valid JSON SCALAR or LIST is not a tool-arguments OBJECT either
        // (assoc decoding cannot distinguish ["a"] from {"0":"a"}, so the
        // check decodes to stdClass for exact object-ness).
$body = ''
            . 'event: message_start' . "\n" .
            'data: {"type":"message_start","message":{"id":"msg_ns","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n" .
            'event: content_block_start' . "\n" .
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_s","name":"ping","input":{}}}' . "\n\n" .
            'event: content_block_delta' . "\n" .
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"5"}}' . "\n\n" .
            'event: content_block_stop' . "\n" .
            'data: {"type":"content_block_stop","index":0}' . "\n\n" .
            'event: message_delta' . "\n" .
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n" .
            'event: message_stop' . "\n" .
            'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A scalar tool JSON fragment must fail.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
        }

        // A JSON LIST is equally not an object.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), str_replace(
            '"partial_json":"5"',
            '"partial_json":"[1,2]"',
            $body
        ));

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A list tool JSON fragment must fail.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
        }
    }

    public function testAnEmptyObjectToolJsonStreamStaysALegitimateEmptyCall()
    {
        // The legitimate empty-object stream must keep working: fragments
        // combining to {} decode to an object and yield a no-argument call.
$body = ''
            . 'event: message_start' . "\n" .
            'data: {"type":"message_start","message":{"id":"msg_eo","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n" .
            'event: content_block_start' . "\n" .
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_e","name":"ping","input":{}}}' . "\n\n" .
            'event: content_block_delta' . "\n" .
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{}"}}' . "\n\n" .
            'event: content_block_stop' . "\n" .
            'data: {"type":"content_block_stop","index":0}' . "\n\n" .
            'event: message_delta' . "\n" .
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n" .
            'event: message_stop' . "\n" .
            'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertSame('toolu_e', $call->getId());
        $this->assertNull($call->getArgs(), 'An empty-object input stream stays a no-argument call.');
    }

    public function testAScalarStartBlockToolInputFailsAsAStreamParseError()
    {
        // Codex R3 #2: a malformed content_block_start input (scalar here)
        // with NO input_json_delta following must not be silently replaced
        // with {} — that fabricated an executable no-argument call.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_sb","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_s1","name":"delete_file","input":"/etc/hosts"}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A scalar start-block input must fail, got a call: ' . wp_json_encode($result->toMessage()->getParts()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
            $this->assertRedacted($e->getMessage(), '/etc/hosts');
            $this->assertStringNotContainsString('delete_file', $e->getMessage());
        }
    }

    public function testAListStartBlockToolInputFailsAsAStreamParseError()
    {
        $list_start = '{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_s2","name":"get_weather","input":["Oslo"]}}';
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_sb2","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: ' . $list_start . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A list start-block input must fail.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
        }
    }

    public function testAnEmptyJsonListStartBlockToolInputFailsAsAStreamParseError()
    {
        // Codex R3 #1's [] variant on the stream path: the start block's
        // associative decode collapses "input":[] to an empty array — the
        // raw oracle must still recognize the empty LIST.
        $empty_list_start = '{"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_s3","name":"ping","input":[]}}';
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_sb3","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: ' . $empty_list_start . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('An empty-list start-block input must fail, got a call with args: ' . wp_json_encode($result->toMessage()->getParts()[0]->getFunctionCall()->getArgs()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
        }
    }

    public function testAnEmptyObjectStartBlockInputStaysANoArgumentCall()
    {
        // The legitimate start-block {}: no deltas follow, and the call is
        // a no-argument one (R3 #2: {} must stay legitimate).
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_sb4","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_s4","name":"ping","input":{}}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertSame('toolu_s4', $call->getId());
        $this->assertNull($call->getArgs());
    }

    public function testAnObjectStartBlockInputWithoutDeltasBecomesTheCallArgs()
    {
        // Start-block object input with no deltas: the initial value IS the
        // call's arguments.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_sb5","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_s5","name":"get_weather","input":{"city":"Oslo"}}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertSame(array('city' => 'Oslo'), $call->getArgs());
    }

    public function testANullPartialJsonDeltaFailsAsAStreamParseError()
    {
        // Codex R4 #1: "partial_json": null was silently ignored (isset()
        // is false for null), letting the initial {} become an executable
        // no-argument call.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_np","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_np","name":"delete_file","input":{}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":null}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A null partial_json delta must fail, got a call: ' . wp_json_encode($result->toMessage()->getParts()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
            $this->assertStringNotContainsString('delete_file', $e->getMessage());
        }
    }

    public function testAMissingPartialJsonDeltaFailsAsAStreamParseError()
    {
        // Codex R4 #1: the member OMITTED entirely is the same corrupt
        // shape as null.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_mp","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_mp","name":"delete_file","input":{}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta"}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A missing partial_json member must fail, got a call: ' . wp_json_encode($result->toMessage()->getParts()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
        }
    }

    public function testAnEmptyStringPartialJsonDeltaStaysANoOp()
    {
        // The legitimate empty-string fragment keeps its no-op semantics:
        // it does not set has_json, and the start input stands.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ep","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_ep","name":"get_weather","input":{"city":"Oslo"}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":""}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertSame(array('city' => 'Oslo'), $call->getArgs(), 'The start-block input stands after an empty-string fragment.');
    }

    public function testANonStringPartialJsonDeltaFailsAsAStreamParseError()
    {
        // Verifier finding on Codex R3: the protocol's partial_json member
        // is a string; a non-string value is a corrupt streamed-arguments
        // event and must not be silently dropped (a {} start plus this
        // delta fabricated a no-argument call).
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_npd","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_n1","name":"delete_file","input":{}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":{"path":"/etc/hosts"}}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A non-string partial_json delta must fail, got a call: ' . wp_json_encode($result->toMessage()->getParts()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
            $this->assertStringNotContainsString('delete_file', $e->getMessage());
        }
    }

    public function testAMalformedContentDeltaFrameInvalidatesTheStream()
    {
        // Codex R4 #3: a frame DECLARING content_block_delta with invalid
        // JSON must fail the whole stream — silently dropping the chunk
        // would return a successful completion with the answer altered.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_md","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: this is not json' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Complete."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A malformed declared-event frame must fail the stream, got: ' . $result->toText());
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('Complete.', $e->getMessage());
        }

        // The typed boundary surfaces the same verdict.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);
        $error = $this->model()->generate_text($this->prompt());
        $this->assertWPError($error, Deicod\WpConnectors\Zai\Support\ErrorMapper::CODE_INVALID_RESPONSE);
    }

    public function testMalformedFramesOfOtherDeclaredEventsInvalidateTheStream()
    {
        foreach (array('message_start', 'message_delta', 'message_stop', 'error') as $declared) {
            $body = ''
                . 'event: ' . $declared . "\n"
                . 'data: broken-' . $declared . "\n\n"
                . 'event: message_delta' . "\n"
                . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

            $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

            try {
                $this->model()->generateTextResult($this->prompt());
                $this->fail("A malformed {$declared} frame must fail the stream.");
            } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
                $this->assertStringContainsString('malformed event frame', $e->getMessage());
            }
        }
    }

    public function testAMalformedUnknownEventFrameStaysIgnorable()
    {
        // The distinction is declared-event-with-bad-payload vs UNKNOWN
        // event: an unresolvable/unknown name with garbage data stays
        // ignorable for forward compatibility, and the stream completes.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ue","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: some_future_event' . "\n"
            . 'data: not json at all' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"OK."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $this->assertSame('OK.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testAMalformedPingFrameStaysIgnorable()
    {
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_pg","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: ping' . "\n"
            . 'data: broken keep-alive' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"OK."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $this->assertSame('OK.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    /**
     * @dataProvider provideWrongShapeContentDeltas
     */
    public function testWrongShapeContentDeltasInvalidateTheStream($deltaJson)
    {
        // Verifier sweep on Codex R4: decodable-but-wrong-shape content
        // deltas were silently dropped — a completion with that chunk of
        // the answer missing.
        $this->assertDeltaInvalidates($deltaJson);
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideWrongShapeContentDeltas()
    {
        return array(
            'delta member missing' => array('{"type":"content_block_delta","index":0}'),
            'delta member null' => array('{"type":"content_block_delta","index":0,"delta":null}'),
            'delta member scalar' => array('{"type":"content_block_delta","index":0,"delta":"text"}'),
            'delta type missing' => array('{"type":"content_block_delta","index":0,"delta":{"text":"x"}}'),
            'delta type non-string' => array('{"type":"content_block_delta","index":0,"delta":{"type":5,"text":"x"}}'),
            'text_delta text missing' => array('{"type":"content_block_delta","index":0,"delta":{"type":"text_delta"}}'),
            'text_delta text non-string' => array('{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":123}}'),
            'thinking_delta thinking non-string' => array('{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":true}}'),
        );
    }

    public function testAListPayloadForADeclaredEventInvalidatesTheStream()
    {
        // Verifier sweep on Codex R4: valid JSON in LIST shape bypassed the
        // is_array() object-ness collapse — the dropped chunk inside the
        // list vanished while the stream reported success.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_lp","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: ["lost chunk"]' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A list payload for a declared event must fail, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('lost chunk', $e->getMessage());
        }
    }

    public function testAWrongShapeTypedDataOnlyFrameInvalidatesTheStream()
    {
        // A DATA-ONLY frame whose type member names the declared event and
        // whose shape is wrong: dispatch by the type member must apply the
        // same validation (there is no event: field to key on).
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_do","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'data: {"type":"content_block_delta","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A wrong-shape typed data-only frame must fail the stream.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    public function testAGarbageContentBlockStartInvalidatesTheStream()
    {
        // Verifier sweep on Codex R4: a content_block_start whose
        // content_block member is absent/non-object defaulted to a text
        // block, silently swallowing the tool call's deltas.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_gb","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":"garbage"}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A garbage content_block_start must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('garbage', $e->getMessage());
        }
    }

    public function testUnknownDeltaTypesStayIgnorable()
    {
        // Forward compatibility: a future delta type (with object shape) is
        // ignored and the stream completes.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $this->streamWithDelta(
            '{"type":"content_block_delta","index":0,"delta":{"type":"citation_delta","citation":"src"}}'
        ));

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('', $result->toText(), 'The unknown delta contributes no text; the stream completes.');
    }

    public function testAToolDeltaWithoutAStartBlockInvalidatesTheStream()
    {
        // Codex R5 #2: an input_json_delta for an index whose
        // content_block_start was never received previously defaulted to a
        // text accumulator — the fragments were collected and ignored, so
        // a tool_use completion "succeeded" with NO FunctionCall at all.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ns","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"city\":\"Oslo\"}"}}' . "\n\n"
            . 'event: content_block_stop' . "\n"
            . 'data: {"type":"content_block_stop","index":0}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":2}}' . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $parts = $result->toMessage()->getParts();
            $this->fail('A tool delta without a start block must fail, got parts: ' . wp_json_encode($parts));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            // Both defects in this fixture are flagged (R5 #2: the unseen
            // tool delta; R8 #1: the trailing stop for the never-started
            // index) — the model reports the malformed-event verdict first.
            $this->assertStringContainsString('malformed', $e->getMessage());
        }
    }

    public function testATextDeltaWithoutAStartBlockInvalidatesTheStream()
    {
        // Codex R10 #3 supersedes the R5 #2 text tolerance: a text delta
        // for an unseen index means the stream began mid-block or lost a
        // content_block_start — content before the missing start is
        // silently absent, and the synthesized accumulator returned a
        // successful TRUNCATED completion. Rejected like the tool path.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ts","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Truncated chunk."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $result = $this->model()->generateTextResult($this->prompt());
            $this->fail('A text delta without a start block must fail the stream, got: ' . wp_json_encode($result->toText()));
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
            $this->assertStringNotContainsString('Truncated chunk.', $e->getMessage());
        }
    }

    public function testAThinkingDeltaWithoutAStartBlockInvalidatesTheStream()
    {
        // Same rule on the thinking path (Codex R10 #3).
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_tsn","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":2,"delta":{"type":"thinking_delta","thinking":"Orphan thought."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A thinking delta without a start block must fail the stream.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('malformed event frame', $e->getMessage());
        }
    }

    public function testAnUnknownDeltaTypeWithoutAStartBlockStaysSeeded()
    {
        // Unknown (future) delta types carry no content this aggregator
        // maps, so the seeded tolerance loses nothing (Codex R10 #3 note).
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $this->streamWithDelta(
            '{"type":"content_block_delta","index":0,"delta":{"type":"citation_delta","citation":"src"}}'
        ));

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('', $result->toText(), 'The unknown delta contributes no text; the stream completes.');
    }

    public function testAnErrorEventFailsWithAFixedSafeMessage()
    {
        $secret = FakeSecrets::apiKey();
        $body = ''
            . 'event: message_start' . "\n\n"
            . 'data: {"type":"message_start","message":{"id":"msg_e","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: error' . "\n\n"
            . 'data: {"type":"error","error":{"type":"overloaded_error","message":"Overloaded,Bearer ' . $secret . '"}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('An error event must throw.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('error event', $e->getMessage());
            $this->assertRedacted($e->getMessage(), $secret);
            $this->assertStringNotContainsString('Overloaded', $e->getMessage(), 'Upstream error text must not be echoed.');
        }
    }

    public function testAnAllMalformedStreamFailsSafely()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), "data: broken\n\ndata: also broken\n\n");

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('An all-malformed stream must throw.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            $this->assertStringContainsString('z.ai', $e->getMessage());
            $this->assertStringNotContainsString('broken', $e->getMessage(), 'Raw event payloads must not be echoed.');
        }
    }

    public function testUnknownStreamEventsAreIgnored()
    {
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_u","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: some_future_event' . "\n"
            . 'data: {"type":"some_future_event","payload":"whatever"}' . "\n\n"
            . 'event: content_block_start' . "\n"
            . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"OK."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $this->assertSame('OK.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    /*
     * Error mapping and redaction (shared catalog).
     */

    /**
     * @dataProvider provideErrorStatuses
     */
    public function testErrorStatusesMapToSafeTypedExceptions($status, $expectedClass)
    {
        // Upstream error bodies may echo request material, including the
        // credential itself: the exception message must never include it.
        $secret = FakeSecrets::apiKey();
        $body = HttpResponseFactory::anthropicErrorBody('invalid x-api-key: Bearer ' . $secret, 'authentication_error');

        $this->queueSdkResponse($status, array('Content-Type' => 'application/json'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail("Status {$status} must throw.");
        } catch (\Exception $e) {
            $this->assertInstanceOf($expectedClass, $e);
            $this->assertSame($status, $e->getCode());
            $this->assertRedacted($e->getMessage(), $secret);
            $this->assertStringNotContainsString('invalid x-api-key', $e->getMessage(), 'Upstream error text must not be copied.');
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideErrorStatuses()
    {
        return array(
            '401' => array(401, ClientException::class),
            '403' => array(403, ClientException::class),
            '429' => array(429, ClientException::class),
            '418' => array(418, ClientException::class),
            '500' => array(500, ServerException::class),
            '503' => array(503, ServerException::class),
            '307' => array(307, WordPress\AiClient\Providers\Http\Exception\RedirectException::class),
        );
    }

    public function testTheSharedErrorCatalogGuidesBothSurfaces()
    {
        // z.ai 429 is also code 1113 (plan/balance mismatch, record 0006):
        // the one redacted catalog message must guide both surfaces.
        $message = ErrorMapper::safe_http_message(429);

        $this->assertStringContainsString('rate limiting', $message);
        $this->assertStringContainsString('plan/balance mismatch', $message);
    }

    public function testNoRetriesAreAttemptedOn429()
    {
        $this->queueSdkResponse(429, array(), HttpResponseFactory::anthropicErrorBody('slow down'));

        try {
            $this->model()->generateTextResult($this->prompt());
        } catch (ClientException $e) {
            // Expected.
        }

        $this->assertCount(1, $this->sdkHttpAttempts(), 'v1 must not retry rate-limited requests.');
    }

    /*
     * Typed WP_Error boundary.
     */

    public function testGenerateTextReturnsTheResultOnSuccess()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('Hi.'));

        $result = $this->model()->generate_text($this->prompt());

        $this->assertNotWPError($result);
        $this->assertSame('Hi.', $result->toText());
    }

    /**
     * @dataProvider provideBoundaryErrorCodes
     */
    public function testGenerateTextYieldsTypedWpErrorsAtTheBoundary($status, $expectedCode)
    {
        $secret = FakeSecrets::apiKey();
        $this->queueSdkResponse($status, array(), HttpResponseFactory::anthropicErrorBody('upstream echoes Bearer ' . $secret));

        $error = $this->model()->generate_text($this->prompt());

        $this->assertWPError($error, $expectedCode);
        $this->assertSame($status, $error->get_error_data()['status']);
        $this->assertRedacted($error->get_error_message(), $secret);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideBoundaryErrorCodes()
    {
        return array(
            '401' => array(401, ErrorMapper::CODE_UNAUTHORIZED),
            '403' => array(403, ErrorMapper::CODE_FORBIDDEN),
            '429' => array(429, ErrorMapper::CODE_RATE_LIMITED),
            '418' => array(418, ErrorMapper::CODE_CLIENT_ERROR),
            '500' => array(500, ErrorMapper::CODE_UPSTREAM_ERROR),
            '503' => array(503, ErrorMapper::CODE_UPSTREAM_ERROR),
            '307' => array(307, ErrorMapper::CODE_REDIRECT_ERROR),
        );
    }

    public function testTokenLimitSurfacesAsTheTypedTokenLimitError()
    {
        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicMessagesBody('trunc', null, 'max_tokens'));

        $error = $this->model()->generate_text($this->prompt());

        $this->assertWPError($error, ErrorMapper::CODE_TOKEN_LIMIT);
        $this->assertStringContainsString('token limit', $error->get_error_message());
    }

    public function testGenerateTextMapsTransportFailuresToTypedWpErrors()
    {
        $this->allowUnmockedHttp = true;

        $error = $this->model()->generate_text($this->prompt());

        $this->assertWPError($error, ErrorMapper::CODE_TRANSPORT_ERROR);
        $this->assertRedacted($error->get_error_message(), FakeSecrets::apiKey());
    }

    public function testTransportFailureSurfacesAsNetworkException()
    {
        $this->allowUnmockedHttp = true;

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A transport failure must throw.');
        } catch (NetworkException $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
        }
    }

    public function testUnboundDirectModelSurfacesTheBindingHintNotAGenericError()
    {
        $this->primeZaiAnthropicDiscoveryTransient();

        // Bare factory call: no transporter and no request auth — only
        // ProviderRegistry::getProviderModel() binds them.
        $model = ZaiAnthropicProvider::model('glm-5.3');

        $error = $model->generate_text($this->prompt());

        $this->assertWPError($error, ErrorMapper::CODE_ERROR);
        $this->assertStringContainsString('instance not set', $error->get_error_message());
        $this->assertStringContainsString('AiClient', $error->get_error_message());
    }

    /*
     * The REAL dispatch path: the genuine WP core prompt builder
     * (WP_AI_Client_Prompt_Builder) → the SDK PromptBuilder → the FINAL
     * ZaiAnthropicTextGenerationModel::generateTextResult() → core's fixed
     * exception_to_wp_error() conversion. These tests prove what callers of
     * wp_ai_client_prompt(...)->using_provider('zai_anthropic')->generate_text()
     * actually receive: CORE codes, zai-safe messages (core passes the
     * exception message through verbatim — no filter exists), and correct
     * HTTP statuses.
     *
     * Requires the WordPress core source; set WP_CONNECTORS_TEST_WP_ROOT
     * (defaults to ~/wp-ai-research/wordpress when present). Skipped
     * otherwise — never fails a checkout without core.
     */

    /**
     * Loads the real core prompt builder class file.
     *
     * @return class-string<WP_AI_Client_Prompt_Builder>
     */
    private function corePromptBuilderClass(): string
    {
        $home = (string) getenv('HOME');
        $wpRoot = (string) (getenv('WP_CONNECTORS_TEST_WP_ROOT') ?: ($home !== '' ? $home . '/wp-ai-research/wordpress' : ''));
        $file = $wpRoot . '/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php';

        if ('' === $wpRoot || ! is_file($file)) {
            $this->markTestSkipped('WP core source not found (set WP_CONNECTORS_TEST_WP_ROOT to the WordPress checkout).');
        }

        require_once $file;

        return 'WP_AI_Client_Prompt_Builder';
    }

    /**
     * Boots the provider into the registry and settles the availability
     * verdict (the SDK's model resolution probes isConfigured() first, which
     * would otherwise consume the queued generation response).
     *
     * @return WP_AI_Client_Prompt_Builder
     */
    private function corePromptBuilder()
    {
        $class = $this->corePromptBuilderClass();

        $this->primeZaiAnthropicDiscoveryTransient();
        update_option(Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability::KEY_OPTION, FakeSecrets::apiKey());
        Deicod\WpConnectors\Zai\Plugin::register(AiClient::defaultRegistry());
        AiClient::defaultRegistry()->setProviderRequestAuthentication(
            'zai_anthropic',
            new ApiKeyRequestAuthentication(FakeSecrets::apiKey())
        );

        $this->queueSdkResponse(200, array(), HttpResponseFactory::anthropicModelsBody(array('glm-5.3')));
        $this->assertTrue(ZaiAnthropicProvider::availability()->isConfigured(), 'Availability must settle before generation.');

        return new $class(AiClient::defaultRegistry(), 'Hello');
    }

    public function testCoreBuilderPathReturnsGeneratedTextOnSuccess()
    {
        $builder = $this->corePromptBuilder();

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), HttpResponseFactory::anthropicMessagesBody('Via core.'));

        $text = $builder->using_provider('zai_anthropic')->generate_text();

        $this->assertSame('Via core.', $text);
    }

    /**
     * @dataProvider provideCoreBuilderErrorCases
     */
    public function testCoreBuilderPathYieldsCoreCodesWithZaiSafeMessages($status, $expectedCoreCode)
    {
        $builder = $this->corePromptBuilder();

        $secret = FakeSecrets::apiKey();
        $this->queueSdkResponse($status, array(), HttpResponseFactory::anthropicErrorBody('upstream echoes Bearer ' . $secret));

        $result = $builder->using_provider('zai_anthropic')->generate_text();

        $this->assertWPError($result, $expectedCoreCode);
        $data = $result->get_error_data();
        $this->assertSame($status, $data['status'], 'Core must derive the REST status from the exception code.');
        $this->assertArrayHasKey('exception_class', $data, 'Core records the exception class in the error data.');
        $this->assertSame(
            ErrorMapper::safe_http_message($status),
            $result->get_error_message(),
            'The verbatim-passed message must be exactly the shared safe-catalog text.'
        );
        $this->assertRedacted($result->get_error_message(), $secret);
        $this->assertStringNotContainsString('upstream echoes', $result->get_error_message(), 'Raw upstream body must never reach the message.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideCoreBuilderErrorCases()
    {
        return array(
            '401' => array(401, 'prompt_client_error'),
            '429' => array(429, 'prompt_client_error'),
            '503' => array(503, 'prompt_upstream_server_error'),
        );
    }
}
