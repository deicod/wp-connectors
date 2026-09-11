<?php
/**
 * Shared SSE frame-consumption protocol for both aggregators
 * (round 8 cleanup, GLM8 #8).
 *
 * The plumbing — the shared SseFrameBuffer instance, the constructor
 * wiring it, feed()/finish() driving it, and the pull loop that hands
 * every completed frame to consume_frame() — was copy-pasted
 * byte-identical between SseAggregator and AnthropicSseAggregator, the
 * exact duplication pattern that drifts when one copy learns a rule
 * (the divergence class the SseFieldParser extraction, GLM7 #18, was
 * made to stop). One base owns the protocol now; each aggregator keeps
 * only its event semantics.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Base SSE aggregator: frame buffering and the consumption protocol.
 *
 * @since 0.2.0
 */
abstract class AbstractSseAggregator {

	/**
	 * The protocol-neutral frame splitter (shared by every aggregator):
	 * buffering, line-ending normalization (mixed CR/LF/CRLF), split
	 * chunks, the final unterminated frame, and the stream-start BOM
	 * prefix rule live there. The EVENT semantics (names, data shapes,
	 * completion sentinels) stay with each concrete aggregator's
	 * consume_frame().
	 *
	 * @since 0.2.0
	 *
	 * @var SseFrameBuffer
	 */
	private $frame_buffer;

	/**
	 * Whether the stream contained an error event (glm38-6).
	 *
	 * The CHANNEL is the shared plumbing — a boolean the concrete
	 * aggregator's consume_frame() raises when the wire declares a
	 * provider-side failure and the model consults through the final
	 * getter before trusting any aggregated payload. WHAT trips the
	 * channel is each wire's own semantics (the OpenAI surface's payload
	 * PRESENT error member or `event: error` declaration; the Anthropic
	 * surface's declared error event) and stays at each subclass's
	 * raising sites, which is where the two wires genuinely differ.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	protected $error = false;

	/**
	 * Whether a frame's corruption tripped the malformed-event channel
	 * (glm38-6).
	 *
	 * Like $error above: the boolean channel and its getter are shared,
	 * the corruption CLASSES that raise it are per-wire (the OpenAI
	 * surface's unusable choice/tool-call index members; the Anthropic
	 * surface's undecodable or impossible declared-event shapes) and live
	 * at each subclass's raising sites.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	protected $malformed_event = false;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		$this->frame_buffer = new SseFrameBuffer();
	}

	/**
	 * Feeds a raw chunk of the event stream.
	 *
	 * Frames are separated by a blank line; the shared SseFrameBuffer
	 * normalizes mixed CR/LF/CRLF terminators, holds split chunks, and
	 * strips the stream-start BOM prefix.
	 *
	 * @since 0.2.0
	 *
	 * @param string $chunk Raw bytes as received from the transport.
	 * @return void
	 */
	public function feed( string $chunk ): void {
		$this->frame_buffer->feed( $chunk );
		$this->consume_ready_frames();
	}

	/**
	 * Marks the stream complete, flushing any final unterminated frame.
	 *
	 * A response may end directly after the last field line with no
	 * blank line following it; whatever remains buffered is a real final
	 * frame, not a split chunk — discarding it would lose the final
	 * event (a single-event stream would fail as unusable, a multi-event
	 * one its last content).
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function finish(): void {
		$this->frame_buffer->finish();
		$this->consume_ready_frames();
	}

	/**
	 * Whether the stream contained an error event.
	 *
	 * Final (glm38-6): the getter is pure plumbing over the shared
	 * channel — every surface answers through this one body, so a future
	 * contract change (a reset method, a new flag) lands once instead of
	 * drifting between the byte-identical copies both aggregators
	 * carried.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the stream contained an error event.
	 */
	final public function has_error(): bool {
		return $this->error;
	}

	/**
	 * Whether a frame tripped the malformed-event channel.
	 *
	 * True means the stream is corrupt in a way the wire's own raising
	 * sites define; the model must surface its fixed parse-error message
	 * instead of completing with the damaged content silently missing.
	 * Final for the same reason as has_error(): one body, both surfaces.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when a frame was flagged malformed.
	 */
	final public function has_malformed_event(): bool {
		return $this->malformed_event;
	}

	/**
	 * Consumes one complete SSE frame: the concrete aggregator's event
	 * semantics.
	 *
	 * @since 0.2.0
	 *
	 * @param string $frame Frame contents (without the separating blank line).
	 * @return void
	 */
	abstract protected function consume_frame( string $frame ): void;

	/**
	 * Consumes every frame the buffer has completed so far.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function consume_ready_frames(): void {
		while ( true ) {
			$frame = $this->frame_buffer->pull();
			if ( null === $frame ) {
				break;
			}

			$this->consume_frame( $frame );
		}
	}
}
