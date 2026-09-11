<?php
/**
 * The generation-prompt stash — the one owner both surface models
 * ride (glm25-3).
 *
 * The property, its assignment discipline, and the null-tolerant read
 * were byte-identical between ZaiTextGenerationModel and
 * ZaiAnthropicTextGenerationModel (the parents differ, so a trait is
 * the only shared shape — the MemoizesToolLoopVerdicts precedent).
 * Each suite pinned only its own copy, so a stash-discipline change
 * landing on one surface would silently leave the other's attribution
 * walk judging an empty array — degrading every multi-bad payload's
 * precise first-bad-member message to the generic 'a request payload
 * member' rejection. One trait owns the stash now: the assignment
 * stays at each surface's prepareGenerateTextParams() (the earliest
 * point the surface sees the prompt), and the failure closure reads
 * the one accessor.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Stashes the prompt the CURRENT in-flight request was prepared from,
 * for the encodability attribution walk.
 *
 * Composed by ZaiTextGenerationModel (glm13-11) and
 * ZaiAnthropicTextGenerationModel (glm15-5).
 *
 * @since 0.2.0
 */
trait StashesGenerationPrompt {

	/**
	 * The prompt the CURRENT in-flight request was prepared from —
	 * assigned in prepareGenerateTextParams() so the encodability
	 * attribution walk can name the first bad member when the
	 * request-build whole-payload net fails.
	 *
	 * @since 0.2.0
	 *
	 * @var array|null
	 */
	private $generation_prompt = null;

	/**
	 * The stashed prompt as the attribution walk's input: the current
	 * stash, or an empty list before the first build (or after a
	 * construction-time guard failure that never reached the stash
	 * assignment) — the walk over an empty payload finds nothing to
	 * attribute and the net's generic description stands.
	 *
	 * @since 0.2.0
	 *
	 * @return array The stashed prompt messages (list of Message).
	 */
	private function stashed_generation_prompt(): array {
		return \is_array( $this->generation_prompt ) ? $this->generation_prompt : array();
	}
}
