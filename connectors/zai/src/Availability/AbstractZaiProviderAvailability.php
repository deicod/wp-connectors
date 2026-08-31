<?php
/**
 * Shared provider availability: authenticated probe with persisted validated state.
 *
 * Mere key presence is NOT availability (Tasks 1.4/2.1): a nonempty-but-invalid
 * key must report not-connected. This implementation probes the provider's
 * model-list endpoint with the effective credential and persists the
 * verdict, bound to the COMPLETE key value, its source (env/constant/
 * database/runtime) and the endpoint identity (provider+plan+region). Only
 * a definitive credential rejection (401/403) reports not-connected;
 * INCONCLUSIVE probes (route unavailable, network error, 429, 5xx) report
 * configured-pending instead, so core's key-save validation never blocks a
 * key for an endpoint whose probe route is unavailable (expected for the
 * China region /models 404):
 *
 * - Replacing the key (any source change) changes the binding, so a newly
 *   invalid key can never appear connected and a corrected key can never stay
 *   unavailable — both force a fresh probe.
 * - Switching plan or region changes the binding too. For a REGION switch
 *   the stored key is additionally deleted by the settings layer (SPEC §3.3;
 *   separate accounts and keys): pending-accept semantics must never let the
 *   old region's key ride an inconclusive probe onto the new endpoint.
 *   Env/constant credentials cannot be deleted, so the same handler marks
 *   them pending DEFINITIVE validation (REGION_PENDING_OPTION, bound to the
 *   new region + credential fingerprint): while that exact key is the
 *   effective one, only an authenticated 2xx (connected) or a 401/403
 *   (rejected) may settle the state — a merely inconclusive probe reads as
 *   DISCONNECTED, never as configured-pending.
 *
 * Each provider (zai, zai_anthropic) persists its own state under its own
 * option names, and the binding embeds the provider-scoped endpoint
 * identity, so one provider's validated state can NEVER establish the other
 * provider's status — availability is validated independently per provider.
 *
 * The stored state contains a SHA-256 binding (not the key), the boolean
 * verdict, and the check timestamp — never credential material.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Availability;

use Throwable;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

/**
 * Provider availability with a persisted, credential-bound validated state.
 *
 * @since 0.2.0
 */
abstract class AbstractZaiProviderAvailability implements ProviderAvailabilityInterface, WithHttpTransporterInterface, WithRequestAuthenticationInterface {

	// The alias lets the wrapping setHttpTransporter() override below
	// delegate to the trait implementation (traits have no parent:: chain).
	use WithHttpTransporterTrait {
		setHttpTransporter as trait_set_transporter; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias.
	}
	use WithRequestAuthenticationTrait;

