<?php
/**
 * Shared /models list parsing for both z.ai surfaces (code-review GLM1 #11).
 *
 * ~100 lines of response parsing were duplicated between the OpenAI-compat
 * and Anthropic directories and had already drifted twice: the R15 has_more
 * rejection and the R3 #4 plan-intersection existed only on the Anthropic
 * side, so the zai surface could advertise general-only models on the
 * coding plan while zai_anthropic could not. BOTH behaviors now live here
 * once and apply to BOTH surfaces — every future parser hardening lands in
 * one place.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;

/**
 * Parses a /models discovery response into chat-capable, in-plan IDs.
 *
 * @since 0.2.0
 */
final class ZaiModelListParser {

	/**
	 * Parses a model-list response into chat-capable, in-plan model IDs.
	 *
	 * Both common list shapes carry data[].id entries, so the parser accepts
	 * the Anthropic shape (data + display_name/created_at) and the OpenAI
	 * shape (data + object/created/owned_by) alike. Any malformed shape, an
	 * incomplete page, or a list with no usable chat ID throws — the caller
	 * turns that into the plan fallback.
	 *
	 * Checks, in order:
	 * - the data member exists and is a JSON LIST (the associative decode
	 *   collapses an object-shaped catalog into a passing PHP array — the
	 *   raw object-ness oracle rejects it; Codex R14 #4),
	 * - has_more is absent or exactly false (a present non-false value —
	 *   string "true", 1, null — is not the documented shape and fails the
	 *   same way; Codex R15 #3, now on BOTH surfaces via GLM1 #11),
	 * - every entry carries a non-empty string id,
	 * - IDs without known chat support are dropped BEFORE anything is
	 *   cached (advertising them would route them to a chat route they
	 *   cannot work on; chat-support evidence lives in ZaiModelCatalog),
	 * - the survivors are intersected with the ACTIVE plan's catalog
	 *   (Codex R3 #4, now on BOTH surfaces via GLM1 #11): the coding
	 *   subscription exposes only its restricted model set even though the
	 *   live route returns the full list.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The /models or /v1/models response.
	 * @param string   $plan     The active plan ('coding' or 'general').
	 * @return list<string> Chat-capable, in-plan model IDs.
	 * @throws ResponseException When the response shape is malformed,
	 *                           reports additional pages, or no usable chat
	 *                           ID remains.
	 */
	public static function parse_chat_ids( Response $response, string $plan ): array {
		$data = $response->getData();

		if ( ! \is_array( $data ) || ! isset( $data['data'] ) || ! \is_array( $data['data'] ) ) {
			throw ResponseException::fromMissingData( 'z.ai', 'data' );
		}

		/*
		 * Object-ness oracle (Codex R14 #4): the associative decode
		 * collapses an object-shaped {"data":{"only":{"id":...}}} into a
		 * PHP array that passes is_array(), and the foreach then iterates
		 * the object's VALUES as entries — a malformed catalog was treated
		 * as successful live discovery and cached. Only a JSON array
		 * decodes to a PHP list; an object decodes to stdClass.
		 */
		$raw_body = json_decode( (string) $response->getBody() );
		if ( ! \is_object( $raw_body ) || ! isset( $raw_body->data ) || ! \is_array( $raw_body->data ) ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'data', 'The discovered model list must be a JSON list.' );
		}

		/*
		 * Codex R15 #3 — decision: treat an incomplete page as discovery
		 * FAILURE (option a) rather than following the after_id cursor. A
		 * partial catalog with has_more: true would otherwise be cached,
		 * freezing the directory to one page and dropping known in-plan
		 * models; cursor-following would add a transport loop to a
		 * connector whose discovery is opportunistic (the static plan
		 * catalog is authoritative), so the strict path is to fall back to
		 * it — the page says it is incomplete, therefore it is not a
		 * catalog. STRICT bool: a present has_more that is not exactly
		 * false (string "true", 1, null) is not the documented shape and
		 * fails the same way. Shared by BOTH surfaces since GLM1 #11.
		 */
		if ( \array_key_exists( 'has_more', $data ) && false !== $data['has_more'] ) {
			throw ResponseException::fromInvalidData( 'z.ai', 'data', 'The discovered model list reported additional pages.' );
		}

		$ids = array();
		foreach ( $data['data'] as $entry ) {
			if ( ! \is_array( $entry ) || ! isset( $entry['id'] ) || ! \is_string( $entry['id'] ) || '' === $entry['id'] ) {
				throw ResponseException::fromInvalidData( 'z.ai', 'data', 'Every entry must carry a non-empty string "id".' );
			}

			$ids[] = $entry['id'];
		}

		$chat_ids = array();
		foreach ( $ids as $id ) {
			if ( ZaiModelCatalog::is_chat_model( $id ) ) {
				$chat_ids[] = $id;
			}
		}

		/*
		 * Codex R3 #4, shared by BOTH surfaces since GLM1 #11: the live
		 * /models route returns the SAME full-catalog list on the coding
		 * plan, but the coding subscription exposes only its restricted
		 * model set — discovered IDs are intersected with the ACTIVE
		 * plan's catalog before the caller caches anything. The general
		 * catalog IS the full observed list, so the general plan's
		 * behavior is unchanged.
		 */
		$chat_ids = array_values( array_intersect( $chat_ids, ZaiModelCatalog::ids_for_plan( $plan ) ) );

		if ( array() === $chat_ids ) {
			throw ResponseException::fromMissingData( 'z.ai', 'data' );
		}

		return $chat_ids;
	}
}
