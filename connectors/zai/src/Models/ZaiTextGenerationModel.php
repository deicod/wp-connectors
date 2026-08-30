<?php
/**
 * Z.ai text generation model (scaffold).
 *
 * Placeholder until Task 1.6 implements the chat-completions request mapping
 * on top of the SDK's OpenAI-compatible base class. Carries only the SDK
 * model plumbing (metadata, provider metadata, config).
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;

/**
 * Text generation model for z.ai.
 *
 * @since 0.1.0
 */
final class ZaiTextGenerationModel extends AbstractApiBasedModel {

}
