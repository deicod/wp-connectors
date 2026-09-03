<?php
/**
 * Server-sent-events aggregator for OpenAI-style streaming responses.
 *
 * Pure value collector (no WordPress, no I/O): chat.completion.chunk events
 * are fed in as received and merged into ONE consolidated chat.completion
 * payload that the non-streaming response parser can consume.
 *
 * Handles the OpenAI/z.ai streaming conventions: `data:` lines (multi-line
 * data joined), `[DONE]` sentinel (TERMINAL — the content stream ends
 * there: no delta, role, or tool-call fragment merges after it and no new
 * choice turn opens, but a frame an appending gateway emits after the
 * sentinel still COMPLETES the payload with terminal metadata it lacks —
 * a finish_reason for an accumulated choice missing one, a usage member
 * when none merged; never an overwrite of data already carried, GLM5 #7
 * narrowed by GLM7 #2), comment lines (`:`), ignorable
 * `event:`/`id:`/`retry:` fields, malformed JSON events (counted and
 * skipped, never fatal), and — via the shared SseFrameBuffer — split
 * frames (chunks may end mid-frame), CR/LF/CRLF line terminators mixed
 * freely, and a final unterminated frame.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * SSE aggregator producing a consolidated chat-completion payload.
 *
 * @since 0.1.0
 */
final class SseAggregator {

	/**
	 * The protocol-neutral frame splitter (shared with the Anthropic
	 * aggregator): buffering, line-ending normalization, and frame
	 * separation live there; event semantics here.
	 *
	 * @since 0.2.0
	 *
	 * @var SseFrameBuffer
	 */
	private $frame_buffer;

	/**
	 * Decoded data events (chat.completion.chunk shapes), in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array<string, mixed>>
	 */
	private $events = array();

	/**
	 * Whether the [DONE] sentinel was seen.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $done = false;

	/**
	 * The usage member from a NON-associative decode of the last frame
	 * whose usage the merge actually takes, or null when none was seen
	 * (verifier round on GLM5 #3; GLM6 #3 aligned the capture with the
	 * merge rule).
	 *
	 * The associative merge cannot recover the usage member's JSON
	 * object-ness ({} vs []), so the raw value travels along for the
	 * model's shared UsageValidator — the same oracle the non-streaming
	 * path derives from its body, keeping both transports of one
	 * provider on identical usage rules. Captured under the SAME
	 * condition aggregated() merges by (present AND an array), so the
	 * oracle always describes the frame the consolidated payload
	 * carries — never a later non-merging usage member the payload
	 * discards.
	 *
	 * @since 0.2.0
	 *
	 * @var mixed
	 */
	private $raw_usage = null;

	/**
	 * Number of data events that failed JSON decoding.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $malformed = 0;

	/**
	 * The usage member of a POST-sentinel frame, or null when no trailing
	 * frame carried one (GLM7 #2).
	 *
	 * Appending gateways emit the final usage-bearing chunk AFTER the
	 * [DONE] sentinel; master merged it, GLM5 #7 dropped it, and the
	 * completed generation then reported zero token usage. The trailing
	 * member gap-fills the payload only when NO pre-sentinel usage
	 * merged — it never replaces one (the mutation guard).
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, mixed>|null
	 */
	private $trailing_usage = null;

	/**
	 * The raw (non-associative) usage value of the trailing frame whose
	 * usage the gap-fill takes, or null — the oracle that travels with
	 * $trailing_usage exactly the way $raw_usage travels with the
	 * pre-sentinel merge (GLM6 #3's same-frame rule, GLM7 #2).
	 *
	 * @since 0.2.0
	 *
	 * @var mixed
	 */
	private $trailing_raw_usage = null;

	/**
	 * Finish reasons declared by POST-sentinel frames, keyed by choice
	 * index, last declaration per index wins (GLM7 #2).
	 *
	 * Gap-fill only: aggregated() applies one to an accumulated choice
	 * that still carries a null finish_reason — a trailing frame never
	 * REPLACES a finish reason the payload already has, and an index
	 * with no accumulated choice opens no new turn.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, mixed>
	 */
	private $trailing_finish_reasons = array();

