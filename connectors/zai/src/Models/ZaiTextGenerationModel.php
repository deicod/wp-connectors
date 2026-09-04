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
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard;
use Deicod\WpConnectors\Zai\Support\AdvertisedUsageGuard;
use Deicod\WpConnectors\Zai\Support\EventStreamSniff;
use Deicod\WpConnectors\Zai\Support\JsonBodyDecoder;
use Deicod\WpConnectors\Zai\Support\JsonEncodeGuard;
use Deicod\WpConnectors\Zai\Support\PreDecodedResponse;
use Deicod\WpConnectors\Zai\Support\SafeGenerationBoundary;
use Deicod\WpConnectors\Zai\Support\SseFrameBuffer;
use Deicod\WpConnectors\Zai\Support\ThrowsSafeHttpErrors;
use Deicod\WpConnectors\Zai\Support\SseAggregator;
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
	use SafeGenerationBoundary;

	/**
	 * The per-surface provider label interpolated into every guard and
	 * rejection message (GLM10 #9).
	 *
	 * The label was a bare string literal at ~25 call sites that mixed
	 * 'z.ai' (the advertised guards, the ResponseException labels) and
	 * 'zai' (the JsonEncodeGuard sites) within this one surface, so the
	 * surface's user-facing rejections named the provider two different
	 * ways. One constant — ridden on the availability owner's
	 * REFUSAL_LABEL, the same identity the credential-refusal messages
	 * carry — makes every message name it exactly one way.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PROVIDER_LABEL = ZaiProviderAvailability::REFUSAL_LABEL;

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
	 * Prepares the request parameters after refusing refused credentials,
	 * rejecting unsupported input, and omitting explicitly-cleared list
	 * options (GLM12 #4).
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

		$params = parent::prepareGenerateTextParams( $prompt );

		/*
		 * GLM12 #4 (parity with the zai_anthropic twin's GLM1 #4): an
		 * explicitly-cleared list option ([] — the SDK setters are
		 * non-nullable, so [] is the only clear value, and
		 * PromptBuilder::usingFunctionDeclarations() with zero arguments
		 * reaches it too) means "not set", not an empty wire member. The
		 * SDK parent guards only tool_calls against empty arrays and
		 * ships "stop":[] / "tools":[] verbatim, where the spec-faithful
		 * reading (OpenAI documents both lists with min length 1)
		 * rejects the request with the generic misattributed 400 — the
		 * same request succeeded on the twin, which omits both when
		 * empty. Only the two list members the caller can clear to []
		 * are treated; every other member keeps the parent's mapping
		 * verbatim.
		 */
		foreach ( array( 'stop', 'tools' ) as $list_member ) {
			if ( \array_key_exists( $list_member, $params ) && \is_array( $params[ $list_member ] ) && array() === $params[ $list_member ] ) {
				unset( $params[ $list_member ] );
			}
		}

		return $params;
	}

	/**
	 * The availability instance whose credential gate generation consults
	 * (GLM9 #11 wiring hook — see the SafeGenerationBoundary trait).
	 *
	 * @since 0.2.0
	 *
	 * @return AbstractZaiProviderAvailability
	 */
	protected function credential_gate_availability(): AbstractZaiProviderAvailability {
		return new ZaiProviderAvailability();
	}

	/**
	 * The authentication the credential gate judges: this model's own SDK
	 * getter for the authentication it would authenticate with (an
	 * unwired model skips the gate, keeping the pre-gate exception
	 * order — the GLM1 #1 verifier nit; GLM9 #11 wiring hook).
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	protected function gate_authentication(): RequestAuthenticationInterface {
		return $this->getRequestAuthentication();
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
			 *
			 * GLM10 #11: the decode block itself — the strip, the
			 * associative view, the raw object-ness view, the vendor
			 * null normalization — rides the one shared JsonBodyDecoder
			 * with the zai_anthropic twin's parse_body_string(); the
			 * GLM7 #9 one-decode-per-flavor contract is unchanged, and a
			 * body with no decodable payload keeps the ORIGINAL Response
			 * so the parent's own missing-data rejection fires unchanged.
			 */
			list( $data, $raw ) = JsonBodyDecoder::decode( $body );

			/*
			 * GLM5 #3 stands: the usage member is validated BEFORE the SDK
			 * parse — a string/INF member reached the SDK parent's
			 * int-typed TokenUsage constructor unvalidated (the shared
			 * validator was wired into the Anthropic transports only) and
			 * detonated as a raw strict-types TypeError, surfaced by the
			 * mapper's catch-all as the generic 500 instead of the typed
			 * zai_invalid_response.
			 */
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
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'stream',
				'The chat-completions stream contained a malformed chunk event.'
			);
		}

		if ( null === $aggregated ) {
			/*
			 * GLM12 #3 (the glm8-5 mechanism, ported from the
			 * zai_anthropic twin): the sniff trusts a
			 * text/event-stream Content-Type before inspecting the body
			 * (the documented precedence — the body sniff is the fallback
			 * for mangled/omitted headers, not a veto of the header), so
			 * a doubly-nonconforming gateway that labels a VALID JSON
			 * chat.completion body with the stream header routed the body
			 * here — one JSON object, no data: field line, nothing to
			 * aggregate — and a byte-identical response succeeded on the
			 * twin. A stream whose aggregation produced nothing usable is
			 * exactly the signal the label may have lied: before
			 * surfacing the stream verdict, try the JSON the label
			 * promised the body wasn't. A body that parses as a complete
			 * chat.completion completes the generation; anything else
			 * keeps the stream-typed error below — the header DID
			 * promise a stream.
			 */
			$fallback = $this->json_fallback_result( $response );

			if ( null !== $fallback ) {
				return $fallback;
			}

			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
		 *
		 * GLM10 #10: the oracle is the GLM9 #13 structural fast path
		 * (ToolArgsReplayGuard::is_replayable_decoded) — the payload is
		 * 100% json_decode output and guarded constants, so the walker
		 * decides identically at O(tree) with zero serialization: INF at
		 * any depth (the pinned 1e999 finish_reason case) rejects exactly
		 * like a failed encode, with no whole-payload string allocation
		 * per streamed generation. Strict-superset residual, disclosed:
		 * the walker ALSO flags finite integral floats beyond
		 * PHP_INT_MAX (a wire integer the platform int could not hold),
		 * which json_encode() accepts — such values only reach
		 * finish_reason/delta.role here (usage is validated above) and
		 * the SDK's is_string gates reject both downstream either way.
		 */
		if ( ! ToolArgsReplayGuard::is_replayable_decoded( $aggregated ) ) {
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'response',
				'The chat-completions payload was malformed.'
			);
		}
	}

	/**
	 * The JSON fallback for a body the Content-Type mislabeled as a stream
	 * (GLM12 #3 — the glm8-5 mechanism ported from the zai_anthropic
	 * twin), or null when the body is no chat.completion payload.
	 *
	 * Runs only AFTER SSE aggregation produced nothing usable (the
	 * caller's no-usable-event channel), so a genuinely-decodable stream
	 * never re-routes: only a body that decodes as a JSON OBJECT is even
	 * attempted, and only a full non-streaming parse — the same decode,
	 * usage validation, and SDK parse the unlabeled JSON path runs —
	 * succeeds. A ResponseException from that parse (a JSON object, but
	 * not a valid chat.completion body) returns null so the caller
	 * surfaces its stream-typed error; the header DID promise a stream.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The stream-labeled response.
	 * @return GenerativeAiResult|null The parsed result, or null when the
	 *                                 body is not a chat.completion payload.
	 */
	private function json_fallback_result( Response $response ): ?GenerativeAiResult {
		$body = (string) $response->getBody();

		if ( ! \is_object( json_decode( SseFrameBuffer::strip_stream_prefix( $body ) ) ) ) {
			return null;
		}

		try {
			list( $data, $raw ) = JsonBodyDecoder::decode( $body );

			$this->reject_malformed_usage( $data, $raw );

			return $this->parseNonStreamBody(
				null !== $data
					? new PreDecodedResponse( $response->getStatusCode(), $data )
					: $response
			);
		} catch ( ResponseException $e ) {
			return null;
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
					self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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

		/*
		 * GLM5 #2: a value the outbound replay cannot losslessly re-encode
		 * must not become a generation — it would poison every later
		 * request of the conversation (see the docblock). GLM9 #13: the
		 * decoded fast path — every construction of $args above is a
		 * json_decode() product (the SDK parent's associative decode or
		 * ToolArgsObjectNess::from_raw()), so the structural walker
		 * alone decides, no serialization round trip.
		 */
		if ( ! ToolArgsReplayGuard::is_replayable_decoded( $args ) ) {
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
		AdvertisedOptionGuard::reject_unsupported( $config->toArray(), self::PROVIDER_LABEL );

		/*
		 * GLM2 #9: the five usage rejections the two surfaces advertise
		 * IDENTICALLY (candidateCount, text-only output modalities, the
		 * MIME whitelist, text-only input, custom options) are shared too
		 * — they were verbatim twins directly under this call, the exact
		 * duplication pattern the guard above was extracted to stop.
		 */
		AdvertisedUsageGuard::reject_unsupported( $config, $prompt, self::PROVIDER_LABEL );

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
			JsonEncodeGuard::must_encode( $system_instruction, 'the system instruction', self::PROVIDER_LABEL );
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
			JsonEncodeGuard::must_encode( $temperature, 'the temperature option', self::PROVIDER_LABEL );
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			JsonEncodeGuard::must_encode( $top_p, 'the top_p option', self::PROVIDER_LABEL );
		}

		$output_schema = $config->getOutputSchema();
		if ( \is_array( $output_schema ) ) {
			JsonEncodeGuard::must_encode( $output_schema, 'the configured output schema', self::PROVIDER_LABEL );
		}

		$stop_sequences = $config->getStopSequences();
		if ( \is_array( $stop_sequences ) ) {
			/*
			 * GLM9 #12: the per-entry rule (GLM7 #7 — encodability alone
			 * passed non-string and empty entries verbatim, shipping
			 * "stop":[""] / "stop":[0] upstream to the misattributed 400)
			 * rides the shared JsonEncodeGuard now, the same guard the
			 * zai_anthropic twin's GLM3 #3 contract lives in — the twin
			 * loops this extraction replaced had already drifted once.
			 */
			JsonEncodeGuard::must_encode_stop_sequences( $stop_sequences, self::PROVIDER_LABEL );
		}

		$function_declarations = $config->getFunctionDeclarations();
		if ( \is_array( $function_declarations ) ) {
			foreach ( $function_declarations as $declaration ) {
				/*
				 * GLM12 #5 (parity with the zai_anthropic twin's Codex
				 * R18 #2 rule): a declared tool with an EMPTY name is a
				 * malformed identity — the encodability check below
				 * passes it (json_encode('') succeeds), the SDK parent
				 * ships it verbatim inside tools[].function, and the
				 * spec-faithful endpoint rejects it (name must be 1-64
				 * chars) as the generic misattributed 'rejected the
				 * request' 400 — the exact misattributed-error class
				 * GLM9 #4 fixed for tool-result ids. The DTO constructor
				 * coerces the name to a string, so '' is the only
				 * constructible empty identity; identity errors surface
				 * BEFORE the encodability checks, matching the twin's
				 * ordering.
				 */
				$name = $declaration->getName();

				if ( '' === $name ) {
					throw new InvalidArgumentException(
						'The zai provider requires declared tool functions to carry a non-empty name.'
					);
				}

				JsonEncodeGuard::must_encode( $name, 'a declared tool function name', self::PROVIDER_LABEL );
				JsonEncodeGuard::must_encode( $declaration->getDescription(), 'a declared tool function description', self::PROVIDER_LABEL );

				$input_schema = $declaration->getParameters();
				if ( \is_array( $input_schema ) ) {
					JsonEncodeGuard::must_encode( $input_schema, 'a declared tool parameter schema', self::PROVIDER_LABEL );
				}
			}
		}

		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isText() && ! $part->getChannel()->isThought() ) {
					// Only visible text ships (the parent drops thought
					// parts, so guarding them would over-reject).
					JsonEncodeGuard::must_encode( (string) $part->getText(), 'a message text part', self::PROVIDER_LABEL );
					continue;
				}

				if ( $part->getType()->isFunctionResponse() && null !== $part->getFunctionResponse() ) {
					/*
					 * GLM9 #4: the id ships as "tool_call_id" verbatim
					 * (the SDK parent's message mapping copies it
					 * unvalidated), so a null or empty id rode the wire
					 * to an upstream 400 surfaced as the generic
					 * 'rejected the request' message — where the
					 * zai_anthropic twin and this same walk's
					 * FunctionCall ids give the precise typed
					 * pre-transport rejection for the identical shape.
					 * GLM9 #12: the identity rule rides the shared
					 * JsonEncodeGuard.
					 */
					JsonEncodeGuard::must_encode_tool_result_identity( $part->getFunctionResponse(), 'tool call id', 'a tool result id', self::PROVIDER_LABEL );

					/*
					 * The parent's message mapping json_encodes the tool
					 * response with plain json_encode() and string-casts
					 * the failure — an unencodable result silently shipped
					 * as "content": false (the R18 corruption class the
					 * zai_anthropic surface fixed, with the identical
					 * guard).
					 */
					JsonEncodeGuard::must_encode( $part->getFunctionResponse()->getResponse(), 'a tool result', self::PROVIDER_LABEL );
					continue;
				}

				if ( $part->getType()->isFunctionCall() && null !== $part->getFunctionCall() ) {
					/*
					 * The id and name ride the wire verbatim inside the
					 * tool_calls member; the ARGUMENTS are guarded by the
					 * replay guard in getMessagePartToolCallData().
					 * GLM9 #12: the GLM7 #7 identity rule (the (string)
					 * casts let a NULL id or name ride the wire
					 * unvalidated) rides the shared JsonEncodeGuard —
					 * the same one the zai_anthropic twin's Codex R9 #3
					 * contract lives in.
					 */
					JsonEncodeGuard::must_encode_tool_call_identity( $part->getFunctionCall(), self::PROVIDER_LABEL );
				}
			}
		}
	}
}