	/**
	 * Plugin-owned option persisting the last validated state.
	 *
	 * Overridden per provider child; the base value is the zai provider's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const STATE_OPTION = 'zai_connector_zai_key_state';

	/**
	 * Plugin-owned option marking an environment/constant credential as
	 * pending DEFINITIVE validation after a region switch.
	 *
	 * Holds array{region: string, fingerprint: string} — the new region and
	 * a SHA-256 fingerprint of the credential that was effective when the
	 * region changed (never the key itself). While the flag binds the
	 * currently effective key, isConfigured() reports false on anything but
	 * a definitive probe result; see mark_region_switch_pending().
	 *
	 * Overridden per provider child; the base value is the zai provider's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const REGION_PENDING_OPTION = 'zai_connector_zai_region_pending';

	/**
	 * The core-owned option holding this provider's API key.
	 *
	 * Overridden per provider child; the base value is the zai provider's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const KEY_OPTION = 'connectors_ai_zai_api_key';

	/**
	 * Environment variable / constant name core advertises for the key.
	 *
	 * Overridden per provider child; the base value is the zai provider's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const KEY_ENV_NAME = 'ZAI_API_KEY';

	/**
	 * Seconds a validated verdict stays authoritative before re-probing.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	const STATE_TTL = 300;

	/**
	 * Clock marker stored with UTC-based timestamps.
	 *
	 * A stored state without this marker predates the UTC switch; its
	 * checked_at cannot be compared reliably (the site offset may have
	 * changed), so such states are treated as stale and re-probed — never
	 * trusted, never fatal.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const STATE_CLOCK_UTC = 'utc';

	/**
	 * Verdict: the credential is valid (probe returned 2xx).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const VERDICT_VALID = 'valid';

	/**
	 * Verdict: the credential was rejected (probe returned 401/403).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	const VERDICT_INVALID = 'invalid';

	/**
	 * The provider's endpoint resolver class.
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	abstract protected static function endpoint_class(): string;

	/**
	 * The provider's SDK-free settings class (the invalidation identifiers
	 * and the region-pending implementation live there; loading it is safe
	 * on sites without the SDK plugin — Codex R2 #3).
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	abstract protected static function settings_class(): string;

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.2.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		if ( ! $http_transporter instanceof LoggingHttpTransporter ) {
			$http_transporter = new LoggingHttpTransporter( $http_transporter );
		}

		$this->trait_set_transporter( $http_transporter );
	}

	/**
	 * Reports whether the provider is configured with a validated credential.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the effective key was validated against the
	 *              currently selected endpoint.
	 */
	public function isConfigured(): bool {
		$effective = $this->effective_key();

		if ( '' === $effective['key'] ) {
			// Nothing to validate; drop any stale verdict.
			delete_option( static::STATE_OPTION );

			return false;
		}

		$binding = $this->binding( $effective['source'], $effective['key'] );
		$state   = $this->stored_state();

		// Region-switch distrust (set by the settings layer after a region
		// change): while the effective key is exactly the env/constant
		// credential that rode the switch, only a DEFINITIVE result may
		// report it connected — see region_switch_pending().
		$region_pending = $this->region_switch_pending( $effective['key'] );

		if ( \is_array( $state ) && ( $state['binding'] ?? '' ) === $binding ) {
			// UTC on BOTH sides (current_time() with $gmt, not time(), so the
			// deterministic test clock still drives TTL expiry): a site
			// timezone change alters no input to this subtraction, so the
			// elapsed-time math can never go negative ("fresh" for hours).
			// Marker-less states predate the UTC switch — see STATE_CLOCK_UTC.
			$fresh = ( $state['clock'] ?? '' ) === self::STATE_CLOCK_UTC
				&& isset( $state['checked_at'] )
				&& ( current_time( 'timestamp', true ) - (int) $state['checked_at'] ) < self::STATE_TTL; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.RequestedUTC -- time() would bypass the deterministic (injectable) clock the TTL tests rely on.

			if ( $fresh ) {
				if ( $region_pending ) {
					// A fresh verdict for this exact binding IS the definitive
					// answer the distrust waits for (defensively: persisting
					// one already clears the flag).
					delete_option( static::REGION_PENDING_OPTION );
				}

				return self::VERDICT_VALID === ( $state['valid'] ?? null );
			}

			// Stale-but-matching verdict: probe again, but remember it as the
			// fallback for inconclusive probes below.
			$fallback = self::VERDICT_VALID === ( $state['valid'] ?? null );
		}

		$verdict = $this->probe();

		if ( null === $verdict ) {
			// Inconclusive probe (transport error, 5xx, 429, 404 on the
			// unprobed cn /models route, ...): nothing here rejects the
			// CREDENTIAL, so core's key-save validation (which clears the
			// key when isConfigured() returns false) must NOT be blocked —
			// otherwise no key could ever be saved for an endpoint whose
			// probe route is unavailable. Treat as configured-pending; a
			// stored matching verdict (definitive evidence about this exact
			// key+endpoint) still takes precedence.
			//
			// EXCEPT under region-switch distrust: a pending probe says
			// nothing about the old-region credential, and configured-pending
			// here would send it against the new endpoint indefinitely.
			if ( $region_pending ) {
				return false;
			}

			return isset( $fallback ) ? $fallback : true;
		}

		$this->persist_state( $binding, $verdict );

		if ( $region_pending ) {
			// Definitive answer about the riding credential (valid or
			// rejected): the distrust is resolved either way.
			delete_option( static::REGION_PENDING_OPTION );
		}

		return $verdict;
	}

