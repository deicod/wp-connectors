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
use WordPress\AiClient\Common\Exception\RuntimeException;
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
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;
use Deicod\WpConnectors\Zai\Support\ThrowsSafeHttpErrors;
use Deicod\WpConnectors\Zai\Support\SseAggregator;
use Deicod\WpConnectors\Zai\Support\ToolArgsObjectNess;
use Deicod\WpConnectors\Zai\Support\ToolArgsReplayGuard;

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
		/*
		 * An unbound model (no wired auth) is not this gate's concern: skip
		 * it and let the request fail exactly where it did before the gate
		 * existed (the SDK's authenticateRequest() RuntimeException), so
		 * the pre-gate exception ORDER is preserved for callers that
		 * misuse an unbound model while ALSO carrying invalid options
		 * (verifier nit on GLM1 #1: the gate must not steal that error).
		 *
		 * GLM4 #9: the gate PREDICATE and the refusal messages live on
		 * the availability layer now
		 * (generation_refusal_for_wired_authentication() /
		 * refusal_message()), shared with the zai_anthropic twin and
		 * both metadata directories.
		 */
		try {
			$authentication = $this->getRequestAuthentication();
		} catch ( RuntimeException $e ) {
			return;
		}

		$refusal = ( new ZaiProviderAvailability() )->generation_refusal_for_wired_authentication( $authentication );

		if ( null !== $refusal ) {
			$message = ZaiProviderAvailability::refusal_message( 'zai', $refusal );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( $message );
		}
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
	 * shapes. Root values that are not JSON objects (scalars, lists) and
	 * pre-decoded (non-string) arguments keep the SDK parent's semantics
	 * untouched.
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

			if ( $raw instanceof \stdClass ) {
				// GLM5 #1: preserve nested object-ness (see the docblock).
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
	}
}
