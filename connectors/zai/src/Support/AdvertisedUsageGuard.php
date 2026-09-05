<?php
/**
 * Advertised request-usage rejection for both z.ai model surfaces (GLM2 #9).
 *
 * The five usage rejections (candidateCount, text-only output modalities,
 * the MIME whitelist, text-only input, custom options) and the output MIME
 * list were verbatim twins between the two model classes' validate_request()
 * — sitting directly under the AdvertisedOptionGuard call this branch
 * introduced to deduplicate exactly that twin pattern, but which absorbed
 * only the unsupported-key loop. They live here once now, parameterized by
 * the provider label the messages interpolate.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Rejects request usage outside the surfaces' advertised capabilities.
 *
 * @since 0.2.0
 */
final class AdvertisedUsageGuard {

	/**
	 * Output MIME types both z.ai surfaces support — the single source the
	 * per-surface rejections consult (was a duplicated const, GLM2 #9).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const SUPPORTED_OUTPUT_MIME_TYPES = array( 'text/plain', 'application/json' );

	/**
	 * Rejects request usage neither surface advertises.
	 *
	 * Everything rejected here fails BEFORE any transport work, so callers
	 * get an immediate, precise error instead of an upstream 400. Surface-
	 * specific rejections (the Anthropic Messages max_tokens requirement
	 * and sampling-parameter ranges, the role-order rules) stay in the
	 * owning model classes; this guard covers only the capabilities the
	 * two surfaces advertise identically.
	 *
	 * @since 0.2.0
	 *
	 * @param ModelConfig $config         The model configuration.
	 * @param array       $prompt         Prompt messages (list of Message).
	 * @param string      $provider_label Provider name for the messages ('zai' or 'zai_anthropic' — the surfaces' PROVIDER_LABEL, GLM10 #9).
	 * @return void
	 * @throws InvalidArgumentException When the request uses an unadvertised capability.
	 */
	public static function reject_unsupported( ModelConfig $config, array $prompt, string $provider_label ): void {
		// Multiple candidates are not advertised.
		if ( null !== $config->getCandidateCount() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider does not support candidateCount (multiple candidates).', $provider_label ) );
		}

		// Output modalities: text only.
		$output_modalities = $config->getOutputModalities();
		if ( \is_array( $output_modalities ) ) {
			foreach ( $output_modalities as $modality ) {
				if ( ! $modality->isText() ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
					throw new InvalidArgumentException( sprintf( 'The %s provider only supports text output modalities.', $provider_label ) );
				}
			}
		}

		// Structured output only in the two advertised MIME types.
		$output_mime_type = $config->getOutputMimeType();
		if ( null !== $output_mime_type && ! \in_array( $output_mime_type, self::SUPPORTED_OUTPUT_MIME_TYPES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider supports outputMimeType values text/plain and application/json only.', $provider_label ) );
		}

		// Text-only input: no file (image/audio/document) parts in any message.
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isFile() ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
					throw new InvalidArgumentException( sprintf( 'The %s provider only supports text input in v1; file (image/audio/document) message parts are rejected.', $provider_label ) );
				}
			}
		}

		// Custom options are not advertised; passing them is rejected rather
		// than silently forwarded to the API.
		if ( array() !== $config->getCustomOptions() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( sprintf( 'The %s provider does not support custom options.', $provider_label ) );
		}
	}
}