	/**
	 * Returns the effective credential and where it came from.
	 *
	 * The registry-wired authentication object is authoritative (it is what
	 * actual requests authenticate with — including the candidate key core
	 * sets during REST settings validation). When nothing is wired, the
	 * core resolution order is mirrored: env var, then constant, then the
	 * database option.
	 *
	 * @since 0.2.0
	 *
	 * @return array{key: string, source: string} Empty key with source 'none' when unavailable.
	 */
	public function effective_key(): array {
		try {
			$authentication = $this->getRequestAuthentication();
		} catch ( Throwable $e ) {
			$authentication = null;
		}

		if ( $authentication instanceof ApiKeyRequestAuthentication && '' !== $authentication->getApiKey() ) {
			return array(
				'key'    => $authentication->getApiKey(),
				'source' => $this->key_source( $authentication->getApiKey() ),
			);
		}

		$env_value = getenv( static::KEY_ENV_NAME );
		if ( \is_string( $env_value ) && '' !== $env_value ) {
			return array(
				'key'    => $env_value,
				'source' => 'env',
			);
		}

		if ( \defined( static::KEY_ENV_NAME ) ) {
			$constant_value = \constant( static::KEY_ENV_NAME );
			if ( \is_string( $constant_value ) && '' !== $constant_value ) {
				return array(
					'key'    => $constant_value,
					'source' => 'constant',
				);
			}
		}

		$db_value = get_option( static::KEY_OPTION, '' );
		if ( \is_string( $db_value ) && '' !== $db_value ) {
			return array(
				'key'    => $db_value,
				'source' => 'database',
			);
		}

		return array(
			'key'    => '',
			'source' => 'none',
		);
	}

	/**
	 * Derives the source label for a registry-wired key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The effective key value.
	 * @return string 'env', 'constant', 'database', or 'runtime' (set by core
	 *                during REST validation before the value is stored).
	 */
	private function key_source( string $key ): string {
		$env_value = getenv( static::KEY_ENV_NAME );
		if ( \is_string( $env_value ) && '' !== $env_value && hash_equals( $env_value, $key ) ) {
			return 'env';
		}

		if ( \defined( static::KEY_ENV_NAME ) ) {
			$constant_value = \constant( static::KEY_ENV_NAME );
			if ( \is_string( $constant_value ) && '' !== $constant_value && hash_equals( $constant_value, $key ) ) {
				return 'constant';
			}
		}

		$db_value = get_option( static::KEY_OPTION, '' );
		if ( \is_string( $db_value ) && '' !== $db_value && hash_equals( $db_value, $key ) ) {
			return 'database';
		}

		return 'runtime';
	}

	/**
	 * Computes the state binding for a key: source + endpoint + COMPLETE key.
	 *
	 * The full key value is hashed (never stored), so replacement keys that
	 * merely share a provider prefix — the common API-key format — produce
	 * different bindings, and every key-source change invalidates. The
	 * endpoint identity comes from the provider-scoped resolver, so the
	 * binding differs across providers even for plan/region selections that
	 * happen to match.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source Key source label.
	 * @param string $key    Complete key value.
	 * @return string SHA-256 binding.
	 */
	private function binding( string $source, string $key ): string {
		$endpoint_class = static::endpoint_class();
		$endpoint       = $endpoint_class::for_current_settings();

		return hash( 'sha256', $source . '|' . $endpoint->cache_key() . '|' . $key );
	}

	/**
	 * Reports whether the effective key is the credential a region switch
	 * marked pending DEFINITIVE validation for the CURRENT region.
	 *
	 * The flag binds the region AND the credential fingerprint, so it only
	 * ever gates the exact env/constant key that was effective when the
	 * region changed — a different credential (a candidate core wires
	 * during key-save validation, a newly stored database key, a replaced
	 * env var) is not the riding key and keeps the normal
	 * configured-pending semantics. A candidate with the IDENTICAL value
	 * (an admin re-saving the very env/constant key) is still that old-
	 * region credential and stays gated (SPEC §3.3: separate accounts).
	 *
	 * @since 0.2.0
	 *
	 * @param string $key Complete effective key value.
	 * @return bool True when the flag binds this exact key to the currently
	 *              selected region.
	 */
	private function region_switch_pending( string $key ): bool {
		$flag = get_option( static::REGION_PENDING_OPTION, null );

		if ( ! \is_array( $flag ) ) {
			return false;
		}

		$region      = $flag['region'] ?? null;
		$fingerprint = $flag['fingerprint'] ?? null;

		if ( ! \is_string( $region ) || ! \is_string( $fingerprint ) || '' === $fingerprint ) {
			return false;
		}

		$endpoint_class = static::endpoint_class();

		return $endpoint_class::for_current_settings()->region() === $region
			&& hash_equals( $fingerprint, hash( 'sha256', $key ) );
	}

