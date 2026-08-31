<?php
/**
 * Server-sent-events aggregator for Anthropic Messages streaming responses.
 *
 * Pure value collector (no WordPress, no I/O): the Anthropic event sequence
 * — message_start, content_block_start, content_block_delta (text_delta /
 * input_json_delta / thinking_delta), content_block_stop, message_delta,
 * message_stop, ping, error — is fed in as received and merged into ONE
 * consolidated Messages payload (the non-streaming response shape) that the
 * model's non-streaming parser consumes.
 *
 * Shares the transport framing (buffering, mixed CR/LF/CRLF terminators,
 * split chunks, final unterminated frame) with the OpenAI-style aggregator
 * via SseFrameBuffer; differs in the EVENT semantics: Anthropic frames carry
 * an `event:` field next to `data:`, there is no [DONE] sentinel
 * (message_stop ends the stream), and content is block-indexed rather than
 * choice-indexed.
 *
 * Malformed JSON events are counted and skipped, never fatal; unknown event
 * types and fields are ignored. Error EVENTS are recorded as a flag only —
 * their upstream payload is never retained (the model surfaces a fixed,
 * redacted message). Tool-use blocks whose accumulated input_json_delta
 * fragments do not decode to a JSON OBJECT ({} stays legitimate) are
 * likewise flagged via has_malformed_tool_input() — truncated or corrupt
 * streamed arguments must fail as a parse error, never fabricate a
 * no-argument tool call.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * SSE aggregator producing a consolidated Messages payload.
 *
 * @since 0.2.0
 */
final class AnthropicSseAggregator {

	/**
	 * The protocol-neutral frame splitter (shared with the OpenAI-style
	 * aggregator).
	 *
	 * @since 0.2.0
	 *
	 * @var SseFrameBuffer
	 */
	private $frame_buffer;

	/**
	 * Message id from message_start.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $message_id;

	/**
	 * Input tokens reported with message_start (plus cache variants).
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	private $input_tokens;

	/**
	 * Output tokens reported with message_delta.
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	private $output_tokens;

	/**
	 * Stop reason reported with message_delta.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $stop_reason;

	/**
	 * Content block accumulators keyed by stream block index.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $blocks = array();

	/**
	 * Block indexes in content_block_start order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private $block_order = array();

	/**
	 * Whether an error event was received.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $error = false;

	/**
	 * Whether the message_stop event was received.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $done = false;

	/**
	 * Number of well-formed data events consumed.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $events = 0;

	/**
	 * Number of malformed data events (bad JSON) skipped.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $malformed = 0;

	/**
	 * Whether a tool_use block's accumulated input JSON failed to decode
	 * into an object (truncated or corrupt streamed tool arguments).
	 *
	 * Set during aggregated(); the model turns the flag into its fixed,
	 * typed stream-parse error so no FunctionCall is fabricated from the
	 * fragments.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $malformed_tool_input = false;

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
	 * A stream may end directly after the last event with no blank line
	 * following it; the remainder is a real final frame, not a split chunk.
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

	/**
	 * Whether an error event was received.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the stream contained an error event.
	 */
	public function has_error(): bool {
		return $this->error;
	}

	/**
	 * Whether the message_stop event was received.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the stream was terminated by message_stop.
	 */
	public function is_done(): bool {
		return $this->done;
	}

	/**
	 * Number of well-formed data events consumed.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function event_count(): int {
		return $this->events;
	}

	/**
	 * Number of malformed data events (bad JSON) skipped.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function malformed_count(): int {
		return $this->malformed;
	}

	/**
	 * Whether a tool_use block's streamed input JSON was unusable.
	 *
	 * Set during feed() (a malformed start-block input or a non-string
	 * partial_json member) or while aggregated() builds the payload
	 * (fragments that do not decode to an object): true means the stream's
	 * tool arguments were truncated or corrupt, and the caller must treat
	 * the whole response as a parse error rather than use the payload.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when at least one tool_use block carried
	 *              non-object (or undecodable) input JSON.
	 */
	public function has_malformed_tool_input(): bool {
		return $this->malformed_tool_input;
	}

