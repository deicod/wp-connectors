<?php
/**
 * Task 1.7 — response, streaming, and error mapping tests.
 *
 * Covers non-streaming success/tool calls/finish reasons/usage, SSE
 * aggregation (split frames, [DONE], malformed events, tool-call deltas),
 * every required error-status mapping, redaction of upstream error bodies
 * (which may contain secrets), and the typed WP_Error mapper.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Support\ErrorMapper;
use Deicod\WpConnectors\Zai\Support\SseAggregator;
use Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard;

final class ZaiResponseMappingTest extends WpConnectorsTestCase
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
        return array(new Message(
            WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
            array(new MessagePart('hi'))
        ));
    }

    /*
     * Non-streaming success.
     */

    public function testParsesContentFinishReasonAndUsage()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), wp_json_encode(array(
            'id' => 'chatcmpl-1',
            'choices' => array(array(
                'index' => 0,
                'message' => array('role' => 'assistant', 'content' => 'Hello there.'),
                'finish_reason' => 'stop',
            )),
            'usage' => array('prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10),
        )));

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hello there.', $result->toText());
        $this->assertSame('chatcmpl-1', $result->getId());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
        $this->assertSame(7, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(3, $result->getTokenUsage()->getCompletionTokens());
        $this->assertSame(10, $result->getTokenUsage()->getTotalTokens());
    }

    public function testABomPrefixedJsonBodyStillParses()
    {
        /*
         * GLM9 #3: the zai_anthropic twin's GLM8 #3 fix, landed there
         * only — a UTF-8 BOM prepended to an application/json
         * chat.completion body made json_decode() fail, the SDK
         * fallback re-decode failed on the same BOM'd body, and a
         * valid generation surfaced as 'The chat-completions payload
         * was malformed.' The shared strip_stream_prefix() now runs
         * before both decodes here too (whitespace around the BOM
         * included), so both surfaces of one provider tolerate the
         * identical gateway/CDN prefix shape.
         */
        $payload = (string) wp_json_encode(array(
            'id' => 'chatcmpl-bom-json',
            'choices' => array(array(
                'index' => 0,
                'message' => array('role' => 'assistant', 'content' => 'Bom-tolerant.'),
                'finish_reason' => 'stop',
            )),
            'usage' => array('prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10),
        ));

        foreach (array(
            'bare BOM' => "\xEF\xBB\xBF" . $payload,
            'whitespace then BOM' => " \xEF\xBB\xBF" . $payload,
            'BOM then whitespace' => "\xEF\xBB\xBF " . $payload,
        ) as $label => $body) {
            $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), $body);

            $result = $this->model()->generateTextResult($this->prompt());

            $this->assertSame('Bom-tolerant.', $result->toText(), "[{$label}] The BOM must not fail the JSON parse.");
            $this->assertSame('chatcmpl-bom-json', $result->getId(), "[{$label}] The id parses.");
            $this->assertSame(10, $result->getTokenUsage()->getTotalTokens(), "[{$label}] The usage parses.");
        }
    }

    public function testABomPrefixedNonJsonBodyStillFailsTyped()
    {
        // GLM9 #3 guard: the strip is a prefix tolerance, not a rescue —
        // a BOM before garbage keeps the typed malformed-payload
        // rejection exactly like the BOM-less body.
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), "\xEF\xBB\xBFnot json at all");

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A BOM before a non-JSON body must still fail.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('chat-completions payload was malformed', $e->getMessage());
        }
    }

    public function testParsesReasoningContentAsThoughtPart()
    {
        $this->queueSdkResponse(200, array(), wp_json_encode(array(
            'id' => 'chatcmpl-2',
            'choices' => array(array(
                'message' => array('role' => 'assistant', 'reasoning_content' => 'thinking...', 'content' => 'Answer.'),
                'finish_reason' => 'stop',
            )),
        )));

        $parts = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts();

        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('thinking...', $parts[0]->getText());
        $this->assertSame('Answer.', $parts[1]->getText());
    }

    public function testParsesToolCallsAndFinishReason()
    {
        $this->queueSdkResponse(200, array(), wp_json_encode(array(
            'id' => 'chatcmpl-3',
            'choices' => array(array(
                'message' => array(
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => array(array(
                        'id' => 'call_9',
                        'type' => 'function',
                        'function' => array('name' => 'get_weather', 'arguments' => '{"city":"Oslo"}'),
                    )),
                ),
                'finish_reason' => 'tool_calls',
            )),
        )));

        $result = $this->model()->generateTextResult($this->prompt());
        $message = $result->toMessage();

        $call = $message->getParts()[0]->getFunctionCall();
        $this->assertSame('call_9', $call->getId());
        $this->assertSame('get_weather', $call->getName());
        $this->assertSame(array('city' => 'Oslo'), $call->getArgs());
        $this->assertSame(FinishReasonEnum::toolCalls(), $result->getCandidates()[0]->getFinishReason());
    }

    public function testToolCallArgumentsPreserveNestedObjectNessThroughReplay()
    {
        /*
         * GLM5 #1: the SDK parent's ASSOCIATIVE arguments decode collapses
         * a nested {} to [] and a numeric-keyed object to a list, so this
         * surface's own tool loop shipped ALTERED arguments on the very
         * next replay ({"opts":{},"rows":{"0":"a"}} became
         * {"opts":[],"rows":["a"]}) — the GLM1 #2 object-ness preservation
         * existed on the zai_anthropic surface only. The parser re-derives
         * the tree from a RAW decode through the same shared walk now, so
         * the replay is byte-identical.
         */
        $this->queueSdkResponse(200, array(), wp_json_encode(array(
            'id' => 'chatcmpl-obj',
            'choices' => array(array(
                'message' => array(
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => array(array(
                        'id' => 'call_obj',
                        'type' => 'function',
                        'function' => array('name' => 'do_thing', 'arguments' => '{"opts":{},"rows":{"0":"a"},"plain":{"k":"v"}}'),
                    )),
                ),
                'finish_reason' => 'tool_calls',
            )),
        )));

        $assistant = $this->model()->generateTextResult($this->prompt())->toMessage();

        $args = $assistant->getParts()[0]->getFunctionCall()->getArgs();
        $this->assertInstanceOf('stdClass', $args['opts'], 'A nested empty object must stay an object.');
        $this->assertInstanceOf('stdClass', $args['rows'], 'A nested numeric-keyed object must stay an object.');
        $this->assertSame(array('k' => 'v'), $args['plain'], 'An ordinary object keeps its ergonomic array form.');

        // Replay the answered turn: the outbound arguments string must
        // carry exactly the object shapes the model produced.
        $this->queueSdkResponse(200, array(), wp_json_encode(array(
            'id' => 'chatcmpl-after',
            'choices' => array(array(
                'message' => array('role' => 'assistant', 'content' => 'done'),
                'finish_reason' => 'stop',
            )),
        )));

        $this->model()->generateTextResult(array(
            $this->prompt()[0],
            $assistant,
            new Message(WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('call_obj', 'do_thing', array('ok' => true))),
            )),
        ));

        $attempts = $this->sdkHttpAttempts();
        $this->assertStringContainsString(
            '"arguments":"{\"opts\":{},\"rows\":{\"0\":\"a\"},\"plain\":{\"k\":\"v\"}}"',
            (string) $attempts[1]['body'],
            'The zai surface replay must preserve the parsed object shapes.'
        );
    }

    public function testToolCallArgumentsDecodingToInfAreRejectedTyped()
    {
        /*
         * GLM5 #2: 1e999 decodes to INF, which the SDK parent's outbound
         * json_encode() turns into false — every later request of the
         * conversation shipped "arguments": false. The shared replay guard
         * (GLM4 #2, previously wired on the zai_anthropic surface only)
         * rejects the value at parse time in this surface's typed channel.
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-inf","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_inf","type":"function","function":{"name":"f","arguments":"{\"v\":1e999}"}}]},"finish_reason":"tool_calls"}]}');

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('INF tool arguments must be rejected.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('The chat-completions payload was malformed.', $e->getMessage());
        }
    }

    public function testToolCallArgumentsBeyondIntRangeAreRejectedTyped()
    {
        /*
         * GLM5 #2: an integer beyond PHP_INT_MAX decodes to a lossy float;
         * replay would silently ship an altered e-notation value. The value
         * sits clearly outside the ~2048-wide window above PHP_INT_MAX whose
         * integers are indistinguishable after the decode (the guard's
         * documented accepted residual).
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-big","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_big","type":"function","function":{"name":"f","arguments":"{\"n\":99999999999999999999}"}}]},"finish_reason":"tool_calls"}]}');

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('Beyond-int-range tool arguments must be rejected.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('The chat-completions payload was malformed.', $e->getMessage());
        }
    }

    public function testPreDecodedToolCallArgumentsWithInfAreRejectedTyped()
    {
        /*
         * GLM5 #2 (fallback path): when the body carries the arguments as
         * a pre-decoded JSON member (not a string), the SDK parent passes
         * the value through untouched — an INF member reaches the guard
         * through the same channel.
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-arr","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_arr","type":"function","function":{"name":"f","arguments":{"v":1e999}}}}]},"finish_reason":"tool_calls"}]}');

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('INF inside pre-decoded tool arguments must be rejected.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('The chat-completions payload was malformed.', $e->getMessage());
        }
    }

    public function testTruncatedToolCallArgumentsAreRejectedNotSilentlyEmptied()
    {
        /*
         * GLM6 #1: a non-streaming body carrying an arguments string that
         * fails JSON decode left the SDK parent's null-args call standing
         * (the replay guard tolerates null), so the generation SUCCEEDED
         * with finish reason toolCalls and getArgs() null — a consumer
         * could execute a possibly side-effecting tool with arguments the
         * model never produced. Typed rejection now, never a fabricated
         * empty call.
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-tr","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_tr","type":"function","function":{"name":"get_weather","arguments":"{\"city\":"}}]},"finish_reason":"tool_calls"}]}');

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('Truncated tool-call arguments must be rejected.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('The chat-completions payload was malformed.', $e->getMessage());
        }
    }

    public function testStreamedToolCallArgumentsLosingAFragmentAreRejectedNotSilentlyEmptied()
    {
        /*
         * GLM6 #1 (streamed half): a gateway dropping one arguments delta
         * leaves the concatenated fragments invalid JSON — the exact
         * corruption the non-streaming twin above rejects, reaching the
         * same channel through the consolidated payload.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-trs","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_trs","type":"function","function":{"name":"get_weather","arguments":""}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-trs","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"city\":"}}]},"finish_reason":"tool_calls"}]}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('Fragment-losing streamed tool arguments must be rejected.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('The chat-completions payload was malformed.', $e->getMessage());
        }
    }

    public function testALiteralJsonNullArgumentsStringKeepsTheParentSemantics()
    {
        // GLM6 #1 guard: only DECODE FAILURES reject — a literal "null"
        // string is valid JSON and keeps the SDK parent's null-args
        // semantics (the empty-call representation this surface documents).
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-nu","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_nu","type":"function","function":{"name":"ping","arguments":"null"}}]},"finish_reason":"tool_calls"}]}');

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertNull($call->getArgs());
    }

    public function testAnEmptyArgumentsStringKeepsTheParentSemantics()
    {
        /*
         * GLM6 #1 (verifier round): the streamed aggregator initializes
         * arguments to '' and only appends fragments, so a legitimate
         * zero-argument streamed call consolidates to '' — and a
         * non-streaming "arguments": "" is the same legal zero-arg shape.
         * Both keep the SDK parent's null-args semantics; only genuinely
         * undecodable strings reject.
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-es","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_es","type":"function","function":{"name":"ping","arguments":""}}]},"finish_reason":"tool_calls"}]}');

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertNull($call->getArgs());
    }

    public function testAStreamedZeroArgumentToolCallConsolidatingToEmptyStillSucceeds()
    {
        // GLM6 #1 (verifier round, streamed half): no arguments fragment
        // ever arrives, so the consolidated arguments string is ''.
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-za","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_za","type":"function","function":{"name":"ping","arguments":""}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-za","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertSame('call_za', $call->getId());
        $this->assertNull($call->getArgs(), "A streamed zero-argument call consolidating to '' keeps the null-args semantics.");
    }

    public function testListRootedToolCallArgumentsPreserveNestedObjectNessThroughReplay()
    {
        /*
         * GLM6 #2: the object-ness walk ran only for stdClass roots, so a
         * LIST-rooted arguments string kept the SDK parent's associative
         * decode — whose nested empty and numeric-keyed objects re-encoded
         * as JSON lists on every later replay ([{"a":{}},{"0":"x"}] became
         * [{"a":[]},["x"]]), the GLM5 #1 corruption class one level down.
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-lr","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_lr","type":"function","function":{"name":"batch","arguments":"[{\"a\":{}},{\"0\":\"x\"}]"}}]},"finish_reason":"tool_calls"}]}');

        $assistant = $this->model()->generateTextResult($this->prompt())->toMessage();

        $args = $assistant->getParts()[0]->getFunctionCall()->getArgs();
        $this->assertIsArray($args, 'A list root must stay a list.');
        $this->assertArrayHasKey(0, $args);
        $this->assertInstanceOf('stdClass', $args[0]['a'], 'A nested empty object must stay an object under a list root.');
        $this->assertInstanceOf('stdClass', $args[1], 'A nested numeric-keyed object must stay an object under a list root.');
        $this->assertSame('x', $args[1]->{'0'});

        // Replay the answered turn: the outbound arguments string must
        // carry exactly the object shapes the model produced.
        $this->queueSdkResponse(200, array(), wp_json_encode(array(
            'id' => 'chatcmpl-lr-after',
            'choices' => array(array(
                'message' => array('role' => 'assistant', 'content' => 'done'),
                'finish_reason' => 'stop',
            )),
        )));

        $this->model()->generateTextResult(array(
            $this->prompt()[0],
            $assistant,
            new Message(WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(), array(
                new MessagePart(new FunctionResponse('call_lr', 'batch', array('ok' => true))),
            )),
        ));

        $attempts = $this->sdkHttpAttempts();
        $this->assertStringContainsString(
            '"arguments":"[{\"a\":{}},{\"0\":\"x\"}]"',
            (string) $attempts[1]['body'],
            'The zai surface replay must preserve nested object shapes under a list root.'
        );
    }

    public function testAnEmptyListRootedArgumentsStringKeepsTheParentSemantics()
    {
        // GLM6 #2 guard: an EMPTY list root has no nested members whose
        // object-ness could be lost — it decodes identically through the
        // walk and the SDK parent's associative decode alike.
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-el","choices":[{"message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_el","type":"function","function":{"name":"ping","arguments":"[]"}}]},"finish_reason":"tool_calls"}]}');

        $call = $this->model()->generateTextResult($this->prompt())->toMessage()->getParts()[0]->getFunctionCall();

        $this->assertSame(array(), $call->getArgs());
    }

    public function testALateNonMergingUsageMemberDoesNotPoisonTheMergedOne()
    {
        /*
         * GLM6 #3: the streamed validator compared the merged usage
         * member against the raw oracle of the LAST usage-BEARING frame,
         * while the merge takes the last frame whose usage was an ARRAY —
         * so a trailing "usage":"corrupt" member handed the validator a
         * frame the consolidated payload does not carry and rejected an
         * otherwise-complete generation. The oracle now describes exactly
         * the merged frame.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-ln","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-ln","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":3,"completion_tokens":2,"total_tokens":5}}',
            'data: {"id":"chatcmpl-ln","usage":"corrupt"}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hi', $result->toText());
        $this->assertSame(5, $result->getTokenUsage()->getTotalTokens(), 'The merged usage member stands.');
    }

    public function testALateNullUsageMemberChangesNothingAboutTheMergedUsage()
    {
        /*
         * GLM6 #3 (null variant) originally pinned that a trailing
         * "usage":null must not lose the raw oracle for the merged empty
         * LIST member — GLM7 #8 then restored master semantics on this
         * surface, where "usage":[] zero-defaults, so the shape no longer
         * rejects here. The invariant that remains: the late null member
         * does not merge and must not disturb the merged member's
         * accounting (zeros, not an error).
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-ln2","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-ln2","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":[]}',
            'data: {"id":"chatcmpl-ln2","usage":null}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hi', $result->toText());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens(), 'The merged empty-list usage keeps master zero semantics despite the later null member.');
    }

    public function testAStreamedUnencodableNonUsageMemberIsRejectedTypedNotMasked()
    {
        /*
         * GLM6 #6: finish_reason (like delta.role) is stored verbatim by
         * the aggregator's merge, so an INF value — "finish_reason":1e999
         * — made wp_json_encode($aggregated) return false and the string
         * cast collapsed the consolidated body to '': the SDK parse then
         * failed as the generic 'payload was malformed', masking the real
         * cause. The whole-payload encodability check rejects typed now.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-inf2","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-inf2","choices":[{"index":0,"delta":{},"finish_reason":1e999}]}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A streamed unencodable non-usage member must be rejected typed.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('cannot be JSON-encoded', $e->getMessage());
            $this->assertStringNotContainsString('malformed', $e->getMessage(), 'The generic masked message must not fire.');
        }
    }

    public function testTheStreamedEncodabilityOracleMatchesTheStructuralWalker()
    {
        /*
         * GLM10 #10: the whole-payload json_encode() INF oracle swapped
         * for the GLM9 #13 structural walker (O(tree) with zero
         * serialization per streamed generation). The pin: on every
         * REACHABLE aggregated shape the two decide alike — INF at any
         * depth fails the encode and the walker alike; clean payloads
         * pass both. The one disclosed strict superset (a finite
         * integral float beyond PHP_INT_MAX, which json_encode()
         * accepts) is pinned as the walker's decision, matching the
         * SDK's downstream is_string gates.
         */
        $clean = array(
            'id' => 'chatcmpl-x',
            'choices' => array(array('index' => 0, 'delta' => array('role' => 'assistant', 'content' => 'Hi'), 'finish_reason' => 'stop')),
            'usage' => array('prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3),
        );
        $this->assertNotFalse(json_encode($clean));
        $this->assertTrue(ToolArgsReplayGuard::is_replayable_decoded($clean), 'A clean payload passes the walker.');

        $infShapes = array(
            'finish_reason INF' => array('choices' => array(array('finish_reason' => INF))),
            'deeply nested INF' => array('choices' => array(array('delta' => array('content' => 'x'), 'meta' => array('deep' => array(INF))))),
            'INF under a list' => array('choices' => array(array('tool_calls' => array(array('function' => array('arguments' => INF)))))),
        );
        foreach ($infShapes as $label => $payload) {
            $this->assertFalse(json_encode($payload), "{$label}: the encode oracle rejects it.");
            $this->assertFalse(ToolArgsReplayGuard::is_replayable_decoded($payload), "{$label}: the walker rejects it identically.");
        }

        $beyondInt = array('choices' => array(array('finish_reason' => 1e300)));
        $this->assertNotFalse(json_encode($beyondInt), 'Sanity: the encode oracle ACCEPTS a beyond-PHP_INT_MAX float (the disclosed superset).');
        $this->assertFalse(ToolArgsReplayGuard::is_replayable_decoded($beyondInt), 'The walker rejects it — as the downstream is_string gates would.');
    }

    public function testTheDecodedStreamHandOffParsesExactlyLikeTheEncodedOne()
    {
        /*
         * GLM6 #14: the consolidated payload now reaches the SDK parser
         * DECODED (the pre-decoded Response shim) instead of through a
         * wp_json_encode()/getData() round trip. The shapes are identical
         * by construction (the payload is built from associative frame
         * decodes); this parity test pins that end-to-end for the heavy
         * shape classes — awkward tool-call stream indexes, text content,
         * usage — against the same assertions the encoded path carried.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-hd","choices":[{"index":0,"delta":{"role":"assistant","content":"Hel"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-hd","choices":[{"index":0,"delta":{"tool_calls":[{"index":1,"id":"call_b","type":"function","function":{"name":"tool_b","arguments":"{\"x\":"}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-hd","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"call_a","type":"function","function":{"name":"tool_a","arguments":"{}"}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-hd","choices":[{"index":0,"delta":{"content":"lo"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-hd","choices":[{"index":0,"delta":{"tool_calls":[{"index":1,"function":{"arguments":"1}"}}]},"finish_reason":"tool_calls"}],"usage":{"prompt_tokens":11,"completion_tokens":7,"total_tokens":18}}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $result = $this->model()->generateTextResult($this->prompt());
        $parts = $result->toMessage()->getParts();

        $this->assertSame('Hello', $result->toText());
        $this->assertSame('chatcmpl-hd', $result->getId());
        $this->assertSame(FinishReasonEnum::toolCalls(), $result->getCandidates()[0]->getFinishReason());

        $calls = array();
        foreach ($parts as $part) {
            if (null !== $part->getFunctionCall()) {
                $calls[] = $part->getFunctionCall();
            }
        }

        $this->assertCount(2, $calls);
        $this->assertSame('call_a', $calls[0]->getId(), 'Tool calls arrive reindexed by stream index.');
        $this->assertInstanceOf('stdClass', $calls[0]->getArgs(), 'An empty-object arguments string stays an object (GLM5 #1).');
        $this->assertSame(array(), get_object_vars($calls[0]->getArgs()));
        $this->assertSame('call_b', $calls[1]->getId());
        $this->assertSame(array('x' => 1), $calls[1]->getArgs());

        $this->assertSame(11, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(7, $result->getTokenUsage()->getCompletionTokens());
        $this->assertSame(18, $result->getTokenUsage()->getTotalTokens());
    }

    public function testTheStreamedConsolidationNoLongerRoundTripsThroughAJsonBody()
    {
        /*
         * GLM6 #14 (verifier round): the decoded hand-off is
         * behavior-preserving by design, so a behavioral parity test
         * cannot discriminate it from the wp_json_encode()/getData()
         * round trip it replaced. This pins the mechanism at the source
         * level (the GLM6 #10 extraction-pattern precedent): the streamed
         * consolidation must construct the pre-decoded Response and must
         * not re-encode the aggregated payload.
         */
        $source = (string) file_get_contents(
            __DIR__ . '/../../connectors/zai/src/Models/ZaiTextGenerationModel.php'
        );

        $this->assertSame(
            1,
            preg_match('/new PreDecodedResponse\(/', $source),
            'The streamed consolidation must hand the parser the pre-decoded payload.'
        );
        $this->assertSame(
            0,
            preg_match('/wp_json_encode\(\s*\$aggregated/', $source),
            'The aggregated payload must not be re-encoded into a synthetic body.'
        );
    }

    public function testTheNonStreamingPathDecodesTheBodyOnceAndHandsOffPreDecoded()
    {
        /*
         * GLM7 #9: the decoded hand-off is behavior-preserving by design
         * (the entire non-streaming suite runs through it), so — the
         * GLM6 #14 source-level precedent — this pins the mechanism: the
         * non-streaming branch must hand the parser a PreDecodedResponse
         * built from ONE shared decode, and reject_malformed_usage() must
         * not re-read the Response (the vendor getData() re-decodes the
         * whole body per call; three decodes where master paid one).
         */
        $source = (string) file_get_contents(
            __DIR__ . '/../../connectors/zai/src/Models/ZaiTextGenerationModel.php'
        );

        $this->assertSame(
            2,
            preg_match_all('/new PreDecodedResponse\(/', $source),
            'Both the streamed and non-streamed paths must hand the parser the pre-decoded payload.'
        );
        $this->assertSame(
            0,
            preg_match('/reject_malformed_usage\(\s*\$response\s*\)/', $source),
            'The usage pre-check must consume the shared decode, not a fresh Response read.'
        );
    }

    public function testNonStreamingStringUsageMemberIsRejectedTyped()
    {
        /*
         * GLM5 #3: a string usage member reached the SDK parent's
         * int-typed TokenUsage constructor unvalidated (the shared
         * validator was wired into the Anthropic transports only) and
         * detonated as a raw strict-types TypeError — surfaced by the
         * mapper's catch-all as the generic 500 instead of the typed
         * zai_invalid_response.
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-u1","choices":[{"message":{"role":"assistant","content":"hi"},"finish_reason":"stop"}],"usage":{"prompt_tokens":"5","completion_tokens":3,"total_tokens":8}}');

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A string usage member must be rejected typed.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('Token counts must be non-negative integers.', $e->getMessage());
        }
    }

    public function testNonStreamingListShapedUsageMemberIsRejectedTyped()
    {
        // GLM5 #3: a list-shaped usage member is not a JSON object.
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-u2","choices":[{"message":{"role":"assistant","content":"hi"},"finish_reason":"stop"}],"usage":[1,2]}');

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A list-shaped usage member must be rejected typed.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('The usage member must be a JSON object.', $e->getMessage());
        }
    }

    public function testStreamedInfUsageMemberIsRejectedTypedNotMasked()
    {
        /*
         * GLM5 #3 (streamed half): an INF usage member made
         * wp_json_encode($aggregated) return false, collapsing the
         * consolidated body to '' so the failure surfaced as 'The
         * chat-completions payload was malformed.', masking the real
         * cause. The usage member is validated before the re-encode now.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-su","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-su","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":1e999,"completion_tokens":1,"total_tokens":2}}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A streamed INF usage member must be rejected typed.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('Token counts must be non-negative integers.', $e->getMessage());
        }
    }

    public function testStreamedListShapedUsageMemberIsRejectedTyped()
    {
        // GLM5 #3: the aggregator's associative decode cannot tell a JSON
        // list usage from an object; the validator's fallback can.
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-su2","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-su2","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":[1,2]}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A streamed list-shaped usage member must be rejected typed.');
        } catch (ResponseException $e) {
            $this->assertStringContainsString('The usage member must be a JSON object.', $e->getMessage());
        }
    }

    public function testStreamedEmptyListUsageMemberKeepsMasterZeroSemantics()
    {
        /*
         * GLM7 #8: master read token counts with ($usage['prompt_tokens']
         * ?? 0), so a streamed final "usage":[] produced a SUCCESSFUL
         * zero-defaulted generation on the legacy surface — semantics the
         * GLM5 #3 shared validator silently dropped when it wired both
         * surfaces onto one strict rule. The lenient mode restores them
         * (the Anthropic surface keeps rejecting the identical shape).
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-su3","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-su3","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":[]}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hi', $result->toText());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * @dataProvider provideMasterToleratedUsageShapes
     */
    public function testMasterToleratedUsageShapesKeepSucceeding($usageFragment, $expectedTotal, $label)
    {
        /*
         * GLM7 #8 (non-streamed half): "usage":null, "usage":[], and
         * explicitly-null token members all zero-defaulted on master —
         * the strict shared validator turned each into a typed rejection
         * for every existing zai consumer. Lenient mode keeps the master
         * verdicts; genuinely corrupt shapes (scalars, non-empty lists,
         * string counts) still reject.
         */
        $this->queueSdkResponse(200, array(), '{"id":"chatcmpl-mt","choices":[{"message":{"role":"assistant","content":"hi"},"finish_reason":"stop"}],' . $usageFragment . '}');

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('hi', $result->toText(), "{$label}: the generation must succeed.");
        $this->assertSame($expectedTotal, $result->getTokenUsage()->getTotalTokens(), "{$label}: master zero/default semantics.");
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideMasterToleratedUsageShapes()
    {
        return array(
            'null usage member' => array('"usage":null', 0, 'null usage member'),
            'empty list usage member' => array('"usage":[]', 0, 'empty list usage member'),
            'null prompt member' => array('"usage":{"prompt_tokens":null,"completion_tokens":3,"total_tokens":3}', 3, 'null prompt member'),
        );
    }

    public function testStreamedEmptyObjectUsageMemberStaysTolerated()
    {
        // Verifier round on GLM5 #3: the legitimate "usage":{} shape keeps
        // its documented default-zero tolerance on BOTH transports.
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-su4","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-su4","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{}}',
            'data: [DONE]',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hi', $result->toText());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
    }

    public function testLengthFinishReasonMapsToLength()
    {
        $this->queueSdkResponse(200, array(), wp_json_encode(array(
            'id' => 'chatcmpl-4',
            'choices' => array(array(
                'message' => array('role' => 'assistant', 'content' => 'trunc'),
                'finish_reason' => 'length',
            )),
        )));

        $result = $this->model()->generateTextResult($this->prompt());
        $this->assertSame(FinishReasonEnum::length(), $result->getCandidates()[0]->getFinishReason());
    }

    /*
     * SSE aggregation.
     */

    public function testStreamsSimpleContentDeltas()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), implode("\n\n", array(
            'data: {"id":"chatcmpl-s","choices":[{"index":0,"delta":{"role":"assistant","content":"Hel"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-s","choices":[{"index":0,"delta":{"content":"lo "},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-s","choices":[{"index":0,"delta":{"content":"world"},"finish_reason":"stop"}]}',
            'data: {"id":"chatcmpl-s","choices":[{"index":0,"delta":{},"finish_reason":null}],"usage":{"prompt_tokens":4,"completion_tokens":3,"total_tokens":7}}',
            'data: [DONE]',
            '',
        )));

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hello world', $result->toText());
        $this->assertSame('chatcmpl-s', $result->getId());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
        $this->assertSame(7, $result->getTokenUsage()->getTotalTokens());
    }

    public function testPostSentinelFramesDoNotMutateTheCompletedPayload()
    {
        /*
         * GLM5 #7: 'data: [DONE]' set the sentinel flag but nothing
         * consulted it, so frames an intermediary APPENDED after it
         * still merged into the aggregated payload — content
         * concatenated, finish reason and usage overwritten — silently
         * mutating a completed generation. GLM7 #2 narrowed the policy:
         * trailing frames may only COMPLETE the payload (see the
         * gap-fill test below); overwriting data it already carries
         * stays rejected.
         */
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), implode("\n\n", array(
            'data: {"id":"chatcmpl-td","choices":[{"index":0,"delta":{"role":"assistant","content":"A"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-td","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":4,"completion_tokens":3,"total_tokens":7}}',
            'data: [DONE]',
            'data: {"id":"chatcmpl-td","choices":[{"index":0,"delta":{"content":"GHOST"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-td","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}],"usage":{"prompt_tokens":99,"completion_tokens":99,"total_tokens":198}}',
            '',
        )));

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('A', $result->toText(), 'Post-sentinel content must not merge into the completion.');
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason(), 'Post-sentinel finish reasons must not overwrite the completion.');
        $this->assertSame(7, $result->getTokenUsage()->getTotalTokens(), 'Post-sentinel usage must not overwrite the completion.');
    }

    public function testFinalUsageAndFinishReasonAfterTheDoneSentinelCompleteThePayload()
    {
        /*
         * GLM7 #2: appending gateways emit the FINAL chunk (the one
         * carrying finish_reason and usage) after the `data: [DONE]`
         * sentinel — the repo's records document the shape. Master
         * merged it; the GLM5 #7 wholesale drop made the completed
         * generation fail the SDK parse (missing
         * choices[0].finish_reason) or report zero token usage. The
         * trailing terminal data gap-fills the payload now.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-tg","choices":[{"index":0,"delta":{"role":"assistant","content":"Completed"},"finish_reason":null}]}',
            'data: [DONE]',
            'data: {"id":"chatcmpl-tg","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":11,"completion_tokens":5,"total_tokens":16}}',
            '',
        ));

        $aggregator = new SseAggregator();
        $aggregator->feed($stream);
        $aggregator->finish();

        $aggregated = $aggregator->aggregated();
        $this->assertSame('stop', $aggregated['choices'][0]['finish_reason'], 'A trailing finish reason must complete a choice that lacks one.');
        $this->assertSame(16, $aggregated['usage']['total_tokens'], 'A trailing usage member must fill the payload when none merged pre-sentinel.');
        $this->assertSame(1, $aggregator->event_count(), 'Trailing frames must not count as content events.');

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Completed', $result->toText());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
        $this->assertSame(16, $result->getTokenUsage()->getTotalTokens());
    }

    public function testAnEmptyPreSentinelUsageMemberDoesNotBlockTheTrailingGapFill()
    {
        /*
         * Verifier round on GLM7 #2: an intermediate "usage":{} (a
         * null-usage normalization several OpenAI-compatible gateways
         * emit) passed the isset-merge, so the strict null gap-fill
         * guard counted it as "usage already merged" — the appending
         * gateway's real final usage chunk after the sentinel was
         * silently dropped and the completed generation reported zero
         * tokens where master's last-wins merge carried the real counts.
         * An empty member carries no token data: it is gap-fillable.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-eu","choices":[{"index":0,"delta":{"role":"assistant","content":"Hi"},"finish_reason":"stop"}],"usage":{}}',
            'data: [DONE]',
            'data: {"id":"chatcmpl-eu","choices":[],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
            '',
        ));

        $aggregator = new SseAggregator();
        $aggregator->feed($stream);
        $aggregator->finish();

        $aggregated = $aggregator->aggregated();
        $this->assertSame(15, $aggregated['usage']['total_tokens'], 'The trailing usage must complete an empty pre-sentinel member.');

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Hi', $result->toText());
        $this->assertSame(15, $result->getTokenUsage()->getTotalTokens(), 'Zero-token silent undercounting versus master is the exact regression GLM7 #2 fixes.');
    }

    public function testPostSentinelFramesOpenNoNewTurnsAndCountMalformed()
    {
        /*
         * GLM7 #2 (aggregator half): a trailing frame cannot create a
         * choice accumulator (an unknown index is gap-fill-inert), its
         * delta content never merges, and its malformed JSON still
         * counts — master's decode pipeline, minus the content mutation.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-tn","choices":[{"index":0,"delta":{"role":"assistant","content":"Only"},"finish_reason":"stop"}]}',
            'data: [DONE]',
            'data: {"id":"chatcmpl-tn","choices":[{"index":5,"delta":{"content":"GHOST"},"finish_reason":"length"}]}',
            'data: not json',
            '',
        ));

        $aggregator = new SseAggregator();
        $aggregator->feed($stream);
        $aggregator->finish();

        $aggregated = $aggregator->aggregated();

        $this->assertSame('Only', $aggregated['choices'][0]['message']['content'], 'Post-sentinel content must not merge.');
        $this->assertArrayNotHasKey(1, $aggregated['choices'], 'A trailing unknown index must not open a new choice turn.');
        $this->assertSame('stop', $aggregated['choices'][0]['finish_reason'], 'A trailing finish reason must not replace a present one.');
        $this->assertSame(1, $aggregator->malformed_count(), 'A malformed post-sentinel frame must still count.');
        $this->assertFalse($aggregator->has_malformed_event(), 'Well-formed trailing frames are not corruption.');
    }

    public function testAStreamPrefixedWithAUtf8BomStillAggregates()
    {
        /*
         * GLM3 #7: a gateway/CDN-prepended BOM glued itself to the first
         * data: frame, which then matched no known prefix and was
         * silently dropped — on this surface a single-event stream
         * aggregated to null and failed with 'No usable
         * chat.completion.chunk event was received.' The shared
         * SseFrameBuffer now strips the BOM at stream start.
         */
        $body = "\xEF\xBB\xBF" . implode("\n\n", array(
            'data: {"id":"chatcmpl-bom","choices":[{"index":0,"delta":{"role":"assistant","content":"Bom-proof."},"finish_reason":"stop"}]}',
            'data: [DONE]',
            '',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Bom-proof.', $result->toText());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * @dataProvider provideMangledContentTypeStreamLeads
     */
    public function testStreamsSniffedByBodyLeadWhenTheContentTypeIsMangled($lead, $label)
    {
        /*
         * GLM4 #3: this surface's sniff recognized only a leading 'data:'
         * line, so the exact scenario the Anthropic twin's GLM3 #5/#7
         * fixes cite — a gateway that mangles/omits the
         * text/event-stream Content-Type AND prepends a BOM or a
         * ': keepalive' comment — misrouted the stream to the JSON
         * parser and died as 'The chat-completions payload was
         * malformed' although the shared SseFrameBuffer would have
         * framed it fine. Both surfaces now sniff through the shared
         * EventStreamSniff.
         */
        $body = $lead . implode("\n\n", array(
            'data: {"id":"chatcmpl-sniff","choices":[{"index":0,"delta":{"role":"assistant","content":"Sniffed anyway."},"finish_reason":"stop"}]}',
            'data: [DONE]',
            '',
        ));

        $this->queueSdkResponse(200, array('Content-Type' => 'application/octet-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());

        $this->assertSame('Sniffed anyway.', $result->toText(), "[{$label}] The body lead must sniff as an event stream.");
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideMangledContentTypeStreamLeads()
    {
        return array(
            'leading comment line' => array(': keepalive' . "\n\n", 'leading comment line'),
            'leading UTF-8 BOM' => array("\xEF\xBB\xBF", 'leading UTF-8 BOM'),
            'BOM then comment line' => array("\xEF\xBB\xBF: keepalive" . "\n\n", 'BOM then comment line'),
            'leading event field' => array('event: message' . "\n\n", 'leading event field'),
            // GLM4 #8: the shared sniff also recognizes the id:/retry:
            // SSE fields both aggregators tolerate mid-stream.
            'leading id field' => array('id: 42' . "\n\n", 'leading id field'),
            'leading retry field' => array('retry: 3000' . "\n\n", 'leading retry field'),
            // GLM6 #11: PHP's default whitespace set — a NUL or vertical
            // tab byte before the first field line must not stop the sniff.
            'leading NUL byte' => array("\0\n", 'leading NUL byte'),
            'leading vertical tab' => array("\x0B\n", 'leading vertical tab'),
            // GLM8 #2: whitespace around a BOM rides the one canonical
            // prefix rule (SseFrameBuffer::strip_stream_prefix) — the
            // sniff accepted these shapes while the framing dropped their
            // first frame silently, corrupting the aggregated content.
            'whitespace then BOM' => array(" \xEF\xBB\xBF", 'whitespace then BOM'),
            'BOM then whitespace' => array("\xEF\xBB\xBF ", 'BOM then whitespace'),
            'newline then BOM' => array("\r\n\xEF\xBB\xBF", 'newline then BOM'),
        );
    }

    public function testStreamsToolCallDeltasMergedAcrossChunks()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), implode("\n\n", array(
            'data: {"id":"chatcmpl-t","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"get_weather","arguments":""}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-t","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"city\":"}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-t","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\"Paris\"}"}}]},"finish_reason":"tool_calls"}]}',
            'data: [DONE]',
            '',
        )));

        $message = $this->model()->generateTextResult($this->prompt())->toMessage();

        $call = $message->getParts()[0]->getFunctionCall();
        $this->assertSame('call_1', $call->getId());
        $this->assertSame('get_weather', $call->getName());
        $this->assertSame(array('city' => 'Paris'), $call->getArgs());
    }

    public function testMergedToolCallsEncodeAsAJsonListForAwkwardStreamIndexes()
    {
        // r5: out-of-order, non-zero-starting, or sparse tool-call stream
        // indexes must not leak insertion-order/sparse PHP integer keys into
        // the consolidated payload — json_encode would produce an OBJECT for
        // message.tool_calls and the valid multi-tool stream would fail the
        // non-streaming parser as malformed.
        $cases = array(
            'out-of-order indexes' => array(
                'deltas' => array(array(1, 'call_b'), array(0, 'call_a')),
                'expected_ids' => array('call_a', 'call_b'),
            ),
            'first index greater than zero' => array(
                'deltas' => array(array(1, 'call_b'), array(2, 'call_c')),
                'expected_ids' => array('call_b', 'call_c'),
            ),
            'sparse gapped indexes' => array(
                'deltas' => array(array(0, 'call_a'), array(2, 'call_c')),
                'expected_ids' => array('call_a', 'call_c'),
            ),
        );

        foreach ($cases as $label => $case) {
            $aggregator = new SseAggregator();
            foreach ($case['deltas'] as list($index, $id)) {
                $aggregator->feed('data: ' . wp_json_encode(array(
                    'id' => 'chatcmpl-tc',
                    'choices' => array(array(
                        'index' => 0,
                        'delta' => array('tool_calls' => array(array(
                            'index' => $index,
                            'id' => $id,
                            'type' => 'function',
                            'function' => array('name' => 'tool_' . $id, 'arguments' => '{}'),
                        ))),
                        'finish_reason' => null,
                    )),
                )) . "\n\n");
            }
            $aggregator->finish();

            $toolCalls = $aggregator->aggregated()['choices'][0]['message']['tool_calls'];

            $json = wp_json_encode($toolCalls);
            $this->assertStringStartsWith('[', $json, "{$label}: tool_calls must encode as a JSON list.");
            $this->assertStringStartsNotWith('{', $json, "{$label}: tool_calls must not encode as a JSON object.");

            $decoded = json_decode($json, true);
            $this->assertSame(
                range(0, count($case['deltas']) - 1),
                array_keys($decoded),
                "{$label}: merged tool calls must be reindexed 0-based."
            );
            $this->assertSame(
                $case['expected_ids'],
                array_map(static function (array $call) {
                    return $call['id'];
                }, $decoded),
                "{$label}: tool calls must be ordered by stream index."
            );
        }
    }

    /**
     * @dataProvider provideUnusableChoiceIndexes
     */
    public function testStreamedChoicesWithUnusableIndexesAreRejectedTyped($indexFragment, $label)
    {
        /*
         * GLM7 #1: a chunk choice whose 'index' member is missing, null,
         * or not a non-negative integer was silently SKIPPED (that
         * delta's content vanished from a successful stream) or
         * int-COERCED into the wrong accumulator — the Anthropic twin
         * added in this branch rejects the identical corruption as a
         * malformed event, and the legacy surface now fails typed
         * through the same channel instead of returning wrong output.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-bi","choices":[{"index":0,"delta":{"role":"assistant","content":"Good"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-bi","choices":[{' . $indexFragment . '"delta":{"content":"MISSING"},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-bi","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}',
            'data: [DONE]',
            '',
        ));

        $aggregator = new SseAggregator();
        $aggregator->feed($stream);
        $aggregator->finish();
        $aggregator->aggregated();

        $this->assertTrue($aggregator->has_malformed_event(), "{$label}: the unusable choice index must flag the stream.");

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail("{$label}: an unusable choice index must be rejected typed.");
        } catch (ResponseException $e) {
            $this->assertStringContainsString('malformed chunk event', $e->getMessage());
            $this->assertStringNotContainsString('MISSING', $e->getMessage(), 'Raw event payloads must not be echoed.');
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideUnusableChoiceIndexes()
    {
        return array(
            'index omitted' => array('', 'index omitted'),
            'index null' => array('"index":null,', 'index null'),
            'index string' => array('"index":"0",', 'index string'),
            'index float' => array('"index":1.9,', 'index float'),
            'index negative' => array('"index":-1,', 'index negative'),
        );
    }

    /**
     * @dataProvider provideUnusableToolCallIndexes
     */
    public function testStreamedToolCallDeltasWithUnusableIndexesAreRejectedTyped($toolIndexFragment, $label)
    {
        /*
         * GLM7 #1 (tool-call half): a tool_calls delta whose 'index' is
         * null passed array_key_exists() and coerced to 0 — the fragment
         * merged into the WRONG call's accumulator; a missing one was
         * skipped, silently truncating the call's arguments.
         */
        $stream = implode("\n\n", array(
            'data: {"id":"chatcmpl-ti","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_ok","type":"function","function":{"name":"get_weather","arguments":"{}"}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-ti","choices":[{"index":0,"delta":{"tool_calls":[{' . $toolIndexFragment . '"function":{"arguments":"{\\"city\\":\\"Paris\\"}"}}]},"finish_reason":null}]}',
            'data: {"id":"chatcmpl-ti","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}',
            'data: [DONE]',
            '',
        ));

        $aggregator = new SseAggregator();
        $aggregator->feed($stream);
        $aggregator->finish();
        $aggregator->aggregated();

        $this->assertTrue($aggregator->has_malformed_event(), "{$label}: the unusable tool-call index must flag the stream.");

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $stream);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail("{$label}: an unusable tool-call index must be rejected typed.");
        } catch (ResponseException $e) {
            $this->assertStringContainsString('malformed chunk event', $e->getMessage());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideUnusableToolCallIndexes()
    {
        return array(
            'tool index omitted' => array('', 'tool index omitted'),
            'tool index null' => array('"index":null,', 'tool index null'),
            'tool index string' => array('"index":"1",', 'tool index string'),
            'tool index float' => array('"index":0.9,', 'tool index float'),
        );
    }

    public function testStreamParserToleratesSplitFramesCrlfCommentsAndMalformedEvents()
    {
        $body = ""
            . 'data: {"id":"chatcmpl-x","choices":[{"index":0,"delta":{"role":"assistant","content":"A"},"finish_reason":null}]}' . "\r\n\r\n"
            . ': keep-alive comment' . "\r\n\r\n"
            . 'event: message' . "\n"
            . 'data: {"id":"chatcmpl-x","choices":[{"index":0,"delta":{"content":"B"},"finish_reason":null}]}' . "\n\n"
            . 'data: this is not json' . "\n\n"
            . 'data: {"id":"chatcmpl-x","choices":[{"index":0,"delta":{"content":"C"},"finish_reason":"stop"}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        $aggregator = new SseAggregator();
        // Feed in awkwardly-sized pieces to prove frames may split anywhere.
        foreach ( str_split($body, 13) as $piece ) {
            $aggregator->feed($piece);
        }
        $aggregator->finish();

        $this->assertTrue($aggregator->is_done());
        $this->assertSame(3, $aggregator->event_count());
        $this->assertSame(1, $aggregator->malformed_count());

        $aggregated = $aggregator->aggregated();
        $this->assertSame('ABC', $aggregated['choices'][0]['message']['content']);
        $this->assertSame('stop', $aggregated['choices'][0]['finish_reason']);
    }

    public function testStreamWithoutUsableEventsFailsSafely()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), "data: broken\n\ndata: also broken\n\ndata: [DONE]\n\n");

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('An all-malformed stream must throw.');
        } catch (WordPress\AiClient\Providers\Http\Exception\ResponseException $e) {
            // GLM10 #9: the surface's one unified provider label.
            $this->assertStringContainsString('Unexpected zai API response', $e->getMessage());
            $this->assertStringNotContainsString('broken', $e->getMessage(), 'Raw event payloads must not be echoed.');
        }
    }

    public function testMixedLineEndingBoundariesAreRecognized()
    {
        // SSE allows CR, LF, and CRLF line terminators mixed freely; a data
        // line ending LF followed by a CRLF blank line is a legal frame
        // boundary (review finding: previously both events were lost).
        $body = ""
            . 'data: {"id":"chatcmpl-m","choices":[{"index":0,"delta":{"role":"assistant","content":"Mixed"},"finish_reason":null}]}' . "\n\r\n"
            . 'data: {"id":"chatcmpl-m","choices":[{"index":0,"delta":{"content":"!"},"finish_reason":"stop"}]}' . "\r\r"
            . 'data: [DONE]' . "\n\n";

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());
        $this->assertSame('Mixed!', $result->toText());
    }

    public function testNonStandardFinishReasonYieldsAFixedSafeMessage()
    {
        // The SDK's parser embeds the upstream finish_reason verbatim into
        // its ResponseException; the model must replace that message so no
        // upstream content reaches error surfaces.
        $upstream = '<img src=x onerror=alert(1)>Bearer ' . FakeSecrets::apiKey();
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), wp_json_encode(array(
            'id' => 'chatcmpl-bad',
            'choices' => array(array(
                'message' => array('role' => 'assistant', 'content' => 'x'),
                'finish_reason' => $upstream,
            )),
        )));

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A non-standard finish_reason must throw.');
        } catch ( WordPress\AiClient\Providers\Http\Exception\ResponseException $e ) {
            $this->assertStringContainsString('malformed', $e->getMessage());
            $this->assertRedacted($e->getMessage(), FakeSecrets::apiKey());
            $this->assertStringNotContainsString('<img', $e->getMessage());
        }
    }

    public function testStreamWithoutDoneSentinelStillAggregates()
    {
        // [DONE] is conventional, not required by our parser.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'),
            'data: {"id":"chatcmpl-n","choices":[{"index":0,"delta":{"role":"assistant","content":"Done."},"finish_reason":"stop"}]}' . "\n\n");

        $this->assertSame('Done.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testFinishFlushesTheFinalFrameWhenTheStreamEndsWithoutADelimiter()
    {
        // A response that ends right after the last data: line (no trailing
        // blank line) must not lose that frame: the buffered remainder is a
        // real final event, not a split chunk (review finding: finish()
        // previously discarded it — single-event streams failed as
        // zai_invalid_response, multi-event streams lost their final content).
        $body = ''
            . 'data: {"id":"chatcmpl-f","choices":[{"index":0,"delta":{"role":"assistant","content":"Tail "},"finish_reason":null}]}' . "\n\n"
            . 'data: {"id":"chatcmpl-f","choices":[{"index":0,"delta":{"content":"frame."},"finish_reason":"stop"}]}';

        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'), $body);

        $result = $this->model()->generateTextResult($this->prompt());
        $this->assertSame('Tail frame.', $result->toText());
        $this->assertSame(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
    }

    public function testFinishFlushesASingleEventStreamWithoutAnyTrailingNewline()
    {
        // Minimum repro of the lost-frame defect: one event, no terminator
        // at all — previously zai_invalid_response, now a parsed result.
        $this->queueSdkResponse(200, array('Content-Type' => 'text/event-stream'),
            'data: {"id":"chatcmpl-f1","choices":[{"index":0,"delta":{"role":"assistant","content":"Only event."},"finish_reason":"stop"}]}');

        $this->assertSame('Only event.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    public function testFinishNormalizesAHeldBackTrailingCrBeforeDispatchingTheFinalFrame()
    {
        // A body ending in a lone CR leaves the CR held back in feed(); at
        // end of input it is definitively a line terminator, so the final
        // frame must still be consumed and decode cleanly.
        $aggregator = new SseAggregator();
        $aggregator->feed('data: {"id":"chatcmpl-f2","choices":[{"index":0,"delta":{"role":"assistant","content":"CR tail."},"finish_reason":"stop"}]}' . "\r");
        $aggregator->finish();

        $this->assertSame(1, $aggregator->event_count());
        $this->assertSame('CR tail.', $aggregator->aggregated()['choices'][0]['message']['content']);
    }

    public function testEventStreamDetectedByBodyPrefixWithoutContentType()
    {
        $this->queueSdkResponse(200, array(),
            'data: {"id":"chatcmpl-p","choices":[{"index":0,"delta":{"role":"assistant","content":"Body-detected."},"finish_reason":"stop"}]}' . "\n\n");

        $this->assertSame('Body-detected.', $this->model()->generateTextResult($this->prompt())->toText());
    }

    /*
     * Error mapping and redaction.
     */

    /**
     * @dataProvider provideErrorStatuses
     */
    public function testErrorStatusesMapToSafeTypedExceptions($status, $expectedClass)
    {
        // Upstream error bodies may echo request material, including the
        // credential itself: the exception message must never include it.
        $secret = FakeSecrets::apiKey();
        $body = wp_json_encode(array('error' => array(
            'message' => 'token expired or incorrect; echoes Bearer ' . $secret,
            'type' => 'invalid_request_error',
        )));

        $this->queueSdkResponse($status, array('Content-Type' => 'application/json'), $body);

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail("Status {$status} must throw.");
        } catch ( \Exception $e ) {
            $this->assertInstanceOf($expectedClass, $e);
            $this->assertSame($status, $e->getCode());
            $this->assertRedacted($e->getMessage(), $secret);
            $this->assertStringNotContainsString('token expired', $e->getMessage(), 'Upstream error text must not be copied.');
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

    public function testErrorMessagesAreActionable()
    {
        $cases = array(
            401 => 'Connectors screen',
            403 => 'access',
            429 => 'plan/balance mismatch',
            500 => 'server error',
        );

        foreach ($cases as $status => $needle) {
            WpHarness::$sdk_http_attempts = array();
            $this->queueSdkResponse($status, array(), HttpResponseFactory::openAiErrorBody('ignored upstream text'));

            try {
                $this->model()->generateTextResult($this->prompt());
                $this->fail("Status {$status} must throw.");
            } catch ( \Exception $e ) {
                $this->assertStringContainsString($needle, $e->getMessage(), "Status {$status} message must be actionable.");
                $this->assertStringNotContainsString('ignored upstream text', $e->getMessage());
            }
        }
    }

    public function testNoRetriesAreAttemptedOn429()
    {
        $this->queueSdkResponse(429, array(), HttpResponseFactory::openAiErrorBody('slow down'));

        try {
            $this->model()->generateTextResult($this->prompt());
        } catch ( ClientException $e ) {
            // Expected.
        }

        $this->assertCount(1, $this->sdkHttpAttempts(), 'v1 must not retry rate-limited requests.');
    }

    public function testRateLimitMessageGuidesPlanAndBalanceMismatches()
    {
        // r5: z.ai 429 is ALSO code 1113 — a Coding-Plan key against the
        // General endpoint without pay-as-you-go balance (record 0006) —
        // an account state that "wait and retry" can never fix. The safe
        // (redacted, status-only) catalog text must guide both shapes and
        // name both region portals, without echoing the upstream body.
        $message = ErrorMapper::safe_http_message(429);

        $this->assertStringContainsString('rate limiting', $message, 'The temporary-rate-limit reading must stay.');
        $this->assertStringContainsString('plan/balance mismatch', $message);
        $this->assertStringContainsString('Coding Plan', $message);
        $this->assertStringContainsString('General API', $message);
        $this->assertStringContainsString('balance', $message);
        $this->assertStringContainsString('z.ai', $message, 'The international portal must be named.');
        $this->assertStringContainsString('open.bigmodel.cn', $message, 'The China portal must be named.');
        $this->assertStringNotContainsString('1113', $message, 'Upstream error codes must stay out of the fixed catalog.');
    }

    /*
     * Typed WP_Error mapping.
     */

    public function testGenerateTextReturnsTheResultOnSuccess()
    {
        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), wp_json_encode(array(
            'id' => 'chatcmpl-ok',
            'choices' => array(array(
                'message' => array('role' => 'assistant', 'content' => 'Hi.'),
                'finish_reason' => 'stop',
            )),
        )));

        $result = $this->model()->generate_text($this->prompt());

        $this->assertNotWPError($result);
        $this->assertSame('Hi.', $result->toText());
    }

    /**
     * @dataProvider provideBoundaryErrorCodes
     */
    public function testGenerateTextYieldsTypedWpErrorsAtTheBoundary($status, $expectedCode)
    {
        // The WP-facing boundary must convert the SDK exception into the
        // promised typed WP_Error (SPEC 6.2) — not leak the exception for
        // core's generic conversion (review finding).
        $secret = FakeSecrets::apiKey();
        $this->queueSdkResponse($status, array(), HttpResponseFactory::openAiErrorBody('upstream echoes Bearer ' . $secret));

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

    public function testGenerateTextMapsTransportFailuresToTypedWpErrors()
    {
        $this->allowUnmockedHttp = true;

        $error = $this->model()->generate_text($this->prompt());

        $this->assertWPError($error, ErrorMapper::CODE_TRANSPORT_ERROR);
        $this->assertRedacted($error->get_error_message(), FakeSecrets::apiKey());
    }

    public function testUnboundDirectModelSurfacesTheBindingHintNotAGenericError()
    {
        $this->primeZaiDiscoveryTransient();

        // Bare factory call: no transporter and no request auth — only
        // ProviderRegistry::getProviderModel() binds them. Direct generation
        // on the unbound model must report the SDK's clear binding hint
        // instead of the generic catch-all message.
        $model = ZaiProvider::model('glm-5.3');

        $error = $model->generate_text($this->prompt());

        $this->assertWPError($error, ErrorMapper::CODE_ERROR);
        $this->assertStringContainsString('instance not set', $error->get_error_message());
        $this->assertStringContainsString('AiClient', $error->get_error_message());
    }

    public function testErrorMapperProducesStableTypedWpErrors()
    {
        $cases = array(
            array(new ClientException('upstream says: secret stuff', 401), ErrorMapper::CODE_UNAUTHORIZED, 401),
            array(new ClientException('nope', 403), ErrorMapper::CODE_FORBIDDEN, 403),
            array(new ClientException('nope', 429), ErrorMapper::CODE_RATE_LIMITED, 429),
            array(new ClientException('nope', 422), ErrorMapper::CODE_CLIENT_ERROR, 422),
            array(new ServerException('boom', 502), ErrorMapper::CODE_UPSTREAM_ERROR, 502),
            array(new WordPress\AiClient\Providers\Http\Exception\RedirectException('moved', 307), ErrorMapper::CODE_REDIRECT_ERROR, 307),
        );

        foreach ($cases as $case) {
            list($exception, $expectedCode, $expectedStatus) = $case;
            $error = ErrorMapper::to_wp_error($exception);
            $this->assertWPError($error, $expectedCode);
            $this->assertSame($expectedStatus, $error->get_error_data()['status']);
        }
    }

    public function testErrorMapperRedactsUpstreamSecrets()
    {
        $secret = FakeSecrets::apiKey();
        $error = ErrorMapper::to_wp_error(new ClientException('Bearer ' . $secret . ' is invalid', 401));

        $this->assertWPError($error, ErrorMapper::CODE_UNAUTHORIZED);
        $this->assertRedacted($error->get_error_message(), $secret);
    }

    public function testErrorMapperCoversTransportAndParseFailures()
    {
        $transport = ErrorMapper::to_wp_error(new NetworkException('Network error occurred while sending request to https://api.z.ai/api/paas/v4/chat/completions: connection refused'));
        $this->assertWPError($transport, ErrorMapper::CODE_TRANSPORT_ERROR);
        $this->assertStringContainsString('connection refused', $transport->get_error_message());

        $parse = ErrorMapper::to_wp_error(WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData('z.ai', 'choices'));
        $this->assertWPError($parse, ErrorMapper::CODE_INVALID_RESPONSE);

        $invalid = ErrorMapper::to_wp_error(new WordPress\AiClient\Common\Exception\InvalidArgumentException('The z.ai provider does not support top-k.'));
        $this->assertWPError($invalid, ErrorMapper::CODE_INVALID_REQUEST);
        $this->assertStringContainsString('top-k', $invalid->get_error_message());
    }

    public function testTransportFailureSurfacesAsNetworkException()
    {
        $this->allowUnmockedHttp = true;

        try {
            $this->model()->generateTextResult($this->prompt());
            $this->fail('A transport failure must throw.');
        } catch ( NetworkException $e ) {
            $this->assertRedacted($e->getMessage(), FakeSecrets::apiKey());
        }
    }

    /*
     * The REAL dispatch path: the genuine WP core prompt builder
     * (WP_AI_Client_Prompt_Builder) → the SDK PromptBuilder → the FINAL
     * ZaiTextGenerationModel::generateTextResult() → core's fixed
     * exception_to_wp_error() conversion. The zai generate_text() wrapper is
     * never called on this path; these tests prove what callers of
     * wp_ai_client_prompt(...)->using_provider('zai')->generate_text()
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

        $this->primeZaiDiscoveryTransient();
        update_option(\Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability::KEY_OPTION, FakeSecrets::apiKey());
        \Deicod\WpConnectors\Zai\Plugin::register(AiClient::defaultRegistry());
        AiClient::defaultRegistry()->setProviderRequestAuthentication(
            'zai',
            new ApiKeyRequestAuthentication(FakeSecrets::apiKey())
        );

        $this->queueSdkResponse(200, array(), HttpResponseFactory::openAiModelsBody(array('glm-5.3')));
        $this->assertTrue(ZaiProvider::availability()->isConfigured(), 'Availability must settle before generation.');

        return new $class(AiClient::defaultRegistry(), 'Hello');
    }

    public function testCoreBuilderPathReturnsGeneratedTextOnSuccess()
    {
        $builder = $this->corePromptBuilder();

        $this->queueSdkResponse(200, array('Content-Type' => 'application/json'), wp_json_encode(array(
            'id' => 'chatcmpl-core',
            'choices' => array(array(
                'index' => 0,
                'message' => array('role' => 'assistant', 'content' => 'Via core.'),
                'finish_reason' => 'stop',
            )),
        )));

        $text = $builder->using_provider('zai')->generate_text();

        $this->assertSame('Via core.', $text);
    }

    /**
     * @dataProvider provideCoreBuilderErrorCases
     */
    public function testCoreBuilderPathYieldsCoreCodesWithZaiSafeMessages($status, $expectedCoreCode)
    {
        $builder = $this->corePromptBuilder();

        $secret = FakeSecrets::apiKey();
        $this->queueSdkResponse($status, array(), HttpResponseFactory::openAiErrorBody('upstream echoes Bearer ' . $secret));

        $result = $builder->using_provider('zai')->generate_text();

        $this->assertWPError($result, $expectedCoreCode);
        $data = $result->get_error_data();
        $this->assertSame($status, $data['status'], 'Core must derive the REST status from the exception code.');
        $this->assertArrayHasKey('exception_class', $data, 'Core records the exception class in the error data.');
        $this->assertSame(
            ErrorMapper::safe_http_message($status),
            $result->get_error_message(),
            'The verbatim-passed message must be exactly the zai-safe catalog text.'
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
