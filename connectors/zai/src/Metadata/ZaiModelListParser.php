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

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use Deicod\WpConnectors\Zai\Support\SseFrameBuffer;

/**
 * Parses a /models discovery response into chat-capable, in-plan IDs.
 *
 * @since 0.2.0
 */
final class ZaiModelListParser {

	/**
	 * Entry-failure reasons (glm14-7): typed constants, not bare strings —
	 * the rejection map below keys on them, so a reason added without its
	 * rejection mapping fails LOUDLY instead of silently degrading to the
	 * missing-data shape the old switch's default arm produced.
	 *
	 * @since 0.2.0
	 */
	private const ENTRY_MISSING_DATA     = 'missing_data';
	private const ENTRY_NOT_A_LIST       = 'not_a_list';
	private const ENTRY_ADDITIONAL_PAGES = 'additional_pages';
	private const ENTRY_EMPTY_LIST       = 'empty_list';
	private const ENTRY_ENTRY_ID         = 'entry_id';

	/**
	 * The ONE rejection mapping for the entry-failure reasons: the precise
	 * message each reason rejects with, or null for the two no-usable-data
	 * rejections that throw fromMissingData() exactly like the missing
	 * member always did. entry_rejection() consults this map alone — the
	 * reason set and the rejection set can never drift apart silently
	 * (glm14-7).
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string|null>
	 */
	private const ENTRY_REJECTION_MESSAGES = array(
		self::ENTRY_MISSING_DATA     => null,
		self::ENTRY_EMPTY_LIST       => null,
		self::ENTRY_NOT_A_LIST       => 'The discovered model list must be a JSON list.',
		self::ENTRY_ADDITIONAL_PAGES => 'The discovered model list reported additional pages.',
		self::ENTRY_ENTRY_ID         => 'Every entry must carry a non-empty string "id".',
	);

	/**
	 * Parses a model-list response into chat-capable, in-plan model IDs.
	 *
	 * Both common list shapes carry data[].id entries, so the parser accepts
	 * the Anthropic shape (data + display_name/created_at) and the OpenAI
	 * shape (data + object/created/owned_by) alike. Any malformed shape, an
	 * incomplete page, or a list with no usable chat ID throws — the caller
	 * turns that into the plan fallback.
	 *
	 * Checks, in order (one BOM-safe object-view decode of the raw body,
	 * GLM10 #3 — a gateway-prepended UTF-8 BOM must degrade nothing):
	 * - the data member exists and is a JSON LIST (an object-shaped
	 *   catalog decodes to stdClass, not a PHP list — the object-ness
	 *   oracle rejects it; Codex R14 #4),
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
	 * @param string   $provider_label The consuming surface's provider label
	 *                           (its availability owner's REFUSAL_LABEL —
	 *                           GLM10 #9 verifier round: the parser is
	 *                           shared by both surfaces, so each names
	 *                           itself in the rejection messages it gets).
	 * @return list<string> Chat-capable, in-plan model IDs.
	 * @throws ResponseException When the response shape is malformed,
	 *                           reports additional pages, or no usable chat
	 *                           ID remains.
	 */
	public static function parse_chat_ids( Response $response, string $plan, string $provider_label ): array {
		/*
		 * GLM10 #3: the parser owns ONE BOM-SAFE decode of the raw body.
		 * The SDK getData()'s bare json_decode() (and the previous second
		 * raw decode here) failed wholesale on the UTF-8 BOM a gateway or
		 * CDN can prepend to JSON bodies — the documented threat class the
		 * SSE and Messages/completions paths already harden against
		 * (GLM8-2/3, GLM9-3) — so dynamic discovery silently degraded to
		 * the 60s '_miss' marker plus static plan fallback on every
		 * request. The prefix rides the shared SseFrameBuffer rule, and
		 * the single object-view decode serves both the shape checks and
		 * the reads (the GLM9 #15 object-tree idiom).
		 */
		return self::parse_decoded_chat_ids(
			self::decode_models_body( $response ),
			$plan,
			$provider_label
		);
	}

	/**
	 * The ONE BOM-safe object-view decode of a models body (glm13-3).
	 *
	 * The availability probe decodes the SAME body its verdict and the
	 * discovery seed consume; this shared decode keeps the strip + decode
	 * at one site so the verdict path and the seed path can never see (or
	 * pay for) two different decodes of one response.
	 *
	 * @since 0.2.0
	 *
	 * @param Response $response The /models response.
	 * @return mixed The decoded body (stdClass, scalar, or null).
	 */
	public static function decode_models_body( Response $response ) {
		return json_decode( SseFrameBuffer::strip_stream_prefix( (string) $response->getBody() ) );
	}

