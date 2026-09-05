<?php
/**
 * The Anthropic Messages protocol authentication wrap, owned once
 * (glm15-8).
 *
 * The protocol wrap — funnel every wired authentication through
 * ZaiAnthropicRequestAuthentication::wrap() so each request carries
 * Bearer plus anthropic-version and never x-api-key — was re-declared
 * as a bespoke getRequestAuthentication() override in each of the
 * three SDK-interfaced classes (the model, the metadata directory, the
 * availability). A fourth class speaking the surface (embeddings,
 * count-tokens) that forgets the override silently sends plain ApiKey
 * auth: requests still succeed against z.ai — it ignores the version
 * header — while violating the never-x-api-key/proxy-ready contract,
 * so the omission fails open and undetected. Composing this trait IS
 * speaking the protocol: the wrap is structurally impossible to
 * forget, and a wrap-rule change lands once.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Authentication;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

/**
 * Protocol-wraps the composing class's request authentication.
 *
 * The composer supplies the RAW authentication through the one hook
 * (the SDK parent's getter or the aliased trait getter — the wiring
 * choice each class already owns); everything above the hook is the
 * protocol contract this trait carries.
 *
 * @since 0.2.0
 */
trait SpeaksAnthropicMessagesProtocol {

	/**
	 * The RAW wired authentication this class would authenticate with —
	 * unwrapped, exactly as the SDK stores it.
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	abstract protected function raw_request_authentication(): RequestAuthenticationInterface;

	/**
	 * Returns the wired authentication, protocol-wrapped for the
	 * Anthropic surface.
	 *
	 * The registry wires the SDK's plain ApiKeyRequestAuthentication
	 * (core's key store is protocol-agnostic); every request this class
	 * sends must carry the surface's headers instead, so the wired
	 * instance is funneled through ZaiAnthropicRequestAuthentication::
	 * wrap() — once, here.
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	public function getRequestAuthentication(): RequestAuthenticationInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		return ZaiAnthropicRequestAuthentication::wrap( $this->raw_request_authentication() );
	}

	/**
	 * The UNWIRED-probe fallback authentication, protocol-wrapped too
	 * (GLM5 #10): a database-only key must validate against the
	 * endpoint with this surface's headers, never the plain
	 * OpenAI-style auth. Overrides the availability base's plain
	 * default for every composing class.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The effective credential.
	 * @return RequestAuthenticationInterface
	 */
	protected static function fallback_authentication( string $key ): RequestAuthenticationInterface {
		return ZaiAnthropicRequestAuthentication::wrap( new ApiKeyRequestAuthentication( $key ) );
	}
}
