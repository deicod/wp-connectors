<?php
/**
 * Shared vendor-body decode (code-review GLM10 #11).
 *
 * The getData()-mirroring decode block — the BOM strip (the shared
 * stream-prefix rule), the associative decode, the raw object-ness
 * decode, and the vendor null-for-empty/failure/non-array
 * normalization — was hand-rolled twice with already-diverged
 * mechanics: the zai model's json_last_error dance plus empty-body
 * guard and unconditional mixed raw view, versus the zai_anthropic
 * model's bare is_array plus stdClass narrowing. The copies were
 * behaviorally identical only by convention: a future change to the
 * mirrored contract (empty-body or scalar-root normalization) would
 * land on one surface only, and the identical malformed body would
 * yield a typed rejection on one transport and the generic
 * missing-data error on the other — the divergence class that made
 * the GLM7-9/GLM9-15 rounds consolidate the per-surface decodes.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Decodes one raw vendor body into both JSON views.
 *
 * @since 0.2.0
 */
final class JsonBodyDecoder {

	/**
	 * Decodes a raw vendor body into the associative and raw views, after
	 * the canonical BOM-strip (a deliberate no-op on BOM-less bodies).
	 *
	 * The vendor Response::getData() contract for the associative view:
	 * null for an empty body, a decode failure, or a non-array
	 * (scalar/null) value. The raw view is the object-ness oracle
	 * (Codex R14 #4): stdClass only — a scalar, a list, or a decode
	 * failure yields null, so a JSON OBJECT root stays distinguishable
	 * from a list root after the associative collapse.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The raw response body.
	 * @return array{0: array<string, mixed>|null, 1: \stdClass|null} The
	 *                associative view and the raw object-ness view.
	 */
	public static function decode( string $body ): array {
		$body = SseFrameBuffer::strip_stream_prefix( $body );

		if ( '' === $body ) {
			return array(
				null,
				null,
			);
		}

		$decoded = json_decode( $body, true );
		$raw     = json_decode( $body );

		return array(
			\is_array( $decoded ) ? $decoded : null,
			$raw instanceof \stdClass ? $raw : null,
		);
	}
}
