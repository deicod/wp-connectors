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

    public function testAMissingToolUseInputMemberStaysANoArgumentCall()
    {
        // Codex R2 #1: a missing input member is a legitimate no-argument
        // call, not a parse error.
        $this->queueSdkResponse(200, array(), (string) wp_json_encode(array(
            'id' => 'msg_tool_absent',
            'role' => 'assistant',
            'content' => array(array('type' => 'tool_use', 'id' => 'toolu_03', 'name' => 'ping')),
            'stop_reason' => 'tool_use',
            'usage' => array('input_tokens' => 1, 'output_tokens' => 1),
        )));

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertNull($call->getArgs(), 'A missing input member means "no arguments".');
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
            $this->assertStringContainsString('malformed input JSON', $e->getMessage());
        }
    }

    public function testATextDeltaWithoutAStartBlockStaysTolerated()
    {
        // Documented tolerance (Codex R5 #2): a genuine TEXT delta for an
        // unseen index keeps the tolerant text-default — the chunk is
        // still accumulated and surfaced, never dropped.
        $body = ''
            . 'event: message_start' . "\n"
            . 'data: {"type":"message_start","message":{"id":"msg_ts","content":[],"usage":{"input_tokens":1,"output_tokens":1}}}' . "\n\n"
            . 'event: content_block_delta' . "\n"
            . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Recovered chunk."}}' . "\n\n"
            . 'event: message_delta' . "\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Recovered chunk.', $result->toText(), 'A lost start event for a text block must not lose the text.');
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
