<?php
/**
 * The one stream-index value predicate both aggregators judge by
 * (glm21-11).
 *
 * The rule — an index a stream entry declares must be a NON-NEGATIVE
 * INTEGER; a missing, null, non-integer, or negative value is
 * corruption, never an int-coerced accumulator key — was stated twice,
 * once per protocol surface: the OpenAI-style aggregator's
 * sound_index() (the GLM7 #1 rule over the associative choice/tool-call
 * entry) and the Anthropic aggregator's raw_block_index() (the Codex
 * R6 #4 rule over the raw-decoded content event). The two statements
 * agreed only by convention, the exact one-surface-late drift class
 * the shared Support/ extraction exists to stop: an index-rule change
 * landed on one aggregator would give the two protocols different
 * corruption verdicts for the same malformed index shape. The
 * CONTAINER fetch stays per-caller (an array entry's isset() and a
 * stdClass property's property_exists() remain each surface's own
 * idiom — both funnel the same value classes here); the VALUE rule
 * lives once.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Judges one declared stream index value.
 *
 * @since 0.2.0
 */
final class StreamIndex {

	/**
	 * The non-negative integer index a stream entry declared, or null
	 * when the value is unsound.
	 *
	 * The associative decode preserves JSON int-ness, so is_int()
	 * rejects "1", 1.9, true, and null exactly like the raw-decode
	 * twin's oracle does — the callers flag the stream malformed (the
	 * silent skip lost that delta's content while the stream still
	 * reported success; the old (int) cast merged float and string
	 * indexes into the WRONG accumulator).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $index The declared index value (decoded).
	 * @return int|null The sound index, or null when the entry is corrupt.
	 */
	public static function sound( $index ): ?int {
		return \is_int( $index ) && $index >= 0 ? $index : null;
	}
}