	/**
	 * Marks the region-immutable credential as pending definitive validation.
	 *
	 * The implementation lives in the SDK-free settings layer (Codex R2 #3):
	 * the region switch fires on sites without the SDK plugin too, where
	 * this class cannot be autoloaded at all. Kept as a delegating public
	 * method for SDK-present callers holding the availability class.
	 *
	 * @since 0.2.0
	 *
	 * @param string $region The newly selected region.
	 * @return void
	 */
	public static function mark_region_switch_pending( string $region ): void {
		$settings_class = static::settings_class();
		$settings_class::mark_region_switch_pending( $region );
	}

	/**
	 * Probes the model-list endpoint with the effective credential.
	 *
	 * Only statuses that definitively reject the CREDENTIAL persist a verdict
	 * (401 bad token; 403 no access for this key). Everything else stays
	 * transient so it can never poison the connected state — z.ai returns
	 * 429 both for real rate limits and for plan mismatches on an otherwise
	 * VALID key (error 1113 "Insufficient balance", record 0006), and 404/5xx
	 * indicate an unavailable probe endpoint (the cn /models path is unprobed),
	 * not a bad key.
	 *
	 * @since 0.2.0
	 *
	 * @return bool|null True (valid), false (credential rejected: 401/403),
	 *                   or null when the probe was inconclusive (transport
	 *                   error, 3xx, 429, other 4xx, 5xx) — which says nothing
	 *                   about the credential and must not block key saving.
	 */
	private function probe(): ?bool {
		try {
			$endpoint_class = static::endpoint_class();
			$endpoint       = $endpoint_class::for_current_settings();
			$request        = new Request( HttpMethodEnum::GET(), $endpoint->models_url() );
			$request        = $this->getRequestAuthentication()->authenticateRequest( $request );
			$response       = $this->getHttpTransporter()->send( $request );
		} catch ( Throwable $e ) {
			// Transport failure: transient, never persisted.
			return null;
		}

		$status = $response->getStatusCode();

		if ( $response->isSuccessful() ) {
			return true;
		}

		if ( 401 === $status || 403 === $status ) {
			// The endpoint answered and rejected the credential itself.
			return false;
		}

		// 3xx, 429, other 4xx, 5xx: inconclusive for the credential.
		return null;
	}

	/**
	 * Returns the stored validated state, if any.
	 *
	 * @since 0.2.0
	 *
	 * @return array{binding?: string, valid?: string, checked_at?: int, clock?: string}|null
	 */
	private function stored_state(): ?array {
		$state = get_option( static::STATE_OPTION, null );

		return \is_array( $state ) ? $state : null;
	}

	/**
	 * Persists the validated state (never autoloaded, never the key itself).
	 *
	 * The stored checked_at is UTC (current_time() with $gmt) so a later
	 * site timezone change cannot distort the TTL elapsed-time math; the
	 * clock marker records the basis for safe future interpretation.
	 *
	 * @since 0.2.0
	 *
	 * @param string $binding Credential+endpoint binding.
	 * @param bool   $valid   Whether the credential validated.
	 * @return void
	 */
	private function persist_state( string $binding, bool $valid ): void {
		update_option(
			static::STATE_OPTION,
			array(
				'binding'    => $binding,
				'valid'      => $valid ? self::VERDICT_VALID : self::VERDICT_INVALID,
				'checked_at' => (int) current_time( 'timestamp', true ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.RequestedUTC -- time() would bypass the deterministic (injectable) clock the TTL tests rely on.
				'clock'      => self::STATE_CLOCK_UTC,
			),
			false
		);
	}
}
