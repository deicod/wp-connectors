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
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;

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
