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
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Support\ErrorMapper;
use Deicod\WpConnectors\Zai\Support\SseAggregator;

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
            $this->assertStringContainsString('z.ai', $e->getMessage());
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
            429 => 'rate limiting',
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
