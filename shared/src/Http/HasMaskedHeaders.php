<?php
/**
 * The masked-header facade shared by the HTTP value objects (Task 3.1,
 * deduplicated review round t31-r12-13).
 *
 * HttpRequest and HttpResponse carried byte-identical twins of the
 * header facade — the headers()/header() delegations and the
 * __toString()/__debugInfo() bodies (eight methods across two files,
 * four copies of the masking plumbing after the location round) — so a
 * future edit to one side's redaction would silently drift the other's:
 * the exact seam the one-render-owner doctrine exists to close. The
 * trait is ONE implementation both consume; the only divergence kept
 * is the head each VO contributes — the request line
 * (method + redacted URL) versus the status line ('HTTP ' + status).
 *
 * The render decisions themselves stay owned where they were: WHAT is
 * sensitive and WHAT a mask looks like is SecretMask's, and every
 * rendered byte of a header line or map is HeaderMap's
 * (rendered_lines()/masked_headers(), the one header-render owner) —
 * this trait only owns the FACADE SHAPE, never a mask decision.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Http;

/**
 * The shared header facade for the HTTP value objects.
 *
 * @since 0.1.0
 */
trait HasMaskedHeaders {

	/**
	 * The VO's header map — the shared owner of validation, lookup, and
	 * render.
	 *
	 * @since 0.1.0
	 *
	 * @return HeaderMap
	 */
	abstract protected function header_map(): HeaderMap;

	/**
	 * The safe string form's head: the request line or the status line,
	 * the one line the two value objects spell differently.
	 *
	 * @since 0.1.0
	 *
	 * @return string The head line (never a secret).
	 */
	abstract protected function safe_render_head(): string;

	/**
	 * The safe debug form's head fields (before 'headers' and 'body'):
	 * the request's method and redacted URL, or the response's status.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Head fields, never containing secrets.
	 */
	abstract protected function safe_debug_head_fields(): array;

	/**
	 * Header map as constructed.
	 *
	 * LOSS, STATED HONESTLY (review round t31-r2-13, carried by the
	 * response side before the facade merged): an array-keyed header
	 * map cannot represent REPEATED header names — a provider sending
	 * the same name on multiple lines (Set-Cookie is the canonical
	 * case) collapses to the single entry whichever parser stage the
	 * binding lets win. The value objects carry one value per name by
	 * design; how a binding ought to surface repeats (first-wins
	 * documented, folded per RFC 9110 section 5.2, or a list-carrying
	 * shape) is the Task 3.7 transport binding's decision to make
	 * against a live provider — re-open this seam when that consumer
	 * exists.
	 *
	 * An all-digit header name (a legal RFC 7230 token) appears under
	 * its PHP-canonical INTEGER key — the engine coerces canonical
	 * digit-string array keys before any PHP array can carry them, so
	 * the array<string, string> return names every NON-digit name's
	 * spelling; header() and the safe debug render fold through
	 * (string) and never observe the difference (t31-r10-5: execution
	 * pins this behavior; the annotation states it now, matching the
	 * HeaderMap owner's own docblock).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->header_map()->headers();
	}

	/**
	 * One header value, looked up case-insensitively.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name (any case).
	 * @return string|null The value, or null when absent.
	 */
	public function header( string $name ): ?string {
		return $this->header_map()->header( $name );
	}

	/**
	 * Safe debug rendering — never contains secrets.
	 *
	 * The head the VO contributes (request line or status line), then
	 * HeaderMap's own masked render of every header line, then the
	 * omitted-body marker.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function __toString(): string {
		$lines   = array_merge( array( $this->safe_render_head() ), $this->header_map()->rendered_lines() );
		$lines[] = '[body omitted]';

		return implode( "\n", $lines );
	}

	/**
	 * Safe debug rendering for the serialization channel — print_r(),
	 * var_dump(), and every debugger that walks object properties
	 * (verifier round t31-r11-5).
	 *
	 * Mirrors __toString()'s vocabulary exactly — the VO's head fields,
	 * the masked header map (HeaderMap's own __debugInfo owner), body
	 * omitted — so the string form and the serialized form cannot
	 * drift. Without it the engine dumps the raw property tree.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked debug fields, never containing secrets.
	 */
	public function __debugInfo(): array {
		return array_merge(
			$this->safe_debug_head_fields(),
			array(
				'headers' => $this->header_map()->masked_headers(),
				'body'    => '[body omitted]',
			)
		);
	}
}
