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
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard;
use Deicod\WpConnectors\Zai\Support\AdvertisedUsageGuard;
use Deicod\WpConnectors\Zai\Support\ErrorMapper;
use Deicod\WpConnectors\Zai\Support\EventStreamSniff;
use Deicod\WpConnectors\Zai\Support\JsonEncodeGuard;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;
use Deicod\WpConnectors\Zai\Support\PreDecodedResponse;
use Deicod\WpConnectors\Zai\Support\ThrowsSafeHttpErrors;
use Deicod\WpConnectors\Zai\Support\SseAggregator;
use Deicod\WpConnectors\Zai\Support\SseFrameBuffer;
use Deicod\WpConnectors\Zai\Support\ToolArgsObjectNess;
use Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard;
use Deicod\WpConnectors\Zai\Support\UsageValidator;

/**
 * Text generation model for z.ai.
 *
 * @since 0.1.0
 */
final class ZaiTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	use ThrowsSafeHttpErrors;

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
		parent::setHttpTransporter( LoggingHttpTransporter::wrap( $http_transporter ) );
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
	 * reported disconnected.
	 *
	 * GLM4 #9 moved the gate PREDICATE and the refusal messages to the
	 * availability layer; GLM5 #17 absorbed the remaining wrapper
	 * sequence (the unwired-model skip, the predicate call, the message
	 * build, the throw) into the one refuse_generation() helper every
	 * credential consumer consults. This surface's only contribution is
	 * its WIRING: the model's own SDK getter for the authentication it
	 * would authenticate with (an unwired model skips the gate, keeping
	 * the pre-gate exception order — the GLM1 #1 verifier nit).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 * @throws InvalidArgumentException When the credential is region-pending
	 *                                  or carries a fresh invalid verdict
	 *                                  for the selected endpoint.
	 */
	private function refuse_refused_credentials(): void {
		( new ZaiProviderAvailability() )->refuse_generation(
			function () {
				return $this->getRequestAuthentication();
			}
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

		/*
		 * GLM4 #3: the shared EventStreamSniff — the BOM skip, ':'
		 * comment-line and 'event:' recognition the Anthropic surface
		 * gained in GLM3 #5/#7 now apply here too. This surface's old
		 * inline copy recognized only a leading 'data:' line, so a
		 * mangled/omitted Content-Type plus a leading BOM or ': keepalive'
		 * comment misrouted the stream to the JSON parser and every such
		 * streamed generation died as 'The chat-completions payload was
		 * malformed' although the shared SseFrameBuffer would have framed
		 * the stream fine.
		 */
		if ( ! EventStreamSniff::matches( $body, $response->getHeaderAsString( 'Content-Type' ) ) ) {
			/*
			 * GLM9 #3: the stream-start prefix strip the zai_anthropic
			 * twin applies to its non-streaming decodes (GLM8 #3): a
			 * UTF-8 BOM a gateway/CDN prepends to an application/json
			 * chat.completion body makes json_decode() fail (PHP does not
			 * skip a leading BOM), the associative decode below yields
			 * null, the PreDecodedResponse hand-off never happens, and a
			 * valid generation surfaced as 'The chat-completions payload
			 * was malformed.' — one surface tolerating the exact prefix
			 * shape its own twin already strips. The strip runs before
			 * BOTH decodes (the raw oracle travels to
			 * reject_malformed_usage()), and it is deliberately a no-op
			 * on BOM-less bodies.
			 */
			$body = SseFrameBuffer::strip_stream_prefix( $body );

			/*
			 * GLM7 #9: ONE decode per flavor. reject_malformed_usage()'s
			 * getData(), its raw-body oracle, and the SDK parent parser's
			 * own uncached getData() (vendor Response::getData()
			 * re-decodes per call) each paid a full-body json_decode —
			 * three where master paid one. The associative decode here
			 * replicates getData() exactly (empty/invalid bodies and
			 * non-array JSON yield null) and travels to the parent through
			 * the pre-decoded Response the streamed path already uses
			 * (GLM6 #14); a body with no decodable payload keeps the
			 * ORIGINAL Response so the parent's own missing-data
			 * rejection fires unchanged.
			 *
			 * GLM5 #3 stands: the usage member is validated BEFORE the SDK
			 * parse — a string/INF member reached the SDK parent's
			 * int-typed TokenUsage constructor unvalidated (the shared
			 * validator was wired into the Anthropic transports only) and
			 * detonated as a raw strict-types TypeError, surfaced by the
			 * mapper's catch-all as the generic 500 instead of the typed
			 * zai_invalid_response.
			 */
			$data = null;
			if ( '' !== $body ) {
				$decoded = json_decode( $body, true );

				if ( \JSON_ERROR_NONE === \json_last_error() && \is_array( $decoded ) ) {
					$data = $decoded;
				}
			}

			$raw = json_decode( $body );

			$this->reject_malformed_usage( $data, $raw );

			return $this->parseNonStreamBody(
				null !== $data
					? new PreDecodedResponse( $response->getStatusCode(), $data )
					: $response
			);
		}

		$aggregator = new SseAggregator();
		$aggregator->feed( $body );
		$aggregator->finish();

		$aggregated = $aggregator->aggregated();

		/*
		 * GLM7 #1: the merge raises this flag when a chunk choice or
		 * tool-call delta carried an index it could not identify soundly
		 * (missing, null, or non-integer) — the check runs AFTER
		 * aggregated() because aggregation itself raises the flag, and
		 * BEFORE the no-usable-event check so the corruption is named
		 * rather than masked by the generic empty-stream message. Parity
		 * with the Anthropic twin's malformed-event channel.
		 */
		if ( $aggregator->has_malformed_event() ) {
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'The chat-completions stream contained a malformed chunk event.'
			);
		}

		if ( null === $aggregated ) {
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'No usable chat.completion.chunk event was received.'
			);
		}

		if ( \array_key_exists( 'usage', $aggregated ) ) {
			/*
			 * GLM5 #3 (streamed half): validated BEFORE the re-encode
			 * below — an INF member makes wp_json_encode() return false,
			 * collapsing the consolidated body to '' so the failure
			 * surfaced as 'The chat-completions payload was malformed.',
			 * masking the real cause. The aggregator hands the usage
			 * member's RAW shape along (verifier round: the associative
			 * merge could not distinguish {} from [], so a streamed
			 * "usage":[] was tolerated where the non-streaming transport
			 * rejects it — the two transports of one provider now decide
			 * through the same oracle).
			 */
			self::reject_bad_usage( $aggregated['usage'], $aggregator->raw_usage() );
		}

		/*
		 * GLM6 #6: the WHOLE consolidated payload is encodability-checked
		 * before it is handed to the parser — the usage check above names
		 * one member, but the aggregator stores finish_reason and
		 * delta.role VERBATIM (only null/isset checks), so a
		 * "finish_reason":1e999 frame decodes to INF and previously made
		 * the re-encode's wp_json_encode() return false, collapsing the
		 * body to '': the SDK parse then failed as the generic 'The
		 * chat-completions payload was malformed.', masking the real
		 * cause (the same masking class GLM5 #3 fixed for usage). The RAW
		 * json_encode() oracle (the GLM3 #4 primitive — JSON strings from
		 * the frame decodes are always valid UTF-8, so INF is the
		 * realistic survivor) rejects the payload typed instead, in this
		 * surface's fixed-message channel.
		 */
		if ( false === json_encode( $aggregated ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM3 #4 verifier round).
			throw ResponseException::fromInvalidData(
				'z.ai',
				'stream',
				'The consolidated stream payload carried a value that cannot be JSON-encoded.'
			);
		}

		/*
		 * GLM6 #14: the aggregator's payload is already decoded — handing
		 * it to the parser through the pre-decoded Response removes the
		 * wp_json_encode() into a synthetic body plus getData()'s full
		 * re-decode (one extra whole-payload serialization pass each way
		 * per streamed generation), the exact round trip this branch
		 * deleted on the Anthropic twin (GLM2 #10). The shapes are
		 * identical by construction: the payload is built from associative
		 * frame decodes, which is what getData() would return.
		 */
		return $this->parseNonStreamBody(
			new PreDecodedResponse( $response->getStatusCode(), $aggregated )
		);
	}

	/**
	 * Rejects a malformed usage member on a non-streaming response before
	 * the SDK parse (GLM5 #3).
	 *
	 * GLM7 #9: takes the ALREADY-DECODED payload and raw oracle from the
	 * caller (one associative and one non-associative decode per body,
	 * shared with the pre-decoded hand-off) instead of re-reading the
	 * Response — vendor Response::getData() re-decodes per call.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $data The associatively decoded body (null when undecodable).
	 * @param mixed      $raw  The non-associative decode of the same body.
	 * @return void
	 * @throws ResponseException When the usage member is malformed.
	 */
	private function reject_malformed_usage( $data, $raw ): void {
		if ( ! \is_array( $data ) || ! \array_key_exists( 'usage', $data ) ) {
			return;
		}

		self::reject_bad_usage(
			$data['usage'],
			$raw instanceof \stdClass && \property_exists( $raw, 'usage' ) ? $raw->usage : null
		);
	}

	/**
	 * Rejects a malformed chat.completions usage member (GLM5 #3).
	 *
	 * One rejection channel for both transports of the surface, built on
	 * the shared UsageValidator with the OpenAI member list — the same
	 * source the Anthropic transports validate through, so a usage-rule
	 * change can never land on one surface only.
	 *
	 * GLM7 #8: this surface validates in the validator's LENIENT mode —
	 * master read token counts with ($usage['prompt_tokens'] ?? 0), so
	 * "usage":null, "usage":[], and explicitly-null members produced
	 * successful zero-defaulted generations here before the shared
	 * validator existed; the strict mode remains the Anthropic surface's
	 * (and every genuinely corrupt shape — scalars, non-empty lists,
	 * non-int counts — still rejects on both).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $usage     The associatively decoded usage member.
	 * @param mixed $raw_usage The same member from a non-associative
	 *                         decode, or null when unavailable.
	 * @return void
	 * @throws ResponseException When the usage member is malformed.
	 */
	private static function reject_bad_usage( $usage, $raw_usage ): void {
		$reason = UsageValidator::failure_reason( $usage, $raw_usage, UsageValidator::OPENAI_MEMBERS, true );

		if ( null !== $reason ) {
			throw ResponseException::fromInvalidData(
				'z.ai',
				'usage',
				UsageValidator::message_for_reason( $reason ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
			);
		}
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
	 * Parses one tool call with JSON object-ness preserved at every level
	 * and replayability enforced.
	 *
	 * The SDK parent decodes the wire arguments string ASSOCIATIVELY, which
	 * collapses {} and [] into the same empty PHP array and turns a
	 * numeric-keyed JSON object ({"0":"x"}) into a list — information the
	 * outbound replay (the parent's getMessagePartToolCallData()
	 * json_encode()) could not recover, so a nested empty or
	 * numeric-keyed object silently re-encoded as a JSON list on the next
	 * request of the conversation (code-review GLM5 #1: the GLM1 #2 fix
	 * existed on the zai_anthropic surface only). The arguments string is
	 * re-decoded NON-associatively here and run through the same shared
	 * Support\ToolArgsObjectNess walk that surface uses, so a tool call
	 * parsed by either surface carries — and replays with — identical
	 * shapes. Root values that are not JSON objects (scalars, the empty
	 * list) and pre-decoded (non-string) arguments keep the SDK parent's
	 * semantics untouched; a NON-EMPTY list root runs the same walk so its
	 * nested object shapes survive replay too (GLM6 #2 — the corruption
	 * class one level down).
	 *
	 * GLM5 #2 then applies the shared Support\ToolArgsReplayGuard to the
	 * final arguments value of EVERY path: 1e999 decodes to INF and an
	 * integer beyond PHP_INT_MAX to a lossy float, and the parent's
	 * outbound mapper json_encodes them with plain json_encode() —
	 * returning false — so the wire carried "arguments": false on every
	 * later request of the conversation instead of the typed rejection the
	 * zai_anthropic surface gives the identical payload (GLM4 #2's
	 * conversation-poisoning class, fixed there only).
	 *
	 * GLM6 #1 rejects arguments strings that fail JSON DECODE outright: the
	 * decode failure used to leave the parent's null-args call standing
	 * (the replay guard tolerates null), fabricating a no-argument tool
	 * call from a truncated or fragment-losing stream.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $tool_call_data The tool call (associative decode).
	 * @return \WordPress\AiClient\Messages\DTO\MessagePart|null The tool-call part, or null
	 *                                              (the SDK caller then rejects the
	 *                                              unexpected type).
	 * @throws ResponseException When the arguments cannot replay onto the wire.
	 */
	protected function parseResponseChoiceMessageToolCallPart( array $tool_call_data ): ?MessagePart { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-mandated override name.
		$part = parent::parseResponseChoiceMessageToolCallPart( $tool_call_data );

		if ( null === $part || null === $part->getFunctionCall() ) {
			return $part;
		}

		$function_call = $part->getFunctionCall();
		$args          = $function_call->getArgs();

		$raw_arguments = $tool_call_data['function']['arguments'] ?? null;

		if ( \is_string( $raw_arguments ) ) {
			$raw = json_decode( $raw_arguments );

			if ( '' !== $raw_arguments && null === $raw && \JSON_ERROR_NONE !== \json_last_error() ) {
				/*
				 * GLM6 #1: a decode failure left the SDK parent's null-args
				 * call standing — the replay guard passes null (it encodes
				 * fine), so a streamed response that lost one arguments
				 * fragment (gateway drops/reorders a delta, so the
				 * concatenated string is invalid JSON) or a non-streaming
				 * body carrying a truncated arguments string SUCCEEDED with
				 * finish reason toolCalls and getArgs() null: a consumer
				 * could execute a possibly side-effecting tool with no
				 * arguments the model never produced. Substituting {} would
				 * fabricate a no-argument call the same way (the corruption
				 * class the zai_anthropic twin's Codex R1/R7 rejections
				 * exist to stop), so the response fails typed instead.
				 *
				 * Verifier round: the EMPTY string keeps the parent's
				 * null-args semantics (excluded above) — the streamed
				 * aggregator initializes arguments to '' and only appends
				 * fragments, so a legitimate zero-argument streamed call
				 * structurally consolidates to '' (it cannot produce the
				 * literal "null" string); a non-streaming "arguments": ""
				 * is the same legal zero-arg shape. A literal "null" string
				 * decodes cleanly and keeps the parent's semantics too.
				 */
				throw ResponseException::fromInvalidData(
					'z.ai',
					'tool_calls',
					'A tool call carried an arguments string that is not valid JSON.'
				);
			}

			if ( $raw instanceof \stdClass || \is_array( $raw ) ) {
				/*
				 * GLM5 #1: preserve nested object-ness (see the docblock).
				 *
				 * GLM6 #2: the walk also covers LIST-rooted arguments — a
				 * JSON list root kept the SDK parent's associative decode,
				 * whose NESTED empty and numeric-keyed objects re-encoded
				 * as JSON lists on every later replay: the same corruption
				 * class one level down. The walk preserves those nested
				 * shapes while the root itself stays a list (an empty list
				 * decodes identically on both paths, so nothing shifts).
				 */
				$args = ToolArgsObjectNess::from_raw( $raw );
			}
		}

		// GLM5 #2: a value the outbound replay cannot losslessly re-encode
		// must not become a generation — it would poison every later
		// request of the conversation (see the docblock).
		if ( ! ToolArgsReplayGuard::is_replayable( $args ) ) {
			throw ResponseException::fromInvalidData(
				'z.ai',
				'tool_calls',
				'A tool call carried arguments that cannot be replayed (an unencodable or precision-loss value was decoded).'
			);
		}

		if ( $args === $function_call->getArgs() ) {
			// No object-ness substitution happened: the parent's part stands.
			return $part;
		}

		return new MessagePart(
			new FunctionCall( $function_call->getId(), $function_call->getName(), $args )
		);
	}

	/**
	 * Guards one OUTBOUND tool call against unreplayable arguments.
	 *
	 * Verifier round on GLM5 #2: the replay guard ran on INBOUND-parsed
	 * tool calls only — CALLER-supplied FunctionCall arguments (a tool
	 * loop feeding back a computed value) still traveled through the SDK
	 * parent's getMessagePartToolCallData(), whose plain json_encode()
	 * returns false for an unencodable value (INF/NAN, invalid UTF-8) and
	 * silently shipped "arguments": false on a generation that then
	 * SUCCEEDED — the exact conversation-poisoning class the guard exists
	 * to close, and one the zai_anthropic twin rejects typed before
	 * transport (message_part_block() → JsonEncodeGuard). The same shared
	 * ToolArgsReplayGuard now judges the outbound arguments of every
	 * replayed call, in the same typed pre-transport channel.
	 *
	 * @since 0.2.0
	 *
	 * @param MessagePart $part The message part to map.
	 * @return array<string, mixed>|null The tool-call data, or null when the part carries none.
	 * @throws InvalidArgumentException When the tool arguments cannot replay onto the wire.
	 */
	protected function getMessagePartToolCallData( MessagePart $part ): ?array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK-mandated override name.
		$data = parent::getMessagePartToolCallData( $part );

		if ( null === $data || null === $part->getFunctionCall() ) {
			return $data;
		}

		if ( ! ToolArgsReplayGuard::is_replayable( $part->getFunctionCall()->getArgs() ) ) {
			throw new InvalidArgumentException(
				'The zai provider could not replay tool call arguments (an unencodable or precision-loss value was given).'
			);
		}

		return $data;
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

		/*
		 * GLM1 #11: the unsupported-option rejection is shared with the
		 * zai_anthropic surface (was a verbatim twin, one label apart).
		 */
		AdvertisedOptionGuard::reject_unsupported( $config->toArray(), 'z.ai' );

		/*
		 * GLM2 #9: the five usage rejections the two surfaces advertise
		 * IDENTICALLY (candidateCount, text-only output modalities, the
		 * MIME whitelist, text-only input, custom options) are shared too
		 * — they were verbatim twins directly under this call, the exact
		 * duplication pattern the guard above was extracted to stop.
		 */
		AdvertisedUsageGuard::reject_unsupported( $config, $prompt, 'z.ai' );

		/*
		 * GLM6 #5: every caller-authored WIRE value the SDK parent's
		 * request mapping ships verbatim is encodability-guarded here —
		 * the GLM3 #4/GLM4 #1 guards existed on the zai_anthropic surface
		 * only, so an invalid-UTF-8 (or NAN-bearing) text part or system
		 * instruction detonated in the transport's whole-request
		 * json_encode(..., JSON_THROW_ON_ERROR) as an untyped
		 * JsonException, surfaced by the mapper's catch-all as the
		 * generic 500 instead of this typed pre-transport 400. This
		 * surface's mapping lives in the SDK parent (no per-site hooks
		 * without duplicating it), so the walk runs once over the prompt
		 * and configuration — every value the parent copies to the wire
		 * unvalidated passes the same shared oracle the twin applies at
		 * its mapping sites. Tool-call ARGUMENTS need no entry here: the
		 * outbound replay guard below already rejects unencodable ones
		 * typed.
		 */
		$this->guard_wire_values( $prompt );
	}

	/**
	 * Rejects unencodable caller-authored wire values before transport
	 * (GLM6 #5).
	 *
	 * The zai surface's request mapping is the SDK parent's
	 * (prepareGenerateTextParams()), which copies the caller's strings and
	 * tool-result values into the request params with at most type juggling
	 * — never an encodability check — and the parent's tool-response
	 * serialization uses plain json_encode(), whose failure string-casts to
	 * 'content': false, telling the model the tool returned no output. One
	 * walk over the prompt and the model configuration guards every such
	 * value through the shared JsonEncodeGuard oracle (the same one the
	 * zai_anthropic twin applies at its mapping sites), first-bad-wins,
	 * before any request build or transport work.
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return void
	 * @throws InvalidArgumentException When a wire value cannot encode.
	 */
	private function guard_wire_values( array $prompt ): void {
		$config = $this->getConfig();

		$system_instruction = $config->getSystemInstruction();
		if ( \is_string( $system_instruction ) && '' !== $system_instruction ) {
			JsonEncodeGuard::must_encode( $system_instruction, 'the system instruction', 'zai' );
		}

		/*
		 * Verifier round on GLM6 #5: the SDK parent ships the sampling
		 * floats and the response-format schema verbatim too — a NAN
		 * temperature/top_p or an unencodable outputSchema member reached
		 * the transport's whole-request encode as the untyped
		 * JsonException (generic 500), the exact divergence class the
		 * walk exists to close (the zai_anthropic twin guards the schema
		 * and rejects NAN temperature through its range checks).
		 */
		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			JsonEncodeGuard::must_encode( $temperature, 'the temperature option', 'zai' );
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			JsonEncodeGuard::must_encode( $top_p, 'the top_p option', 'zai' );
		}

		$output_schema = $config->getOutputSchema();
		if ( \is_array( $output_schema ) ) {
			JsonEncodeGuard::must_encode( $output_schema, 'the configured output schema', 'zai' );
		}

		$stop_sequences = $config->getStopSequences();
		if ( \is_array( $stop_sequences ) ) {
			/*
			 * GLM7 #7: encodability alone passed non-string and empty
			 * entries verbatim ([''] and [0] encode fine, and the SDK
			 * setter checks only list-ness), shipping "stop":[""] /
			 * "stop":[0] upstream to a 400 with the generic misattributed
			 * client-error message. Per-entry validation first, then the
			 * encodability oracle — the zai_anthropic twin's GLM3 #3
			 * contract.
			 */
			foreach ( $stop_sequences as $sequence ) {
				if ( ! \is_string( $sequence ) || '' === $sequence ) {
					throw new InvalidArgumentException(
						'The zai provider requires every stop sequence to be a non-empty string.'
					);
				}

				JsonEncodeGuard::must_encode( $sequence, 'a stop sequence', 'zai' );
			}
		}

		$function_declarations = $config->getFunctionDeclarations();
		if ( \is_array( $function_declarations ) ) {
			foreach ( $function_declarations as $declaration ) {
				JsonEncodeGuard::must_encode( $declaration->getName(), 'a declared tool function name', 'zai' );
				JsonEncodeGuard::must_encode( $declaration->getDescription(), 'a declared tool function description', 'zai' );

				$input_schema = $declaration->getParameters();
				if ( \is_array( $input_schema ) ) {
					JsonEncodeGuard::must_encode( $input_schema, 'a declared tool parameter schema', 'zai' );
				}
			}
		}

		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isText() && ! $part->getChannel()->isThought() ) {
					// Only visible text ships (the parent drops thought
					// parts, so guarding them would over-reject).
					JsonEncodeGuard::must_encode( (string) $part->getText(), 'a message text part', 'zai' );
					continue;
				}

				if ( $part->getType()->isFunctionResponse() && null !== $part->getFunctionResponse() ) {
					/*
					 * The parent's message mapping json_encodes the tool
					 * response with plain json_encode() and string-casts
					 * the failure — an unencodable result silently shipped
					 * as "content": false (the R18 corruption class the
					 * zai_anthropic surface fixed, with the identical
					 * guard).
					 */
					JsonEncodeGuard::must_encode( $part->getFunctionResponse()->getResponse(), 'a tool result', 'zai' );
					continue;
				}

				if ( $part->getType()->isFunctionCall() && null !== $part->getFunctionCall() ) {
					/*
					 * The id and name ride the wire verbatim inside the
					 * tool_calls member; the ARGUMENTS are guarded by the
					 * replay guard in getMessagePartToolCallData().
					 *
					 * GLM7 #7: the (string) casts let a NULL id or name
					 * pass the encodability guard while null itself rode
					 * the wire (the SDK parent copies it unvalidated) —
					 * now the typed non-empty rejection the zai_anthropic
					 * twin's Codex R9 #3 gives the identical shape, then
					 * the encodability oracle on the real string.
					 */
					$function_call = $part->getFunctionCall();

					if ( null === $function_call->getId() || '' === $function_call->getId()
						|| null === $function_call->getName() || '' === $function_call->getName() ) {
						throw new InvalidArgumentException(
							'The zai provider requires every function-call part to carry a non-empty id and name.'
						);
					}

					JsonEncodeGuard::must_encode( $function_call->getId(), 'a tool call id', 'zai' );
					JsonEncodeGuard::must_encode( $function_call->getName(), 'a tool call name', 'zai' );
				}
			}
		}
	}
}