	/**
	 * Whether a chunk choice or tool-call delta carried an index this
	 * merge could not identify soundly (GLM7 #1).
	 *
	 * The legacy merge used to SILENTLY SKIP choices whose 'index' member
	 * was missing or null and int-COERCE malformed ones ((int) "1.9" is 1,
	 * (int) null is 0) — a chunk of the answer (or a tool-call fragment)
	 * vanished from a stream that still reported success, and a float or
	 * null index merged its delta into the WRONG accumulator. The Anthropic
	 * twin added in this branch rejects the identical corruption through
	 * raw_block_index(); this flag is the legacy surface's parity channel:
	 * aggregated() raises it, the model turns it into the typed
	 * zai_invalid_response stream rejection.
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
	 * Frames are separated by a blank line; the shared SseFrameBuffer
	 * normalizes mixed CR/LF/CRLF terminators and split chunks.
	 *
	 * @since 0.1.0
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
	 * A response may end directly after the last `data:` line with no blank
	 * line following it; since feed() receives the complete body before
	 * finish(), whatever remains buffered is a real final frame, not a split
	 * chunk. Discarding it would lose the final event — a single-event
	 * stream would fail as zai_invalid_response, a multi-event stream its
	 * last content.
	 *
	 * @since 0.1.0
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
	 * Whether the [DONE] sentinel was received.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return $this->done;
	}

	/**
	 * Number of well-formed data events consumed.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function event_count(): int {
		return \count( $this->events );
	}

	/**
	 * Number of malformed data events (bad JSON) skipped.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function malformed_count(): int {
		return $this->malformed;
	}

	/**
	 * Whether a chunk choice or tool-call delta carried an unusable index.
	 *
	 * True means the stream is corrupt: at least one decoded chunk
	 * declared a choices entry (or a tool_calls delta) whose 'index'
	 * member was absent, null, or not a non-negative integer, so the
	 * merged payload would be missing that delta's content or carry it
	 * merged into the wrong accumulator. The model must treat the whole
	 * response as a parse error (GLM7 #1 — parity with the Anthropic
	 * twin's raw_block_index() rejection).
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when a declared index was malformed.
	 */
	public function has_malformed_event(): bool {
		return $this->malformed_event;
	}

