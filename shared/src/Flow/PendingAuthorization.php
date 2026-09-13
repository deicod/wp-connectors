<?php
/**
 * Pending user-scoped authorization flow state (Task 3.1).
 *
 * The neutral shape of an authorization flow that is mid-air: which
 * admin (user id) started it, for which provider, when, and the flow's
 * own state — exactly one of a device-authorization session (awaiting
 * the user's confirmation, poll pending) or a PKCE pair (awaiting the
 * paste-back of the code). The revoke path deletes all provider- and
 * user-scoped pending state so a flow that was mid-air during
 * revocation can never later install a fresh grant.
 *
 * Pure value object; persistence and expiry sweeping belong to the
 * admin/flow tasks.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Flow;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable, constructor-validated pending authorization.
 *
 * @since 0.1.0
 */
final class PendingAuthorization {

	/**
	 * The admin who started the flow.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private readonly int $user_id;

	/**
	 * Provider label (neutral; values come from per-plugin provider config).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $provider_id;

	/**
	 * When the flow started.
	 *
	 * @since 0.1.0
	 *
	 * @var DateTimeImmutable
	 */
	private readonly DateTimeImmutable $created_at;

	/**
	 * Device-flow session, when the pending flow is a device flow.
	 *
	 * @since 0.1.0
	 *
	 * @var DeviceAuthorizationSession|null
	 */
	private readonly ?DeviceAuthorizationSession $device_session;

	/**
	 * PKCE pair, when the pending flow is an authorization-code-with-PKCE flow.
	 *
	 * @since 0.1.0
	 *
	 * @var PkceCodePair|null
	 */
	private readonly ?PkceCodePair $pkce_pair;

	/**
	 * Constructor (private: use the for_device()/for_pkce() factories).
	 *
	 * @since 0.1.0
	 *
	 * @param int                             $user_id        Starting admin's user id (positive).
	 * @param string                          $provider_id    Provider label (non-empty).
	 * @param DateTimeImmutable               $created_at     Flow start reading.
	 * @param DeviceAuthorizationSession|null $device_session Device session, or null.
	 * @param PkceCodePair|null               $pkce_pair      PKCE pair, or null.
	 * @throws InvalidArgumentException When the user/provider is invalid or the payload is not exactly one flow.
	 */
	private function __construct( int $user_id, string $provider_id, DateTimeImmutable $created_at, ?DeviceAuthorizationSession $device_session, ?PkceCodePair $pkce_pair ) {
		if ( $user_id < 1 ) {
			throw new InvalidArgumentException( 'The user id must be positive.' );
		}
		if ( '' === trim( $provider_id ) ) {
			throw new InvalidArgumentException( 'The provider id must be a non-empty string.' );
		}
		if ( ( null === $device_session ) === ( null === $pkce_pair ) ) {
			throw new InvalidArgumentException( 'A pending authorization carries exactly one flow payload (device session or PKCE pair).' );
		}

		$this->user_id        = $user_id;
		$this->provider_id    = $provider_id;
		$this->created_at     = $created_at;
		$this->device_session = $device_session;
		$this->pkce_pair      = $pkce_pair;
	}

	/**
	 * A pending device flow.
	 *
	 * @since 0.1.0
	 *
	 * @param int                        $user_id     Starting admin's user id.
	 * @param string                     $provider_id Provider label.
	 * @param DeviceAuthorizationSession $session    Device-authorization session.
	 * @param DateTimeImmutable          $created_at  Flow start reading.
	 * @return self
	 */
	public static function for_device( int $user_id, string $provider_id, DeviceAuthorizationSession $session, DateTimeImmutable $created_at ): self {
		return new self( $user_id, $provider_id, $created_at, $session, null );
	}

	/**
	 * A pending authorization-code-with-PKCE flow.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $user_id     Starting admin's user id.
	 * @param string            $provider_id Provider label.
	 * @param PkceCodePair      $pair        PKCE pair.
	 * @param DateTimeImmutable $created_at  Flow start reading.
	 * @return self
	 */
	public static function for_pkce( int $user_id, string $provider_id, PkceCodePair $pair, DateTimeImmutable $created_at ): self {
		return new self( $user_id, $provider_id, $created_at, null, $pair );
	}

	/**
	 * Starting admin's user id.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Provider label.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function provider_id(): string {
		return $this->provider_id;
	}

	/**
	 * Flow start reading.
	 *
	 * @since 0.1.0
	 *
	 * @return DateTimeImmutable
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Device session, or null when this is a PKCE flow.
	 *
	 * @since 0.1.0
	 *
	 * @return DeviceAuthorizationSession|null
	 */
	public function device_session(): ?DeviceAuthorizationSession {
		return $this->device_session;
	}

	/**
	 * PKCE pair, or null when this is a device flow.
	 *
	 * @since 0.1.0
	 *
	 * @return PkceCodePair|null
	 */
	public function pkce_pair(): ?PkceCodePair {
		return $this->pkce_pair;
	}
}
