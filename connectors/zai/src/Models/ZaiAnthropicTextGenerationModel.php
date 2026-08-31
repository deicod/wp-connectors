<?php
/**
 * Z.ai text generation model (Anthropic Messages mapping).
 *
 * Maps the SDK's prompt/config surface onto the Anthropic-compatible
 * Messages API (`{base}/v1/messages`), mirroring the official
 * ai-provider-for-anthropic reference plugin with z.ai specifics:
 *
 * - request-time endpoint resolution (plan × region, Tasks 2.2/2.5),
 * - `max_tokens` is REQUIRED by the Messages protocol: a sensible default
 *   is applied when the caller omits it (Task 2.5),
 * - pre-transport rejection of option/model combinations the zai_anthropic
 *   catalog does not advertise, plus Messages role-order violations
 *   (Task 2.5),
 * - Bearer + anthropic-version headers via the wrapped authentication
 *   (Task 2.3), and
 * - SAFE exception messages at every boundary the core prompt builder can
 *   reach: core dispatches to generateTextResult() and converts the caught
 *   exception to WP_Error with a FIXED code map, passing the message through
 *   VERBATIM — no filter exists on that path — so every exception this class
 *   throws is built from the shared ErrorMapper catalog, never from the
 *   upstream body.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\RedirectException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use Deicod\WpConnectors\Zai\Authentication\ZaiAnthropicRequestAuthentication;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Support\AnthropicSseAggregator;
use Deicod\WpConnectors\Zai\Support\ErrorMapper;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

/**
 * Text generation model for zai_anthropic.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * Default maximum number of tokens for one generation.
	 *
	 * The Messages protocol requires `max_tokens` on every request, so this
	 * value is applied when the caller's configuration omits it (same
	 * default as the official Anthropic provider plugin).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_TOKENS = 4096;

	/**
	 * Output MIME types the z.ai Anthropic surface supports.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const SUPPORTED_OUTPUT_MIME_TYPES = array( 'text/plain', 'application/json' );

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.2.0
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
	 * Returns the wired authentication, protocol-wrapped for this surface.
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	public function getRequestAuthentication(): RequestAuthenticationInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		return ZaiAnthropicRequestAuthentication::wrap( parent::getRequestAuthentication() );
	}

	/**
	 * WP-facing generation boundary for DIRECT model use: typed WP_Error.
	 *
	 * NOT part of the core prompt flow: wp_ai_client_prompt() dispatches to
	 * generateTextResult() and converts exceptions itself (fixed core codes,
	 * messages verbatim — no filter), so this wrapper is never called there.
	 * It exists for code that holds the model directly — obtained via
	 * ProviderRegistry::getProviderModel(), the only factory that binds the
	 * HTTP transporter and request auth — and wants the plugin's typed,
	 * redacted zai_* codes (SPEC §6.2) instead of SDK exceptions.
	 *
	 * @since 0.2.0
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
	 * Generates a result from the given prompt via the Messages API.
	 *
	 * The URL is resolved from the settings at REQUEST-build time, so a
	 * plan/region change retargets the very next request without rebuilding
	 * the registry.
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return GenerativeAiResult The generation result.
	 * @throws \Exception On transport, HTTP, or parsing failures (typed, safe messages).
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$params = $this->prepareGenerateTextParams( $prompt );

		$request = new Request(
			HttpMethodEnum::POST(),
			ZaiAnthropicEndpoint::for_current_settings()->messages_url(),
			array( 'Content-Type' => 'application/json' ),
			$params,
			$this->getRequestOptions()
		);

		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		$response = $this->getHttpTransporter()->send( $request );

		$this->throwIfNotSuccessful( $response );

		return $this->parseResponseToGenerativeAiResult( $response );
	}

	/**
	 * Prepares the Messages API request parameters after rejecting unsupported input.
	 *
	 * Structured output travels as JSON GUIDANCE in the system prompt, not as
	 * a native output_format parameter: z.ai's support for Anthropic's
	 * structured-outputs beta (anthropic-beta header + output_format) is
	 * unverified, while instruction-guided JSON works on every
	 * Messages-compatible endpoint (documented as the v1 behavior).
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return array<string, mixed> API request parameters.
	 * @throws InvalidArgumentException When an unsupported option/model combination or role order is used.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$this->validate_request( $prompt );

		$config = $this->getConfig();

		$params = array(
			'model'      => $this->metadata()->getId(),
			'max_tokens' => $config->getMaxTokens() ?? self::DEFAULT_MAX_TOKENS,
			'messages'   => $this->prepare_messages_param( $prompt ),
		);

		$system_instruction = $config->getSystemInstruction();
		$json_guidance      = $this->json_output_guidance();
		if ( '' !== $json_guidance ) {
			$system_instruction = \is_string( $system_instruction ) && '' !== $system_instruction
				? $system_instruction . "\n\n" . $json_guidance
				: $json_guidance;
		}
		if ( \is_string( $system_instruction ) && '' !== $system_instruction ) {
			$params['system'] = $system_instruction;
		}

		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			$params['temperature'] = $temperature;
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			$params['top_p'] = $top_p;
		}

		$stop_sequences = $config->getStopSequences();
		if ( \is_array( $stop_sequences ) ) {
			$params['stop_sequences'] = $stop_sequences;
		}

		$function_declarations = $config->getFunctionDeclarations();
		if ( \is_array( $function_declarations ) ) {
			$params['tools'] = $this->prepare_tools_param( $function_declarations );
		}

		return $params;
	}

	/**
	 * Builds the JSON-output guidance for the system prompt, or '' when
	 * neither JSON output signal was given.
	 *
	 * The outputSchema option is advertised independently of
	 * outputMimeType, so EITHER signal requests guidance: a schema without
	 * the MIME option must not be silently discarded into an unconstrained
	 * request (Codex R1 finding 4).
	 *
	 * @since 0.2.0
	 *
	 * @return string Guidance text (possibly embedding the outputSchema).
	 */
	private function json_output_guidance(): string {
		$config = $this->getConfig();

		$output_schema = $config->getOutputSchema();
		$json_mime     = 'application/json' === $config->getOutputMimeType();

		if ( ! $json_mime && ! \is_array( $output_schema ) ) {
			return '';
		}

		$guidance = __( 'Respond with a single JSON value only — no markdown fences, no commentary, no surrounding text.', 'zai' );

		if ( \is_array( $output_schema ) ) {
			$guidance .= "\n" . sprintf(
				/* translators: %s: a JSON Schema document (compact JSON). */
				__( 'The JSON value must conform to this JSON Schema: %s', 'zai' ),
				(string) wp_json_encode( $output_schema )
			);
		}

		return $guidance;
	}

	/**
	 * Prepares the tools parameter for the API request.
	 *
	 * @since 0.2.0
	 *
	 * @param array $function_declarations Declared functions (list of FunctionDeclaration).
	 * @return array The prepared tools parameter (list of tool objects).
	 */
	protected function prepare_tools_param( array $function_declarations ): array {
		$tools = array();

		foreach ( $function_declarations as $declaration ) {
			// The Messages protocol requires input_schema on every tool — an
			// OBJECT — even for functions without parameters. Both the
			// absent schema (null) and an EMPTY array schema () normalize
			// to the empty-object schema; a raw empty array would
			// JSON-encode as [] and fail upstream validation (Codex R1
			// finding 5).
			$input_schema = $declaration->getParameters();
			if ( null === $input_schema || array() === $input_schema ) {
				$input_schema = array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				);
			}

			$tools[] = array(
				'name'         => $declaration->getName(),
				'description'  => $declaration->getDescription(),
				'input_schema' => $input_schema,
			);
		}

		return $tools;
	}

	/**
	 * Prepares the messages parameter for the API request.
	 *
	 * Adjacent turns of the SAME role are coalesced into one message (their
	 * content blocks merged in order) — the Messages protocol's own
	 * combining rule for this shape, which generic chat histories
	 * legitimately contain (Codex R1 finding 2); coalescing here means the
	 * request stays valid without rejecting such histories.
	 *
	 * @since 0.2.0
	 *
	 * @param array $messages Messages to prepare (list of Message).
	 * @return array The prepared messages parameter (list of content messages).
	 */
	protected function prepare_messages_param( array $messages ): array {
		$prepared = array();

		foreach ( $messages as $message ) {
			$role   = $this->message_role_string( $message->getRole() );
			$blocks = $this->message_content_blocks( $message );

			$last = \count( $prepared ) - 1;
			if ( $last >= 0 && $prepared[ $last ]['role'] === $role ) {
				$prepared[ $last ]['content'] = array_merge( $prepared[ $last ]['content'], $blocks );
				continue;
			}

			$prepared[] = array(
				'role'    => $role,
				'content' => $blocks,
			);
		}

		return $prepared;
	}

	/**
	 * Returns the Messages API role string for a message role.
	 *
	 * @since 0.2.0
	 *
	 * @param MessageRoleEnum $role The message role.
	 * @return string 'user' or 'assistant'.
	 */
	protected function message_role_string( MessageRoleEnum $role ): string {
		return $role->isModel() ? 'assistant' : 'user';
	}

	/**
	 * Builds the content blocks for one message.
	 *
	 * Thought-channel text parts are deliberately DROPPED: replaying model
	 * reasoning through `thinking` blocks is only valid on real Anthropic
	 * with signature blocks, and z.ai behavior is unverified — the blocks
	 * carry no user intent, so nothing is lost. A message whose parts ALL
	 * drop (or that has no parts) has no translatable content left: the
	 * Messages protocol rejects empty text blocks, so degrading to one
	 * would only move the failure upstream — the request is rejected here,
	 * before any transport work, with a precise message instead (review
	 * finding).
	 *
	 * Tool parts are validated against their message's role (Codex R5 #1):
	 * the Messages protocol requires tool_use blocks in ASSISTANT turns
	 * and tool_result blocks in USER turns, so a FunctionCall in a user
	 * message or a FunctionResponse in an assistant message is rejected
	 * before transport instead of failing upstream with a 400.
	 *
	 * @since 0.2.0
	 *
	 * @param Message $message The message.
	 * @return list<array<string, mixed>> Content blocks.
	 * @throws InvalidArgumentException When the message has no translatable
	 *                                   parts or a tool part sits in an
	 *                                   incompatible role.
	 */
	protected function message_content_blocks( Message $message ): array {
		$is_assistant = $message->getRole()->isModel();
		$blocks       = array();

		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isFunctionCall() && ! $is_assistant ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires function-call (tool_use) parts to sit in assistant messages.'
				);
			}

			if ( $part->getType()->isFunctionResponse() && $is_assistant ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires function-response (tool_result) parts to sit in user messages.'
				);
			}

			$block = $this->message_part_block( $part );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}

		if ( array() === $blocks ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider requires every message to carry at least one translatable (text, tool call, or tool result) part.'
			);
		}

		return $blocks;
	}

	/**
	 * Returns the content block for one message part, or null to drop it.
	 *
	 * @since 0.2.0
	 *
	 * @param MessagePart $part The message part.
	 * @return array<string, mixed>|null The block, or null when dropped.
	 * @throws InvalidArgumentException When a tool part carries no identity.
	 */
	protected function message_part_block( MessagePart $part ): ?array {
		if ( $part->getType()->isText() ) {
			if ( $part->getChannel()->isThought() ) {
				return null;
			}

			/*
			 * Empty text parts are DROPPED (Codex R4 #2): the Messages
			 * protocol rejects empty text blocks with a 400, and a message
			 * whose only visible part is empty would otherwise pass the
			 * later no-content check (a block exists) and fail upstream.
			 * Dropping here lets that check reject such messages before
			 * transport.
			 */
			$text = (string) $part->getText();
			if ( '' === $text ) {
				return null;
			}

			return array(
				'type' => 'text',
				'text' => $text,
			);
		}

		if ( $part->getType()->isFunctionCall() ) {
			$function_call = $part->getFunctionCall();
			if ( null === $function_call || null === $function_call->getId() || null === $function_call->getName() ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires every function-call part to carry an id and a name.'
				);
			}

			/*
			 * The Messages protocol requires an OBJECT for tool_use input.
			 * Empty/absent args become an empty object explicitly
			 * (official-plugin normalization; PHP's empty array would
			 * encode as []). Any OTHER shape that would encode as a JSON
			 * list or scalar — a non-empty sequential array like
			 * array('Oslo'), or a scalar — is rejected BEFORE transport
			 * (Codex R4 #4): upstream would answer a 400, and silently
			 * re-shaping would silently alter the call's arguments. The
			 * list test is exact: json_encode emits an array only for
			 * 0-based sequential keys, so mixed/string-keyed arrays still
			 * pass as objects.
			 */
			$input = $function_call->getArgs();

			if ( \is_array( $input ) && array() !== $input
				&& \array_keys( $input ) === \range( 0, \count( $input ) - 1 ) ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires tool arguments to be a JSON object (a non-empty list was given).'
				);
			}

			if ( null === $input || ( \is_array( $input ) && array() === $input ) ) {
				$input = new \stdClass();
			}

			return array(
				'type'  => 'tool_use',
				'id'    => $function_call->getId(),
				'name'  => $function_call->getName(),
				'input' => $input,
			);
		}

		if ( $part->getType()->isFunctionResponse() ) {
			$function_response = $part->getFunctionResponse();
			if ( null === $function_response || null === $function_response->getId() ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires every function-response part to carry the tool_use id it answers.'
				);
			}

			return array(
				'type'        => 'tool_result',
				'tool_use_id' => $function_response->getId(),
				'content'     => (string) wp_json_encode( $function_response->getResponse() ),
			);
		}

		// File parts (images, documents, audio) were already rejected by
		// validate_request() — no GLM model on this surface has verified
		// image/file support yet (record 0006 note 4).
		return null;
	}

	/**
	 * Throws a SAFE, typed SDK exception when the response is not successful.
	 *
	 * The SDK defaults embed the upstream error body in the exception message;
	 * z.ai error bodies can echo request material (up to and including
	 * credential fragments). This override builds the message from the shared
	 * ErrorMapper catalog instead, because this exception travels the real
	 * dispatch path: core's prompt builder converts it to WP_Error passing
	 * the message through VERBATIM (no filter on that path), so the redaction
	 * must already be complete here. The exception TYPES are the SDK's own,
	 * so core's fixed instanceof mapping keeps producing the right code and
	 * HTTP status.
	 *
	 * No retries in v1: a non-2xx response always throws exactly once.
	 *
	 * @since 0.2.0
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
	 * Parses a Messages API response into an SDK result object.
	 *
	 * A `text/event-stream` response (or a body starting with `event:` /
	 * `data:`) is aggregated first: Anthropic SSE events — message_start,
	 * content_block_start/delta/stop, message_delta, message_stop, ping —
	 * are merged into ONE consolidated Messages payload and run through the
	 * standard non-streaming parser. Every failure message is a FIXED
	 * string: upstream field values (stop reasons, content block types,
	 * error events) are never interpolated, because ResponseException
	 * messages travel verbatim into core's WP_Error conversion.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The API response.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException When the payload is malformed (directly, or
	 *                           via the helpers for stop reasons and content
	 *                           blocks; the token-limit case throws
	 *                           TokenLimitReachedException instead).
	 */
	protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		$body = (string) $response->getBody();

		$is_event_stream = false !== stripos( (string) $response->getHeaderAsString( 'Content-Type' ), 'text/event-stream' )
			|| 0 === strpos( ltrim( $body ), 'event:' )
			|| 0 === strpos( ltrim( $body ), 'data:' );

		if ( ! $is_event_stream ) {
			return $this->parse_message_body( $response );
		}

		$aggregator = new AnthropicSseAggregator();
		$aggregator->feed( $body );
		$aggregator->finish();

		if ( $aggregator->has_error() ) {
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'The message stream contained an error event.'
			);
		}

		if ( $aggregator->has_malformed_event() ) {
			// A frame declaring a known event name with an undecodable
			// payload (Codex R4 #3): completing would silently return the
			// answer with that event's content missing.
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'The message stream contained a malformed event frame.'
			);
		}

		$aggregated = $aggregator->aggregated();

		if ( $aggregator->has_malformed_tool_input() ) {
			// Truncated/corrupt streamed tool arguments: substituting {}
			// would fabricate a tool call whose inputs the model never
			// produced (Codex R1 finding 1), so the response fails as a
			// parse error with a fixed message instead.
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'A tool_use block in the message stream carried malformed input JSON.'
			);
		}

		if ( null === $aggregated ) {
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'No usable message event was received.'
			);
		}

		$consolidated = new Response(
			200,
			array( 'Content-Type' => array( 'application/json' ) ),
			(string) wp_json_encode( $aggregated )
		);

		return $this->parse_message_body( $consolidated );
	}

	/**
	 * Runs the non-streaming Messages parser over a JSON response.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The Messages response.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException When the payload is malformed.
	 */
	private function parse_message_body( Response $response ): GenerativeAiResult {
		$data = $response->getData();

		if ( ! \is_array( $data ) || ! isset( $data['content'] ) || ! \is_array( $data['content'] ) ) {
			throw ResponseException::fromMissingData( 'z.ai', 'content' );
		}

		/*
		 * Object-ness oracle (Codex R3 #1): getData() decodes the body
		 * ASSOCIATIVELY, which collapses the JSON object {} and the JSON
		 * list [] into the same empty PHP array. A parallel non-
		 * associative decode of the same body preserves the distinction
		 * ({} is stdClass, [] is an array) and is handed to each content
		 * block alongside the associative value.
		 */
		$raw            = json_decode( (string) $response->getBody() );
		$raw_content_ok = \is_object( $raw ) && isset( $raw->content ) && \is_array( $raw->content );

		$role = isset( $data['role'] ) && 'user' === $data['role']
			? MessageRoleEnum::user()
			: MessageRoleEnum::model();

		$parts = array();
		foreach ( $data['content'] as $index => $part_data ) {
			if ( ! \is_array( $part_data ) ) {
				throw ResponseException::fromInvalidData( 'z.ai', 'content', 'Every content entry must be an object.' );
			}

			$raw_part = $raw_content_ok && isset( $raw->content[ $index ] ) && \is_object( $raw->content[ $index ] )
				? $raw->content[ $index ]
				: null;

			$part = $this->parse_content_block( $part_data, $raw_part );
			if ( null !== $part ) {
				$parts[] = $part;
			}
		}

		if ( ! isset( $data['stop_reason'] ) ) {
			throw ResponseException::fromMissingData( 'z.ai', 'stop_reason' );
		}

		$finish_reason = $this->finish_reason_for( (string) $data['stop_reason'] );

		$candidates = array(
			new Candidate(
				new Message( $role, $parts ),
				$finish_reason
			),
		);

		$id = isset( $data['id'] ) && \is_string( $data['id'] ) ? $data['id'] : '';

		$usage_data = isset( $data['usage'] ) && \is_array( $data['usage'] ) ? $data['usage'] : array();
		$input      = (int) ( $usage_data['input_tokens'] ?? 0 )
			+ (int) ( $usage_data['cache_creation_input_tokens'] ?? 0 )
			+ (int) ( $usage_data['cache_read_input_tokens'] ?? 0 );
		$output     = (int) ( $usage_data['output_tokens'] ?? 0 );
		$usage      = new TokenUsage( $input, $output, $input + $output );

		$additional = $data;
		unset( $additional['id'], $additional['role'], $additional['content'], $additional['stop_reason'], $additional['usage'] );

		return new GenerativeAiResult(
			$id,
			$candidates,
			$usage,
			$this->providerMetadata(),
			$this->metadata(),
			$additional
		);
	}

	/**
	 * Parses one response content block into a message part (null to ignore).
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $part_data The content block (associative decode).
	 * @param \stdClass|null       $raw_part  The same block from a NON-
	 *                                        associative decode — the
	 *                                        object-ness oracle for tool
	 *                                         inputs (Codex R3 #1), null
	 *                                        when unavailable.
	 * @return MessagePart|null The part, or null for ignorable block types.
	 * @throws ResponseException When a known block type has a malformed shape.
	 */
	private function parse_content_block( array $part_data, ?\stdClass $raw_part ): ?MessagePart {
		$type = $part_data['type'] ?? null;

		switch ( $type ) {
			case 'text':
				if ( ! isset( $part_data['text'] ) || ! \is_string( $part_data['text'] ) ) {
					throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A text block is missing its text member.' );
				}

				return new MessagePart( $part_data['text'] );

			case 'thinking':
				if ( ! isset( $part_data['thinking'] ) || ! \is_string( $part_data['thinking'] ) ) {
					throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A thinking block is missing its thinking member.' );
				}

				return new MessagePart( $part_data['thinking'], MessagePartChannelEnum::thought() );

			case 'tool_use':
				foreach ( array( 'id', 'name' ) as $member ) {
					if ( ! isset( $part_data[ $member ] ) || ! \is_string( $part_data[ $member ] ) ) {
						throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A tool_use block is missing its identity members.' );
					}
				}

				/*
				 * Object-ness validation (Codex R2 #1 / R3 #1), mirroring
				 * the R1 SSE fix: tool arguments must be a JSON OBJECT. The
				 * RAW (non-associative) decode decides the shape exactly —
				 * {} and missing/null are legitimate no-argument calls;
				 * scalars, booleans, and JSON lists (including the empty
				 * list []) fail as a typed parse error, because passing
				 * them through would hand a consumer fabricated or invalid
				 * arguments for a possibly side-effecting tool. Without a
				 * raw block (defensive: should not happen) the strictest
				 * associative fallback (is_object_shape) rejects the
				 * ambiguous empty array with the empty list.
				 */
				if ( null === $raw_part ) {
					/*
					 * No raw oracle (defensive: should not happen): the
					 * strictest associative probe decides — it also rejects
					 * the ambiguous empty array ({} and [] are
					 * indistinguishable here, so both fail).
					 */
					$args = isset( $part_data['input'] ) ? $part_data['input'] : null;

					if ( null !== $args && ! self::is_object_shape( $args ) ) {
						throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A tool_use block carried a non-object input value.' );
					}

					if ( \is_array( $args ) && array() === $args ) {
						$args = null;
					}
				} else {
					$raw_input = \property_exists( $raw_part, 'input' ) ? $raw_part->input : null;

					if ( null === $raw_input ) {
						// Missing input member or explicit null: no-argument call.
						$args = null;
					} elseif ( \is_object( $raw_input ) ) {
						$args = isset( $part_data['input'] ) && \is_array( $part_data['input'] )
							? $part_data['input']
							: array();

						if ( array() === $args ) {
							// The empty object {} means "no arguments"
							// (official-plugin normalization).
							$args = null;
						}
					} else {
						// A scalar, boolean, or JSON list value (an empty
						// list included).
						throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A tool_use block carried a non-object input value.' );
					}
				}

				return new MessagePart( new FunctionCall( $part_data['id'], $part_data['name'], $args ) );

			case 'redacted_thinking':
			case 'server_tool_use':
			case 'web_search_tool_result':
				// Provider-internal blocks carry no SDK representation.
				return null;
		}

		throw ResponseException::fromInvalidData( 'z.ai', 'content', 'The message contained a block of an unsupported type.' );
	}

	/**
	 * Whether a decoded JSON value has an OBJECT shape (Codex R2 #1, R3 #1).
	 *
	 * The response body is decoded associatively, so JSON objects and JSON
	 * lists both arrive as PHP arrays — and the EMPTY object {} and the
	 * empty list [] collapse to the same empty array, which key inspection
	 * cannot tell apart. Re-encoding restores the distinction: only JSON
	 * objects encode with a leading brace. Lists (including []), scalars,
	 * booleans, and null all fail the probe. The pathological
	 * numeric-keyed JSON object ({"0":…}) is indistinguishable from a list
	 * after an associative decode and is rejected with it.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Decoded input member of a tool_use block.
	 * @return bool True only for object-shaped values.
	 */
	private static function is_object_shape( $value ): bool {
		if ( ! \is_array( $value ) ) {
			return false;
		}

		$encoded = wp_json_encode( $value );

		return \is_string( $encoded ) && '{' === substr( $encoded, 0, 1 );
	}

	/**
	 * Maps a Messages stop reason to the SDK finish reason.
	 *
	 * @since 0.2.0
	 *
	 * @param string $stop_reason The upstream stop reason.
	 * @return FinishReasonEnum The SDK finish reason.
	 * @throws ResponseException          For unknown stop reasons (fixed message).
	 * @throws TokenLimitReachedException When generation stopped at the token limit.
	 */
	private function finish_reason_for( string $stop_reason ): FinishReasonEnum {
		switch ( $stop_reason ) {
			case 'end_turn':
			case 'stop_sequence':
			case 'pause_turn':
				return FinishReasonEnum::stop();

			case 'tool_use':
				return FinishReasonEnum::toolCalls();

			case 'refusal':
				return FinishReasonEnum::contentFilter();

			case 'max_tokens':
			case 'model_context_window_exceeded':
				$max_tokens = absint( $this->getConfig()->getMaxTokens() ?? self::DEFAULT_MAX_TOKENS );

				throw new TokenLimitReachedException(
					sprintf(
						/* translators: %d: the configured token limit. */
						esc_html__( 'The generation stopped because the token limit was reached (%d). Raise maxTokens to continue longer answers.', 'zai' ),
						$max_tokens // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer from absint(), formatted via %d.
					),
					$max_tokens // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer payload for the typed accessor, from absint().
				);
		}

		throw ResponseException::fromInvalidData( 'z.ai', 'stop_reason', 'The message carried an unknown stop reason.' );
	}

	/**
	 * Rejects option/model combinations the zai_anthropic catalog does not
	 * advertise, and Messages protocol violations, BEFORE any transport work.
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return void
	 * @throws InvalidArgumentException When the request is unsupported or malformed.
	 */
	private function validate_request( array $prompt ): void {
		$config = $this->getConfig();

		$this->reject_unsupported_options( $config->toArray() );

		// Multiple candidates are not advertised.
		if ( null !== $config->getCandidateCount() ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider does not support candidateCount (multiple candidates).'
			);
		}

		// max_tokens is required and must be positive; a zero/negative value
		// would be a protocol error upstream.
		if ( null !== $config->getMaxTokens() && 1 > $config->getMaxTokens() ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider requires maxTokens to be a positive number.'
			);
		}

		// Output modalities: text only.
		$output_modalities = $config->getOutputModalities();
		if ( \is_array( $output_modalities ) ) {
			foreach ( $output_modalities as $modality ) {
				if ( ! $modality->isText() ) {
					throw new InvalidArgumentException(
						'The zai_anthropic provider only supports text output modalities.'
					);
				}
			}
		}

		// Structured output only in the two advertised MIME types.
		$output_mime_type = $config->getOutputMimeType();
		if ( null !== $output_mime_type && ! \in_array( $output_mime_type, self::SUPPORTED_OUTPUT_MIME_TYPES, true ) ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider supports outputMimeType values text/plain and application/json only.'
			);
		}

		// Text-only input: no file (image/audio/document) parts in any message.
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isFile() ) {
					throw new InvalidArgumentException(
						'The zai_anthropic provider only supports text input in v1; file (image/audio/document) message parts are rejected.'
					);
				}
			}
		}

		// Custom options are not advertised; passing them is rejected rather
		// than silently forwarded to the API.
		if ( array() !== $config->getCustomOptions() ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider does not support custom options.'
			);
		}

		$this->validate_message_order( $prompt );
	}

	/**
	 * Enforces the Messages protocol's role constraints that coalescing
	 * cannot repair.
	 *
	 * Consecutive same-role turns are COALESCED by prepare_messages_param()
	 * (the protocol combines adjacent turns of one role), so alternation is
	 * no longer a validation concern (Codex R1 finding 2). What remains
	 * genuinely invalid: an empty prompt, and a first message that does not
	 * use the user role — both are rejected before any HTTP request with a
	 * precise message instead of surfacing as an upstream 400.
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return void
	 * @throws InvalidArgumentException When the role order violates the protocol.
	 */
	private function validate_message_order( array $prompt ): void {
		if ( array() === $prompt ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider requires at least one message.'
			);
		}

		$first_role = $this->message_role_string( $prompt[0]->getRole() );
		if ( 'user' !== $first_role ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider requires the first message to use the user role.'
			);
		}
	}

	/**
	 * Rejects config keys that are not part of the advertised option set.
	 *
	 * @since 0.2.0
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
						'The zai_anthropic provider does not support %s.',
						esc_html( $label )
					)
				);
			}
		}
	}
}
