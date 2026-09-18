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

use LogicException;

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
	 * The lookup is explicit (OCR round 27, t31-ocr27-5): a case added
	 * without its LABELS row once answered the engine's own
	 * "Undefined array key" warning and TypeError — loud, but naming
	 * neither the enum nor the missing case nor the sync duty. An
	 * unknown value is a named failure now: the value, the table, and
	 * the add-them-together duty, one message.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 * @throws LogicException The backing value has no LABELS row (a case and its row must be added together).
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- PHPCompatibility 9.3.5 predates enums (PHP 8.1) and misreads enum methods as plain functions; $this in an enum method is valid on the 8.2 floor.
		$value = $this->value;

		if ( ! isset( self::LABELS[ $value ] ) ) {
			throw new LogicException(
				sprintf(
					'AvailabilityState has no label for the backing value "%s" — every case needs its row in AvailabilityState::LABELS; add the case and the row together.',
					$value // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed enum backing-value vocabulary in a developer-facing rejection; escaping belongs to the display layer.
				)
			);
		}

		return self::LABELS[ $value ];
	}
}
