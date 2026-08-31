<?php
/**
 * Example text generation model (test fixture).
 *
 * Carries the SDK model plumbing (final constructor, config handling) without
 * performing requests.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare(strict_types=1);

namespace Deicod\WpConnectors\ExampleConnector\Models;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

final class ExampleTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
	/**
	 * The fixture model never generates text.
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt Prompt messages.
	 * @return GenerativeAiResult
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult
	{
		throw new RuntimeException(
			'The example fixture model cannot generate text; it exists only for registration and packaging tests.'
		);
	}
}
