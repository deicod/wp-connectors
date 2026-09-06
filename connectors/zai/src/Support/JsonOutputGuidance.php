<?php
/**
 * JSON-output guidance builder shared by both surfaces (glm23-2).
 *
 * The Codex R1 #4 remedy — a configured outputSchema must not be
 * silently discarded into an unconstrained request — lives once here:
 * the translated guidance sentences and the glm21-7/16 memoized schema
 * encode that embeds the schema into them. Both surfaces rode private
 * copies of this machinery until glm23-2 (the twin owned all of it; the
 * zai surface owned none, which WAS the finding: a schema under a
 * non-JSON mime flew with no response_format AND no guidance — an HTTP
 * 200 of unconstrained prose where the identical config on the twin
 * produced schema-constrained output); one owner now serves both, so
 * the guidance bytes and the memo discipline cannot drift per surface.
 *
 * Which CONFIGURATION requests guidance stays per surface (the twin
 * embeds on EITHER signal — the Messages mapping has no native
 * structured-output member; the zai surface embeds only in the dropped
 * case — the SDK parent forwards response_format natively under the
 * application/json mime and glm14-1's eager guard already scopes that
 * exact case). The instance holds the memo state, so each model owns
 * its own builder.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Builds the JSON-output guidance, memoizing the schema encode.
 *
 * @since 0.2.0
 */
final class JsonOutputGuidance {

	/**
	 * The encoded outputSchema string for the CURRENT config's schema
	 * (glm21-7, extracted glm23-2).
	 *
	 * The outputSchema is a config member that never changes across a
	 * structured-output agent loop, but the guidance build re-encoded
	 * the whole (often multi-KB) schema into the system prompt on every
	 * request build — one redundant whole-schema json_encode plus
	 * sprintf per HTTP request for identical bytes on the request-build
	 * hot path. The memo holds the ENCODED STRING only, and only for
	 * OBJECT-FREE schema graphs (glm21-16): the translated guidance
	 * sentence is rebuilt per call (translations are not the memo's
	 * business), and an object-carrying graph skips the memo entirely
	 * (see $encode_memo_schema). Rejections never memoize (the guard
	 * throws before any entry lands). The reset set is the
	 * tool-schema memo's (glm16-6/16): a config identity change — the
	 * vendor base's final setConfig() can replace a live instance's
	 * config — or an in-place schema change through the vendor
	 * ModelConfig's public setOutputSchema(), detected by the strict
	 * value compare below.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $encode_memo = null;

	/**
	 * The config whose schema the memo holds (glm21-7; the memo's
	 * identity-based reset trigger — see $encode_memo).
	 *
	 * @since 0.2.0
	 *
	 * @var ModelConfig|null
	 */
	private $encode_memo_config = null;

	/**
	 * The schema value the memo was built for — the memo's value-compare
	 * reset trigger, compared STRICTLY on every build (glm21-7).
	 *
	 * The vendor ModelConfig is MUTABLE (a public setOutputSchema()),
	 * so one config object mutated in place never changes identity
	 * while its schema does; the strict value compare catches every
	 * change to an OBJECT-FREE graph (arrays are values). A graph
	 * carrying OBJECTS is NOT strictly comparable — PHP judges nested
	 * objects by identity, so an in-place mutation of one left the old
	 * and new schemas ===-equal FOREVER and the memo served the
	 * pre-mutation encoding (verifier-reproduced end to end; glm21-16)
	 * — so object-carrying schemas never memoize at all
	 * (schema_is_strict_comparable() gates the memo; they encode every
	 * build, the pre-glm21-7 behavior).
	 *
	 * @since 0.2.0
	 *
	 * @var array|null
	 */
	private $encode_memo_schema = null;

	/**
	 * The schema-free guidance sentence: constrain the response to a
	 * single JSON value.
	 *
	 * @since 0.2.0
	 *
	 * @return string The translated sentence.
	 */
	public static function base_guidance(): string {
		return __( 'Respond with a single JSON value only — no markdown fences, no commentary, no surrounding text.', 'zai' );
	}