	/**
	 * Aggregates the consumed chunks into one chat.completion payload.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>|null Null when no usable event was consumed.
	 */
	public function aggregated(): ?array {
		if ( array() === $this->events ) {
			return null;
		}

		$id      = null;
		$choices = array();
		$usage   = null;

		foreach ( $this->events as $event ) {
			if ( null === $id && isset( $event['id'] ) && \is_string( $event['id'] ) ) {
				$id = $event['id'];
			}

			if ( isset( $event['usage'] ) && \is_array( $event['usage'] ) ) {
				$usage = $event['usage'];
			}

			if ( ! isset( $event['choices'] ) || ! \is_array( $event['choices'] ) ) {
				continue;
			}

			foreach ( $event['choices'] as $choice ) {
				/*
				 * GLM7 #1: an unusable choice index is corruption, not a
				 * skippable entry — the silent skip lost that delta's
				 * content from a stream that still reported success, and
				 * the (int) cast below merged float/string indexes into
				 * the WRONG accumulator. The flag fails the response
				 * typed; the entry itself stays unmerged (its content
				 * cannot be attributed soundly). Parity with the
				 * Anthropic twin's raw_block_index() rule: the index must
				 * be a non-negative INTEGER (the associative decode
				 * preserves JSON int-ness, so is_int() rejects "1", 1.9,
				 * true, and null exactly like the twin's raw oracle).
				 */
				if ( ! \is_array( $choice )
					|| ! isset( $choice['index'] )
					|| ! \is_int( $choice['index'] )
					|| $choice['index'] < 0 ) {
					$this->malformed_event = true;

					continue;
				}

				$index             = $choice['index'];
				$choices[ $index ] = $this->merge_choice( $choices[ $index ] ?? null, $choice );
			}
		}

		if ( array() === $choices ) {
			return null;
		}

		/*
		 * GLM7 #2: post-sentinel terminal data COMPLETES the payload —
		 * gap-fill only, never an overwrite. A finish reason an appending
		 * gateway delivered after the [DONE] sentinel lands on the
		 * accumulated choice that still lacks one (without it the SDK
		 * parse dies on the missing choices[0].finish_reason, failing a
		 * stream master completed); an already-present finish reason or
		 * usage member stands, keeping GLM5 #7's completed-generation
		 * mutation guard for exactly the overwrite shapes it existed to
		 * stop. Indexes without an accumulated choice open no new turn.
		 */
		foreach ( $this->trailing_finish_reasons as $index => $reason ) {
			if ( isset( $choices[ $index ] ) && null === $choices[ $index ]['finish_reason'] ) {
				$choices[ $index ]['finish_reason'] = $reason;
			}
		}

		if ( null === $usage && null !== $this->trailing_usage ) {
			$usage           = $this->trailing_usage;
			$this->raw_usage = $this->trailing_raw_usage;
		}

		// Reindex the merged tool calls ONCE, here: while merging, the
		// accumulated per-choice lists stay keyed by STREAM index (the merge
		// identity), so out-of-order (1 before 0), non-zero-starting, or
		// sparse (0 and 2) indexes would otherwise leave insertion-order or
		// gapped integer keys — and json_encode would emit message.tool_calls
		// as an OBJECT, failing a valid multi-tool stream as malformed.
		foreach ( $choices as &$choice ) {
			if ( isset( $choice['message']['tool_calls'] ) && \is_array( $choice['message']['tool_calls'] ) ) {
				\ksort( $choice['message']['tool_calls'], SORT_NUMERIC );
				$choice['message']['tool_calls'] = \array_values( $choice['message']['tool_calls'] );
			}
		}
		unset( $choice );

		ksort( $choices, SORT_NUMERIC );

		$payload = array(
			'id'      => \is_string( $id ) ? $id : '',
			'choices' => array_values( $choices ),
		);

		if ( null !== $usage ) {
			$payload['usage'] = $usage;
		}

		return $payload;
	}

	/**
	 * Merges one streaming choice delta into the accumulated choice.
	 *
	 * @since 0.1.0
	 *
	 * @param array|null           $accumulated Accumulated choice so far.
	 * @param array<string, mixed> $delta    The incoming chunk choice.
	 * @return array<string, mixed> The merged choice.
	 */
	private function merge_choice( ?array $accumulated, array $delta ): array {
		$choice = \is_array( $accumulated ) ? $accumulated : array(
			'index'         => $delta['index'],
			'message'       => array(),
			'finish_reason' => null,
		);

		if ( \array_key_exists( 'finish_reason', $delta ) && null !== $delta['finish_reason'] ) {
			$choice['finish_reason'] = $delta['finish_reason'];
		}

		if ( ! isset( $delta['delta'] ) || ! \is_array( $delta['delta'] ) ) {
			return $choice;
		}

		if ( isset( $delta['delta']['role'] ) && ! isset( $choice['message']['role'] ) ) {
			$choice['message']['role'] = $delta['delta']['role'];
		}

		foreach ( array( 'content', 'reasoning_content' ) as $text_field ) {
			if ( isset( $delta['delta'][ $text_field ] ) && \is_string( $delta['delta'][ $text_field ] ) && '' !== $delta['delta'][ $text_field ] ) {
				$choice['message'][ $text_field ] = ( $choice['message'][ $text_field ] ?? '' ) . $delta['delta'][ $text_field ];
			}
		}

		if ( isset( $delta['delta']['tool_calls'] ) && \is_array( $delta['delta']['tool_calls'] ) ) {
			$choice['message']['tool_calls'] = $this->merge_tool_calls( $choice['message']['tool_calls'] ?? array(), $delta['delta']['tool_calls'] );
		}

		return $choice;
	}