	/**
	 * Aggregates the consumed events into one Messages payload.
	 *
	 * A stream that never delivered a stop reason (message_delta missing —
	 * a truncated body from a gateway, exactly the wrapped-garbage shape
	 * the coding surface produces per record 0007) is NOT a completion:
	 * fabricating end_turn would mask truncation as a clean stop, so null
	 * is returned and the model surfaces its fixed parse-error message
	 * (review finding).
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>|null Null when no usable completion was consumed.
	 */
	public function aggregated(): ?array {
		if ( null === $this->stop_reason ) {
			return null;
		}

		$content = array();
		foreach ( $this->ordered_blocks() as $block ) {
			$mapped = $this->content_block_payload( $block );
			if ( null !== $mapped ) {
				$content[] = $mapped;
			}
		}

		return array(
			'id'          => \is_string( $this->message_id ) ? $this->message_id : '',
			'type'        => 'message',
			'role'        => 'assistant',
			'content'     => $content,
			'stop_reason' => $this->stop_reason,
			'usage'       => array(
				'input_tokens'  => $this->input_tokens ?? 0,
				'output_tokens' => $this->output_tokens ?? 0,
			),
		);
	}

	/**
	 * Returns the block accumulators in stream order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array<string, mixed>> Blocks ordered by appearance.
	 */
	private function ordered_blocks(): array {
		$ordered = array();
		foreach ( $this->block_order as $index ) {
			if ( isset( $this->blocks[ $index ] ) ) {
				$ordered[] = $this->blocks[ $index ];
			}
		}

		return $ordered;
	}

	/**
	 * Builds the non-streaming content block for one accumulator.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $block The accumulated block.
	 * @return array<string, mixed>|null The block payload, or null for
	 *                                   unknown types and rejected blocks.
	 */
	private function content_block_payload( array $block ): ?array {
		switch ( $block['type'] ) {
			case 'text':
				return array(
					'type' => 'text',
					'text' => $block['text'],
				);

			case 'thinking':
				return array(
					'type'     => 'thinking',
					'thinking' => $block['thinking'],
				);

			case 'tool_use':
				$input = $block['input'];
				if ( $block['has_json'] ) {
					// The accumulated input_json_delta fragments MUST decode
					// to a JSON OBJECT ({} is legitimate). A decode failure
					// or a non-object value means the stream was truncated or
					// corrupt: silently substituting {} would fabricate a
					// valid no-argument tool call whose inputs the model
					// never produced — a consumer could execute a
					// side-effecting tool with wrong arguments. Such a block
					// is rejected (flagged; the model surfaces the fixed
					// parse-error message) instead.
					$decoded = json_decode( $block['json'] );
					if ( ! $decoded instanceof \stdClass ) {
						$this->malformed_tool_input = true;

						return null;
					}

					$input = json_decode( $block['json'], true );

					// The empty object {} must survive the consolidated
					// boundary as an OBJECT: an associative empty array
					// would re-encode as the empty list [] and fail the
					// model's raw object-ness oracle (Codex R3 #1).
					if ( ! \is_array( $input ) || array() === $input ) {
						$input = new \stdClass();
					}
				}

				return array(
					'type'  => 'tool_use',
					'id'    => $block['id'],
					'name'  => $block['name'],
					'input' => $input,
				);
		}

		// Unknown block types (server tool use, search results, future
		// additions) are dropped rather than mis-mapped.
		return null;
	}

