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
	 * Rejects config keys that are not part of the advertised option set.
	 *
	 * GLM1 #12: EVERY falsy flavor is equally "not set" (null, false, [],
	 * 0, '0', 0.0, ''). The previous check treated 0/0.0/'0'/'' as "option
	 * set", so explicitly NEUTRALIZING a previously set option with the
	 * only neutral value available (the setters are non-nullable:
	 * setTopK(0), setPresencePenalty(0.0)) hard-failed the request while
	 * setLogprobs(false) passed.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $config_as_array The model config as an array.
	 * @param string               $provider_label  Provider name for the message ('z.ai' or 'zai_anthropic').
	 * @return void
	 * @throws InvalidArgumentException When a non-advertised option carries a truthy value.
	 */
	public static function reject_unsupported( array $config_as_array, string $provider_label ): void {
		foreach ( self::UNSUPPORTED as $key => $label ) {
			$value = $config_as_array[ $key ] ?? null;

			if ( ! empty( $value ) ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
				throw new InvalidArgumentException(
					sprintf(
						'The %s provider does not support %s.',
						$provider_label,
						$label
					)
				);
			}
		}
	}
}
