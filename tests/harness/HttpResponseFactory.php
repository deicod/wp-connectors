<?php
/**
 * HTTP response builders for offline tests.
 *
 * WP-shape builders feed mockHttpResponse() (wp_remote_* mocks); PSR-7
 * builders feed queueSdkResponse() (SDK transport mocks). Response bodies
 * are constructed from fixture data only — never paste captured payloads
 * containing credentials.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Nyholm\Psr7\Response;

final class HttpResponseFactory
{
    /**
     * WP-shaped response array for the pre_http_request mock.
     *
     * @param int    $status HTTP status.
     * @param string $body   Response body.
     * @param array  $headers Response headers.
     * @return array<string, mixed>
     */
    public static function wp($status, $body = '', array $headers = array())
    {
        return array(
            'response' => array( 'code' => (int) $status, 'message' => '' ),
            'body' => (string) $body,
            'headers' => $headers,
        );
    }

    /**
     * PSR-7 response for queueSdkResponse().
     *
     * @param int    $status HTTP status.
     * @param string $body Response body.
     * @param array  $headers Response headers.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public static function psr7($status, $body = '', array $headers = array())
    {
        return new Response((int) $status, $headers, (string) $body);
    }

    /**
     * OpenAI-shape /models success body (matches the verified z.ai evidence,
     * architecture record 0006).
     *
     * @param list<string> $modelIds Model IDs.
     * @param int          $created Optional created timestamp for all models.
     * @return string JSON body.
     */
    public static function openAiModelsBody(array $modelIds, $created = 1753632000)
    {
        $models = array();
        foreach ($modelIds as $modelId) {
            $models[] = array( 'id' => $modelId, 'object' => 'model', 'created' => $created, 'owned_by' => 'z-ai' );
        }

        return (string) wp_json_encode(array( 'object' => 'list', 'data' => $models ));
    }

    /**
     * OpenAI-shape chat completion body.
     *
     * @param string $text Assistant text.
     * @param string $model Model ID echoed back.
     * @return string JSON body.
     */
    public static function openAiChatCompletionBody($text, $model = 'fixture-model')
    {
        return (string) wp_json_encode(array(
            'id' => 'chatcmpl-fixture',
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => array(
                array(
                    'index' => 0,
                    'message' => array( 'role' => 'assistant', 'content' => $text ),
                    'finish_reason' => 'stop',
                ),
            ),
            'usage' => array( 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15 ),
        ));
    }

    /**
     * OpenAI-shape error body.
     *
     * @param string $message Error message (fixture text only).
     * @param string $type Error type.
     * @return string JSON body.
     */
    public static function openAiErrorBody($message, $type = 'invalid_request_error')
    {
        return (string) wp_json_encode(array( 'error' => array( 'message' => $message, 'type' => $type, 'code' => null ) ));
    }

    /**
     * Anthropic-shape /v1/models success body.
     *
     * @param list<string> $modelIds Model IDs.
     * @return string JSON body.
     */
    public static function anthropicModelsBody(array $modelIds)
    {
        $models = array();
        $previous = null;
        foreach ($modelIds as $modelId) {
            $models[] = array(
                'id' => $modelId,
                'type' => 'model',
                'display_name' => strtoupper(str_replace('-', ' ', $modelId)),
                'created_at' => '2026-01-01T00:00:00Z',
            );
            $previous = $modelId;
        }

        return (string) wp_json_encode(array(
            'data' => $models,
            'first_id' => $modelIds !== array() ? $modelIds[0] : null,
            'has_more' => false,
            'last_id' => $previous,
        ));
    }

    /**
     * Anthropic-shape /v1/messages success body.
     *
     * @param string                          $text Assistant text.
     * @param list<array<string, mixed>>|null $content Optional full content block list.
     * @param string                          $stopReason Stop reason.
     * @return string JSON body.
     */
    public static function anthropicMessagesBody($text, ?array $content = null, $stopReason = 'end_turn')
    {
        return (string) wp_json_encode(array(
            'id' => 'msg_fixture',
            'type' => 'message',
            'role' => 'assistant',
            'content' => $content !== null ? $content : array(array( 'type' => 'text', 'text' => $text )),
            'model' => 'glm-5.3',
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => array( 'input_tokens' => 10, 'output_tokens' => 5 ),
        ));
    }

    /**
     * Anthropic-shape error body.
     *
     * @param string $message Error message (fixture text only).
     * @param string $type Error type.
     * @return string JSON body.
     */
    public static function anthropicErrorBody($message, $type = 'invalid_request_error')
    {
        return (string) wp_json_encode(array( 'type' => 'error', 'error' => array( 'type' => $type, 'message' => $message ) ));
    }

    /**
     * OAuth error body (RFC 6749 section 5.2).
     *
     * @param string $error Error code, e.g. "invalid_grant".
     * @param string $description Optional human-readable description.
     * @return string JSON body.
     */
    public static function oauthErrorBody($error, $description = '')
    {
        $payload = array( 'error' => $error );
        if ('' !== $description) {
            $payload['error_description'] = $description;
        }

        return (string) wp_json_encode($payload);
    }

    /**
     * z.ai status-envelope body — the failure framing the API carries
     * INSIDE HTTP 200 responses on routes that answer 200 regardless of
     * authentication (live capture 2026-09-04: the Anthropic /v1/models
     * route returns {"code":401,"msg":"token expired or incorrect",
     * "success":false} with HTTP 200 for any or no credential).
     *
     * @param int    $code Vendor status code (e.g. 401 for the credential
     *                     rejection, 1113 for a balance/plan failure).
     * @param string $msg  Fixture-only message text.
     * @return string JSON body.
     */
    public static function zaiStatusEnvelopeBody($code, $msg)
    {
        return (string) wp_json_encode(array( 'code' => (int) $code, 'msg' => $msg, 'success' => false ));
    }

    /*
     * Anthropic SSE stream builders (glm34-10): the envelope framing
     * (event:/data: line pair, the blank-line separator) and the
     * canonical lifecycle boilerplate were hand-spelled ~330 times
     * across the streamed fixtures — every envelope convention change
     * meant re-editing hundreds of inline strings, and a one-copy typo
     * silently produced a fixture different from what neighboring
     * tests purport to test. These builders emit BYTE-IDENTICAL frames
     * to the hand-spelled canonical forms (member order included);
     * deliberately-malformed fixtures (cut lines, split declarations,
     * garbled payloads) stay hand-spelled — their malformation IS the
     * fixture.
     */

    /**
     * The canonical message_start frame.
     *
     * @param string $messageId Stream message id.
     * @param int    $inputTokens Start-side input usage.
     * @param int    $outputTokens Start-side output usage.
     * @return string Frame bytes.
     */
    public static function anthropicStreamStart($messageId, $inputTokens = 1, $outputTokens = 1)
    {
        return 'event: message_start' . "\n"
            . 'data: ' . wp_json_encode(array(
                'type' => 'message_start',
                'message' => array(
                    'id' => $messageId,
                    'content' => array(),
                    'usage' => array( 'input_tokens' => (int) $inputTokens, 'output_tokens' => (int) $outputTokens ),
                ),
            )) . "\n\n";
    }

    /**
     * The canonical content_block_stop frame.
     *
     * @param int $index Block index.
     * @return string Frame bytes.
     */
    public static function anthropicBlockStop($index)
    {
        return 'event: content_block_stop' . "\n"
            . 'data: ' . wp_json_encode(array( 'type' => 'content_block_stop', 'index' => (int) $index )) . "\n\n";
    }

    /**
     * The canonical message_delta + message_stop tail that ends a stream.
     *
     * @param string $stopReason Final stop reason.
     * @param int    $outputTokens Delta-side output usage.
     * @return string Frame bytes.
     */
    public static function anthropicStreamEnd($stopReason = 'end_turn', $outputTokens = 2)
    {
        return 'event: message_delta' . "\n"
            . 'data: ' . wp_json_encode(array(
                'type' => 'message_delta',
                'delta' => array( 'stop_reason' => $stopReason ),
                'usage' => array( 'output_tokens' => (int) $outputTokens ),
            )) . "\n\n"
            . 'event: message_stop' . "\n"
            . 'data: {"type":"message_stop"}' . "\n\n";
    }
}
