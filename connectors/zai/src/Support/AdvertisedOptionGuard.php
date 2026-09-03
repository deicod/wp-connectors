<?php
/**
 * Advertised-option rejection for both z.ai model surfaces (GLM1 #11).
 *
 * Reject_unsupported_options() was duplicated between the two model classes
 * (one label apart); it lives here once now.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Rejects config keys outside the advertised option set.
 *
 * @since 0.2.0
 */
final class AdvertisedOptionGuard {

	/**
	 * Config keys that are not part of either surface's advertised option
	 * set, with their human labels.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	const UNSUPPORTED = array(
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

	/**
	 * Unsupported options the OpenAI-compatible request builder FORWARDS
	 * onto the wire whenever they are non-null (GLM4 #4).
	 *
	 * The SDK parent emits presence_penalty / frequency_penalty / logprobs
	 * / top_logprobs for every non-null config value — so the falsy
	 * neutral values GLM1 #12 taught this guard to tolerate
	 * (setLogprobs(false), setTopLogprobs(0), setPresencePenalty(0.0))
	 * still SHIPPED: a spec-faithful OpenAI-compatible endpoint rejects
	 * top_logprobs without logprobs=true, and the caller got the generic
	 * upstream error where the guard exists to give the precise local
	 * one. For these options an explicitly-set falsy value is therefore
	 * rejected typed. The remaining unsupported options (topK, webSearch,
	 * the output-* family) are never forwarded by the request builder —
	 * a set falsy value there is wire-inert and stays tolerated.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	const WIRE_FORWARDED = array(
		'presencePenalty'  => true,
		'frequencyPenalty' => true,
		'logprobs'         => true,
		'topLogprobs'      => true,
	);

	/**
	 * Rejects config keys that are not part of the advertised option set.
	 *
	 * GLM1 #12: EVERY falsy flavor is equally "not set" (null, false, [],
	 * 0, '0', 0.0, ''). The previous check treated 0/0.0/'0'/'' as "option
	 * set", so explicitly NEUTRALIZING a previously set option with the
	 * only neutral value available (the setters are non-nullable:
	 * setTopK(0), setPresencePenalty(0.0)) hard-failed the request while
	 * setLogprobs(false) passed.
	 *
	 * GLM4 #4 supersedes that tolerance for the WIRE_FORWARDED options
	 * only: ModelConfig::toArray() is sparse (non-null values only), so
	 * null still means "not set" — but an explicitly-set falsy value of a
	 * forwarded option would reach the wire verbatim ("logprobs": false,
	 * "top_logprobs": 0, "presence_penalty": 0), and is rejected with the
	 * precise local message instead of the generic upstream one it used
	 * to buy. Both surfaces share the rule so a config rejected by one is
	 * rejected by the other.
	 *
	 * GLM7 #15: the REJECTION is shared, the JUSTIFICATION is per
	 * surface. $ships_forwarded_values states whether the caller's
	 * request builder actually emits the forwarded keys: the zai surface
	 * (the SDK parent's builder) does — its message keeps the truthful
	 * 'would still be sent to the API' clause — while the zai_anthropic
	 * builder never emits presence_penalty/frequency_penalty/logprobs/
	 * top_logprobs, so its message justifies the rejection by the
	 * cross-surface contract instead of a forwarding this surface does
	 * not do (the hardcoded clause was factually false for half the
	 * callers and would silently diverge further if either builder
	 * changed).
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $config_as_array       The model config as an array.
	 * @param string               $provider_label        Provider name for the message ('z.ai' or 'zai_anthropic').
	 * @param bool                 $ships_forwarded_values Whether the caller's request
	 *                                                    builder emits the WIRE_FORWARDED
	 *                                                    keys (the zai surface's SDK
	 *                                                    parent does; the zai_anthropic
	 *                                                    builder does not).
	 * @return void
	 * @throws InvalidArgumentException When a non-advertised option carries a
	 *                                   truthy value, or a wire-forwarded
	 *                                   option carries any explicitly-set value.
	 */
	public static function reject_unsupported( array $config_as_array, string $provider_label, bool $ships_forwarded_values = true ): void {
		foreach ( self::UNSUPPORTED as $key => $label ) {
			$value = $config_as_array[ $key ] ?? null;

			if ( ! empty( $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				throw new InvalidArgumentException( sprintf( 'The %s provider does not support %s.', $provider_label, $label ) );
			}

			if ( null !== $value && isset( self::WIRE_FORWARDED[ $key ] ) ) {
				$message = sprintf(
					$ships_forwarded_values
						? 'The %1$s provider does not support %2$s (an explicitly-set value — even a neutral one — would still be sent to the API; build the request without the option instead).'
						: 'The %1$s provider does not support %2$s (an explicitly-set value — even a wire-inert one on this surface — is rejected so both z.ai surfaces keep one option contract; build the request without the option instead).',
					$provider_label,
					$label
				);

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				throw new InvalidArgumentException( $message );
			}
		}
	}
}
