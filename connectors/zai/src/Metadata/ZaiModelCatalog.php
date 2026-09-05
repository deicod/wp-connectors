<?php
/**
 * Static GLM model catalog for z.ai (plan-partitioned).
 *
 * Maintained, versioned data — not behavior. The fallback catalogs are
 * deliberately SEPARATE per plan (SPEC §3.3): coding subscriptions expose a
 * restricted, coding-suitable model set (GLM 5.x family), while the general
 * pay-as-you-go API exposes the full catalog. A shared fallback would
 * advertise general-only models while the coding endpoint is selected.
 *
 * The observed live list (record 0006, 2026-08-30) is a point-in-time fact,
 * not a contract; dynamic discovery is the primary path and this catalog is
 * what the provider falls back to when discovery fails (401/404/malformed/
 * offline).
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Static model catalog and capability data.
 *
 * @since 0.1.0
 */
final class ZaiModelCatalog {

	/**
	 * Coding-plan fallback catalog: GLM 5.x family, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const CODING_MODELS = array(
		'glm-5.3',
		'glm-5.3-flash',
		'glm-5.2',
		'glm-5.1',
		'glm-5',
		'glm-5-turbo',
	);

	/**
	 * General-plan fallback catalog: the full observed GLM list, newest first
	 * (record 0006).
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const GENERAL_MODELS = array(
		'glm-5.3',
		'glm-5.3-flash',
		'glm-5.2',
		'glm-5.1',
		'glm-5',
		'glm-5-turbo',
		'glm-4.7',
		'glm-4.6',
		'glm-4.5',
		'glm-4.5-air',
	);

	/**
	 * Returns the fallback model IDs for a plan.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plan 'coding' or 'general'.
	 * @return list<string> Model IDs, newest first.
	 */
	public static function ids_for_plan( string $plan ): array {
		return 'general' === $plan ? self::GENERAL_MODELS : self::CODING_MODELS;
	}

	/**
	 * Curated allowlist of chat-capable IDs verified beyond the catalogs.
	 *
	 * The is_chat_model() filter admits an ID only with VERIFIED chat
	 * support — the plan forbids deriving capabilities from the family name
	 * or ID grammar alone, so a family-shaped non-chat release
	 * ('glm-6-image', 'glm-6-embedding') must never be advertised as chat. A
	 * model whose
	 * chat support is verified live (record-0006-style evidence) is added
	 * HERE and to the matching fallback catalog — never by loosening
	 * is_chat_model().
	 *
	 * Empty as of record 0006: every verified chat ID already sits in one
	 * of the two fallback catalogs.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	const VERIFIED_CHAT_MODELS = array();

	/**
	 * Whether an ID is known to be a chat-capable GLM text model.
	 *
	 * Verified evidence only: membership in the curated VERIFIED_CHAT_MODELS
	 * allowlist or one of the static catalogs (per-model evidence, record
	 * 0006). The GLM ID grammar is deliberately NOT proof of chat
	 * capability — an unverified future release ('glm-6'), an embedding
	 * model like 'embedding-3', an image model like 'cogview-4' or
	 * 'glm-6-image' must never receive chat metadata: it would be
	 * advertised as selectable and then fail at /chat/completions.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id Model ID from discovery or the catalogs.
	 * @return bool True when the ID has known chat support.
	 */
	public static function is_chat_model( string $model_id ): bool {
		return \in_array( $model_id, self::verified_chat_ids(), true );
	}

	/**
	 * Every ID with verified chat support: the curated allowlist plus both
	 * plan-partitioned fallback catalogs.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Verified chat-capable model IDs.
	 */
	private static function verified_chat_ids(): array {
		return array_merge( self::VERIFIED_CHAT_MODELS, self::CODING_MODELS, self::GENERAL_MODELS );
	}

