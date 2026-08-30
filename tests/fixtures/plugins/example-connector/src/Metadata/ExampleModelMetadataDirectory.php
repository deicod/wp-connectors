<?php
/**
 * Static model catalog for the example provider (test fixture).
 *
 * Offline by design: the fixture catalog never performs HTTP, so registration
 * and directory tests cannot touch the network.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare(strict_types=1);

namespace Deicod\WpConnectors\ExampleConnector\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

final class ExampleModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
	/**
	 * Static catalog, newest model first.
	 *
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	protected function sendListModelsRequest(): array
	{
		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);
		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption(
				OptionEnum::inputModalities(),
				array( array( ModalityEnum::text() ) )
			),
		);

		$models = array();
		foreach ( array( 'example-2', 'example-1' ) as $modelId ) {
			$models[ $modelId ] = new ModelMetadata(
				$modelId,
				'Example ' . $modelId,
				$capabilities,
				$options
			);
		}

		return $models;
	}
}
