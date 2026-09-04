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
use Deicod\WpConnectors\Zai\Authentication\ZaiAnthropicRequestAuthentication;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Support\AdvertisedOptionGuard;
use Deicod\WpConnectors\Zai\Support\AdvertisedUsageGuard;
use Deicod\WpConnectors\Zai\Support\AnthropicSseAggregator;
use Deicod\WpConnectors\Zai\Support\JsonBodyDecoder;
use Deicod\WpConnectors\Zai\Support\JsonEncodeGuard;
use Deicod\WpConnectors\Zai\Support\UsageValidator;
use Deicod\WpConnectors\Zai\Support\EventStreamSniff;
use Deicod\WpConnectors\Zai\Support\JsonShape;
use Deicod\WpConnectors\Zai\Support\SafeGenerationBoundary;
use Deicod\WpConnectors\Zai\Support\SseFrameBuffer;
use Deicod\WpConnectors\Zai\Support\ThrowsSafeHttpErrors;
use Deicod\WpConnectors\Zai\Support\ToolArgsObjectNess;
use Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard;

/**
 * Text generation model for zai_anthropic.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {
	use ThrowsSafeHttpErrors;
	use SafeGenerationBoundary;

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
	 * The authentication the credential gate judges: the RAW parent getter,
	 * not this model's protocol-wrapping getRequestAuthentication() override
	 * — the override's wrap() threw a foreign-wiring failure through the
	 * gate's RuntimeException-only skip as a 400 BEFORE validate_request()
	 * (GLM3 #9); the availability gate keys on the API key alone, which the
	 * raw instance carries, and wrap() refuses foreign wiring with the same
	 * binding-failure RuntimeException, so wherever the failure eventually
	 * surfaces it maps to 500 zai_error, never 400. GLM9 #11 wiring hook.
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	protected function gate_authentication(): RequestAuthenticationInterface {
		return parent::getRequestAuthentication();
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
			/*
			 * GLM3 #4: the system member is a wire STRING — the same
			 * invalid-UTF-8 rejection as text parts (the shared
			 * JsonEncodeGuard's raw-oracle rationale), not the
			 * transport's raw JsonException surfaced as the generic 500.
			 */
			JsonEncodeGuard::must_encode( $system_instruction, 'the system instruction', self::PROVIDER_LABEL );

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
			 * replaced had already drifted once.
			 */
			JsonEncodeGuard::must_encode_stop_sequences( $stop_sequences, self::PROVIDER_LABEL );

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
			 * the encode return false, which the string cast silently
			 * turned into '': the guidance ended in "JSON Schema: " and the
			 * model produced unconstrained output even though the caller
			 * requested a schema. Rejected before transport in the same
			 * channel as the R18 tool-result encoding failure.
			 *
			 * GLM4 #1: the oracle is the RAW json_encode() — the same
			 * primitive the GLM3 #4 wire-string guards use (GLM5 #16:
			 * single-sourced on the shared JsonEncodeGuard). Core's
			 * wp_json_encode() lossily rescues invalid UTF-8 and never
			 * returns false for a string in production, so a guard on it
			 * was dead code outside the test stub.
			 */
			$encoded_schema = JsonEncodeGuard::encode( $output_schema, 'the configured output schema', self::PROVIDER_LABEL );

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
			 * GLM6 #9: the identity and description strings ride the
			 * tools member verbatim and were only ever EMPTINESS-checked —
			 * an unencodable one (invalid UTF-8 from a DB row, say)
			 * detonated in the transport's whole-request encode as the
			 * generic 500 instead of the typed pre-transport 400 every
			 * neighboring wire string receives.
			 */
			JsonEncodeGuard::must_encode( $name, 'a declared tool function name', self::PROVIDER_LABEL );
			JsonEncodeGuard::must_encode( $declaration->getDescription(), 'a declared tool function description', self::PROVIDER_LABEL );

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

			if ( \is_array( $input_schema ) && array() !== $input_schema && JsonShape::is_list( $input_schema ) ) {
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
			 * RAW json_encode() oracle the output-schema (R19) and
			 * tool-result (R18) rejections use (GLM4 #1: raw, not
			 * wp_json_encode() — core's lossy UTF-8 rescue made that
			 * variant dead code in production; GLM5 #16: single-sourced on
			 * the shared JsonEncodeGuard); the empty schema keeps its
			 * object normalization below.
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
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SCHEMA_OBJECT_MAP_KEYS = array( 'properties', 'patternProperties', 'definitions', '$defs' );

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
	 * round).
	 *
	 * A `default`/`examples`/`const` value may legitimately contain an
	 * empty list at a key named 'properties' (say, a property-management
	 * tool's default object) — descending into these keywords silently
	 * converted such data to {} on the wire, altering the caller's
	 * default/example values with no upstream error to surface the
	 * change. Annotation keywords carry arbitrary JSON values, so they
	 * pass through verbatim.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SCHEMA_DATA_VALUE_KEYS = array( 'default', 'examples', 'const' );

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
	 * data-valued keywords (required, enum, unknown keywords), and the
	 * DATA-valued annotation keywords (default/examples/const, verbatim)
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

					$value[ $key ] = array() !== $member && self::is_schema_list( $member )
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
	 * Reports whether the array is a plain LIST (integer keys 0..N-1 in
	 * order), the shape a tuple-form 'items' carries (GLM10 #6).
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $value The member to test.
	 * @return bool True when every key is an integer in sequence.
	 */
	private static function is_schema_list( array $value ): bool {
		$expected = 0;

		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}

		return true;
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
			 */
			if ( $role !== $previous_role ) {
				$this->advance_answer_window( $awaiting_answer, $outstanding_tools, $previous_role );

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
							'The zai_anthropic provider requires tool call ids to be unique across the conversation (duplicate tool call id).'
						);
					}

					$seen_tool_ids[ $block['id'] ] = true;

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
	 * - previous turn 'assistant': the answering USER turn BEGINS — the
	 *   outstanding IDs stay answerable (the user turn's own results
	 *   consume them as its messages are processed). The old expiry for a
	 *   NON-user incoming role was provably dead (GLM5 #19):
	 *   message_role_string() only ever produces 'user' or 'assistant',
	 *   and this method runs only on role CHANGE, so an assistant turn
	 *   can only ever be followed by a user turn here — the R9 stale
	 *   semantics are carried entirely by the unmatched-result rejection
	 *   on the consuming side.
	 * - previous turn 'user': the answering coalesced turn has ENDED —
	 *   every ID must have been answered (R10 #1 partial rule, evaluated
	 *   only now that the split messages have merged, per R11 #1).
	 *
	 * @since 0.2.0
	 *
	 * @param bool        $awaiting_answer   Whether a tool-answer window is open (by ref).
	 * @param array       $outstanding_tools Outstanding tool-use IDs.
	 * @param string|null $previous_role     Role of the coalesced turn that just ended.
	 * @return void
	 * @throws InvalidArgumentException When a completed user turn left IDs unanswered.
	 */
	private function advance_answer_window( bool &$awaiting_answer, array $outstanding_tools, $previous_role ): void {
		if ( ! $awaiting_answer ) {
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
			 * every is_string check and detonated as a raw JsonException
			 * in the transport's whole-request encode, surfacing as the
			 * generic 500 (zai_error). The oracle is the shared
			 * JsonEncodeGuard's RAW json_encode() — the same primitive
			 * the SDK transport's Request::getBody() throws on (GLM5 #16
			 * single-sourced it; see there for why wp_json_encode() and
			 * mb_check_encoding() are not options).
			 */
			JsonEncodeGuard::must_encode( $text, 'a message text part', self::PROVIDER_LABEL );

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
			 * 400). GLM6 #9: replayed identities are wire strings like
			 * every other — encodability-guarded, not just
			 * emptiness-checked. GLM9 #12: the identity rule rides the
			 * shared JsonEncodeGuard, the same one the zai surface's
			 * GLM7 #7 contract lives in.
			 */
			JsonEncodeGuard::must_encode_tool_call_identity( $function_call, self::PROVIDER_LABEL );

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
			 */
			if ( ! ToolArgsReplayGuard::is_replayable( $input ) ) {
				throw new InvalidArgumentException(
					'The zai_anthropic provider could not replay tool arguments (an unencodable or precision-loss value was given).'
				);
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

			/*
			 * The tool_use id answers the call and is a wire string like
			 * the rest (GLM6 #9: encodability-guarded, not just
			 * emptiness-checked). GLM9 #12: the identity rule rides the
			 * shared JsonEncodeGuard — the same one the zai surface's
			 * GLM9 #4 guard lives in, one protocol-name apart.
			 */
			JsonEncodeGuard::must_encode_tool_result_identity( $function_response, 'tool_use id', 'a tool result tool_use id', self::PROVIDER_LABEL );

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
			 */
			$encoded = JsonEncodeGuard::encode( $function_response->getResponse(), 'a tool result', self::PROVIDER_LABEL );

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
			return $this->parse_message_body( $response );
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
			 */
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'stream',
				'A tool_use block in the message stream carried malformed input JSON.'
			);
		}

		if ( null === $aggregated ) {
			throw ResponseException::fromInvalidData(
				self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				'stream',
				'No usable message event was received.'
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
	 * Runs the non-streaming Messages parser over a JSON response.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The Messages response.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException When the payload is malformed.
	 */
	private function parse_message_body( Response $response ): GenerativeAiResult {
		return $this->parse_body_string( (string) $response->getBody() );
	}

	/**
	 * Runs the non-streaming Messages parser over a raw body string.
	 *
	 * GLM8 #3: a UTF-8 BOM prepended to an otherwise-valid JSON Messages
	 * body — the same gateway/CDN threat class the SSE side strips
	 * through the shared SseFrameBuffer — made the vendor
	 * Response::getData() decode fail (JSON_ERROR_SYNTAX) and the whole
	 * non-streaming generation died as 'Missing the "content" key': a
	 * typed rejection of a valid completion one layer short of where this
	 * branch's own BOM hardening stops. The canonical prefix strip
	 * (strip_stream_prefix(), deliberately a no-op on BOM-less bodies)
	 * runs before BOTH decodes here, so the associative parse and the
	 * raw object-ness oracle always read the same cleaned body.
	 *
	 * GLM10 #11: the decode block itself — the strip, the associative
	 * view, the raw object-ness view, the vendor null normalization —
	 * rides the one shared JsonBodyDecoder with the zai model's
	 * non-streaming decode; the (array|null, stdClass|null) contract is
	 * the helper's.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The raw response body.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException When the payload is malformed.
	 */
	private function parse_body_string( string $body ): GenerativeAiResult {
		list( $data, $raw ) = JsonBodyDecoder::decode( $body );

		return $this->parse_decoded_message( $data, $raw );
	}

	/**
	 * The JSON fallback for a body the Content-Type mislabeled as a
	 * stream (GLM8 #5), or null when the body is no Messages payload.
	 *
	 * Runs only AFTER SSE aggregation failed (the caller's
	 * malformed-event channel), so a genuinely-decodable stream never
	 * re-routes: only a body that decodes as a JSON OBJECT is even
	 * attempted, and only a full Messages-payload parse succeeds. A
	 * ResponseException from the parse (a JSON object, but not a valid
	 * Messages body) returns null so the caller surfaces its
	 * stream-typed error; the typed truncation outcome
	 * (TokenLimitReachedException) is a successful parse of a valid body
	 * and propagates.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The raw response body.
	 * @return GenerativeAiResult|null The parsed result, or null when the
	 *                                 body is not a Messages payload.
	 * @throws TokenLimitReachedException When the JSON body is a valid
	 *                                    Messages payload that stopped at
	 *                                    the token limit.
	 */
	private function json_fallback_result( string $body ): ?GenerativeAiResult {
		if ( ! \is_object( json_decode( SseFrameBuffer::strip_stream_prefix( $body ) ) ) ) {
			return null;
		}

		try {
			return $this->parse_body_string( $body );
		} catch ( ResponseException $e ) {
			return null;
		}
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
	 */
	private function parse_decoded_message( $data, ?\stdClass $raw_body ): GenerativeAiResult {
		if ( ! \is_array( $data ) || ! isset( $data['content'] ) || ! \is_array( $data['content'] ) ) {
			throw ResponseException::fromMissingData( self::PROVIDER_LABEL, 'content' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
		 */
		if ( null !== $raw_body && ! \is_array( $raw_body->content ?? null ) && \property_exists( $raw_body, 'content' ) ) {
			throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'The message content must be a JSON array.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
		$raw_content_ok = null !== $raw && isset( $raw->content ) && \is_array( $raw->content );

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
		if ( array() !== $parts && ! self::message_has_translatable_part( $parts ) ) {
			throw ResponseException::fromInvalidData(
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
			throw ResponseException::fromInvalidData(
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
			$raw_usage = null !== $raw && \property_exists( $raw, 'usage' ) ? $raw->usage : null;
			$reason    = UsageValidator::failure_reason(
				\is_array( $data['usage'] ) ? $data['usage'] : null,
				$raw_usage
			);

			if ( null !== $reason ) {
				throw ResponseException::fromInvalidData(
					self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
					'usage',
					UsageValidator::message_for_reason( $reason ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				);
			}

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

		switch ( $type ) {
			case 'text':
				if ( ! isset( $part_data['text'] ) || ! \is_string( $part_data['text'] ) ) {
					throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'A text block is missing its text member.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				}

				return new MessagePart( $part_data['text'] );

			case 'thinking':
				if ( ! isset( $part_data['thinking'] ) || ! \is_string( $part_data['thinking'] ) ) {
					throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'A thinking block is missing its thinking member.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
				}

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
						 */
						throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'A tool_use block is missing its input member.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
					}
				} else {
					$raw_input = \property_exists( $raw_part, 'input' ) ? $raw_part->input : null;

					if ( null === $raw_input ) {
						// Missing input member or explicit null (R7 #1).
						throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'A tool_use block is missing its input member.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
						throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'A tool_use block carried a non-object input value.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
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
				 */
				if ( null !== $args && ! ToolArgsReplayGuard::is_replayable_decoded( $args ) ) {
					throw ResponseException::fromInvalidData(
						self::PROVIDER_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
						'content',
						'A tool_use block carried arguments that cannot be replayed (an unencodable or precision-loss value was decoded).'
					);
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
				 * no parts, which parse_decoded_message() rejects as a
				 * ResponseException (zai_invalid_response) regardless of
				 * the stop reason (GLM3 #2 made this guarantee real; GLM5
				 * #4 removed the empty-refusal tolerance so the guarantee
				 * has no exception — a zero-part turn can never replay),
				 * with a message naming the dropped blocks when that was
				 * the case. The streamed path drops the
				 * same shapes earlier (see the aggregator's
				 * content_block_payload()).
				 */
				return null;
		}

		throw ResponseException::fromInvalidData( self::PROVIDER_LABEL, 'content', 'The message contained a block of an unsupported type.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
	}

	/**
	 * Whether a parsed part list carries a part the OUTBOUND mapper would
	 * translate into a Messages content block (GLM3 #1).
	 *
	 * Mirrors message_part_block()'s keep/drop decisions exactly — a text
	 * part on the default channel with NON-EMPTY text, a function call, or
	 * a function response — so the inbound parser can enforce the same
	 * contract it will be held to on replay: a turn that would map to zero
	 * wire blocks cannot join the conversation history.
	 *
	 * @since 0.2.0
	 *
	 * @param array $parts The parsed parts of one turn (list of MessagePart).
	 * @return bool True when at least one part is translatable.
	 */
	private static function message_has_translatable_part( array $parts ): bool {
		foreach ( $parts as $part ) {
			if ( $part->getType()->isFunctionCall() || $part->getType()->isFunctionResponse() ) {
				return true;
			}

			if ( $part->getType()->isText() && ! $part->getChannel()->isThought() && '' !== (string) $part->getText() ) {
				return true;
			}
		}

		return false;
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
	 * GLM7 #10: the probe uses the RAW json_encode() oracle (the GLM3 #4
	 * primitive the shared JsonEncodeGuard single-sources, GLM5 #16) —
	 * core's wp_json_encode() lossily rescues invalid UTF-8 in production
	 * and never returns false for a string, so this branch decided
	 * DIFFERENTLY under the deliberately stricter test stub than in
	 * production for the same payload (the GLM4 #1 dead-oracle class).
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

		$encoded = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (GLM7 #10; the GLM3 #4 verifier-round class).

		return \is_string( $encoded ) && '{' === substr( $encoded, 0, 1 );
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
		AdvertisedOptionGuard::reject_unsupported( $config->toArray(), self::PROVIDER_LABEL, false );

		/*
		 * GLM2 #9: the five usage rejections the two surfaces advertise
		 * IDENTICALLY (candidateCount, text-only output modalities, the
		 * MIME whitelist, text-only input, custom options) are shared too
		 * — they were verbatim twins directly under this call, the exact
		 * duplication pattern the guard above was extracted to stop.
		 */
		AdvertisedUsageGuard::reject_unsupported( $config, $prompt, self::PROVIDER_LABEL );

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
