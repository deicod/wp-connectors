<?php
/**
 * Maps SDK exceptions from z.ai calls to stable, typed WP_Error values.
 *
 * Codes are part of the plugin's public contract (SPEC §6.2) and never
 * change with provider message wording. Messages are constructed here, never
 * copied verbatim from exceptions whose text can embed upstream content,
 * because upstream bodies can echo request material (including credentials)
 * and must stay redacted. Exceptions whose messages are built entirely from
 * controlled strings (our own pre-transport rejections, the fixed-string
 * ResponseExceptions this plugin produces, NetworkException transport
 * messages, and the SDK's fixed-string RuntimeExceptions) do include the
 * detail.
 *
 * Two error surfaces share the message catalog in this class:
 *
 * - DIRECT model use (ZaiTextGenerationModel::generate_text()) returns the
 *   typed zai_* WP_Error codes below.
 * - The core prompt builder (wp_ai_client_prompt()->...->generate_text())
 *   converts exceptions itself with a fixed code map (prompt_client_error,
 *   prompt_upstream_server_error, …) and NO filter, passing the exception
 *   message through VERBATIM — so the model builds its exceptions from the
 *   same safe_http_message() catalog, which is what keeps that path redacted
 *   and actionable. Typed zai_* codes cannot be delivered through the core
 *   builder; that is a WordPress core limitation.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use Throwable;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\Exception\RedirectException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;

/**
 * Exception → WP_Error mapper for the z.ai provider.
 *
 * @since 0.1.0
 */
final class ErrorMapper {

	/**
	 * The API key was rejected (HTTP 401).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_UNAUTHORIZED = 'zai_unauthorized';

	/**
	 * The key is valid but lacks access (HTTP 403).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_FORBIDDEN = 'zai_forbidden';

	/**
	 * Rate limited (HTTP 429).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_RATE_LIMITED = 'zai_rate_limited';

	/**
	 * Other client errors (4xx).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_CLIENT_ERROR = 'zai_client_error';

	/**
	 * Upstream server errors (5xx).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_UPSTREAM_ERROR = 'zai_upstream_error';

	/**
	 * Unexpected redirect (3xx).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_REDIRECT_ERROR = 'zai_redirect_error';

	/**
	 * Transport failure (connection, DNS, timeout).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_TRANSPORT_ERROR = 'zai_transport_error';

	/**
	 * Malformed/unexpected response payload.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_INVALID_RESPONSE = 'zai_invalid_response';

	/**
	 * Rejected request options (our own pre-transport validation).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_INVALID_REQUEST = 'zai_invalid_request';

	/**
	 * Generation stopped at the token limit.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_TOKEN_LIMIT = 'zai_token_limit';

	/**
	 * Anything else.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CODE_ERROR = 'zai_error';

	/**
	 * Builds the safe, actionable message for a non-2xx HTTP status.
	 *
	 * The ONE catalog shared by both error surfaces: the model's non-final
	 * response pipeline (whose exception messages core later converts to
	 * WP_Error VERBATIM — no filter exists on that path) and this mapper's
	 * typed WP_Error output. Never includes upstream body content.
	 *
	 * @since 0.1.0
	 *
	 * @param int $status HTTP status code (any non-2xx).
	 * @return string Translated, redacted, actionable message.
	 */
	public static function safe_http_message( int $status ): string {
		if ( $status >= 500 ) {
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The z.ai API reported a server error (%d). This is usually temporary; try again shortly.', 'zai' ),
				$status
			);
		}

