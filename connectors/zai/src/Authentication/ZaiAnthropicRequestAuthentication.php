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

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

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
	 * Any pre-existing x-api-key header is REMOVED first (Codex R4 #5): a
	 * reused or decorated request could otherwise carry a stale second
	 * credential alongside the Bearer key, violating this class's
	 * never-x-api-key contract. The withHeader() calls replace existing
	 * values, so exactly ONE Authorization header (Bearer) and ONE
	 * anthropic-version header leave this method.
	 *
	 * @since 0.2.0
	 *
	 * @param Request $request The request to authenticate.
	 * @return Request The authenticated request.
	 */
	public function authenticateRequest( Request $request ): Request {
		$key = $this->getApiKey();
		self::reject_uncarriable_credential( $key );

		$request = self::without_x_api_key( $request );
		$request = $request->withHeader( 'anthropic-version', self::ANTHROPIC_VERSION );

		return $request->withHeader( 'Authorization', 'Bearer ' . $key );
	}

	/**
	 * Rejects credential material the Authorization header cannot carry
	 * (glm16-13).
	 *
	 * The header value is built by raw concatenation of the key, and
	 * nothing between here and the PSR-7 conversion rejects CR/LF —
	 * while the vendor HeadersCollection it lands in SPLITS
	 * comma-joined string values (explode(',')), silently turning one
	 * credential into several header values. A key containing control
	 * characters (the CRLF header-injection class, tabs included) or a
	 * comma is therefore typed pre-transport rejected — the same
	 * wire-value-guard discipline the request members get — instead of
	 * failing auth at the fixed z.ai HTTPS endpoint with no hint the
	 * credential material is the cause. The rejection rides the
	 * RuntimeException binding-failure family (GLM3 #9: ErrorMapper
	 * maps it to 500 zai_error, never 400), and the fixed message
	 * never interpolates the key (the redaction contract).
	 *
	 * An EMPTY key stays tolerated: glm13-1's empty-wire rules own that
	 * shape (the probe treats it exactly like unwired; a generation
	 * request flies 'Bearer ' as before).
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The credential material about to ride the header.
	 * @return void
	 * @throws RuntimeException When the key cannot ride an Authorization value.
	 */
	private static function reject_uncarriable_credential( string $key ): void {
		if ( '' === $key ) {
			return;
		}

		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $key ) || false !== strpos( $key, ',' ) ) {
			throw new RuntimeException(
				'The zai_anthropic provider refuses credential material containing control characters or commas: the Authorization header cannot carry it.'
			);
		}
	}

	/**
	 * Strips any x-api-key header (case-insensitively) from a request.
	 *
	 * The SDK's HeadersCollection has no removal API, so the request is
	 * rebuilt from its remaining headers with method, URI, body/data, and
	 * transport options carried over verbatim.
	 *
	 * GLM4 #7: for a GET carrying array data, getUri() already folds the
	 * data into the query string — a rebuild constructed with BOTH that
	 * folded URI and the data component appended every query parameter a
	 * SECOND time on its own next getUri() call
	 * ('...?page=2&limit=50&page=2&limit=50'). For that one shape the
	 * folded URI travels without the data component (getBody() is null
	 * for GETs, so the wire output is identical); every other shape keeps
	 * its original body/data component verbatim.
	 *
	 * @since 0.2.0
	 *
	 * @param Request $request The request to strip.
	 * @return Request The request without any x-api-key header.
	 */
	private static function without_x_api_key( Request $request ): Request {
		if ( ! $request->hasHeader( 'x-api-key' ) ) {
			return $request;
		}

		$headers = array();
		foreach ( $request->getHeaders() as $name => $values ) {
			if ( 'x-api-key' !== strtolower( (string) $name ) ) {
				$headers[ $name ] = $values;
			}
		}

		$uri  = $request->getUri();
		$data = $request->getData() ?? $request->getBody();

		if ( null !== $request->getData() && HttpMethodEnum::GET() === $request->getMethod() ) {
			// The query string is already embedded in the folded URI.
			$data = null;
		}

		return new Request(
			$request->getMethod(),
			$uri,
			$headers,
			$data,
			$request->getOptions()
		);
	}

	/**
	 * Wraps a wired authentication instance for the Anthropic surface.
	 *
	 * The registry wires the SDK's plain ApiKeyRequestAuthentication (core's
	 * key store does not know about protocol headers), so the zai_anthropic
	 * model, directory, and availability funnel their wired instance through
	 * here before authenticating a request. Already-wrapped instances pass
	 * through unchanged. Any OTHER implementation is refused: passing it
	 * through would send the request unauthenticated (no Bearer, no
	 * anthropic-version) instead of failing closed (review finding). The
	 * registry itself only ever wires ApiKeyRequestAuthentication subclasses
	 * for apiKey() metadata, so this guard is defense in depth.
	 *
	 * @since 0.2.0
	 *
	 * @param RequestAuthenticationInterface $authentication The wired authentication.
	 * @return RequestAuthenticationInterface The Anthropic-surface authentication.
	 * @throws RuntimeException When the authentication type cannot carry
	 *                          this surface's protocol (GLM3 #9: the
	 *                          binding-failure family — ErrorMapper maps it
	 *                          to 500 zai_error, never 400
	 *                          zai_invalid_request the way the previous
	 *                          InvalidArgumentException did).
	 */
	public static function wrap( RequestAuthenticationInterface $authentication ): RequestAuthenticationInterface {
		if ( $authentication instanceof self ) {
			return $authentication;
		}

		if ( $authentication instanceof ApiKeyRequestAuthentication ) {
			return new self( $authentication->getApiKey() );
		}

		throw new RuntimeException(
			'The zai_anthropic provider requires an API-key authentication instance.'
		);
	}
}
