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
	 * Header map (the shared owner of validation, lookup, and render).
	 *
	 * @since 0.1.0
	 *
	 * @var HeaderMap
	 */
	private readonly HeaderMap $headers;

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

		$header_map = new HeaderMap( $headers );

		$this->status  = $status;
		$this->headers = $header_map;
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
		return $this->headers->headers();
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
		return $this->headers->header( $name );
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
		$lines   = array_merge( array( 'HTTP ' . $this->status ), $this->headers->rendered_lines() );
		$lines[] = '[body omitted]';

		return implode( "\n", $lines );
	}
}