		if ( $status >= 400 ) {
			switch ( $status ) {
				case 401:
					return __( 'The z.ai API rejected the API key (401). Check the key on the Connectors screen — international and China keys are not interchangeable.', 'zai' );
				case 403:
					return __( 'The z.ai API refused the request (403). The key may not have access to this model or plan.', 'zai' );
				case 429:
					return __( 'The z.ai API is rate limiting this key (429). Wait a moment and try again.', 'zai' );
				default:
					return sprintf(
						/* translators: %d: HTTP status code. */
						__( 'The z.ai API rejected the request (%d). Check the prompt and model selection.', 'zai' ),
						$status
					);
			}
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'The z.ai API returned an unexpected redirect (%d). No request was retried.', 'zai' ),
			$status
		);
	}

	/**
	 * Maps a caught exception to a typed WP_Error with a safe message.
	 *
	 * @since 0.1.0
	 *
	 * @param Throwable $exception The caught exception.
	 * @return \WP_Error Typed error; message never contains upstream bodies.
	 */
	public static function to_wp_error( Throwable $exception ): \WP_Error {
		if ( $exception instanceof ClientException ) {
			return self::client_error( $exception );
		}

		if ( $exception instanceof ServerException ) {
			return new \WP_Error(
				self::CODE_UPSTREAM_ERROR,
				self::safe_http_message( self::status_of( $exception ) ),
				array( 'status' => self::status_of( $exception ) )
			);
		}

		if ( $exception instanceof RedirectException ) {
			return new \WP_Error(
				self::CODE_REDIRECT_ERROR,
				self::safe_http_message( self::status_of( $exception ) ),
				array( 'status' => self::status_of( $exception ) )
			);
		}

		if ( $exception instanceof NetworkException ) {
			// NetworkException messages are constructed by the SDK from the
			// request URL and transport error only — no upstream body.
			return new \WP_Error(
				self::CODE_TRANSPORT_ERROR,
				$exception->getMessage(),
				array( 'status' => 503 )
			);
		}

		if ( $exception instanceof TokenLimitReachedException ) {
			return new \WP_Error(
				self::CODE_TOKEN_LIMIT,
				__( 'The generation stopped because the configured token limit was reached.', 'zai' ),
				array( 'status' => 400 )
			);
		}

		if ( $exception instanceof ResponseException ) {
			// Safe to pass through: ResponseExceptions reaching this mapper
			// are constructed by THIS plugin with fixed messages (the model
			// re-wraps SDK parse failures precisely so no upstream field can
			// reach exception messages).
			return new \WP_Error(
				self::CODE_INVALID_RESPONSE,
				$exception->getMessage(),
				array( 'status' => 502 )
			);
		}

		if ( $exception instanceof InvalidArgumentException ) {
			// Our own pre-transport rejections carry precise, safe messages.
			return new \WP_Error(
				self::CODE_INVALID_REQUEST,
				$exception->getMessage(),
				array( 'status' => 400 )
			);
		}

		if ( $exception instanceof RuntimeException ) {
			// SDK construction/binding failures carry fixed-string messages —
			// notably the "instance not set. Make sure you use the AiClient
			// class" hint a model built outside the registry throws — so
			// surfacing them beats the generic text without any leak risk.
			return new \WP_Error(
				self::CODE_ERROR,
				$exception->getMessage(),
				array( 'status' => 500 )
			);
		}

		return new \WP_Error(
			self::CODE_ERROR,
			__( 'The z.ai request failed.', 'zai' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Maps a 4xx ClientException to a stable code and hint.
	 *
	 * @since 0.1.0
	 *
	 * @param ClientException $exception The 4xx exception.
	 * @return \WP_Error Typed error.
	 */
	private static function client_error( ClientException $exception ): \WP_Error {
		$status = self::status_of( $exception );

		$code = self::CODE_CLIENT_ERROR;
		if ( 401 === $status ) {
			$code = self::CODE_UNAUTHORIZED;
		} elseif ( 403 === $status ) {
			$code = self::CODE_FORBIDDEN;
		} elseif ( 429 === $status ) {
			$code = self::CODE_RATE_LIMITED;
		}

		return new \WP_Error(
			$code,
			self::safe_http_message( $status ),
			array( 'status' => $status )
		);
	}

	/**
	 * Extracts the HTTP status an HTTP exception carries as its code.
	 *
	 * @since 0.1.0
	 *
	 * @param Throwable $exception Exception with a numeric HTTP status code.
	 * @return int Status code (0 when not numeric).
	 */
	private static function status_of( Throwable $exception ): int {
		$code = $exception->getCode();

		return \is_int( $code ) ? $code : (int) $code;
	}
}
