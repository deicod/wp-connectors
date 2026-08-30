<?php
/**
 * Z.ai text generation model (OpenAI chat-completions mapping).
 *
 * Request mapping rides on the SDK's AbstractOpenAiCompatibleTextGenerationModel
 * base class; this subclass adds:
 *
 * - request-time endpoint resolution (plan × region, Task 1.3), and
 * - pre-transport rejection of option/model combinations the z.ai catalog
 *   does not advertise (Task 1.6): anything not in the advertised option set
 *   fails BEFORE any HTTP request, with a clear message.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\RedirectException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Support\SseAggregator;

/**
 * Text generation model for z.ai.
 *
 * @since 0.1.0
 */
final class ZaiTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * Output MIME types the z.ai surface supports.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const SUPPORTED_OUTPUT_MIME_TYPES = array( 'text/plain', 'application/json' );

	/**
	 * Builds the request against the CURRENT plan/region endpoint.
	 *
	 * The option read happens here, at request-build time — never at
	 * construction time — so a settings change retargets the very next
	 * request without rebuilding the registry (Task 1.3).
	 *
	 * @since 0.1.0
	 *
	 * @param HttpMethodEnum                     $method  HTTP method.
	 * @param string                             $path    API path relative to the base URL.
	 * @param array<string, string|list<string>> $headers Request headers.
	 * @param string|array<string, mixed>|null   $data    Request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			ZaiEndpoint::for_current_settings()->api_url( $path ),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}

	/**
	 * Prepares the request parameters after rejecting unsupported input.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return array<string, mixed> API request parameters.
	 * @throws InvalidArgumentException When an unsupported option/model combination is used.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$this->validate_request( $prompt );

		return parent::prepareGenerateTextParams( $prompt );
	}

	/**
	 * Throws a SAFE, typed SDK exception when the response is not successful.
	 *
	 * The SDK defaults embed the upstream error body in the exception message;
	 * z.ai error bodies can echo request material (up to and including
	 * credential fragments), so this override replaces the message with a
	 * stable, status-specific one. The exception TYPES are the SDK's own, so
	 * core's exception_to_wp_error() instanceof mapping keeps working.
	 *
	 * No retries in v1: a non-2xx response always throws exactly once.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The HTTP response to check.
	 * @return void
	 * @throws ClientException   For 4xx responses.
	 * @throws ServerException   For 5xx responses.
	 * @throws RedirectException For 3xx responses.
	 */
	protected function throwIfNotSuccessful( Response $response ): void {
		if ( $response->isSuccessful() ) {
			return;
		}

		$status = $response->getStatusCode();

		if ( $status >= 500 ) {
			throw new ServerException(
				sprintf(
					/* translators: %d: HTTP status code. */
					esc_html__( 'The z.ai API reported a server error (%d). This is usually temporary; try again shortly.', 'zai' ),
					absint( $status )
				),
				absint( $status )
			);
		}

		if ( $status >= 400 ) {
			switch ( $status ) {
				case 401:
					$message = __( 'The z.ai API rejected the API key (401). Check the key on the Connectors screen — international and China keys are not interchangeable.', 'zai' );
					break;
				case 403:
					$message = __( 'The z.ai API refused the request (403). The key may not have access to this model or plan.', 'zai' );
					break;
				case 429:
					$message = __( 'The z.ai API is rate limiting this key (429). Wait a moment and try again.', 'zai' );
					break;
				default:
					$message = sprintf(
						/* translators: %d: HTTP status code. */
						esc_html__( 'The z.ai API rejected the request (%d). Check the prompt and model selection.', 'zai' ),
						absint( $status )
					);
			}

			throw new ClientException( esc_html( $message ), absint( $status ) );
		}

		throw new RedirectException(
			sprintf(
				/* translators: %d: HTTP status code. */
				esc_html__( 'The z.ai API returned an unexpected redirect (%d). No request was retried.', 'zai' ),
				absint( $status )
			),
			absint( $status )
		);
	}

	/**
	 * Parses non-streaming AND SSE responses into SDK result objects.
	 *
	 * A `text/event-stream` response (or a body starting with `data:`) is
	 * aggregated first: chunk deltas are merged into one consolidated
	 * chat.completion payload (tool calls, finish reasons, usage included)
	 * and then run through the standard non-streaming parser.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The API response.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException When the payload is malformed.
	 */
	protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-mandated override name.
		$body = (string) $response->getBody();

		$is_event_stream = false !== stripos( (string) $response->getHeaderAsString( 'Content-Type' ), 'text/event-stream' )
			|| 0 === strpos( ltrim( $body ), 'data:' );

		if ( ! $is_event_stream ) {
			return parent::parseResponseToGenerativeAiResult( $response );
		}

		$aggregator = new SseAggregator();
		$aggregator->feed( $body );
		$aggregator->finish();

		$aggregated = $aggregator->aggregated();

		if ( null === $aggregated ) {
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'No usable chat.completion.chunk event was received.'
			);
		}

		$consolidated = new Response(
			$response->getStatusCode(),
			array( 'Content-Type' => array( 'application/json' ) ),
			(string) wp_json_encode( $aggregated )
		);

		return parent::parseResponseToGenerativeAiResult( $consolidated );
	}

	/**
	 * Rejects option/model combinations the z.ai catalog does not advertise.
	 *
	 * Everything rejected here fails BEFORE any transport work, so callers
	 * get an immediate, precise error instead of an upstream 400.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return void
	 * @throws InvalidArgumentException When an unsupported option/model combination is used.
	 */
	private function validate_request( array $prompt ): void {
		$config = $this->getConfig();

		$this->reject_unsupported_options( $config->toArray() );

		// Multiple candidates are not advertised.
		if ( null !== $config->getCandidateCount() ) {
			throw new InvalidArgumentException(
				'The z.ai provider does not support candidateCount (multiple candidates).'
			);
		}

		// Output modalities: text only.
		$output_modalities = $config->getOutputModalities();
		if ( \is_array( $output_modalities ) ) {
			foreach ( $output_modalities as $modality ) {
				if ( ! $modality->isText() ) {
					throw new InvalidArgumentException(
						'The z.ai provider only supports text output modalities.'
					);
				}
			}
		}

		// Structured output only in the two advertised MIME types.
		$output_mime_type = $config->getOutputMimeType();
		if ( null !== $output_mime_type && ! \in_array( $output_mime_type, self::SUPPORTED_OUTPUT_MIME_TYPES, true ) ) {
			throw new InvalidArgumentException(
				'The z.ai provider supports outputMimeType values text/plain and application/json only.'
			);
		}

		// Text-only input: no file (image/audio/document) parts in any message.
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isFile() ) {
					throw new InvalidArgumentException(
						'The z.ai provider only supports text input in v1; file (image/audio/document) message parts are rejected.'
					);
				}
			}
		}

		// Custom options are not advertised; passing them is rejected rather
		// than silently forwarded to the API.
		if ( array() !== $config->getCustomOptions() ) {
			throw new InvalidArgumentException(
				'The z.ai provider does not support custom options.'
			);
		}
	}

	/**
	 * Rejects config keys that are not part of the advertised option set.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $config_as_array The model config as an array.
	 * @return void
	 * @throws InvalidArgumentException When a non-advertised option carries a value.
	 */
	private function reject_unsupported_options( array $config_as_array ): void {
		$unsupported = array(
			'presencePenalty'        => 'presence penalty',
			'frequencyPenalty'       => 'frequency penalty',
			'topK'                   => 'top-k',
			'logprobs'               => 'logprobs',
			'topLogprobs'            => 'top logprobs',
			'webSearch'              => 'web search',
			'outputFileType'         => 'output file types',
			'outputMediaOrientation' => 'output media orientation',
			'outputMediaAspectRatio' => 'output media aspect ratio',
			'outputSpeechVoice'      => 'output speech voice',
		);

		foreach ( $unsupported as $key => $label ) {
			$value = $config_as_array[ $key ] ?? null;

			if ( null !== $value && array() !== $value && false !== $value ) {
				throw new InvalidArgumentException(
					sprintf(
						'The z.ai provider does not support %s.',
						esc_html( $label )
					)
				);
			}
		}
	}
}
