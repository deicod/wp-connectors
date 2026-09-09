<?php
/**
 * The SSE aggregator mutation-invariant property harness (proph).
 *
 * Closes the defect class the per-round review loop has been chasing
 * one shape at a time (glm23-6 → glm28-2 → glm31-1 → glm31-2 →
 * glm33-1 → glm34-1 were all members): a corrupted or mutated stream
 * must never cause SILENT divergence or silent loss. Every past fix
 * pinned its own shape with a test; the neighboring shape surfaced
 * next round. This harness states the invariant over the whole
 * mutation space instead:
 *
 *   for every (well-formed corpus stream × mutation operator × site),
 *   the aggregation is exactly one of
 *
 *   CLEAN     — identical output and flags (the mutation was
 *               semantically invisible);
 *   FLAGGED   — output differs AND a corruption flag rose (detected
 *               corruption is the fix working);
 *   TOLERATED — output differs with NO flag, and the observation is
 *               an EXPLICITLY allow-listed tolerance class with a
 *               REFUTATION_LEDGER citation (the pinned absent-member
 *               semantics, ''-fragment merges, value transparency,
 *               and the wire's absent frame-integrity signal).
 *
 *   NEVER     — output differs with no flag and no allow-list entry
 *               (silent divergence/loss: the glm class), and NEVER an
 *               uncaught PHP Error/TypeError escaping the aggregator.
 *
 * The tolerance table is ENUMERATED, not discovered: a diff-no-flag
 * observation that matches no entry fails the run with the seed, the
 * operator, and the reproducing stream. Adding an entry requires a
 * ledger justification (the proph discipline: adjudicate, never
 * widen silently).
 *
 * Determinism: a fixed default seed (20260909); WP_CONNECTORS_FUZZ_SEED
 * and WP_CONNECTORS_FUZZ_CASES scale the run for soaks. The default
 * rides `composer check` through the phpunit suite (>= 2000 cases per
 * surface, well inside the check budget).
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use Deicod\WpConnectors\Zai\Support\AnthropicSseAggregator;
use Deicod\WpConnectors\Zai\Support\SseAggregator;

final class SseAggregatorMutationPropertyTest extends WpConnectorsTestCase
{
    /**
     * Case-count override for soaks (per surface).
     */
    const CASES_ENV = 'WP_CONNECTORS_FUZZ_CASES';

    /**
     * Seed override for reproducing an observed failure.
     */
    const SEED_ENV = 'WP_CONNECTORS_FUZZ_SEED';

    const DEFAULT_CASES = 2000;
    const DEFAULT_SEED = 20260909;

    public function testTheOpenAiSurfaceAggregatorMutationInvariantHolds()
    {
        $this->run_invariant('zai');
    }

    public function testTheAnthropicSurfaceAggregatorMutationInvariantHolds()
    {
        $this->run_invariant('anthropic');
    }

    /**
     * Runs the mutation invariant over one surface's corpus.
     *
     * @param string $surface 'zai' or 'anthropic'.
     * @return void
     */
    private function run_invariant($surface)
    {
        $class = 'zai' === $surface ? SseAggregator::class : AnthropicSseAggregator::class;
        $cases = max(1, (int) (getenv(self::CASES_ENV) ?: self::DEFAULT_CASES));
        $seed = (int) (getenv(self::SEED_ENV) ?: self::DEFAULT_SEED) + ('zai' === $surface ? 0 : 1);

        mt_srand($seed);

        $operator_ids = array_keys($this->operators());
        $fired = array_fill_keys($operator_ids, 0);
        $classified = array('CLEAN' => 0, 'FLAGGED' => 0, 'TOLERATED' => 0);

        for ($case = 0; $case < $cases; $case++) {
            $frames = 'zai' === $surface ? $this->openai_corpus() : $this->anthropic_corpus();

            $pristine = $this->aggregate_frames($class, $frames);
            $this->assertPristineCorpus($surface, $case, $pristine, $frames);

            // Pick an operator until one actually mutates the stream.
            $mutation = null;
            for ($attempt = 0; $attempt < 6 && null === $mutation; $attempt++) {
                $op_id = $operator_ids[mt_rand(0, count($operator_ids) - 1)];
                $mutation = $this->apply_operator($op_id, $frames);
            }
            if (null === $mutation) {
                continue;
            }

            $mutated = $this->aggregate_frames($class, $mutation['frames']);
            $fired[$mutation['meta']['op']]++;

            $verdict = $this->classify($surface, $mutation['meta'], $pristine, $mutated);

            if ('FAIL' === $verdict['class']) {
                $this->fail($this->failure_report($surface, $case, $seed, $mutation['meta'], $pristine, $mutated, $mutation['frames'], $verdict['why']));
            }

            $classified[$verdict['class']]++;
        }

        /*
         * Coverage gate: every operator must have fired at least once
         * in the default run — an operator that never fires pins
         * nothing, and a corpus change that starves one must fail
         * loudly here rather than silently shrink the mutation space.
         */
        $silent = array_keys(array_filter($fired, static function ($n) {
            return 0 === $n;
        }));
        $this->assertSame(array(), $silent, 'Operators that never fired (corpus starvation or operator regression): ' . implode(', ', $silent));

        /*
         * Non-vacuity in both interesting directions: some case must
         * have flagged (the corruption rules observed) and some case
         * must have been tolerated (the allow-list exercised).
         */
        $this->assertGreaterThan(0, $classified['FLAGGED'], 'No FLAGGED case observed — the corruption rules went unwitnessed.');
        $this->assertGreaterThan(0, $classified['TOLERATED'], 'No TOLERATED case observed — the allow-list went unwitnessed.');
    }

    /**
     * Cross-surface parity: shared corruption shapes must earn the
     * same verdict class on both twins, except where the ledger pins
     * a divergence (encoded here with its citation).
     *
     * @return void
     */
    public function testCrossSurfaceVerdictsMatchOnSharedCorruptionShapes()
    {
        $start = array('event' => 'message_start', 'data' => array(
            'type' => 'message_start',
            'message' => array('id' => 'm1', 'content' => array(), 'usage' => array('input_tokens' => 1, 'output_tokens' => 1)),
        ));
        $block_text = array('event' => 'content_block_start', 'data' => array(
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => array('type' => 'text', 'text' => 'Hi.'),
        ));
        $block_stop = array('event' => 'content_block_stop', 'data' => array('type' => 'content_block_stop', 'index' => 0));
        $final_delta = array('event' => 'message_delta', 'data' => array(
            'type' => 'message_delta',
            'delta' => array('stop_reason' => 'end_turn'),
            'usage' => array('output_tokens' => 2),
        ));
        $terminal = array('event' => 'message_stop', 'data' => array('type' => 'message_stop'));

        $zai_content = array('data' => array('id' => 'c1', 'choices' => array(array(
            'index' => 0,
            'delta' => array('content' => 'Hi.'),
            'finish_reason' => 'stop',
        ))));
        $zai_role = array('data' => array('id' => 'c1', 'choices' => array(array(
            'index' => 0,
            'delta' => array('role' => 'assistant'),
            'finish_reason' => null,
        ))));
        $zai_done = array('raw' => '[DONE]');

        $scenarios = array(
            // [label, zai frames, anthropic frames, zai class, anthropic class, divergence note or null].
            'undecodable data-only frame mid-stream' => array(
                array(
                    array('data' => array('id' => 'c1', 'choices' => array(array('index' => 0, 'delta' => array('role' => 'assistant'), 'finish_reason' => null)))),
                    array('raw' => '{"id":"c2","choices":[{"index":0,"delta":{"content":"Hel'),
                    array('data' => array('id' => 'c3', 'choices' => array(array('index' => 0, 'delta' => array('content' => 'lo.'), 'finish_reason' => null)))),
                    $zai_done,
                ),
                array(
                    $start,
                    array('event' => 'content_block_start', 'data' => array('type' => 'content_block_start', 'index' => 0, 'content_block' => array('type' => 'text', 'text' => ''))),
                    array('raw' => '{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hel'),
                    $block_stop,
                    $final_delta,
                    $terminal,
                ),
                'FLAGGED', 'FLAGGED', null, // glm23-6 / glm34-1.
            ),
            'decodable scalar data frame (non-event skip)' => array(
                array(
                    array('data' => array('id' => 'c1', 'choices' => array(array('index' => 0, 'delta' => array('role' => 'assistant'), 'finish_reason' => null)))),
                    array('raw' => 'null'),
                    array('data' => array('id' => 'c2', 'choices' => array(array('index' => 0, 'delta' => array('content' => 'Hi.'), 'finish_reason' => 'stop')))),
                    $zai_done,
                ),
                array(
                    $start,
                    array('raw' => 'null'),
                    $block_text,
                    $block_stop,
                    $final_delta,
                    $terminal,
                ),
                'CLEAN', 'CLEAN', null, // glm23-6 scalar skip / glm33-1 scalar tolerance.
            ),
            'unknown agreed event name is noise' => array(
                array(
                    array('event' => 'future_banana', 'data' => array('id' => 'c1', 'choices' => array(array('index' => 0, 'delta' => array('content' => 'Hi.'), 'finish_reason' => 'stop')))),
                    $zai_done,
                ),
                array(
                    $start,
                    array('event' => 'future_banana', 'data' => array('type' => 'future_banana')),
                    $final_delta,
                    $terminal,
                ),
                'CLEAN', 'CLEAN', null, // GLM7 #18 on both wires.
            ),
            'corrupt-only usage declaration' => array(
                array(
                    $zai_role,
                    array('data' => array('id' => 'c2', 'usage' => 'corrupt', 'choices' => array())),
                    array('data' => array('id' => 'c3', 'choices' => array(array('index' => 0, 'delta' => array('content' => 'Hi.'), 'finish_reason' => 'stop')))),
                    $zai_done,
                ),
                array(
                    array('event' => 'message_start', 'data' => array(
                        'type' => 'message_start',
                        'message' => array('id' => 'm1', 'content' => array(), 'usage' => 'corrupt'),
                    )),
                    $final_delta,
                    $terminal,
                ),
                'FLAGGED', 'FLAGGED', null, // glm31-1 end-of-stream / Codex R15 #1.
            ),
            'dropped terminal sentinel' => array(
                array($zai_content),
                array($start, $block_text, $block_stop, $final_delta),
                'CLEAN', 'FLAGGED',
                'PINNED DIVERGENCE: the Anthropic wire REQUIRES the message_start/message_delta/message_stop lifecycle (every aggregated() null path flags — Codex R8 #3, GLM7 #5, Codex R16 #1, glm35-1); the OpenAI wire\'s [DONE] is a terminal marker whose absence is undetectable (the choices-emptiness gate, glm26-11; GLM5 #7).',
            ),
            'duplicated terminal sentinel' => array(
                array($zai_content, $zai_done, $zai_done),
                array($start, $block_text, $block_stop, $final_delta, $terminal, $terminal),
                'CLEAN', 'CLEAN', null, // Duplicate sentinel no-op / glm16-2.
            ),
            'non-string role member' => array(
                array(
                    array('data' => array('id' => 'c1', 'choices' => array(array('index' => 0, 'delta' => array('role' => 5), 'finish_reason' => 'stop')))),
                    $zai_done,
                ),
                array(
                    array('event' => 'message_start', 'data' => array(
                        'type' => 'message_start',
                        'message' => array('id' => 'm1', 'role' => 5, 'content' => array(), 'usage' => array('input_tokens' => 1, 'output_tokens' => 1)),
                    )),
                    $final_delta,
                    $terminal,
                ),
                'FLAGGED', 'FLAGGED', null, // glm26-3 / Codex R6 #2.
            ),
        );

        foreach ($scenarios as $label => $scenario) {
            list($zai_frames, $anthropic_frames, $zai_class, $anthropic_class, $divergence) = $scenario;

            $zai = $this->aggregate_frames(SseAggregator::class, $zai_frames);
            $anthropic = $this->aggregate_frames(AnthropicSseAggregator::class, $anthropic_frames);

            $zai_verdict = $this->verdict_of($zai);
            $anthropic_verdict = $this->verdict_of($anthropic);

            $this->assertSame($zai_class, $zai_verdict, "[zai] {$label}: expected {$zai_class}.");
            $this->assertSame($anthropic_class, $anthropic_verdict, "[anthropic] {$label}: expected {$anthropic_class}.");

            if (null === $divergence) {
                $this->assertSame($zai_verdict, $anthropic_verdict, "{$label}: the twins must agree on shared corruption shapes.");
            }
        }
    }

    /*
     * ------------------------------------------------------------------
     * Corpus generators (deterministic under the seeded RNG).
     * ------------------------------------------------------------------
     */

    /**
     * One well-formed OpenAI-surface stream: role-first chunk, content
     * (and/or tool-call fragments), a finish_reason chunk, a usage
     * frame, the sentinel, and optional trailing shapes.
     *
     * @return list<array<string, mixed>> Frames.
     */
    private function openai_corpus()
    {
        $frames = array();
        $stream_id = 'chatcmpl-p' . mt_rand(1000, 9999);
        $multi_choice = 0 === mt_rand(0, 4);
        $tool_stream = 0 === mt_rand(0, 3);

        $first_delta = array('role' => 'assistant');
        if (!$tool_stream && 0 === mt_rand(0, 2)) {
            $first_delta['content'] = $this->content_chunk();
        }
        $frames[] = array('data' => array('id' => $stream_id, 'object' => 'chat.completion.chunk', 'choices' => array(array('index' => 0, 'delta' => $first_delta, 'finish_reason' => null))));

        $n_chunks = mt_rand(1, 4);
        for ($i = 0; $i < $n_chunks; $i++) {
            if ($tool_stream) {
                $frames[] = array('data' => array('id' => $stream_id, 'choices' => array(array(
                    'index' => 0,
                    'delta' => array('tool_calls' => array(array(
                        'index' => 0,
                        'id' => 0 === $i ? 'call_p1' : null,
                        'type' => 0 === $i ? 'function' : null,
                        'function' => array(
                            'name' => 0 === $i ? 'pick' : null,
                            'arguments' => $this->tool_fragment($i),
                        ),
                    ))),
                    'finish_reason' => null,
                ))));
            } else {
                $frame = array('data' => array('id' => $stream_id, 'choices' => array(array(
                    'index' => 0,
                    'delta' => array('content' => $this->content_chunk()),
                    'finish_reason' => null,
                ))));
                if (0 === mt_rand(0, 5)) {
                    // A declared chunk (the event line carries no
                    // semantics on this wire beyond 'error').
                    $frame['event'] = 'chat.completion.chunk';
                }
                $frames[] = $frame;

                if ($multi_choice) {
                    $frames[] = array('data' => array('id' => $stream_id, 'choices' => array(array(
                        'index' => 1,
                        'delta' => array('content' => $this->content_chunk()),
                        'finish_reason' => null,
                    ))));
                }
            }
        }

        // The zero-normalized gateway shape is well-formed too.
        if (0 === mt_rand(0, 4)) {
            $frames[] = array('data' => array('id' => $stream_id, 'usage' => array('prompt_tokens' => 0), 'choices' => array()));
        }

        $frames[] = array('data' => array('id' => $stream_id, 'choices' => array(array(
            'index' => 0,
            'delta' => new stdClass(),
            'finish_reason' => $tool_stream ? 'tool_calls' : 'stop',
        ))));

        $usage = array('prompt_tokens' => mt_rand(3, 40), 'completion_tokens' => mt_rand(1, 20));
        $usage['total_tokens'] = $usage['prompt_tokens'] + $usage['completion_tokens'];

        $frames[] = array('raw' => '[DONE]');

        if (0 === mt_rand(0, 2)) {
            // Appending gateway: the usage-bearing chunk AFTER the
            // sentinel (GLM7 #2's gap-fill shape).
            $frames[] = array('data' => array('id' => $stream_id, 'usage' => $usage, 'choices' => array()));
        } else {
            // Pre-sentinel usage riding the finish frame.
            $last = count($frames) - 2;
            $frames[$last]['data']['usage'] = $usage;
        }

        if (0 === mt_rand(0, 5)) {
            $frames[] = array('raw' => '[DONE]');
        }

        return $frames;
    }

    /**
     * One well-formed Anthropic-surface stream: required lifecycle,
     * serialized block lifecycles, optional pings and trailing noise.
     *
     * @return list<array<string, mixed>> Frames.
     */
    private function anthropic_corpus()
    {
        $frames = array();
        $message_id = 'msg_p' . mt_rand(1000, 9999);

        $frames[] = array('event' => 'message_start', 'data' => array(
            'type' => 'message_start',
            'message' => array(
                'id' => $message_id,
                'type' => 'message',
                'role' => 'assistant',
                'content' => array(),
                'model' => 'glm-5.3',
                'usage' => array('input_tokens' => mt_rand(1, 30), 'output_tokens' => 1),
            ),
        ));

        $n_blocks = mt_rand(0, 3);
        $last_block_type = '';
        for ($index = 0; $index < $n_blocks; $index++) {
            $pick = mt_rand(0, 2);
            if (0 === $pick) {
                $last_block_type = 'text';
                $frames[] = array('event' => 'content_block_start', 'data' => array(
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => array('type' => 'text', 'text' => 0 === mt_rand(0, 3) ? $this->content_chunk() : ''),
                ));
                $n_deltas = mt_rand(0, 3);
                for ($d = 0; $d < $n_deltas; $d++) {
                    $frames[] = array('event' => 'content_block_delta', 'data' => array(
                        'type' => 'content_block_delta',
                        'index' => $index,
                        'delta' => array('type' => 'text_delta', 'text' => $this->content_chunk()),
                    ));
                }
            } elseif (1 === $pick) {
                $last_block_type = 'thinking';
                $frames[] = array('event' => 'content_block_start', 'data' => array(
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => array('type' => 'thinking', 'thinking' => ''),
                ));
                $frames[] = array('event' => 'content_block_delta', 'data' => array(
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => array('type' => 'thinking_delta', 'thinking' => $this->content_chunk()),
                ));
            } else {
                $last_block_type = 'tool_use';
                $frames[] = array('event' => 'content_block_start', 'data' => array(
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => array('type' => 'tool_use', 'id' => 'toolu_p' . $index, 'name' => 'pick_' . $index, 'input' => new stdClass()),
                ));
                foreach ($this->tool_json_fragments() as $fragment) {
                    $frames[] = array('event' => 'content_block_delta', 'data' => array(
                        'type' => 'content_block_delta',
                        'index' => $index,
                        'delta' => array('type' => 'input_json_delta', 'partial_json' => $fragment),
                    ));
                }
            }

            if (0 === mt_rand(0, 4)) {
                $frames[] = array('event' => 'ping', 'data' => array('type' => 'ping'));
            }

            $frames[] = array('event' => 'content_block_stop', 'data' => array('type' => 'content_block_stop', 'index' => $index));
        }

        $stop_reason = 0 === mt_rand(0, 6) ? null : ('tool_use' === $last_block_type ? 'tool_use' : 'end_turn');
        $usage = array('output_tokens' => mt_rand(1, 30));
        if (0 === mt_rand(0, 3)) {
            $usage['input_tokens'] = mt_rand(1, 30);
        }

        $frames[] = array('event' => 'message_delta', 'data' => array(
            'type' => 'message_delta',
            'delta' => array('stop_reason' => $stop_reason, 'stop_sequence' => null),
            'usage' => $usage,
        ));

        $frames[] = array('event' => 'message_stop', 'data' => array('type' => 'message_stop'));

        if (0 === mt_rand(0, 3)) {
            $frames[] = array('raw' => '[DONE]');
        }
        if (0 === mt_rand(0, 4)) {
            $frames[] = array('event' => 'telemetry', 'data' => array('type' => 'telemetry', 'span' => 'abc'));
        }

        return $frames;
    }

    /**
     * A content chunk value; some carry multi-byte UTF-8 (rendered
     * unescaped) so the byte-level operators have real targets.
     *
     * @return string
     */
    private function content_chunk()
    {
        static $pool = array('Hello ', 'world.', 'The answer is 42.', 'héllo→世界', 'multi part ', 'text.', 'λ-calculus ', 'naïve résumé');

        return $pool[mt_rand(0, count($pool) - 1)];
    }

    /**
     * The i-th tool-call arguments fragment of a valid JSON object.
     *
     * @param int $i Fragment ordinal.
     * @return string
     */
    private function tool_fragment($i)
    {
        static $fragments = array('{"pa', 'th":"/tm', 'p","limit', '":5}');

        return $fragments[$i % count($fragments)];
    }

    /**
     * Valid JSON-object fragments for input_json_delta accumulation.
     *
     * @return list<string>
     */
    private function tool_json_fragments()
    {
        $shapes = array(
            array('{"path"', ':"/tmp"', ',"limit":5}'),
            array('{"a"', ':1}'),
            array('{}'),
            array('{"nested"', ':{"k"', ':"v"}}'),
        );

        return $shapes[mt_rand(0, count($shapes) - 1)];
    }

    /*
     * ------------------------------------------------------------------
     * Mutation operators.
     * ------------------------------------------------------------------
     */

    /**
     * The operator registry, ledger-history mapped:
     *
     *   cut-data-json / byte-inject-nul / byte-split-utf8 /
     *   truncate-stream-mid-frame / cut-data-only-frame → glm23-6, glm34-1
     *   drop-data-line → glm23-1 (the cut-declaration shape)
     *   drop-event-line → glm23-1/glm33-1 (the cut-carrier shape)
     *   drop-type-member → glm33-1 (the typeless carrier)
     *   member-wrong-type / member-absent / member-empty-string →
     *       glm26-3, glm28-2, glm29-4, glm31-1, glm31-2, GLM7 #8
     *   duplicate-frame / swap-frames → glm16-2, glm26-2
     *   partial-then-valid → the proxy-cut continuation shape
     *   unknown-enum / unknown-event-agreed → glm33-4, GLM7 #18, GLM1 #15
     *   usage-corrupt → glm31-1, Codex R15 #1
     *   drop-frame → the wire's absent frame-integrity signal
     *
     * @return array<string, string> Operator id => method name.
     */
    private function operators()
    {
        return array(
            'cut-data-json' => 'op_cut_data_json',
            'cut-data-only-frame' => 'op_cut_data_only_frame',
            'drop-event-line' => 'op_drop_event_line',
            'drop-data-line' => 'op_drop_data_line',
            'drop-type-member' => 'op_drop_type_member',
            'member-wrong-type' => 'op_member_wrong_type',
            'member-empty-string' => 'op_member_empty_string',
            'member-absent' => 'op_member_absent',
            'duplicate-frame' => 'op_duplicate_frame',
            'swap-frames' => 'op_swap_frames',
            'byte-split-utf8' => 'op_byte_split_utf8',
            'byte-inject-nul' => 'op_byte_inject_nul',
            'partial-then-valid' => 'op_partial_then_valid',
            'unknown-enum' => 'op_unknown_enum',
            'unknown-event-agreed' => 'op_unknown_event_agreed',
            'usage-corrupt' => 'op_usage_corrupt',
            'drop-frame' => 'op_drop_frame',
            'truncate-stream-mid-frame' => 'op_truncate_stream',
        );
    }

    /**
     * Applies one operator; null when no eligible site exists or the
     * rendered stream did not change.
     *
     * @param string $op_id Operator id.
     * @param list<array<string, mixed>> $frames Corpus frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function apply_operator($op_id, array $frames)
    {
        $method = $this->operators()[$op_id];
        $result = $this->{$method}($frames);

        if (null === $result) {
            return null;
        }
        if ($this->render_stream($frames) === $this->render_stream($result['frames'])) {
            return null;
        }

        $result['meta']['op'] = $op_id;

        return $result;
    }

    /**
     * Frame indexes carrying a data payload (structured or raw).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return list<int>
     */
    private function data_frame_indexes(array $frames)
    {
        $indexes = array();
        foreach ($frames as $i => $frame) {
            if (null !== ($frame['data'] ?? null) || null !== ($frame['raw'] ?? null)) {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /**
     * Cut one frame's data payload mid-JSON (the proxy-cut shape).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_cut_data_json(array $frames)
    {
        return $this->cut_payload($frames, true);
    }

    /**
     * Cut the payload mid-JSON AND strip the event line — glm34-1's
     * undecodable data-only frame.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_cut_data_only_frame(array $frames)
    {
        return $this->cut_payload($frames, false);
    }

    /**
     * The shared mid-payload cutter.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @param bool $keep_event Whether the event line survives.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function cut_payload(array $frames, $keep_event)
    {
        $candidates = array();
        foreach ($frames as $i => $frame) {
            $json = $this->frame_json($frame);
            if (null !== $json && strlen($json) > 8) {
                $candidates[] = $i;
            }
        }
        if (array() === $candidates) {
            return null;
        }

        $i = $candidates[mt_rand(0, count($candidates) - 1)];
        $json = $this->frame_json($frames[$i]);
        $cut = mt_rand(2, strlen($json) - 2);
        $remainder = substr($json, 0, $cut);

        $event = $frames[$i]['event'] ?? null;
        $label = null !== $event ? $event : 'data-only';
        $frames[$i] = $keep_event && null !== $event ? array('event' => $event, 'raw' => $remainder) : array('raw' => $remainder);

        // A remainder that still decodes to a non-array keeps the
        // decodable-scalar tolerance on both wires; anything else must
        // flag through the undecodable rules.
        $decoded = json_decode($remainder);
        $decodes = null !== $decoded && !is_object($decoded) && !is_array($decoded) ? gettype($decoded) : null;

        return array('frames' => $frames, 'meta' => array(
            'target' => $label . '.data',
            'kind' => 'cut',
            'decodes' => $decodes,
        ));
    }

    /**
     * Remove the event: line from one frame (the split carrier's other
     * half — glm23-1/glm33-1).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_drop_event_line(array $frames)
    {
        $candidates = array();
        foreach ($frames as $i => $frame) {
            if (null !== ($frame['event'] ?? null)) {
                $candidates[] = $i;
            }
        }
        if (array() === $candidates) {
            return null;
        }

        $i = $candidates[mt_rand(0, count($candidates) - 1)];
        $event = $frames[$i]['event'];
        unset($frames[$i]['event']);

        return array('frames' => $frames, 'meta' => array(
            'target' => $event . '.event-line',
            'kind' => 'absent',
        ));
    }

    /**
     * Remove the data line(s) from one frame (the bare declaration —
     * glm23-1's cut-data shape).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_drop_data_line(array $frames)
    {
        $candidates = $this->data_frame_indexes($frames);
        if (array() === $candidates) {
            return null;
        }

        $i = $candidates[mt_rand(0, count($candidates) - 1)];
        $label = $frames[$i]['event'] ?? 'data-only';
        unset($frames[$i]['data'], $frames[$i]['raw']);

        return array('frames' => $frames, 'meta' => array(
            'target' => $label . '.data-line',
            'kind' => 'absent',
        ));
    }

    /**
     * Remove the payload's type member (glm33-1's typeless carrier).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_drop_type_member(array $frames)
    {
        $probes = array(
            array('type'),
            array('delta', 'type'),
            array('choices', '0', 'delta', 'tool_calls', '0', 'type'),
        );

        $candidates = array();
        foreach ($frames as $i => $frame) {
            if (!is_array($frame['data'] ?? null)) {
                continue;
            }
            foreach ($probes as $probe) {
                if ("\0absent" !== $this->get_member_path($frame['data'], $probe)) {
                    $label = $frames[$i]['event'] ?? 'data-only';
                    $tail = 'type' === $probe[0] ? 'type' : implode('.', array_slice($probe, -2));
                    $candidates[] = array($i, $probe, $label . '.' . $tail);
                }
            }
        }
        if (array() === $candidates) {
            return null;
        }

        list($i, $probe, $target) = $candidates[mt_rand(0, count($candidates) - 1)];
        $frames[$i]['data'] = $this->unset_member_path($frames[$i]['data'], $probe);

        return array('frames' => $frames, 'meta' => array(
            'target' => $target,
            'kind' => 'absent',
        ));
    }

    /**
     * Every member path in the frames' structured payloads.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return list<array{i: int, path: string, target: string}>
     */
    private function member_sites(array $frames)
    {
        $sites = array();
        foreach ($frames as $i => $frame) {
            if (!is_array($frame['data'] ?? null)) {
                continue;
            }
            $this->collect_member_sites($frame['data'], '', $frame['event'] ?? 'data-only', $i, $sites);
        }

        return $sites;
    }

    /**
     * Recursive member-path collector.
     *
     * @param mixed $value Current payload node.
     * @param string $path Path so far.
     * @param string $event Frame event label.
     * @param int $i Frame index.
     * @param list<array{i: int, path: string, target: string}> $sites Collected sites (by reference).
     * @return void
     */
    private function collect_member_sites($value, $path, $event, $i, array &$sites)
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $child_path = '' === $path ? (string) $key : $path . '.' . $key;
            $sites[] = array('i' => $i, 'path' => $child_path, 'target' => $event . '.' . $child_path);
            $this->collect_member_sites($child, $child_path, $event, $i, $sites);
        }
    }

    /**
     * Substitute one member with a wrong-typed value.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_member_wrong_type(array $frames)
    {
        $sites = $this->member_sites($frames);
        if (array() === $sites) {
            return null;
        }

        $site = $sites[mt_rand(0, count($sites) - 1)];
        $kinds = array('int' => 5, 'list' => array(1), 'object' => new stdClass(), 'null' => null, 'false' => false);
        $keys = array_keys($kinds);
        $kind = $keys[mt_rand(0, count($keys) - 1)];

        $frames[$site['i']]['data'] = $this->set_member_path($frames[$site['i']]['data'], explode('.', $site['path']), $kinds[$kind]);

        return array('frames' => $frames, 'meta' => array(
            'target' => $site['target'],
            'kind' => $kind,
        ));
    }

    /**
     * Set one string member to the empty string.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_member_empty_string(array $frames)
    {
        $sites = array();
        foreach ($this->member_sites($frames) as $site) {
            $value = $this->get_member_path($frames[$site['i']]['data'], explode('.', $site['path']));
            if (is_string($value) && '' !== $value) {
                $sites[] = $site;
            }
        }
        if (array() === $sites) {
            return null;
        }

        $site = $sites[mt_rand(0, count($sites) - 1)];
        $frames[$site['i']]['data'] = $this->set_member_path($frames[$site['i']]['data'], explode('.', $site['path']), '');

        return array('frames' => $frames, 'meta' => array(
            'target' => $site['target'],
            'kind' => 'empty',
        ));
    }

    /**
     * Remove one member outright.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_member_absent(array $frames)
    {
        $sites = $this->member_sites($frames);
        if (array() === $sites) {
            return null;
        }

        $site = $sites[mt_rand(0, count($sites) - 1)];
        $frames[$site['i']]['data'] = $this->unset_member_path($frames[$site['i']]['data'], explode('.', $site['path']));

        return array('frames' => $frames, 'meta' => array(
            'target' => $site['target'],
            'kind' => 'absent',
        ));
    }

    /**
     * Duplicate one frame at its own position.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_duplicate_frame(array $frames)
    {
        $i = mt_rand(0, count($frames) - 1);
        $label = is_string($frames[$i]['event'] ?? null) ? $frames[$i]['event'] : (is_string($frames[$i]['raw'] ?? null) ? $frames[$i]['raw'] : 'frame');

        array_splice($frames, $i, 0, array($frames[$i]));

        return array('frames' => $frames, 'meta' => array(
            'target' => $label,
            'kind' => 'dup',
        ));
    }

    /**
     * Swap two frames (out-of-order lifecycle producer).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_swap_frames(array $frames)
    {
        if (count($frames) < 2) {
            return null;
        }

        $a = mt_rand(0, count($frames) - 1);
        $b = mt_rand(0, count($frames) - 1);
        if ($a === $b) {
            return null;
        }

        $label = static function ($frame) {
            return is_string($frame['event'] ?? null) ? $frame['event'] : (is_string($frame['raw'] ?? null) ? $frame['raw'] : 'frame');
        };

        $target = $label($frames[$a]) . '<->' . $label($frames[$b]);
        $tmp = $frames[$a];
        $frames[$a] = $frames[$b];
        $frames[$b] = $tmp;

        return array('frames' => $frames, 'meta' => array(
            'target' => $target,
            'kind' => 'swap',
        ));
    }

    /**
     * Split one multi-byte UTF-8 sequence in the raw payload bytes.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_byte_split_utf8(array $frames)
    {
        $candidates = array();
        foreach ($frames as $i => $frame) {
            $json = $this->frame_json($frame);
            if (null !== $json && preg_match('/[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3}/', $json, $m, PREG_OFFSET_CAPTURE)) {
                $candidates[] = array($i, $m[0][1], strlen($m[0][0]));
            }
        }
        if (array() === $candidates) {
            return null;
        }

        list($i, $offset, $length) = $candidates[mt_rand(0, count($candidates) - 1)];
        $label = $frames[$i]['event'] ?? 'data-only';
        $json = $this->frame_json($frames[$i]);
        // Keep the first byte of the sequence only — invalid UTF-8.
        $frames[$i] = array('event' => $frames[$i]['event'] ?? null, 'raw' => substr($json, 0, $offset + 1) . substr($json, $offset + $length));

        return array('frames' => $frames, 'meta' => array(
            'target' => $label . '.data',
            'kind' => 'utf8-split',
        ));
    }

    /**
     * Inject a NUL byte into one payload.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_byte_inject_nul(array $frames)
    {
        $candidates = $this->data_frame_indexes($frames);
        if (array() === $candidates) {
            return null;
        }

        $i = $candidates[mt_rand(0, count($candidates) - 1)];
        $label = $frames[$i]['event'] ?? 'data-only';
        $json = $this->frame_json($frames[$i]);
        $at = mt_rand(1, max(1, strlen($json) - 1));
        $frames[$i] = array('event' => $frames[$i]['event'] ?? null, 'raw' => substr($json, 0, $at) . "\0" . substr($json, $at));

        return array('frames' => $frames, 'meta' => array(
            'target' => $label . '.data',
            'kind' => 'nul',
        ));
    }

    /**
     * A cut frame immediately followed by its complete valid form (the
     * proxy-cut continuation shape).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_partial_then_valid(array $frames)
    {
        $candidates = array();
        foreach ($frames as $i => $frame) {
            $json = $this->frame_json($frame);
            if (null !== $json && strlen($json) > 8) {
                $candidates[] = $i;
            }
        }
        if (array() === $candidates) {
            return null;
        }

        $i = $candidates[mt_rand(0, count($candidates) - 1)];
        $original = $frames[$i];
        $json = $this->frame_json($original);
        $cut = mt_rand(2, strlen($json) - 2);

        $frames[$i] = array('event' => $original['event'] ?? null, 'raw' => substr($json, 0, $cut));
        array_splice($frames, $i + 1, 0, array($original));

        return array('frames' => $frames, 'meta' => array(
            'target' => ($original['event'] ?? 'data-only') . '.data',
            'kind' => 'cut-then-valid',
        ));
    }

    /**
     * Substitute one enum-ish value with an unknown one (stop_reason,
     * finish_reason, block/delta types, message role/type, tool type).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_unknown_enum(array $frames)
    {
        $probes = array(
            array(array('choices', '0', 'finish_reason'), 'finish_reason'),
            array(array('delta', 'stop_reason'), 'stop_reason'),
            array(array('content_block', 'type'), 'content_block.type'),
            array(array('delta', 'type'), 'delta.type'),
            array(array('message', 'role'), 'message.role'),
            array(array('message', 'type'), 'message.type'),
            array(array('type'), 'type'),
            array(array('choices', '0', 'delta', 'tool_calls', '0', 'type'), 'tool_calls.type'),
            array(array('id'), 'id'),
        );

        $sites = array();
        foreach ($frames as $i => $frame) {
            if (!is_array($frame['data'] ?? null)) {
                continue;
            }
            $event = $frame['event'] ?? 'data-only';
            foreach ($probes as $probe) {
                $value = $this->get_member_path($frame['data'], $probe[0]);
                if (is_string($value) && '' !== $value) {
                    $sites[] = array('i' => $i, 'path' => $probe[0], 'target' => $event . '.' . $probe[1]);
                }
            }
        }
        if (array() === $sites) {
            return null;
        }

        $site = $sites[mt_rand(0, count($sites) - 1)];
        $frames[$site['i']]['data'] = $this->set_member_path($frames[$site['i']]['data'], $site['path'], 'future_banana');

        return array('frames' => $frames, 'meta' => array(
            'target' => $site['target'],
            'kind' => 'enum',
        ));
    }

    /**
     * Rename one declared event AND its payload's type member to the
     * same unknown value (the pure unknown-name noise shape).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_unknown_event_agreed(array $frames)
    {
        $candidates = array();
        foreach ($frames as $i => $frame) {
            if (null !== ($frame['event'] ?? null) && is_array($frame['data'] ?? null)) {
                $candidates[] = $i;
            }
        }
        if (array() === $candidates) {
            return null;
        }

        $i = $candidates[mt_rand(0, count($candidates) - 1)];
        $event = $frames[$i]['event'];
        $frames[$i]['event'] = 'future_banana';
        if (array_key_exists('type', $frames[$i]['data'])) {
            $frames[$i]['data']['type'] = 'future_banana';
        }

        return array('frames' => $frames, 'meta' => array(
            'target' => $event . '.event-name',
            'kind' => 'enum-agreed',
        ));
    }

    /**
     * Corrupt one usage member or its inner counts (glm31-1's shape
     * family, wrong-typed at either depth).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_usage_corrupt(array $frames)
    {
        $sites = array();
        foreach ($frames as $i => $frame) {
            if (!is_array($frame['data'] ?? null)) {
                continue;
            }
            $event = $frame['event'] ?? 'data-only';
            $paths = array(array('usage'));

            $message_usage = $this->get_member_path($frame['data'], array('message', 'usage'));
            if (is_array($message_usage) && "\0absent" !== $message_usage) {
                $paths[] = array('message', 'usage');
                foreach (array_keys($message_usage) as $key) {
                    $paths[] = array('message', 'usage', $key);
                }
            }
            $usage = $this->get_member_path($frame['data'], array('usage'));
            if (is_array($usage) && "\0absent" !== $usage) {
                foreach (array_keys($usage) as $key) {
                    $paths[] = array('usage', $key);
                }
            }

            foreach ($paths as $path) {
                if ("\0absent" !== $this->get_member_path($frame['data'], $path)) {
                    $sites[] = array('i' => $i, 'path' => $path, 'target' => $event . '.' . implode('.', $path));
                }
            }
        }
        if (array() === $sites) {
            return null;
        }

        $site = $sites[mt_rand(0, count($sites) - 1)];
        $kinds = array('string' => 'corrupt', 'int' => 5, 'list' => array(1), 'null' => null);
        $keys = array_keys($kinds);
        $pick = $keys[mt_rand(0, count($keys) - 1)];

        $frames[$site['i']]['data'] = $this->set_member_path($frames[$site['i']]['data'], $site['path'], $kinds[$pick]);

        return array('frames' => $frames, 'meta' => array(
            'target' => $site['target'],
            'kind' => 'usage-' . $pick,
        ));
    }

    /**
     * Drop one whole frame (the wire's absent frame-integrity class).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_drop_frame(array $frames)
    {
        if (count($frames) < 2) {
            return null;
        }

        $i = mt_rand(0, count($frames) - 1);
        $label = is_string($frames[$i]['event'] ?? null) ? $frames[$i]['event'] : (is_string($frames[$i]['raw'] ?? null) ? $frames[$i]['raw'] : 'frame');
        array_splice($frames, $i, 1);

        return array('frames' => $frames, 'meta' => array(
            'target' => $label,
            'kind' => 'drop',
        ));
    }

    /**
     * Truncate the stream inside one frame's bytes (mid-frame cut).
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return array{frames: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function op_truncate_stream(array $frames)
    {
        if (count($frames) < 2) {
            return null;
        }

        $keep = mt_rand(1, count($frames) - 1);
        $kept = array_slice($frames, 0, $keep);
        $last = $kept[$keep - 1];
        $label = is_string($last['event'] ?? null) ? $last['event'] : 'data-only';
        $rendered = $this->render_frame($last);
        $cut = mt_rand(1, max(1, strlen($rendered) - 1));

        // Represent the truncated stream as one pre-rendered suffix.
        $stream = $this->render_stream(array_slice($kept, 0, $keep - 1)) . substr($rendered, 0, $cut);

        return array(
            'frames' => array(array('suffix' => $stream)),
            'meta' => array('target' => $label, 'kind' => 'truncate'),
        );
    }

    /*
     * ------------------------------------------------------------------
     * Frame rendering and aggregation.
     * ------------------------------------------------------------------
     */

    /**
     * Renders one frame to wire bytes.
     *
     * @param array<string, mixed> $frame Frame.
     * @return string
     */
    private function render_frame(array $frame)
    {
        if (null !== ($frame['suffix'] ?? null)) {
            return (string) $frame['suffix'];
        }

        $out = '';
        if (null !== ($frame['event'] ?? null)) {
            $out .= 'event: ' . $frame['event'] . "\n";
        }
        if (null !== ($frame['raw'] ?? null)) {
            $out .= 'data: ' . $frame['raw'] . "\n\n";
        } elseif (null !== ($frame['data'] ?? null)) {
            $out .= 'data: ' . $this->frame_json($frame) . "\n\n";
        } else {
            // A bare declaration frame.
            $out .= "\n";
        }

        return $out;
    }

    /**
     * Renders a whole frame list to stream bytes.
     *
     * @param list<array<string, mixed>> $frames Frames.
     * @return string
     */
    private function render_stream(array $frames)
    {
        $out = '';
        foreach ($frames as $frame) {
            $out .= $this->render_frame($frame);
        }

        return $out;
    }

    /**
     * The rendered data JSON of one frame, or null for data-less
     * frames. Non-ASCII strings render unescaped (legal raw-UTF-8
     * JSON) so the byte-level operators see real multi-byte targets.
     *
     * @param array<string, mixed> $frame Frame.
     * @return string|null
     */
    private function frame_json(array $frame)
    {
        if (null !== ($frame['raw'] ?? null)) {
            return (string) $frame['raw'];
        }
        if (null === ($frame['data'] ?? null)) {
            return null;
        }

        $flags = $this->data_contains_raw_unicode($frame['data']) ? JSON_UNESCAPED_UNICODE : 0;

        return (string) wp_json_encode($frame['data'], $flags);
    }

    /**
     * Whether a payload tree carries non-ASCII strings.
     *
     * @param mixed $value Payload node.
     * @return bool
     */
    private function data_contains_raw_unicode($value)
    {
        if (is_string($value)) {
            return (bool) preg_match('/[^\x00-\x7F]/', $value);
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->data_contains_raw_unicode($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Feeds a frame list through one aggregator and returns its state.
     *
     * @param string $class Aggregator class.
     * @param list<array<string, mixed>> $frames Frames.
     * @return array<string, mixed> The aggregation outcome.
     */
    private function aggregate_frames($class, array $frames)
    {
        $aggregator = new $class();

        try {
            $aggregator->feed($this->render_stream($frames));
            $aggregator->finish();
            $payload = $aggregator->aggregated();

            return array(
                'ok' => true,
                'payload' => $payload,
                'flags' => array(
                    $aggregator->has_malformed_event(),
                    $aggregator->has_error(),
                    method_exists($aggregator, 'has_malformed_tool_input') && $aggregator->has_malformed_tool_input(),
                ),
                'threw' => null,
            );
        } catch (Throwable $e) {
            return array('ok' => false, 'payload' => null, 'flags' => array(), 'threw' => get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Asserts the corpus itself is clean (generator sanity).
     *
     * @param string $surface Surface.
     * @param int $case Case number.
     * @param array<string, mixed> $pristine Pristine aggregation.
     * @param list<array<string, mixed>> $frames Corpus frames.
     * @return void
     */
    private function assertPristineCorpus($surface, $case, array $pristine, array $frames)
    {
        $this->assertTrue($pristine['ok'], "[{$surface} corpus case {$case}] the pristine stream threw: {$pristine['threw']}");
        $this->assertNotNull($pristine['payload'], "[{$surface} corpus case {$case}] the pristine stream aggregated null — generator bug:\n" . $this->render_stream($frames));
        $this->assertSame(array(false, false, false), $pristine['flags'], "[{$surface} corpus case {$case}] the pristine stream flagged — generator bug:\n" . $this->render_stream($frames));
    }

    /**
     * The verdict class of one mutated aggregation against its
     * pristine twin.
     *
     * @param string $surface Surface.
     * @param array<string, mixed> $meta Operator metadata.
     * @param array<string, mixed> $pri Pristine outcome.
     * @param array<string, mixed> $mut Mutated outcome.
     * @return array{class: string, cite: ?string, why: ?string}
     */
    private function classify($surface, array $meta, array $pri, array $mut)
    {
        if (!$mut['ok']) {
            return array('class' => 'FAIL', 'cite' => null, 'why' => 'the aggregator threw (' . $mut['threw'] . ') — the invariant requires a flag or identical output, never an escaping Error/Throwable');
        }

        $diff = $mut['payload'] != $pri['payload'];
        $flagged = array(false, false, false) !== $mut['flags'];

        if (!$diff && !$flagged) {
            return array('class' => 'CLEAN', 'cite' => null, 'why' => null);
        }
        if ($flagged) {
            return array('class' => 'FLAGGED', 'cite' => null, 'why' => null);
        }

        $cite = $this->tolerance_cite($surface, $meta);

        return null !== $cite
            ? array('class' => 'TOLERATED', 'cite' => $cite, 'why' => null)
            : array('class' => 'FAIL', 'cite' => null, 'why' => 'output diverged with NO corruption flag and NO allow-listed tolerance');
    }

    /**
     * The verdict label for the parity battery (no pristine twin
     * there — a flagged or null outcome is FLAGGED, else CLEAN).
     *
     * @param array<string, mixed> $outcome Aggregation outcome.
     * @return string
     */
    private function verdict_of(array $outcome)
    {
        $this->assertTrue($outcome['ok'], 'Parity scenario threw: ' . $outcome['threw']);

        return array(false, false, false) !== $outcome['flags'] || null === $outcome['payload'] ? 'FLAGGED' : 'CLEAN';
    }

    /**
     * Builds the failure report for an invariant violation.
     *
     * @param string $surface Surface.
     * @param int $case Case number.
     * @param int $seed Seed.
     * @param array<string, mixed> $meta Operator metadata.
     * @param array<string, mixed> $pri Pristine outcome.
     * @param array<string, mixed> $mut Mutated outcome.
     * @param list<array<string, mixed>> $frames Mutated frames.
     * @param string $why Failure reason.
     * @return string
     */
    private function failure_report($surface, $case, $seed, array $meta, array $pri, array $mut, array $frames, $why)
    {
        $stream = $this->render_stream($frames);
        if (strlen($stream) > 2400) {
            $stream = substr($stream, 0, 2400) . "\n… (truncated)";
        }

        return sprintf(
            "[%s case %d seed %d op=%s target=%s kind=%s] %s.\n\nPristine payload: %s\nMutated payload: %s\n\nReproducing stream:\n%s",
            $surface,
            $case,
            $seed,
            $meta['op'],
            $meta['target'],
            $meta['kind'],
            $why,
            var_export($pri['payload'], true),
            var_export($mut['payload'], true),
            $stream
        );
    }

    /*
     * ------------------------------------------------------------------
     * The enumerated tolerance allow-list. A diff-no-flag observation
     * matching NO entry fails the run. Every entry cites its ledger
     * decision; widening requires adjudication, never a silent edit.
     * ------------------------------------------------------------------
     */

    /**
     * The ledger citation for one diff-no-flag observation, or null
     * when the observation is not a known tolerance.
     *
     * @param string $surface 'zai' or 'anthropic'.
     * @param array<string, mixed> $meta Operator metadata (op/target/kind/...).
     * @return string|null
     */
    private function tolerance_cite($surface, array $meta)
    {
        $op = $meta['op'];
        $kind = $meta['kind'];
        $target = $meta['target'];

        /*
         * The wire-level integrity classes (both surfaces): SSE
         * carries no per-frame sequencing or checksum field, so a
         * vanished, duplicated, or reordered whole frame is
         * indistinguishable from a stream that arrived that way.
         * Every glm-class fix targets VISIBLE in-band damage; the
         * aggregators' charter is exactly that (frames are merged
         * "as received"). The Anthropic twin still flags
         * lifecycle-frame drops and reorderings through its required
         * serialized lifecycle — those cases never reach this table.
         */
        if ('drop-frame' === $op) {
            return 'no frame-integrity signal exists on the SSE wire (no sequencing or checksum field); a vanished whole frame is indistinguishable from a stream that never contained it — the aggregators\' charter is visible in-band damage';
        }
        if ('swap-frames' === $op) {
            return 'frame order is carried by arrival alone; absent sequence fields, a reorder is indistinguishable from a stream that arrived that way (glm26-2\'s ascending-interleave tolerance is the pinned member of this class)';
        }
        if ('truncate-stream-mid-frame' === $op) {
            return 'a suffix cut from the stream end is the drop-frame class at stream scope (the wire signals no expected length); mid-byte cuts that damage a data line flag through the undecodable rules, and the Anthropic lifecycle gates flag suffix loss of lifecycle frames';
        }
        if ('duplicate-frame' === $op) {
            return 'a byte-identical duplicate is indistinguishable from a legitimately repeated delta (no idempotency key exists on the wire); the duplicate-sentinel no-op is the pinned member of this class (glm16-2 appending-gateway tolerance)';
        }
        if (('cut-data-json' === $op || 'cut-data-only-frame' === $op || 'partial-then-valid' === $op) && null !== ($meta['decodes'] ?? null)) {
            return 'cut data whose remainder still decodes to a scalar keeps the decodable-scalar non-event skip (glm23-6 on zai, glm33-1\'s scalar tolerance on the twin)';
        }

        return 'zai' === $surface
            ? $this->zai_tolerance_cite($meta)
            : $this->anthropic_tolerance_cite($meta);
    }

    /**
     * The zai-surface half of the allow-list.
     *
     * @param array<string, mixed> $meta Operator metadata.
     * @return string|null
     */
    private function zai_tolerance_cite(array $meta)
    {
        $op = $meta['op'];
        $kind = $meta['kind'];
        $target = $meta['target'];
        $absent = 'absent' === $kind || 'null' === $kind;
        $transparent_kinds = array('enum', 'int', 'list', 'object', 'false', 'empty');

        // --- The frame-level non-event skip family -------------------
        if (preg_match('~(^|\.)(choices|choices\.\d+)$~', $target) && ($absent || 'object' === $kind)) {
            // {} decodes to the empty array, and a vanished choice entry
            // leaves the empty list: the choices-less non-event either
            // way (the entry-granularity half is the drop-frame class).
            return 'glm23-6/glm28-2: an absent, explicitly-null, or EMPTY ({} / []) choices member — or a vanished choice ENTRY leaving the empty list — keeps the historical non-event skip; the frame\'s id/usage halves still merge';
        }
        if (preg_match('~(^|\.)(delta|data)$~', $target) && ($absent || in_array($kind, array('object', 'list'), true))) {
            // Adjudicated proph: a LIST delta is still an array — glm28-2's
            // gate flags non-arrays only, and the associative decode
            // collapses {} and [] (GLM5 #3's documented collapse), so an
            // array delta with no recognizable members is the empty-delta
            // shape.
            return 'glm28-2/GLM5 #3: an absent/null delta member keeps its silent skip; the EMPTY delta ({} — or any array-shaped delta with no recognizable members, the {} / [] associative collapse) is the legitimate empty-chunk shape the fixtures themselves use';
        }
        if ('drop-data-line' === $op) {
            return 'a data-less frame names nothing actionable on this wire (no declared-event semantics beyond error — GLM7 #18); its payload is gone with the frame, the drop-frame class one layer down';
        }
        if ('unknown-event-agreed' === $op) {
            return 'GLM7 #18: unknown declared event names are ignorable noise on this wire';
        }

        // --- Tool-call fragment members ------------------------------
        if (preg_match('~\.delta\.tool_calls$~', $target) && ($absent || 'object' === $kind)) {
            // Adjudicated proph: {} decodes to the empty list — no
            // fragments to merge (a NON-empty list of non-entries
            // flags through the index rule).
            return 'glm28-2/GLM5 #3: an absent/null — or EMPTY ({} / []) — tool_calls member keeps the skip (no fragments to merge)';
        }
        if (preg_match('~\.tool_calls\.\d+$~', $target) && $absent) {
            // Adjudicated proph: a vanished fragment ENTRY leaves the
            // list shorter — entry-granularity drop-frame; the remaining
            // argument fragments are judged where tool JSON is parsed
            // (the model layer), never re-derived here.
            return 'glm28-2/GLM10 #13: a vanished tool_calls ENTRY is the drop-frame class at entry granularity; the accumulated argument fragments travel verbatim and the model layer judges their concatenation';
        }
        if (preg_match('~\.tool_calls\.\d+\.(id|type)$~', $target)) {
            if ($absent) {
                return 'glm28-2: an absent/null fragment member keeps its skip; the accumulator default is judged downstream (the model\'s identity rejection)';
            }
            if (in_array($kind, array('enum', 'empty'), true)) {
                return 'value transparency: string id/type values merge verbatim; tool shapes judge at the vendor parse downstream';
            }
        }
        if (preg_match('~\.tool_calls\.\d+\.function$~', $target) && ($absent || in_array($kind, array('object', 'list'), true))) {
            // Adjudicated proph: an array-shaped function node (the {}
            // / [] associative collapse, GLM5 #3) carries no
            // recognizable members — the skip class, not corruption.
            return 'glm28-2/GLM5 #3: an absent/null function member — or an array-shaped one with no recognizable members (the {} / [] collapse) — keeps its skip';
        }
        if (preg_match('~\.tool_calls\.\d+\.function\.(name|arguments)$~', $target)) {
            if ($absent) {
                return 'glm28-2: absent/null function members keep their skip (accumulator defaults; glm6-15\'s empty-string arguments is the legitimate no-argument-call semantics)';
            }
            if ('empty' === $kind && 'arguments' === substr($target, -9)) {
                return 'glm6-15: an empty-string arguments field denotes a no-argument call — the legitimate fragment shape';
            }
        }

        // --- Delta content members -----------------------------------
        if (preg_match('~\.delta\.(role|content|reasoning_content|tool_calls)$~', $target) && $absent) {
            return 'glm26-3/glm28-2: an absent/null delta member keeps the absent-semantics skip (the kept-tolerance half of the present-but-wrong-type rules)';
        }
        if (preg_match('~\.delta\.(content|reasoning_content)$~', $target) && 'empty' === $kind) {
            return 'glm28-2: the empty-string content fragment merges nothing by construction';
        }
        if (preg_match('~\.delta\.role$~', $target) && in_array($kind, array('enum', 'empty'), true)) {
            return 'glm26-3 flags non-STRING roles; any string value (the empty string included) merges, and the vendor parse coerces non-user roles into a model message downstream (documented at the glm26-3 rule)';
        }

        // --- Usage ----------------------------------------------------
        if (preg_match('~(^|\.)(usage|message\.usage)$~', $target)) {
            if ($absent) {
                return 'GLM7 #8: an absent/null usage member keeps absent semantics on this wire';
            }
            if ('object' === $kind || 'usage-null' === $kind) {
                return 'GLM12 #9: the empty usage member ({} / the zero-normalized gateway shape) is informationally empty — a documented tolerance';
            }
            if (in_array($kind, array('int', 'false', 'string', 'usage-int', 'usage-false', 'usage-string'), true)) {
                return 'glm31-1/GLM6 #3: a non-array usage member superseded by a valid merge elsewhere is pinned noise (the flag rises only on the corrupt-only stream at end of stream)';
            }
            if ('list' === $kind || 'usage-list' === $kind) {
                return 'the array member merges and travels to the model\'s shared UsageValidator through the raw-oracle contract (GLM6 #3/GLM4 #11); inner corruption is the validator\'s typed rejection, never the aggregator\'s';
            }
        }
        if (preg_match('~\.usage\.[^.]+$~', $target)) {
            if ($absent || 'null' === $kind) {
                return 'GLM7 #8: absent/null members of a usage object default to zero downstream (the partial-object tolerance)';
            }
            if (0 === strpos($kind, 'usage-') || in_array($kind, array('int', 'list', 'object', 'false'), true)) {
                return 'usage members travel to the model\'s shared UsageValidator through the raw-oracle contract (GLM6 #3/GLM4 #11): valid value changes merge (provider-reported counts are transparent) and corrupt members are the validator\'s typed rejection — never the aggregator\'s';
            }
        }

        // --- Envelope and finish metadata -----------------------------
        if (preg_match('~(\.choices\.\d+|\.tool_calls\.\d+)\.index$~', $target) && 'int' === $kind) {
            // Adjudicated proph: a VALID non-negative integer index
            // merges — GLM7 #1 rules the TYPE; the value is the wire's
            // own merge addressing (sparse/non-zero-starting indexes
            // are documented legitimate at the reindexing site).
            return 'GLM7 #1/GLM10 #13: the index TYPE is corruption-judged, never the value — the stream\'s own index is the merge identity (sparse and non-zero-starting indexes are the documented legitimate shapes)';
        }
        if (preg_match('~(^|\.)(id|object)$~', $target)) {
            if ($absent || in_array($kind, array('int', 'false', 'list', 'object', 'enum', 'empty'), true)) {
                return 'the id capture is first-string-wins by design and non-string/absent members are the benign symmetric skip (glm28-2); the object member has no reader on this wire';
            }
        }
        if (preg_match('~(^|\.)(finish_reason|stop_reason)$~', $target)) {
            if ($absent) {
                return 'GLM5 #7/GLM7 #2: an absent/null finish reason contributes nothing; the post-sentinel gap-fill only takes present non-null values';
            }
            if (in_array($kind, array_merge($transparent_kinds, array('usage-null')), true)) {
                return 'the aggregator merges finish-reason values transparently; an unknown VALUE rejects typed at the vendor parse (glm33-4\'s documented vendor throw) — value transparency at this layer';
            }
        }
        if ('member-empty-string' === $op && preg_match('~(^|\.)(name|model)$~', $target)) {
            return 'value transparency: string members merge verbatim (structure-only validation at this layer)';
        }
        if ('unknown-enum' === $op && preg_match('~(^|\.)(type)$~', $target)) {
            return 'value transparency: the payload type member has no aggregator reader on this wire (event semantics ride the event: field alone — GLM7 #18)';
        }

        return null;
    }

    /**
     * The Anthropic-surface half of the allow-list.
     *
     * @param array<string, mixed> $meta Operator metadata.
     * @return string|null
     */
    private function anthropic_tolerance_cite(array $meta)
    {
        $op = $meta['op'];
        $kind = $meta['kind'];
        $target = $meta['target'];
        $absent = 'absent' === $kind;

        if ('drop-event-line' === $op) {
            return 'the payload-type dispatch carries the frame alone when the declaration is absent (GLM1 #14/GLM5 #8) — the split-form reunion\'s tolerant half';
        }
        if ('drop-type-member' === $op && preg_match('~\.(type)$~', $target) && !preg_match('~(delta\.type|tool_calls\.\d+\.type)$~', $target)) {
            return 'a frame still DECLARING its event name needs no payload type member (the event: field governs — GLM5 #8/GLM1 #14); the typeless DATA-ONLY variant flags through glm33-1, and a stripped DELTA type must flag (Codex R4\'s delta shape rule)';
        }
        if ('drop-data-line' === $op && preg_match('~^(ping|telemetry|message_start|message_delta|message_stop|future_banana)~', $target)) {
            return 'GLM8 #1: data-less lifecycle and ping frames are ignorable (a lost lifecycle event is caught by the aggregated() absence guards in the same channel)';
        }
        if ('drop-data-line' === $op && preg_match('~^content_block~', $target)) {
            return null; // The glm23-1 one-frame window must flag (next frame or EOF settles it).
        }
        if ('unknown-event-agreed' === $op) {
            return 'GLM7 #18/GLM1 #14: unknown agreed event names are ignorable forward-compatible noise';
        }
        if (in_array($op, array('unknown-enum', 'member-empty-string'), true) && preg_match('~delta\.type$~', $target)) {
            // Adjudicated proph: the empty string names no delta type —
            // the same forward-compat tolerance an unknown string type
            // gets (is_string passes; the switch's no-case fallthrough).
            return 'glm13-4/glm15-15: unknown delta types — the empty string included — on a STARTED block keep the forward-compatible tolerance (no block-type claim to violate)';
        }
        if (in_array($op, array('unknown-enum', 'member-empty-string'), true) && preg_match('~content_block\.type$~', $target)) {
            // Adjudicated proph: the empty string names no block type —
            // the same streamed drop an unknown type string gets.
            return 'GLM1 #15/glm26-2/glm34-8: the streamed path drops block types unknown to both switches — the empty string included (the documented streamed-drop vs body-reject divergence)';
        }
        if (preg_match('~(^|\.)(stop_reason)$~', $target) && in_array($kind, array('enum', 'empty', 'null'), true)) {
            // Adjudicated proph: GLM9 #1's reception semantics latch ANY
            // string (including '') and the schema's own explicit null
            // (GLM8 #4); unmapped string values reject typed at the
            // model's finish_reason_for() (glm33-4's conscious accept).
            return 'glm33-4/GLM9 #1/GLM8 #4: the aggregator latches ANY string stop_reason and the schema-legal EXPLICIT NULL (reception semantics); unmapped string values — the empty string included — reject typed at the model\'s finish_reason_for()';
        }
        if (preg_match('~message\.(id|model)$~', $target)) {
            return 'string-gated envelope capture degrades to the documented defaults (GLM1 #9 envelope parity; glm28-2\'s benign-symmetric-skip twin)';
        }
        if (preg_match('~\.message$~', $target) && 'object' === $kind) {
            // Adjudicated proph: {} IS a message object (Codex R12 #1's
            // rule) whose every member is absent — each absent half is
            // individually a documented tolerance (R6 #2's assistant
            // default, R14 #1's absent type, GLM6 #4/glm34-5's absent
            // usage, GLM1 #9's degraded id/model), so the composite is
            // the glm34-5 all-absent-members adjudication.
            return 'the EMPTY message object is the composite of individually documented absent-member tolerances (Codex R12 #1 accepts any object; role/type/usage/id/model absent halves are each pinned — glm34-5\'s adjudication pattern)';
        }
        if (preg_match('~\.delta\.stop_sequence$~', $target)) {
            return 'stop_sequence is optional string-gated metadata (aggregated()\'s is_string gate); absent or non-string degrades to null';
        }
        if (preg_match('~\.usage$~', $target) && ($absent || 'object' === $kind)) {
            // Adjudicated proph: {} is the null-usage normalization shape
            // gateways emit (GLM12 #9) — every member absent, zeroed
            // accounting, glm34-5's conscious accept; a PRESENT corrupt
            // (string/int/list/null) member still rejects through the
            // validator.
            return 'GLM12 #9/glm34-5: an ABSENT — or EMPTY-OBJECT {} — usage member is the documented zeroed-accounting tolerance (the null-usage normalization gateway shape); a present corrupt one rejects through the shared validator';
        }
        if (preg_match('~\.usage\.[^.]+$~', $target) && in_array($kind, array('usage-int', 'int'), true)) {
            // Adjudicated proph: a non-negative integer member is a
            // VALID provider-reported count — the validator judges
            // type and non-negativity (Codex R15 #1), never values.
            return 'token-count VALUES are transparent (Codex R15 #1\'s validator judges type and non-negativity, never the count a provider reports)';
        }
        if (preg_match('~\.usage\.[^.]+$~', $target) && 'absent' === $kind) {
            // Adjudicated proph: absent usage-object members default to
            // zero through the validator's documented tolerance (an
            // explicitly-null member is PRESENT and rejects — only the
            // absent half is tolerated here).
            return 'absent usage-object members default to zero (the validator\'s documented default-zero tolerance — GLM4 #11; glm34-5\'s zeroed-accounting conscious accept covers the shape)';
        }
        if (preg_match('~content_block\.(id|name)$~', $target)) {
            if ($absent || 'null' === $kind) {
                return 'glm29-4: absent identity members keep the silent skip (the payload carries null and the model\'s identity rejection owns the shape — Codex R9 #3)';
            }
            if ('empty' === $kind) {
                return 'an empty identity string is a string value (transparency); the model\'s identity rejection owns empty identities downstream (Codex R9 #3)';
            }
        }
        if (preg_match('~content_block\.input$~', $target) && in_array($kind, array('object', 'enum'), true)) {
            return 'glm31-2/glm24-4: tool-argument VALUES are transparent (structure-only: object-ness, decodability, replayability); {} is the legitimate empty placeholder the streaming wire documents';
        }
        if (preg_match('~content_block\.(text|thinking)$~', $target) && 'empty' === $kind) {
            return 'an empty content member is a legal string value (structure-only validation; Codex R13 #3 judges presence and string-ness)';
        }
        if (preg_match('~partial_json$~', $target) && 'empty' === $kind) {
            return 'glm31-2: an empty-string fragment accumulates nothing and the start input stands (the pre-round no-op pin)';
        }
        if (preg_match('~\.delta\.(text|thinking)$~', $target) && 'empty' === $kind) {
            return 'the empty-string fragment appends nothing (glm32-1\'s unconditional-append discipline)';
        }

        return null;
    }

    /*
     * ------------------------------------------------------------------
     * Member-path helpers.
     * ------------------------------------------------------------------
     */

    /**
     * Reads one member path from a payload tree.
     *
     * @param mixed $data Payload tree.
     * @param list<string|int> $path Member path.
     * @param int $depth Walk depth.
     * @return mixed The value, or the "\0absent" sentinel.
     */
    private function get_member_path($data, array $path, $depth = 0)
    {
        if ($depth === count($path)) {
            return $data;
        }
        $key = $path[$depth];
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return "\0absent";
        }

        return $this->get_member_path($data[$key], $path, $depth + 1);
    }

    /**
     * Writes one member path in a payload tree.
     *
     * @param mixed $data Payload tree.
     * @param list<string|int> $path Member path.
     * @param mixed $value Replacement value.
     * @return mixed The modified tree.
     */
    private function set_member_path($data, array $path, $value)
    {
        $this->member_path_edit($data, $path, $value, 'set');

        return $data;
    }

    /**
     * Removes one member path from a payload tree.
     *
     * @param mixed $data Payload tree.
     * @param list<string|int> $path Member path.
     * @return mixed The modified tree.
     */
    private function unset_member_path($data, array $path)
    {
        $this->member_path_edit($data, $path, null, 'unset');

        return $data;
    }

    /**
     * By-reference member-path editor. Refuses to descend through a
     * non-array node (a stdClass placeholder in the corpus): the
     * caller's render-equality check then treats the operator as
     * inapplicable at that site and re-picks.
     *
     * @param mixed $data Payload node (by reference).
     * @param list<string|int> $path Member path.
     * @param mixed $value Replacement value.
     * @param string $mode 'set' or 'unset'.
     * @return void
     */
    private function member_path_edit(&$data, array $path, $value, $mode)
    {
        $key = array_shift($path);
        if (array() === $path) {
            if ('set' === $mode) {
                $data[$key] = $value;
            } else {
                unset($data[$key]);
            }

            return;
        }

        if (!is_array($data) || !array_key_exists($key, $data) || !is_array($data[$key])) {
            return;
        }

        $this->member_path_edit($data[$key], $path, $value, $mode);
    }
}
