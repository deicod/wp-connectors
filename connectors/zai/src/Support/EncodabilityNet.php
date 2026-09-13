<?php
/**
 * The request-build encodability net, owned once (glm16-7).
 *
 * Both model surfaces run the same net at their createRequest()
 * chokepoint — ONE raw json_encode() of the assembled payload proves
 * encodability, and the encoded string rides as the request's body
 * (glm13-11/glm14-4 on the zai surface, glm15-5 on zai_anthropic) —
 * and the same attribution walk on failure: per-member
 * JsonEncodeGuard checks over the stashed prompt name the first bad
 * member with the precise message the eager per-site guards used to
 * give. The pair was kept in lockstep only by comments and had
 * already diverged once (GLM3 #3 landed the stop-sequence rule on one
 * surface only); one owner means the next net rule lands once.
 *
 * The walk SEGMENTS below are the verbatim-identical members both
 * surfaces judge; each surface's guard_wire_values() composes them in
 * ITS OWN mapping order (the order preserves which member a multi-bad
 * payload names — pinned per surface), and the one genuinely
 * surface-specific segment (the sampling options) is opt-in with its
 * divergence documented at the method. glm20-8 added the stop-sequence
 * and prompt-tool-identity segments (the encodability halves of the
 * old eager composite guards, whose SHAPE halves stay eager at the
 * params build); the identity segment takes one per-surface parameter —
 * the tool-result id's attribution subject the protocols spell
 * differently.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * One raw-encode net plus the shared attribution-walk segments.
 *
 * @since 0.2.0
 */
final class EncodabilityNet {

	/**
	 * ONE raw encode of the assembled payload — the net both surfaces'
	 * createRequest() chokepoints consult (glm16-7 single-sources the
	 * twins).
	 *
	 * On success the encoded string returns for the caller's ride
	 * decision (glm14-4/glm15-5: it is the request's body — the zai
	 * surface additionally checks its transport would carry a JSON
	 * body before riding). On failure the caller's attribution walk
	 * runs first — naming the first bad member with the precise
	 * per-member message — and only then the generic typed rejection
	 * fires for a payload the re-encode still cannot serialize (the
	 * walk must throw for any member it knows, so the generic branch
	 * catches only members the walk does not).
	 *
	 * @since 0.2.0
	 *
	 * @param array    $payload            The assembled request params.
	 * @param string   $provider_label     The surface's provider label.
	 * @param callable $attribution_walk   Runs before the generic rejection.
	 * @return string The encoded JSON body.
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When any member cannot encode.
	 */
	public static function encode( array $payload, string $provider_label, callable $attribution_walk ): string {
		$encoded = json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the RAW oracle is required: core's wp_json_encode() lossily rescues invalid UTF-8 (the GLM3 #4 verifier-round class).

		if ( false !== $encoded ) {
			return $encoded;
		}

		$attribution_walk();

		/*
		 * glm29-1: the fall-through is REACHABLE — jsonSerialize() is
		 * user code whose output can diverge between invocations, so
		 * the full-payload oracle above can meet an unencodable view
		 * the attribution walk's per-member re-encodes do not (each
		 * member encodes clean on its second visit). Returning ''
		 * here shipped a zero-length body generateTextResult() then
		 * reported as success. The re-encoded artifact rides instead:
		 * either this encode throws the generic typed rejection (a
		 * payload no invocation can serialize) or its string is a
		 * genuine encoding of the payload as the walk saw it.
		 */
		return JsonEncodeGuard::encode( $payload, 'a request payload member', $provider_label );
	}

	/**
	 * Guards the configured system instruction (a shared walk segment).
	 *
	 * @since 0.2.0
	 *
	 * @param ModelConfig $config         The request's configuration.
	 * @param string      $provider_label The surface's provider label.
	 * @return void
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When the value cannot encode.
	 */
	public static function guard_system_instruction( ModelConfig $config, string $provider_label ): void {
		$system_instruction = $config->getSystemInstruction();
		if ( \is_string( $system_instruction ) && '' !== $system_instruction ) {
			JsonEncodeGuard::must_encode( $system_instruction, 'the system instruction', $provider_label );
		}
	}

	/**
	 * Guards the sampling options and the response-format schema (the
	 * ONE surface-specific walk segment — zai only, glm16-7).
	 *
	 * The zai surface's SDK parent ships the temperature/top_p floats
	 * and the outputSchema verbatim into the request, so a NAN float or
	 * an unencodable schema member reaches this walk (Verifier round on
	 * GLM6 #5). The zai_anthropic surface does NOT compose this
	 * segment: its outputSchema and guidance ride the wire through an
	 * eager TRANSFORM (json_output_guidance(), R19) whose encodability
	 * guard runs before the net by glm15-5's transform exception, and
	 * its NAN temperature/top_p are rejected by the advertised-option
	 * range checks in validate_request() — both reach the net already
	 * rejected, so the segment there would be dead branches. The
	 * divergence is deliberate and pinned on both sides.
	 *
	 * @since 0.2.0
	 *
	 * @param ModelConfig $config         The request's configuration.
	 * @param string      $provider_label The surface's provider label.
	 * @return void
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When a value cannot encode.
	 */
	public static function guard_sampling_options( ModelConfig $config, string $provider_label ): void {
		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			JsonEncodeGuard::must_encode( $temperature, 'the temperature option', $provider_label );
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			JsonEncodeGuard::must_encode( $top_p, 'the top_p option', $provider_label );
		}

