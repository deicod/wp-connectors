<?php
/**
 * Provider-neutral HTTP response value object (Task 3.1).
 *
 * The neutral response shape: final status code, header map, body. Same
 * purity and redaction rules as the request value object — the debug form
 * masks sensitive header values and omits the body, so a response
 * carrying token material in a header or body cannot leak it through a
 * string form.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Http;

use Deicod\WpConnectors\Shared\Support\SecretMask;
use InvalidArgumentException;

/**
 * Immutable, constructor-validated HTTP response.
 *
 * @since 0.1.0
 */
final class HttpResponse {

	/**
	 * Final status code (an intermediate 1xx is not a completed exchange).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $status;

	/**
	 * Header map (name as received => value).
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private readonly array $headers;

	/**
	 * Body exactly as received.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $body;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $status  Final status code (200-599).
	 * @param array<string, mixed> $headers Header map; non-empty string keys, string values.
	 * @param string               $body    Body as received (may be empty).
	 * @throws InvalidArgumentException When the status or header map violates the contract.
	 */
	public function __construct( int $status, array $headers = array(), string $body = '' ) {
		if ( $status < 200 || $status > 599 ) {
			throw new InvalidArgumentException( sprintf( 'The response status must be a final code (200-599), %d given.', $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a validated int in a developer-facing rejection; escaping belongs to the display layer.
		}

		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw new InvalidArgumentException( 'Header names must be non-empty strings.' );
			}
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Header values must be strings.' );
			}
		}

		$this->status  = $status;
		$this->headers = $headers;
		$this->body    = $body;
	}

	/**
	 * Status code.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Header map as received.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * One header value, looked up case-insensitively.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Header name (any case).
	 * @return string|null The value, or null when absent.
	 */
	public function header( string $name ): ?string {
		foreach ( $this->headers as $header_name => $value ) {
			if ( strtolower( (string) $header_name ) === strtolower( $name ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Body exactly as received.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Safe debug rendering — never contains secrets.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function __toString(): string {
		$lines = array( 'HTTP ' . $this->status );
		foreach ( $this->headers as $name => $value ) {
			$rendered = SecretMask::is_sensitive_header_name( (string) $name ) ? SecretMask::mask( $value ) : $value;
			$lines[]  = $name . ': ' . $rendered;
		}
		$lines[] = '[body omitted]';

		return implode( "\n", $lines );
	}
}
