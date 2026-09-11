<?php
/**
 * The mislabeled-body JSON fallback, owned once (glm16-8).
 *
 * Both surfaces run the identical fallback scaffold when the
 * Content-Type promised a stream but SSE aggregation failed: ONE
 * shared decode (glm15-7), the decoder's raw view as the object-root
 * oracle (only a body decoding as a JSON OBJECT is even attempted),
 * then the surface's parse over the already-decoded pair — with the
 * glm14-2 exception contract (the plugin's own fixed-message marker
 * rejections propagate; every other ResponseException means 'a JSON
 * object, but no valid surface payload' and returns null so the
 * caller surfaces its stream-typed error). The scaffold was a ~25-line
 * structural twin hand-maintained in both model files (the divergence
 * class glm15-11 was landed to prevent); one helper takes the
 * surface's parse callable, the repo's EventStreamSniff/
 * JsonBodyDecoder/SseFieldParser pattern.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Parses a stream-labeled body as JSON when it is one, or reports null.
 *
 * @since 0.2.0
 */
final class JsonFallbackResult {

	/**
	 * The shared fallback scaffold (glm16-8).
	 *
	 * @since 0.2.0
	 *
	 * @param string   $body           The raw response body.
	 * @param callable $parse_decoded  The surface's parser over the
	 *                                 already-decoded pair —
	 *                                 f(array|null $data, stdClass|null $raw)
	 *                                 returning the parsed result.
	 * @return GenerativeAiResult|null The parse's result, or null when the
	 *                                 body is no JSON object or no valid
	 *                                 surface payload.
	 * @throws FixedMessageResponseException When the body IS a JSON object
	 *                                       whose parse produced one of this
	 *                                       plugin's precise fixed-message
	 *                                       rejections (glm14-2 — the marker
	 *                                       propagates by design). A
	 *                                       TokenLimitReachedException is
	 *                                       no ResponseException and
	 *                                       propagates uncaught as well.
	 */
	public static function parse( string $body, callable $parse_decoded ): ?GenerativeAiResult {
		list( $data, $raw ) = JsonBodyDecoder::decode( $body );

		if ( ! \is_object( $raw ) ) {
			return null;
		}

		try {
			return $parse_decoded( $data, $raw );
		} catch ( FixedMessageResponseException $e ) {
			// glm14-2: the plugin's own precise fixed-message rejections
			// surface even on the fallback path; swallowing them degraded
			// the byte-identical corruption to the generic stream-typed
			// error whenever the gateway also mislabeled the body.
			throw $e;
		} catch ( ResponseException $e ) {
			return null;
		}
	}
}