		$output_schema = $config->getOutputSchema();
		if ( \is_array( $output_schema ) ) {
			JsonEncodeGuard::must_encode( $output_schema, 'the configured output schema', $provider_label );
		}
	}

	/**
	 * Guards every declared tool's identity strings and parameter
	 * schema (a shared walk segment).
	 *
	 * An EMPTY parameter schema encodes fine ([]) — including it is a
	 * no-op kept from the zai twin's form; both surfaces normalize it
	 * before the wire anyway.
	 *
	 * @since 0.2.0
	 *
	 * @param ModelConfig $config         The request's configuration.
	 * @param string      $provider_label The surface's provider label.
	 * @return void
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When a value cannot encode.
	 */
	public static function guard_declarations( ModelConfig $config, string $provider_label ): void {
		$function_declarations = $config->getFunctionDeclarations();
		if ( ! \is_array( $function_declarations ) ) {
			return;
		}

		foreach ( $function_declarations as $declaration ) {
			JsonEncodeGuard::must_encode( $declaration->getName(), 'a declared tool function name', $provider_label );
			JsonEncodeGuard::must_encode( $declaration->getDescription(), 'a declared tool function description', $provider_label );

			$input_schema = $declaration->getParameters();
			if ( \is_array( $input_schema ) ) {
				JsonEncodeGuard::must_encode( $input_schema, 'a declared tool parameter schema', $provider_label );
			}
		}
	}

	/**
	 * Guards every visible text part across the prompt (a shared walk
	 * segment).
	 *
	 * Only visible text ships (both surfaces' mappings drop thought
	 * parts, so guarding them would over-reject). Empty strings encode
	 * fine — guarding them cannot reject; the zai_anthropic composer
	 * documents its mapping's drop-empty nuance at its own site.
	 *
	 * @since 0.2.0
	 *
	 * @param Message[] $prompt         Prompt messages (list of Message).
	 * @param string    $provider_label The surface's provider label.
	 * @return void
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When a value cannot encode.
	 */
	public static function guard_visible_text( array $prompt, string $provider_label ): void {
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isText() && ! $part->getChannel()->isThought() ) {
					JsonEncodeGuard::must_encode( (string) $part->getText(), 'a message text part', $provider_label );
				}
			}
		}
	}

	/**
	 * Guards every configured stop-sequence entry's encodability (a
	 * shared walk segment, glm20-8).
	 *
	 * The entries ship inside the assembled params, so the net's one
	 * encode already proves their encodability — this segment only
	 * NAMES the first bad entry on failure. The SHAPE rule (non-string
	 * or empty entries) stays eager at the params build
	 * (JsonEncodeGuard::reject_misshapen_stop_sequences()): a misshapen
	 * entry must reject before the payload assembles at all. Both
	 * surfaces compose this segment at the position their old eager
	 * encode occupied relative to the identity segments (zai guarded
	 * stop sequences before the identities; zai_anthropic after its
	 * message mapping), so a multi-bad payload keeps naming the member
	 * the eager order named.
	 *
	 * @since 0.2.0
	 *
	 * @param ModelConfig $config         The request's configuration.
	 * @param string      $provider_label The surface's provider label.
	 * @return void
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When an entry cannot encode.
	 */
	public static function guard_stop_sequences( ModelConfig $config, string $provider_label ): void {
		$stop_sequences = $config->getStopSequences();
		if ( ! \is_array( $stop_sequences ) ) {
			return;
		}

		foreach ( $stop_sequences as $sequence ) {
			JsonEncodeGuard::must_encode( $sequence, 'a stop sequence', $provider_label );
		}
	}

	/**
	 * Guards every tool part's identity strings' encodability (a shared
	 * walk segment, glm20-8).
	 *
	 * The tool-call id/name and the tool-result id ship inside the
	 * assembled params verbatim, so the net's one encode already proves
	 * their encodability — this segment only NAMES the first bad
	 * identity on failure, walking the prompt in part order (the order
	 * the old eager per-part guards fired in). The SHAPE rules (null or
	 * empty identities) stay eager at the mapping/typed-walk sites
	 * (JsonEncodeGuard::reject_tool_call_identity() /
	 * reject_tool_result_identity()). $tool_result_id_subject carries
	 * each surface's own attribution ('a tool result id' on zai, 'a
	 * tool result tool_use id' on zai_anthropic) — the one parameter
	 * the twin protocols spell differently.
	 *
	 * @since 0.2.0
	 *
	 * @param Message[] $prompt                 Prompt messages (list of Message).
	 * @param string    $provider_label         The surface's provider label.
	 * @param string    $tool_result_id_subject The tool-result id's attribution subject.
	 * @return void
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When an identity cannot encode.
	 */
	public static function guard_prompt_tool_identities( array $prompt, string $provider_label, string $tool_result_id_subject ): void {
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isFunctionCall() && null !== $part->getFunctionCall() ) {
					JsonEncodeGuard::must_encode( $part->getFunctionCall()->getId(), 'a tool call id', $provider_label );
					JsonEncodeGuard::must_encode( $part->getFunctionCall()->getName(), 'a tool call name', $provider_label );
					continue;
				}

				if ( $part->getType()->isFunctionResponse() && null !== $part->getFunctionResponse() ) {
					JsonEncodeGuard::must_encode( $part->getFunctionResponse()->getId(), $tool_result_id_subject, $provider_label );
				}
			}
		}
	}
}
