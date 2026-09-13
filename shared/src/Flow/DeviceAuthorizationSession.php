<?php
/**
 * Device-authorization session value object (Task 3.1).
 *
 * The neutral result shape of a device-authorization request: the codes
 * the user confirms, where they confirm them, how fast the poll may
 * run, and when the session dies. The SHAPE is fixed by the flow; the
 * endpoints, client identifiers, and poll implementation are per-plugin
 * provider config — none of them live here.
 *
 * Pure value object: immutable, constructor-validated, no environment
 * access.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Flow;

use DateTimeImmutable;
use Deicod\WpConnectors\Shared\Http\Url;
use InvalidArgumentException;

/**
 * Immutable, constructor-validated device-authorization session.
 *
 * @since 0.1.0
 */
final class DeviceAuthorizationSession {

	/**
	 * Device code (the poll credential; provider spellings differ, the role does not).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $device_code;

	/**
	 * User code (what the admin types at the verification page).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $user_code;

	/**
	 * Verification page the admin opens.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $verification_uri;

	/**
	 * Minimum poll interval in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $interval_seconds;

	/**
	 * Absolute session expiry.
	 *
	 * @since 0.1.0
	 *
	 * @var DateTimeImmutable
	 */
	private readonly DateTimeImmutable $expires_at;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string            $device_code      Device code (non-empty).
	 * @param string            $user_code        User code (non-empty).
	 * @param string            $verification_uri Verification page (absolute http(s) URL).
	 * @param int               $interval_seconds Minimum poll interval (at least 1 second).
	 * @param DateTimeImmutable $expires_at       Absolute session expiry.
	 * @throws InvalidArgumentException When any field violates the contract above.
	 */
	public function __construct( string $device_code, string $user_code, string $verification_uri, int $interval_seconds, DateTimeImmutable $expires_at ) {
		if ( '' === trim( $device_code ) ) {
			throw new InvalidArgumentException( 'The device code must be a non-empty string.' );
		}
		if ( '' === trim( $user_code ) ) {
			throw new InvalidArgumentException( 'The user code must be a non-empty string.' );
		}
		Url::parse_validated( $verification_uri );
		if ( $interval_seconds < 1 ) {
			throw new InvalidArgumentException( 'The poll interval must be at least one second.' );
		}

		$this->device_code      = $device_code;
		$this->user_code        = $user_code;
		$this->verification_uri = $verification_uri;
		$this->interval_seconds = $interval_seconds;
		$this->expires_at       = $expires_at;
	}

	/**
	 * Device code.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function device_code(): string {
		return $this->device_code;
	}

	/**
	 * User code.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function user_code(): string {
		return $this->user_code;
	}

	/**
	 * Verification page URI.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function verification_uri(): string {
		return $this->verification_uri;
	}

	/**
	 * Minimum poll interval in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function interval_seconds(): int {
		return $this->interval_seconds;
	}

	/**
	 * Absolute session expiry.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function expires_at(): DateTimeImmutable {
		return $this->expires_at;
	}
}
