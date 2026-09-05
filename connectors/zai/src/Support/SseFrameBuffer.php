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
	 * The bytes a gateway may place before the first field line: PHP's
	 * DEFAULT ltrim() charlist (space, tab, newline, CR, NUL, vertical
	 * tab — the GLM6 #11 set), the one set both this class's stream-start
	 * handling and EventStreamSniff judge by (GLM8 #2).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const LEADING_WHITESPACE = " \t\n\r\0\x0B";

	/**
	 * The UTF-8 byte-order mark a gateway or CDN may prepend to a stream.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const UTF8_BOM = "\xEF\xBB\xBF";

	/**
	 * Stream-start state: no prefix decision possible yet (GLM8 #2).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const PREFIX_UNDECIDED = 0;

	/**
	 * Stream-start state: the BOM was consumed; the whitespace run after
	 * it is still being stripped (GLM8 #2).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const PREFIX_AFTER_BOM = 1;

	/**
	 * Stream-start state: the prefix is settled; nothing more is stripped
	 * (GLM8 #2).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const PREFIX_SETTLED = 2;

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
	 * The stream-start prefix state (GLM8 #2).
	 *
	 * The prefix rule — strip one leading run of LEADING_WHITESPACE, then
	 * a UTF-8 BOM, then the whitespace after it, ONLY when a BOM is
	 * actually present — applies exactly once, at stream start. While the
	 * state is undecided (a whitespace-only buffer may still extend into
	 * a BOM, and a BOM may be split across chunks) NO frame splitting
	 * runs either: the decision changes the first frame's bytes, so
	 * splitting before it is settled would emit frames the strip would
	 * have altered. PREFIX_AFTER_BOM keeps stripping the unbounded
	 * whitespace run behind a confirmed BOM until the first field byte
	 * settles it; from PREFIX_SETTLED on, nothing is stripped ever again
	 * (a mid-stream BOM is frame CONTENT, as before).
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $prefix_state = self::PREFIX_UNDECIDED;

	/**
	 * Strips the one stream-start prefix both layers recognize (GLM8 #2):
	 * leading whitespace, a UTF-8 BOM, then the whitespace after it —
	 * applied only when a BOM is actually present.
	 *
	 * ONE canonical composition serves BOTH layers: EventStreamSniff
	 * routes bodies through this method, and feed() implements the
	 * identical rule incrementally (holding a whitespace-only or
	 * split-BOM prefix across chunk boundaries until the next byte
	 * disambiguates it), so the sniff can never again accept a body the
	 * framing below silently mis-parses — the whitespace-then-BOM shape
	 * (and its BOM-then-whitespace mirror) whose first frame matched no
	 * field, was silently dropped, and surfaced corrupted content as a
	 * success.
	 *
	 * A body WITHOUT a BOM is returned UNCHANGED, leading whitespace and
	 * all: the plain-ws prefix is deliberately not stripped here. Such a
	 * stream still routes to the SSE aggregator (the sniff's own ltrim
	 * tolerance) and still drops its whitespace-prefixed first frame —
	 * the spec-correct, master-identical behavior.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The raw response body.
	 * @return string The body with its BOM-adjacent prefix removed.
	 */
	public static function strip_stream_prefix( string $body ): string {
		$rest = ltrim( $body, self::LEADING_WHITESPACE );

		if ( 0 === strpos( $rest, self::UTF8_BOM ) ) {
			return ltrim( substr( $rest, \strlen( self::UTF8_BOM ) ), self::LEADING_WHITESPACE );
		}

		return $body;
	}

	/**
	 * Feeds a raw chunk of the event stream.
	 *
	 * GLM15-24 (the honest bound): functionally correct for ANY chunking,
	 * but asymptotically each call re-normalizes line endings and rescans
	 * for frame delimiters over the ENTIRE unconsumed buffer — feeding one
	 * large frame in k small chunks costs O(k · unconsumed-tail), quadratic
	 * in the tail size (consumed frames are already split off; it is never
	 * quadratic in the whole stream). Both production callers feed the
	 * COMPLETE body in one call, so the bound is latent; a persistent
	 * normalized/scanned cursor (paying only for the newly appended bytes)
	 * is deferred until an incremental consumer exists — the prefix-strip
	 * states rewrite the buffer head and the CR-hold rewrites its tail, so
	 * every cursor transition there is regression surface in this, the
	 * suite's most heavily pinned support class, for zero current benefit.
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
		 * stream lost its first delta.
		 *
		 * GLM8 #2: the strip window now recognizes the SAME
		 * whitespace-BOM-whitespace prefix EventStreamSniff routes on
		 * (see strip_stream_prefix()): the sniff ltrimmed before its BOM
		 * test while this strip ran at byte 0 only, so a
		 * whitespace-then-BOM body (and a BOM-then-whitespace one)
		 * misrouted to the SSE aggregator whose first frame then matched
		 * no field — silently dropped, corrupted content as success. The
		 * prefix is held undecided across chunk boundaries (a
		 * whitespace-only buffer may still extend into a BOM; a BOM may
		 * be split across chunks — SSE framing begins with ASCII field
		 * names, so a genuine \xEF-led first byte cannot lose data by
		 * waiting), and no frame is split before the decision: the strip
		 * changes the first frame's bytes.
		 */
		if ( self::PREFIX_SETTLED !== $this->prefix_state ) {
			if ( self::PREFIX_UNDECIDED === $this->prefix_state ) {
				$rest = ltrim( $this->buffer, self::LEADING_WHITESPACE );

				if ( '' === $rest || "\xEF" === $rest || "\xEF\xBB" === $rest || self::UTF8_BOM === $rest ) {
					// Nothing decidable yet (an empty chunk keeps the
					// window open, as before): hold, including framing.
					return;
				}

				if ( 0 === strpos( $rest, self::UTF8_BOM ) ) {
					// BOM confirmed: strip through it and keep stripping
					// the whitespace after it until a field byte arrives
					// (one unbounded run — the strip_stream_prefix() rule).
					$this->buffer = ltrim( substr( $rest, \strlen( self::UTF8_BOM ) ), self::LEADING_WHITESPACE );

					if ( '' === $this->buffer ) {
						$this->prefix_state = self::PREFIX_AFTER_BOM;

						return;
					}
				} else {
					/*
					 * No BOM behind the leading whitespace: the buffer
					 * stands as received — the whitespace-prefixed first
					 * frame is the spec-correct DROPPED frame both
					 * surfaces have always produced (master-identical).
					 */
					$this->prefix_state = self::PREFIX_SETTLED;
				}
			} else {
				// PREFIX_AFTER_BOM: the whitespace run behind the BOM.
				$this->buffer = ltrim( $this->buffer, self::LEADING_WHITESPACE );

				if ( '' === $this->buffer ) {
					return;
				}
			}

			$this->prefix_state = self::PREFIX_SETTLED;
		}

		$held_back_cr = '';
		if ( "\r" === substr( $this->buffer, -1 ) ) {
			$held_back_cr = "\r";
			$this->buffer = substr( $this->buffer, 0, -1 );
		}

		/*
		 * glm15-24: this whole-buffer str_replace is half of feed()'s
		 * documented O(unconsumed-tail) per-call bound — see the
		 * docblock. It is one pass, not one pass per frame (the scan
		 * below is offset-driven, Codex R18 #1), and the single-feed
		 * shape both model parsers use keeps it O(body).
		 */
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