	/**
	 * Consumes one complete SSE frame.
	 *
	 * @since 0.2.0
	 *
	 * @param string $frame Frame contents (without the separating blank line).
	 * @return void
	 */
	private function consume_frame( string $frame ): void {
		$event_name = null;
		$data_lines = array();

		foreach ( explode( "\n", $frame ) as $line ) {
			if ( '' === $line || 0 === strpos( $line, ':' ) ) {
				// Empty line or comment (keep-alive): ignore.
				continue;
			}

			if ( 0 === strpos( $line, 'event:' ) ) {
				$event_name = ltrim( substr( $line, 6 ), ' ' );
				continue;
			}

			if ( 0 === strpos( $line, 'data:' ) ) {
				$data_lines[] = ltrim( substr( $line, 5 ), ' ' );
				continue;
			}

			// id:/retry: and unknown fields are ignored.
		}

		if ( array() === $data_lines ) {
			return;
		}

		$payload = implode( "\n", $data_lines );
		$decoded = json_decode( $payload, true );

		if ( ! \is_array( $decoded ) ) {
			++$this->malformed;

			return;
		}

		++$this->events;

		$type = \is_string( $event_name )
			? $event_name
			: ( isset( $decoded['type'] ) && \is_string( $decoded['type'] ) ? $decoded['type'] : '' );

		/*
		 * Object-ness oracle (Codex R3 #1/#2): the associative decode above
		 * collapses JSON {} and [] to the same empty PHP array, so the raw
		 * non-associative decode of the same payload travels along for the
		 * tool-input shape decisions.
		 */
		$this->dispatch_event( $type, $decoded, json_decode( $payload ) );
	}

	/**
	 * Dispatches one decoded event by type.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $type Event type (event: field or data.type).
	 * @param array<string, mixed> $data The decoded event payload.
	 * @param mixed                $raw  Non-associative decode of the same payload.
	 * @return void
	 */
	private function dispatch_event( string $type, array $data, $raw ): void {
		switch ( $type ) {
			case 'message_start':
				$message = isset( $data['message'] ) && \is_array( $data['message'] ) ? $data['message'] : array();
				if ( isset( $message['id'] ) && \is_string( $message['id'] ) ) {
					$this->message_id = $message['id'];
				}
				if ( isset( $message['usage'] ) && \is_array( $message['usage'] ) ) {
					$this->input_tokens = (int) ( $message['usage']['input_tokens'] ?? 0 )
						+ (int) ( $message['usage']['cache_creation_input_tokens'] ?? 0 )
						+ (int) ( $message['usage']['cache_read_input_tokens'] ?? 0 );
				}
				return;

			case 'content_block_start':
				$index = isset( $data['index'] ) ? (int) $data['index'] : 0;
				$block = isset( $data['content_block'] ) && \is_array( $data['content_block'] ) ? $data['content_block'] : array();

				$raw_block = \is_object( $raw ) && isset( $raw->content_block ) && \is_object( $raw->content_block )
					? $raw->content_block
					: null;

				$this->start_block( $index, $block, $raw_block );
				return;

			case 'content_block_delta':
				$this->apply_delta( isset( $data['index'] ) ? (int) $data['index'] : 0, $data );
				return;

			case 'content_block_stop':
				// The accumulator map already holds the block; nothing to move.
				return;

			case 'message_delta':
				if ( isset( $data['delta'] ) && \is_array( $data['delta'] ) && isset( $data['delta']['stop_reason'] ) && \is_string( $data['delta']['stop_reason'] ) ) {
					$this->stop_reason = $data['delta']['stop_reason'];
				}
				if ( isset( $data['usage'] ) && \is_array( $data['usage'] ) ) {
					$this->output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );
				}
				return;

			case 'message_stop':
				$this->done = true;
				return;

			case 'ping':
				return;

			case 'error':
				// The upstream error payload is deliberately NOT retained;
				// the model surfaces a fixed, redacted message instead.
				$this->error = true;
				return;
		}

