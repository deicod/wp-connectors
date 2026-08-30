<?php
/**
 * Z.ai model metadata directory (scaffold).
 *
 * Placeholder until Task 1.5 implements the dynamic /models discovery with the
 * plan-partitioned static fallback.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Model metadata directory for z.ai.
 *
 * @since 0.1.0
 */
final class ZaiModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {

	/**
	 * Returns the model catalog. Empty until Task 1.5.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	protected function sendListModelsRequest(): array {
		return array();
	}
}
