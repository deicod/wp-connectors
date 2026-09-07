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
 * `event:`/`id:`/`retry:` fields (an `event: error` DECLARATION
 * excepted — glm29-2 flags it through has_error()), malformed JSON
 * events (flagged via
 * has_malformed_event() and skipped — never fatal in the aggregator
 * itself; glm19-11 removed the malformed-frame counter, and glm23-6
 * extended the flag to the UNDECODABLE data frame itself, the channel's
 * original claim, in both the pre- and post-sentinel phases), error
 * events (an event object carrying a PRESENT error member, or the error
 * declaration — has_error(), glm29-2: the Anthropic twin's channel at
 * this wire's own spelling), and — via
 * the shared SseFrameBuffer — split
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
	 * Whether an error event was received (glm29-2 — the Anthropic
	 * twin's has_error() channel, in the OpenAI wire's own spelling).
	 *
	 * The twin's error signal is an `event: error` DECLARATION; this
	 * wire has no declared-event semantics (GLM7 #18), so the signal
	 * rides the payload instead: a decodable event object carrying a
	 * PRESENT error member (absent or null keeps the member's absent
	 * semantics — the glm28-1 one-check idiom), pre- and post-sentinel
	 * identically. An `event: error` declaration sets the same flag
	 * (wire-robustness parity with the twin; bare event: lines are
	 * absent from the live capture, GLM1 #14) — the declaration itself
	 * is the error signal and the payload's condition cannot
	 * un-declare it.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private $error = false;

	/*
	 * glm19-11: the observability getters (is_done(), event_count(),
	 * malformed_count()) were a public API only tests called — deleted
	 * with the $malformed dead-store counter they existed to expose.
	 * The $done field stays (the frame gates read it); tests that need
	 * to pin termination read the field through the harness reflection
	 * helper. glm26-11 consciously superseded the $event_count half of
	 * this note: its only production reader — aggregated()'s first gate
	 * disjunct — was logically subsumed by the choices-emptiness
	 * disjunct beside it (choices are written exclusively inside
	 * merge_event(), which runs only after the increment), so the
	 * field, its increment, and the disjunct are gone; the four
	 * reflection pins that counted the field were superseded by the
	 * behavioral assertions around them (their information — which
	 * frames merged — was already implied).
	 */

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
	 * Whether the stream contained an error event (glm29-2).
	 *
	 * The model rejects the response typed with the fixed error-event
	 * message before consulting any aggregated payload — a
	 * provider-declared failure must not complete as a clean
	 * generation, exactly as the Anthropic twin's has_error() channel
	 * rejects the byte-equivalent shape.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the stream contained an error event.
	 */
	public function has_error(): bool {
		return $this->error;
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
		/*
		 * glm26-11: the gate is the choices emptiness alone — the former
		 * '0 === $this->event_count ||' disjunct was subsumed (choices
		 * are written exclusively inside merge_event(), which runs only
		 * after the deleted counter's increment, so zero events always
		 * meant zero choices; an event that merges no choices kept the
		 * second disjunct authoritative either way).
		 */
		if ( array() === $this->choices ) {
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

		/*
		 * glm28-2 (round-28 finding 2, extends glm26-3): a PRESENT
		 * non-array delta member object is corruption — "delta":"x"
		 * used to silently drop the whole fragment while the stream
		 * completed clean. An absent member (or an explicit null, which
		 * isset() reads as absent) keeps its silent skip: the member's
		 * absent semantics on this wire.
		 */
		if ( ! isset( $delta['delta'] ) ) {
			return $choice;
		}

		if ( ! \is_array( $delta['delta'] ) ) {
			$this->malformed_event = true;

			return $choice;
		}

		/*
		 * glm26-3: a PRESENT non-string role member is corruption — the
		 * same verdict the Anthropic twin's non-string declaration rule
		 * (GLM9 #2) gives the shape. This was the one delta member merged
		 * with isset() alone: a gateway-mangled {"role":["assistant"]}
		 * merged the array verbatim, the vendor parent's parse coerces any
		 * non-'user' role into a model message, and the corrupt chunk
		 * completed as a clean generation. Flagged now, and the member is
		 * not merged. An explicit null keeps the historical skip: isset()
		 * reads it as absent, matching the member's absent semantics on
		 * this wire.
		 *
		 * glm28-2 extends the same present-but-wrong-type rule to the
		 * siblings below (content, reasoning_content, tool_calls): a
		 * gateway-mangled {"content":123} used to skip the fragment
		 * silently — part of the answer vanished from a stream that
		 * still reported success, the exact silent-loss class glm23-6
		 * and glm26-3 were landed to close, and the shape the Anthropic
		 * twin flags through has_string_content_member(). The ''-string
		 * content fragment keeps its silent skip (an empty fragment
		 * merges nothing by construction).
		 */
		if ( isset( $delta['delta']['role'] ) ) {
			if ( ! \is_string( $delta['delta']['role'] ) ) {
				$this->malformed_event = true;
			} elseif ( ! isset( $choice['message']['role'] ) ) {
				$choice['message']['role'] = $delta['delta']['role'];
			}
		}

		foreach ( array( 'content', 'reasoning_content' ) as $text_field ) {
			if ( ! isset( $delta['delta'][ $text_field ] ) ) {
				continue;
			}

			if ( ! \is_string( $delta['delta'][ $text_field ] ) ) {
				$this->malformed_event = true;

				continue;
			}

			if ( '' !== $delta['delta'][ $text_field ] ) {
				$choice['message'][ $text_field ] = ( $choice['message'][ $text_field ] ?? '' ) . $delta['delta'][ $text_field ];
			}
		}

		if ( isset( $delta['delta']['tool_calls'] ) ) {
			if ( ! \is_array( $delta['delta']['tool_calls'] ) ) {
				$this->malformed_event = true;
			} else {
				$choice['message']['tool_calls'] = $this->merge_tool_calls( $choice['message']['tool_calls'] ?? array(), $delta['delta']['tool_calls'] );
			}
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
	 * glm21-11: the VALUE half of the rule rides the one shared
	 * StreamIndex predicate with the Anthropic twin's
	 * raw_block_index() — the container fetch (an array entry's
	 * isset()) stays here, the index rule itself cannot drift per
	 * protocol anymore.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $entry One decoded choices[] / tool_calls[] element.
	 * @return int|null The non-negative index, or null when unsound.
	 */
	private static function sound_index( $entry ): ?int {
		if ( ! \is_array( $entry ) || ! isset( $entry['index'] ) ) {
			return null;
		}

		return StreamIndex::sound( $entry['index'] );
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

			/*
			 * glm28-2: the fragment's own members join the present-but-
			 * wrong-type rule (glm26-3). The harmful shape the round-28
			 * verifier reproduced: "arguments":123 as a stream's ONLY
			 * arguments fragment silently fabricated a successful
			 * NO-ARGUMENT call whose inputs the model never produced —
			 * the exact fabrication the Anthropic twin's
			 * has_malformed_tool_input() exists to stop. Corrupt id/name
			 * members used to leave the accumulator's null for the
			 * model's identity rejection — a coincidental downstream
			 * catch; the corruption belongs in the malformed-event
			 * channel. Absent/null keeps its skip (isset semantics),
			 * and glm6-15's legitimate empty-string arguments fragment
			 * is a STRING — it still merges.
			 */
			if ( isset( $tool_delta['id'] ) ) {
				if ( \is_string( $tool_delta['id'] ) ) {
					$accumulated[ $index ]['id'] = $tool_delta['id'];
				} else {
					$this->malformed_event = true;
				}
			}
			if ( isset( $tool_delta['type'] ) ) {
				if ( \is_string( $tool_delta['type'] ) ) {
					$accumulated[ $index ]['type'] = $tool_delta['type'];
				} else {
					$this->malformed_event = true;
				}
			}

			$function = isset( $tool_delta['function'] ) && \is_array( $tool_delta['function'] )
				? $tool_delta['function']
				: null;

			if ( isset( $tool_delta['function'] ) && ! \is_array( $tool_delta['function'] ) ) {
				$this->malformed_event = true;
			}

			if ( null !== $function ) {
				if ( isset( $function['name'] ) ) {
					if ( \is_string( $function['name'] ) ) {
						$accumulated[ $index ]['function']['name'] = $function['name'];
					} else {
						$this->malformed_event = true;
					}
				}
				if ( isset( $function['arguments'] ) ) {
					if ( \is_string( $function['arguments'] ) ) {
						$accumulated[ $index ]['function']['arguments'] .= $function['arguments'];
					} else {
						$this->malformed_event = true;
					}
				}
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
		 * shared SseFieldParser. glm29-2: the event name stopped being
		 * ignored — but only as an ERROR declaration ('event: error'),
		 * the one declared-event semantics this wire needs (the twin's
		 * GLM7 #4 discipline: the declaration itself is the error
		 * signal, and the payload's condition cannot un-declare it — a
		 * truncated or undecodable error event still counts); every
		 * other name keeps the historical ignore (chat.completion.chunk
		 * frames carry no declared-event semantics).
		 */
		$fields = SseFieldParser::parse( $frame );
		$data   = $fields['data'];

		if ( 'error' === $fields['event'] ) {
			$this->error = true;

			return;
		}

		if ( null === $data ) {
			return;
		}

		if ( '[DONE]' === trim( $data ) ) {
			$this->done = true;

			return;
		}

		$decoded = json_decode( $data, true );

		if ( ! \is_array( $decoded ) ) {
			/*
			 * glm23-6 (review round 23, finding 6): a data: line that
			 * is not valid JSON is a cut or corrupt frame, not skippable
			 * noise. GLM7 #2's post-sentinel policy always counted
			 * malformed frames ("still parsed, malformed ones still
			 * counted"); glm19-11 deleted the counter but the class
			 * docblock's flag claim kept describing it, and nothing
			 * backed it — the flag covered index corruption only, so a
			 * gateway-mangled frame's text silently vanished from a
			 * stream that still reported success while the Anthropic
			 * twin rejects the identical corruption typed. The
			 * UNDECODABLE shape flags in BOTH phases now;
			 * json_last_error() distinguishes it from decodable
			 * non-array payloads (a scalar `data: null` is not malformed
			 * JSON and keeps its skip — the same non-event tolerance
			 * merge_event() applies to an object without choices).
			 */
			if ( \JSON_ERROR_NONE !== \json_last_error() ) {
				$this->malformed_event = true;
			}

			return;
		}

		if ( $this->done ) {
			$this->consume_trailing_frame( $decoded, $data );

			return;
		}

		/*
		 * GLM10 #13: the decoded event merges into the accumulators
		 * immediately — nothing retains it (glm26-11 deleted the counter
		 * that used to stand here; aggregated()'s gate reads the choices
		 * emptiness the merging itself produces).
		 */
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
		 * glm29-2: a PRESENT error member is an error EVENT — the
		 * provider-declared mid-stream failure shape of this wire
		 * (data: {"error":{...}} after content, the OpenAI convention).
		 * It used to fall through the object-without-choices tolerance
		 * and vanish, completing the generation clean while the twin
		 * rejects the byte-equivalent frame typed. An absent member (or
		 * an explicit null, which isset() reads as absent) keeps the
		 * tolerance: the member's absent semantics on this wire. The
		 * frame contributes no content — an error event has no
		 * mergeable choices semantics.
		 */
		if ( isset( $event['error'] ) ) {
			$this->error = true;

			return;
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

		/*
		 * glm28-2: a PRESENT non-array choices member is corruption
		 * ("choices":5) — the frame used to vanish silently, taking its
		 * text or finish_reason with it while the stream completed
		 * clean. An absent member (or an explicit null — the usage-frame
		 * shape) keeps the historical non-event skip.
		 */
		if ( ! isset( $event['choices'] ) ) {
			return;
		}

		if ( ! \is_array( $event['choices'] ) ) {
			$this->malformed_event = true;

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
		/*
		 * glm29-2: the pre-sentinel rule verbatim — a present error
		 * member flags identically before and after the sentinel (the
		 * glm15-14 one-predicate rationale: the same payload shape must
		 * not earn different corruption verdicts per phase). An
		 * appending gateway's trailing error frame is the failure the
		 * provider declared, not post-terminal metadata.
		 */
		if ( isset( $decoded['error'] ) ) {
			$this->error = true;

			return;
		}

		if ( isset( $decoded['usage'] ) && \is_array( $decoded['usage'] ) ) {
			$this->trailing_usage            = $decoded['usage'];
			$this->trailing_raw_usage_source = $data;
		}

		/*
		 * glm28-2: the pre-sentinel rule verbatim — a present non-array
		 * choices member flags identically before and after the sentinel
		 * (the glm15-14 one-predicate rationale: the same payload shape
		 * must not earn different corruption verdicts per phase).
		 */
		if ( ! isset( $decoded['choices'] ) ) {
			return;
		}

		if ( ! \is_array( $decoded['choices'] ) ) {
			$this->malformed_event = true;

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
