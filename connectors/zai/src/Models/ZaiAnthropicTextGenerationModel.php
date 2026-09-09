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
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use Deicod\WpConnectors\Zai\Authentication\SpeaksAnthropicMessagesProtocol;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;
use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard;
use Deicod\WpConnectors\Zai\Support\AdvertisedUsageGuard;
use Deicod\WpConnectors\Zai\Support\AnthropicContentBlocks;
use Deicod\WpConnectors\Zai\Support\AnthropicSseAggregator;
use Deicod\WpConnectors\Zai\Support\EncodabilityNet;
use Deicod\WpConnectors\Zai\Support\JsonBodyDecoder;
use Deicod\WpConnectors\Zai\Support\JsonFallbackResult;
use Deicod\WpConnectors\Zai\Support\JsonEncodeGuard;
use Deicod\WpConnectors\Zai\Support\JsonOutputGuidance;
use Deicod\WpConnectors\Zai\Support\MemoizesToolLoopVerdicts;
use Deicod\WpConnectors\Zai\Support\ReplayValidatedFunctionCall;
use Deicod\WpConnectors\Zai\Support\RequestShapeGuard;
use Deicod\WpConnectors\Zai\Support\UsageValidator;
use Deicod\WpConnectors\Zai\Support\BuildsJsonOutputGuidance;
use Deicod\WpConnectors\Zai\Support\EventStreamSniff;
use Deicod\WpConnectors\Zai\Support\FixedMessageResponseException;
use Deicod\WpConnectors\Zai\Support\JsonShape;
use Deicod\WpConnectors\Zai\Support\SafeGenerationBoundary;
use Deicod\WpConnectors\Zai\Support\StashesGenerationPrompt;
use Deicod\WpConnectors\Zai\Support\ThrowsSafeHttpErrors;
use Deicod\WpConnectors\Zai\Support\ToolArgsObjectNess;
use Deicod\WpConnectors\Zai\Support\TranslatableMessageParts;
use Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard;

