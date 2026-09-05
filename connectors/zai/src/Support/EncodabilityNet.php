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
 * divergence documented at the method.
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
	 * fires (unreachable after it; the walk must throw for any member
	 * it knows, so the generic branch catches only members the walk
	 * does not).
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

		JsonEncodeGuard::must_encode( $payload, 'a request payload member', $provider_label );

		return ''; // Unreachable: must_encode() rejects above.
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
}
