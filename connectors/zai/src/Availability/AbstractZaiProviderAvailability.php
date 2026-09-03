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
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
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
	 * Seconds an INCONCLUSIVE probe suppresses repeat remote attempts,
	 * scoped to the credential+endpoint binding (code-review GLM1 #6).
	 *
	 * The availability layer is consulted on every request
	 * (ProviderRegistry::isProviderConfigured), and a persistently
	 * inconclusive route — the unprobed cn /models 404 — paid one doomed
	 * blocking HTTPS GET per consult. The marker stores NO verdict: a
	 * cached inconclusive returns exactly what a live inconclusive probe
	 * returns (configured-pending, or a stored matching verdict as the
	 * fallback), so the configured-state semantics are untouched, and a
	 * different key (new binding) or the same binding after the TTL probes
	 * again immediately.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	const PROBE_MISS_TTL = 60;

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
			/*
			 * Nothing to validate; drop any stale verdict — but only when
			 * one exists (GLM1 #6): ProviderRegistry consults availability
			 * on every request, and an unconditional delete_option() ran a
			 * needless DELETE for the missing row each time.
			 */
			if ( null !== get_option( static::STATE_OPTION, null ) ) {
				delete_option( static::STATE_OPTION );
			}

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
			$fresh = self::state_is_fresh( $state );

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

		$verdict = $this->probe_with_negative_cache( $binding, $region_pending );

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

		/*
		 * GLM5 #11: 'runtime' (core's save-time candidate label) and
		 * 'database' (the same credential once stored) are ONE credential
		 * identity — normalizing at binding construction keeps the binding
		 * stable across the save→store transition, so a fresh invalid
		 * verdict persisted while the candidate was wired still refuses
		 * the identical credential once it is read back from the stored
		 * option (the refusal gate previously computed a DIFFERENT binding
		 * per label and let a definitively-rejected key through).
		 */
		if ( 'runtime' === $source ) {
			$source = 'database';
		}

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
	 * Reports whether a stored verdict is still inside its trust TTL.
	 *
	 * UTC on BOTH sides (current_time() with $gmt, not time(), so the
	 * deterministic test clock still drives TTL expiry): a site timezone
	 * change alters no input to this subtraction, so the elapsed-time
	 * math can never go negative ("fresh" for hours). Marker-less states
	 * predate the UTC switch — see STATE_CLOCK_UTC.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $state Stored validated state.
	 * @return bool True while the verdict is within STATE_TTL seconds.
	 */
	private static function state_is_fresh( array $state ): bool {
		return ( $state['clock'] ?? '' ) === self::STATE_CLOCK_UTC
			&& isset( $state['checked_at'] )
			&& ( current_time( 'timestamp', true ) - (int) $state['checked_at'] ) < self::STATE_TTL; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.RequestedUTC -- time() would bypass the deterministic (injectable) clock the TTL tests rely on.
	}

	/**
	 * Reports whether the effective credential must be REFUSED for
	 * generation (Codex R19 #3).
	 *
	 * An env/constant credential cannot be deleted by a region switch, so
	 * the settings layer marks that exact key region-pending and this
	 * layer persists definitive invalid verdicts — but the public
	 * direct-generation path authenticated unconditionally, sending the
	 * old region's credential to the newly selected endpoint while the
	 * connector reported disconnected. This gate READS the same state the
	 * other layers record (reusing this class's own readers — no
	 * duplicated logic, no network probe): a region-pending flag binding
	 * the effective key to the currently selected region, or a fresh
	 * stored verdict that definitively rejected this exact key+endpoint
	 * binding, refuses generation.
	 *
	 * @since 0.2.0
	 *
	 * @param ApiKeyRequestAuthentication|null $authentication The model's
	 *                                                        own auth when
	 *                                                        available (the
	 *                                                        exact credential
	 *                                                        about to
	 *                                                        authenticate);
	 *                                                        null falls back
	 *                                                        to effective_key().
	 * @return string|null 'region_pending' or 'invalid_verdict' when
	 *                     generation must be refused, null when allowed.
	 */
	public function generation_refusal_reason( ?ApiKeyRequestAuthentication $authentication = null ): ?string {
		$effective = null !== $authentication && '' !== $authentication->getApiKey()
			? array(
				'key'    => $authentication->getApiKey(),
				'source' => $this->key_source( $authentication->getApiKey() ),
			)
			: $this->effective_key();

		if ( '' === $effective['key'] ) {
			// Not this gate's concern: the request surfaces its own auth error.
			return null;
		}

		if ( $this->region_switch_pending( $effective['key'] ) ) {
			return 'region_pending';
		}

		$state = $this->stored_state();
		if ( \is_array( $state )
			&& ( $state['binding'] ?? '' ) === $this->binding( $effective['source'], $effective['key'] )
			&& self::state_is_fresh( $state )
			&& self::VERDICT_INVALID === ( $state['valid'] ?? null ) ) {
			return 'invalid_verdict';
		}

		return null;
	}

	/**
	 * Refusal decision for the authentication instance a consumer would
	 * authenticate with (GLM4 #9).
	 *
	 * The credential-refusal gate was copy-pasted at FOUR credential
	 * consumers — both model surfaces' refuse_refused_credentials() and
	 * both metadata directories' discovery gates — with already-divergent
	 * wiring, so the same credential state could reach each consumer's
	 * own idea of the rule and GLM3 #9 had to fix one copy in
	 * isolation. This ONE predicate is the gate every consumer consults:
	 * it applies the ApiKey-shape skip (foreign/unset wiring is not the
	 * gate's concern) and delegates to generation_refusal_reason() with
	 * the consumer's exact credential — the same state now yields the
	 * same decision on every surface. Consumers keep their own error
	 * SURFACES (the models' typed InvalidArgumentException, the
	 * directories' never-fatal discovery fallback) and their own wiring
	 * choices (the zai_anthropic model reads the RAW wired instance so a
	 * foreign wiring failure surfaces as the 500 binding error, GLM3
	 * #9; the zai_anthropic directory reads its protocol-wrapping
	 * override).
	 *
	 * @since 0.2.0
	 *
	 * @param RequestAuthenticationInterface|null $authentication The wired
	 *                                        authentication the consumer
	 *                                        would authenticate with, or
	 *                                        null when unwired.
	 * @return string|null 'region_pending' or 'invalid_verdict' when the
	 *                     gate refuses, null when it does not apply.
	 */
	public function generation_refusal_for_wired_authentication( ?RequestAuthenticationInterface $authentication ): ?string {
		if ( ! $authentication instanceof ApiKeyRequestAuthentication ) {
			return null;
		}

		return $this->generation_refusal_reason( $authentication );
	}

	/**
	 * The fixed refusal message for a gate decision (GLM4 #9).
	 *
	 * Both model surfaces built these strings inline, one provider label
	 * apart; one builder keeps the wording from drifting between the
	 * surfaces the way the gate itself did.
	 *
	 * @since 0.2.0
	 *
	 * @param string $provider_label Provider name for the message ('zai' or 'zai_anthropic').
	 * @param string $reason         Refusal reason from the gate.
	 * @return string The fixed, safe message.
	 */
	public static function refusal_message( string $provider_label, string $reason ): string {
		return 'region_pending' === $reason
			? sprintf( 'The %s provider refuses generation: the active environment credential is pending revalidation after a region switch.', $provider_label )
			: sprintf( 'The %s provider refuses generation: the active credential was rejected for the selected endpoint.', $provider_label );
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
	 * Probes with a SHORT binding-scoped negative cache (GLM1 #6).
	 *
	 * A recently-inconclusive probe for this exact key+endpoint binding
	 * returns the same inconclusive outcome WITHOUT another doomed remote
	 * attempt; definitive results drop any marker (and persist the verdict
	 * as always). Nothing about the returned semantics changes — only the
	 * repeat transport cost collapses for PROBE_MISS_TTL seconds.
	 *
	 * Region-switch distrust (the R19 contract) is EXEMPT: while the
	 * effective key is region-pending, every consult must keep probing so
	 * the definitive validation happens as soon as the endpoint can answer
	 * — suppressing it would hold the connector falsely disconnected for
	 * up to a minute after a switch even when the endpoint is ready.
	 *
	 * @since 0.2.0
	 *
	 * @param string $binding  Credential+endpoint binding.
	 * @param bool   $distrusted Whether the region-pending distrust binds this key.
	 * @return bool|null As probe(): true, false, or null (inconclusive).
	 */
	private function probe_with_negative_cache( string $binding, bool $distrusted ) {
		if ( $distrusted ) {
			return $this->probe();
		}

		$miss_transient = self::probe_miss_transient_name( $binding );

		if ( get_transient( $miss_transient ) ) {
			return null;
		}

		$verdict = $this->probe();

		if ( null === $verdict ) {
			set_transient( $miss_transient, true, self::PROBE_MISS_TTL );
		} else {
			delete_transient( $miss_transient );
		}

		return $verdict;
	}

	/**
	 * Deletes the probe-miss marker of the CURRENT effective binding
	 * (code-review GLM2 #6).
	 *
	 * The live probe (bin/zai-live-probe.php) exists to exercise the LIVE
	 * network path on every run, so it clears the positive caches (the
	 * availability state option, the discovery transient) before its
	 * acceptance steps — but the GLM1 #6 negative markers survived that
	 * clearing, letting those steps serve a 60-second-old inconclusive
	 * verdict (probe-miss, discovery '_miss') instead of the live request
	 * they exist to verify. This derives the exact marker name the NEXT
	 * isConfigured() consult would read for the current effective key and
	 * endpoint — no network request — so callers that need a guaranteed
	 * live probe can clear it alongside the positive state.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function clear_probe_miss_marker(): void {
		$effective = $this->effective_key();

		if ( '' === $effective['key'] ) {
			// No effective credential: no binding, so no marker to clear.
			return;
		}

		delete_transient( self::probe_miss_transient_name( $this->binding( $effective['source'], $effective['key'] ) ) );
	}

	/**
	 * Builds the binding-scoped probe-miss transient name.
	 *
	 * One construction site shared by the writer (probe_with_negative_cache)
	 * and the live-probe clearer, so the two can never drift apart.
	 *
	 * @since 0.2.0
	 *
	 * @param string $binding Credential+endpoint binding.
	 * @return string Transient name holding the miss marker.
	 */
	private static function probe_miss_transient_name( string $binding ): string {
		return static::STATE_OPTION . '_probe_' . md5( $binding );
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

			/*
			 * GLM5 #10: the SDK registry wires provider credentials from
			 * env/constant ONLY, so a DATABASE-only key rode an UNWIRED
			 * probe: getRequestAuthentication() threw the binding
			 * RuntimeException, the catch below counted the probe
			 * inconclusive, and isConfigured() reported connected
			 * (configured-pending) FOREVER without a single validation
			 * request — defeating this class's own 'nonempty-but-invalid
			 * key must report not-connected' contract. When no auth is
			 * wired, the probe authenticates with the EFFECTIVE key (the
			 * same resolution effective_key() performs) through the
			 * surface's fallback authentication, so the database-only key
			 * actually validates against the endpoint.
			 */
			try {
				$authentication = $this->getRequestAuthentication();
			} catch ( Throwable $unwired ) {
				$effective = $this->effective_key();

				if ( '' === $effective['key'] ) {
					// Nothing to authenticate with: as inconclusive as
					// before the fallback existed.
					return null;
				}

				$authentication = static::fallback_authentication( $effective['key'] );
			}

			$request  = $authentication->authenticateRequest( $request );
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
	 * Builds the authentication an UNWIRED probe authenticates with
	 * (GLM5 #10).
	 *
	 * The base returns the plain SDK API-key authentication (the zai
	 * surface's protocol); the zai_anthropic surface overrides to
	 * protocol-wrap the key — its probe requests must carry the Anthropic
	 * surface's headers, exactly like its wired requests do.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The effective credential.
	 * @return RequestAuthenticationInterface
	 */
	protected static function fallback_authentication( string $key ): RequestAuthenticationInterface {
		return new ApiKeyRequestAuthentication( $key );
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
