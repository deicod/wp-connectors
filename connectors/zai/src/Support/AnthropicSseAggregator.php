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
	 * Model name from message_start (GLM1 #9: carried into the consolidated
	 * payload so both transports expose the same fields).
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $model;

	/**
	 * Stop sequence from message_delta (GLM1 #9: same parity purpose).
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $stop_sequence;

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

		/*
		 * Codex R16 #1: the terminal event is REQUIRED — a transport that
		 * ends after a valid message_delta but before message_stop left
		 * stop_reason populated, so the truncated stream was returned as a
		 * successful generation while is_done() was still false. A missing
		 * message_stop is the same corruption class as the missing
		 * message_start above: mark the stream malformed and return null
		 * (never a soft null a caller might read as "no completion yet").
		 */
		if ( ! $this->done ) {
			$this->malformed_event = true;

			return null;
		}

		$content = array();
		foreach ( $this->ordered_blocks() as $block ) {
			$mapped = $this->content_block_payload( $block );
			if ( null !== $mapped ) {
				$content[] = $mapped;
			}
		}

		/*
		 * GLM1 #9: the consolidated payload carries the same always-present
		 * members a non-streaming Messages body does (model from
		 * message_start, stop_sequence from message_delta), so the two
		 * transports of one generation expose identical result fields.
		 */
		return array(
			'id'            => \is_string( $this->message_id ) ? $this->message_id : '',
			'type'          => 'message',
			'role'          => 'assistant',
			'model'         => \is_string( $this->model ) ? $this->model : null,
			'content'       => $content,
			'stop_reason'   => $this->stop_reason,
			'stop_sequence' => \is_string( $this->stop_sequence ) ? $this->stop_sequence : null,
			'usage'         => array(
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

					/*
					 * Code review GLM1 #3: the consolidated input stays the
					 * RAW object. The previous associative decode →
					 * re-encode round trip destroyed object-ness for
					 * sequential-numeric keys ({"0":"x"} became ["x"]) and
					 * the model's own object-ness oracle then rejected the
					 * response — the raw decode survives regardless of key
					 * shape (and keeps {} an object, Codex R3 #1).
					 *
					 * GLM4 #2: the accumulated JSON must also decode to a
					 * value that can REPLAY (1e999 → INF, a beyond-int
					 * integer → lossy float); accepting it would hand the
					 * model a FunctionCall whose arguments detonate at the
					 * transport on the turn's very first replay — the same
					 * reason non-object decodes fail above.
					 */
					if ( ! ToolArgsReplayGuard::is_replayable( $decoded ) ) {
						$this->malformed_tool_input = true;

						return null;
					}

					$input = $decoded;
				}

				return array(
					'type'  => 'tool_use',
					'id'    => $block['id'],
					'name'  => $block['name'],
					'input' => $input,
				);
		}

		/*
		 * Unknown block types (server tool use, search results, future
		 * additions) are dropped rather than mis-mapped — the streamed half
		 * of the KNOWN LIMITATION documented at the model's
		 * parse_content_block() drop site (code-review #15).
		 */
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
				/*
				 * GLM5 #8: the field parser stripped only spaces
				 * (ltrim(..., ' ')), so a spec-legal EMPTY 'event:' value
				 * (the payload's type member governs) and a
				 * tab-separated 'event:\t<name>' produced a name that
				 * differed from the payload's type member — tripping the
				 * Codex R7 #6 agreement rule and invalidating an
				 * otherwise valid whole stream as a malformed event
				 * frame. Field-value whitespace (spaces AND tabs, both
				 * ends) is trimmed now, and an EMPTY name counts as
				 * ABSENT (null), exactly like a frame without the field.
				 */
				$name       = trim( substr( $line, 6 ), " \t" );
				$event_name = '' === $name ? null : $name;
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
		 * GLM5 #18: ONE pipeline judges every frame — trailing frames
		 * after the terminal message_stop (Codex R8 #2/GLM4 #6) ride the
		 * SAME decode/agreement/object-shape rules the main path applies,
		 * then dispatch_event() applies the one post-termination policy
		 * (handle_trailing_event()). The trailing branch used to
		 * re-implement these rules privately and had already diverged
		 * once (GLM4 #6).
		 */
		$payload = implode( "\n", $data_lines );

		// An OpenAI-style [DONE] sentinel a gateway may append after the
		// Anthropic stream has already ended is exactly that: noise.
		if ( $this->terminated && '[DONE]' === trim( $payload ) ) {
			return;
		}

		$decoded = json_decode( $payload, true );

		if ( ! \is_array( $decoded ) ) {
			++$this->malformed;

			/*
			 * Codex R4 #3: a frame DECLARING a known event name with an
			 * undecodable payload is a corrupt stream event, not noise —
			 * silently dropping it (a content delta, say) would return a
			 * successful completion with that chunk of the answer missing.
			 * Invalidate the stream; unknown event names and ping frames
			 * stay ignorable (for trailing frames too: an event-less or
			 * unknown-named one is noise, not corruption of the completed
			 * generation).
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
		 * existing behavior. Trailing frames agree by the same rule
		 * (GLM4 #6: the old trailing copy accepted a trailing
		 * 'event: error' regardless of a contradicting payload type).
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
		 *
		 * Verifier round on GLM5 #18: PRE-termination only. A trailing
		 * frame carries no content into the completed generation, so the
		 * GLM4 #6 name-based policy judges it — including an error
		 * declaration with a malformed payload, which must still set the
		 * error flag (the old dedicated branch did; the shared check
		 * intercepted it first and surfaced the wrong fixed message).
		 */
		if ( ! $this->terminated && \in_array( $type, self::DECLARED_EVENTS, true ) && ! \is_object( $raw ) ) {
			++$this->malformed;
			$this->malformed_event = true;

			return;
		}

		// Trailing frames do not count as consumed events: they can never
		// contribute content to the completed generation.
		if ( ! $this->terminated ) {
			++$this->events;
		}

		$this->dispatch_event( $type, $decoded, $raw );
	}

	/**
	 * Dispatches one decoded event by type.
	 *
	 * GLM5 #18: after the terminal message_stop, the (already fully
	 * judged — see consume_frame()) frame lands here for the one
	 * post-termination policy instead of the content dispatch.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $type Event type (event: field or data.type).
	 * @param array<string, mixed> $data The decoded event payload.
	 * @param mixed                $raw  Non-associative decode of the same payload.
	 * @return void
	 */
	private function dispatch_event( string $type, array $data, $raw ): void {
		if ( $this->terminated ) {
			$this->handle_trailing_event( $type );

			return;
		}

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
				// GLM1 #9: envelope parity with the non-streaming body.
				if ( isset( $message['model'] ) && \is_string( $message['model'] ) ) {
					$this->model = $message['model'];
				}
				if ( \array_key_exists( 'usage', $message ) ) {
					/*
					 * Codex R15 #1: streamed usage is validated BEFORE the casts
					 * store it — the casts previously normalized "5", 3.7, true,
					 * and negatives into plausible counts, and a list-shaped
					 * usage silently became zero (the named member is absent),
					 * all before parse_message_body()'s strict validator could
					 * see the original types.
					 *
					 * GLM4 #11: the shared UsageValidator is the one
					 * source (this copy and the non-streaming parser's inline
					 * block had to be fixed in lockstep once already); its
					 * overflow-checked input total (GLM4 #5) also keeps a
					 * past-PHP_INT_MAX prompt side from silently promoting to
					 * float in the consolidated payload.
					 */
					$raw_message_usage = \is_object( $raw->message ) && \property_exists( $raw->message, 'usage' ) ? $raw->message->usage : null;
					if ( null !== UsageValidator::failure_reason( $message['usage'], $raw_message_usage ) ) {
						$this->malformed_event = true;

						return;
					}

					$input_total = UsageValidator::input_total( $message['usage'] );
					if ( null === $input_total ) {
						$this->malformed_event = true;

						return;
					}

					$this->input_tokens = $input_total;
				}
				return;

			case 'content_block_start':
				/*
				 * Codex R16 #2: content events REQUIRE message_start at
				 * dispatch time — the aggregated() guard only fires when the
				 * flag is still false at the END, so a late-but-valid
				 * message_start legitimized content that arrived before it.
				 * The malformed flag is sticky; the late start cannot launder
				 * the early content.
				 */
				if ( ! $this->message_started ) {
					$this->malformed_event = true;

					return;
				}

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
				// Codex R16 #2: as above — content before message_start.
				if ( ! $this->message_started ) {
					$this->malformed_event = true;

					return;
				}

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
				// Codex R16 #2: as above — content before message_start.
				if ( ! $this->message_started ) {
					$this->malformed_event = true;

					return;
				}

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
				 * Codex R17 #1: final metadata requires the envelope, exactly
				 * like content-block events (R16 #2) — a message_delta that
				 * arrived before message_start was laundered by a later valid
				 * start into a successful empty completion carrying the late
				 * metadata. The flag is sticky; the late start cannot repair
				 * it.
				 */
				if ( ! $this->message_started ) {
					$this->malformed_event = true;

					return;
				}

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

				/*
				 * Codex R15 #2: the final metadata frame requires a CLOSED
				 * block lifecycle — a stream that loses a content_block_stop
				 * completed successfully with a truncated block lifecycle.
				 * Every started (or seeded) block index must be stopped
				 * before the delta is accepted; a stream with zero blocks
				 * keeps its existing behavior.
				 */
				foreach ( $this->blocks as $open_index => $_open_block ) {
					if ( ! isset( $this->stopped_indexes[ $open_index ] ) ) {
						$this->malformed_event = true;

						return;
					}
				}

				$this->message_delta_received = true;

				if ( isset( $data['delta'] ) && \is_array( $data['delta'] ) && isset( $data['delta']['stop_reason'] ) && \is_string( $data['delta']['stop_reason'] ) ) {
					$this->stop_reason = $data['delta']['stop_reason'];
				}
				// GLM1 #9: envelope parity with the non-streaming body.
				if ( isset( $data['delta']['stop_sequence'] ) && \is_string( $data['delta']['stop_sequence'] ) ) {
					$this->stop_sequence = $data['delta']['stop_sequence'];
				}
				if ( \array_key_exists( 'usage', $data ) ) {
					// Codex R15 #1: same validation as message_start's input
					// side — GLM4 #11: the one shared validator.
					$raw_usage = \is_object( $raw ) && \property_exists( $raw, 'usage' ) ? $raw->usage : null;
					if ( null !== UsageValidator::failure_reason( $data['usage'], $raw_usage ) ) {
						$this->malformed_event = true;

						return;
					}

					$this->output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );
				}
				return;

			case 'message_stop':
				/*
				 * Codex R17 #1 (verifier-probe extension): the TERMINAL event
				 * equally requires the envelope — a message_stop before
				 * message_start, followed by a late start and a message_delta,
				 * laundered into a successful empty completion because done
				 * latched while the envelope was still missing.
				 */
				if ( ! $this->message_started ) {
					$this->malformed_event = true;

					return;
				}

				/*
				 * Codex R8 #2: message_stop is TERMINAL. Frames after it
				 * were still dispatched and could modify the returned
				 * text/tool args/stop reason/usage while the response
				 * succeeded. Consumption ends here; consume_frame() rejects
				 * anything but keepalive traffic that follows.
				 */
				$this->done = true;

				/*
				 * GLM5 #18: a SECOND message_stop can no longer reach
				 * this case — dispatch_event() routes post-termination
				 * frames to handle_trailing_event(), whose
				 * declared-content-bearing rule invalidates them (the
				 * old in-case duplicate check is subsumed).
				 */
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
	 * Applies the post-termination policy to one fully-judged trailing
	 * frame (Codex R8 #2, GLM4 #6; single-sourced here by GLM5 #18).
	 *
	 * The frame already passed the SAME decode/agreement/object-shape
	 * pipeline every pre-termination frame passes (consume_frame()); all
	 * that remains is what its type may do to a COMPLETED generation:
	 *
	 * - an error event (either declaration) sets the error flag — the
	 *   model surfaces its fixed typed error message;
	 * - a frame DECLARING any other known content-bearing event
	 *   (message_start, content_block_*, message_delta, a second
	 *   message_stop) would mutate a completed generation and stays
	 *   corrupt;
	 * - everything else — pings and UNKNOWN event names or payload types
	 *   (a future benign telemetry/heartbeat frame an intermediary may
	 *   append) — is trailing noise; the completed generation stands.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type Event type (event: field or data.type).
	 * @return void
	 */
	private function handle_trailing_event( string $type ): void {
		if ( 'error' === $type ) {
			// The payload is deliberately not retained; the model surfaces
			// the fixed, redacted error message.
			$this->error = true;

			return;
		}

		if ( \in_array( $type, self::DECLARED_EVENTS, true ) ) {
			// A declared content-bearing event after the terminal
			// message_stop would mutate a completed generation.
			++$this->malformed;
			$this->malformed_event = true;

			return;
		}

		// Pings and unknown (future/intermediary) event names: benign
		// trailing noise — the completed generation stands.
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
				/*
				 * GLM1 #3 (same round trip as the accumulated-JSON path):
				 * the initial input stays the RAW object — the previous
				 * associative re-decode turned nested empty objects into []
				 * and numeric-keyed objects into lists in the consolidated
				 * payload.
				 *
				 * GLM4 #2: the initial input must equally decode to a
				 * REPLAYABLE value (INF/precision-loss floats are the
				 * conversation-poison class; see the accumulated-JSON
				 * path's guard).
				 */
				if ( ! ToolArgsReplayGuard::is_replayable( $raw_input ) ) {
					$this->malformed_tool_input = true;
				} else {
					$input = $raw_input;
				}
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

		/*
		 * Codex R17 #2: started indexes must form the contiguous
		 * zero-based sequence {0..N-1} — a truncated stream that lost
		 * block 0 but delivered a complete block at index 1 passed the
		 * non-negative-integer check, and ordered_blocks() repacks by
		 * arrival order, so the gap was invisible downstream and the
		 * surviving block became content position 0 of a successful but
		 * truncated completion. Duplicates are already rejected above,
		 * so the map size IS the next expected index; any smaller
		 * (reordering) or larger (gap) value fails here. Synthesized
		 * seeds (unknown-delta compatibility path) enter through this
		 * same method and obey the same rule — a seed occupies an index
		 * the way a started block does.
		 */
		if ( \count( $this->blocks ) !== $index ) {
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
