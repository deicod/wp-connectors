<?php
/**
 * OAuth availability state (Task 3.1).
 *
 * The five externally visible availability states of an OAuth provider.
 * Four are the shared runtime's model (disconnected, reconnect
 * required, temporarily unavailable, connected); the fifth is the
 * configuration-error ("update required") state of the third terminal
 * class — the client id is broken, retries are suppressed until the
 * connector's configuration changes, and no reconnection will fix it.
 *
 * The label is neutral, untranslated English: each plugin's availability
 * implementation maps states onto the host surface (and its own text
 * domain) in the availability-semantics task; this enum is the shared
 * vocabulary, not a display layer.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Shared\Availability;

/**
 * Availability state of an OAuth provider.
 *
 * @since 0.1.0
 */
enum AvailabilityState: string {

	/**
	 * No grant stored; the provider was never connected (or was revoked
	 * and fully cleaned up).
	 */
	case Disconnected = 'disconnected';

	/**
	 * Dead grant: definitive authorization failure; the user must
	 * re-connect.
	 */
	case ReconnectRequired = 'reconnect_required';

	/**
	 * Retryable right now: stale expiry under cooldown, transient
	 * failure, or an unreadable store — with a scheduled refresh path.
	 */
	case TemporarilyUnavailable = 'temporarily_unavailable';

	/**
	 * Usable grant; tokens fresh or refreshable.
	 */
	case Connected = 'connected';

	/**
	 * Permanent configuration failure; the connector must be updated or
	 * reconfigured before anything else can help.
	 */
	case ConfigurationError = 'configuration_error';

	/**
	 * Neutral, untranslated labels keyed by backing value.
	 *
	 * A data table (not a match on $this): one place to add a state's
	 * label, and the enum itself keeps the sniff surface of a plain
	 * constant expression.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	const LABELS = array(
		'disconnected'            => 'Disconnected',
		'reconnect_required'      => 'Re-connect required',
		'temporarily_unavailable' => 'Temporarily unavailable',
		'connected'               => 'Connected',
		'configuration_error'     => 'Update required',
	);

	/**
	 * Neutral, untranslated label for the state.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- PHPCompatibility 9.3.5 predates enums (PHP 8.1) and misreads enum methods as plain functions; $this in an enum method is valid on the 8.2 floor.
		return self::LABELS[ $this->value ];
	}
}
