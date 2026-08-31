<?php
/**
 * Server-sent-events aggregator for OpenAI-style streaming responses.
 *
 * Pure value collector (no WordPress, no I/O): chat.completion.chunk events
 * are fed in as received and merged into ONE consolidated chat.completion
 * payload that the non-streaming response parser can consume.
 *
 * Handles the OpenAI/z.ai streaming conventions: `data:` lines (multi-line
 * data joined), `[DONE]` sentinel, comment lines (`:`), ignorable `event:`/
 * `id:`/`retry:` fields, split frames (chunks may end mid-frame), CR/LF/CRLF
 * line terminators mixed freely, and malformed JSON events (counted and
 * skipped, never fatal).
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
	 * Buffered bytes not yet forming a complete frame: line endings
	 * normalized to LF, except possibly one trailing CR that may still
	 * extend to CRLF when more data arrives.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $buffer = '';

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
	 * Number of data events that failed JSON decoding.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $malformed = 0;

	/**
	 * Feeds a raw chunk of the event stream.
	 *
	 * SSE allows CR, LF, or CRLF line terminators, mixed freely, so the
	 * buffer is normalized to LF before frame splitting; a trailing CR is
	 * held back because it may still extend to CRLF when the next chunk
	 * arrives. Frames are separated by a blank line (two consecutive LF
	 * after normalization).
	 *
	 * @since 0.1.0
	 *
	 * @param string $chunk Raw bytes as received from the transport.
	 * @return void
	 */
	public function feed( string $chunk ): void {
		$this->buffer .= $chunk;

		$held_back_cr = '';
		if ( "\r" === substr( $this->buffer, -1 ) ) {
			$held_back_cr = "\r";
			$this->buffer = substr( $this->buffer, 0, -1 );
		}

		$this->buffer = str_replace( array( "\r\n", "\r" ), "\n", $this->buffer ) . $held_back_cr;

		$pos = strpos( $this->buffer, "\n\n" );
		while ( false !== $pos ) {
			$frame        = substr( $this->buffer, 0, $pos );
			$this->buffer = substr( $this->buffer, $pos + 2 );

			$this->consume_frame( $frame );

			$pos = strpos( $this->buffer, "\n\n" );
		}
	}

	/**
	 * Marks the stream complete, flushing any final unterminated frame.
	 *
	 * A response may end directly after the last `data:` line with no blank
	 * line following it; since feed() receives the complete body before
	 * finish(), whatever remains buffered is a real final frame, not a split
	 * chunk. A trailing CR is now definitively a line terminator (no further
	 * data can extend it to CRLF), so the remainder is fully normalized
	 * before dispatch. Discarding it would lose the final event — a
	 * single-event stream would fail as zai_invalid_response, a multi-event
	 * stream its last content.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function finish(): void {
		$remaining    = str_replace( array( "\r\n", "\r" ), "\n", $this->buffer );
		$this->buffer = '';

		if ( '' !== $remaining ) {
			$this->consume_frame( $remaining );
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
				if ( ! \is_array( $choice ) || ! isset( $choice['index'] ) ) {
					continue;
				}

				$index             = (int) $choice['index'];
				$choices[ $index ] = $this->merge_choice( $choices[ $index ] ?? null, $choice );
			}
		}

		if ( array() === $choices ) {
			return null;
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
			'index'         => (int) $delta['index'],
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
			if ( ! \is_array( $tool_delta ) || ! \array_key_exists( 'index', $tool_delta ) ) {
				continue;
			}

			$index = (int) $tool_delta['index'];

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

		$this->events[] = $decoded;
	}
}
