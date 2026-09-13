<?php
/**
 * The Anthropic Messages content-block vocabulary, stated once (glm34-8).
 *
 * Round 34 finding 8: the mapped/unmapped decision and each mapped
 * type's required string member were owned by two hand-maintained
 * switches — the body parse's parse_content_block() and the streamed
 * aggregator's content_block_payload()/has_string_content_member() —
 * with no shared table and no lockstep pin, so a new block type (or an
 * unmapped one becoming mappable) was a two-file edit with no failing
 * test if one side was missed, and the streamed and non-streamed
 * transports of one generation could silently disagree. The catalog is
 * the finish_reason_for() pattern applied to block types: the decision
 * is ONE edit here, and the lockstep pin (in
 * ZaiAnthropicResponseMappingTest) fails when either switch drifts
 * from the table.
 *
 * The unknown-type DIVERGENCE itself is unchanged and stays the
 * documented decision it has been since GLM1 #15/glm26-2: the streamed
 * path drops a type unknown to BOTH switches for forward compatibility
 * while the body parse rejects it — vendor-quirk territory neither
 * shape can reach through this connector's own requests.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Names the Anthropic content-block types and their member rules.
 *
 * @since 0.2.0
 */
final class AnthropicContentBlocks {

	/**
	 * Block types BOTH transports map (glm34-8).
	 *
	 * The aggregator's content_block_payload() builds a consolidated
	 * block for exactly these; the body parse's parse_content_block()
	 * maps exactly these. A type here without an arm in both switches
	 * fails the lockstep pin.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const MAPPED_TYPES = array(
		'text',
		'thinking',
		'tool_use',
	);

	/**
	 * Provider-internal block types BOTH transports drop (glm34-8).
	 *
	 * The KNOWN LIMITATION documented at the body parse's drop site
	 * (code-review #15): these carry no SDK representation and are
	 * silently dropped — an upstream quirk this connector cannot
	 * trigger through its own requests (client function tools only).
	 * The failure surface stays typed, never silent-empty: a body
	 * whose content is ONLY these blocks rejects through the
	 * zero-parts channel (GLM3 #2/GLM5 #4).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	const KNOWN_UNMAPPED_TYPES = array(
		'redacted_thinking',
		'server_tool_use',
		'web_search_tool_result',
	);

	/**
	 * The string content member each known content-carrying block/delta
	 * type requires (glm15-21; glm34-8 promoted from the aggregator's
	 * private map to the shared vocabulary).
	 *
	 * The 'known non-tool block requires its string content member'
	 * rule (Codex R13 #3 for block starts, the R4 verifier sweep for
	 * deltas) rides this one map for every consumer: a new block type
	 * with a content member is one entry plus one case arm, never a
	 * pasted guard that misses one site and gives starts and deltas
	 * different corruption verdicts for the same payload shape.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string> Block/delta type => required string member.
	 */
	const STRING_CONTENT_MEMBERS = array(
		'text'           => 'text',
		'thinking'       => 'thinking',
		'text_delta'     => 'text',
		'thinking_delta' => 'thinking',
	);

	/**
	 * MAPPED_TYPES as a membership SET (glm38-8): the once-built flipped
	 * derivation the aggregator's event-name membership (glm37-11) and
	 * the catalog's chat-model membership (glm26-12) already ride — the
	 * body parse consults the table per content block of every response
	 * parse, so the linear scans become one isset().
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>|null
	 */
	private static $mapped_type_set = null;

	/**
	 * KNOWN_UNMAPPED_TYPES as a membership SET (glm38-8): the same
	 * once-flipped derivation for the drop list.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>|null
	 */
	private static $unmapped_type_set = null;

	/**
	 * Whether a block type is one BOTH transports map (glm38-8).
	 *
	 * MAPPED_TYPES' membership rule on the once-flipped set — the
	 * strict in_array the rule rode before, the same derivation shape
	 * glm37-11 pinned for the aggregator's event names.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type The block type member.
	 * @return bool True when the type is a mapped content-block type.
	 */
	public static function is_mapped_type( string $type ): bool {
		if ( null === self::$mapped_type_set ) {
			self::$mapped_type_set = \array_fill_keys( self::MAPPED_TYPES, true );
		}

		return isset( self::$mapped_type_set[ $type ] );
	}

	/**
	 * Whether a block type is one BOTH transports drop (glm38-8).
	 *
	 * KNOWN_UNMAPPED_TYPES' membership rule on the once-flipped set.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type The block type member.
	 * @return bool True when the type is a known provider-internal block.
	 */
	public static function is_known_unmapped_type( string $type ): bool {
		if ( null === self::$unmapped_type_set ) {
			self::$unmapped_type_set = \array_fill_keys( self::KNOWN_UNMAPPED_TYPES, true );
		}

		return isset( self::$unmapped_type_set[ $type ] );
	}
}
