<?php
/**
 * Provider-neutral HTTP request value object (Task 3.1).
 *
 * The neutral request shape the OAuth transport port carries: method,
 * absolute http(s) URL, header map, optional body. Pure and immutable —
 * no environment access, no credential knowledge beyond the masking
 * policy its debug form consults.
 *
 * Redaction contract (the reason __toString() exists in safe form):
 * casting a request to string NEVER reveals secrets. The URL loses its
 * query, fragment, and userinfo; sensitive header values are masked
 * through the one policy (SecretMask::mask(): an ellipsis plus the
 * last four characters for values LONGER than the OTP threshold —
 * twelve characters — and the bare ellipsis at or below it, the
 * t31-ocr6-1 close, so an OTP-class value never shows a tail); the
 * body is omitted entirely. A request carrying an Authorization
 * header, a
 * token-bearing URL, or a token-bearing body therefore cannot leak it
 * through any string interpolation, log call, or exception message.
 * The dump and serialization channels ride the same contract:
 * __debugInfo() (t31-r11-5) mirrors the masked vocabulary for
 * print_r()/var_dump() and every debugger view, and __serialize()
 * (t31-ocr1-8) rides it for serialize() — both render the redacted
 * URL, the masked header map, and an omitted body, never the raw
 * property tree. var_export() is the one channel EXCLUDED by engine
 * design (no hook exists — it dumps the raw property tree); its eval
 * refuses through __set_state(), and no shipped code feeds these VOs
 * to var_export().
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Http;

use Deicod\WpConnectors\Shared\Support\AsciiFold;
use InvalidArgumentException;

/**
 * Immutable, constructor-validated HTTP request.
 *
 * @since 0.1.0
 */
final class HttpRequest {

	use HasMaskedHeaders;

	/**
	 * HTTP method token characters (RFC 7230 tchar).
	 *
	 * A constant-expression alias of the SINGLE grammar owner,
	 * HeaderMap::NAME_TOKEN_PATTERN (review round t31-r2-11): an HTTP
	 * method is a token over the same alphabet, and this second
	 * verbatim copy had already drifted its anchor (^ vs \A). The name
	 * stays for the method surface's readability; the grammar lives
	 * once (the PROVIDER_ID/CACHE_SCOPE alias direction).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const METHOD_TOKEN_PATTERN = HeaderMap::NAME_TOKEN_PATTERN;

	/**
	 * Upper-cased HTTP method.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $method;

	/**
	 * Absolute http(s) URL exactly as requested.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $url;

	/**
	 * Header map (the shared owner of validation, lookup, and render).
	 *
	 * @since 0.1.0
	 *
	 * @var HeaderMap
	 */
	private readonly HeaderMap $headers;

	/**
	 * Request body, or null when the request carries none.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private readonly ?string $body;

	/**
	 * Redacted target (scheme://host[:port]/path), derived at construction.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $redacted_url;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method  HTTP method token (stored upper-cased).
	 * @param string               $url     Absolute http(s) URL with a host.
	 * @param array<string, mixed> $headers Header map; non-empty string keys, string values.
	 * @param string|null          $body    Body, or null for none.
	 * @throws InvalidArgumentException When the method, URL, or header map violates the contract.
	 */
	public function __construct( string $method, string $url, array $headers = array(), ?string $body = null ) {
		// The method fold is the LOCALE-INDEPENDENT one (review round
		// t31-r3-10): the C-library byte upper-case mapping consults
		// LC_CTYPE, and under the Turkish dotted-I rule an ASCII 'i'
		// upper-cases to the two-byte 'İ' — bytes the ASCII-only method
		// grammar would then reject, so 'post' would stop being a method
		// in exactly the processes whose locale folds it. Same
		// class-closure posture as the header-name fold (t31-r2-14),
		// one owner.
		$normalized_method = AsciiFold::upper( $method );
		if ( 1 !== preg_match( self::METHOD_TOKEN_PATTERN, $normalized_method ) ) {
			throw new InvalidArgumentException( 'The HTTP method must be a non-empty method token.' );
		}

		$parts = $this->validated_url_parts( $url );

		$header_map = new HeaderMap( $headers );

		$this->method       = $normalized_method;
		$this->url          = $url;
		$this->headers      = $header_map;
		$this->body         = $body;
		$this->redacted_url = $parts['scheme'] . '://' . $parts['authority'] . $parts['path'];
	}

	/**
	 * Method (upper-cased).
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * URL exactly as requested.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Body, or null when the request carries none.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function body(): ?string {
		return $this->body;
	}

	/**
	 * The URL reduced to scheme://host[:port]/path.
	 *
	 * Query, fragment, and userinfo are dropped — the query is where
	 * credentials ride on some endpoints, and userinfo is credentials by
	 * definition.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function redacted_url(): string {
		return $this->redacted_url;
	}

	/**
	 * The header map the shared facade (HasMaskedHeaders) delegates to.
	 *
	 * @since 0.1.0
	 *
	 * @return HeaderMap
	 */
	protected function header_map(): HeaderMap {
		return $this->headers;
	}

	/**
	 * The request line the shared string form leads with (the trait's
	 * one divergence point, t31-r12-13).
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function safe_render_head(): string {
		return $this->method . ' ' . $this->redacted_url;
	}

	/**
	 * The request's head fields for the shared debug form.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function safe_debug_head_fields(): array {
		return array(
			'method'       => $this->method,
			'redacted_url' => $this->redacted_url,
		);
	}

	/**
	 * Parses and validates the URL via the shared owner, returning the parts the redacted form needs.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url URL.
	 * @return array{scheme: string, authority: string, path: string}
	 * @throws InvalidArgumentException When the URL is not absolute http(s) with a host and valid port.
	 */
	private function validated_url_parts( string $url ): array {
		return Url::parse_validated( $url );
	}
}
