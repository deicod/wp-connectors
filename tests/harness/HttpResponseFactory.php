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
     * @param string                                            $text Assistant text.
     * @param list<array<string, mixed>>|null                   $content Optional full content block list.
     * @param string                                            $stopReason Stop reason.
     * @return string JSON body.
     */
    public static function anthropicMessagesBody($text, array $content = null, $stopReason = 'end_turn')
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
}
