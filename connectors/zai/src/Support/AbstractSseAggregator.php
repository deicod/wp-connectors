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