	/**
	 * Merges streamed tool-call deltas by their index.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $accumulated Tool calls so far.
	 * @param list<array<string, mixed>>       $deltas      Incoming tool-call deltas.
	 * @return array<int, array<string, mixed>> Merged tool calls.
	 */
	private function merge_tool_calls( array $accumulated, array $deltas ): array {
		foreach ( $deltas as $tool_delta ) {
			/*
			 * GLM7 #1 (tool-call half): the same rejection the choice
			 * loop applies — a missing/null index was skipped silently
			 * (the fragment vanished) and a null index even passed
			 * array_key_exists() to coerce to 0, merging the fragment
			 * into tool accumulator 0 of the WRONG call. Non-negative
			 * integer or the stream fails typed.
			 */
			if ( ! \is_array( $tool_delta )
				|| ! isset( $tool_delta['index'] )
				|| ! \is_int( $tool_delta['index'] )
				|| $tool_delta['index'] < 0 ) {
				$this->malformed_event = true;

				continue;
			}

			$index = $tool_delta['index'];

			if ( ! isset( $accumulated[ $index ] ) ) {
				$accumulated[ $index ] = array(
					'type'     => 'function',
					'id'       => null,
					'function' => array(
						'name'      => null,
						'arguments' => '',
					),
				);
			}

			if ( isset( $tool_delta['id'] ) && \is_string( $tool_delta['id'] ) ) {
				$accumulated[ $index ]['id'] = $tool_delta['id'];
			}
			if ( isset( $tool_delta['type'] ) && \is_string( $tool_delta['type'] ) ) {
				$accumulated[ $index ]['type'] = $tool_delta['type'];
			}
			if ( isset( $tool_delta['function']['name'] ) && \is_string( $tool_delta['function']['name'] ) ) {
				$accumulated[ $index ]['function']['name'] = $tool_delta['function']['name'];
			}
			if ( isset( $tool_delta['function']['arguments'] ) && \is_string( $tool_delta['function']['arguments'] ) ) {
				$accumulated[ $index ]['function']['arguments'] .= $tool_delta['function']['arguments'];
			}
		}

		return $accumulated;
	}

	/**
	 * Consumes one complete SSE frame.
	 *
	 * @since 0.1.0
	 *
	 * @param string $frame Frame contents (without the separating blank line).
	 * @return void
	 */
	private function consume_frame( string $frame ): void {
		/*
		 * GLM5 #7 established `data: [DONE]` as TERMINAL; GLM7 #2 narrows
		 * what that terminates: the CONTENT stream (no delta, role, or
		 * tool-call fragment merges after the sentinel, and no new choice
		 * turn opens), not the frame pipeline. Frames an appending
		 * gateway emits after the sentinel — the repo's own records
		 * document exactly that behavior — are still parsed, malformed
		 * ones still counted, and their TERMINAL metadata (finish reason,
		 * usage) still completes the payload (see consume_trailing_frame()
		 * and aggregated()'s gap-fill). Dropping every post-sentinel
		 * frame wholesale failed streams master completed (missing
		 * finish_reason) and silently zeroed their usage.
		 */
		$data_lines = array();

		foreach ( explode( "\n", $frame ) as $line ) {
			if ( '' === $line || 0 === strpos( $line, ':' ) ) {
				// Empty line or comment (keep-alive): ignore.
				continue;
			}

			if ( 0 === strpos( $line, 'data:' ) ) {
				$data_lines[] = ltrim( substr( $line, 5 ), ' ' );
				continue;
			}

			// event:/id:/retry: and unknown fields are ignored.
		}

		if ( array() === $data_lines ) {
			return;
		}

		$data = implode( "\n", $data_lines );

		if ( '[DONE]' === trim( $data ) ) {
			$this->done = true;

			return;
		}

		$decoded = json_decode( $data, true );

		if ( ! \is_array( $decoded ) ) {
			++$this->malformed;

			return;
		}

		if ( $this->done ) {
			$this->consume_trailing_frame( $decoded, $data );

			return;
		}

		/*
		 * Verifier round on GLM5 #3: capture the usage member's RAW
		 * (non-associative) shape for the model's validator — the
		 * associative merge collapses {} and [] to the same empty array,
		 * which the validator's sequential-key fallback must then
		 * tolerate, diverging from the non-streaming transport (it
		 * rejects the empty list through its body oracle). The extra
		 * decode runs only for frames that carry a usage member (~one
		 * per stream).
		 *
		 * GLM6 #3: the capture condition is the SAME rule aggregated()
		 * merges by (present AND an array) — previously every
		 * usage-BEARING frame replaced the oracle, so a later
		 * non-merging member ("usage":"corrupt", "usage":null) either
		 * handed the validator a frame the consolidated payload does not
		 * carry (rejecting a valid generation) or dropped the oracle
		 * entirely (flipping the verdict through the sequential-key
		 * fallback). The oracle and the merged member now always
		 * describe the same frame.
		 */
		if ( isset( $decoded['usage'] ) && \is_array( $decoded['usage'] ) ) {
			$raw_event       = json_decode( $data );
			$this->raw_usage = \is_object( $raw_event ) ? ( $raw_event->usage ?? null ) : null;
		}

		$this->events[] = $decoded;
	}

