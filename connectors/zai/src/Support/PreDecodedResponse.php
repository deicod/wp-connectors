<?php
/**
 * An SDK Response whose payload is already decoded (code-review GLM6 #14).
 *
 * The zai surface's streamed path consolidates chat.completion.chunk
 * events into an already-decoded PHP payload (SseAggregator::aggregated()).
 * Handing it to the SDK parent's non-streaming parser used to require
 * wp_json_encode() into a synthetic Response whose getData() then
 * json_decode()d the whole body AGAIN — one extra full-payload encode
 * plus one extra full-body decode per streamed generation, on top of the
 * per-frame decodes the aggregator already did (the exact round trip
 * this branch deleted on the Anthropic twin, GLM2 #10).
 *
 * Mirroring the parent's parse walk instead (the twin's approach) would
 * duplicate the SDK's parser and drift; this shim keeps the ONE parser
 * and removes only the round trip: getData() returns the aggregator's
 * payload as-is. The shapes are identical by construction — the
 * aggregator builds the payload from associative frame decodes, which
 * is exactly what getData() would produce — and the model's
 * whole-payload encodability check (GLM6 #6) still guards the hand-off.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * A Response carrying its payload pre-decoded.
 *
 * @since 0.2.0
 */
final class PreDecodedResponse extends Response {

	/**
	 * The already-decoded payload getData() returns.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, mixed>
	 */
	private $decoded;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $status_code The original response's status code.
	 * @param array<string, mixed> $decoded    The consolidated payload.
	 */
	public function __construct( int $status_code, array $decoded ) {
		parent::__construct( $status_code, array(), null );

		$this->decoded = $decoded;
	}

	/**
	 * Returns the pre-decoded payload (no body decode).
	 *
	 * The parent's nullable return narrows covariantly: this payload is
	 * always present by construction (the aggregator only produces it for
	 * a consumed stream).
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The consolidated payload.
	 */
	public function getData(): array {
		return $this->decoded;
	}
}
