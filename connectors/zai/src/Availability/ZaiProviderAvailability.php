<?php
/**
 * Z.ai provider availability: authenticated probe with persisted validated state.
 *
 * Mere key presence is NOT availability (Task 1.4 / M1 exit criteria): a
 * nonempty-but-invalid key must report not-connected. This implementation
 * probes the model-list endpoint with the effective credential and persists
 * the verdict, bound to the COMPLETE key value, its source (env/constant/
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
 *
 * The stored state contains a SHA-256 binding (not the key), the boolean
 * verdict, and the check timestamp — never credential material.
 *
 * @since 0.1.0
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
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

/**
 * Provider availability for z.ai.
 *
 * @since 0.1.0
 */
final class ZaiProviderAvailability implements ProviderAvailabilityInterface, WithHttpTransporterInterface, WithRequestAuthenticationInterface {

	// The alias lets the wrapping setHttpTransporter() override below
	// delegate to the trait implementation (traits have no parent:: chain).
	use WithHttpTransporterTrait {
		setHttpTransporter as trait_set_transporter; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias.
	}
	use WithRequestAuthenticationTrait;

	/**
	 * Plugin-owned option persisting the last validated state.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'zai_connector_zai_key_state';

	/**
	 * The core-owned option holding the z.ai API key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_OPTION = 'connectors_ai_zai_api_key';

	/**
	 * Environment variable / constant name core advertises for the key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_ENV_NAME = 'ZAI_API_KEY';

	/**
	 * Seconds a validated verdict stays authoritative before re-probing.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STATE_TTL = 300;

	/**
	 * Clock marker stored with UTC-based timestamps.
	 *
	 * A stored state without this marker predates the UTC switch; its
	 * checked_at cannot be compared reliably (the site offset may have
	 * changed), so such states are treated as stale and re-probed — never
	 * trusted, never fatal.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATE_CLOCK_UTC = 'utc';

	/**
	 * Verdict: the credential is valid (probe returned 2xx).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VERDICT_VALID = 'valid';

	/**
	 * Verdict: the credential was rejected (probe returned 401/403).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VERDICT_INVALID = 'invalid';

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @return bool True when the effective key was validated against the
	 *              currently selected endpoint.
	 */
	public function isConfigured(): bool {
		$effective = $this->effective_key();

		if ( '' === $effective['key'] ) {
			// Nothing to validate; drop any stale verdict.
			delete_option( self::STATE_OPTION );

			return false;
		}

		$binding = $this->binding( $effective['source'], $effective['key'] );
		$state   = $this->stored_state();

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
			return isset( $fallback ) ? $fallback : true;
		}

		$this->persist_state( $binding, $verdict );

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
	 * @since 0.1.0
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

		$env_value = getenv( self::KEY_ENV_NAME );
		if ( \is_string( $env_value ) && '' !== $env_value ) {
			return array(
				'key'    => $env_value,
				'source' => 'env',
			);
		}

		if ( \defined( self::KEY_ENV_NAME ) ) {
			$constant_value = \constant( self::KEY_ENV_NAME );
			if ( \is_string( $constant_value ) && '' !== $constant_value ) {
				return array(
					'key'    => $constant_value,
					'source' => 'constant',
				);
			}
		}

		$db_value = get_option( self::KEY_OPTION, '' );
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
	 * @since 0.1.0
	 *
	 * @param string $key The effective key value.
	 * @return string 'env', 'constant', 'database', or 'runtime' (set by core
	 *                during REST validation before the value is stored).
	 */
	private function key_source( string $key ): string {
		$env_value = getenv( self::KEY_ENV_NAME );
		if ( \is_string( $env_value ) && '' !== $env_value && hash_equals( $env_value, $key ) ) {
			return 'env';
		}

		if ( \defined( self::KEY_ENV_NAME ) ) {
			$constant_value = \constant( self::KEY_ENV_NAME );
			if ( \is_string( $constant_value ) && '' !== $constant_value && hash_equals( $constant_value, $key ) ) {
				return 'constant';
			}
		}

		$db_value = get_option( self::KEY_OPTION, '' );
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
	 * different bindings, and every key-source change invalidates.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source Key source label.
	 * @param string $key    Complete key value.
	 * @return string SHA-256 binding.
	 */
	private function binding( string $source, string $key ): string {
		$endpoint = ZaiEndpoint::for_current_settings();

		return hash( 'sha256', $source . '|' . $endpoint->cache_key() . '|' . $key );
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
	 * @since 0.1.0
	 *
	 * @return bool|null True (valid), false (credential rejected: 401/403),
	 *                   or null when the probe was inconclusive (transport
	 *                   error, 3xx, 429, other 4xx, 5xx) — which says nothing
	 *                   about the credential and must not block key saving.
	 */
	private function probe(): ?bool {
		try {
			$endpoint = ZaiEndpoint::for_current_settings();
			$request  = new Request( HttpMethodEnum::GET(), $endpoint->api_url( 'models' ) );
			$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
			$response = $this->getHttpTransporter()->send( $request );
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
	 * @since 0.1.0
	 *
	 * @return array{binding?: string, valid?: string, checked_at?: int, clock?: string}|null
	 */
	private function stored_state(): ?array {
		$state = get_option( self::STATE_OPTION, null );

		return \is_array( $state ) ? $state : null;
	}

	/**
	 * Persists the validated state (never autoloaded, never the key itself).
	 *
	 * The stored checked_at is UTC (current_time() with $gmt) so a later
	 * site timezone change cannot distort the TTL elapsed-time math; the
	 * clock marker records the basis for safe future interpretation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $binding Credential+endpoint binding.
	 * @param bool   $valid   Whether the credential validated.
	 * @return void
	 */
	private function persist_state( string $binding, bool $valid ): void {
		update_option(
			self::STATE_OPTION,
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
