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
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use Deicod\WpConnectors\Zai\Authentication\ZaiAnthropicRequestAuthentication;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard;
use Deicod\WpConnectors\Zai\Support\AdvertisedUsageGuard;
use Deicod\WpConnectors\Zai\Support\AnthropicSseAggregator;
use Deicod\WpConnectors\Zai\Support\ErrorMapper;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;
use Deicod\WpConnectors\Zai\Support\ThrowsSafeHttpErrors;

/**
 * Text generation model for zai_anthropic.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {
	use ThrowsSafeHttpErrors;

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
	 * Transport, HTTP, and parsing failures throw their typed SDK
	 * exceptions with fixed, safe messages (see the parse/throw helpers).
	 *
	 * @throws InvalidArgumentException When the credential gate refuses
	 *                                  the active key (Codex R19:
	 *                                  region-pending or an invalid
	 *                                  verdict for the selected endpoint).
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$this->refuse_refused_credentials();

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
	 * Refuses generation for credentials the availability layer distrusts.
	 *
	 * Codex R19 #3: an env/constant credential cannot be deleted by a
	 * region switch, so the settings layer marks that exact key
	 * region-pending and the availability layer persists definitive
	 * invalid verdicts — yet this public direct-generation path
	 * authenticated unconditionally, sending the old region's credential
	 * to the newly selected regional endpoint even while the connector
	 * reported disconnected. The gate REUSES the availability layer's
	 * own state readers (no duplicated logic, no probe request) with
	 * the model's exact credential, exactly like the zai (OpenAI)
	 * surface's prepareGenerateTextParams() gate.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 * @throws InvalidArgumentException When the credential is region-pending
	 *                                  or carries a fresh invalid verdict
	 *                                  for the selected endpoint.
	 */
	private function refuse_refused_credentials(): void {
		/*
		 * GLM2 #3: an unbound model (no wired auth) is not this gate's
		 * concern — skip it and let the request fail exactly where it did
		 * before the gate existed (the SDK's authenticateRequest()
		 * RuntimeException), so the pre-gate exception ORDER is preserved
		 * for callers that misuse an unbound model while ALSO carrying
		 * invalid options (the OpenAI twin's verifier nit on GLM1 #1
		 * guards the same divergence; this surface now mirrors it, instead
		 * of throwing the binding RuntimeException before
		 * validate_request() can reject the options).
		 */
		try {
			$authentication = $this->getRequestAuthentication();
		} catch ( RuntimeException $e ) {
			return;
		}

		if ( ! $authentication instanceof ApiKeyRequestAuthentication ) {
			return;
		}

		$refusal = ( new ZaiAnthropicProviderAvailability() )->generation_refusal_reason( $authentication );

		if ( null !== $refusal ) {
			throw new InvalidArgumentException(
				'region_pending' === $refusal
					? 'The zai_anthropic provider refuses generation: the active environment credential is pending revalidation after a region switch.'
					: 'The zai_anthropic provider refuses generation: the active credential was rejected for the selected endpoint.'
			);
		}
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

		/*
		 * GLM1 #4: an explicitly-cleared list ([] — the setters accept it,
		 * array_is_list is true for the empty list) means "not set" here,
		 * not an empty wire member: the Messages API rejects "tools": []
		 * with a 400 (and an empty stop_sequences adds nothing a missing
		 * member does not). Both are omitted when empty.
		 */
		$stop_sequences = $config->getStopSequences();
		if ( \is_array( $stop_sequences ) && array() !== $stop_sequences ) {
			$params['stop_sequences'] = $stop_sequences;
		}

		$function_declarations = $config->getFunctionDeclarations();
		if ( \is_array( $function_declarations ) && array() !== $function_declarations ) {
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
	 * @throws InvalidArgumentException When the configured outputSchema
	 *                                  cannot be JSON-encoded (Codex R19).
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
			/*
			 * R19 (inline 3906739372): a constructible but unencodable
			 * outputSchema — NAN, invalid UTF-8, a recursive structure — makes
			 * wp_json_encode() return false, which the string cast silently
			 * turned into '': the guidance ended in "JSON Schema: " and the
			 * model produced unconstrained output even though the caller
			 * requested a schema. Rejected before transport in the same
			 * channel as the R18 tool-result encoding failure.
			 */
			$encoded_schema = wp_json_encode( $output_schema );
			if ( false === $encoded_schema ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider could not JSON-encode the configured output schema (unencodable value such as NAN, invalid UTF-8, or a recursive structure).'
				);
			}

			$guidance .= "\n" . sprintf(
				/* translators: %s: a JSON Schema document (compact JSON). */
				__( 'The JSON value must conform to this JSON Schema: %s', 'zai' ),
				$encoded_schema
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
	 * @throws InvalidArgumentException When a parameter schema is a non-empty list.
	 */
	protected function prepare_tools_param( array $function_declarations ): array {
		$tools          = array();
		$declared_names = array();

		foreach ( $function_declarations as $declaration ) {
			/*
			 * Codex R18 #2: a declared tool with an EMPTY name is the same
			 * malformed identity the call and tool-result paths already
			 * reject before transport (Messages requires a non-empty
			 * identity) — the declaration path must not be the bypass that
			 * sends it upstream to a 400. The DTO constructor coerces the
			 * name to a string, so '' is the only constructible empty
			 * identity. Identity errors surface BEFORE the schema checks
			 * (first-bad-wins), matching the call path's ordering.
			 */
			$name = $declaration->getName();

			if ( '' === $name ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires declared tool functions to carry a non-empty name.'
				);
			}

			/*
			 * R18 (inline 3906485728): a returned tool_use identifies the
			 * selected declaration ONLY by name — two declarations sharing a
			 * name make that identification ambiguous (the caller may
			 * validate or execute the call against the wrong tool), so a
			 * duplicate is a typed pre-transport rejection like the empty
			 * name above.
			 */
			if ( isset( $declared_names[ $name ] ) ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires declared tool functions to carry unique names.'
				);
			}

			$declared_names[ $name ] = true;

			/*
			 * The Messages protocol requires input_schema on every tool —
			 * an OBJECT — even for functions without parameters. Both the
			 * absent schema (null) and an EMPTY array schema () normalize
			 * to the empty-object schema; a raw empty array would
			 * JSON-encode as [] and fail upstream validation (Codex R1
			 * finding 5). A NON-EMPTY sequential schema (Codex R7 #3)
			 * serializes as a JSON LIST — same failure, so it is rejected
			 * before transport with the same surface as the
			 * invocation-arguments validation (R4 #4), never silently
			 * re-shaped: the list test is exact (json_encode emits an array
			 * only for 0-based sequential keys).
			 */
			$input_schema = $declaration->getParameters();

			if ( \is_array( $input_schema ) && array() !== $input_schema
				&& \array_keys( $input_schema ) === \range( 0, \count( $input_schema ) - 1 ) ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires tool parameter schemas to be a JSON object (a non-empty list was given).'
				);
			}

			/*
			 * R20 (inline 3907008524): an unencodable schema value — NAN,
			 * invalid UTF-8, a resource, a recursive structure — reached the
			 * request untouched, so generation failed in the transport's
			 * whole-request serialization instead of producing the adapter's
			 * pre-transport configuration error. Validated with the same
			 * wp_json_encode() oracle the output-schema (R19) and tool-result
			 * (R18) rejections use; the empty schema keeps its object
			 * normalization below.
			 */
			if ( \is_array( $input_schema ) && array() !== $input_schema
				&& false === wp_json_encode( $input_schema ) ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider could not JSON-encode a declared tool parameter schema (unencodable value such as NAN, invalid UTF-8, or a recursive structure).'
				);
			}

			if ( null === $input_schema || array() === $input_schema ) {
				$input_schema = array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				);
			}

			$tools[] = array(
				'name'         => $name,
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
	 * Tool-result linkage (Codex R8 #5 / R9 #1): every tool_result block
	 * must answer a tool_use from the IMMEDIATELY PRECEDING assistant
	 * turn, exactly once. Outstanding IDs are opened by an assistant
	 * turn's tool_use blocks and may ONLY be answered by the very next
	 * user turn — once any other turn advances past that window, the IDs
	 * expire: a stale result later in the history is rejected before
	 * transport (as are unmatched/mistyped/duplicate results) instead of
	 * failing upstream with a 400. A history that ends on the unanswered
	 * assistant tool turn itself is rejected the same way (GLM2 #1): the
	 * protocol demands the answering user turn before the replay ships.
	 *
	 * @since 0.2.0
	 *
	 * @param array $messages Messages to prepare (list of Message).
	 * @return array The prepared messages parameter (list of content messages).
	 * @throws InvalidArgumentException When a tool_result ID is unmatched, stale, or answered twice, or the history ends on an unanswered tool turn.
	 */
	protected function prepare_messages_param( array $messages ): array {
		$prepared          = array();
		$outstanding_tools = array();
		$awaiting_answer   = false;
		$previous_role     = null;
		$turn_tool_ids     = array();

		foreach ( $messages as $message ) {
			$role   = $this->message_role_string( $message->getRole() );
			$blocks = $this->message_content_blocks( $message );

			/*
			 * Turn boundary = role change: adjacent SDK messages of the same
			 * role coalesce into ONE wire turn below, so all turn-scoped
			 * validation runs HERE, once per coalesced turn — (a) the
			 * duplicate-id check scopes to the wire turn (Codex R10 #2
			 * verifier probe: two adjacent assistant Messages sharing a tool
			 * id must reject), and (b) the R9/R10 answer window closes only
			 * when the answering COALESCED user turn has fully ended
			 * (Codex R11 #1): checking after each SDK message rejected a
			 * legitimately split answer (result A in SDK user message 1,
			 * result B in the immediately adjacent message 2) before the
			 * coalescing below could merge them into the one valid wire
			 * turn.
			 */
			if ( $role !== $previous_role ) {
				$this->advance_answer_window( $awaiting_answer, $outstanding_tools, $previous_role, $role );

				$turn_tool_ids = array();
				$previous_role = $role;
			}

			$opens_tools = false;

			foreach ( $blocks as $block ) {
				if ( 'tool_use' === $block['type'] ) {
					/*
					 * Codex R10 #2: two tool_use blocks with the SAME id in
					 * one assistant turn are ambiguous — a single later
					 * result would satisfy linkage while the wire carries
					 * duplicate identities (upstream validation failure).
					 * Reject before the map assignment can overwrite.
					 */
					if ( isset( $turn_tool_ids[ $block['id'] ] ) ) {
						throw new InvalidArgumentException(
							'The zai_anthropic provider requires tool call ids to be unique within an assistant message (duplicate tool call id).'
						);
					}

					$turn_tool_ids[ $block['id'] ] = true;

					// A new tool_use opens its ID for exactly one answer.
					$outstanding_tools[ $block['id'] ] = true;
					$opens_tools                       = true;
				} elseif ( 'tool_result' === $block['type'] ) {
					if ( ! isset( $outstanding_tools[ $block['tool_use_id'] ] ) ) {
						throw new InvalidArgumentException(
							'The zai_anthropic provider requires every tool result to answer the preceding assistant tool call with the same id (unmatched, stale, or duplicate tool result).'
						);
					}

					// Each tool_use ID may be answered exactly once.
					unset( $outstanding_tools[ $block['tool_use_id'] ] );
				}
			}

			if ( $opens_tools ) {
				$awaiting_answer = true;
			}

			$last = \count( $prepared ) - 1;
			if ( $last >= 0 && $prepared[ $last ]['role'] === $role ) {
				/*
				 * Codex R12 #2: the coalescing merge must not produce a
				 * user wire turn with text BEFORE its tool_result blocks —
				 * Anthropic requires tool results to precede any text in
				 * the turn answering tool calls, and the linkage checks
				 * consume IDs regardless of position, so this order passed
				 * local validation and 400'd upstream. Judged on the MERGED
				 * turn (which also covers a single SDK message whose blocks
				 * are already misordered).
				 */
				$merged = array_merge( $prepared[ $last ]['content'], $blocks );

				if ( 'user' === $role ) {
					$this->reject_text_before_tool_results( $merged );
				}

				$prepared[ $last ]['content'] = $merged;
				continue;
			}

			if ( 'user' === $role ) {
				$this->reject_text_before_tool_results( $blocks );
			}

			$prepared[] = array(
				'role'    => $role,
				'content' => $blocks,
			);
		}

		/*
		 * End of history is the final coalesced-turn boundary: a window
		 * answered by the LAST user turn is judged for completeness after
		 * that turn's full coalescing (Codex R11 #1).
		 *
		 * GLM2 #1: a history that ENDS on the open assistant tool turn
		 * itself is equally invalid — the Messages API requires every
		 * tool_use to be followed by its tool_result blocks in the next
		 * message, so such a request 400s upstream with the generic
		 * client-error surface. The caller's tool loop must append the
		 * answering user turn BEFORE replaying the conversation, never
		 * send the unanswered trailing turn back to the wire.
		 */
		if ( $awaiting_answer && array() !== $outstanding_tools ) {
			if ( 'user' === $previous_role ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires the user turn after a tool call to answer every tool call of that turn (partially answered tool turn).'
				);
			}

			throw new InvalidArgumentException(
				'The zai_anthropic provider requires the final assistant tool call turn to be answered by a following user turn (unanswered tool call at the end of the conversation).'
			);
		}

		return $prepared;
	}

	/**
	 * Rejects text blocks preceding tool-result blocks in a user turn.
	 *
	 * Anthropic requires tool_result blocks to come FIRST — before any
	 * text block — in the user turn that answers tool calls (Codex R12
	 * #2). Runs on the fully merged wire turn so both shapes are caught:
	 * a text Message adjacent-before a FunctionResponse Message, and a
	 * single SDK message whose blocks array already has text first. A user
	 * turn with no tool_result blocks is not an answering turn and is not
	 * judged here.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<string, mixed>> $blocks The merged wire-turn blocks.
	 * @return void
	 * @throws InvalidArgumentException When a text block precedes a tool_result block.
	 */
	private function reject_text_before_tool_results( array $blocks ): void {
		$has_tool_result = false;
		foreach ( $blocks as $block ) {
			if ( 'tool_result' === $block['type'] ) {
				$has_tool_result = true;
				break;
			}
		}

		if ( ! $has_tool_result ) {
			return;
		}

		$seen_text = false;
		foreach ( $blocks as $block ) {
			if ( 'text' === $block['type'] ) {
				$seen_text = true;
			} elseif ( 'tool_result' === $block['type'] && $seen_text ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires tool results to precede text blocks in the user turn following a tool call.'
				);
			}
		}
	}

	/**
	 * Advances the tool-answer window across a coalesced-turn boundary.
	 *
	 * Called when the incoming turn's role differs from the previous
	 * turn's — i.e., exactly once per WIRE turn (adjacent same-role SDK
	 * messages coalesce; Codex R11 #1). A window opened by an assistant
	 * tool turn is judged here:
	 *
	 * - previous turn 'assistant' + incoming 'user': the answering turn
	 *   BEGINS — the outstanding IDs stay answerable (the user turn's own
	 *   results consume them as its messages are processed).
	 * - previous turn 'assistant' + incoming non-user: the tool turn's
	 *   results never arrive — expire the IDs (R9 stale semantics).
	 * - previous turn 'user': the answering coalesced turn has ENDED —
	 *   every ID must have been answered (R10 #1 partial rule, evaluated
	 *   only now that the split messages have merged, per R11 #1).
	 *
	 * @since 0.2.0
	 *
	 * @param bool        $awaiting_answer   Whether a tool-answer window is open (by ref).
	 * @param array       $outstanding_tools Outstanding tool-use IDs (by ref).
	 * @param string|null $previous_role     Role of the coalesced turn that just ended.
	 * @param string      $role              Role of the incoming coalesced turn.
	 * @return void
	 * @throws InvalidArgumentException When a completed user turn left IDs unanswered.
	 */
	private function advance_answer_window( bool &$awaiting_answer, array &$outstanding_tools, $previous_role, string $role ): void {
		if ( ! $awaiting_answer ) {
			return;
		}

		if ( 'assistant' === $previous_role ) {
			if ( 'user' !== $role ) {
				// The tool turn's results never arrived: expire.
				$outstanding_tools = array();
				$awaiting_answer   = false;
			}

			// Incoming user: the answering turn begins — keep the IDs.
			return;
		}

		if ( 'user' === $previous_role ) {
			// The answering coalesced user turn has ended.
			if ( array() !== $outstanding_tools ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires the user turn after a tool call to answer every tool call of that turn (partially answered tool turn).'
				);
			}

			$awaiting_answer = false;
		}
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

			/*
			 * Codex R9 #3: the Messages protocol requires NON-EMPTY tool
			 * ids and names — an empty string passes the null-only guard
			 * and emitted a tool_use block with an empty identity (upstream
			 * 400).
			 */
			if ( null === $function_call
				|| null === $function_call->getId() || '' === $function_call->getId()
				|| null === $function_call->getName() || '' === $function_call->getName() ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires every function-call part to carry a non-empty id and name.'
				);
			}

			/*
			 * The Messages protocol requires an OBJECT for tool_use input.
			 * Empty/absent args (null, the empty string, the empty array)
			 * become an empty object explicitly (official-plugin
			 * normalization; PHP's empty array would encode as []). Any
			 * OTHER shape that would encode as a JSON list or scalar — a
			 * non-empty sequential array like array('Oslo'), or a scalar
			 * such as 'Oslo' or a float — is rejected BEFORE transport
			 * (Codex R4 #4; scalars GLM2 #2): upstream would answer a 400,
			 * and silently re-shaping would silently alter the call's
			 * arguments. NAN and INF floats are scalars and are rejected
			 * here too — they would otherwise surface as a raw JsonException
			 * from the transport's whole-request encode. Objects (stdClass
			 * values from the inbound parser's nested object-ness
			 * preservation, GLM1 #2) already ARE JSON objects and pass
			 * untouched. The list test is exact: json_encode emits an array
			 * only for 0-based sequential keys, so mixed/string-keyed
			 * arrays still pass as objects.
			 */
			$input = $function_call->getArgs();

			if ( \is_array( $input ) && array() !== $input
				&& \array_keys( $input ) === \range( 0, \count( $input ) - 1 ) ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires tool arguments to be a JSON object (a non-empty list was given).'
				);
			}

			if ( null !== $input && '' !== $input && ! \is_array( $input ) && ! \is_object( $input ) ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires tool arguments to be a JSON object (a scalar was given).'
				);
			}

			if ( null === $input || '' === $input || ( \is_array( $input ) && array() === $input ) ) {
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
			if ( null === $function_response || null === $function_response->getId() || '' === $function_response->getId() ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider requires every function-response part to carry the non-empty tool_use id it answers.'
				);
			}

			/*
			 * R18 (inline 3906485711): an unencodable tool-result value — NAN,
			 * a resource, a recursive structure — makes wp_json_encode()
			 * return false, which the string cast silently turned into '': the
			 * request then succeeded structurally while telling the model the
			 * tool returned NO content, corrupting the conversation. The
			 * serialization failure is a typed pre-transport rejection in the
			 * same channel as the other tool-result validations.
			 */
			$encoded = wp_json_encode( $function_response->getResponse() );
			if ( false === $encoded ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider could not JSON-encode a tool result (unencodable value such as NAN, a resource, or a recursive structure).'
				);
			}

			return array(
				'type'        => 'tool_result',
				'tool_use_id' => $function_response->getId(),
				'content'     => $encoded,
			);
		}

		// File parts (images, documents, audio) were already rejected by
		// validate_request() — no GLM model on this surface has verified
		// image/file support yet (record 0006 note 4).
		return null;
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

		$aggregated = $aggregator->aggregated();

		if ( $aggregator->has_malformed_event() ) {
			/*
			 * A declared event frame was undecodable or wrongly shaped
			 * (Codex R4 #3), or aggregated() refused the stream — e.g. no
			 * message_start was received (Codex R8 #3), which must never
			 * produce a payload. The check runs AFTER aggregated() because
			 * aggregation itself raises the flag.
			 */
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'The message stream contained a malformed event frame.'
			);
		}

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
		 * Codex R6 #5: the top-level content member must be a JSON ARRAY.
		 * The associative decode collapses a JSON object {} into the same
		 * empty PHP array as an empty list, so "content": {} slipped past
		 * the is_array() check above and returned a successful candidate
		 * with no parts. The raw (non-associative) decode preserves the
		 * distinction: only a JSON array decodes to a PHP list.
		 */
		$raw_body = json_decode( (string) $response->getBody() );
		if ( ! \is_object( $raw_body ) || ! \is_array( $raw_body->content ) ) {
			if ( \is_object( $raw_body ) && property_exists( $raw_body, 'content' ) ) {
				throw ResponseException::fromInvalidData( 'z.ai', 'content', 'The message content must be a JSON array.' );
			}
		}

		/*
		 * Object-ness oracle (Codex R3 #1): getData() decodes the body
		 * ASSOCIATIVELY, which collapses the JSON object {} and the JSON
		 * list [] into the same empty PHP array. A parallel non-
		 * associative decode of the same body preserves the distinction
		 * ({} is stdClass, [] is an array) and is handed to each content
		 * block alongside the associative value.
		 */
		$raw            = $raw_body;
		$raw_content_ok = \is_object( $raw ) && isset( $raw->content ) && \is_array( $raw->content );

		/*
		 * Verifier residual on Codex R5 + Codex R6 #1: a Messages response
		 * envelope identifies itself with type "message" — a contradictory
		 * envelope (e.g. type "error" carrying an otherwise-valid body)
		 * must not parse as a generation. array_key_exists() treats an
		 * explicitly-null member as PRESENT (isset() does not), so
		 * "type": null is rejected too; an omitted member stays tolerated
		 * (the role, content, and stop_reason members carry the validation
		 * weight). The strict !== also covers non-string values.
		 */
		if ( \array_key_exists( 'type', $data ) && 'message' !== $data['type'] ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'type', 'The response envelope did not identify itself as a message.' );
		}

		/*
		 * Codex R5 #3: a Messages GENERATION response must be an assistant
		 * message — require the exact role. A missing, unknown, or `user`
		 * role previously fabricated an assistant turn or, worse, exposed
		 * the payload as a generated USER message, mis-attributing content
		 * into downstream history.
		 */
		if ( ! isset( $data['role'] ) || ! \is_string( $data['role'] ) || 'assistant' !== $data['role'] ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'role', 'The message did not identify itself as an assistant response.' );
		}

		$role = MessageRoleEnum::model();

		$parts            = array();
		$seen_tool_ids    = array();
		$dropped_unmapped = false;
		foreach ( $data['content'] as $index => $part_data ) {
			if ( ! \is_array( $part_data ) ) {
				throw ResponseException::fromInvalidData( 'z.ai', 'content', 'Every content entry must be an object.' );
			}

			$raw_part = $raw_content_ok && isset( $raw->content[ $index ] ) && \is_object( $raw->content[ $index ] )
				? $raw->content[ $index ]
				: null;

			$part = $this->parse_content_block( $part_data, $raw_part );

			/*
			 * Codex R13 #4: two tool_use blocks with the same NON-EMPTY id
			 * are ambiguous identities — a consumer cannot correlate
			 * results to calls, and replaying the assistant turn hits this
			 * adapter's own outbound duplicate-id rejection after tools may
			 * have executed. Rejected in the same channel as other
			 * malformed content blocks; empty/absent ids keep their
			 * existing malformed-id handling untouched.
			 */
			if ( null !== $part && 'tool_use' === ( $part_data['type'] ?? null )
				&& isset( $part_data['id'] ) && \is_string( $part_data['id'] ) && '' !== $part_data['id'] ) {
				if ( isset( $seen_tool_ids[ $part_data['id'] ] ) ) {
					throw ResponseException::fromInvalidData( 'z.ai', 'content', 'Two tool_use blocks carried the same id.' );
				}

				$seen_tool_ids[ $part_data['id'] ] = true;
			}

			if ( null !== $part ) {
				$parts[] = $part;
			} else {
				// A known-but-unmapped block was dropped (see the
				// KNOWN LIMITATION note in parse_content_block()).
				$dropped_unmapped = true;
			}
		}

		if ( ! isset( $data['stop_reason'] ) ) {
			throw ResponseException::fromMissingData( 'z.ai', 'stop_reason' );
		}

		/*
		 * GLM2 #5: every sibling envelope member (type, role, id) gets an
		 * is_string shape guard, but stop_reason was only (string)-cast —
		 * a non-scalar value emitted an Array-to-string warning before the
		 * typed rejection and, on warning-strict installs (a custom
		 * set_error_handler, PHPUnit), aborted the parse with an
		 * ErrorException-family Throwable that bypassed this channel.
		 */
		if ( ! \is_string( $data['stop_reason'] ) ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'stop_reason', 'The message did not carry a string stop reason.' );
		}

		$finish_reason = $this->finish_reason_for( $data['stop_reason'] );

		/*
		 * Codex R14 #2: the stop reason must match the parsed content — a
		 * tool_use reason with no FunctionCall signals toolCalls() with
		 * nothing to execute, and tool blocks under an ordinary completion
		 * reason (end_turn/stop_sequence/pause_turn/refusal) execute
		 * nothing while signaling completion. Checked AFTER
		 * finish_reason_for() so the typed truncation exceptions keep
		 * precedence; same typed channel as the duplicate-id rejection
		 * above.
		 */
		$has_tool_call = false;
		foreach ( $parts as $part ) {
			if ( null !== $part->getFunctionCall() ) {
				$has_tool_call = true;
				break;
			}
		}

		if ( ( 'tool_use' === $data['stop_reason'] ) !== $has_tool_call ) {
			/*
			 * Code-review #15 (diagnosability only — no new rejections):
			 * when unmapped block types were dropped, say so in the
			 * message; a content-only-dropped-blocks response is otherwise
			 * indistinguishable from a corrupt stop_reason.
			 */
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stop_reason',
				$dropped_unmapped
					? 'The stop reason did not match the response content (unmapped block types were dropped).'
					: 'The stop reason did not match the response content.'
			);
		}

		$candidates = array(
			new Candidate(
				new Message( $role, $parts ),
				$finish_reason
			),
		);

		/*
		 * R19 (inline 3906739381): a successful response must carry a
		 * NON-EMPTY string id — the fallback returned a result with no
		 * message identity, and the consolidated-stream path shared the gap
		 * because the aggregator fabricates an empty id when
		 * message_start.message.id is absent. Both paths merge HERE, so one
		 * rejection covers them (same typed channel as the other response
		 * identity checks).
		 */
		if ( ! isset( $data['id'] ) || ! \is_string( $data['id'] ) || '' === $data['id'] ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'id', 'The response did not carry a non-empty message id.' );
		}

		$id = $data['id'];

		/*
		 * Codex R14 #5: a PRESENT usage member must be a JSON OBJECT whose
		 * supplied token counts are non-negative integers — a list-shaped
		 * [1,2] passes is_array() and strings, bools, floats, and
		 * negatives survived the (int) casts below as plausible token
		 * accounting on a successful generation. Object-ness oracle (the
		 * R3 #1 pattern): the raw decode distinguishes a JSON object
		 * (stdClass) from a list (array), with the repo's sequential-key
		 * test as the fallback when the oracle is unavailable. An ABSENT
		 * usage member keeps the documented default-zero tolerance; an
		 * explicitly-null member is PRESENT (array_key_exists) and
		 * therefore rejected.
		 */
		$usage_data = array();
		if ( \array_key_exists( 'usage', $data ) ) {
			$usage_data      = \is_array( $data['usage'] ) ? $data['usage'] : null;
			$usage_is_object = false;

			if ( null !== $usage_data ) {
				if ( \is_object( $raw ) && \property_exists( $raw, 'usage' ) ) {
					$usage_is_object = \is_object( $raw->usage );
				} else {
					$usage_is_object = array() === $usage_data
						|| \array_keys( $usage_data ) !== \range( 0, \count( $usage_data ) - 1 );
				}
			}

			if ( ! $usage_is_object ) {
					throw ResponseException::fromInvalidData( 'z.ai', 'usage', 'The usage member must be a JSON object.' );
			}

			foreach ( array( 'input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens' ) as $member ) {
				if ( \array_key_exists( $member, $usage_data ) && ( ! \is_int( $usage_data[ $member ] ) || $usage_data[ $member ] < 0 ) ) {
						throw ResponseException::fromInvalidData( 'z.ai', 'usage', 'Token counts must be non-negative integers.' );
				}
			}
		}

		$input  = (int) ( $usage_data['input_tokens'] ?? 0 )
			+ (int) ( $usage_data['cache_creation_input_tokens'] ?? 0 )
			+ (int) ( $usage_data['cache_read_input_tokens'] ?? 0 );
		$output = (int) ( $usage_data['output_tokens'] ?? 0 );
		$usage  = new TokenUsage( $input, $output, $input + $output );

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
				// Codex R9 #3: identities must be non-empty strings, not
				// merely present — an empty id/name is a corrupt block.
				foreach ( array( 'id', 'name' ) as $member ) {
					if ( ! isset( $part_data[ $member ] ) || ! \is_string( $part_data[ $member ] ) || '' === $part_data[ $member ] ) {
						throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A tool_use block is missing its identity members.' );
					}
				}

				/*
				 * Object-ness validation (Codex R2 #1 / R3 #1 / R7 #1),
				 * mirroring the R1 SSE fix: tool arguments must be a JSON
				 * OBJECT, and the member itself is REQUIRED — an empty call
				 * is represented by {} alone. An OMITTED or explicitly-null
				 * input previously normalized into a fabricated
				 * no-argument FunctionCall (the R2-era tolerance, superseded
				 * by R7: the protocol demands the member); scalars,
				 * booleans, and JSON lists (including the empty list [])
				 * were already typed parse errors, because passing them
				 * through would hand a consumer fabricated or invalid
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

					if ( null === $args || ! self::is_object_shape( $args ) ) {
						throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A tool_use block is missing its input member.' );
					}

					if ( array() === $args ) {
						$args = null;
					}
				} else {
					$raw_input = \property_exists( $raw_part, 'input' ) ? $raw_part->input : null;

					if ( null === $raw_input ) {
						// Missing input member or explicit null (R7 #1).
						throw ResponseException::fromInvalidData( 'z.ai', 'content', 'A tool_use block is missing its input member.' );
					} elseif ( \is_object( $raw_input ) ) {
						if ( array() === get_object_vars( $raw_input ) ) {
							// The empty object {} means "no arguments"
							// (official-plugin normalization).
							$args = null;
						} else {
							$args = self::tool_args_from_raw( $raw_input );
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
				/*
				 * KNOWN LIMITATION (code-review #15, documented not fixed):
				 * these provider-internal block types carry no SDK
				 * representation and are silently DROPPED. Accepted as of
				 * 2026-09-01 because they are an upstream quirk — this
				 * connector's own requests send client function tools
				 * only, so it cannot trigger server_tool_use /
				 * web_search_tool_result / redacted_thinking itself. The
				 * failure surface stays typed, never silent-empty: a
				 * response whose content is ONLY unmapped blocks produces
				 * no parts, which the stop-reason/content consistency
				 * check below rejects as a ResponseException
				 * (zai_invalid_response) whose message names the dropped
				 * blocks when that was the case. The streamed path drops
				 * the same shapes earlier (see the aggregator's
				 * content_block_payload()).
				 */
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
	 * Converts a raw-decoded tool input into the stored arguments value,
	 * preserving JSON object-ness at EVERY level (code-review GLM1 #2).
	 *
	 * The associative decode collapses {} and [] into the same empty PHP
	 * array and turns a numeric-keyed JSON object ({"0":"x"}) into a list —
	 * information the outbound replay (message_part_block) could not
	 * recover, so nested empty objects and numeric-keyed objects silently
	 * re-encoded as JSON lists on the wire. This walk starts from the RAW
	 * (non-associative) decode instead, keeping a PHP array wherever an
	 * array re-encodes as an object unambiguously (any non-sequential key
	 * set — consumer ergonomics unchanged for ordinary arguments) and a
	 * stdClass wherever an array would re-encode as a list (the empty
	 * object and the purely sequential-keyed object).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value A value from a non-associative json_decode() tree.
	 * @return mixed The same tree with object-ness preserved for encoding.
	 */
	private static function tool_args_from_raw( $value ) {
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );

			$converted = array();
			foreach ( $properties as $key => $member ) {
				$converted[ $key ] = self::tool_args_from_raw( $member );
			}

			// An empty object, or one whose keys would re-encode the array
			// as a JSON list, stays a stdClass (with converted members).
			if ( array() === $converted
				|| \array_keys( $converted ) === \range( 0, \count( $converted ) - 1 ) ) {
				$object = new \stdClass();
				foreach ( $converted as $key => $member ) {
					$object->{$key} = $member;
				}

				return $object;
			}

			return $converted;
		}

		if ( \is_array( $value ) ) {
			$converted = array();
			foreach ( $value as $key => $member ) {
				$converted[ $key ] = self::tool_args_from_raw( $member );
			}

			return $converted;
		}

		return $value;
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
				$max_tokens = absint( $this->getConfig()->getMaxTokens() ?? self::DEFAULT_MAX_TOKENS );

				throw new TokenLimitReachedException(
					sprintf(
						/* translators: %d: the configured token limit. */
						__( 'The generation stopped because the token limit was reached (%d). Raise maxTokens to continue longer answers.', 'zai' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
						$max_tokens // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer from absint(), formatted via %d.
					),
					$max_tokens // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer payload for the typed accessor, from absint().
				);

			case 'model_context_window_exceeded':
				/*
				 * Codex R5 #4: the model's overall CONTEXT window is
				 * exhausted — raising maxTokens cannot recover it (it
				 * leaves even less room), so this gets its own advice and
				 * a null typed payload to distinguish it from the
				 * max_tokens case (ErrorMapper keys on that).
				 */
				throw new TokenLimitReachedException(
					__( 'The generation stopped because the conversation exceeds the model\'s context window. Reduce the input — truncate the history or shorten the prompt — and try again.', 'zai' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
					null
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

		/*
		 * GLM1 #11: the unsupported-option rejection is shared with the
		 * zai surface (was a verbatim twin, one label apart).
		 */
		AdvertisedOptionGuard::reject_unsupported( $config->toArray(), 'zai_anthropic' );

		/*
		 * GLM2 #9: the five usage rejections the two surfaces advertise
		 * IDENTICALLY (candidateCount, text-only output modalities, the
		 * MIME whitelist, text-only input, custom options) are shared too
		 * — they were verbatim twins directly under this call, the exact
		 * duplication pattern the guard above was extracted to stop.
		 */
		AdvertisedUsageGuard::reject_unsupported( $config, $prompt, 'zai_anthropic' );

		// max_tokens is required and must be positive; a zero/negative value
		// would be a protocol error upstream.
		if ( null !== $config->getMaxTokens() && 1 > $config->getMaxTokens() ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider requires maxTokens to be a positive number.'
			);
		}

		/*
		 * GLM1 #8: the Messages protocol bounds temperature and top_p to
		 * the CLOSED interval 0..1 — values the SDK and the OpenAI surface
		 * accept (temperature up to 2.0) are protocol violations here that
		 * surfaced only as an upstream 400 with the generic misattributed
		 * message. Typed pre-transport rejection citing the range; never
		 * silently clamped. Explicit comparisons: 0.0 is falsy but legal.
		 *
		 * GLM2 #4: NAN compares false against BOTH bounds, so the range
		 * test alone let it through — the unencodable float then detonated
		 * as a raw JsonException in the transport's whole-request encode
		 * instead of this typed rejection. is_nan() is checked explicitly
		 * (INF already fails the > 1 bound; -INF the < 0 one).
		 */
		$temperature = $config->getTemperature();
		if ( null !== $temperature && ( \is_nan( $temperature ) || $temperature < 0 || $temperature > 1 ) ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider requires temperature between 0 and 1 (the Anthropic Messages protocol range).'
			);
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p && ( \is_nan( $top_p ) || $top_p < 0 || $top_p > 1 ) ) {
			throw new InvalidArgumentException(
				'The zai_anthropic provider requires top_p between 0 and 1 (the Anthropic Messages protocol range).'
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
}
