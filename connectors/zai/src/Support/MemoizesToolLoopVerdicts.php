<?php
/**
 * Identity-keyed tool-loop memos — the one owner both surface models
 * ride (glm23-14).
 *
 * The machinery (the three SplObjectStorage stores, the build-set
 * noting, the post-build sweep, the replay-verdict memo) was
 * byte-identical between ZaiTextGenerationModel and
 * ZaiAnthropicTextGenerationModel (one parameter name apart), and the
 * memo-rule fixes have already had to land twice: the glm21-17
 * rotating-tail sweep and glm22-3's whole re-landing on the port. One
 * trait owns the trio now (the parents differ, so a trait is the only
 * shared shape); every future memo-discipline fix — bounding, reset
 * semantics, rejection-never-memoizes — lands once.
 *
 * What stays PER SURFACE: the encodable halves that READ the
 * $tool_result_encode_memo store (the zai surface's
 * prove_tool_result_encodable() stores a TRUE verdict — its guard is
 * the eager glm13-11 exception; the zai_anthropic surface's
 * encoded_tool_result_response() stores the ENCODED STRING — glm16-4's
 * encode-everything convention). The store's shape (identity-keyed,
 * swept to the build set, rejections never landing) is the shared
 * discipline; the value convention is each surface's guard semantics.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Tools\DTO\FunctionCall;

/**
 * Shared tool-loop memo machinery for both surface models.
 *
 * Composed by ZaiTextGenerationModel (glm22-3/4, the port of the
 * twin's glm21-4/5/17) and ZaiAnthropicTextGenerationModel.
 *
 * @since 0.2.0
 */
trait MemoizesToolLoopVerdicts {

	/**
	 * Tool-result encodability state, keyed by DTO identity (glm21-4 on
	 * the twin, glm22-3 on the zai port).
	 *
	 * The vendor FunctionResponse DTO is immutable (private
	 * constructor-assigned properties, getters only), so the guard's
	 * verdict on its response value is a PURE function of the DTO: a
	 * K-turn tool loop that replays the conversation every request
	 * otherwise re-proves every prior tool result (often multi-KB
	 * scraped or JSON payloads) on every request build — O(K²) on the
	 * hot path. SplObjectStorage is the identity-keyed store — chosen
	 * on the former 7.4 floor and KEPT on 8.2 by decision: it is
	 * STRONG-keyed by design (the storage pins every entry until the
	 * sweep releases it — the tool_schema_memo precedent, glm16-6; a
	 * WeakMap swap would change the lifetime semantics the resets are
	 * tuned against); rejections
	 * never memoize (the guard throws before any entry lands), and the
	 * first-run proof is byte-identical. The VALUE convention is
	 * per-surface (see this trait's docblock); the build-set sweep
	 * bounds the pin at the previous and current build's tool parts —
	 * the caller's own conversation array pins those DTOs anyway, and a
	 * rehydrated (toArray()/fromArray()) conversation carries fresh
	 * instances every request and misses BY DESIGN: any value-key would
	 * have to serialize the value first — the very cost the memo exists
	 * to skip.
	 *
	 * @since 0.2.0
	 *
	 * @var \SplObjectStorage|null
	 */
	private $tool_result_encode_memo = null;

	/**
	 * Replay verdicts for caller-built tool calls, keyed by DTO identity
	 * (glm21-5 on the twin, glm22-4 on the zai port).
	 *
	 * The GLM12 #12 stamp skips inbound-accepted calls at the instanceof
	 * check; CALLER-built plain SDK instances (and every rehydrated
	 * toArray()/fromArray() conversation — the stamp does not survive
	 * the vendor round trip) otherwise re-run the full
	 * ToolArgsReplayGuard oracle (encode + decode + re-encode + walker,
	 * ~3 whole-argument serializations per historical call) on every
	 * request for all history: O(K²) over a conversation. The vendor
	 * FunctionCall DTO is immutable (getters only), so the verdict is a
	 * pure function of the DTO: a PASSED oracle memoizes true;
	 * rejections never memoize (the glm16-6 discipline — an unplayable
	 * DTO re-proves and re-rejects identically on every build). The
	 * build-set sweep bounds the pin the same way
	 * $tool_result_encode_memo's does.
	 *
	 * @since 0.2.0
	 *
	 * @var \SplObjectStorage|null
	 */
	private $tool_call_replay_memo = null;

