<?php
/**
 * Shared event-stream sniff for both z.ai surfaces (code-review GLM4 #3).
 *
 * A response's parser is chosen by sniff: a text/event-stream body goes to
 * the surface's SSE aggregator, everything else to the JSON parser. The
 * Content-Type header decides when the gateway sends it correctly — but
 * gateways mangle or omit it, so the body's first non-whitespace bytes are
 * the fallback signal. The GLM3 #5/#7 recognitions (a UTF-8 BOM before the
 * first field, a legal SSE comment line) lived only in the Anthropic
 * surface's inline copy of this mechanism; the OpenAI surface still
 * recognized a bare leading 'data:' line, so the exact scenario those
 * fixes cite — a mangled Content-Type plus a leading BOM or ': keepalive'
 * comment — misrouted the stream to the JSON parser and every such
 * generation died as a malformed payload. One helper, both surfaces: the
 * sniff can never drift again.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

/**
 * Decides whether a response body is a server-sent-events stream.
 *
 * @since 0.2.0
 */
final class EventStreamSniff {

	/**
	 * Whether the response is an SSE event stream.
	 *
	 * True when the Content-Type header names text/event-stream, or when
	 * the body's first non-whitespace bytes (after an optional UTF-8 BOM,
	 * which the shared SseFrameBuffer also strips) begin a field or
	 * comment only SSE framing can produce — a JSON body never starts
	 * with one. The id:/retry: fields (GLM4 #8) close the parity gap with
	 * the aggregators, which already tolerate both mid-frame: a
	 * nonconforming intermediary emitting "id: …" before the first
	 * event/data field misrouted the whole stream to the JSON parser
	 * even though every aggregator ignores those fields.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $body            The raw response body.
	 * @param string|null $content_type    The Content-Type header value, or null.
	 * @return bool True when the body should go to the SSE aggregator.
	 */
	public static function matches( string $body, ?string $content_type ): bool {
		if ( null !== $content_type && false !== stripos( $content_type, 'text/event-stream' ) ) {
			return true;
		}

		$sniff = ltrim( $body, " \t\r\n" );
		if ( 0 === strpos( $sniff, "\xEF\xBB\xBF" ) ) {
			$sniff = ltrim( substr( $sniff, 3 ), " \t\r\n" );
		}

		return 0 === strpos( $sniff, 'event:' )
			|| 0 === strpos( $sniff, 'data:' )
			|| 0 === strpos( $sniff, 'id:' )
			|| 0 === strpos( $sniff, 'retry:' )
			|| 0 === strpos( $sniff, ':' );
	}
}
