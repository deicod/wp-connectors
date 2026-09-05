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
 * glm19-4: the shim now carries the ORIGINAL response's headers and
 * body, not just its status — the old constructor forwarded an empty
 * header map and a null body, so any reader beyond getData() (a future
 * SDK release reading Content-Type off the wrapped response, a new
 * caller wanting the raw stream text) silently saw an empty view with
 * no failure signal, though the original Response was in scope at both
 * construction sites. Taking the whole original Response makes the
 * forwarding structural: no caller can hand off a decoded payload
 * without its provenance.
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
	 * @param Response             $original The response the payload was
	 *                                       decoded from — its status,
	 *                                       headers, and body ride along
	 *                                       verbatim (glm19-4).
	 * @param array<string, mixed> $decoded The consolidated payload.
	 */
	public function __construct( Response $original, array $decoded ) {
		parent::__construct( $original->getStatusCode(), $original->getHeaders(), $original->getBody() );

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