	/**
	 * Consumes one POST-sentinel frame's terminal metadata (GLM7 #2).
	 *
	 * The frame already passed the same decode pipeline a pre-sentinel
	 * frame passes; all that may be taken from it is what completes the
	 * payload: the usage member (captured raw alongside, GLM6 #3's
	 * same-frame rule) and finish reasons for already-accumulated choice
	 * indexes. Content-bearing members (delta, role, tool_calls) are
	 * deliberately ignored — the completed generation's text cannot be
	 * mutated — and choices with unusable indexes raise the GLM7 #1 flag
	 * like their pre-sentinel twins.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decoded The decoded frame payload.
	 * @param string               $data    The raw data string of the frame.
	 * @return void
	 */
	private function consume_trailing_frame( array $decoded, string $data ): void {
		if ( isset( $decoded['usage'] ) && \is_array( $decoded['usage'] ) ) {
			$raw_event                = json_decode( $data );
			$this->trailing_usage     = $decoded['usage'];
			$this->trailing_raw_usage = \is_object( $raw_event ) ? ( $raw_event->usage ?? null ) : null;
		}

		if ( ! isset( $decoded['choices'] ) || ! \is_array( $decoded['choices'] ) ) {
			return;
		}

		foreach ( $decoded['choices'] as $choice ) {
			// GLM7 #1 parity: an unusable index in a trailing frame is the
			// same corruption class as a pre-sentinel one.
			if ( ! \is_array( $choice )
				|| ! isset( $choice['index'] )
				|| ! \is_int( $choice['index'] )
				|| $choice['index'] < 0 ) {
				$this->malformed_event = true;

				continue;
			}

			if ( \array_key_exists( 'finish_reason', $choice ) && null !== $choice['finish_reason'] ) {
				$this->trailing_finish_reasons[ $choice['index'] ] = $choice['finish_reason'];
			}
		}
	}

	/**
	 * The usage member from a non-associative decode of the last frame
	 * whose usage the merge takes, or null when none was seen.
	 *
	 * GLM7 #2: when a post-sentinel frame's usage gap-fills the payload,
	 * aggregated() re-points this oracle at that trailing frame — the
	 * value always describes the member the consolidated payload carries.
	 *
	 * @since 0.2.0
	 *
	 * @return mixed The raw usage value (object-ness oracle for the validator).
	 */
	public function raw_usage() {
		return $this->raw_usage;
	}
}
