<?php
/**
 * Bearer authentication for the z.ai Anthropic-compatible surface.
 *
 * The z.ai API accepts `Authorization: Bearer <key>` on the Anthropic
 * surface (production-proven, SPEC §3.2) — the SAME key the OpenAI surface
 * uses. The `x-api-key` header real Anthropic uses is UNVERIFIED on z.ai and is
 * deliberately never sent. The `anthropic-version` header is not required by
 * z.ai either, but is sent anyway: harmless against z.ai and required should
 * the surface ever be proxied to (or replaced by) a strict Anthropic
 * implementation.
 *
 * Extends the SDK's ApiKeyRequestAuthentication so the registry's
 * apiKey()-metadata validation (instanceof check on the implementation
 * class) accepts instances unchanged; the official Anthropic provider plugin
 * uses the same shape.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Authentication;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * Bearer + protocol-version authentication for zai_anthropic.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicRequestAuthentication extends ApiKeyRequestAuthentication {

	/**
	 * The Anthropic protocol version marker z.ai expects (and ignores).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * Authenticates the request with Bearer auth and the version header.
	 *
	 * The withHeader() call replaces any existing value, so exactly ONE
	 * Authorization header (Bearer) and ONE anthropic-version header leave this method —
	 * never x-api-key, never a duplicate credential header.
	 *
	 * @since 0.2.0
	 *
	 * @param Request $request The request to authenticate.
	 * @return Request The authenticated request.
	 */
	public function authenticateRequest( Request $request ): Request {
		$request = $request->withHeader( 'anthropic-version', self::ANTHROPIC_VERSION );

		return $request->withHeader( 'Authorization', 'Bearer ' . $this->getApiKey() );
	}

	/**
	 * Wraps a wired authentication instance for the Anthropic surface.
	 *
	 * The registry wires the SDK's plain ApiKeyRequestAuthentication (core's
	 * key store does not know about protocol headers), so the zai_anthropic
	 * model, directory, and availability funnel their wired instance through
	 * here before authenticating a request. Already-wrapped instances pass
	 * through unchanged; anything else is returned as-is for the caller to
	 * handle.
	 *
	 * @since 0.2.0
	 *
	 * @param RequestAuthenticationInterface $authentication The wired authentication.
	 * @return RequestAuthenticationInterface The Anthropic-surface authentication.
	 */
	public static function wrap( RequestAuthenticationInterface $authentication ): RequestAuthenticationInterface {
		if ( $authentication instanceof self ) {
			return $authentication;
		}

		if ( $authentication instanceof ApiKeyRequestAuthentication ) {
			return new self( $authentication->getApiKey() );
		}

		return $authentication;
	}
}
