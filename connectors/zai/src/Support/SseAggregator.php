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
 * GLM8 #8: the frame-consumption protocol (the shared SseFrameBuffer,
 * feed()/finish(), the pull loop) rides the shared AbstractSseAggregator
 * base — this class owns only the chat.completions event semantics.
 *
 * @since 0.1.0
 */
final class SseAggregator extends AbstractSseAggregator {

	/**
	 * Well-formed pre-sentinel data events consumed (GLM10 #13).
	 *
	 * A plain counter, not the decoded-frame list this class used to
	 * retain: every frame merges into the accumulators below at FEED
	 * time (the Anthropic twin's pattern), so peak memory no longer
	 * holds every decoded frame of the stream simultaneously — decoded
	 * PHP arrays run ~2-5x the JSON text, plausibly tens of MB on long
	 * multi-thousand-chunk answers. Trailing frames never count: they
	 * carry no content into the completed generation.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $event_count = 0;

	/**
	 * The first string id any well-formed event declared, or null.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $id = null;

	/**
	 * Choice accumulators keyed by stream choice index (GLM10 #13).
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $choices = array();

	/**
	 * The last usage member a well-formed event carried (present AND an
	 * array — the merge rule), or null when none merged.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, mixed>|null
	 */
	private $usage = null;

	/**
	 * Whether the [DONE] sentinel was seen.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $done = false;

	/**
	 * The RAW DATA STRING of the last frame whose usage the merge takes
	 * (verifier round on GLM5 #3; GLM6 #3 aligned the capture with the
	 * merge rule; GLM10 #12 made the decode lazy).
	 *
	 * The associative merge cannot recover the usage member's JSON
	 * object-ness ({} vs []), so the raw value travels along for the
	 * model's shared UsageValidator — the same oracle the non-streaming
	 * path derives from its body, keeping both transports of one
	 * provider on identical usage rules. Captured under the SAME
	 * condition the merge applies (present AND an array), so the oracle
	 * always describes the frame the consolidated payload carries —
	 * never a later non-merging usage member the payload discards.
	 *
	 * GLM10 #12: gateways that emit "usage":{} on EVERY chunk made the
	 * eager capture pay one full non-associative decode PER TOKEN-DELTA
	 * frame for an oracle the last-wins merge discards on the next
	 * frame; the string is held instead and raw_usage() decodes it ONCE,
	 * where the winner is known.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $raw_usage_source = null;

	/**
	 * The memoized raw usage oracle — decoded once from
	 * $raw_usage_source by raw_usage() (GLM10 #12), null before.
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
	 * The RAW DATA STRING of the trailing frame whose usage the gap-fill
	 * takes, or null — the oracle source that travels with
	 * $trailing_usage exactly the way $raw_usage_source travels with the
	 * pre-sentinel merge (GLM6 #3's same-frame rule, GLM7 #2; GLM10 #12
	 * made the decode lazy, decoded once at gap-fill time).
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $trailing_raw_usage_source = null;

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
	 * GLM10 #13: a counter, not a count of retained frames — the
	 * decoded events merge into the accumulators at feed time now.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function event_count(): int {
		return $this->event_count;
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
	 * Assembles the consolidated chat.completion payload.
	 *
	 * GLM10 #13: the per-event merge happens at FEED time now
	 * (merge_event() — the Anthropic twin's immediate-accumulator
	 * pattern), so this method owns only what needs the WHOLE stream:
	 * the post-sentinel gap-fill, the one-time reindexing, and the
	 * payload assembly. Every step is idempotent, so repeated calls
	 * return the same payload.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>|null Null when no usable event was consumed.
	 */
	public function aggregated(): ?array {
		if ( 0 === $this->event_count || array() === $this->choices ) {
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
			if ( isset( $this->choices[ $index ] ) && null === $this->choices[ $index ]['finish_reason'] ) {
				$this->choices[ $index ]['finish_reason'] = $reason;
			}
		}

		/*
		 * Verifier round on GLM7 #2: "no usage merged" means no usage
		 * DATA merged. An EMPTY pre-sentinel member ("usage":{} — or[],
		 * both collapsing to the same empty array; several
		 * OpenAI-compatible gateways emit it as a null-usage
		 * normalization) passed the isset-merge above, so the strict
		 * null check let it BLOCK the gap-fill and the completed
		 * generation reported zero tokens where master's last-wins
		 * merge carried the appending gateway's real counts — the exact
		 * silent zeroing GLM7 #2 exists to fix. An empty member carries
		 * no token counts, so completing it overwrites nothing; every
		 * DATA-BEARING member (even a partial one) still stands.
		 *
		 * GLM12 #9 extends "no data" to the zero-valued shape: a gateway
		 * that zero-normalizes ("usage":{"prompt_tokens":0} on every
		 * chunk) writes non-empty but INFORMATIONALLY EMPTY members —
		 * zero is exactly what the lenient validator's absent-member
		 * default reads, so the member says nothing the missing member
		 * would not, and blocking the gap-fill on it reproduced the same
		 * silent zeroing. Corrupt members (strings, floats, INF) are NOT
		 * information: they stay standing so the downstream validator
		 * rejects them typed, never rescued by the gap-fill.
		 *
		 * GLM10 #12: the trailing oracle SOURCE replaces the pre-sentinel
		 * one and the memoized decode resets — raw_usage() decodes the
		 * (single) winner once, on demand.
		 */
		if ( self::usage_carries_no_token_data( $this->usage ) && null !== $this->trailing_usage ) {
			$this->usage            = $this->trailing_usage;
			$this->raw_usage_source = $this->trailing_raw_usage_source;
			$this->raw_usage        = null;
		}

		// Reindex the merged tool calls ONCE, here: while merging, the
		// accumulated per-choice lists stay keyed by STREAM index (the merge
		// identity), so out-of-order (1 before 0), non-zero-starting, or
		// sparse (0 and 2) indexes would otherwise leave insertion-order or
		// gapped integer keys — and json_encode would emit message.tool_calls
		// as an OBJECT, failing a valid multi-tool stream as malformed.
		foreach ( $this->choices as &$choice ) {
			if ( isset( $choice['message']['tool_calls'] ) && \is_array( $choice['message']['tool_calls'] ) ) {
				\ksort( $choice['message']['tool_calls'], SORT_NUMERIC );
				$choice['message']['tool_calls'] = \array_values( $choice['message']['tool_calls'] );
			}
		}
		unset( $choice );

		\ksort( $this->choices, SORT_NUMERIC );

		$payload = array(
			'id'      => \is_string( $this->id ) ? $this->id : '',
			'choices' => \array_values( $this->choices ),
		);

		if ( null !== $this->usage ) {
			$payload['usage'] = $this->usage;
		}

		return $payload;
	}