	/**
	 * Runs the model-list parser over an ALREADY-DECODED body (glm13-3).
	 *
	 * Same contract as parse_chat_ids(), minus the decode — the caller
	 * (the availability probe's discovery seed) holds the pre-decoded
	 * tree from the same response.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $raw            The decoded response body.
	 * @param string $plan           The active plan ('coding' or 'general').
	 * @param string $provider_label The consuming surface's provider label.
	 * @return list<string> Chat-capable, in-plan model IDs.
	 * @throws ResponseException When the entry shape is not a model list
	 *                           or no usable chat ID remains after the
	 *                           catalog read.
	 */
	public static function parse_decoded_chat_ids( $raw, string $plan, string $provider_label ): array {
		$reason = self::entry_failure_reason( $raw );

		if ( null !== $reason ) {
			/*
			 * glm14-7: the rejection rides the ONE reason→message map —
			 * the switch's default arm used to fold any future or renamed
			 * reason silently into the missing-data shape.
			 */
			self::entry_rejection( $reason, $provider_label ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed messages by design; the label is the caller's surface constant (GLM10 #9).
		}

		$ids = array();
		foreach ( $raw->data as $entry ) {
			$ids[] = $entry->id;
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
			throw ResponseException::fromMissingData( $provider_label, 'data' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design; the label is the caller's surface constant (GLM10 #9).
		}

		return $chat_ids;
	}

	/**
	 * The typed rejection for an entry-failure reason (glm14-7 — the
	 * method entry_failure_reason()'s docblock has referenced by name
	 * since glm13-2; it exists now).
	 *
	 * The map is the single source: a mapped null message rejects
	 * fromMissingData() exactly like the missing member always did; an
	 * UNMAPPED reason — one a future edit of entry_failure_reason()
	 * returns without extending ENTRY_REJECTION_MESSAGES — throws the
	 * internal lockstep RuntimeException instead of silently degrading
	 * to the generic missing-data shape the old switch's default arm
	 * produced. The runtime failure is the honest outcome: reason and
	 * rejection disagreeing is a bug, not a data condition.
	 *
	 * @since 0.2.0
	 *
	 * @param string $reason         An entry-failure reason constant.
	 * @param string $provider_label The consuming surface's provider label.
	 * @return void
	 * @throws ResponseException The reason's mapped rejection — every
	 *                           declared reason carries one.
	 * @throws RuntimeException When the reason carries no rejection
	 *                          mapping.
	 */
	private static function entry_rejection( string $reason, string $provider_label ): void {
		if ( ! \array_key_exists( $reason, self::ENTRY_REJECTION_MESSAGES ) ) {
			throw new RuntimeException( 'Unmapped model-list entry reason: ' . $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a developer-facing lockstep invariant naming a class constant, never response data.
		}

		$message = self::ENTRY_REJECTION_MESSAGES[ $reason ];

		if ( null === $message ) {
			// The empty list rejects exactly like the missing member
			// always did: no usable data.
			throw ResponseException::fromMissingData( $provider_label, 'data' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design; the label is the caller's surface constant (GLM10 #9).
		}

		throw ResponseException::fromInvalidData( $provider_label, 'data', $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design; the label is the caller's surface constant (GLM10 #9).
	}

	/**
	 * The model-list ENTRY failure of a decoded body, or null when the
	 * body carries the model-list shape (glm13-2).
	 *
	 * ONE rule TWO consumers ride — the discovery parser (throws the
	 * per-reason rejection through entry_rejection()) and the
	 * availability verdict predicate (an entry failure means the body is
	 * no VALID verdict evidence and falls through to the inconclusive
	 * rules). The verdict's private shape-check copy had already
	 * diverged: its vacuous foreach over zero entries accepted an EMPTY
	 * data list the parser rejects, persisting VERDICT_VALID for a body
	 * carrying no authentication proof.
	 *
	 * The rule is everything the parser checks BEFORE the catalog
	 * concerns (chat filter, plan intersection, non-empty survivors) —
	 * those stay parse-only: a body that authenticated is valid verdict
	 * evidence even when its catalog intersection is empty. This
	 * supersedes the verdict's earlier tolerance of a has_more body
	 * (an incomplete page is not a catalog, Codex R15 #3, so it is not
	 * the models-list shape either): such a body is INCONCLUSIVE for the
	 * credential now — never a valid verdict, never an unproven invalid
	 * one.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $raw Decoded response body.
	 * @return string|null Failure reason (an ENTRY_* constant), or null
	 *                     for the model-list shape.
	 */
	public static function entry_failure_reason( $raw ): ?string {
		if ( ! \is_object( $raw ) || ! isset( $raw->data ) ) {
			return self::ENTRY_MISSING_DATA;
		}

		/*
		 * Object-ness oracle (Codex R14 #4): only a JSON array decodes
		 * to a PHP list; an object-shaped {"data":{"only":{"id":...}}}
		 * decodes to stdClass here, and iterating the object's VALUES as
		 * entries would treat a malformed catalog as successful live
		 * discovery and cache it.
		 */
		if ( ! \is_array( $raw->data ) ) {
			return self::ENTRY_NOT_A_LIST;
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
		if ( \property_exists( $raw, 'has_more' ) && false !== $raw->has_more ) {
			return self::ENTRY_ADDITIONAL_PAGES;
		}

		/*
		 * glm13-2: a data list with ZERO entries is not the models-list
		 * shape either — the parser's catalog stage rejects it (no usable
		 * chat ID), and a body carrying no entries carries no
		 * authentication proof worth a VALID verdict.
		 */
		if ( array() === $raw->data ) {
			return self::ENTRY_EMPTY_LIST;
		}

		foreach ( $raw->data as $entry ) {
			if ( ! \is_object( $entry ) || ! isset( $entry->id ) || ! \is_string( $entry->id ) || '' === $entry->id ) {
				return self::ENTRY_ENTRY_ID;
			}
		}

		return null;
	}
}