		// Unknown event types are ignored (forward compatibility).
	}

	/**
	 * Starts (or resets) the content block accumulator at a stream index.
	 *
	 * A tool_use block's ORIGINAL input shape is validated here against the
	 * raw (non-associative) decode (Codex R3 #2): an object becomes the
	 * initial input value, a missing/null input member stays the no-argument
	 * placeholder, and anything else (scalar, boolean, JSON list —
	 * including []) marks the block malformed. {} is NEVER silently
	 * substituted for a malformed value: that fabricated a valid
	 * no-argument tool call the model never produced.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $index     Stream block index.
	 * @param array<string, mixed> $block     The content_block payload (associative).
	 * @param \stdClass|null       $raw_block The same payload, non-associatively decoded.
	 * @return void
	 */
	private function start_block( int $index, array $block, $raw_block ): void {
		$type = isset( $block['type'] ) && \is_string( $block['type'] ) ? $block['type'] : 'text';

		$input = new \stdClass();

		if ( 'tool_use' === $type && null !== $raw_block && \property_exists( $raw_block, 'input' ) ) {
			$raw_input = $raw_block->input;

			if ( null === $raw_input ) {
				// Explicit null: no-argument call (same as a missing member).
				$input = new \stdClass();
			} elseif ( \is_object( $raw_input ) ) {
				$assoc = json_decode( (string) wp_json_encode( $raw_input ), true );
				$input = \is_array( $assoc ) && array() !== $assoc ? $assoc : new \stdClass();
			} else {
				// A scalar, boolean, or JSON list value (an empty list
				// included) — malformed streamed tool arguments.
				$this->malformed_tool_input = true;
			}
		}

		$this->blocks[ $index ] = array(
			'type'     => $type,
			'text'     => isset( $block['text'] ) && \is_string( $block['text'] ) ? $block['text'] : '',
			'thinking' => isset( $block['thinking'] ) && \is_string( $block['thinking'] ) ? $block['thinking'] : '',
			'id'       => isset( $block['id'] ) && \is_string( $block['id'] ) ? $block['id'] : null,
			'name'     => isset( $block['name'] ) && \is_string( $block['name'] ) ? $block['name'] : null,
			'input'    => $input,
			'json'     => '',
			'has_json' => false,
		);

		if ( ! \in_array( $index, $this->block_order, true ) ) {
			$this->block_order[] = $index;
		}
	}

	/**
	 * Applies one content_block_delta to the accumulator at a stream index.
	 *
	 * A delta for an index without content_block_start (defensive) starts a
	 * default text block first.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $index Stream block index.
	 * @param array<string, mixed> $data  The event payload.
	 * @return void
	 */
	private function apply_delta( int $index, array $data ): void {
		if ( ! isset( $this->blocks[ $index ] ) ) {
			$this->start_block( $index, array( 'type' => 'text' ), null );
		}

		$delta = isset( $data['delta'] ) && \is_array( $data['delta'] ) ? $data['delta'] : array();

		if ( isset( $delta['type'] ) && \is_string( $delta['type'] ) ) {
			switch ( $delta['type'] ) {
				case 'text_delta':
					if ( isset( $delta['text'] ) && \is_string( $delta['text'] ) ) {
						$this->blocks[ $index ]['text'] .= $delta['text'];
					}
					return;

				case 'thinking_delta':
					if ( isset( $delta['thinking'] ) && \is_string( $delta['thinking'] ) ) {
						$this->blocks[ $index ]['thinking'] .= $delta['thinking'];
					}
					return;

				case 'input_json_delta':
					if ( isset( $delta['partial_json'] ) && ! \is_string( $delta['partial_json'] ) ) {
						/*
						 * The protocol's partial_json member is a STRING; a
						 * non-string value is a corrupt streamed-arguments
						 * event. Dropping it silently (verifier finding on
						 * Codex R3) would surface a no-argument call built
						 * from a broken stream — flag it like every other
						 * malformed tool input instead.
						 */
						$this->malformed_tool_input = true;

						return;
					}

					if ( isset( $delta['partial_json'] ) ) {
						$this->blocks[ $index ]['json'] .= $delta['partial_json'];
						if ( '' !== $delta['partial_json'] ) {
							$this->blocks[ $index ]['has_json'] = true;
						}
					}
					return;
			}
		}
	}
}
