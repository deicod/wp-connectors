<?php
/**
 * Translatable-part predicate shared by both model surfaces (glm30-4).
 *
 * The message_has_translatable_part() predicate was a private
 * near-verbatim twin of itself in the two model classes, differing only
 * in the empty-text
 * clause — a future rule change (a new translatable part type, a
 * thought-channel policy change) landing on one model would make the
 * identical vendor turn parse as a successful generation on one surface
 * while the other rejects it, the parse/replay-divergence class the
 * branch's twin extractions exist to stop. One owner carries the shared
 * shape; the ONE deliberate divergence rides a parameter each model
 * passes from its own pinned policy constant.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Messages\DTO\MessagePart;

/**
 * Whether a parsed part list carries a part the surface's outbound
 * mapper would translate.
 *
 * @since 0.2.0
 */
final class TranslatableMessageParts {

	/**
	 * Whether a parsed part list carries a part the surface's OUTBOUND
	 * mapper would translate into a wire member (glm28-4).
	 *
	 * The shared shape mirrors the keep/drop decisions both surfaces'
	 * mappers agree on — thought-channel text parts drop on both;
	 * function calls translate to tool calls and the function response
	 * to the tool message on both — so the inbound parser can enforce
	 * the same contract it will be held to on replay: a turn that would
	 * map to zero wire members cannot join the conversation history.
	 *
	 * The ONE deliberate divergence rides the parameter (glm28-4's
	 * pinned tolerance): the zai (OpenAI-compatible) mapper translates
	 * every non-thought text part — an empty string included, as a wire
	 * text entry — while the zai_anthropic mapper drops empty-string
	 * text (its own block rule mirrors the drop). Each model passes its
	 * pinned policy constant; this predicate never decides the policy.
	 *
	 * @since 0.2.0
	 *
	 * @param array $parts                 The parsed parts of one turn (list of MessagePart).
	 * @param bool  $empty_text_translates The surface's pinned empty-text policy (zai: true; zai_anthropic: false).
	 * @return bool True when at least one part is translatable.
	 */
	public static function has_translatable_part( array $parts, bool $empty_text_translates ): bool {
		foreach ( $parts as $part ) {
			$type = $part->getType();

			if ( $type->isFunctionCall() || $type->isFunctionResponse() ) {
				return true;
			}

			if ( ! $type->isText() || $part->getChannel()->isThought() ) {
				continue;
			}

			if ( $empty_text_translates || '' !== (string) $part->getText() ) {
				return true;
			}
		}

		return false;
	}
}
