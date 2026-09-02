<?php
/**
 * Protocol-neutral server-sent-events frame splitter.
 *
 * Handles the SSE transport framing both protocol adapters share: chunks are
 * fed in as received, line endings are normalized (CR, LF, and CRLF may mix
 * freely), and complete frames — separated by a blank line — are queued for
 * consumption via pull(). The EVENT PAYLOAD semantics (event names, data
 * shapes, completion sentinels) stay with each protocol's aggregator.
 *
 * A trailing CR is held back between feed() calls because it may still
 * extend to CRLF when the next chunk arrives; finish() flushes whatever
 * remains — a response may end directly after the last field line with no
 * blank line following it, and that remainder is a real final frame, not a
 * split chunk.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Frame splitter for server-sent-events streams.
 *
 * @since 0.2.0
 */
final class SseFrameBuffer {

	/**
	 * Buffered bytes not yet forming a complete frame: line endings
	 * normalized to LF, except possibly one trailing CR that may still
	 * extend to CRLF when more data arrives.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Completed frames, in arrival order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private $frames = array();

	/**
	 * Read cursor into the completed-frame queue (Codex R15 #4).
	 *
	 * The array_shift() reindexes the remaining array on every pull, so
	 * draining a long stream (thousands of token-delta frames) was
	 * quadratic in the frame count; the cursor keeps each pull
	 * constant-time. The queue is compacted — cursor and frames both
	 * reset — as soon as the cursor passes the last frame, so a drained
	 * buffer retains nothing and a reused instance accepts new feeds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $cursor = 0;

	/**
	 * Whether the stream's first byte has arrived yet (the BOM window).
	 *
	 * A UTF-8 BOM is legal at stream start only, so the strip in feed()
	 * runs exactly once, while this flag is true and no frame has been
	 * consumed.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $awaiting_first_byte = true;

	/**
	 * Feeds a raw chunk of the event stream.
	 *
	 * @since 0.2.0
	 *
	 * @param string $chunk Raw bytes as received from the transport.
	 * @return void
	 */
	public function feed( string $chunk ): void {
		$this->buffer .= $chunk;

		/*
		 * GLM3 #7: a UTF-8 BOM (\xEF\xBB\xBF) prepended by a gateway or
		 * CDN glued itself to the first frame, where it matched no
		 * 'data:'/'event:' prefix — the frame was silently dropped (not
		 * even counted malformed), so a single-event stream aggregated to
		 * null ('No usable ... event was received') and a multi-event
		 * stream lost its first delta. The BOM is stripped at stream
		 * start only; a chunk that delivers just a BOM PREFIX (a BOM
		 * split across chunks) is held until the next byte disambiguates
		 * it — SSE framing begins with ASCII field names, so a genuine
		 * \xEF-led first byte cannot lose data by waiting.
		 */
		if ( $this->awaiting_first_byte && '' !== $this->buffer ) {
			if ( "\xEF" === $this->buffer || "\xEF\xBB" === $this->buffer ) {
				// Possibly a split BOM: wait for the next chunk.
				return;
			}

			if ( 0 === strpos( $this->buffer, "\xEF\xBB\xBF" ) ) {
				$this->buffer = substr( $this->buffer, 3 );
			}

			$this->awaiting_first_byte = false;
		}

		$held_back_cr = '';
		if ( "\r" === substr( $this->buffer, -1 ) ) {
			$held_back_cr = "\r";
			$this->buffer = substr( $this->buffer, 0, -1 );
		}

		$this->buffer = str_replace( array( "\r\n", "\r" ), "\n", $this->buffer ) . $held_back_cr;

		/*
		 * Codex R18 #1: the split scans with an OFFSET into the same
		 * string and discards the consumed prefix ONCE, after the loop.
		 * The previous shape reassigned $this->buffer inside the loop —
		 * one full-suffix copy per delimiter — so feeding a complete
		 * response with thousands of token-delta frames (as both model
		 * parsers do in one feed($body) call) made frame splitting
		 * quadratic even after the R15 #4 cursor made draining
		 * constant-time (~3.1 s for 80k small frames).
		 */
		$offset = 0;
		$pos    = strpos( $this->buffer, "\n\n", $offset );
		while ( false !== $pos ) {
			$this->frames[] = substr( $this->buffer, $offset, $pos - $offset );

			$offset = $pos + 2;
			$pos    = strpos( $this->buffer, "\n\n", $offset );
		}

		if ( $offset > 0 ) {
			$this->buffer = substr( $this->buffer, $offset );
		}
	}

	/**
	 * Marks the stream complete, flushing any final unterminated frame.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function finish(): void {
		$remaining    = str_replace( array( "\r\n", "\r" ), "\n", $this->buffer );
		$this->buffer = '';

		if ( '' !== $remaining ) {
			$this->frames[] = $remaining;
		}
	}

	/**
	 * Returns the next complete frame, in arrival order.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null Frame contents (without the separating blank line),
	 *                     or null when none remain.
	 */
	public function pull(): ?string {
		$count = \count( $this->frames );

		if ( $this->cursor >= $count ) {
			// Fully drained: compact so a reused buffer starts clean.
			$this->frames = array();
			$this->cursor = 0;

			return null;
		}

		$frame = $this->frames[ $this->cursor ];
		++$this->cursor;

		if ( $this->cursor >= \count( $this->frames ) ) {
			// This was the last frame: drop the queue immediately.
			$this->frames = array();
			$this->cursor = 0;
		}

		return $frame;
	}
}
