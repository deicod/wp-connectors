<?php
/**
 * Shared server-sent-events field parser (round 7 cleanup, GLM7 #18).
 *
 * The event:/data: line parsing — comment and empty-line skipping, the
 * GLM5 #8 field-value whitespace rules, multi-line data joining — was
 * copy-pasted between the two aggregators' consume_frame() methods, the
 * exact duplication pattern that drifts when one copy learns a rule.
 * One parser serves both surfaces now, so a field-rule change can never
 * land on one surface only (the same single-source reason the frame
 * splitter SseFrameBuffer and the discovery cache ZaiDiscoveryCache
 * were extracted).
 *
 * Protocol rules implemented (SSE spec, as constrained by the repo's
 * review history):
 *
 * - empty lines and ':'-led comment lines (keep-alive) are ignored;
 * - a field value is everything after the first ':' — the data: value
 *   has leading SPACES stripped only (the spec's single optional space,
 *   deliberately not tabs: the historical rule both aggregators
 *   shipped), while the event: value is trimmed of spaces AND tabs with
 *   an empty result counting as ABSENT (GLM5 #8 — a spec-legal empty
 *   'event:' must not contradict the payload's type member);
 * - multiple data: lines join with "\n" (the spec's concatenation);
 * - id:/retry: and unknown field names are ignored (forward
 *   compatibility).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Parses one complete SSE frame into its field values.
 *
 * @since 0.2.0
 */
final class SseFieldParser {

	/**
	 * Parses one complete frame's field lines.
	 *
	 * @since 0.2.0
	 *
	 * @param string $frame Frame contents (without the separating blank
	 *                      line; line endings already normalized to LF by
	 *                      the shared SseFrameBuffer).
	 * @return array{event: string|null, data: string|null} The declared
	 *                          event name (null when absent or empty) and
	 *                          the joined data payload (null when the
	 *                          frame carried no data: line).
	 */
	public static function parse( string $frame ): array {
		$event_name = null;
		$data_lines = array();

		foreach ( explode( "\n", $frame ) as $line ) {
			if ( '' === $line || 0 === strpos( $line, ':' ) ) {
				// Empty line or comment (keep-alive): ignore.
				continue;
			}

			if ( 0 === strpos( $line, 'event:' ) ) {
				/*
				 * GLM5 #8: the field parser strips only spaces
				 * (ltrim(..., ' ')), so a spec-legal EMPTY 'event:' value
				 * (the payload's type member governs) and a
				 * tab-separated 'event:\t<name>' produced a name that
				 * differed from the payload's type member — tripping the
				 * Codex R7 #6 agreement rule and invalidating an
				 * otherwise valid whole stream as a malformed event
				 * frame. Field-value whitespace (spaces AND tabs, both
				 * ends) is trimmed now, and an EMPTY name counts as
				 * ABSENT (null), exactly like a frame without the field.
				 */
				$name       = trim( substr( $line, 6 ), " \t" );
				$event_name = '' === $name ? null : $name;
				continue;
			}

			if ( 0 === strpos( $line, 'data:' ) ) {
				$data_lines[] = ltrim( substr( $line, 5 ), ' ' );
				continue;
			}

			// id:/retry: and unknown fields are ignored.
		}

		return array(
			'event' => $event_name,
			'data'  => array() === $data_lines ? null : implode( "\n", $data_lines ),
		);
	}
}