	/**
	 * Builds the metadata for one model ID.
	 *
	 * The same conservative text-only capability set serves every admitted
	 * ID; the catalog data only refines display names. Callers must gate on
	 * is_chat_model() first so non-chat or unverified IDs never receive
	 * this chat metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id Model ID, e.g. 'glm-5.3'.
	 * @return ModelMetadata
	 */
	public static function metadata_for( string $model_id ): ModelMetadata {
		return new ModelMetadata(
			$model_id,
			self::display_name( $model_id ),
			array(
				CapabilityEnum::textGeneration(),
				CapabilityEnum::chatHistory(),
			),
			self::supported_options()
		);
	}

	/**
	 * The supported-option set advertised for GLM text models (v1).
	 *
	 * Text input and text output only: no image/document modality is claimed
	 * anywhere — no model-specific evidence exists (SPEC §3.3, record 0006
	 * note 4). outputModalities MUST be advertised: the SDK's prompt builders
	 * (including core's wp_ai_client_prompt()) set an output modality before
	 * generation and require it in model resolution — a catalog without it
	 * matches no model at all through that path.
	 *
	 * @since 0.1.0
	 *
	 * @return list<SupportedOption>
	 */
	public static function supported_options(): array {
		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption(
				OptionEnum::outputMimeType(),
				array( 'text/plain', 'application/json' )
			),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption(
				OptionEnum::inputModalities(),
				array( array( ModalityEnum::text() ) )
			),
			new SupportedOption(
				OptionEnum::outputModalities(),
				array( array( ModalityEnum::text() ) )
			),
		);
	}

	/**
	 * Human-readable model name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id Model ID.
	 * @return string Display name.
	 */
	public static function display_name( string $model_id ): string {
		if ( 1 === preg_match( '/^glm-([0-9.]+)(?:-([a-z0-9.-]+))?$/', $model_id, $matches ) ) {
			$name = 'GLM ' . $matches[1];
			if ( isset( $matches[2] ) ) {
				$name .= ' ' . ucwords( str_replace( '-', ' ', $matches[2] ) );
			}

			return $name;
		}

		return strtoupper( $model_id );
	}

	/**
	 * Sort callback: newest GLM models first.
	 *
	 * Higher versions first; at equal versions base models before variants
	 * (e.g. glm-5.3 before glm-5.3-flash), then variants alphabetically.
	 * Non-GLM IDs sort after all GLM IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata $a First model.
	 * @param ModelMetadata $b Second model.
	 * @return int Comparison result for usort().
	 */
	public static function sort_callback( ModelMetadata $a, ModelMetadata $b ): int {
		$a_id = $a->getId();
		$b_id = $b->getId();

		$a_version = self::glm_version( $a_id );
		$b_version = self::glm_version( $b_id );

		if ( null === $a_version || null === $b_version ) {
			// Non-GLM models after GLM models; otherwise alphabetical.
			if ( null === $a_version && null === $b_version ) {
				return strcmp( $a_id, $b_id );
			}

			return null === $a_version ? 1 : -1;
		}

		if ( $a_version !== $b_version ) {
			return version_compare( $b_version, $a_version );
		}

		$a_variant = self::glm_variant( $a_id );
		$b_variant = self::glm_variant( $b_id );

		// Base model first.
		if ( '' === $a_variant && '' !== $b_variant ) {
			return -1;
		}
		if ( '' === $b_variant && '' !== $a_variant ) {
			return 1;
		}

		return strcmp( $a_variant, $b_variant );
	}

	/**
	 * Extracts the numeric version from a GLM model ID, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id Model ID.
	 * @return string|null e.g. '5.3' for 'glm-5.3-flash'.
	 */
	private static function glm_version( string $model_id ): ?string {
		if ( 1 !== preg_match( '/^glm-([0-9]+(?:\.[0-9]+)*)/', $model_id, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Extracts the variant suffix from a GLM model ID ('' for base models).
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id Model ID.
	 * @return string e.g. 'flash' for 'glm-5.3-flash'.
	 */
	private static function glm_variant( string $model_id ): string {
		if ( 1 !== preg_match( '/^glm-[0-9.]+-([a-z0-9.-]+)$/', $model_id, $matches ) ) {
			return '';
		}

		return $matches[1];
	}
}
