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
	 * Indexes whose content_block_stop was received (Codex R8 #1).
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, true>
	 */
	private $stopped_indexes = array();

	/**
	 * Whether the message_start event was received (Codex R8 #3).
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $message_started = false;

	/**
	 * Whether the (single) message_delta event was received (Codex R9 #2).
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $message_delta_received = false;

	/**
	 * Event names the aggregator actively dispatches on (Codex R4 #3).
	 *
	 * A frame DECLARING one of these names with an undecodable payload is a
	 * corrupt stream event, not ignorable noise: it must invalidate the
	 * whole response (a silently dropped content delta would alter the
	 * generated answer). Unknown event names and ping/keep-alive frames
	 * stay ignorable for forward compatibility.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const DECLARED_EVENTS = array(
		'message_start',
		'content_block_start',
		'content_block_delta',
		'content_block_stop',
		'message_delta',
		'message_stop',
		'error',
	);

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
	 * Whether message_stop terminated the stream (Codex R8 #2).
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $terminated = false;

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
	 * Whether a frame DECLARING a known event name carried an undecodable
	 * payload (Codex R4 #3): the stream is corrupt and the model must
	 * surface its fixed parse-error message instead of completing with the
	 * damaged content silently missing.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $malformed_event = false;

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
	 * Whether a declared event frame carried an undecodable payload.
	 *
	 * True means the stream is corrupt: at least one frame explicitly
	 * declaring a known event name (message_start, content_block_*,
	 * message_delta, message_stop, error) had a payload that failed JSON
	 * decoding, so the aggregated completion would be missing that event's
	 * content and must be treated as a parse error.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when a declared event frame was malformed.
	 */
	public function has_malformed_event(): bool {
		return $this->malformed_event;
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
		/*
		 * Codex R8 #3: a stream without message_start must not aggregate —
		 * doing so fabricated an assistant envelope (blank id/usage) and
		 * bypassed the R6 streamed-role validation entirely. The model
		 * checks this flag after aggregation.
		 */
		if ( ! $this->message_started ) {
			$this->malformed_event = true;

			return null;
		}

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

		/*
		 * Codex R8 #2: after message_stop terminated the stream, only
		 * keepalive traffic (comments and pings) may follow. Any other
		 * data frame is a corrupt post-termination event.
		 */
		if ( $this->terminated && '' !== trim( implode( "\n", $data_lines ) ) ) {
			$type_probe   = json_decode( implode( "\n", $data_lines ), true );
			$is_keepalive = \is_array( $type_probe ) && isset( $type_probe['type'] ) && 'ping' === $type_probe['type'];

			if ( ! $is_keepalive ) {
				++$this->malformed;
				$this->malformed_event = true;

				return;
			}
		}

		$payload = implode( "\n", $data_lines );
		$decoded = json_decode( $payload, true );

		if ( ! \is_array( $decoded ) ) {
			++$this->malformed;

			/*
			 * Codex R4 #3: a frame DECLARING a known event name with an
			 * undecodable payload is a corrupt stream event, not noise —
			 * silently dropping it (a content delta, say) would return a
			 * successful completion with that chunk of the answer missing.
			 * Invalidate the stream; unknown event names and ping frames
			 * stay ignorable.
			 */
			if ( \is_string( $event_name ) && \in_array( $event_name, self::DECLARED_EVENTS, true ) ) {
				$this->malformed_event = true;
			}

			return;
		}

		$payload_type = isset( $decoded['type'] ) && \is_string( $decoded['type'] ) ? $decoded['type'] : '';
		$type         = \is_string( $event_name ) ? $event_name : $payload_type;

		/*
		 * Codex R7 #6: when BOTH declarations are present as strings they
		 * must AGREE — the event: field always won, so `event: ping` with a
		 * content_block_delta payload was ignored as keep-alive and the
		 * answer completed with the content chunk missing. A contradiction
		 * is a corrupt frame; frames with only one declaration keep their
		 * existing behavior.
		 */
		if ( \is_string( $event_name ) && '' !== $payload_type && $event_name !== $payload_type ) {
			++$this->malformed;
			$this->malformed_event = true;

			return;
		}

		/*
		 * Object-ness oracle (Codex R3 #1/#2): the associative decode above
		 * collapses JSON {} and [] to the same empty PHP array, so the raw
		 * non-associative decode of the same payload travels along for the
		 * tool-input shape decisions.
		 */
		$raw = json_decode( $payload );

		/*
		 * Verifier sweep on Codex R4: a DECLARED event (by event: field or
		 * — for data-only frames — by the payload's own type member) whose
		 * payload is valid JSON but NOT an object (a list, e.g. a dropped
		 * chunk inside ["lost"]) is the same corruption class as an
		 * undecodable payload: is_array() cannot tell a JSON list from a
		 * JSON object, the raw decode can. Flag it; unknown names stay
		 * ignorable.
		 */
		if ( \in_array( $type, self::DECLARED_EVENTS, true ) && ! \is_object( $raw ) ) {
			++$this->malformed;
			$this->malformed_event = true;

			return;
		}

		++$this->events;

		$this->dispatch_event( $type, $decoded, $raw );
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
				/*
				 * Codex R13 #2: the protocol sends exactly ONE message_start.
				 * A second (even valid) one overwrote the first message id
				 * and input-token usage while the generated content still
				 * succeeded — guarded exactly like duplicate block starts and
				 * duplicate message deltas.
				 */
				if ( $this->message_started ) {
					$this->malformed_event = true;

					return;
				}

				/*
				 * Codex R12 #1: the completion prerequisite is satisfied
				 * only by a message_start that actually carries a valid
				 * message OBJECT — a missing, null, scalar, or list message
				 * previously set message_started unconditionally, letting
				 * later valid content and message_delta fabricate an
				 * assistant envelope with a blank id and zero input usage
				 * (a bypass of the R8 #3 missing-start guard). An invalid
				 * payload marks the stream malformed in the same channel as
				 * the role violation; message_started stays untouched.
				 */
				if ( ! \is_object( $raw ) || ! isset( $raw->message ) || ! \is_object( $raw->message ) ) {
					$this->malformed_event = true;

					return;
				}

				$this->message_started = true;

				/*
				 * Codex R6 #2: the streamed envelope role must be validated
				 * HERE — aggregated() fabricates role:assistant, which would
				 * otherwise make a user/other-role stream slip past the
				 * model's exact-role check (a bypass the non-streaming path
				 * does not have). Absent → assistant default (documented
				 * tolerance for streams that omit it); 'assistant' → ok;
				 * anything else (null included — array_key_exists treats it
				 * as present) → the stream is corrupt.
				 */
				if ( property_exists( $raw->message, 'role' ) && 'assistant' !== $raw->message->role ) {
					$this->malformed_event = true;

					return;
				}

				/*
				 * Codex R14 #1: an envelope that explicitly declares a type OTHER
				 * than "message" (e.g. an error object wearing an assistant role)
				 * contradicts the generation aggregated() builds, which hardcodes
				 * the envelope type — the non-streaming path rejects the same
				 * contradictory shape. A PRESENT type must be exactly "message"
				 * (explicit null is present and therefore rejected, mirroring the
				 * non-streaming array_key_exists semantics); an ABSENT type stays
				 * tolerated like the documented envelope tolerances above.
				 */
				if ( property_exists( $raw->message, 'type' ) && 'message' !== $raw->message->type ) {
					$this->malformed_event = true;

					return;
				}

				$message = isset( $data['message'] ) && \is_array( $data['message'] ) ? $data['message'] : array();
				if ( isset( $message['id'] ) && \is_string( $message['id'] ) ) {
					$this->message_id = $message['id'];
				}
				if ( \array_key_exists( 'usage', $message ) ) {
					/*
					 * Codex R15 #1: streamed usage is validated BEFORE the casts
					 * store it — the casts previously normalized "5", 3.7, true,
					 * and negatives into plausible counts, and a list-shaped
					 * usage silently became zero (the named member is absent),
					 * all before parse_message_body()'s strict validator could
					 * see the original types.
					 */
					$raw_message_usage = \is_object( $raw->message ) && \property_exists( $raw->message, 'usage' ) ? $raw->message->usage : null;
					if ( ! self::streamed_usage_is_valid( $message['usage'], $raw_message_usage ) ) {
						$this->malformed_event = true;

						return;
					}

					$this->input_tokens = (int) ( $message['usage']['input_tokens'] ?? 0 )
						+ (int) ( $message['usage']['cache_creation_input_tokens'] ?? 0 )
						+ (int) ( $message['usage']['cache_read_input_tokens'] ?? 0 );
				}
				return;

			case 'content_block_start':
				/*
				 * Codex R13 #1: content events after the final message_delta
				 * mutated the accumulators — the completion then succeeded with
				 * text or tool args received after the final message metadata.
				 * Rejected in the same channel as the duplicate-delta guard.
				 */
				if ( $this->message_delta_received ) {
					$this->malformed_event = true;

					return;
				}

				$index = self::raw_block_index( $raw );

				if ( null === $index ) {
					$this->malformed_event = true;

					return;
				}

				$block = isset( $data['content_block'] ) && \is_array( $data['content_block'] ) ? $data['content_block'] : array();

				$raw_block = \is_object( $raw ) && isset( $raw->content_block ) && \is_object( $raw->content_block )
					? $raw->content_block
					: null;

				/*
				 * Verifier sweep on Codex R4: a content_block_start whose
				 * content_block member is absent or not an object is a
				 * corrupt declared event — defaulting to an empty text
				 * block would let a garbage tool-block start silently
				 * swallow the block's deltas (the tool call vanishes while
				 * the stream reports success). Flag it.
				 */
				if ( \is_object( $raw ) && ( ! \property_exists( $raw, 'content_block' ) || ! \is_object( $raw->content_block ) ) ) {
					$this->malformed_event = true;
				}

				$this->start_block( $index, $block, $raw_block );
				return;

			case 'content_block_delta':
				if ( $this->message_delta_received ) {
					$this->malformed_event = true;

					return;
				}

				/*
				 * Verifier sweep on Codex R4: a decodable-but-wrong-shape
				 * delta is the same corruption class as an undecodable one
				 * — silently dropping it returns a completion with that
				 * chunk of the answer missing. The delta member must be an
				 * object carrying a string type; text_delta/thinking_delta
				 * additionally require their string text/thinking member
				 * (mirroring the partial_json rule). Unknown delta types
				 * stay ignorable for forward compatibility;
				 * input_json_delta's partial_json is validated in
				 * apply_delta (Codex R4 #1).
				 */
				$raw_delta = \is_object( $raw ) && isset( $raw->delta ) && \is_object( $raw->delta )
					? $raw->delta
					: null;

				if ( null === $raw_delta ) {
					$this->malformed_event = true;

					return;
				}

				if ( ! \property_exists( $raw_delta, 'type' ) || ! \is_string( $raw_delta->type ) ) {
					$this->malformed_event = true;

					return;
				}

				if ( 'text_delta' === $raw_delta->type
					&& ( ! \property_exists( $raw_delta, 'text' ) || ! \is_string( $raw_delta->text ) ) ) {
					$this->malformed_event = true;

					return;
				}

				if ( 'thinking_delta' === $raw_delta->type
					&& ( ! \property_exists( $raw_delta, 'thinking' ) || ! \is_string( $raw_delta->thinking ) ) ) {
					$this->malformed_event = true;

					return;
				}

				$index = self::raw_block_index( $raw );

				if ( null === $index ) {
					$this->malformed_event = true;

					return;
				}

				$this->apply_delta( $index, $data );
				return;

			case 'content_block_stop':
				if ( $this->message_delta_received ) {
					$this->malformed_event = true;

					return;
				}

				// The event still names an index, and a malformed one marks
				// the stream corrupt (R6 #4 class).
				$index = self::raw_block_index( $raw );

				if ( null === $index ) {
					$this->malformed_event = true;

					return;
				}

				/*
				 * Codex R8 #1: a stop for an index that was never started,
				 * or a second stop for the same index, is a corrupt stream
				 * (nothing legitimate is being closed). Record the closed
				 * state so later deltas for the index are rejected too.
				 */
				if ( ! isset( $this->blocks[ $index ] ) || isset( $this->stopped_indexes[ $index ] ) ) {
					$this->malformed_event = true;

					return;
				}

				$this->stopped_indexes[ $index ] = true;

				return;

			case 'message_delta':
				/*
				 * Codex R9 #2: the protocol sends exactly ONE message_delta.
				 * A second one silently overwrote the first stop reason and
				 * usage — end_turn then tool_use made the completed result
				 * report toolCalls() with no corresponding function call.
				 * A repeat is a corrupt stream, never an overwrite (the
				 * payloads may differ, so it is NOT an idempotent no-op —
				 * superseding the R8 verifier's tolerance judgment).
				 */
				if ( $this->message_delta_received ) {
					$this->malformed_event = true;

					return;
				}

				$this->message_delta_received = true;

				if ( isset( $data['delta'] ) && \is_array( $data['delta'] ) && isset( $data['delta']['stop_reason'] ) && \is_string( $data['delta']['stop_reason'] ) ) {
					$this->stop_reason = $data['delta']['stop_reason'];
				}
				if ( \array_key_exists( 'usage', $data ) ) {
					// Codex R15 #1: same validation as message_start's input side.
					$raw_usage = \is_object( $raw ) && \property_exists( $raw, 'usage' ) ? $raw->usage : null;
					if ( ! self::streamed_usage_is_valid( $data['usage'], $raw_usage ) ) {
						$this->malformed_event = true;

						return;
					}

					$this->output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );
				}
				return;

			case 'message_stop':
				/*
				 * Codex R8 #2: message_stop is TERMINAL. Frames after it
				 * were still dispatched and could modify the returned
				 * text/tool args/stop reason/usage while the response
				 * succeeded. Consumption ends here; consume_frame() rejects
				 * anything but keepalive traffic that follows.
				 */
				$this->done = true;

				if ( $this->terminated ) {
					// A second message_stop is itself post-termination.
					$this->malformed_event = true;
				}

				$this->terminated = true;
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
	 * Validates a streamed usage member BEFORE any cast stores it
	 * (Codex R15 #1).
	 *
	 * A present usage must be an object-shaped array (a JSON list [1,2]
	 * passes is_array() after the associative decode, and its named
	 * members are then absent) whose SUPPLIED known token members are
	 * non-negative integers. Absent members stay tolerated (the
	 * default-zero tolerance is documented); a violation marks the
	 * stream malformed instead of normalizing the value into plausible
	 * accounting. Object-ness comes from the non-associative decode (the
	 * R3 #1 oracle), with the repo's sequential-key test as fallback
	 * when the oracle value is unavailable.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $usage     The associatively decoded usage member.
	 * @param mixed $raw_usage The same member from the non-associative
	 *                         decode, or null when unavailable.
	 * @return bool True when absent-shape-safe and valid; false marks the
	 *              stream malformed.
	 */
	private static function streamed_usage_is_valid( $usage, $raw_usage ): bool {
		if ( ! \is_array( $usage ) ) {
			// Present but scalar or null — not a usage object.
			return false;
		}

		if ( null === $raw_usage ) {
			// Oracle unavailable: the sequential-key list test.
			$is_object = array() === $usage
				|| \array_keys( $usage ) !== \range( 0, \count( $usage ) - 1 );
		} else {
			$is_object = \is_object( $raw_usage );
		}

		if ( ! $is_object ) {
			return false;
		}

		foreach ( array( 'input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens' ) as $member ) {
			if ( \array_key_exists( $member, $usage ) && ( ! \is_int( $usage[ $member ] ) || $usage[ $member ] < 0 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extracts the content block index from a raw decoded event payload.
	 *
	 * Codex R6 #4: the index is a non-negative INTEGER — a missing member
	 * or a string/float/null value was previously coerced to 0, so a
	 * malformed event MUTATED THE WRONG BLOCK (block 0) while the stream
	 * still succeeded. Returns null when the index is absent or not a
	 * non-negative integer; callers flag the stream malformed.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $raw Non-associative decode of the event payload.
	 * @return int|null The validated index, or null when malformed.
	 */
	private static function raw_block_index( $raw ): ?int {
		if ( ! \is_object( $raw ) || ! \property_exists( $raw, 'index' ) ) {
			return null;
		}

		$index = $raw->index;

		return \is_int( $index ) && $index >= 0 ? $index : null;
	}

	/**
	 * Starts (or resets) the content block accumulator at a stream index.
	 *
	 * A tool_use block's ORIGINAL input shape is validated here against the
	 * raw (non-associative) decode (Codex R3 #2): an object becomes the
	 * initial input value, a MISSING or explicitly-NULL input member marks
	 * the block malformed (Codex R7 #1 sibling — the protocol requires the
	 * member, an empty call is {} alone; normalizing it to a placeholder
	 * fabricated a valid no-argument tool call the model never produced),
	 * and anything else (scalar, boolean, JSON list — including []) also
	 * marks the block malformed. {} is NEVER silently substituted for a
	 * malformed value.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $index     Stream block index.
	 * @param array<string, mixed> $block     The content_block payload (associative).
	 * @param \stdClass|null       $raw_block The same payload, non-associatively decoded.
	 * @return void
	 */
	private function start_block( int $index, array $block, $raw_block ): void {
		/*
		 * Codex R8 #4: the block type is REQUIRED and must be a string —
		 * a missing or non-string type silently became a text block, and a
		 * following text_delta then succeeded on the fabricated block. No
		 * default: flag the stream malformed.
		 */
		$type = isset( $block['type'] ) && \is_string( $block['type'] ) ? $block['type'] : null;

		if ( null === $type ) {
			$this->malformed_event = true;

			return;
		}

		/*
		 * Codex R13 #3: the known non-tool block types REQUIRE their
		 * content member — a missing or non-string text/thinking member
		 * previously defaulted to '' and a later valid delta produced a
		 * successful response whose start payload was malformed (unlike
		 * the equivalent malformed deltas and non-streaming blocks).
		 */
		if ( 'text' === $type && ( ! array_key_exists( 'text', $block ) || ! \is_string( $block['text'] ) ) ) {
			$this->malformed_event = true;

			return;
		}

		if ( 'thinking' === $type && ( ! array_key_exists( 'thinking', $block ) || ! \is_string( $block['thinking'] ) ) ) {
			$this->malformed_event = true;

			return;
		}

		$input = new \stdClass();

		if ( 'tool_use' === $type && null !== $raw_block ) {
			if ( ! \property_exists( $raw_block, 'input' ) ) {
				// Absent member (Codex R7 #1 sibling).
				$this->malformed_tool_input = true;
			}

			$raw_input = \property_exists( $raw_block, 'input' ) ? $raw_block->input : null;

			if ( null === $raw_input ) {
				if ( \property_exists( $raw_block, 'input' ) ) {
					// Explicit null (Codex R7 #1 sibling).
					$this->malformed_tool_input = true;
				}
			} elseif ( \is_object( $raw_input ) ) {
				$assoc = json_decode( (string) wp_json_encode( $raw_input ), true );
				$input = \is_array( $assoc ) && array() !== $assoc ? $assoc : new \stdClass();
			} else {
				// A scalar, boolean, or JSON list value (an empty list
				// included) — malformed streamed tool arguments.
				$this->malformed_tool_input = true;
			}
		}

		/*
		 * Codex R7 #4: a SECOND start for an already-started index is a
		 * corrupt stream — silently replacing the accumulator discarded
		 * every text/thinking/tool fragment collected so far, and the
		 * later completion reported success with altered content. Flag it
		 * and keep the ORIGINAL accumulator (the flag fails the response
		 * before the payload is ever used).
		 */
		if ( \array_key_exists( $index, $this->blocks ) ) {
			$this->malformed_event = true;

			return;
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

		$this->block_order[] = $index;
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
		$delta = isset( $data['delta'] ) && \is_array( $data['delta'] ) ? $data['delta'] : array();

		/*
		 * Codex R8 #1: a delta for an index whose content_block_stop was
		 * already received appends to a closed block — the completion then
		 * carried post-stop modifications. Reject it.
		 */
		if ( isset( $this->stopped_indexes[ $index ] ) ) {
			$this->malformed_event = true;

			return;
		}

		if ( ! isset( $this->blocks[ $index ] ) ) {
			/*
			 * Codex R5 #2 / R10 #3: a delta for an index whose
			 * content_block_start was never received means the stream is
			 * damaged or resumed mid-flight — the content before the
			 * missing start is silently absent, and defaulting the unseen
			 * index to a synthesized accumulator returned a successful
			 * TRUNCATED completion (for tool deltas, one with NO
			 * FunctionCall at all). ALL known delta types now require an
			 * existing started block and invalidate the stream otherwise;
			 * unknown (future) delta types keep the seed below — they carry
			 * no content this aggregator maps, so seeding loses nothing.
			 */
			if ( isset( $delta['type'] ) && \is_string( $delta['type'] ) ) {
				if ( 'input_json_delta' === $delta['type'] ) {
					$this->malformed_tool_input = true;

					return;
				}

				if ( 'text_delta' === $delta['type'] || 'thinking_delta' === $delta['type'] ) {
					$this->malformed_event = true;

					return;
				}
			}

			/*
			 * Codex R14 #3: the seed must satisfy the R13 #3 start-member
			 * validation — a bare type-only text block is malformed there.
			 * Unknown (future) delta types still carry no content this
			 * aggregator maps, so an empty initial text value loses nothing.
			 */
			$this->start_block(
				$index,
				array(
					'type' => 'text',
					'text' => '',
				),
				null
			);
		}

		/*
		 * Codex R6 #3: each delta type must match the accumulator's block
		 * type — the protocol sends text deltas to text blocks, thinking
		 * deltas to thinking blocks, and JSON deltas to tool_use blocks.
		 * A mismatch previously appended to an incompatible accumulator
		 * whose payload builder IGNORES the member: an input_json_delta on
		 * a text/thinking block silently omitted the tool call while the
		 * stream finished with stop_reason tool_use, and text/thinking
		 * deltas on a tool block accumulated then discarded. Mismatches
		 * now invalidate the stream; unknown delta types keep their
		 * forward-compatible tolerance (no block-type claim to violate).
		 */
		$expected_type = null;
		if ( isset( $delta['type'] ) && \is_string( $delta['type'] ) ) {
			switch ( $delta['type'] ) {
				case 'text_delta':
					$expected_type = 'text';
					break;
				case 'thinking_delta':
					$expected_type = 'thinking';
					break;
				case 'input_json_delta':
					$expected_type = 'tool_use';
					break;
			}
		}

		if ( null !== $expected_type && $this->blocks[ $index ]['type'] !== $expected_type ) {
			if ( 'tool_use' === $expected_type ) {
				// Tool-argument corruption: the malformed-tool-input error.
				$this->malformed_tool_input = true;
			} else {
				$this->malformed_event = true;
			}

			return;
		}

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
					/*
					 * The protocol's partial_json member is a STRING, and
					 * in this tool-JSON context it is REQUIRED: a missing,
					 * null, or non-string member (isset() is false for both
					 * missing and null — Codex R4 #1) is a corrupt
					 * streamed-arguments event. Dropping it silently would
					 * surface a no-argument call built from a broken stream
					 * — flag it like every other malformed tool input.
					 */
					if ( ! \array_key_exists( 'partial_json', $delta ) || ! \is_string( $delta['partial_json'] ) ) {
						$this->malformed_tool_input = true;

						return;
					}

					$this->blocks[ $index ]['json'] .= $delta['partial_json'];
					if ( '' !== $delta['partial_json'] ) {
						$this->blocks[ $index ]['has_json'] = true;
					}
					return;
			}
		}
	}
}
