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
use Deicod\WpConnectors\Zai\Support\FixedMessageResponseException;
use Deicod\WpConnectors\Zai\Support\JsonBodyDecoder;
use Deicod\WpConnectors\Zai\Support\JsonShape;
use Deicod\WpConnectors\Zai\Support\JsonEncodeGuard;
use Deicod\WpConnectors\Zai\Support\PreDecodedResponse;
use Deicod\WpConnectors\Zai\Support\ReplayValidatedFunctionCall;
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
	 * The prompt the CURRENT in-flight request was prepared from —
	 * captured in prepareGenerateTextParams() (glm13-11) so the
	 * encodability attribution walk can name the first bad member when
	 * the createRequest() chokepoint's whole-payload net fails.
	 *
	 * @since 0.2.0
	 *
	 * @var array|null
	 */
	private $generation_prompt = null;

	/**
	 * Builds the request against the CURRENT plan/region endpoint.
	 *
	 * The option read happens here, at request-build time — never at
	 * construction time — so a settings change retargets the very next
	 * request without rebuilding the registry (Task 1.3).
	 *
	 * GLM12 #7: this is the CHOKEPOINT every outbound request passes with
	 * its FULLY ASSEMBLED $data params, so the encodability net lives
	 * here once — see guard_assembled_params(). The typed per-family
	 * walk (guard_wire_values()) still runs first at the params hook and
	 * keeps the precise per-member messages; this net auto-covers every
	 * member the parent forwards that the typed walk has not been told
	 * about yet.
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
		if ( \is_array( $data ) ) {
			$data = $this->guard_assembled_params( $data, $method, $headers );
		}

		$endpoint = ZaiEndpoint::for_current_settings();

		/*
		 * glm13-6: the request-time endpoint capture — see
		 * SafeGenerationBoundary::capture_generation_endpoint(). Every
		 * createRequest() re-captures before the send that follows it.
		 */
		$this->capture_generation_endpoint( $endpoint->cache_key() );

		return new Request(
			$method,
			$endpoint->api_url( $path ),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}

	/**
	 * Rejects an unassemblable request payload at the chokepoint
	 * (GLM12 #7).
	 *
	 * The typed walk (guard_wire_values()) hand-enumerates the
	 * SDK-forwarded member families so its rejections can name the
	 * member — a mirror of SDK mapping knowledge that must be
	 * re-extended in lockstep with every SDK release, and the lockstep
	 * already broke once inside this PR (the GLM6 #5 verifier round had
	 * to add temperature/top_p/outputSchema after the initial landing).
	 * The advertised-option layer covers the members it knows too, but
	 * BOTH layers must learn each new forwarded member by name. This one
	 * oracle over the assembled $data closes the class for every member
	 * that REACHES $data verbatim: any value in ANY forwarded member —
	 * known, forgotten, or added by a future SDK release — that the
	 * transport's whole-request encode would fail on (NAN, INF, invalid
	 * UTF-8, recursion) rejects HERE as the typed pre-transport 400
	 * instead of the untyped JsonException surfaced by the mapper's
	 * catch-all as the generic 500.
	 *
	 * glm14-3, the honest boundary: the net sees only what assembly
	 * PRODUCED. Values the parent PRE-ENCODES with a plain json_encode
	 * and string-casts on failure (a failed encode launders into a
	 * literal false the net happily passes) or DROPS conditionally never
	 * reach $data and are structurally invisible here — they keep their
	 * own eager guards in the typed walk (see
	 * reject_misshapen_wire_values() for the enumeration). The typed
	 * walk's per-member messages degrade to this one generic description
	 * only for members the typed walk does not know — every covered
	 * family still rejects upstream, first-bad-wins, with its precise
	 * message.
	 *
	 * The oracle is the shared JsonEncodeGuard's RAW json_encode (the
	 * GLM3 #4 primitive): the assembled $data is CALLER-built (not a
	 * json_decode product), so the zero-serialization decoded walker is
	 * not sound here — NAN, invalid UTF-8, and recursion are exactly the
	 * caller-value hazards the walker cannot see.
	 *
	 * glm14-4: the net's ONE encode is also the request's LAST — when
	 * this request carries a JSON body (a body-carrying method with a
	 * JSON Content-Type, Request::getBody()'s own branch), the encoded
	 * string is returned and handed to the Request as its $data: Request
	 * stores a string as the RAW body and getBody() returns it as-is, so
	 * the transport's send-time json_encode (the second whole-payload
	 * serialization every zai generation paid end-to-end) is gone. The
	 * wire bytes are unchanged — the same encoder, no flags, the same
	 * depth, byte-identical to what getBody() would have produced from
	 * the array — and no plugin or vendor consumer reads getData() on
	 * the generation request (the transport consumes getBody() only,
	 * HttpTransporter::convertToPsr7Request). A request that would NOT
	 * have JSON-encoded its data (a GET's query params, a form body)
	 * keeps the array verbatim.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>               $data    The assembled request params.
	 * @param HttpMethodEnum                     $method  The request's HTTP method.
	 * @param array<string, string|list<string>> $headers The request headers.
	 * @return string|array The encoded JSON body when this request carries
	 *                      one, or $data unchanged when it does not.
	 * @throws InvalidArgumentException When any member cannot encode.
	 */
	private function guard_assembled_params( array $data, HttpMethodEnum $method, array $headers ) {
		/*
		 * glm13-11: the happy path is ONE raw encode of the assembled
		 * payload — the per-member encodability pre-pass this chokepoint
		 * used to run eagerly in validate_request() serialized every
		 * member individually (system instruction, every text part, every
		 * tool schema) and then this net serialized the same values again
		 * inside $params, a redundant O(payload) pass on every request of
		 * a tool-loop history. Only when the net FAILS does the
		 * per-member attribution walk run (guard_wire_values(), over the
		 * stashed prompt) to name the first bad member with the precise
		 * message the eager pre-pass used to give; a member the walk does
		 * not know keeps this generic description.
		 */
		$encoded = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM3 #4 verifier round).

		if ( false !== $encoded && self::carries_json_body( $method, $headers ) ) {
			// glm14-4: the ride — this string is the request's body.
			return $encoded;
		}

		if ( false !== $encoded ) {
			// Not a JSON-body request: the array rides as it always did.
			return $data;
		}

		$this->guard_wire_values( \is_array( $this->generation_prompt ) ? $this->generation_prompt : array() );

		JsonEncodeGuard::must_encode( $data, 'a request payload member', self::PROVIDER_LABEL );

		return $data; // Unreachable: must_encode() rejects above.
	}

	/**
	 * Reports whether the request would carry the encoded $data as its
	 * JSON body — Request::getBody()'s own encoding branch, mirrored so
	 * the net's returned string is exactly what getBody() would have
	 * produced (glm14-4).
	 *
	 * @since 0.2.0
	 *
	 * @param HttpMethodEnum                     $method  The request's HTTP method.
	 * @param array<string, string|list<string>> $headers The request headers.
	 * @return bool True when getBody() would JSON-encode the data array.
	 */
	private static function carries_json_body( HttpMethodEnum $method, array $headers ): bool {
		if ( ! $method->hasBody() ) {
			return false;
		}

		$content_type = $headers['Content-Type'] ?? '';

		if ( \is_array( $content_type ) ) {
			$content_type = $content_type[0] ?? '';
		}

		return false !== stripos( (string) $content_type, 'application/json' );
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

		// glm13-11: the attribution walk reads this prompt if the
		// assembled-params encodability net fails at createRequest().
		$this->generation_prompt = $prompt;

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

			$data = self::derive_absent_total_tokens( $data );

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
		 * GLM12 #6 runs on the consolidated payload too: a streamed final
		 * usage frame carrying only prompt+completion hits the same
		 * absent-total defaulting below the stream as the non-streaming
		 * body does.
		 */
		$aggregated = self::derive_absent_total_tokens( $aggregated );

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
			/*
			 * glm13-7: this plugin's own fixed-message rejections pass
			 * through verbatim — the three tool-arguments rejections the
			 * parse hook below throws (GLM6 #1, GLM5 #2, GLM12 #8) are
			 * precise, actionable diagnostics the blanket rewrite ate,
			 * degrading the byte-identical corruption on this surface to
			 * the generic string while the zai_anthropic twin surfaced its
			 * precise message end-to-end. Only the SDK parent's OWN
			 * ResponseExceptions (whose messages can embed upstream body
			 * content, e.g. a non-standard finish_reason) keep the fixed
			 * generic rewrite.
			 */
			if ( $e instanceof FixedMessageResponseException ) {
				throw $e;
			}

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
	 * The plugin's own fixed-message rejections
	 * (FixedMessageResponseException, glm14-2) are the one exception:
	 * they propagate, so a precise tool-arguments diagnostic surfaces
	 * even when the gateway also mislabeled the body.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The stream-labeled response.
	 * @return GenerativeAiResult|null The parsed result, or null when the
	 *                                 body is not a chat.completion payload.
	 * @throws FixedMessageResponseException When the body IS a JSON object
	 *                                       whose parse produced one of this
	 *                                       plugin's precise fixed-message
	 *                                       rejections (glm14-2).
	 */
	private function json_fallback_result( Response $response ): ?GenerativeAiResult {
		$body = (string) $response->getBody();

		if ( ! \is_object( json_decode( SseFrameBuffer::strip_stream_prefix( $body ) ) ) ) {
			return null;
		}

		try {
			list( $data, $raw ) = JsonBodyDecoder::decode( $body );

			$this->reject_malformed_usage( $data, $raw );

			$data = self::derive_absent_total_tokens( $data );

			return $this->parseNonStreamBody(
				null !== $data
					? new PreDecodedResponse( $response->getStatusCode(), $data )
					: $response
			);
		} catch ( FixedMessageResponseException $e ) {
			/*
			 * glm14-2: the precise tool-arguments diagnostics the parse
			 * below throws (glm13-7's marker subclass) surface even on
			 * the fallback path — swallowing them here degraded the
			 * byte-identical corruption to the generic no-usable-event
			 * message whenever the gateway also mislabeled the body,
			 * exactly the degradation parseNonStreamBody's pass-through
			 * exists to prevent. The marker is this plugin's OWN fixed
			 * message; every other ResponseException (the body is a JSON
			 * object but no valid chat.completion payload) still returns
			 * null so the caller surfaces its stream-typed error — the
			 * GLM12 #3 contract.
			 */
			throw $e;
		} catch ( ResponseException $e ) {
			return null;
		}
	}

	/**
	 * Derives an absent total_tokens member from prompt+completion
	 * (GLM12 #6), before the SDK parent's per-member ?? 0 defaulting.
	 *
	 * The lenient validator (GLM7 #8) blesses partial usage objects —
	 * master parity, and deliberately kept — but the SDK parent then
	 * constructs TokenUsage(prompt, completion, total ?? 0), so a body
	 * reporting {"prompt_tokens":500,"completion_tokens":120} answered
	 * totalTokens() with 0 for 620 billed tokens, under-reporting every
	 * total-metering consumer (the live probe's usage-total fact among
	 * them) while the zai_anthropic twin derives its total by summation.
	 * The derivation fills ONLY the absent member (missing, or the
	 * lenient explicit null); a PRESENT total stands verbatim — even 0,
	 * a data-bearing statement from a zero-normalizing gateway — and the
	 * other members keep the absent→0 tolerance untouched. A sum that
	 * would overflow PHP_INT_MAX stays absent (0, master parity) rather
	 * than introducing a rejection this surface never had.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $data The validated, associatively decoded payload.
	 * @return array|null The payload with a derived total_tokens when one
	 *                    was absent (null passes through unchanged).
	 */
	private static function derive_absent_total_tokens( $data ) {
		if ( ! \is_array( $data ) || ! \array_key_exists( 'usage', $data ) || ! \is_array( $data['usage'] ) ) {
			return $data;
		}

		$usage = $data['usage'];

		if ( \array_key_exists( 'total_tokens', $usage ) && null !== $usage['total_tokens'] ) {
			return $data;
		}

		/*
		 * Post-validation (lenient), each known member is a non-negative
		 * int or an explicit null (which counts as absent → 0). The sum
		 * is overflow-checked the way the shared validator's totals are
		 * (GLM4 #5's rule): no intermediate may promote to float.
		 */
		$prompt     = \is_int( $usage['prompt_tokens'] ?? null ) ? $usage['prompt_tokens'] : 0;
		$completion = \is_int( $usage['completion_tokens'] ?? null ) ? $usage['completion_tokens'] : 0;

		if ( $prompt > PHP_INT_MAX - $completion ) {
			return $data;
		}

		$usage['total_tokens'] = $prompt + $completion;
		$data['usage']         = $usage;

		return $data;
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
	 * @throws FixedMessageResponseException When the arguments do not decode
	 *                              or cannot replay onto the wire — the
	 *                              ResponseException subtype whose precise
	 *                              message parseNonStreamBody() passes
	 *                              through (glm13-7).
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
				throw FixedMessageResponseException::fixed(
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
		 *
		 * GLM12 #8 splits the out-of-range-integer half of that rule by
		 * what this hook can see: BOTH zai transports deliver the
		 * arguments as a STRING here, so the PRECISE rule applies — an
		 * integer literal beyond PHP_INT_MAX replays when the platform
		 * decode keeps it EXACT (1e20, 2^63; the old blanket walker
		 * rejection failed these valid generations), while a genuinely
		 * lossy literal (…809 collapsing to the …808 boundary double)
		 * still rejects. The pre-decoded (non-string) member keeps the
		 * conservative walker: post-decode, an exact big float and a
		 * lossy one are indistinguishable, so undecidable rejects.
		 */
		if ( \is_string( $raw_arguments ) ) {
			/*
			 * glm13-10: $raw is the SAME string's decode from the GLM6 #1
			 * branch above — the guard takes it instead of re-decoding
			 * (and re-encoding) a value already in hand and verified
			 * decodable.
			 */
			if ( ! ToolArgsReplayGuard::wire_arguments_are_replayable( $raw_arguments, $raw ) ) {
				throw FixedMessageResponseException::fixed(
					self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
					'tool_calls',
					'A tool call carried arguments that cannot be replayed (an unencodable or precision-loss value was given).'
				);
			}
		} elseif ( ! ToolArgsReplayGuard::is_replayable_decoded( $args ) ) {
			throw FixedMessageResponseException::fixed(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'tool_calls',
				'A tool call carried arguments that cannot be replayed (an unencodable or precision-loss value was decoded).'
			);
		}

		/*
		 * GLM12 #12: every validation above passed, so the part returns
		 * as a STAMPED twin — ReplayValidatedFunctionCall marks the
		 * arguments as replay-validated right here (the wire rule or the
		 * walker ran against exactly this $args), and the outbound
		 * replay guard skips its serializing oracle for stamped calls
		 * instead of re-running two encodes plus a decode per historical
		 * call on every request of the conversation. Functionally the
		 * part is identical (same id, name, and args; the DTO is
		 * immutable), whether or not the object-ness substitution above
		 * replaced the parent's args.
		 */
		return new MessagePart(
			new ReplayValidatedFunctionCall( $function_call->getId(), $function_call->getName(), $args )
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

		$function_call = $part->getFunctionCall();

		/*
		 * GLM12 #12 (stamp-at-acceptance skip): an inbound-accepted call
		 * carries the ReplayValidatedFunctionCall stamp — its arguments
		 * passed the replay rule at parse time, the DTO is immutable,
		 * and the stamp carries the PRECISE GLM12 #8 verdict (an exact
		 * big integer literal the full oracle's conservative walker
		 * below would reject), keeping the parse and replay verdicts in
		 * agreement instead of re-serializing the tree on every request
		 * of the conversation. First-seen CALLER-built calls (plain SDK
		 * instances) keep the full oracle.
		 */
		if ( ! $function_call instanceof ReplayValidatedFunctionCall
			&& ! ToolArgsReplayGuard::is_replayable( $function_call->getArgs() ) ) {
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
		 * request mapping ships verbatim is encodability-guarded before
		 * transport — the GLM3 #4/GLM4 #1 guards existed on the
		 * zai_anthropic surface only, so an invalid-UTF-8 (or
		 * NAN-bearing) text part or system instruction detonated in the
		 * transport's whole-request json_encode(..., JSON_THROW_ON_ERROR)
		 * as an untyped JsonException, surfaced by the mapper's catch-all
		 * as the generic 500 instead of this typed pre-transport 400.
		 * This surface's mapping lives in the SDK parent (no per-site
		 * hooks without duplicating it), so the walk runs once over the
		 * prompt and configuration — every value the parent copies to the
		 * wire unvalidated passes the same shared oracle the twin applies
		 * at its mapping sites. Tool-call ARGUMENTS need no entry here:
		 * the outbound replay guard below already rejects unencodable
		 * ones typed.
		 *
		 * glm13-11: the walk is now the TYPED half — identity and shape
		 * rules whose rejections name the member (empty/duplicate
		 * declared names, list-root output schemas, non-string/empty stop
		 * entries, tool-result and tool-call identity, the tool-result
		 * response's laundered-encode exception). The pure-ENCODABILITY
		 * half of the old walk rides the createRequest() chokepoint's
		 * whole-payload net now, with this walk retained as the
		 * attribution pass on failure (guard_wire_values()).
		 */
		$this->reject_misshapen_wire_values( $prompt );
	}

	/**
	 * Rejects misshapen caller-authored wire VALUES before transport —
	 * the TYPED half of the GLM6 #5 walk (glm13-11).
	 *
	 * The zai surface's request mapping is the SDK parent's
	 * (prepareGenerateTextParams()), which copies the caller's strings and
	 * tool-result values into the request params with at most type
	 * juggling — never a validation the endpoint would not answer with
	 * the generic misattributed 400. This walk carries every rule whose
	 * rejection must NAME the member: identity and shape rules (empty and
	 * duplicate declared names, list-root output schemas, non-string and
	 * empty stop entries, tool-result and tool-call identity). The pure
	 * ENCODABILITY half rides the createRequest() chokepoint's
	 * whole-payload net (guard_assembled_params()) with ONE serialization
	 * per request; the old eager per-member pre-pass (a second
	 * O(payload) serialization on every request) survives only as the
	 * attribution walk that net runs on failure (guard_wire_values()).
	 *
	 * glm14-3: the net's coverage contract is every member the parent
	 * forwards VERBATIM to $data — including members added by future SDK
	 * releases. It structurally cannot cover members the parent
	 * TRANSFORMS or DROPS before assembly, and the vendor parent has two
	 * plain-json_encode LAUNDER sites and one conditional DROP today,
	 * each with its own eager guard outside the net:
	 * - the tool-result RESPONSE (prepareMessagesParam string-casts a
	 *   failed encode into "content": false) — must_encode below, the
	 *   glm13-11 exception;
	 * - the outbound tool-call ARGUMENTS (getMessagePartToolCallData
	 *   string-casts into "arguments": false) — the replay-guard
	 *   override, whose ReplayValidatedFunctionCall stamp skip is the
	 *   glm12-12 contract;
	 * - the configured output SCHEMA under a non-JSON mime (the parent
	 *   forwards response_format only under application/json) — the
	 *   glm14-1 eager check above.
	 * A future SDK release that adds a fourth pre-encode or drop site
	 * needs its own eager guard; the net alone will not catch it.
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return void
	 * @throws InvalidArgumentException When a wire value is misshapen.
	 */
	private function reject_misshapen_wire_values( array $prompt ): void {
		$config = $this->getConfig();

		$output_schema = $config->getOutputSchema();
		if ( \is_array( $output_schema ) ) {
			/*
			 * glm13-8 (the GLM12 #4/#5 error class, parity with the
			 * twin's Codex R7 #3 parameter-schema rule): the SDK setter
			 * accepts any array, so a LIST-root schema (['a','b'], or [])
			 * encodes fine and rode the wire verbatim as
			 * response_format.json_schema — where the spec-faithful
			 * endpoint rejects it as the generic misattributed upstream
			 * 400 with no hint the caller's schema shape is the cause.
			 * Typed pre-transport rejection; the shape rule surfaces
			 * before the encodability check, matching the twin's
			 * identity-before-schema ordering.
			 */
			if ( JsonShape::is_list( $output_schema ) ) {
				throw new InvalidArgumentException(
					'The zai provider requires the configured output schema to be a JSON object (a list was given).'
				);
			}

			/*
			 * glm14-1: the SDK parent forwards response_format ONLY under
			 * the JSON mime (AbstractOpenAiCompatibleTextGenerationModel),
			 * so under any other mime the configured schema never reaches
			 * the assembled $data — the chokepoint net cannot fail on it,
			 * and glm13-11's move of the schema's encodability check onto
			 * the net let an unencodable schema under, say, text/plain fly
			 * unchecked where the pre-glm13-11 eager walk rejected it typed
			 * and the zai_anthropic twin still guards the schema on EITHER
			 * signal. Guarded HERE, eagerly, only in the dropped case:
			 * under the JSON mime the net's whole-payload encode covers
			 * the schema exactly once, and the attribution walk names it
			 * precisely on failure — the eager check would be the second
			 * O(payload-member) serialization glm13-11 deleted.
			 */
			if ( 'application/json' !== $config->getOutputMimeType() ) {
				JsonEncodeGuard::must_encode( $output_schema, 'the configured output schema', self::PROVIDER_LABEL );
			}
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
			$declared_names = array();

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

				/*
				 * glm13-9 (parity with the twin's R18 rule, one surface
				 * late): a returned tool_call identifies the selected
				 * declaration ONLY by name (the SDK maps the response to
				 * FunctionCall(id, function.name, args) — name is the
				 * only declaration reference on the DTO), so two
				 * declarations sharing a name make that identification
				 * ambiguous and a name-keyed consumer dispatches against
				 * the wrong tool. A duplicate is a typed pre-transport
				 * rejection exactly as on the twin.
				 */
				if ( isset( $declared_names[ $name ] ) ) {
					throw new InvalidArgumentException(
						'The zai provider requires declared tool functions to carry unique names.'
					);
				}

				$declared_names[ $name ] = true;
			}
		}

		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
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
					 * glm13-11 exception: the tool-result RESPONSE cannot
					 * ride the post-mapping encodability net — the SDK
					 * parent's message mapping json_encodes it with plain
					 * json_encode() and STRING-CASTS the failure, so by
					 * createRequest() time an unencodable result has
					 * already laundered into the string "false" (which
					 * encodes fine — the R18 'content': false corruption
					 * class). Its encodability guard stays eager here,
					 * PRE-mapping, the one value whose check cannot wait
					 * for the net.
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

	/**
	 * Attributes an assembled-payload encodability failure to its first
	 * bad member — the encodability half of the GLM6 #5 walk (glm13-11).
	 *
	 * Runs ONLY when the createRequest() chokepoint's whole-payload net
	 * (guard_assembled_params()) fails, over the stashed prompt: the
	 * same per-member JsonEncodeGuard checks the eager pre-pass ran on
	 * every request, preserving the precise first-bad-wins messages
	 * ('the system instruction', 'a message text part', ...) without the
	 * second O(payload) serialization the happy path used to pay. A
	 * member this walk does not know falls back to the caller's generic
	 * description.
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

		$function_declarations = $config->getFunctionDeclarations();
		if ( \is_array( $function_declarations ) ) {
			foreach ( $function_declarations as $declaration ) {
				JsonEncodeGuard::must_encode( $declaration->getName(), 'a declared tool function name', self::PROVIDER_LABEL );
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
			}
		}
	}
}
