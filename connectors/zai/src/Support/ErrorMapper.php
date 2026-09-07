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
	 * glm30-2: the identity is the CALLER's — each surface names itself by
	 * its card name (the glm24-2 chain: the settings layer's
	 * PROVIDER_LABEL), so an operator staring at "which of the two cards
	 * holds the wrong key?" can tell. 'z.ai API' keeps the OpenAI
	 * surface's messages byte-identical to the old hardcoded wording.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $status    HTTP status code (any non-2xx).
	 * @param string $api_label API display name ('z.ai API' / 'z.ai (Anthropic API)').
	 * @return string Translated, redacted, actionable message.
	 */
	public static function safe_http_message( int $status, string $api_label ): string {
		if ( $status >= 500 ) {
			return sprintf(
				/* translators: 1: API display name, 2: HTTP status code. */
				__( 'The %1$s reported a server error (%2$d). This is usually temporary; try again shortly.', 'zai' ),
				$api_label,
				$status
			);
		}

		if ( $status >= 400 ) {
			switch ( $status ) {
				case 401:
					return sprintf(
						/* translators: %s: API display name. */
						__( 'The %s rejected the API key (401). Check the key on the Connectors screen — international and China keys are not interchangeable.', 'zai' ),
						$api_label
					);
				case 403:
					return sprintf(
						/* translators: %s: API display name. */
						__( 'The %s refused the request (403). The key may not have access to this model or plan.', 'zai' ),
						$api_label
					);
				case 429:
					// 429 is also z.ai code 1113: a Coding-Plan key against the
					// General endpoint without pay-as-you-go balance (record
					// 0006) — an account state that waiting can never fix.
					return sprintf(
						/* translators: %s: API display name. */
						__( 'The %s rejected the request (429). This is either temporary rate limiting — wait a moment and try again — or a plan/balance mismatch: check that the selected plan (Coding Plan or General API) matches the key, and that the account has balance or an active subscription at its region portal (z.ai internationally, open.bigmodel.cn in China).', 'zai' ),
						$api_label
					);
				default:
					return sprintf(
						/* translators: 1: API display name, 2: HTTP status code. */
						__( 'The %1$s rejected the request (%2$d). Check the prompt and model selection.', 'zai' ),
						$api_label,
						$status
					);
			}
		}

		return sprintf(
			/* translators: 1: API display name, 2: HTTP status code. */
			__( 'The %1$s returned an unexpected redirect (%2$d). No request was retried.', 'zai' ),
			$api_label,
			$status
		);
	}

	/**
	 * Maps a caught exception to a typed WP_Error with a safe message.
	 *
	 * @since 0.1.0
	 *
	 * @param Throwable $exception The caught exception.
	 * @param string    $api_label API display name ('z.ai API' / 'z.ai (Anthropic API)') for the catalog messages (glm30-2).
	 * @return \WP_Error Typed error; message never contains upstream bodies.
	 */
	public static function to_wp_error( Throwable $exception, string $api_label ): \WP_Error {
		if ( $exception instanceof ClientException ) {
			return self::client_error( $exception, $api_label );
		}

		if ( $exception instanceof ServerException ) {
			return new \WP_Error(
				self::CODE_UPSTREAM_ERROR,
				self::safe_http_message( self::status_of( $exception ), $api_label ),
				array( 'status' => self::status_of( $exception ) )
			);
		}

		if ( $exception instanceof RedirectException ) {
			return new \WP_Error(
				self::CODE_REDIRECT_ERROR,
				self::safe_http_message( self::status_of( $exception ), $api_label ),
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
			/*
			 * glm13-14: the exception OWNS the message — this mapper branch
			 * was a second catalog that discarded the model's precise text
			 * (the limit number and the 'Raise maxTokens' guidance, both
			 * built into the throw at the parse site) and maintained the
			 * context-window string byte-identically in two files. Only
			 * this plugin constructs the exception (the SDK never throws
			 * it), always from fixed __() strings — the same
			 * controlled-string pass-through policy the branches below
			 * apply. The max_tokens typed payload rides the WP_Error data
			 * (null for the Codex R5 #4 context-window case, whose
			 * reduce-the-input guidance cannot be recovered by raising
			 * maxTokens — the distinction lives in the model's own two
			 * messages now).
			 */
			return new \WP_Error(
				self::CODE_TOKEN_LIMIT,
				$exception->getMessage(),
				array(
					'status'     => 400,
					'max_tokens' => $exception->getMaxTokens(),
				)
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
			sprintf(
				/* translators: %s: API display name. */
				__( 'The %s request failed.', 'zai' ),
				$api_label
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Maps a 4xx ClientException to a stable code and hint.
	 *
	 * @since 0.1.0
	 *
	 * @param ClientException $exception The 4xx exception.
	 * @param string          $api_label API display name for the catalog message (glm30-2).
	 * @return \WP_Error Typed error.
	 */
	private static function client_error( ClientException $exception, string $api_label ): \WP_Error {
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
			self::safe_http_message( $status, $api_label ),
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