	/**
	 * Whether a merged pre-sentinel usage member carries any token DATA
	 * (GLM12 #9).
	 *
	 * Null, the empty array, and a member whose every value is an
	 * explicit zero or null are all informationally empty: zero is the
	 * lenient validator's absent-member default, so such a member states
	 * nothing a missing member would not, and the post-[DONE] gap-fill
	 * may complete it over nothing. Any other value — a non-zero count,
	 * or a corrupt shape (string, float, INF) — is data-bearing (or the
	 * validator's business, not the gap-fill's) and stands.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>|null $usage The merged pre-sentinel usage member.
	 * @return bool True when the member carries no token data.
	 */
	private static function usage_carries_no_token_data( ?array $usage ): bool {
		if ( null === $usage || array() === $usage ) {
			return true;
		}

		foreach ( $usage as $count ) {
			if ( null === $count || ( \is_int( $count ) && 0 === $count ) ) {
				continue;
			}

			return false;
		}

		return true;
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
	 * The index of one decoded choice/tool-call entry, or null when the
	 * entry is not a sound array carrying a non-negative INTEGER index
	 * (glm15-14).
	 *
	 * The GLM7 #1 rule — a missing, null, non-integer, or negative
	 * index is corruption, never an int-coerced accumulator key — was
	 * stated verbatim at the three merge sites (the choice loop, the
	 * tool-call loop, the trailing-frame loop); ONE predicate keeps the
	 * next index-rule change from landing on one copy only and giving
	 * pre- and post-sentinel frames different corruption verdicts for
	 * the same payload shape.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $entry One decoded choices[] / tool_calls[] element.
	 * @return int|null The non-negative index, or null when unsound.
	 */
	private static function sound_index( $entry ): ?int {
		if ( ! \is_array( $entry )
			|| ! isset( $entry['index'] )
			|| ! \is_int( $entry['index'] )
			|| $entry['index'] < 0 ) {
			return null;
		}

		return $entry['index'];
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
			 * integer or the stream fails typed (glm15-14: the index
			 * rule rides the one sound_index() predicate).
			 */
			$index = self::sound_index( $tool_delta );

			if ( null === $index ) {
				$this->malformed_event = true;

				continue;
			}

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
	 * GLM8 #8: protected — the shared base's pull loop calls it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $frame Frame contents (without the separating blank line).
	 * @return void
	 */
	protected function consume_frame( string $frame ): void {
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
		 *
		 * GLM7 #18: the field parsing (comment/empty lines, data: value
		 * joining, the ignored id:/retry:/unknown fields) rides the one
		 * shared SseFieldParser — this surface ignores the event name
		 * (chat.completion.chunk frames carry no declared-event
		 * semantics), exactly as it ignored event: lines before.
		 */
		$data = SseFieldParser::parse( $frame )['data'];

		if ( null === $data ) {
			return;
		}

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
		 * GLM10 #13: the decoded event merges into the accumulators
		 * immediately — nothing retains it — and the counter replaces
		 * the decoded-frame list (event_count()'s contract is
		 * unchanged).
		 */
		++$this->event_count;
		$this->merge_event( $decoded, $data );
	}

	/**
	 * Merges one well-formed pre-sentinel event into the accumulators
	 * (GLM10 #13 — the merge loop aggregated() used to run over the
	 * retained frame list at stream end, holding every decoded frame in
	 * memory for the stream's lifetime).
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $event The decoded event payload.
	 * @param string               $data  The raw data string of the frame.
	 * @return void
	 */
	private function merge_event( array $event, string $data ): void {
		if ( null === $this->id && isset( $event['id'] ) && \is_string( $event['id'] ) ) {
			$this->id = $event['id'];
		}

		/*
		 * Verifier round on GLM5 #3: the usage member's RAW
		 * (non-associative) shape travels for the model's validator —
		 * the associative merge collapses {} and [] to the same empty
		 * array, which the validator's sequential-key fallback must then
		 * tolerate, diverging from the non-streaming transport (it
		 * rejects the empty list through its body oracle).
		 *
		 * GLM6 #3: the capture condition is the SAME rule the merge
		 * applies (present AND an array) — previously every
		 * usage-BEARING frame replaced the oracle, so a later
		 * non-merging member ("usage":"corrupt", "usage":null) either
		 * handed the validator a frame the consolidated payload does not
		 * carry (rejecting a valid generation) or dropped the oracle
		 * entirely (flipping the verdict through the sequential-key
		 * fallback). The oracle and the merged member always describe
		 * the same frame.
		 *
		 * GLM10 #12: the RAW DATA STRING is the captured state — decoded
		 * once, lazily, by raw_usage() where the last-wins winner is
		 * already this frame. The eager per-frame decode paid a second
		 * FULL parse of every usage-bearing token-delta frame on
		 * gateways that emit "usage":{} on every chunk, for an oracle
		 * the next frame's merge discarded.
		 */
		if ( isset( $event['usage'] ) && \is_array( $event['usage'] ) ) {
			$this->usage            = $event['usage'];
			$this->raw_usage_source = $data;
		}

		if ( ! isset( $event['choices'] ) || ! \is_array( $event['choices'] ) ) {
			return;
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
			 * glm15-14: the rule rides the one sound_index() predicate.
			 */
			$index = self::sound_index( $choice );

			if ( null === $index ) {
				$this->malformed_event = true;

				continue;
			}

			$this->choices[ $index ] = $this->merge_choice( $this->choices[ $index ] ?? null, $choice );
		}
	}

	/**
	 * Consumes one POST-sentinel frame's terminal metadata (GLM7 #2).
	 *
	 * The frame already passed the same decode pipeline a pre-sentinel
	 * frame passes; all that may be taken from it is what completes the
	 * payload: the usage member (its raw data string captured alongside,
	 * GLM6 #3's same-frame rule — decoded once, lazily, GLM10 #12) and
	 * finish reasons for already-accumulated choice indexes.
	 * Content-bearing members (delta, role, tool_calls) are deliberately
	 * ignored — the completed generation's text cannot be mutated — and
	 * choices with unusable indexes raise the GLM7 #1 flag like their
	 * pre-sentinel twins.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decoded The decoded frame payload.
	 * @param string               $data    The raw data string of the frame.
	 * @return void
	 */
	private function consume_trailing_frame( array $decoded, string $data ): void {
		if ( isset( $decoded['usage'] ) && \is_array( $decoded['usage'] ) ) {
			$this->trailing_usage            = $decoded['usage'];
			$this->trailing_raw_usage_source = $data;
		}

		if ( ! isset( $decoded['choices'] ) || ! \is_array( $decoded['choices'] ) ) {
			return;
		}

		foreach ( $decoded['choices'] as $choice ) {
			// GLM7 #1 parity: an unusable index in a trailing frame is the
			// same corruption class as a pre-sentinel one (glm15-14: the
			// rule rides the one sound_index() predicate).
			$index = self::sound_index( $choice );

			if ( null === $index ) {
				$this->malformed_event = true;

				continue;
			}

			if ( \array_key_exists( 'finish_reason', $choice ) && null !== $choice['finish_reason'] ) {
				$this->trailing_finish_reasons[ $index ] = $choice['finish_reason'];
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
	 * GLM10 #12: the decode is LAZY and memoized — the winner is known
	 * by the time this runs (after aggregated()), so ONE non-associative
	 * decode of the captured data string answers every caller, instead
	 * of one full decode per usage-bearing frame during the stream.
	 *
	 * @since 0.2.0
	 *
	 * @return mixed The raw usage value (object-ness oracle for the validator).
	 */
	public function raw_usage() {
		if ( null === $this->raw_usage && null !== $this->raw_usage_source ) {
			$raw_event       = json_decode( $this->raw_usage_source );
			$this->raw_usage = \is_object( $raw_event ) ? ( $raw_event->usage ?? null ) : null;
		}

		return $this->raw_usage;
	}
}
