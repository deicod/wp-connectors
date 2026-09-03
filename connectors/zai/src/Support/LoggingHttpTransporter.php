<?php
/**
 * HTTP transporter decorator that records debug log entries.
 *
 * Wraps the real transporter; every send() is timed and, when the debug
 * option is enabled, recorded as method + redacted URL + status + duration.
 * On transport failures the status 0 is recorded and the original exception
 * is re-thrown untouched (its message is never logged).
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Logging decorator around any HttpTransporterInterface implementation.
 *
 * @since 0.1.0
 */
final class LoggingHttpTransporter implements HttpTransporterInterface {

	/**
	 * The wrapped transporter.
	 *
	 * @since 0.1.0
	 *
	 * @var HttpTransporterInterface
	 */
	private $inner;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param HttpTransporterInterface $inner Transporter to wrap.
	 */
	public function __construct( HttpTransporterInterface $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Wraps the transporter unless it already IS the logging decorator
	 * (GLM6 #13).
	 *
	 * The idempotent wrap rule — install the debug logger on whatever
	 * transporter arrives, never double-wrap — was copy-pasted in five
	 * setHttpTransporter() overrides (both models, both metadata
	 * directories, the availability base); a change to the rule would
	 * have had to land in five places in lockstep, the exact drift
	 * pattern the shared-support extractions exist to stop. Each override
	 * now delegates here in one line.
	 *
	 * @since 0.2.0
	 *
	 * @param HttpTransporterInterface $transporter Transporter to install.
	 * @return HttpTransporterInterface The transporter, wrapped when needed.
	 */
	public static function wrap( HttpTransporterInterface $transporter ): HttpTransporterInterface {
		return $transporter instanceof self ? $transporter : new self( $transporter );
	}

	/**
	 * Sends the request through the inner transporter, logging the round trip.
	 *
	 * @since 0.1.0
	 *
	 * @param Request             $request The request to send.
	 * @param RequestOptions|null $options Optional transport options.
	 * @return Response The response received.
	 * @throws \Exception Whatever the inner transporter throws on failure.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$method = (string) $request->getMethod()->value;
		$url    = $request->getUri();
		$start  = microtime( true );

		try {
			$response = $this->inner->send( $request, $options );
		} catch ( \Exception $e ) {
			DebugLogger::log( $method, $url, DebugLogger::STATUS_TRANSPORT_ERROR, ( microtime( true ) - $start ) * 1000 );

			throw $e;
		}

		DebugLogger::log( $method, $url, $response->getStatusCode(), ( microtime( true ) - $start ) * 1000 );

		return $response;
	}
}
