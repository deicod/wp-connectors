<?php
/**
 * Example provider (test fixture).
 *
 * A deliberately minimal AbstractApiProvider subclass: static model catalog,
 * key-presence availability, canonical base URL. It exists so the harness can
 * prove registration timing and so the artifact builder has a realistic
 * plugin to zip, not to model production behavior.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare(strict_types=1);

namespace Deicod\WpConnectors\ExampleConnector\Provider;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Common\Exception\RuntimeException;
use Deicod\WpConnectors\ExampleConnector\Availability\ExampleProviderAvailability;
use Deicod\WpConnectors\ExampleConnector\Metadata\ExampleModelMetadataDirectory;

final class ExampleProvider extends AbstractApiProvider
{
	/**
	 * Connector ID used by core (connectors_ai_example_api_key option name).
	 */
	public const PROVIDER_ID = 'example';

	protected static function baseUrl(): string
	{
		return 'https://api.example.test/v1';
	}

	protected static function createModel(
		ModelMetadata $modelMetadata,
		ProviderMetadata $providerMetadata
	): ModelInterface {
		foreach ( $modelMetadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new \Deicod\WpConnectors\ExampleConnector\Models\ExampleTextGenerationModel(
					$modelMetadata,
					$providerMetadata
				);
			}
		}

		throw new RuntimeException(
			'Unsupported model capabilities: ' . implode( ', ', $modelMetadata->getSupportedCapabilities() )
		);
	}

	protected static function createProviderMetadata(): ProviderMetadata
	{
		return new ProviderMetadata(
			self::PROVIDER_ID,
			'Example (Test Fixture)',
			ProviderTypeEnum::cloud(),
			'https://example.test/settings/keys',
			RequestAuthenticationMethod::apiKey()
		);
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface
	{
		return new ExampleProviderAvailability();
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
	{
		return new ExampleModelMetadataDirectory();
	}
}