	/**
	 * The tool DTOs the CURRENT request build has mapped — the build set
	 * the tool-loop memos are pruned to (glm21-4/glm21-17 on the twin,
	 * glm22-3's port).
	 *
	 * Re-armed (null) at every params build and noted at each tool DTO
	 * the mapping walk visits (exactly the DTOs the surface's message
	 * mapping maps); once the build's params are prepared,
	 * prune_tool_loop_memos() detaches every memo entry whose DTO this
	 * build did NOT map. The bound is thereby structural — after every
	 * completed tool-bearing build the memos hold at most THAT build's
	 * tool parts (during a build, at most the previous and the current
	 * build's), the glm16-6 "at most the current set" discipline. This
	 * closes the rotating-tail shape — builds sharing one tool DTO
	 * while replacing later ones would otherwise accumulate every
	 * superseded entry. A tool-less build contributes nothing and prunes
	 * nothing (the set stays null): an interleaved plain generation does
	 * not shed a tool loop's entries, and the last tool-bearing build's
	 * bound stands. A build that throws mid-walk never prunes: released
	 * or stale entries only ever cost a re-derivation — entries are pure
	 * derivations of their DTOs, never wrong for them.
	 *
	 * @since 0.2.0
	 *
	 * @var \SplObjectStorage|null
	 */
	private $tool_loop_build_dto = null;

	/**
	 * Notes one tool DTO the current build maps, into the build set the
	 * tool-loop memos are pruned to.
	 *
	 * @since 0.2.0
	 *
	 * @param object $tool_dto The FunctionCall/FunctionResponse being mapped.
	 * @return void
	 */
	private function note_tool_loop_dto( object $tool_dto ): void {
		if ( null === $this->tool_loop_build_dto ) {
			$this->tool_loop_build_dto = new \SplObjectStorage();
		}

		$this->tool_loop_build_dto->offsetSet( $tool_dto, true );
	}

	/**
	 * Detaches every tool-loop memo entry whose DTO the completed build
	 * did not map (glm21-17's sweep; glm22-3's port).
	 *
	 * Runs once per completed request build (prepareGenerateTextParams,
	 * after the params are prepared): the memos may hold at most the
	 * PREVIOUS and the CURRENT build's tool parts, so the rotating-tail
	 * shape — builds keeping one tool DTO while replacing later ones —
	 * never accumulates every superseded entry (the verifier-reproduced
	 * hole in the anchor-based release glm21-4 shipped). Entries are
	 * pure derivations of their DTOs, so a detach only ever costs a
	 * re-derivation on a later build.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function prune_tool_loop_memos(): void {
		$build_set = $this->tool_loop_build_dto;

		if ( null !== $build_set ) {
			foreach ( array( $this->tool_result_encode_memo, $this->tool_call_replay_memo ) as $storage ) {
				if ( null === $storage ) {
					continue;
				}

				$detached = array();
				foreach ( $storage as $dto ) {
					if ( ! $build_set->offsetExists( $dto ) ) {
						$detached[] = $dto;
					}
				}

				foreach ( $detached as $dto ) {
					$storage->offsetUnset( $dto );
				}
			}
		}

		$this->tool_loop_build_dto = null;
	}

	/**
	 * The replay verdict for one caller-built tool call, memoized by DTO
	 * identity (glm21-5 on the twin, glm22-4's port).
	 *
	 * Same contract as the ToolArgsReplayGuard::is_replayable() call it
	 * wraps — identical verdicts, identical typed rejection at the call
	 * site — minus the repeat: a conversation replaying the same DTO
	 * instances reads the verdict instead of re-running the
	 * encode/decode/re-encode/walker oracle for every historical call
	 * on every request build. Rejections return false WITHOUT
	 * memoizing, so an unplayable DTO re-proves on every build.
	 *
	 * @since 0.2.0
	 *
	 * @param FunctionCall $function_call The caller-built call being mapped.
	 * @param mixed        $args          Its raw/normalized tool arguments.
	 * @return bool True when the arguments replay losslessly.
	 */
	private function replayable_tool_call( FunctionCall $function_call, $args ): bool {
		if ( null !== $this->tool_call_replay_memo && $this->tool_call_replay_memo->offsetExists( $function_call ) ) {
			return true;
		}

		if ( ! ToolArgsReplayGuard::is_replayable( $args ) ) {
			return false;
		}

		if ( null === $this->tool_call_replay_memo ) {
			$this->tool_call_replay_memo = new \SplObjectStorage();
		}

		$this->tool_call_replay_memo->offsetSet( $function_call, true );

		return true;
	}
}
