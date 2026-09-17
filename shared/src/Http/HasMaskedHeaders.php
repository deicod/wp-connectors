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

use RuntimeException;

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
	 * @return array<int|string, string> All-digit names surface under their PHP-canonical integer key — matching the HeaderMap owner's own shape (t31-ocr10-11).
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
	 * Safe debug rendering for the debugger channel — print_r(),
	 * var_dump(), and every debugger that walks object properties
	 * (verifier round t31-r11-5).
	 *
	 * Mirrors __toString()'s vocabulary exactly — the VO's head fields,
	 * the masked header map (HeaderMap's own __debugInfo owner), body
	 * omitted — so the string form and the dump form cannot drift.
	 * Without it the engine dumps the raw property tree.
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

	/**
	 * The serialize() channel rides the same masked view (OCR round 1,
	 * t31-ocr1-8).
	 *
	 * Un-hooked, serialize() bypasses __debugInfo() by engine design and
	 * dumps the raw property tree — the full URL with its
	 * query and userinfo, the raw Authorization/Cookie header values,
	 * the body — into every persistence or queue payload built from
	 * the VO. The payload is byte-identical in vocabulary to
	 * __debugInfo() above (the same head fields, the same masked map,
	 * the same omitted-body marker), so the string, dump, and
	 * serialize forms cannot drift. The masked view is a SNAPSHOT, not
	 * a round-trip payload: __unserialize() below refuses it — these
	 * VOs reconstruct through their constructors, never from their own
	 * safe forms.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The masked payload fields, never containing secrets.
	 */
	public function __serialize(): array {
		return array_merge(
			$this->safe_debug_head_fields(),
			array(
				'headers' => $this->header_map()->masked_headers(),
				'body'    => '[body omitted]',
			)
		);
	}

	/**
	 * A masked payload is not a reconstruction source — it refuses.
	 *
	 * These VOs are request/response snapshots; the safe forms are
	 * lossy by design (the URL loses its query and userinfo, the body
	 * is omitted), so nothing can rebuild a value object from them.
	 * unserialize() on the __serialize() payload throws instead of
	 * half-initializing typed properties against masked fields.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data The masked payload (never a source of truth).
	 * @return never
	 * @throws RuntimeException Always — the masked snapshot is not a round-trip payload.
	 */
	public function __unserialize( array $data ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the payload to the hook; the refusal is the contract, the payload is not read.
		throw new RuntimeException( 'A masked HTTP value object is a snapshot, not a round-trip payload — reconstruct through the constructor, never from a serialization of its own safe form.' );
	}

	/**
	 * The var_export() eval channel refuses the same way.
	 *
	 * The var_export() call itself dumps the raw property tree through
	 * no hook (engine design — the one channel the masking contract
	 * cannot ride, named as excluded in the consuming VOs' docblocks),
	 * but the dump it produces is executable code: evaluating it calls
	 * __set_state(), which refuses — an exported request or response
	 * never reconstructs from its own raw dump.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $properties The exported property tree.
	 * @return never
	 * @throws RuntimeException Always — the raw dump is not a reconstruction source.
	 */
	public static function __set_state( array $properties ): never { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the engine hands the export to the hook; the refusal is the contract, the tree is not read.
		throw new RuntimeException( 'A masked HTTP value object cannot be reconstructed from an exported property tree — the raw dump is display material, never a payload.' );
	}
}