/**
 * Text generation model for zai_anthropic.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {
	use ThrowsSafeHttpErrors;
	use SafeGenerationBoundary;
	use SpeaksAnthropicMessagesProtocol;
	use MemoizesToolLoopVerdicts;
	use StashesGenerationPrompt;
	use BuildsJsonOutputGuidance;

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
	 * The per-surface provider label interpolated into every guard and
	 * rejection message (GLM10 #9).
	 *
	 * The label was a bare string literal at ~40 call sites that mixed
	 * 'z.ai' (the ResponseException labels) and 'zai_anthropic' (the
	 * guard sites) within this one surface. One constant — ridden on
	 * the availability owner's REFUSAL_LABEL, the same identity the
	 * credential-refusal messages carry — makes every message name the
	 * provider exactly one way.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PROVIDER_LABEL = ZaiAnthropicProviderAvailability::REFUSAL_LABEL;

	/**
	 * The API identity the HTTP-error catalog names this surface by
	 * (glm30-2).
	 *
	 * The card-name chain (glm24-2's direction): the settings layer's
	 * PROVIDER_LABEL — 'z.ai (Anthropic API)' — verbatim, WITHOUT the
	 * ' API' suffix the OpenAI twin appends, because this card name
	 * already carries its '(Anthropic API)' suffix and appending again
	 * would duplicate it. Before the identity became a parameter the
	 * shared catalog hardcoded 'z.ai API' here too, so a 401 with both
	 * cards configured never named which surface rejected the key.
	 *
	 * Read by the ThrowsSafeHttpErrors and SafeGenerationBoundary traits
	 * this model composes (static:: resolves there); a composer without
	 * the constant fatals loudly on first use (the GLM6 #12 discipline).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const HTTP_API_LABEL = ZaiAnthropicPlanRegionSettings::PROVIDER_LABEL;

	/**
	 * This surface's pinned empty-text translation policy (glm28-4,
	 * parameterized by glm30-4's shared owner).
	 *
	 * The Messages mapper's block rule DROPS an empty-string text part
	 * (message_part_block() emits no block for it), so an empty-text
	 * part is NOT a translatable part here and a turn carrying only one
	 * rejects at parse time — unlike the zai twin, whose
	 * OpenAI-compatible mapper emits a wire text entry for it.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private const EMPTY_TEXT_TRANSLATES = false;

	/**
	 * Normalized input schemas for the CURRENT config's tool
	 * declarations, keyed by declaration object identity (glm16-6).
	 *
	 * The vendor FunctionDeclaration DTO is immutable (private
	 * constructor-assigned properties, getters only), so the
	 * list-shape/encodability/normalization pipeline is a PURE function
	 * of the declaration: a tool loop that replays the conversation
	 * every turn re-ran a full json_encode plus the recursive
	 * normalization walk per declaration per request for a wire form
	 * that never changes. SplObjectStorage is the identity-keyed store
	 * on the PHP 7.4 floor (WeakMap is 8.0+; a spl_object_id-keyed map
	 * is unsafe across GC id reuse) — but unlike WeakMap it holds
	 * STRONG keys: the storage itself pins every declaration it holds
	 * until a reset releases it, which is why the reset set must cover
	 * every reconfiguration idiom (glm16-16/glm17-1).
	 *
	 * @since 0.2.0
	 *
	 * @var \SplObjectStorage|null
	 */
	private $tool_schema_memo = null;

	/**
	 * The config whose declarations the memo holds (glm16-6).
	 *
	 * One of the memo's two compare-based reset triggers: a config
	 * identity change — the vendor base's final setConfig() can replace
	 * a live instance's config. The declarations snapshot below is the
	 * other (glm16-16: the vendor ModelConfig is MUTABLE — a public
	 * setFunctionDeclarations() — so one config object mutated in a
	 * batch loop never changes identity while its declaration set
	 * does). A cleared or absent list releases the whole memo instead —
	 * glm17-1: the compare-based resets live behind the non-empty gate
	 * at the params build, so the empty branch cannot reach them.
	 *
	 * @since 0.2.0
	 *
	 * @var \WordPress\AiClient\Providers\Models\DTO\ModelConfig|null
	 */
	private $tool_schema_memo_config = null;

	/**
	 * The declaration list the memo was built for (glm16-16).
	 *
	 * The exact list of FunctionDeclaration objects the current memo
	 * holds entries for, compared by strict array identity (object
	 * identity per element, order included) on every request build.
	 * Either non-empty reconfiguration idiom — setConfig() replacement
	 * OR in-place setFunctionDeclarations() mutation — changes the list
	 * and resets the memo, and a cleared/absent list releases it
	 * entirely at the params-build gate (glm17-1), so the memo pins at
	 * most the CURRENT declaration set: the very declarations the
	 * config itself already pins, zero extra retention under any
	 * idiom. The O(n) identity compare is trivial beside the
	 * per-declaration encode-plus-walk the memo exists to skip.
	 *
	 * @since 0.2.0
	 *
	 * @var array|null
	 */
	private $tool_schema_memo_declarations = null;

	/**
	 * The RAW wired authentication — the SDK parent's getter, unwrapped
	 * (glm15-8: the protocol wrap lives once on the
	 * SpeaksAnthropicMessagesProtocol trait).
	 *
	 * GLM28-6: this one hook now feeds BOTH consumers of the raw
	 * instance — the protocol wrap (SpeaksAnthropicMessagesProtocol)
	 * and the credential gate/recorder (SafeGenerationBoundary,
	 * superseding glm18-14's gate_authentication() delegation: the gate
	 * hook and the raw hook were two names for one body). The GLM3 #9
	 * contract rides it: the gate keys on the API key alone, which the
	 * raw instance carries, and wrap() refuses foreign wiring with the
	 * same binding-failure RuntimeException, so wherever the failure
	 * eventually surfaces it maps to 500 zai_error, never 400.
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	protected function raw_request_authentication(): RequestAuthenticationInterface {
		return parent::getRequestAuthentication();
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
		return new ZaiAnthropicProviderAvailability();
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
		/*
		 * glm13-12: transporter resolution precedes the guards — the
		 * vendor-mandated order of the SDK parent's FINAL
		 * generateTextResult() the zai surface rides (getHttpTransporter()
		 * before prepareGenerateTextParams()), so identical misuse yields
		 * the identical error on both surfaces: an unbound instance fails
		 * the transporter binding (RuntimeException -> 500 zai_error
		 * through the mapper) before its options are judged, exactly like
		 * the zai surface. Guards-first is not implementable there — the
		 * vendor method is final — so the surfaces unify on the one order
		 * both can share.
		 */
		$http_transporter = $this->getHttpTransporter();

		$this->refuse_refused_credentials();

		$params = $this->prepareGenerateTextParams( $prompt );

		/*
		 * glm15-5: the request-build chokepoint net — the identical
		 * glm13-11/glm14-4 mechanics the zai surface runs at its
		 * createRequest(): ONE raw encode of the assembled payload that
		 * both proves encodability (the typed pre-transport rejection,
		 * with the attribution walk naming the first bad member) and
		 * rides as the request's body string, deleting the transport's
		 * send-time re-encode. See guard_assembled_params().
		 */
		$data = $this->guard_assembled_params( $params );

		$endpoint = ZaiAnthropicEndpoint::for_current_settings();

		/*
		 * glm13-6: the request-time endpoint capture — see
		 * SafeGenerationBoundary::capture_generation_endpoint().
		 */
		$this->capture_generation_endpoint( $endpoint->cache_key() );

		$request = new Request(
			HttpMethodEnum::POST(),
			$endpoint->messages_url(),
			array( 'Content-Type' => 'application/json' ),
			$data,
			$this->getRequestOptions()
		);

		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		$response = $http_transporter->send( $request );

		$this->throwIfNotSuccessful( $response );

		return $this->parseResponseToGenerativeAiResult( $response );
	}

	/**
	 * Rejects an unassemblable request payload at the request-build
	 * chokepoint — and rides the successful encode as the body (glm15-5).
	 *
	 * The port of the zai surface's glm13-11/glm14-4 net to this
	 * surface's own request build: the mapping (this class, not a vendor
	 * parent) used to eagerly json_encode every wire member per request
	 * — every text part of the conversation history, the system
	 * instruction, every tool declaration name/description/schema — and
	 * the transport's Request::getBody() then re-encoded the identical
	 * assembled payload, the exact per-member-walk pattern the sibling
	 * surface replaced in this same PR. ONE raw encode of the assembled
	 * $params now decides both halves:
	 *
	 * - SUCCESS: the encoded string is returned and handed to the
	 *   Request as its $data — Request stores a string as the RAW body
	 *   and getBody() returns it as-is, so the transport's send-time
	 *   re-encode (the second whole-payload serialization every request
	 *   of a tool-loop history paid) is gone. The wire bytes are
	 *   unchanged — the same encoder, no flags, byte-identical to what
	 *   getBody() would have produced from the array (this surface's
	 *   generation requests always carry a JSON body: the literal
	 *   Content-Type header two calls up). No plugin or vendor consumer
	 *   reads getData() on the request (the transport consumes
	 *   getBody() only, HttpTransporter::convertToPsr7Request).
	 * - FAILURE: the per-member attribution walk (guard_wire_values())
	 *   runs over the stashed prompt to name the first bad member with
	 *   the precise message the eager per-site guards used to give; a
	 *   member the walk does not know keeps the generic description.
	 *   Both reject typed pre-transport, before any Request is built.
	 *
	 * The mapping sites keep every rule that is NOT pure
	 * encodability, exactly like the zai surface's typed walk: identity
	 * rules (non-empty tool ids/names, duplicates), shape rules
	 * (list-root arguments/schemas, scalars, empty drops), the
	 * stop-sequence per-entry rule, the schema normalization (a
	 * TRANSFORM — its output rides the wire), the tool-result response
	 * and output-schema guidance encodes (TRANSFORMS — their encoded
	 * strings ride the wire), and the tool-arguments replay guard (the
	 * precision-loss rule, with its glm12-12 stamp skip). One ordering
	 * nuance the split shares with the zai surface: a payload carrying
	 * BOTH a typed-walk violation (an empty tool name) and an
	 * encodability violation now rejects on the TYPED one first (the
	 * mapping still runs before the net); single-bad payloads keep
	 * byte-identical messages through the attribution walk.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $params The assembled request params.
	 * @return string The encoded JSON body (glm16-7: the shared net
	 *                always returns the string — a failure rejects
	 *                before any return).
	 * @throws InvalidArgumentException When any member cannot encode.
	 */
	private function guard_assembled_params( array $params ) {
		/*
		 * glm16-7: the net rides the shared EncodabilityNet owner (the
		 * one raw encode + failure sequence both surfaces run); the
		 * Messages request ALWAYS carries a JSON body, so unlike the
		 * zai twin there is no carries_json_body() branch — every
		 * success rides as the body string (glm15-5).
		 */
		return EncodabilityNet::encode(
			$params,
			self::PROVIDER_LABEL,
			function () {
				$this->guard_wire_values( $this->stashed_generation_prompt() );
			}
		);
	}

	/**
	 * Attributes an assembled-payload encodability failure to its first
	 * bad member (glm15-5; glm16-7 composes the shared walk segments).
	 *
	 * Runs ONLY when the request-build net (guard_assembled_params())
	 * fails, over the stashed prompt: the same per-member
	 * JsonEncodeGuard checks the eager mapping-site guards ran on every
	 * request, preserving the precise first-bad-wins messages ('a
	 * message text part', 'the system instruction', 'a declared tool
	 * function name', ...) without the second O(payload) serialization
	 * the happy path used to pay. The segment ORDER matches the OLD
	 * EAGER positions, not the wire mapping order — glm20-8 composed
	 * identities and stop sequences at the positions the pre-round
	 * eager guards ran (identities, stop, then this walk's glm15-5
	 * tail: text, system, declarations), so a multi-bad payload keeps
	 * naming the member that walk named even where the orders differ
	 * (stop_sequences MAPS after system; the identities and text
	 * segments guard per-kind blocks, not per-message interleaved —
	 * the zai twin's docblock states the same old-eager-order contract
	 * for its stop-first composition). glm34-4: this docblock's former
	 * 'matches the mapping order' phrasing was the glm19-13 doc-drift
	 * class — a stated contract the composition never satisfied. A
	 * member this walk does not know falls back to the caller's generic
	 * description. The sampling options are deliberately NOT composed
	 * here — see EncodabilityNet::guard_sampling_options() for why the
	 * zai twin composes them and this surface's eager transforms make
	 * them dead branches.
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return void
	 * @throws InvalidArgumentException When a wire value cannot encode.
	 */
	private function guard_wire_values( array $prompt ): void {
		$config = $this->getConfig();

		/*
		 * glm20-8: the prompt's tool identities and the stop-sequence
		 * entries joined the composition here, at the positions the old
		 * eager guards ran — identities first, then stop sequences,
		 * both ahead of the text segment. That is the old eager order,
		 * not the wire mapping order; the full contract statement and
		 * the glm34-4 correction live in the docblock above.
		 */
		EncodabilityNet::guard_prompt_tool_identities( $prompt, self::PROVIDER_LABEL, 'a tool result tool_use id' );
		EncodabilityNet::guard_stop_sequences( $config, self::PROVIDER_LABEL );

		/*
		 * The mapping drops empty text parts, so the wire carries only
		 * visible NON-EMPTY text — the shared segment's guard covers
		 * exactly those (an empty string encodes fine either way).
		 */
		EncodabilityNet::guard_visible_text( $prompt, self::PROVIDER_LABEL );
		EncodabilityNet::guard_system_instruction( $config, self::PROVIDER_LABEL );
		EncodabilityNet::guard_declarations( $config, self::PROVIDER_LABEL );
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

		// glm15-5/glm25-3: the attribution walk reads this prompt if the
		// request-build encodability net fails — the stash rides the
		// shared StashesGenerationPrompt trait.
		$this->generation_prompt = $prompt;

		$config = $this->getConfig();

		/*
		 * glm21-4/glm21-17: the build's tool-DTO set is re-armed here
		 * (message_part_block() notes every tool DTO it maps) and the
		 * memos are pruned to it once the messages are prepared — see
		 * $tool_loop_build_dto and prune_tool_loop_memos().
		 */
		$this->tool_loop_build_dto = null;

		$params = array(
			'model'      => $this->metadata()->getId(),
			'max_tokens' => $this->effective_max_tokens(),
			'messages'   => $this->prepare_messages_param( $prompt ),
		);

		$this->prune_tool_loop_memos();

		$system_instruction = $config->getSystemInstruction();
		$json_guidance      = $this->json_output_guidance();
		if ( '' !== $json_guidance ) {
			$system_instruction = \is_string( $system_instruction ) && '' !== $system_instruction
				? $system_instruction . "\n\n" . $json_guidance
				: $json_guidance;
		}
		if ( \is_string( $system_instruction ) && '' !== $system_instruction ) {
			/*
			 * GLM3 #4: the system member is a wire STRING — its
			 * invalid-UTF-8 rejection is the shared JsonEncodeGuard's
			 * raw-oracle rule. glm15-5: that pure-encodability check
			 * rides the request-build whole-payload net now (the
			 * attribution walk names 'the system instruction' on
			 * failure), so it no longer runs eagerly per member.
			 */
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
			/*
			 * GLM3 #3: the SDK setter checks only LIST-ness, so a
			 * non-string or empty-string entry ([0], [''], ['END', null])
			 * reached the wire verbatim and failed upstream with the
			 * generic misattributed client-error message instead of the
			 * typed pre-transport rejection every neighboring malformed
			 * input already receives. GLM9 #12: the per-entry rule rides
			 * the shared JsonEncodeGuard (the same invalid-UTF-8 oracle
			 * as text parts, GLM3 #4) — the twin loops this extraction
			 * replaced had already drifted once. glm20-8: the SHAPE half
			 * stays eager here; the entries' encodability rides the net's
			 * attribution walk (EncodabilityNet::guard_stop_sequences(),
			 * composed after the identity segments at the old eager
			 * position — ahead of the text segment, not at stop_sequences'
			 * later mapping position; see guard_wire_values(), glm34-4).
			 */
			JsonEncodeGuard::reject_misshapen_stop_sequences( $stop_sequences, self::PROVIDER_LABEL );

			$params['stop_sequences'] = $stop_sequences;
		}

		$function_declarations = $config->getFunctionDeclarations();
		if ( \is_array( $function_declarations ) && array() !== $function_declarations ) {
			$params['tools'] = $this->prepare_tools_param( $function_declarations );
		} else {
			/*
			 * glm17-1: a cleared or absent list is a reconfiguration
			 * idiom too. prepare_tools_param() — the memo's other reset
			 * site — only runs for a NON-empty list, so this branch used
			 * to skip every reset: the previous set's declarations stayed
			 * strongly keyed in the memo storage for the model's
			 * lifetime while the config itself held none. The no-tools
			 * build releases the memo, keeping the documented bound (at
			 * most the CURRENT declaration set) true at every build.
			 */
			$this->release_tool_schema_memo();
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
	 * request (Codex R1 finding 4). glm23-2: the sentences and the
	 * memoized schema encode ride the one shared JsonOutputGuidance
	 * builder ($json_output_guidance_builder) — the guidance bytes and
	 * the glm21-7/16 memo discipline are one owner across both surfaces
	 * now.
	 *
	 * @since 0.2.0
	 *
	 * @return string Guidance text (possibly embedding the outputSchema).
	 * @throws InvalidArgumentException When the configured outputSchema is a
	 *                                  JSON list (glm18-6) or cannot be
	 *                                  JSON-encoded (Codex R19).
	 */
	private function json_output_guidance(): string {
		$config = $this->getConfig();

		$output_schema = $config->getOutputSchema();
		$json_mime     = 'application/json' === $config->getOutputMimeType();

		if ( ! $json_mime && ! \is_array( $output_schema ) ) {
			return '';
		}

		if ( ! \is_array( $output_schema ) ) {
			return JsonOutputGuidance::base_guidance();
		}

		/*
		 * glm28-17: the lazy builder rides the one shared trait
		 * (BuildsJsonOutputGuidance) — the StashesGenerationPrompt
		 * precedent for this byte-identical lazy-init block.
		 */
		return $this->json_output_guidance_builder()->schema_guidance( $output_schema, $config, self::PROVIDER_LABEL );
	}

	/**
	 * Releases the tool-schema memo entirely (glm17-1).
	 *
	 * The clear-to-empty reconfiguration idiom: prepare_tools_param()
	 * (the compare-based reset) only runs for a NON-empty declaration
	 * list, so a params build with a cleared or absent list must release
	 * the memo here — otherwise the previous set's entries stay strongly
	 * keyed in the storage while the config itself holds no
	 * declarations. Null (not a fresh storage) is the honest state: the
	 * next declaration-bearing build re-initializes through the
	 * compare's null arm.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function release_tool_schema_memo(): void {
		$this->tool_schema_memo              = null;
		$this->tool_schema_memo_config       = null;
		$this->tool_schema_memo_declarations = null;
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

		/*
		 * glm16-6: memo lifecycle. The memo lives for the CURRENT
		 * declaration set's lifetime, reset by EVERY reconfiguration
		 * idiom: a config identity change — the vendor base's final
		 * setConfig() — or an in-place declaration-list change through
		 * the vendor ModelConfig's public setters, detected by the
		 * strict-list compare below (glm16-16), or a cleared/absent
		 * list, which releases the memo at the params-build gate before
		 * this method is skipped (glm17-1 — the compare below can never
		 * fire for a list this method is never called with). Entries
		 * themselves are identity-keyed and pure: an entry computed for
		 * one declaration object can never be wrong for it later.
		 */
		$config = $this->getConfig();
		if ( null === $this->tool_schema_memo
			|| $config !== $this->tool_schema_memo_config
			|| $function_declarations !== $this->tool_schema_memo_declarations ) {
			$this->tool_schema_memo              = new \SplObjectStorage();
			$this->tool_schema_memo_config       = $config;
			$this->tool_schema_memo_declarations = $function_declarations;
		}

		$memo = $this->tool_schema_memo;

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
			 * glm19-5: the rule lives on the shared RequestShapeGuard.
			 */
			$name = $declaration->getName();

			RequestShapeGuard::reject_empty_tool_name( $name, self::PROVIDER_LABEL );

			/*
			 * GLM6 #9: the identity and description strings ride the
			 * tools member verbatim and were only ever EMPTINESS-checked
			 * — an unencodable one (invalid UTF-8 from a DB row, say)
			 * used to detonate in the transport's whole-request encode as
			 * the generic 500. glm15-5: that pure-encodability check
			 * rides the request-build whole-payload net now (the
			 * attribution walk names the member on failure); the
			 * identity rules above and below keep their eager typed
			 * rejection.
			 */

			/*
			 * R18 (inline 3906485728): a returned tool_use identifies the
			 * selected declaration ONLY by name — two declarations sharing a
			 * name make that identification ambiguous (the caller may
			 * validate or execute the call against the wrong tool), so a
			 * duplicate is a typed pre-transport rejection like the empty
			 * name above. glm19-5: the rule lives on the shared
			 * RequestShapeGuard.
			 */
			RequestShapeGuard::reject_duplicate_tool_name( $name, $declared_names, self::PROVIDER_LABEL );

			$declared_names[ $name ] = true;

			if ( $memo->offsetExists( $declaration ) ) {
				/*
				 * glm16-6: identity hit — the pipeline below is a pure
				 * function of the immutable declaration, so its result for
				 * this object can be reused as-is for every later request of
				 * this config's lifetime (rejections never reach the memo:
				 * they throw below before an entry is stored).
				 */
				$input_schema = $memo[ $declaration ];
			} else {
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

				/*
				 * glm19-5: the shape rule lives on the shared
				 * RequestShapeGuard (Codex R7 #3's boundary: only a
				 * NON-EMPTY list rejects; null and [] normalize to the
				 * empty-object schema below).
				 */
				RequestShapeGuard::reject_list_root_parameter_schema( $input_schema, self::PROVIDER_LABEL );

				/*
				 * R20 (inline 3907008524): an unencodable schema value — NAN,
				 * invalid UTF-8, a resource, a RECURSIVE structure — used to
				 * reach the request untouched, so generation failed in the
				 * transport's whole-request serialization instead of
				 * producing the adapter's pre-transport configuration error.
				 *
				 * glm15-5: this one encodability check stays EAGER even
				 * though the request-build net covers the schema's
				 * encodability too — the object normalization below is a
				 * recursive TRANSFORM, and on a self-referential structure
				 * (a caller-built array referencing itself) it recurses
				 * until the memory limit: a PHP fatal no net can reject.
				 * json_encode() detects recursion and returns false, so this
				 * oracle is the recursion guard the transform needs before
				 * it runs (GLM4 #1 raw-oracle rule; GLM5 #16 single-sourced).
				 */
				if ( \is_array( $input_schema ) && array() !== $input_schema ) {
					JsonEncodeGuard::must_encode( $input_schema, 'a declared tool parameter schema', self::PROVIDER_LABEL );
				}

				if ( null === $input_schema || array() === $input_schema ) {
					$input_schema = array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					);
				} else {
					/*
					 * GLM8 #6: the empty-object normalization above fires only
					 * when the WHOLE schema is empty, so a NON-empty schema
					 * carrying an empty-array member at an object-demanding
					 * keyword (['type'=>'object','properties'=>[],'required'=>[]])
					 * shipped that member as JSON [] where the protocol's
					 * meta-schema wants an object — risking an upstream 400 in
					 * place of the adapter's own normalization. The object-map
					 * keywords are normalized recursively; 'required' and every
					 * other list-valued keyword keep their (schema-valid) [].
					 *
					 * GLM10 #6: the recursion now knows EVERY schema-valued
					 * position — a property value of [], items: [], an allOf
					 * element — not just the four map keywords, so an
					 * empty-array SUBSCHEMA at any of them encodes as {} too
					 * (see normalize_empty_object_members()).
					 */
					$input_schema = self::normalize_empty_object_members( $input_schema );
				}

				$memo[ $declaration ] = $input_schema;
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
	 * The JSON Schema keywords whose member must be a JSON OBJECT (a
	 * name-to-subschema map), never a list — so an empty PHP array there
	 * encodes as the empty object {} (GLM8 #6).
	 *
	 * 'required' and the other list-valued keywords are deliberately
	 * absent: an empty list is schema-valid for them.
	 *
	 * glm27-3 (Codex R21 finding 3): 'dependentSchemas' is the same
	 * name-to-subschema shape — its absence left the keyword to the
	 * fallthrough recursion, so a dependent's empty array value shipped
	 * as JSON [] where a strict JSON Schema validator demands an object
	 * (the exact class GLM10 #6 closed for every other subschema
	 * position), potentially 400ing an otherwise-valid tool definition
	 * while equivalent empties under 'properties' normalized.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SCHEMA_OBJECT_MAP_KEYS = array( 'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas' );

	/**
	 * The JSON Schema keywords whose value is ONE subschema (an object,
	 * or a boolean in the draft-06+ sense) — so an empty PHP array there
	 * is an empty SCHEMA, encoded as {} (GLM10 #6).
	 *
	 * GLM8 #6 normalized the four object-MAP keywords only, so an
	 * empty-array subschema at every other schema-valued position — a
	 * property value of [], items: [] — shipped on the wire as JSON []
	 * where the Messages input_schema meta-schema demands an object,
	 * surfacing a strict endpoint's 400 as the generic misattributed
	 * upstream client error instead of the adapter's own normalization.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SCHEMA_SUBSCHEMA_KEYS = array( 'items', 'additionalProperties', 'additionalItems', 'not', 'contains', 'propertyNames', 'if', 'then', 'else', 'unevaluatedProperties', 'unevaluatedItems' );

	/**
	 * The JSON Schema keywords whose value is a LIST of subschemas: the
	 * list itself is list-valued (kept even when empty), but every
	 * ELEMENT is a subschema (GLM10 #6).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SCHEMA_SUBSCHEMA_LIST_KEYS = array( 'allOf', 'anyOf', 'oneOf', 'prefixItems' );

	/**
	 * The JSON Schema keywords whose value is caller DATA, not a
	 * subschema: the walk must not descend into them (GLM8 #6 verifier
	 * round; glm16-3 added enum).
	 *
	 * A `default`/`examples`/`const` value may legitimately contain an
	 * empty list at a key named 'properties' (say, a property-management
	 * tool's default object) — descending into these keywords silently
	 * converted such data to {} on the wire, altering the caller's
	 * default/example values with no upstream error to surface the
	 * change. `enum` is the same class (glm16-3): its members are the
	 * CONSTANTS a value must equal — arbitrary JSON values — and the
	 * walk's fallthrough recursion rewrote an enum member's
	 * schema-keyword-named empty arrays ({"enum":[{"patternProperties":
	 * []}]} → {}), so the wire advertised a DIFFERENT constant than the
	 * one declared, silently. Data-bearing keywords carry arbitrary
	 * JSON values, so they pass through verbatim.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SCHEMA_DATA_VALUE_KEYS = array( 'default', 'examples', 'const', 'enum' );

	/**
	 * Normalizes one value occupying a SUBSCHEMA position (GLM10 #6).
	 *
	 * An empty PHP array is ambiguous in exactly this position: as a
	 * subschema it means the empty schema — an OBJECT on the wire ({})
	 * — because the input_schema meta-schema accepts only an object (or
	 * a boolean) where a schema is demanded, never a list.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $schema The subschema candidate (any decoded JSON shape).
	 * @return mixed The normalized subschema.
	 */
	private static function normalize_subschema( $schema ) {
		if ( \is_array( $schema ) && array() === $schema ) {
			return new \stdClass();
		}

		return self::normalize_empty_object_members( $schema );
	}

	/**
	 * Recursively converts empty PHP arrays to empty objects at the JSON
	 * Schema positions that demand an object or a subschema (GLM8 #6,
	 * GLM10 #6).
	 *
	 * The walk covers the whole schema tree through its KNOWN
	 * schema-valued positions — the object-map keywords (every entry is
	 * a subschema), the single-subschema keywords, and the
	 * subschema-list keywords (every element is a subschema) — so nested
	 * subschemas at ANY of them normalize identically, one level down
	 * from the GLM8 #6 map keywords and beyond. Everything else —
	 * non-empty members, scalars, objects, empty arrays at list- or
	 * data-valued keywords (required, unknown keywords), and the
	 * DATA-valued keywords (default/examples/const/enum, verbatim)
	 * — passes through untouched.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value Schema member (any decoded JSON shape).
	 * @return mixed The normalized member.
	 */
	private static function normalize_empty_object_members( $value ) {
		if ( ! \is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $member ) {
			// Data-bearing keywords are caller values, not schema
			// positions: never converted, never descended into.
			if ( \is_string( $key ) && \in_array( $key, self::SCHEMA_DATA_VALUE_KEYS, true ) ) {
				continue;
			}

			if ( \is_string( $key ) && \in_array( $key, self::SCHEMA_OBJECT_MAP_KEYS, true ) ) {
				if ( \is_array( $member ) ) {
					$value[ $key ] = array() === $member
						? new \stdClass()
						: array_map( array( self::class, 'normalize_subschema' ), $member );
				}

				continue;
			}

			if ( \is_string( $key ) && \in_array( $key, self::SCHEMA_SUBSCHEMA_LIST_KEYS, true ) ) {
				if ( \is_array( $member ) ) {
					$value[ $key ] = array_map( array( self::class, 'normalize_subschema' ), $member );
				}

				continue;
			}

			if ( \is_string( $key ) && \in_array( $key, self::SCHEMA_SUBSCHEMA_KEYS, true ) ) {
				if ( \is_array( $member ) ) {
					/*
					 * 'items' has a legacy tuple form: a LIST of
					 * subschemas (draft-04) instead of one. An
					 * all-integer-key member is one — every element
					 * normalizes as the subschema it is; anything else
					 * (and the empty array, which the meta-schema reads
					 * as the empty schema) is ONE subschema.
					 *
					 * Verifier round on GLM10 #6: an EMPTY items with a
					 * sibling 'additionalItems' member is the empty
					 * TUPLE — the caller demonstrably used tuple-form
					 * semantics ('every position is additional'), and
					 * converting it to {} would silently make the
					 * additionalItems constraint inert, weakening the
					 * declared schema. It keeps its (schema-valid [])
					 * verbatim, exactly as non-empty tuples already do;
					 * only an additionalItems-less empty items is the
					 * empty schema {}.
					 */
					if ( 'items' === $key
						&& array() === $member
						&& \array_key_exists( 'additionalItems', $value ) ) {
						continue;
					}

					/*
					 * GLM12 #13: the list test rides the shared
					 * JsonShape::is_list() predicate (already the source
					 * for this file's other list decisions) — the private
					 * is_schema_list() copy it replaces was
					 * behavior-identical by fuzz, and a future rule
					 * change would have landed on the shared predicate
					 * while the schema walker kept the stale copy.
					 */
					$value[ $key ] = array() !== $member && JsonShape::is_list( $member )
						? array_map( array( self::class, 'normalize_subschema' ), $member )
						: self::normalize_subschema( $member );
				}

				continue;
			}

			if ( \is_array( $member ) ) {
				$value[ $key ] = self::normalize_empty_object_members( $member );
			}
		}

		return $value;
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
		$previous_role     = null;
		$turn_text_seen    = false;
		$seen_tool_ids     = array();

		foreach ( $messages as $message ) {
			$role   = $this->message_role_string( $message->getRole() );
			$blocks = $this->message_content_blocks( $message );

			/*
			 * Turn boundary = role change: adjacent SDK messages of the same
			 * role coalesce into ONE wire turn below, so the answer-window
			 * validation runs HERE, once per coalesced turn — the R9/R10
			 * window closes only when the answering COALESCED user turn has
			 * fully ended (Codex R11 #1): checking after each SDK message
			 * rejected a legitimately split answer (result A in SDK user
			 * message 1, result B in the immediately adjacent message 2)
			 * before the coalescing below could merge them into the one
			 * valid wire turn.
			 *
			 * glm16-5: the text-before-tool_result judgment keeps ONE bit
			 * of turn state instead of re-scanning the merged turn —
			 * whether any block contributed to the coalesced turn so far
			 * was text. Reset here, at the same boundary.
			 */
			if ( $role !== $previous_role ) {
				/*
				 * glm26-10: the answer-window judgment derives from the
				 * outstanding-IDs map itself — the $awaiting_answer mirror
				 * (with $opens_tools and advance_answer_window()'s by-ref
				 * parameter, the file's only one) restated a structural
				 * invariant: a non-empty outstanding map implies an open
				 * window (every opener sets it, and the only boundary
				 * that clears it already requires an empty map or
				 * throws), while an EMPTY map at a user-ended boundary
				 * is a no-op either way. The old expiry for a NON-user
				 * incoming role was already provably dead (GLM5 #19 —
				 * message_role_string() produces only 'user'/'assistant'
				 * and this runs only on role change).
				 */
				if ( 'user' === $previous_role && array() !== $outstanding_tools ) {
					$this->reject_partially_answered_tool_turn();
				}

				$previous_role  = $role;
				$turn_text_seen = false;
			}

			foreach ( $blocks as $block ) {
				if ( 'tool_use' === $block['type'] ) {
					/*
					 * Codex R10 #2: two tool_use blocks with the SAME id in
					 * one assistant turn are ambiguous — a single later
					 * result would satisfy linkage while the wire carries
					 * duplicate identities (upstream validation failure).
					 * Reject before the map assignment can overwrite.
					 *
					 * GLM5 #6: the scope spans the WHOLE history, not the
					 * coalesced turn ($turn_tool_ids reset at every role
					 * change, so the same id reused across two different,
					 * properly answered assistant turns passed local
					 * validation and shipped — tool-result correlation is
					 * ambiguous for consumers and a strict Anthropic
					 * implementation upstream rejects the identity), which
					 * is what the R10 #2 duplicate check exists to prevent.
					 */
					if ( isset( $seen_tool_ids[ $block['id'] ] ) ) {
						throw new InvalidArgumentException(
							sprintf( 'The %s provider requires tool call ids to be unique across the conversation (duplicate tool call id).', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
						);
					}

					$seen_tool_ids[ $block['id'] ] = true;

					// A new tool_use opens its ID for exactly one answer.
					$outstanding_tools[ $block['id'] ] = true;
				} elseif ( 'tool_result' === $block['type'] ) {
					if ( ! isset( $outstanding_tools[ $block['tool_use_id'] ] ) ) {
						throw new InvalidArgumentException(
							sprintf( 'The %s provider requires every tool result to answer the preceding assistant tool call with the same id (unmatched, stale, or duplicate tool result).', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
						);
					}

					// Each tool_use ID may be answered exactly once.
					unset( $outstanding_tools[ $block['tool_use_id'] ] );
				}
			}

			$last     = \count( $prepared ) - 1;
			$is_merge = $last >= 0 && $prepared[ $last ]['role'] === $role;

			if ( 'user' === $role ) {
				/*
				 * Codex R12 #2: the coalescing merge must not produce a
				 * user wire turn with text BEFORE its tool_result blocks —
				 * Anthropic requires tool results to precede any text in
				 * the turn answering tool calls, and the linkage checks
				 * consume IDs regardless of position, so this order passed
				 * local validation and 400'd upstream. Judged per MESSAGE
				 * (glm16-5), seeded with the turn's accumulated state:
				 * equivalent to judging the merged turn — a tool_result is
				 * rejected exactly when text precedes it in an earlier
				 * message of the same coalesced turn or earlier in its own
				 * — without re-scanning the whole accumulated turn for
				 * every adjacent SDK message (O(K²) in blocks for the
				 * per-tool-result message shape the coalescing exists
				 * for).
				 */
				$turn_text_seen = $this->reject_text_before_tool_results( $blocks, $turn_text_seen );
			}

			if ( $is_merge ) {
				/*
				 * glm16-5: appended in place — array_merge() rebuilt the
				 * whole accumulated turn (another full copy per adjacent
				 * SDK message) to the same end.
				 */
				foreach ( $blocks as $block ) {
					$prepared[ $last ]['content'][] = $block;
				}

				continue;
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
		if ( array() !== $outstanding_tools ) {
			if ( 'user' === $previous_role ) {
				$this->reject_partially_answered_tool_turn();
			}

			throw new InvalidArgumentException(
				sprintf( 'The %s provider requires the final assistant tool call turn to be answered by a following user turn (unanswered tool call at the end of the conversation).', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			);
		}

		return $prepared;
	}

	/**
	 * Rejects text blocks preceding tool-result blocks in a user turn,
	 * one SDK message's blocks at a time (Codex R12 #2; glm16-5 made the
	 * judgment incremental).
	 *
	 * Anthropic requires tool_result blocks to come FIRST — before any
	 * text block — in the user turn that answers tool calls. The caller
	 * scans each message's blocks against the accumulated turn state, so
	 * both shapes are caught — a text Message adjacent-before a
	 * FunctionResponse Message (through the seeded flag), and a single
	 * SDK message whose blocks array already has text first — without
	 * re-scanning the whole accumulated turn per adjacent message. A user
	 * turn with no tool_result blocks is not an answering turn and is not
	 * judged here — glm15-17: that fact needs no pre-scan, because the
	 * rejection rule ('tool_result' seen after text) is unsatisfiable in
	 * a turn with no tool_result blocks on EVERY input; the single pass
	 * below simply never fires. A tool_result turn scans its blocks once,
	 * not twice.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<string, mixed>> $blocks The message's wire blocks.
	 * @param bool                       $text_seen Whether an earlier message of the same coalesced turn already contributed a text block.
	 * @return bool Whether the turn has now seen text (the incoming flag OR this message's own text blocks).
	 * @throws InvalidArgumentException When a text block precedes a tool_result block.
	 */
	private function reject_text_before_tool_results( array $blocks, bool $text_seen ): bool {
		foreach ( $blocks as $block ) {
			if ( 'text' === $block['type'] ) {
				$text_seen = true;
			} elseif ( 'tool_result' === $block['type'] && $text_seen ) {
				throw new InvalidArgumentException(
					sprintf( 'The %s provider requires tool results to precede text blocks in the user turn following a tool call.', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				);
			}
		}

		return $text_seen;
	}

	/**
	 * Rejects a user turn that left tool calls of the preceding
	 * assistant turn unanswered (glm19-8).
	 *
	 * The message was written out twice — the end-of-history branch and
	 * advance_answer_window() — and a wording edit to one copy would
	 * make the same corruption report two different messages depending
	 * on whether the offending turn ends the conversation; the
	 * byte-stable error contract the mapping/snapshot tests pin rides
	 * one thrower now.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 * @throws InvalidArgumentException Always.
	 */
	private function reject_partially_answered_tool_turn(): void {
		throw new InvalidArgumentException(
			sprintf( 'The %s provider requires the user turn after a tool call to answer every tool call of that turn (partially answered tool turn).', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
		);
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
					sprintf( 'The %s provider requires function-call (tool_use) parts to sit in assistant messages.', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				);
			}

			if ( $part->getType()->isFunctionResponse() && $is_assistant ) {
				throw new InvalidArgumentException(
					sprintf( 'The %s provider requires function-response (tool_result) parts to sit in user messages.', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				);
			}

			$block = $this->message_part_block( $part );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}

		if ( array() === $blocks ) {
			throw new InvalidArgumentException(
				sprintf( 'The %s provider requires every message to carry at least one translatable (text, tool call, or tool result) part.', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
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
	 * @throws InvalidArgumentException When a tool part carries no identity
	 *                                   or an unencodable argument value.
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

			/*
			 * GLM3 #4 (verifier round): an invalid-UTF-8 string passed
			 * every is_string check and used to detonate as a raw
			 * JsonException in the transport's whole-request encode,
			 * surfacing as the generic 500 (zai_error). glm15-5: the
			 * pure-encodability half of that rule (the shared
			 * JsonEncodeGuard's RAW json_encode oracle) rides the
			 * request-build whole-payload net now — the attribution walk
			 * names 'a message text part' on failure; the empty-drop
			 * shape rule above keeps its eager form.
			 */

			return array(
				'type' => 'text',
				'text' => $text,
			);
		}

		if ( $part->getType()->isFunctionCall() ) {
			$function_call = $part->getFunctionCall();

			// glm21-4/glm21-17: into the build's tool-DTO set (see
			// $tool_loop_build_dto).
			$this->note_tool_loop_dto( $function_call );

			/*
			 * Codex R9 #3: the Messages protocol requires NON-EMPTY tool
			 * ids and names — an empty string passes the null-only guard
			 * and emitted a tool_use block with an empty identity (upstream
			 * 400). GLM6 #9: replayed identities are wire strings like
			 * every other — encodability-guarded, not just
			 * emptiness-checked. GLM9 #12: the identity rule rides the
			 * shared JsonEncodeGuard, the same one the zai surface's
			 * GLM7 #7 contract lives in. glm20-8: the SHAPE half stays
			 * eager here; the strings encodability rides the
			 * attribution walks identity segment (composed first, this
			 * surfaces mapping order).
			 */
			JsonEncodeGuard::reject_tool_call_identity( $function_call, self::PROVIDER_LABEL );

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

			if ( \is_array( $input ) && array() !== $input && JsonShape::is_list( $input ) ) {
				throw new InvalidArgumentException(
					sprintf( 'The %s provider requires tool arguments to be a JSON object (a non-empty list was given).', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				);
			}

			if ( null !== $input && '' !== $input && ! \is_array( $input ) && ! \is_object( $input ) ) {
				throw new InvalidArgumentException(
					sprintf( 'The %s provider requires tool arguments to be a JSON object (a scalar was given).', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				);
			}

			/*
			 * glm33-2 (round-33 finding 2): the scalar rejection's
			 * is_object() carve-out presumed every object encodes as a
			 * JSON object — false for a JsonSerializable returning a
			 * list or scalar (json_encode of one returning
			 * array('Oslo') is ["Oslo"]) and for the storage-encoded
			 * ArrayObject/ArrayIterator values: the round-33 repro
			 * shipped "input": ["Oslo"] past both rejections AND past
			 * the replay oracle below (the serialization is stable, no
			 * precision loss) — the misattributed upstream 400 the
			 * Codex R4 #4/GLM2 #2 typed rejections exist to prevent,
			 * while this surface's own inbound parser rejects the
			 * identical shape response-side. An object's JSON form is
			 * knowable only through its encoding, so an OBJECT-typed
			 * argument is judged by the SERIALIZED shape: one raw
			 * encode whose successful output must lead with '{'. A
			 * FAILED encode says nothing here — the replay guard below
			 * owns unencodability with its own message (its first
			 * branch proves it on the very same value).
			 *
			 * The probe runs on CALLER-built object arguments only: a
			 * stamped ReplayValidatedFunctionCall proved its shape at
			 * inbound acceptance (every stored input is object-shaped
			 * by construction — the aggregator requires a stdClass
			 * decode, the non-streaming parse requires the object
			 * shape), so the stamp skips re-proving exactly as it
			 * skips the replay oracle (GLM12 #12). stdClass replays
			 * unstamped only from caller-built calls and always
			 * encodes object-led. Residual, documented: the probe
			 * re-runs per request build on the unstamped object-args
			 * subset — one serialization, the same per-build cost the
			 * glm21-5 memo already accepts where the memo cannot help
			 * (rehydrated conversations carry fresh DTOs and miss by
			 * design).
			 */
			if ( \is_object( $input ) && ! $function_call instanceof ReplayValidatedFunctionCall ) {
				$encoded = json_encode( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: the serialized shape is the fact being judged (GLM3 #4).

				if ( false !== $encoded && '{' !== $encoded[0] ) {
					throw new InvalidArgumentException(
						sprintf( 'The %s provider requires tool arguments to be a JSON object (a value serializing to a non-object was given).', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
					);
				}
			}

			if ( null === $input || '' === $input || ( \is_array( $input ) && array() === $input ) ) {
				$input = new \stdClass();
			}

			/*
			 * GLM4 #1: the shape checks above pass scalars, lists, and
			 * objects, but said nothing about ENCODABILITY — an argument
			 * value carrying NAN, invalid UTF-8, a resource, or a
			 * recursive structure reached the transport and detonated in
			 * its whole-request json_encode(..., JSON_THROW_ON_ERROR) as
			 * an untyped JsonException (generic 500 zai_error), the exact
			 * divergence the GLM3 #4 wire-string guards closed for text
			 * parts.
			 *
			 * GLM6 #8: encodability says nothing about PRECISION — an
			 * integral float beyond PHP_INT_MAX (9.3e18, from caller
			 * int-overflow arithmetic) encodes fine but ships a silently
			 * altered e-notation value on replay. The shared
			 * ToolArgsReplayGuard now judges the outbound arguments too,
			 * so this surface rejects exactly what its own inbound parser
			 * (GLM4 #2) and the zai surface's outbound mapper (GLM5 #20)
			 * already reject — the replay-poisoning contract holds on
			 * every path.
			 *
			 * GLM7 #11: the ONE guard IS the whole judgment. A separate
			 * JsonEncodeGuard::must_encode() used to run first — encoding
			 * the identical tree only to discard the result, four full
			 * serializations per replayed call where the replay guard's
			 * own first branch ('false === $encoded') already proves
			 * unencodability. Its rejection message folds into the
			 * replay message below, which names both failure modes.
			 *
			 * GLM12 #12 (stamp-at-acceptance skip): an inbound-accepted
			 * call carries the ReplayValidatedFunctionCall stamp — its
			 * arguments passed this surface's replay validation at parse
			 * time, the DTO is immutable, and the stamp carries the
			 * precise aggregator verdict, so the serializing oracle
			 * skips it instead of re-running on every request of the
			 * conversation. First-seen CALLER-built calls (plain SDK
			 * instances) keep the full oracle.
			 *
			 * glm21-5/glm21-8: the block rides the ONE shared
			 * ToolArgsReplayGuard::reject_unreplayable_call() with the
			 * zai twin (message, channel, and stamp contract unified —
			 * this surface's wording joins the twin's 'tool call
			 * arguments'), with the glm21-5 identity-keyed verdict memo
			 * (replayable_tool_call()) interposed as the oracle: first
			 * run full, repetition removed; the pinned first-bad-wins
			 * order above is untouched.
			 */
			ToolArgsReplayGuard::reject_unreplayable_call(
				$function_call,
				$input,
				self::PROVIDER_LABEL,
				function ( $args ) use ( $function_call ): bool {
					return $this->replayable_tool_call( $function_call, $args );
				}
			);

			return array(
				'type'  => 'tool_use',
				'id'    => $function_call->getId(),
				'name'  => $function_call->getName(),
				'input' => $input,
			);
		}

		if ( $part->getType()->isFunctionResponse() ) {
			$function_response = $part->getFunctionResponse();

			// glm21-4/glm21-17: into the build's tool-DTO set (see
			// $tool_loop_build_dto).
			$this->note_tool_loop_dto( $function_response );

			/*
			 * The tool_use id answers the call and is a wire string like
			 * the rest (GLM6 #9: encodability-guarded, not just
			 * emptiness-checked). GLM9 #12: the identity rule rides the
			 * shared JsonEncodeGuard — the same one the zai surface's
			 * GLM9 #4 guard lives in, one protocol-name apart. glm20-8: the
			 * SHAPE half stays eager here; the id's encodability rides the
			 * attribution walk's identity segment under this surface's own
			 * subject ('a tool result tool_use id').
			 */
			JsonEncodeGuard::reject_tool_result_identity( $function_response, 'tool_use id', self::PROVIDER_LABEL );

			/*
			 * R18 (inline 3906485711): an unencodable tool-result value — NAN,
			 * a resource, a recursive structure — makes the encode return
			 * false, which the string cast silently turned into '': the
			 * request then succeeded structurally while telling the model the
			 * tool returned NO content, corrupting the conversation. The
			 * serialization failure is a typed pre-transport rejection in the
			 * same channel as the other tool-result validations.
			 *
			 * GLM4 #1: the oracle is the RAW json_encode() (GLM3 #4's
			 * core-faithful primitive; GLM5 #16: single-sourced on the
			 * shared JsonEncodeGuard) — under production wp_json_encode()
			 * an invalid-UTF-8 tool result was lossily re-encoded and
			 * shipped, telling the model altered tool output.
			 *
			 * glm16-4: EVERY response value ships as its JSON encoding,
			 * strings included ('Partly cloudy' → "\"Partly cloudy\"") —
			 * a DELIBERATE convention, not an oversight. A raw string (or
			 * a text-block mapping) is Anthropic-protocol-legal, but the
			 * zai twin's tool-message content is the VENDOR parent's own
			 * json_encode($response) (the SDK's message mapping — not
			 * overridable without replacing it), so a raw-string mapping
			 * HERE would present the SAME tool result to the model
			 * differently on the two surfaces. One convention, both
			 * surfaces, every response type; pinned by
			 * testScalarToolResultsShipJsonEncodedLikeTheOpenAITwin.
			 *
			 * glm21-4: the encoding rides the identity-keyed memo helper
			 * (encoded_tool_result_response()) — the first-run encode is
			 * byte-identical, and a conversation replaying the same DTO
			 * instances skips the re-encode of every historical result.
			 */
			$encoded = $this->encoded_tool_result_response( $function_response );

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
	 * The JSON encoding of one tool result's response value, memoized by
	 * DTO identity (glm21-4).
	 *
	 * Same contract as the JsonEncodeGuard::encode() call it wraps —
	 * byte-identical first-run encoding (the glm16-4 convention), the
	 * same typed rejection on an unencodable value — minus the repeat:
	 * a conversation replaying the same DTO instances (the in-memory
	 * tool-loop shape) reads the memo instead of re-encoding every
	 * historical result on every request build. Rejections never
	 * memoize (the glm16-6 discipline): the guard throws before any
	 * entry lands, so an unencodable DTO re-proves and re-rejects
	 * identically on every build.
	 *
	 * @since 0.2.0
	 *
	 * @param FunctionResponse $function_response The tool result being mapped.
	 * @return string The JSON encoding of the response value.
	 */
	private function encoded_tool_result_response( FunctionResponse $function_response ): string {
		if ( null === $this->tool_result_encode_memo ) {
			$this->tool_result_encode_memo = new \SplObjectStorage();
		} elseif ( $this->tool_result_encode_memo->offsetExists( $function_response ) ) {
			return $this->tool_result_encode_memo->offsetGet( $function_response );
		}

		$encoded = JsonEncodeGuard::encode( $function_response->getResponse(), 'a tool result', self::PROVIDER_LABEL );

		$this->tool_result_encode_memo->offsetSet( $function_response, $encoded );

		return $encoded;
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

		/*
		 * GLM3 #5: when the gateway omits (or mangles) the
		 * text/event-stream Content-Type, this sniff decides the parser —
		 * and it recognized only 'event:'/'data:' as the first
		 * non-whitespace bytes. A legal SSE comment line (': keepalive')
		 * or a UTF-8 BOM before the first field misrouted the stream to
		 * the JSON parser, which failed with 'Missing the "content" key'
		 * instead of returning the aggregated completion. The sniff skips
		 * a leading BOM (the aggregator strips its own copy, GLM3 #7) and
		 * also accepts a comment line: ':' can only be SSE framing — a
		 * JSON body never starts with one.
		 *
		 * GLM4 #3: the mechanism lives in the shared EventStreamSniff so
		 * the OpenAI surface's copy (which still recognized a bare
		 * 'data:' leader) can never drift from this one again.
		 */
		if ( ! EventStreamSniff::matches( $body, $response->getHeaderAsString( 'Content-Type' ) ) ) {
			/*
			 * GLM8 #3: a UTF-8 BOM prepended to an otherwise-valid JSON
			 * Messages body — the same gateway/CDN threat class the SSE
			 * side strips through the shared SseFrameBuffer — made the
			 * vendor Response::getData() decode fail (JSON_ERROR_SYNTAX)
			 * and the whole non-streaming generation die as 'Missing the
			 * "content" key': a typed rejection of a valid completion one
			 * layer short of where this branch's own BOM hardening stops.
			 * The canonical prefix strip (strip_stream_prefix(), stripping
			 * the leading whitespace and any BOM since glm21-1) runs
			 * before BOTH decodes here, so the associative parse and the
			 * raw object-ness oracle always read the same cleaned body.
			 *
			 * GLM10 #11: the decode block itself — the strip, the
			 * associative view, the raw object-ness view, the vendor
			 * null normalization — rides the one shared JsonBodyDecoder
			 * with the zai model's non-streaming decode; the
			 * (array|null, stdClass|null) contract is the helper's.
			 *
			 * glm25-4: the former parse_message_body()/
			 * parse_body_string() two-hop chain collapsed to this one
			 * inline (the zai twin's shape) once glm15-7/glm16-8 moved
			 * the JSON fallback to the shared owners and stranded the
			 * chain's second caller.
			 */
			list( $data, $raw ) = JsonBodyDecoder::decode( (string) $response->getBody() );

			return $this->parse_decoded_message( $data, $raw );
		}

		$aggregator = new AnthropicSseAggregator();
		$aggregator->feed( $body );
		$aggregator->finish();

		if ( $aggregator->has_error() ) {
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'stream',
				'The message stream contained an error event.'
			);
		}

		$aggregated = $aggregator->aggregated();

		if ( $aggregator->has_malformed_tool_input() ) {
			/*
			 * Truncated/corrupt streamed tool arguments: substituting {}
			 * would fabricate a tool call whose inputs the model never
			 * produced (Codex R1 finding 1), so the response fails as a
			 * parse error with a fixed message instead.
			 *
			 * glm20-2: this check runs BEFORE the malformed-event check —
			 * the flags are independent and latch simultaneously when one
			 * frame corrupts while a tool block's input JSON is also
			 * truncated, and the event-first order permanently masked the
			 * actionable tool-args diagnosis behind the generic frame
			 * error. The GLM8 #5 JSON fallback below stays untouched: its
			 * live scenario (a whole JSON body mislabeled as a stream)
			 * produces no tool blocks, so this flag cannot be set there
			 * and the fallback's position after it is unchanged.
			 */
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'stream',
				'A tool_use block in the message stream carried malformed input JSON.'
			);
		}

		if ( $aggregator->has_malformed_event() ) {
			/*
			 * GLM8 #5: the sniff trusts a text/event-stream Content-Type
			 * before inspecting the body (the documented precedence — the
			 * body sniff is the fallback for mangled/omitted headers, not
			 * a veto of the header), so a doubly-nonconforming gateway
			 * that labels a VALID JSON Messages body with the stream
			 * header died here as 'malformed event frame' — one JSON
			 * object, no data: line, no message_start. A stream whose
			 * aggregation failed is exactly the signal the label may have
			 * lied: before surfacing the stream verdict, try the JSON the
			 * label promised the body wasn't. A body that parses as a
			 * Messages payload completes the generation; anything else
			 * (not a JSON object, or not a Messages payload) keeps the
			 * stream-typed error below — the header DID promise a stream.
			 */
			$fallback = $this->json_fallback_result( $body );

			if ( null !== $fallback ) {
				return $fallback;
			}

			/*
			 * A declared event frame was undecodable or wrongly shaped
			 * (Codex R4 #3), or aggregated() refused the stream — e.g. no
			 * message_start was received (Codex R8 #3), which must never
			 * produce a payload. The check runs AFTER aggregated() because
			 * aggregation itself raises the flag.
			 *
			 * glm35-1: this channel is the stream parse's ONLY unusable-
			 * stream verdict. Every aggregated() null return raises the
			 * flag first — this wire's required message_start/
			 * message_delta/message_stop lifecycle makes every unusable
			 * stream a flagged truncation (Codex R8 #3, GLM7 #5, Codex
			 * R16 #1) — so a former separate no-usable-event branch below
			 * this check was unreachable dead code and is deleted
			 * (source-pinned absent). The zai twin keeps its null branch
			 * LIVE: an OpenAI event stream with no choices is unusable
			 * without being malformed, a shape this wire's required
			 * lifecycle cannot produce.
			 */
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'stream',
				'The message stream contained a malformed event frame.'
			);
		}

		/*
		 * GLM2 #10: the aggregated payload is passed through DECODED. The
		 * previous wp_json_encode() into a synthetic 200 Response forced
		 * the parser to re-decode the whole payload twice (getData()'s
		 * associative decode plus the raw-oracle decode) — three whole-
		 * payload serialization passes per streamed generation on top of
		 * the per-frame decodes the aggregator already did. The
		 * aggregator's payload preserves every shape the parser needs (its
		 * tool_use input stays the raw-decoded object, GLM1 #3; its usage
		 * is object-keyed; its content is a constructed PHP list), so the
		 * null raw oracle's documented fallbacks cover each member with
		 * identical semantics and none of the round trip.
		 */
		return $this->parse_decoded_message( $aggregated, null );
	}

	/**
	 * The JSON fallback for a body the Content-Type mislabeled as a
	 * stream (GLM8 #5), or null when the body is no Messages payload.
	 *
	 * GLM15-7: ONE strip and ONE pair of decodes serve both the
	 * object-root gate and the parse — the hand-rolled
	 * is_object(json_decode(strip_stream_prefix(...))) pre-flight used
	 * to strip and decode the same body two more times inside the
	 * non-streaming decode's JsonBodyDecoder (three json_decodes and
	 * two prefix strips of one potentially large body). The decoder's
	 * raw view IS the object-root oracle (stdClass only), so the gate
	 * rides it and the parse consumes the already-decoded pair.
	 *
	 * Runs only AFTER SSE aggregation failed (the caller's
	 * malformed-event channel), so a genuinely-decodable stream never
	 * re-routes: only a body that decodes as a JSON OBJECT is even
	 * attempted, and only a full Messages-payload parse succeeds. A
	 * ResponseException from the parse (a JSON object, but not a valid
	 * Messages body) returns null so the caller surfaces its
	 * stream-typed error; the typed truncation outcome
	 * (TokenLimitReachedException) is a successful parse of a valid body
	 * and propagates, as do the plugin's own fixed-message tool-arguments
	 * rejections (FixedMessageResponseException, glm14-2).
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The raw response body.
	 * @return GenerativeAiResult|null The parsed result, or null when the
	 *                                 body is not a Messages payload.
	 * @throws FixedMessageResponseException When the body IS a JSON object
	 *                                       whose parse produced one of this
	 *                                       plugin's precise fixed-message
	 *                                       rejections (glm14-2). The typed
	 *                                       truncation outcome
	 *                                       (TokenLimitReachedException,
	 *                                       documented above) propagates
	 *                                       uncaught as well.
	 */
	private function json_fallback_result( string $body ): ?GenerativeAiResult {
		/*
		 * glm16-8: the scaffold (one decode, the object-root gate, the
		 * glm14-2 marker contract) rides the shared JsonFallbackResult
		 * owner; this surface's parse consumes the already-decoded pair
		 * (glm15-7's one-decode contract).
		 */
		return JsonFallbackResult::parse(
			$body,
			function ( $data, $raw ) {
				return $this->parse_decoded_message( $data, $raw );
			}
		);
	}

	/**
	 * Runs the Messages parser over an already-decoded payload.
	 *
	 * The raw (non-associative) oracle is optional: the non-streaming path
	 * always supplies it from the body, while the consolidated-stream path
	 * (GLM2 #10) passes the aggregator's decoded payload with none — its
	 * members carry no {}/[]-collapsible ambiguity (tool inputs stay
	 * stdClass, usage is object-keyed, content is a constructed list), so
	 * the documented fallbacks decide identically.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null     $data     The associatively decoded payload.
	 * @param \stdClass|null $raw_body Non-associative decode of the same payload, or null.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException When the payload is malformed.
	 * @throws FixedMessageResponseException When a parsed turn carries no
	 *                                       (translatable) part (glm34-2, the
	 *                                       zai twin's glm28-4 parity) or a
	 *                                       tool_use input rejects precisely
	 *                                       (glm14-2).
	 */
	private function parse_decoded_message( $data, ?\stdClass $raw_body ): GenerativeAiResult {
		/*
		 * glm34-3 (round-34 finding 3): presence is judged with
		 * array_key_exists semantics — the isset() probe collapsed an
		 * explicitly-present "content": null onto the ABSENT member and
		 * misrouted it through fromMissingData, while every sibling
		 * envelope member (type, stop_reason, usage) sends an
		 * explicitly-null value through the invalid-data channel. The
		 * split names the non-array PRESENT value with the same reason
		 * string the raw-oracle probe below uses, on every transport
		 * (the streamed path passes no raw oracle — its entry check is
		 * this one). The genuinely-ABSENT member keeps fromMissingData.
		 */
		if ( ! \is_array( $data ) || ! \array_key_exists( 'content', $data ) ) {
			throw ResponseException::fromMissingData( self::PROVIDER_LABEL, 'content' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		if ( ! \is_array( $data['content'] ) ) {
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'The message content must be a JSON array.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		/*
		 * Codex R6 #5: the top-level content member must be a JSON ARRAY.
		 * The associative decode collapses a JSON object {} into the same
		 * empty PHP array as an empty list, so "content": {} slipped past
		 * the is_array() check above and returned a successful candidate
		 * with no parts. The raw (non-associative) decode preserves the
		 * distinction: only a JSON array decodes to a PHP list. The
		 * oracle-less (aggregated stream) payload needs no probe — its
		 * content is constructed as a PHP list by the aggregator.
		 *
		 * glm24-7: property_exists FIRST, the shape the usage/input probes
		 * below already use — the former ('! is_array( $raw_body->content
		 * ?? null )' before the existence check) was truth-table-identical
		 * but read as though a missing member could reach the array
		 * judgment, and the ?? null existed only to keep that inverted
		 * order safe.
		 */
		if ( null !== $raw_body && \property_exists( $raw_body, 'content' ) && ! \is_array( $raw_body->content ) ) {
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'The message content must be a JSON array.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		/*
		 * Object-ness oracle (Codex R3 #1): getData() decodes the body
		 * ASSOCIATIVELY, which collapses the JSON object {} and the JSON
		 * list [] into the same empty PHP array. A parallel non-
		 * associative decode of the same body preserves the distinction
		 * ({} is stdClass, [] is an array) and is handed to each content
		 * block alongside the associative value.
		 *
		 * glm24-7: no $raw_content_ok mirror — a non-null $raw_body IMPLIES
		 * its content member is a present array here (the associative check
		 * at the top proved the member exists in the same payload's other
		 * decode, and the object-ness probe above proved it is an array),
		 * so the per-part probe below needs only the null check.
		 */

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
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'type', 'The response envelope did not identify itself as a message.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		/*
		 * Codex R5 #3: a Messages GENERATION response must be an assistant
		 * message — require the exact role. A missing, unknown, or `user`
		 * role previously fabricated an assistant turn or, worse, exposed
		 * the payload as a generated USER message, mis-attributing content
		 * into downstream history.
		 */
		if ( ! isset( $data['role'] ) || ! \is_string( $data['role'] ) || 'assistant' !== $data['role'] ) {
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'role', 'The message did not identify itself as an assistant response.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		$role = MessageRoleEnum::model();

		$parts            = array();
		$seen_tool_ids    = array();
		$dropped_unmapped = false;
		foreach ( $data['content'] as $index => $part_data ) {
			if ( ! \is_array( $part_data ) ) {
				throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'Every content entry must be an object.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
			}

			$raw_part = null !== $raw_body && isset( $raw_body->content[ $index ] ) && \is_object( $raw_body->content[ $index ] )
				? $raw_body->content[ $index ]
				: null;

			$part = $this->parse_content_block( $part_data, $raw_part );

			/*
			 * Codex R13 #4: two tool_use blocks with the same id are
			 * ambiguous identities — a consumer cannot correlate results
			 * to calls, and replaying the assistant turn hits this
			 * adapter's own outbound duplicate-id rejection after tools
			 * may have executed. Rejected in the same channel as other
			 * malformed content blocks.
			 *
			 * glm28-7: the id re-validation guards were dead — a non-null
			 * part under type tool_use has, by parse_content_block()'s
			 * Codex R9 #3 identity loop, a non-empty string id already
			 * (the callee THROWS on anything less before its tool_use
			 * return), and the null-return path exists only for the
			 * non-tool_use unmapped blocks. The callee's contract is
			 * relied on directly now (the old comment's "empty/absent ids
			 * keep their existing malformed-id handling" claim was itself
			 * stale — such ids throw inside the callee).
			 */
			if ( null !== $part && 'tool_use' === ( $part_data['type'] ?? null ) ) {
				if ( isset( $seen_tool_ids[ $part_data['id'] ] ) ) {
					throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'Two tool_use blocks carried the same id.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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

		/*
		 * GLM8 #4: the Anthropic Messages schema types stop_reason as
		 * string|null, so an explicitly-present null is schema-legal —
		 * the old isset() presence probe treated it as MISSING and
		 * discarded a successful generation as 'Missing data:
		 * stop_reason'. Presence is judged with array_key_exists() (an
		 * explicit null IS present); the null value itself maps to the
		 * neutral natural-stop finish reason in finish_reason_for(),
		 * still subject to the stop-reason/content consistency check
		 * below. The typed missing-data rejection keeps covering the
		 * genuinely ABSENT member.
		 */
		if ( ! \array_key_exists( 'stop_reason', $data ) ) {
			throw ResponseException::fromMissingData( self::PROVIDER_LABEL, 'stop_reason' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		/*
		 * GLM2 #5: every sibling envelope member (type, role, id) gets an
		 * is_string shape guard, but stop_reason was only (string)-cast —
		 * a non-scalar value emitted an Array-to-string warning before the
		 * typed rejection and, on warning-strict installs (a custom
		 * set_error_handler, PHPUnit), aborted the parse with an
		 * ErrorException-family Throwable that bypassed this channel.
		 *
		 * GLM8 #4: null is the one non-string value that passes — the
		 * schema's own nullable case above.
		 */
		if ( null !== $data['stop_reason'] && ! \is_string( $data['stop_reason'] ) ) {
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'stop_reason', 'The message did not carry a string stop reason.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		$finish_reason = $this->finish_reason_for( $data['stop_reason'] );

		/*
		 * GLM3 #1 (parse/replay agreement): a response whose parsed turn
		 * carries parts but ZERO translatable ones — an empty text block,
		 * a thinking-only turn — parsed as a successful generation, while
		 * the OUTBOUND mapper drops exactly those parts
		 * (message_part_block()) and rejects the replayed turn
		 * pre-transport. One such response permanently poisoned the
		 * conversation history: every later request in that conversation
		 * failed until the turn was removed. The parse now applies the
		 * outbound contract at parse time — a turn that cannot be
		 * replayed cannot be a generation. Checked after
		 * finish_reason_for() so the typed truncation exceptions keep
		 * their precedence (same ordering rule as the consistency check
		 * below).
		 */
		if ( array() !== $parts && ! TranslatableMessageParts::has_translatable_part( $parts, self::EMPTY_TEXT_TRANSLATES ) ) {
			/*
			 * glm34-2 (round-34 finding 2): the marker family — the zai
			 * twin has thrown FixedMessageResponseException for this
			 * identical condition since glm28-4, and the plain
			 * ResponseException here made the mislabeled-Content-Type
			 * fallback (json_fallback_result()'s null-out catch) eat the
			 * precise message and surface the generic stream error
			 * instead, the exact asymmetry glm14-2's marker contract
			 * exists to prevent.
			 */
			throw FixedMessageResponseException::fixed(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'content',
				'The message carried no translatable (text, tool call, or tool result) part, so it cannot be replayed into the conversation history.'
			);
		}

		/*
		 * GLM3 #2: the parse_content_block() KNOWN LIMITATION guarantees
		 * that a content list of ONLY unmapped blocks "rejects as a
		 * ResponseException" — but the consistency check below
		 * cross-checked tool_use alone, so an empty or unmapped-only
		 * content list parsed as a SUCCESS with zero parts under every
		 * ordinary stop reason, bypassing the typed zai_invalid_response
		 * channel (consumers hit the SDK's untyped toText()
		 * RuntimeException instead). Zero parts now rejects REGARDLESS of
		 * the stop reason; the message names the dropped blocks when that
		 * was the case (code-review #15).
		 *
		 * GLM5 #4 removed GLM3 #2's one documented tolerance (an empty
		 * content list under stop_reason refusal parsing as a successful
		 * contentFilter result): the turn that tolerance manufactured
		 * carries ZERO parts, which this adapter's own outbound mapper
		 * rejects pre-transport on replay ('requires every message to
		 * carry at least one translatable part') — so appending it to the
		 * history poisoned every later request of the conversation, the
		 * exact violation the GLM3 #1 parse/replay-agreement contract
		 * exists to prevent. A refusal that carries content (the
		 * protocol's ordinary shape) still parses as a successful
		 * contentFilter result; mapper and parser now agree on the empty
		 * one.
		 */
		if ( array() === $parts ) {
			// glm34-2: the marker family, same as the zero-translatable
			// sibling above (the zai twin's glm28-4 guard covers both
			// shapes with one marker throw).
			throw FixedMessageResponseException::fixed(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'content',
				$dropped_unmapped
					? 'The message contained no usable content (all blocks were of unmapped types).'
					: 'The message contained no content blocks.'
			);
		}

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
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'id', 'The response did not carry a non-empty message id.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
		 *
		 * GLM4 #11: the validation lives in the shared
		 * UsageValidator — the aggregator's streamed copy had to
		 * be fixed in lockstep once already (Codex R15 #1); one source
		 * keeps the two transports of one generation identical.
		 */
		$usage_data = array();
		if ( \array_key_exists( 'usage', $data ) ) {
			/*
			 * glm26-7: the rejection composition rides
			 * UsageValidator::reject() — the throw this block hand-composed
			 * (and the zai model's reject_bad_usage() re-composed
			 * token-identically modulo member list, lenient flag, and
			 * label) lives with the rules and messages it applies. The
			 * argument shape is the inlined form's, verbatim.
			 */
			UsageValidator::reject(
				\is_array( $data['usage'] ) ? $data['usage'] : null,
				null !== $raw_body && \property_exists( $raw_body, 'usage' ) ? $raw_body->usage : null,
				self::PROVIDER_LABEL
			);

			$usage_data = $data['usage'];
		}

		/*
		 * GLM4 #5: every member passed the is_int/>=0 validation above
		 * individually, but their SUM can exceed PHP_INT_MAX — int+int
		 * overflow silently promotes the sum to float, and TokenUsage's
		 * int-typed constructor then threw an uncaught TypeError that
		 * surfaced as the generic 500 (zai_error) instead of the typed
		 * zai_invalid_response every other malformed-usage shape
		 * produces. The shared validator totals with an explicit bound
		 * check per member, so no intermediate ever promotes and the
		 * boundary total PHP_INT_MAX itself stays representable.
		 */
		$total = UsageValidator::total( $usage_data );

		if ( null === $total ) {
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'usage',
				'The reported token counts exceed the platform integer range.'
			);
		}

		$output = (int) ( $usage_data['output_tokens'] ?? 0 );
		$input  = $total - $output; // Both non-negative ints with $total >= $output: in range by construction.
		$usage  = new TokenUsage( $input, $output, $total );

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
	 * @throws FixedMessageResponseException When a tool_use block's input or
	 *                                       arguments are corrupt (glm14-2:
	 *                                       the marker subclass, so the
	 *                                       mislabeled-JSON fallback passes
	 *                                       the precise diagnostic through).
	 */
	private function parse_content_block( array $part_data, ?\stdClass $raw_part ): ?MessagePart {
		$type = $part_data['type'] ?? null;

		/*
		 * GLM5 #5: the unvalidated block type reached switch($type),
		 * whose loose == semantics accept a non-string as a known type
		 * (true == 'text' on every PHP version; 0 == 'text' on the
		 * declared PHP 7.4 target), so a corrupt block like
		 * {"type":true,"text":"hello"} PARSED instead of hitting the
		 * typed unsupported-type rejection — the same coercion class the
		 * GLM2 #5 is_string guard closed for stop_reason. A missing type
		 * kept the same rejection through the switch default; the guard
		 * now decides every non-string shape the same way.
		 */
		if ( ! \is_string( $type ) ) {
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'The message contained a block of an unsupported type.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
		}

		/*
		 * glm34-8: the unmapped trio rides the shared vocabulary
		 * constant (AnthropicContentBlocks::KNOWN_UNMAPPED_TYPES) — the
		 * aggregator's streamed drop and this parse's ride ONE table,
		 * so the mapped/unmapped decision is one catalog edit with a
		 * lockstep pin behind it, never a two-file hand-enumeration.
		 */
		if ( \in_array( $type, AnthropicContentBlocks::KNOWN_UNMAPPED_TYPES, true ) ) {
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
			 * no parts, which parse_decoded_message() rejects as a
			 * ResponseException (zai_invalid_response) regardless of
			 * the stop reason (GLM3 #2 made this guarantee real; GLM5
			 * #4 removed the empty-refusal tolerance so the guarantee
			 * has no exception — a zero-part turn can never replay),
			 * with a message naming the dropped blocks when that was
			 * the case. The streamed path drops the same shapes
			 * earlier (the aggregator's content_block_payload()).
			 */
			return null;
		}

		/*
		 * glm35-4: the required-string-member rule rides the shared
		 * vocabulary table (AnthropicContentBlocks::
		 * STRING_CONTENT_MEMBERS) — the map's own contract says every
		 * consumer does — instead of the per-arm hand-rolled
		 * isset+is_string probes the glm34-8 lockstep pin could not
		 * see. Only the non-tool block types carry an entry (tool_use's
		 * identity and input rules are its own arm below); an unknown
		 * type has no entry and reaches the switch's typed
		 * unsupported-type rejection unchanged. The message names the
		 * type and member from the closed constant table —
		 * byte-identical to the per-arm spellings it replaces.
		 */
		$member = AnthropicContentBlocks::STRING_CONTENT_MEMBERS[ $type ] ?? null;
		if ( null !== $member && ( ! isset( $part_data[ $member ] ) || ! \is_string( $part_data[ $member ] ) ) ) {
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', sprintf( 'A %s block is missing its %s member.', $type, $member ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5): the interpolated type and member are closed constant-table keys, never upstream free text; escaping belongs to the display layer.
		}

		switch ( $type ) {
			case 'text':
				return new MessagePart( $part_data['text'] );

			case 'thinking':
				return new MessagePart( $part_data['thinking'], MessagePartChannelEnum::thought() );

			case 'tool_use':
				// Codex R9 #3: identities must be non-empty strings, not
				// merely present — an empty id/name is a corrupt block.
				foreach ( array( 'id', 'name' ) as $member ) {
					if ( ! isset( $part_data[ $member ] ) || ! \is_string( $part_data[ $member ] ) || '' === $part_data[ $member ] ) {
						throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'A tool_use block is missing its identity members.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
					$args = isset( $part_data['input'] ) ? $part_data['input'] : null;

					if ( $args instanceof \stdClass ) {
						/*
						 * Already a decoded JSON object — the consolidated
						 * stream path (GLM2 #10) hands the aggregator's
						 * raw-preserved input (GLM1 #3) straight through
						 * with no wire round trip: {} means no arguments
						 * and any other object converts exactly like the
						 * raw-oracle branch below it (GLM5 #1: the walk
						 * lives on the shared Support\ToolArgsObjectNess,
						 * used by the zai surface's parser too).
						 *
						 * glm19-14 (verifier round) — the TRUST BOUNDARY,
						 * stated honestly: this sub-path stamps the part
						 * (GLM12 #12) on the aggregator's word. Every
						 * production caller reaching here IS the
						 * aggregator channel, whose input was replay-
						 * validated at acceptance — precisely for the
						 * accumulated-JSON fragments, conservatively for
						 * the initial input (the GLM12 #8 split) — and
						 * re-validating here through the conservative
						 * walker would REJECT the exact big literals that
						 * precise rule accepted, undoing glm12-8 on its
						 * own production channel. But the sub-path cannot
						 * PROVE origin: a caller-built stdClass with a
						 * null raw view (no such caller today) would
						 * stamp UNVALIDATED — the residual latent shape
						 * glm19-3 closed for the associative sibling but
						 * deliberately leaves open here, because closing
						 * it costs the precise rule. Pinned as boundary,
						 * not accident: see the glm19-14 test.
						 */
						$args = array() === get_object_vars( $args ) ? null : ToolArgsObjectNess::from_raw( $args );
					} elseif ( null === $args || ! self::is_object_shape( $args ) ) {
						/*
						 * No raw oracle (defensive: should not happen): the
						 * strictest associative probe decides — it also rejects
						 * the ambiguous empty array ({} and [] are
						 * indistinguishable here, so both fail; the empty
						 * array therefore needs no separate normalization —
						 * GLM5 #19 removed the dead elseif that never ran
						 * behind this rejecting branch).
						 *
						 * glm14-2: the tool_use input rejections carry the
						 * marker subclass (the byte-identical
						 * FixedMessageResponseException) so the mislabeled-
						 * JSON fallback passes them through instead of
						 * degrading them to the generic stream message.
						 */
						throw FixedMessageResponseException::fixed( self::PROVIDER_LABEL, 'content', 'A tool_use block is missing its input member.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
					} elseif ( ! ToolArgsReplayGuard::is_replayable( $args ) ) {
						/*
						 * glm19-3: the defensive associative sub-path has NO
						 * validator behind it (the stdClass sub-path above
						 * rides the aggregator's trust — see the glm19-14
						 * boundary note there), so an associative payload
						 * with a null raw oracle is caller-built territory,
						 * and the GLM12 #12 stamp at the return used to
						 * apply to it unconditionally — permanently
						 * disabling the outbound replay oracle for
						 * arguments that never passed ANY check. The stamp
						 * now rides a proof: the FULL is_replayable()
						 * oracle (not the decoded fast path — a
						 * caller-built tree can carry INF, NAN, invalid
						 * UTF-8, or recursion the walker alone would miss),
						 * the same oracle the outbound guard runs, so parse
						 * verdict and replay verdict stay in agreement.
						 */
						throw FixedMessageResponseException::fixed( self::PROVIDER_LABEL, 'content', 'A tool_use block carried arguments that cannot be replayed (an unencodable or precision-loss value was decoded).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
					}
				} else {
					$raw_input = \property_exists( $raw_part, 'input' ) ? $raw_part->input : null;

					if ( null === $raw_input ) {
						// Missing input member or explicit null (R7 #1).
						throw FixedMessageResponseException::fixed( self::PROVIDER_LABEL, 'content', 'A tool_use block is missing its input member.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
					} elseif ( \is_object( $raw_input ) ) {
						if ( array() === get_object_vars( $raw_input ) ) {
							// The empty object {} means "no arguments"
							// (official-plugin normalization).
							$args = null;
						} else {
							$args = ToolArgsObjectNess::from_raw( $raw_input );
						}
					} else {
						// A scalar, boolean, or JSON list value (an empty
						// list included).
						throw FixedMessageResponseException::fixed( self::PROVIDER_LABEL, 'content', 'A tool_use block carried a non-object input value.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
					}
				}

				/*
				 * GLM4 #2 (parse/replay agreement, the GLM3 #1 contract
				 * applied to argument VALUES): 1e999 decodes to INF and an
				 * integer beyond PHP_INT_MAX to a lossy float — a turn
				 * carrying either cannot re-enter a request (the replay
				 * would throw at the transport's whole-request encode and
				 * poison every later request of the conversation), so it
				 * is rejected pre-acceptance, here, instead of handing a
				 * consumer arguments that detonate on replay. The shared
				 * guard is the same one the SSE acceptance points use, so
				 * the two transports of one generation never diverge.
				 * GLM9 #13: the decoded fast path — $args is a
				 * json_decode() product on every branch above.
				 *
				 * GLM12 #8: the check scopes to the NON-STREAMING branch
				 * ($raw_part set — the only tool-args acceptance point a
				 * body decode reaches). The consolidated-stream branch's
				 * input was ALREADY replayability-validated by the
				 * aggregator — its accumulated-JSON channel precisely (the
				 * raw fragment string is in hand there:
				 * wire_arguments_are_replayable()), its initial-input
				 * channel conservatively — and has_malformed_tool_input()
				 * fails the response before this parse ever runs; re-
				 * checking here through the conservative walker would
				 * undo the precise rule for exact big literals.
				 */
				if ( null !== $raw_part && null !== $args && ! ToolArgsReplayGuard::is_replayable_decoded( $args ) ) {
					/*
					 * glm14-2: the marker subclass (byte-identical on the
					 * wire) so the mislabeled-JSON fallback surfaces this
					 * precise diagnostic instead of the generic stream
					 * message — parity with the zai surface's glm13-7
					 * marker family.
					 */
					throw FixedMessageResponseException::fixed(
						self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
						'content',
						'A tool_use block carried arguments that cannot be replayed (an unencodable or precision-loss value was decoded).'
					);
				}

				/*
				 * GLM12 #12: the args above passed this surface's replay
				 * validation (the model-level check here, or the
				 * aggregator's precise/conservative rule for the
				 * consolidated-stream branch) — the part returns as a
				 * STAMPED twin so the outbound replay guard skips its
				 * serializing oracle for it on every later request of the
				 * conversation (see ReplayValidatedFunctionCall).
				 */
				return new MessagePart( new ReplayValidatedFunctionCall( $part_data['id'], $part_data['name'], $args ) );
		}

		throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'The message contained a block of an unsupported type.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
	}

	/**
	 * Whether a decoded JSON value has an OBJECT shape (Codex R2 #1, R3 #1).
	 *
	 * The response body is decoded associatively, so JSON objects and JSON
	 * lists both arrive as PHP arrays — and the EMPTY object {} and the
	 * empty list [] collapse to the same empty array. Lists (including
	 * []), scalars, booleans, and null all fail the test. The pathological
	 * numeric-keyed JSON object ({"0":…}) is indistinguishable from a list
	 * after an associative decode and is rejected with it.
	 *
	 * glm16-9: the sequential-key rule rides the shared JsonShape
	 * predicate (GLM8 #13's one source) — the private re-encode probe
	 * this replaces was a fifth hand-rolled copy of the same rule, and
	 * it paid a full json_encode per tool_use input member on every
	 * response parse. Key inspection decides identically on every
	 * DECODED input (a json_decode tree is always encodable — the
	 * probe's unencodable-array branch was unreachable here) and
	 * sidesteps the GLM7 #10 raw-oracle divergence question outright.
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

		return ! JsonShape::is_list( $value );
	}

	/**
	 * The effective max_tokens value for the CURRENT config (glm19-7).
	 *
	 * One resolution rule for both consumers — the wire member at the
	 * params build and the TokenLimitReachedException payload at the
	 * finish-reason walk. The expression was written out twice; a
	 * defaulting-rule edit at one site but not the other would make the
	 * token-limit error message and WP_Error payload report a limit the
	 * request never carried, so both read this one helper (the payload
	 * drops its old absint() wrap: getMaxTokens() is ?int, the
	 * expression is already int, and the payload's job is to name
	 * EXACTLY what the wire member carried).
	 *
	 * @since 0.2.0
	 *
	 * @return int The configured max_tokens, or the surface default.
	 */
	private function effective_max_tokens(): int {
		return $this->getConfig()->getMaxTokens() ?? self::DEFAULT_MAX_TOKENS;
	}

	/**
	 * Maps a Messages stop reason to the SDK finish reason.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $stop_reason The upstream stop reason (null is the
	 *                                 schema-legal explicit case, GLM8 #4).
	 * @return FinishReasonEnum The SDK finish reason.
	 * @throws ResponseException          For unknown stop reasons (fixed message).
	 * @throws TokenLimitReachedException When generation stopped at the token limit.
	 */
	private function finish_reason_for( ?string $stop_reason ): FinishReasonEnum {
		/*
		 * GLM8 #4: the Messages schema types stop_reason as string|null;
		 * an explicit null is accepted, not treated as corruption. The
		 * SDK's finish reason is non-nullable, so the null maps to the
		 * neutral natural-stop value — the finish reason carrying the
		 * least claim about why the model stopped — while the
		 * stop-reason/content consistency check in parse_decoded_message()
		 * still judges the turn exactly like any non-tool_use reason (a
		 * null with tool calls stays a rejected contradiction).
		 */
		if ( null === $stop_reason ) {
			return FinishReasonEnum::stop();
		}

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
				$max_tokens = $this->effective_max_tokens();

				throw new TokenLimitReachedException(
					sprintf(
						/* translators: %d: the configured token limit. */
						__( 'The generation stopped because the token limit was reached (%d). Raise maxTokens to continue longer answers.', 'zai' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
						$max_tokens // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer from effective_max_tokens(), formatted via %d.
					),
					$max_tokens // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- integer payload for the typed accessor, from effective_max_tokens().
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

		throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'stop_reason', 'The message carried an unknown stop reason.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
		 *
		 * GLM7 #15: this surface's prepareGenerateTextParams() never
		 * emits presence_penalty/frequency_penalty/logprobs/top_logprobs,
		 * so its WIRE_FORWARDED justification states that truthfully
		 * (cross-surface contract) instead of the forwarding only the
		 * zai surface's SDK-parent builder performs.
		 */
		AdvertisedOptionGuard::reject_unsupported( $config, self::PROVIDER_LABEL, false );

		/*
		 * GLM2 #9: the five usage rejections the two surfaces advertise
		 * IDENTICALLY (candidateCount, text-only output modalities, the
		 * MIME whitelist, text-only input, custom options) are shared too
		 * — they were verbatim twins directly under this call, the exact
		 * duplication pattern the guard above was extracted to stop.
		 */
		AdvertisedUsageGuard::reject_unsupported( $config, $prompt, self::PROVIDER_LABEL );

		/*
		 * max_tokens is required and must be positive; a zero/negative value
		 * would be a protocol error upstream. glm19-5: the rule lives on
		 * the shared RequestShapeGuard (glm18-3's twin parity).
		 */
		RequestShapeGuard::reject_non_positive_max_tokens( $config->getMaxTokens(), self::PROVIDER_LABEL );

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
		 *
		 * glm21-6: the two copy-twin guards ride the shared
		 * RequestShapeGuard's label- and member-parameterized unit-
		 * interval rule now (byte-identical messages) — a bound tweak
		 * can no longer land on one member only.
		 */
		// glm23-15: the Messages-only range phrase rides the call site — the
		// shared guard stays protocol-neutral.
		RequestShapeGuard::reject_out_of_unit_interval( $config->getTemperature(), 'temperature', self::PROVIDER_LABEL, 'the Anthropic Messages protocol range' );
		RequestShapeGuard::reject_out_of_unit_interval( $config->getTopP(), 'top_p', self::PROVIDER_LABEL, 'the Anthropic Messages protocol range' );

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
		/*
		 * glm29-5: the empty-prompt rule rides the shared
		 * RequestShapeGuard now (byte-identical message and channel) —
		 * the zai twin shipped "messages": [] to a spec-faithful 400
		 * (glm18-3's maxTokens-parity class, the member that round
		 * left open), and a message tweak must not land on one surface
		 * only.
		 */
		RequestShapeGuard::reject_empty_prompt( $prompt, self::PROVIDER_LABEL );

		$first_role = $this->message_role_string( $prompt[0]->getRole() );
		if ( 'user' !== $first_role ) {
			throw new InvalidArgumentException(
				sprintf( 'The %s provider requires the first message to use the user role.', self::PROVIDER_LABEL ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			);
		}
	}
}
