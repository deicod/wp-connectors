<?php
/**
 * Z.ai text generation model (OpenAI chat-completions mapping).
 *
 * Request mapping rides on the SDK's AbstractOpenAiCompatibleTextGenerationModel
 * base class; this subclass adds:
 *
 * - request-time endpoint resolution (plan × region, Task 1.3),
 * - pre-transport rejection of option/model combinations the z.ai catalog
 *   does not advertise (Task 1.6): anything not in the advertised option set
 *   fails BEFORE any HTTP request, with a clear message, and
 * - SAFE exception messages at every boundary the core prompt builder can
 *   reach: core dispatches to the FINAL generateTextResult() (this class's
 *   generate_text() wrapper is never part of that flow) and converts the
 *   caught exception to WP_Error with a FIXED code map, passing the message
 *   through VERBATIM — no filter exists on that path — so every exception
 *   this class throws is built from ErrorMapper's redacted, actionable
 *   catalog, never from the upstream body.
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
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\RedirectException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Support\ErrorMapper;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;
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
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		if ( ! $http_transporter instanceof LoggingHttpTransporter ) {
			$http_transporter = new LoggingHttpTransporter( $http_transporter );
		}

		parent::setHttpTransporter( $http_transporter );
	}

	/**
	 * WP-facing generation boundary for DIRECT model use: typed WP_Error.
	 *
	 * NOT part of the core prompt flow: wp_ai_client_prompt() dispatches to
	 * the final generateTextResult() and converts exceptions itself (fixed
	 * core codes, messages verbatim — no filter), so this wrapper is never
	 * called there. It exists for code that holds the model directly —
	 * obtained via ProviderRegistry::getProviderModel(), the only factory
	 * that binds the HTTP transporter and request auth (a bare
	 * ZaiProvider::model() yields an unbound model whose generation fails
	 * before any request) — and wants the plugin's typed, redacted zai_*
	 * codes (SPEC §6.2) instead of SDK exceptions: through the core builder
	 * callers get core codes with the same safe messages and correct HTTP
	 * statuses either way.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return GenerativeAiResult|\WP_Error Result on success; typed, redacted WP_Error on any failure.
	 */
	public function generate_text( array $prompt ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP-flavored direct-use generation boundary; see class docblock.
		try {
			return $this->generateTextResult( $prompt );
		} catch ( \Throwable $e ) {
			return ErrorMapper::to_wp_error( $e );
		}
	}

	/**
	 * Prepares the request parameters after refusing refused credentials and
	 * rejecting unsupported input.
	 *
	 * The SDK's generateTextResult() is FINAL, so this params hook is the
	 * earliest pre-transport boundary this surface owns — the credential
	 * gate runs here, before any request build, authentication, or
	 * transport.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return array<string, mixed> API request parameters.
	 * @throws InvalidArgumentException When the credential gate refuses the
	 *                                  active key, or an unsupported
	 *                                  option/model combination is used.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$this->refuse_refused_credentials();

		$this->validate_request( $prompt );

		return parent::prepareGenerateTextParams( $prompt );
	}

	/**
	 * Refuses generation for credentials the availability layer distrusts.
	 *
	 * Code review GLM1 #1: the R19/R20 credential-refusal gate was consulted
	 * only by the zai_anthropic surface, so this (OpenAI) surface still
	 * authenticated a region-pending or definitively-rejected env/constant
	 * key after a region switch — sending the old region's credential to the
	 * newly selected endpoint (cross-region disclosure) while the connector
	 * reported disconnected. The gate REUSES the availability layer's own
	 * state readers (no duplicated logic, no probe request) with the model's
	 * exact credential, exactly like the zai_anthropic surface's
	 * generateTextResult() gate.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 * @throws InvalidArgumentException When the credential is region-pending
	 *                                  or carries a fresh invalid verdict
	 *                                  for the selected endpoint.
	 */
	private function refuse_refused_credentials(): void {
		$authentication = $this->getRequestAuthentication();

		if ( ! $authentication instanceof ApiKeyRequestAuthentication ) {
			return;
		}

		$refusal = ( new ZaiProviderAvailability() )->generation_refusal_reason( $authentication );

		if ( null !== $refusal ) {
			throw new InvalidArgumentException(
				'region_pending' === $refusal
					? 'The zai provider refuses generation: the active environment credential is pending revalidation after a region switch.'
					: 'The zai provider refuses generation: the active credential was rejected for the selected endpoint.'
			);
		}
	}

	/**
	 * Throws a SAFE, typed SDK exception when the response is not successful.
	 *
	 * The SDK defaults embed the upstream error body in the exception message;
	 * z.ai error bodies can echo request material (up to and including
	 * credential fragments). This override builds the message from
	 * ErrorMapper's shared catalog instead, because this exception travels
	 * the real dispatch path: core's prompt builder converts it to WP_Error
	 * passing the message through VERBATIM (no filter on that path), so the
	 * redaction must already be complete here. The exception TYPES are the
	 * SDK's own, so core's fixed instanceof mapping keeps producing the
	 * right code and HTTP status.
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

		$status = absint( $response->getStatusCode() );

		if ( $status >= 500 ) {
			throw new ServerException( esc_html( ErrorMapper::safe_http_message( $status ) ), absint( $status ) );
		}

		if ( $status >= 400 ) {
			throw new ClientException( esc_html( ErrorMapper::safe_http_message( $status ) ), absint( $status ) );
		}

		throw new RedirectException( esc_html( ErrorMapper::safe_http_message( $status ) ), absint( $status ) );
	}

	/**
	 * Parses non-streaming AND SSE responses into SDK result objects.
	 *
	 * A `text/event-stream` response (or a body starting with `data:`) is
	 * aggregated first: chunk deltas are merged into one consolidated
	 * chat.completion payload (tool calls, finish reasons, usage included)
	 * and then run through the standard non-streaming parser.
	 *
	 * SDK parse failures are re-thrown with a FIXED message: the SDK's
	 * ResponseException messages can embed upstream response fields verbatim
	 * (e.g. an unexpected finish_reason), which must never reach error
	 * surfaces.
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
			return $this->parseNonStreamBody( $response );
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

		return $this->parseNonStreamBody( $consolidated );
	}

	/**
	 * Runs the SDK's non-streaming parser with sanitized failure messages.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The chat.completion response.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException With a fixed message when the payload is malformed.
	 */
	private function parseNonStreamBody( Response $response ): GenerativeAiResult {
		try {
			return parent::parseResponseToGenerativeAiResult( $response );
		} catch ( ResponseException $e ) {
			// The SDK message may embed upstream body content (e.g. a
			// non-standard finish_reason); replace it with a fixed string.
			throw ResponseException::fromInvalidData(
				'z.ai',
				'response',
				'The chat-completions payload was malformed.'
			);
		}
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