	/**
	 * The full guidance for a configured outputSchema: the base sentence
	 * plus the schema-embed sentence carrying the memoized encoding.
	 *
	 * The list-root shape rule (glm18-6 on the twin, glm13-8 parity on
	 * zai) runs FIRST — the SDK setter accepts any array, so a
	 * LIST-root schema (['a','b'], or []) encodes fine and previously
	 * embedded as the meaningless pseudo-instruction 'JSON Schema:
	 * ["a","b"]', unconstraining the output instead of rejecting typed
	 * pre-transport like the sibling member rules. Surfaces that shape-
	 * check the schema earlier in their own validate walk (the zai
	 * surface does) pass the check again here idempotently.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $output_schema The configured output schema (an array).
	 * @param ModelConfig $config        The config the schema was read from (the
	 *                                   memo's identity reset trigger).
	 * @param string      $provider_label The consuming provider's name, for
	 *                                   rejection messages ('zai' or
	 *                                   'zai_anthropic'; GLM6 #5).
	 * @return string The guidance text embedding the encoded schema.
	 * @throws InvalidArgumentException When the schema is a JSON list or
	 *                                  cannot be JSON-encoded.
	 */
	public function schema_guidance( array $output_schema, ModelConfig $config, string $provider_label ): string {
		RequestShapeGuard::reject_list_root_output_schema( $output_schema, $provider_label );

		return self::base_guidance() . "\n" . sprintf(
			/* translators: %s: a JSON Schema document (compact JSON). */
			__( 'The JSON value must conform to this JSON Schema: %s', 'zai' ),
			$this->encoded_output_schema( $output_schema, $config, $provider_label )
		);
	}

	/**
	 * The JSON encoding of the configured outputSchema, memoized per
	 * config identity and schema value (glm21-7).
	 *
	 * Same contract as the JsonEncodeGuard::encode() call it wraps —
	 * byte-identical first-run encoding, the same typed rejection on an
	 * unencodable schema — minus the repeat, for OBJECT-FREE schema
	 * graphs only (glm21-16): the compare pair (config identity, strict
	 * schema value) re-encodes when either half changed, covering BOTH
	 * reconfiguration idioms the way the tool-schema memo's pair does
	 * (glm16-16). A graph carrying objects is not strictly comparable
	 * (nested objects compare by identity, so an in-place mutation is
	 * invisible to the reset — the verifier-reproduced staleness) and
	 * skips the memo entirely, encoding every build: the pre-glm21-7
	 * behavior, correct for every schema shape. Rejections never
	 * memoize: the guard throws before any field lands, so an
	 * unencodable schema re-proves and re-rejects identically on every
	 * build.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $output_schema The configured output schema.
	 * @param ModelConfig $config        The config the schema was read from.
	 * @param string      $provider_label The consuming provider's name.
	 * @return string The JSON encoding of the schema.
	 */
	private function encoded_output_schema( array $output_schema, ModelConfig $config, string $provider_label ): string {
		$strictly_comparable = self::schema_is_strict_comparable( $output_schema );

		if ( $strictly_comparable
			&& null !== $this->encode_memo
			&& $config === $this->encode_memo_config
			&& $output_schema === $this->encode_memo_schema ) {
			return $this->encode_memo;
		}

		/*
		 * Any previous entry describes a different config or schema
		 * value — or this schema never memoizes: clear first, so the
		 * fields state the truth (no active memo) even when the encode
		 * below rejects and never lands a new one.
		 */
		$this->encode_memo        = null;
		$this->encode_memo_config = null;
		$this->encode_memo_schema = null;

		$encoded = JsonEncodeGuard::encode( $output_schema, 'the configured output schema', $provider_label );

		if ( $strictly_comparable ) {
			$this->encode_memo        = $encoded;
			$this->encode_memo_config = $config;
			$this->encode_memo_schema = $output_schema;
		}

		return $encoded;
	}

	/**
	 * Whether the schema's value graph is safely comparable by PHP's
	 * STRICT array comparison (glm21-16).
	 *
	 * An object-free graph is: arrays are values, so every mutation —
	 * through setOutputSchema() or a direct edit of the caller's
	 * array — changes the compared value. A graph carrying OBJECT
	 * members is not (objects compare by identity, hiding in-place
	 * mutation), and neither is an absurdly deep or reference-cyclic
	 * one (the walk is depth-bounded so it cannot hang where the guard's
	 * own encode would reject the schema typed anyway): both read as
	 * NOT comparable and skip the memo.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $value One schema member (or the schema root).
	 * @param int   $depth Walk depth (bounded; internal).
	 * @return bool True when strict comparison decides every change.
	 */
	private static function schema_is_strict_comparable( $value, int $depth = 0 ): bool {
		if ( \is_object( $value ) ) {
			return false;
		}

		if ( ! \is_array( $value ) ) {
			return true;
		}

		if ( $depth >= 64 ) {
			// Too deep to walk cheaply (or cyclic through references):
			// conservatively not comparable — no memo, just encode.
			return false;
		}

		foreach ( $value as $member ) {
			if ( ! self::schema_is_strict_comparable( $member, $depth + 1 ) ) {
				return false;
			}
		}

		return true;
	}
}
