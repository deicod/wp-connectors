<?php
/**
 * The JSON-output guidance builder stash — the one owner both surface
 * models ride (glm28-17).
 *
 * The nullable builder property and the lazy-init block were
 * byte-identical between ZaiTextGenerationModel and
 * ZaiAnthropicTextGenerationModel (the parents differ, so a trait is
 * the only shared shape — the StashesGenerationPrompt precedent). A
 * builder-lifecycle change (a constructor argument, a per-surface
 * memo rule) had to land twice, the twin-drift class the stash traits
 * were created to stop. One trait owns the lazy builder now; each
 * surface keeps only its own guidance consult (the per-surface
 * PROVIDER_LABEL and the embed/return shape stay in the models, the
 * glm23-14 per-surface-halves discipline).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Lazily builds and holds the one per-model JsonOutputGuidance
 * instance (glm23-2: the memo state is per-model by design).
 *
 * Composed by ZaiTextGenerationModel and
 * ZaiAnthropicTextGenerationModel.
 *
 * @since 0.2.0
 */
trait BuildsJsonOutputGuidance {

	/**
	 * The shared JSON-output guidance builder, or null before the
	 * first guidance build — the lazy null keeps unwired instances
	 * allocation-free.
	 *
	 * @since 0.2.0
	 *
	 * @var JsonOutputGuidance|null
	 */
	private $json_output_guidance_builder = null;

	/**
	 * The shared guidance builder, built on first consult.
	 *
	 * @since 0.2.0
	 *
	 * @return JsonOutputGuidance The per-model builder (the glm21-7/16
	 *                            memoized schema encode rides it).
	 */
	private function json_output_guidance_builder(): JsonOutputGuidance {
		if ( null === $this->json_output_guidance_builder ) {
			$this->json_output_guidance_builder = new JsonOutputGuidance();
		}

		return $this->json_output_guidance_builder;
	}
}
