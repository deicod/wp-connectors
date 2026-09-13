<?php
/**
 * Shared provider availability: authenticated probe with persisted validated state.
 *
 * Mere key presence is NOT availability (Tasks 1.4/2.1): a nonempty-but-invalid
 * key must report not-connected. This implementation probes the provider's
 * model-list endpoint with the effective credential and persists the
 * verdict, bound to the COMPLETE key value, its source (env/constant/
 * database/runtime) and the endpoint identity (provider+plan+region). Only
 * a definitive credential rejection (401/403, or a 2xx body whose failure
 * envelope rejects the credential — GLM12 #1: the Anthropic /v1/models
 * route answers 200 for any or no credential — or glm26-1: credential
 * material the Authorization header cannot carry, rejected pre-transport
 * with nothing flown, the verdict naming exactly that material) reports
 * not-connected;
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
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use Deicod\WpConnectors\Zai\Authentication\UncarriableCredentialException;
use Deicod\WpConnectors\Zai\Endpoints\AbstractZaiEndpoint;
use Deicod\WpConnectors\Zai\Metadata\ZaiDiscoveryCache;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelListParser;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;

/**
 * Provider availability with a persisted, credential-bound validated state.
 *
 * GLM6 #12: every IDENTIFIER constant (the state/region-pending/key
 * option names, the env/constant name, the refusal label) is DECLARED
 * BY THE CHILD — this base carries no provider's defaults. A future
 * child that overrides endpoint_class()/settings_class() but forgets
 * one declaration gets an immediate undefined-constant fatal at its
 * first use (loud), never a silent read/write of the zai provider's
 * key and state options — the invariant this class's own binding
 * scoping exists to guarantee.
 *
 * @since 0.2.0
 */
abstract class AbstractZaiProviderAvailability implements ProviderAvailabilityInterface, WithHttpTransporterInterface, WithRequestAuthenticationInterface {

	// The alias lets the wrapping setHttpTransporter() override below
	// delegate to the trait implementation (traits have no parent:: chain).
	use WithHttpTransporterTrait {
		setHttpTransporter as trait_set_transporter; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias.
	}
	use WithRequestAuthenticationTrait {
		getRequestAuthentication as trait_get_request_authentication; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias (glm16-1: the RAW getter stays callable below a surface's protocol-wrapping override).
	}

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
	 * Verdict: the credential is valid (probe returned 2xx with an
	 * authenticated model-list body — GLM12 #1: the status alone proves
	 * nothing on z.ai's Anthropic /v1/models route).
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
	 * The HTTP statuses whose answer definitively rejects the CREDENTIAL
	 * itself (401 bad token; 403 no access for this key).
	 *
	 * GLM10 #8: the ONE source for what counts as a definitive credential
	 * rejection — the probe's verdict branch and both directories'
	 * discovery-response recording consult it. The set was encoded a
	 * third time at each site and kept in lockstep by convention that
	 * already failed once (GLM7 #12 landed one directory only; glm9-5
	 * re-landed the twin): a missed side leaves a server-side-revoked
	 * key passing isConfigured() on one surface for up to the 300s
	 * STATE_TTL.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	const DEFINITIVE_REJECTION_STATUSES = array( 401, 403 );

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
	 * The RAW wired authentication — exactly as the SDK stores it, before
	 * any surface protocol wrap (glm16-1).
	 *
	 * The base's own getRequestAuthentication() IS the raw SDK getter
	 * (no protocol wrap on the zai surface); the zai_anthropic surface
	 * overrides getRequestAuthentication() through
	 * SpeaksAnthropicMessagesProtocol to funnel every wired instance
	 * through the protocol wrap, and inherits this hook so the wrap
	 * stays the surface's ONE funnel while the wiring rules below still
	 * see the instance the registry actually stored. Reading through a
	 * wrapping getter would launder a foreign (non-Api-key) wiring into
	 * the wrap()'s RuntimeException, which the probe's unwired catch
	 * would misread as NO wiring — flying the fallback credential a
	 * caller never wired and persisting the verdict it earned under
	 * that key's binding (the glm14-5 opaque guard, made unreachable).
	 * Same hook name and meaning as SpeaksAnthropicMessagesProtocol's
	 * abstract raw_request_authentication(), so a composing surface
	 * satisfies both with the one inherited implementation.
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 * @throws RuntimeException When nothing is wired (the SDK getter's
	 *                          own unwired failure — the ONLY throw this
	 *                          accessor can produce).
	 */
	protected function raw_request_authentication(): RequestAuthenticationInterface {
		return $this->trait_get_request_authentication();
	}

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.2.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		$this->trait_set_transporter( LoggingHttpTransporter::wrap( $http_transporter ) );
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

		/*
		 * glm37-8: ONE endpoint resolution per consult (the glm26-5/GLM10 #1
		 * precompute-then-pass shape — binding() already accepts the
		 * request-captured cache key; region_switch_pending() takes the same
		 * consult-local endpoint now). The resolution reads the plan/region
		 * options and sanitizes both enums; consulting it twice — binding()'s
		 * internal default, then region_switch_pending()'s own — paid the
		 * reads twice on every isConfigured() call (ProviderRegistry consults
		 * availability on every request). glm15-6's per-consult-read boundary
		 * is untouched: this threads WITHIN one synchronous consult, no memo.
		 */
		$endpoint_class = static::endpoint_class();
		$endpoint       = $endpoint_class::for_current_settings();

		$binding = $this->binding( $effective['source'], $effective['key'], $endpoint->cache_key() );
		$state   = $this->stored_state();

		// Region-switch distrust (set by the settings layer after a region
		// change): while the effective key is exactly the env/constant
		// credential that rode the switch, only a DEFINITIVE result may
		// report it connected — see region_switch_pending().
		$region_pending = $this->region_switch_pending( $effective['key'], $endpoint );

		if ( \is_array( $state ) && ( $state['binding'] ?? '' ) === $binding ) {
			// UTC on BOTH sides (current_time() with $gmt, not time(), so the
			// deterministic test clock still drives TTL expiry): a site
			// timezone change alters no input to this subtraction, and
			// state_is_fresh() bounds the elapsed below at zero (GLM10 #2).
			// Marker-less states predate the UTC switch — see STATE_CLOCK_UTC.
			$fresh = self::state_is_fresh( $state );

			if ( $fresh ) {
				// A fresh verdict for this exact binding IS the definitive
				// answer the distrust waits for (defensively: persisting
				// one already clears the flag).
				$this->settle_region_pending( $region_pending );

				return self::VERDICT_VALID === ( $state['valid'] ?? null );
			}

			// Stale-but-matching verdict: probe again, but remember it as the
			// fallback for inconclusive probes below.
			$fallback = self::VERDICT_VALID === ( $state['valid'] ?? null );
		}

		$verdict = $this->probe_with_negative_cache( $binding );

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
			//
			// glm21-2 (consciously accepted, ledger): the inconclusive
			// set includes the 200-body classes glm13-2 made
			// inconclusive (an empty data list, an incomplete has_more
			// page, a non-JSON 2xx body) that master's status-only
			// probe blessed as connected — so a region whose /models
			// persistently answers one of those shapes keeps the
			// connector not-configured and generation refused until the
			// endpoint answers definitively or the admin intervenes
			// (region back, or a re-saved/re-wired credential); probes
			// throttle to one per PROBE_MISS_TTL window. SPEC §3.3 wins
			// over that master delta.
			if ( $region_pending ) {
				return false;
			}

			return isset( $fallback ) ? $fallback : true;
		}

		$this->persist_state( $binding, $verdict );

		// Definitive answer about the riding credential (valid or
		// rejected): the distrust is resolved either way.
		$this->settle_region_pending( $region_pending );

		return $verdict;
	}

	/**
	 * Returns the effective credential and where it came from.
	 *
	 * The registry-wired authentication object is authoritative (it is what
	 * actual requests authenticate with — including the candidate key core
	 * sets during REST settings validation). When nothing is wired, the
	 * core resolution order applies: env var, then constant (both rungs
	 * delegated to the SDK-free settings layer's shared
	 * env_constant_ladder(), GLM7 #17), then the database option.
	 *
	 * @since 0.2.0
	 *
	 * @return array{key: string, source: string} Empty key with source 'none' when unavailable.
	 */
	public function effective_key(): array {
		/*
		 * glm34-6 (round-34 finding 6): the RAW hook, like the probe's
		 * own reader (glm16-1) — this was the one availability reader
		 * still routing through the wrapping getter, so on zai_anthropic
		 * a foreign (non-Api-key) wiring surfaced as wrap()'s
		 * type-refusal RuntimeException INSIDE this try and laundered
		 * into 'nothing wired', reporting the ladder key as effective.
		 * Output was identical only because both states fall to the
		 * ladder; the raw read distinguishes them structurally (a
		 * foreign instance fails the instanceof below, no exception),
		 * and the catch's one sanctioned throw is back to the raw
		 * accessor's own unwired RuntimeException (the hook's
		 * docblock). The probe's FLIGHT keeps the wrap funnel — only
		 * resolve_probe_authentication() re-wraps, after the raw shape
		 * check.
		 *
		 * glm37-7: the guarded read rides wired_or_null() (glm36-4's one
		 * owner) — RuntimeException-width like the three reader consults
		 * it serves. The hook's docblock names RuntimeException its ONLY
		 * throw, so anything else is a contract violation that must
		 * surface loudly here exactly as it already does through every
		 * wired_or_null() site, never launder into 'nothing wired' with
		 * the ladder key reported effective (the cross-credential shape
		 * glm34-6's raw read exists to prevent).
		 */
		$authentication = $this->wired_or_null(
			function (): RequestAuthenticationInterface {
				return $this->raw_request_authentication();
			}
		);

		if ( $authentication instanceof ApiKeyRequestAuthentication && '' !== $authentication->getApiKey() ) {
			return $this->wired_credential( $authentication );
		}

		$settings_class = static::settings_class();

		/*
		 * glm24-8: the ladder is the owner-ordered list of NON-EMPTY
		 * rungs, so the first-rung-wins resolution is one key fetch —
		 * the former foreach returned unconditionally on its first
		 * iteration, a loop silhouette that implied rung iteration
		 * mattered (key_source()'s genuine search loop below is the
		 * contrast case). An empty ladder falls through to the database
		 * branch exactly as before.
		 */
		$ladder = $settings_class::env_constant_ladder();
		$source = array_key_first( $ladder );

		if ( null !== $source ) {
			return array(
				'key'    => $ladder[ $source ],
				'source' => $source,
			);
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
	 * The env/constant rungs match through the shared settings-layer
	 * ladder (GLM7 #17); each source is compared independently, in
	 * resolution order, exactly as the previous inline copies did.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The effective key value.
	 * @return string 'env', 'constant', 'database', or 'runtime' (set by core
	 *                during REST validation before the value is stored).
	 */
	private function key_source( string $key ): string {
		$settings_class = static::settings_class();

		foreach ( $settings_class::env_constant_ladder() as $source => $value ) {
			if ( hash_equals( $value, $key ) ) {
				return $source;
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
	 * @param string      $source            Key source label.
	 * @param string      $key               Complete key value.
	 * @param string|null $endpoint_cache_key The endpoint identity the verdict
	 *                                        is about — cache_key() CAPTURED at
	 *                                        REQUEST time (GLM10 #1) — or null to
	 *                                        re-resolve the current settings.
	 * @return string SHA-256 binding.
	 */
	private function binding( string $source, string $key, ?string $endpoint_cache_key = null ): string {
		$settings_class = static::settings_class();

		if ( null === $endpoint_cache_key ) {
			$endpoint_class     = static::endpoint_class();
			$endpoint_cache_key = $endpoint_class::for_current_settings()->cache_key();
		}

		/*
		 * GLM5 #11: 'runtime' (core's save-time candidate label) and
		 * 'database' (the same credential once stored) are ONE credential
		 * identity — normalizing at binding construction keeps the binding
		 * stable across the save→store transition, so a fresh invalid
		 * verdict persisted while the candidate was wired still refuses
		 * the identical credential once it is read back from the stored
		 * option (the refusal gate previously computed a DIFFERENT binding
		 * per label and let a definitively-rejected key through).
		 *
		 * GLM9 #8: the hash itself rides the SDK-free settings owner's
		 * credential_binding() — uninstall.php's deterministic probe-miss
		 * sweep composes through the same owner, and a private copy here
		 * was the mirror a composition change silently stranded (the
		 * GLM5 #11 label split already forced one lockstep edit there).
		 * The normalization stays with THIS writer: the sweep iterates the
		 * literal label set so the pre-normalization rows stay derivable.
		 */
		if ( 'runtime' === $source ) {
			$source = 'database';
		}

		return $settings_class::credential_binding( $source, $endpoint_cache_key, $key );
	}

	/**
	 * The credential an optional wired authentication represents, falling
	 * back to the effective key (GLM9 #14).
	 *
	 * The optional-authentication ternary — the wired ApiKey's key plus
	 * its derived source when it carries a non-empty key, else
	 * effective_key() — was copy-pasted verbatim between
	 * generation_refusal_reason() and record_definitive_verdict(): the
	 * copy-paste pattern GLM3 #9 fixed at the four gate consumers but
	 * left duplicated here, where the refusal gate and the verdict
	 * recorder deciding through divergent copies means they can disagree
	 * about WHICH credential a verdict binds (the GLM5 #11 divergence
	 * class: one label split a credential into two bindings and let a
	 * definitively rejected key through the gate).
	 *
	 * @since 0.2.0
	 *
	 * @param ApiKeyRequestAuthentication|null $authentication The wired
	 *                                                        credential, or
	 *                                                        null to resolve
	 *                                                        the effective
	 *                                                        key.
	 * @return array{key: string, source: string} The credential and its source.
	 */
	private function effective_for_authentication( ?ApiKeyRequestAuthentication $authentication ): array {
		if ( null === $authentication || '' === $authentication->getApiKey() ) {
			return $this->effective_key();
		}

		return $this->wired_credential( $authentication );
	}

	/**
	 * The credential pair a NON-EMPTY wired ApiKey represents (glm30-6).
	 *
	 * The pair construction — the wired key plus its derived source —
	 * was spelled inline in effective_key() and again in
	 * effective_for_authentication() (the GLM9 #14 extraction left the
	 * mapping itself duplicated): a change to the pair's shape (a new
	 * member, a different source derivation) had to land in both, and
	 * the gate and the verdict recorder reading divergent copies is the
	 * GLM5 #11 divergence class. One owner; the empty/null paths stay
	 * with their callers (effective_key()'s ladder and
	 * effective_for_authentication()'s fallback deliberately differ).
	 *
	 * @since 0.2.0
	 *
	 * @param ApiKeyRequestAuthentication $authentication The wired credential (non-empty key).
	 * @return array{key: string, source: string} The credential and its source.
	 */
	private function wired_credential( ApiKeyRequestAuthentication $authentication ): array {
		return array(
			'key'    => $authentication->getApiKey(),
			'source' => $this->key_source( $authentication->getApiKey() ),
		);
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
	 * @param string                   $key      Complete effective key value.
	 * @param AbstractZaiEndpoint|null $endpoint The consult's already-resolved
	 *                                        endpoint (glm37-8), or null to
	 *                                        resolve the current settings
	 *                                        here.
	 * @return bool True when the flag binds this exact key to the currently
	 *              selected region.
	 */
	private function region_switch_pending( string $key, ?AbstractZaiEndpoint $endpoint = null ): bool {
		$flag = get_option( static::REGION_PENDING_OPTION, null );

		if ( ! \is_array( $flag ) ) {
			return false;
		}

		$region      = $flag['region'] ?? null;
		$fingerprint = $flag['fingerprint'] ?? null;

		if ( ! \is_string( $region ) || ! \is_string( $fingerprint ) || '' === $fingerprint ) {
			return false;
		}

		if ( null === $endpoint ) {
			// glm37-8: isConfigured() passes the endpoint its consult
			// already resolved; callers without one (the refusal gate,
			// the settle check in record_definitive_verdict) resolve here
			// exactly as before.
			$endpoint_class = static::endpoint_class();
			$endpoint       = $endpoint_class::for_current_settings();
		}

		return $endpoint->region() === $region
			&& hash_equals( $fingerprint, hash( 'sha256', $key ) );
	}

	/**
	 * Settles the region-switch distrust when this consult's precomputed
	 * pending state says a definitive answer arrived (glm26-5).
	 *
	 * The settle rule — a definitive result about the riding credential
	 * resolves the distrust EITHER way (a fresh matching verdict, a
	 * verdict the probe just persisted, or a verdict another component
	 * recorded for the CURRENT endpoint) — was hand-copied at three
	 * sites (isConfigured()'s fresh-verdict branch, its post-probe
	 * branch, and record_definitive_verdict()'s current-endpoint
	 * guard): the class's own GLM4 #9/GLM9 #11 extraction discipline
	 * applies — a future edit to when distrust resolves must land on
	 * the one helper or nowhere, never two-of-three, where a missed
	 * copy leaves a validated credential permanently refused as
	 * region_pending ('pending revalidation after a region switch').
	 *
	 * @since 0.2.0
	 *
	 * @param bool $region_pending The consult's region_switch_pending() result.
	 * @return void
	 */
	private function settle_region_pending( bool $region_pending ): void {
		if ( $region_pending ) {
			delete_option( static::REGION_PENDING_OPTION );
		}
	}

	/**
	 * Reports whether a stored verdict is still inside its trust TTL.
	 *
	 * UTC on BOTH sides (current_time() with $gmt, not time(), so the
	 * deterministic test clock still drives TTL expiry): a site timezone
	 * change alters no input to this subtraction. Marker-less states
	 * predate the UTC switch — see STATE_CLOCK_UTC.
	 *
	 * GLM10 #2: the elapsed time is additionally bounded BELOW at zero.
	 * A checked_at in the future — clock skew between web nodes, a state
	 * restored from an ahead-clocked server — made the subtraction
	 * negative and the < STATE_TTL test trivially true, so the verdict
	 * read fresh for as long as the skew lasted (a server-side-revoked
	 * key reporting connected far past the advertised TTL). A future
	 * checked_at cannot be aged: the state is distrusted and the consult
	 * re-probes, and the probe rewrites checked_at on THIS node's clock —
	 * healing the skew.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $state Stored validated state.
	 * @return bool True while the verdict is within STATE_TTL seconds.
	 */
	private static function state_is_fresh( array $state ): bool {
		if ( ( $state['clock'] ?? '' ) !== self::STATE_CLOCK_UTC || ! isset( $state['checked_at'] ) ) {
			return false;
		}

		$elapsed = current_time( 'timestamp', true ) - (int) $state['checked_at']; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.RequestedUTC -- time() would bypass the deterministic (injectable) clock the TTL tests rely on.

		return $elapsed >= 0 && $elapsed < self::STATE_TTL;
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
	 * glm25-2: PRIVATE behind its always-narrowed wrapper
	 * (generation_refusal_for_wired_authentication()) — the former
	 * public default-null path resolved an effective_key()-based
	 * verdict that bypassed the wrapper's ApiKey-shape skip (the
	 * GLM3 #9 gate-divergence class) and had no production caller;
	 * consumers and tests ride the wrapper.
	 *
	 * @since 0.2.0
	 *
	 * @param ApiKeyRequestAuthentication $authentication The consumer's
	 *                                                   exact credential
	 *                                                   (the instance the
	 *                                                   wrapper narrowed).
	 * @return string|null 'region_pending' or 'invalid_verdict' when
	 *                     generation must be refused, null when allowed.
	 */
	private function generation_refusal_reason( ApiKeyRequestAuthentication $authentication ): ?string {
		$effective = $this->effective_for_authentication( $authentication );

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
	 * #9; glm26-4: the zai_anthropic directory's discovery auth-reader
	 * judges the RAW wired instance too — its FLIGHT still rides the
	 * one protocol-wrap funnel, but a reader through the wrap was the
	 * call-ordering accident that held the glm14-5 poisoning one
	 * refactor away).
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
	 * surfaces the way the gate itself did. glm25-2: PRIVATE — its only
	 * caller is refuse_generation(), so the wording is observed through
	 * the throw both model surfaces ride, not a public static surface.
	 *
	 * @since 0.2.0
	 *
	 * @param string $provider_label Provider name for the message (the consuming surface's REFUSAL_LABEL).
	 * @param string $reason         Refusal reason from the gate.
	 * @return string The fixed, safe message.
	 */
	private static function refusal_message( string $provider_label, string $reason ): string {
		return 'region_pending' === $reason
			? sprintf( 'The %s provider refuses generation: the active environment credential is pending revalidation after a region switch.', $provider_label )
			: sprintf( 'The %s provider refuses generation: the active credential was rejected for the selected endpoint.', $provider_label );
	}

	/**
	 * Invokes an authentication reader, mapping the unwired state to null.
	 *
	 * The ONE guarded invocation the three reader consults shared by
	 * hand (glm36-4) — refuse_generation(), refuse_discovery(), and
	 * record_rejection_via_reader() each wrapped the reader call in the
	 * same RuntimeException catch (the readers' documented unwired
	 * signal, GLM10 #8), so a future policy change (a second unwired
	 * exception type, a diagnostic hook on the unwired read) would have
	 * to land three times and miss one — the GLM5 #11 divergence class
	 * this file's own docblocks name. The readers are typed non-null,
	 * so null unambiguously means unwired.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $authentication_reader Returns the wired
	 *                                        RequestAuthenticationInterface
	 *                                        (throws RuntimeException when
	 *                                        unwired).
	 * @return RequestAuthenticationInterface|null The wired credential, or null when unwired.
	 */
	private function wired_or_null( callable $authentication_reader ): ?RequestAuthenticationInterface {
		try {
			return $authentication_reader();
		} catch ( RuntimeException $unwired ) {
			return null;
		}
	}

	/**
	 * Refuses MODEL GENERATION for a distrusted credential, or returns
	 * quietly (GLM5 #17: the gate wrappers absorbed).
	 *
	 * The wrapper sequence BOTH model surfaces repeated by hand — read
	 * the wired authentication (an UNWIRED model is not the gate's
	 * concern: skipping preserves the pre-gate exception order for
	 * callers that misuse an unbound model while also carrying invalid
	 * options), consult the shared predicate, build the surface's fixed
	 * message, throw — lives here once. Each surface passes ONLY its
	 * wiring choice: the reader closure returning the authentication
	 * instance it would authenticate with (the zai surface's own getter;
	 * the zai_anthropic surface's RAW parent getter, so a foreign-wiring
	 * failure surfaces as the 500 binding error rather than a 400
	 * option-rejection, GLM3 #9).
	 *
	 * @since 0.2.0
	 *
	 * @param callable $authentication_reader Returns the wired
	 *                                        RequestAuthenticationInterface
	 *                                        (throws RuntimeException when
	 *                                        unwired, which skips the gate).
	 * @return void
	 * @throws InvalidArgumentException When the gate refuses the credential.
	 */
	public function refuse_generation( callable $authentication_reader ): void {
		$authentication = $this->wired_or_null( $authentication_reader );
		if ( null === $authentication ) {
			return;
		}

		$refusal = $this->generation_refusal_for_wired_authentication( $authentication );

		if ( null !== $refusal ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design (GLM1 #5); escaping belongs to the display layer.
			throw new InvalidArgumentException( self::refusal_message( static::REFUSAL_LABEL, $refusal ) );
		}
	}

	/**
	 * Refuses DISCOVERY ENUMERATION for a distrusted credential, or
	 * returns quietly (GLM5 #17: the gate wrappers absorbed).
	 *
	 * The wrapper sequence BOTH metadata directories repeated by hand —
	 * consult the shared predicate with the wired authentication, throw
	 * the never-fatal discovery-skip ResponseException the shared cache
	 * catch turns into the plan fallback — lives here once. An UNWIRED
	 * directory skips the gate: the SDK's own request build then throws
	 * the same binding failure into the same catch, so the fallback
	 * still wins.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $authentication_reader Returns the wired
	 *                                        RequestAuthenticationInterface.
	 * @return void
	 * @throws ResponseException When the gate refuses the credential.
	 */
	public function refuse_discovery( callable $authentication_reader ): void {
		$authentication = $this->wired_or_null( $authentication_reader );
		if ( null === $authentication ) {
			return;
		}

		if ( null !== $this->generation_refusal_for_wired_authentication( $authentication ) ) {
			/*
			 * GLM10 #9 (verifier round): the label rides the surface's
			 * own REFUSAL_LABEL — the availability base previously named
			 * BOTH surfaces' discovery skip after the z.ai brand, one
			 * surface's discovery-path rejections contradicting the
			 * one-way naming the models' guards had already unified on.
			 */
			throw ResponseException::fromInvalidData( static::REFUSAL_LABEL, 'data', 'Discovery skipped: the credential is pending revalidation or was rejected for this endpoint.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design; the label is the class-owned constant (GLM10 #9).
		}
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
	 * GLM7 #3: region-switch distrust (the R19 contract) is NO LONGER
	 * exempt. The exemption made every consult re-issue a live blocking
	 * authenticated HTTPS probe — re-transmitting the old-region credential
	 * to the new endpoint each time — for as long as the endpoint answers
	 * inconclusively (the permanently-404 cn /models shape), with no cap.
	 * Distrust now consults the same 60s marker: at most one doomed probe
	 * per PROBE_MISS_TTL window, the definitive validation still happening
	 * as soon as the endpoint can answer within that granularity, and a
	 * definitive result always clears the marker immediately.
	 *
	 * @since 0.2.0
	 *
	 * @param string $binding Credential+endpoint binding.
	 * @return bool|null As probe(): true, false, or null (inconclusive).
	 */
	private function probe_with_negative_cache( string $binding ) {
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
	 * Records a DEFINITIVE verdict about the effective credential that a
	 * component OTHER than the probe learned (GLM7 #12).
	 *
	 * The zai_anthropic discovery route learns exactly what the probe
	 * learns — a 401/403 is the endpoint itself rejecting the credential
	 * — but its failures surfaced as the misattributed "Missing the
	 * \"data\" key" ResponseException and the shared discovery cache
	 * converted them into the silent 60s negative marker plus static-plan
	 * fallback: no persisted verdict, so isConfigured() kept reporting
	 * configured-pending and the refusal gates kept allowing the rejected
	 * key until the marker expired. Recording through the probe's own
	 * persist path keeps ONE verdict store: the state option is written
	 * bound to the SAME credential+endpoint binding a probe verdict would
	 * use, the binding's probe-miss marker is dropped (a definitive
	 * answer is never blocked by one), and an open region-switch distrust
	 * resolves — a definitive answer about the riding credential settles
	 * it either way, the isConfigured() rule.
	 *
	 * @since 0.2.0
	 *
	 * @param bool                             $valid             The definitive verdict.
	 * @param ApiKeyRequestAuthentication|null $authentication    The credential the
	 *                                                             rejecting request
	 *                                                             authenticated with
	 *                                                             (the wired instance),
	 *                                                             or null to resolve
	 *                                                             the effective key.
	 * @param string|null                      $endpoint_cache_key The cache_key() of
	 *                                                             the endpoint the
	 *                                                             rejecting request
	 *                                                             actually hit,
	 *                                                             captured at REQUEST
	 *                                                             time (GLM10 #1), or
	 *                                                             null when the caller
	 *                                                             asserts the verdict
	 *                                                             concerns the current
	 *                                                             settings.
	 * @return void
	 */
	public function record_definitive_verdict( bool $valid, ?ApiKeyRequestAuthentication $authentication = null, ?string $endpoint_cache_key = null ): void {
		/*
		 * glm13-1: an EMPTY wired credential authenticates nothing a
		 * verdict could name. A definitive answer earned by a request the
		 * EMPTY credential flew (a generation or discovery request the SDK
		 * authenticated with the wired instance) says nothing about the
		 * ladder/database credential effective_for_authentication() would
		 * resolve after skipping the empty key — persisting under that
		 * binding is the cross-credential poisoning the probe's own empty
		 * rule (see probe()) refuses. Nothing flew that a verdict could
		 * name: record nothing.
		 */
		if ( null !== $authentication && '' === $authentication->getApiKey() ) {
			return;
		}

		$effective = $this->effective_for_authentication( $authentication );

		if ( '' === $effective['key'] ) {
			return;
		}

		/*
		 * GLM10 #1: the verdict is about the endpoint the rejecting
		 * request HIT, not the endpoint the settings resolve to by the
		 * time the response lands. Recording under the re-resolved
		 * binding persisted an intl rejection under the cn identity when
		 * an admin saved the region mid-flight — isConfigured() on cn
		 * then answered not-connected for a key never tested against cn
		 * (up to STATE_TTL), while the intl endpoint that rejected got
		 * no verdict at all. Callers pass the endpoint they captured at
		 * request time; the marker dropped is the same (recorded)
		 * binding's.
		 */
		$binding = $this->binding( $effective['source'], $effective['key'], $endpoint_cache_key );

		$this->persist_state( $binding, $valid );
		delete_transient( self::probe_miss_transient_name( $binding ) );

		/*
		 * GLM10 #1: the region-switch distrust targets the CURRENT
		 * endpoint. A verdict recorded for a DIFFERENT endpoint (the
		 * mid-flight race above) settles nothing about the new region:
		 * clearing the flag on it would re-open the R19 hole — the riding
		 * credential reported configured-pending (connected) on the new
		 * endpoint without any definitive answer about THAT endpoint.
		 */
		$endpoint_class = static::endpoint_class();

		if ( null === $endpoint_cache_key || $endpoint_cache_key === $endpoint_class::for_current_settings()->cache_key() ) {
			$this->settle_region_pending( $this->region_switch_pending( $effective['key'] ) );
		}
	}

	/**
	 * Reports whether an HTTP status is a DEFINITIVE credential
	 * rejection — the one predicate every status-judging site consults
	 * (GLM10 #8; see DEFINITIVE_REJECTION_STATUSES).
	 *
	 * @since 0.2.0
	 *
	 * @param int $status HTTP status code.
	 * @return bool True for the statuses that reject the credential itself.
	 */
	public static function is_definitive_rejection( int $status ): bool {
		return \in_array( $status, self::DEFINITIVE_REJECTION_STATUSES, true );
	}

	/**
	 * Records the definitive invalid verdict a credential-rejecting
	 * status represents, when it is one (GLM10 #8).
	 *
	 * The recording block both metadata directories hand-copied — the
	 * status test, the RuntimeException-guarded wired-auth read, the
	 * instanceof narrowing, the record_definitive_verdict() call —
	 * lives here once, in the same reader-closure shape refuse_discovery()
	 * established. Non-definitive statuses record nothing (the GLM7 #12
	 * guard: an inconclusive failure must not poison the verdict store).
	 *
	 * @since 0.2.0
	 *
	 * @param int         $status            The HTTP status the endpoint answered with.
	 * @param callable    $authentication_reader Returns the wired
	 *                                        authentication the rejecting request
	 *                                        authenticated with (throws
	 *                                        RuntimeException when unwired, which
	 *                                        resolves the effective key instead;
	 *                                        an opaque non-Api-key instance flew
	 *                                        the request and records NOTHING —
	 *                                        glm14-5).
	 * @param string|null $endpoint_cache_key The request-time endpoint identity
	 *                                        (GLM10 #1), or null for the current
	 *                                        settings.
	 * @return void
	 */
	public function record_rejection_for_status( int $status, callable $authentication_reader, ?string $endpoint_cache_key = null ): void {
		if ( ! self::is_definitive_rejection( $status ) ) {
			return;
		}

		$this->record_rejection_via_reader( $authentication_reader, $endpoint_cache_key );
	}

	/**
	 * Records the definitive invalid verdict a 2xx body's rejection
	 * ENVELOPE represents, when it is one (glm18-4).
	 *
	 * The z.ai Anthropic /v1/models route answers HTTP 200 for any or no
	 * credential, carrying the rejection in the body — the probe has
	 * judged that envelope definitive INVALID since GLM12 #1
	 * (successful_response_verdict()), but the zai_anthropic directory's
	 * discovery path recorded verdicts only for the 401/403 STATUS set,
	 * so a revocation arriving as the 200 envelope persisted nothing and
	 * a stale VALID verdict kept isConfigured() answering true (one
	 * doomed generation per window learning of it only through the
	 * generation route's 401). The directory now rides this predicate —
	 * the same glm12-1 verdict logic the probe applies, INCLUDING its
	 * precedence (glm18-15: a body that passes the models-list entry
	 * rule is valid evidence, so the envelope only rejects bodies that
	 * are not model lists) — through the same guarded recorder the
	 * status path uses; one credential flies AND binds, or no verdict
	 * persists.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed       $raw_body             The decoded 2xx body (the ONE decode
	 *                                          the caller already performed for its
	 *                                          parse — glm13-3's discipline).
	 * @param callable    $authentication_reader Returns the wired authentication
	 *                                          the request authenticated with (same
	 *                                          contract as
	 *                                          record_rejection_for_status()).
	 * @param string|null $endpoint_cache_key  The request-time endpoint identity
	 *                                         (GLM10 #1), or null for the current
	 *                                         settings.
	 * @return bool True when the body IS the definitive rejection envelope
	 *              (the caller surfaces its auth-naming error; whether a
	 *              verdict PERSISTED is the recorder's binding rules — an
	 *              opaque or empty credential records nothing).
	 */
	public function record_rejection_body_verdict( $raw_body, callable $authentication_reader, ?string $endpoint_cache_key = null ): bool {
		/*
		 * glm18-15 (verifier round on glm18-4): the probe's ONE verdict
		 * rule checks the models-list shape FIRST
		 * (successful_response_verdict()), so a HYBRID body — a
		 * well-formed models list that also carries the failure envelope
		 * — is VALID evidence there. The glm18-4 form of this recorder
		 * consulted the envelope predicate alone, answering the same
		 * body INVALID: one 2xx answer then persisted opposite verdicts
		 * at the two sites (empirically reproduced in the repo's own
		 * harness), the fresh poisoned invalid state answering
		 * isConfigured() false with no new request while core clears
		 * keys on false. One precedence, one source: the envelope
		 * records a verdict only when the body is NOT a models list.
		 */
		if ( self::probe_body_is_models_list( $raw_body ) ) {
			return false;
		}

		if ( ! self::probe_body_is_credential_rejection( $raw_body ) ) {
			return false;
		}

		$this->record_rejection_via_reader( $authentication_reader, $endpoint_cache_key );

		return true;
	}

	/**
	 * The guarded recorder both definitive-rejection recorders share.
	 *
	 * The reader-closure dance record_rejection_for_status() established
	 * (GLM10 #8): the RuntimeException-guarded wired-auth read, the
	 * instanceof narrowing (glm14-5: an opaque non-Api-key credential
	 * flew the request and records NOTHING — one credential flies AND
	 * binds, or no verdict persists), and the record_definitive_verdict()
	 * call with its glm13-1 empty-wire skip.
	 *
	 * @since 0.2.0
	 *
	 * @param callable    $authentication_reader Returns the wired authentication
	 *                                        the rejecting request authenticated
	 *                                        with (throws RuntimeException when
	 *                                        unwired, which resolves the
	 *                                        effective key instead).
	 * @param string|null $endpoint_cache_key The request-time endpoint identity
	 *                                        (GLM10 #1), or null for the current
	 *                                        settings.
	 * @return void
	 */
	private function record_rejection_via_reader( callable $authentication_reader, ?string $endpoint_cache_key ): void {
		$wired = $this->wired_or_null( $authentication_reader );

		/*
		 * glm14-5: an OPAQUE wired credential (a non-Api-key
		 * RequestAuthentication) flew the rejecting request. Nulling it —
		 * the unwired treatment — would bind the definitive verdict to
		 * the ladder/database credential effective_for_authentication()
		 * resolves, a key that never flew: the exact cross-credential
		 * poisoning glm13-1's empty-wire rule refuses. One credential
		 * flies AND binds, or no verdict persists.
		 */
		if ( null !== $wired && ! $wired instanceof ApiKeyRequestAuthentication ) {
			return;
		}

		$this->record_definitive_verdict(
			false,
			$wired instanceof ApiKeyRequestAuthentication ? $wired : null,
			$endpoint_cache_key
		);
	}

	/**
	 * Builds the binding-scoped probe-miss transient name.
	 *
	 * GLM9 #8: the composition rides the SDK-free settings owner's
	 * probe_miss_transient_name() — one formula shared with uninstall's
	 * deterministic sweep (which derives names through
	 * probe_miss_transient_ids()), so the writer and the sweeper can
	 * never disagree about which transient a binding's marker lives in.
	 * Kept as a thin delegator for the call sites above.
	 *
	 * @since 0.2.0
	 *
	 * @param string $binding Credential+endpoint binding.
	 * @return string Transient name holding the miss marker.
	 */
	private static function probe_miss_transient_name( string $binding ): string {
		$settings_class = static::settings_class();

		return $settings_class::probe_miss_transient_name( $binding );
	}

	/**
	 * Probes the model-list endpoint with the effective credential.
	 *
	 * Only answers that definitively reject the CREDENTIAL persist a
	 * verdict: a 401/403 status (bad token; no access for this key), or —
	 * GLM12 #1 — a 2xx whose BODY is z.ai's failure envelope rejecting the
	 * credential ({"success":false,"code":401,...}: the Anthropic-surface
	 * /v1/models route answers HTTP 200 for any or no credential with the
	 * rejection in the body, so the status alone proves nothing there; live
	 * curl capture 2026-09-04). A 2xx counts as VALID only when the body is
	 * an authenticated model list. Everything else stays transient so it
	 * can never poison the connected state — z.ai returns 429 both for real
	 * rate limits and for plan mismatches on an otherwise VALID key (error
	 * 1113 "Insufficient balance", record 0006), and 404/5xx indicate an
	 * unavailable probe endpoint (the cn /models path is unprobed), not a
	 * bad key.
	 *
	 * @since 0.2.0
	 *
	 * @return bool|null True (valid), false (credential rejected: 401/403,
	 *                   a 2xx body that rejects the credential, or —
	 *                   glm26-1 — uncarriable credential material rejected
	 *                   pre-transport), or null when the probe was
	 *                   inconclusive (transport error, 3xx, 429, other
	 *                   4xx, 5xx, or a 2xx body that says nothing
	 *                   definitive) — which says nothing about the
	 *                   credential and must not block key saving.
	 */
	private function probe(): ?bool {
		try {
			$endpoint_class = static::endpoint_class();
			$endpoint       = $endpoint_class::for_current_settings();
			$request        = new Request( HttpMethodEnum::GET(), $endpoint->models_url() );

			/*
			 * glm28-12: the ~70-line credential-resolution ladder this
			 * try carried inline (three try-nested branches plus the
			 * effective-key fallback) lives in
			 * resolve_probe_authentication() now — probe() is a flat
			 * read-then-verdict method; every wiring rule's rationale
			 * rides the helper. Null means the probe answers
			 * inconclusive with nothing flown.
			 */
			$authentication = $this->resolve_probe_authentication();

			if ( null === $authentication ) {
				return null;
			}

			$request  = $authentication->authenticateRequest( $request );
			$response = $this->getHttpTransporter()->send( $request );
		} catch ( UncarriableCredentialException $uncarriable ) {
			/*
			 * glm26-1: the credential MATERIAL cannot ride this surface's
			 * Authorization header (glm16-13), so no request can ever
			 * authenticate with it — definitive evidence about the
			 * credential itself, not a transport failure. The verdict
			 * names exactly the credential this probe was about to fly
			 * (the wired Api-key, or the effective key the fallback
			 * carries), so the glm13-1/glm14-5 one-credential-flies-
			 * AND-binds discipline holds: nothing else flew, and the
			 * named material IS the rejection. isConfigured() persists
			 * the invalid verdict (core's key-save validation then
			 * refuses the key instead of saving it as connected) and
			 * the generation refusal gate answers before the
			 * pre-transport throw ever fires.
			 */
			return false;
		} catch ( Throwable $e ) {
			// Transport failure: transient, never persisted.
			return null;
		}

		$status = $response->getStatusCode();

		if ( $response->isSuccessful() ) {
			/*
			 * glm13-3: ONE BOM-safe decode of the body serves BOTH the
			 * verdict and (below) the discovery seed — the verdict branch
			 * decoded it and the seed re-read and re-decoded the identical
			 * body (plus a second stream-prefix strip) on every cold-window
			 * probe with a valid body, on the blocking pre-generation path.
			 * The shared decode lives with the parser (its
			 * decode_models_body entry) so the verdict and the seed can
			 * never see two different decodes either.
			 */
			$raw     = ZaiModelListParser::decode_models_body( $response );
			$verdict = self::successful_response_verdict( $raw );

			/*
			 * GLM12 #2: a verdict-bearing models body is also a DISCOVERY
			 * answer — the probe and the metadata directories each issued
			 * their own authenticated GET to the identical models_url, so a
			 * cold window (no verdict state, no discovery transient) paid
			 * two sequential blocking HTTPS round trips before the first
			 * generation. The probe's own response now seeds the discovery
			 * transient; the verdict is unaffected by whether the seed
			 * lands (the seed runs the full catalog parser, whose
			 * rejections are catalog concerns, not credential ones).
			 */
			if ( true === $verdict ) {
				$this->seed_discovery_from_probe( $raw, $endpoint );
			}

			return $verdict;
		}

		if ( self::is_definitive_rejection( $status ) ) {
			// The endpoint answered and rejected the credential itself.
			return false;
		}

		// 3xx, 429, other 4xx, 5xx: inconclusive for the credential.
		return null;
	}

	/**
	 * Resolves the authentication the probe's /models request flies
	 * (glm28-12 — the credential-resolution ladder extracted from
	 * probe() unchanged).
	 *
	 * Three outcomes, one per wiring rule the ladder has accumulated:
	 *
	 * - The WIRED Api-key instance, read back through the surface's own
	 *   getRequestAuthentication() — the one protocol-wrap funnel
	 *   (glm15-8) — so the wired key flies with this surface's headers
	 *   exactly as its generation requests do (glm16-1).
	 * - The FALLBACK instance carrying the ladder-resolved EFFECTIVE key
	 *   (GLM5 #10: the SDK registry wires provider credentials from
	 *   env/constant ONLY, so a DATABASE-only key rode an UNWIRED probe
	 *   and isConfigured() reported connected (configured-pending)
	 *   FOREVER without a single validation request — the fallback
	 *   makes the database-only key actually validate against the
	 *   endpoint).
	 * - Null: the probe answers INCONCLUSIVE with nothing flown — an
	 *   OPAQUE wired credential the verdict binding cannot name
	 *   (glm14-5: the binding rides effective_key(), which ignores
	 *   non-Api-key wiring; one credential flies AND binds, or no
	 *   verdict persists), or no credential at all (nothing wired and
	 *   the effective key empty).
	 *
	 * An EMPTY wired credential is treated exactly like none (glm13-1):
	 * the SDK registry wires env/constant values verbatim — an empty
	 * ZAI_API_KEY yields ApiKeyRequestAuthentication('') — while the
	 * verdict binding skips an empty wired key and names the
	 * ladder-resolved credential instead; authenticating with the empty
	 * key would fly ONE credential and persist the 401 it earns under a
	 * DIFFERENT (possibly valid) key's binding, clearing a working
	 * credential.
	 *
	 * The UncarriableCredentialException this surface's wrap family can
	 * throw fires at AUTHENTICATE time (reject_uncarriable_credential()
	 * rides authenticateRequest(), not the getters), so it never meets
	 * the inner catch — the only Throwable the unwired catch sees is
	 * the raw accessor's nothing-is-wired RuntimeException (glm16-1:
	 * the rules judge the RAW wired instance, never the wrap funnel,
	 * whose foreign-wiring throw would launder as no wiring).
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface|null The flight credential, or null.
	 */
	private function resolve_probe_authentication(): ?RequestAuthenticationInterface {
		$authentication = null;

		try {
			$wired = $this->raw_request_authentication();

			if ( $wired instanceof ApiKeyRequestAuthentication && '' === $wired->getApiKey() ) {
				// glm13-1: an empty wired key authenticates nothing —
				// treated exactly like none (see the docblock).
				$authentication = null;
			} elseif ( ! $wired instanceof ApiKeyRequestAuthentication ) {
				// glm14-5: the opaque-wiring refusal — inconclusive,
				// nothing flown (see the docblock).
				return null;
			} elseif ( ApiKeyRequestAuthentication::class !== \get_class( $wired ) ) {
				/*
				 * glm38-3: an Api-key SUBCLASS takes the opaque
				 * disposition, not the funnel — since the wrap's
				 * plain-class requirement went exact, the funnel would
				 * throw its typed subclass refusal INSIDE this try, and
				 * the Throwable catch below would launder it into the
				 * ladder fallback (a DIFFERENT credential flying while
				 * an unwrappable wiring sits on the instance — the
				 * glm14-5/glm16-1 cross-credential shape this early
				 * return exists to prevent). The subclass's key is
				 * readable, so effective_key() still reports its
				 * material; nothing flies and nothing persists.
				 */
				return null;
			} else {
				// glm16-1: the FLIGHT credential goes back through the
				// one protocol-wrap funnel; the funnel cannot throw here
				// (a plain Api-key instance is the one shape the wrap
				// rebuilds unconditionally — the subclass shape returned
				// above, and the uncarriable rejection rides
				// authenticateRequest(), later, in probe()'s try).
				$authentication = $this->getRequestAuthentication();
			}
		} catch ( Throwable $unwired ) {
			$authentication = null;
		}

		if ( null === $authentication ) {
			$effective = $this->effective_key();

			if ( '' === $effective['key'] ) {
				// Nothing to authenticate with: as inconclusive as
				// before the fallback existed.
				return null;
			}

			$authentication = static::fallback_authentication( $effective['key'] );
		}

		return $authentication;
	}

	/**
	 * Seeds the discovery transient from the probe's own models response
	 * (GLM12 #2).
	 *
	 * The body the probe already fetched IS the models list the surface's
	 * discovery would fetch over its own second authenticated GET to the
	 * same URL: the shared parser (ZaiModelListParser) runs the full
	 * discovery validation — the has_more rejection, the chat filter, the
	 * plan intersection — and the resulting ID list is stored under the
	 * SAME endpoint-scoped transient id and TTL the directories' own
	 * discovery flow writes, through the cache's ONE store API
	 * (ZaiDiscoveryCache::store_ids(), glm25-1 — the availability base
	 * is not a second writer of the row), so every consumer (cache
	 * reads, settings invalidation, uninstall sweeps) sees one coherent
	 * cache.
	 *
	 * Seeding is strictly opportunistic: a catalog-reason parser failure
	 * (an incomplete page, no in-plan chat ID) leaves discovery to its own
	 * flow — live attempt, 60s negative marker, plan fallback — and the
	 * VALID verdict above stands, because the body authenticated the
	 * credential regardless of its catalog usability. The endpoint is the
	 * one captured at REQUEST time, so a settings change mid-flight cannot
	 * cache one endpoint's catalog under another's id (the GLM3 #10
	 * discipline).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed               $raw      The probe body's decoded tree (the
	 *                                       ONE decode the probe performed,
	 *                                       glm13-3).
	 * @param AbstractZaiEndpoint $endpoint The endpoint the probe requested.
	 * @return void
	 */
	private function seed_discovery_from_probe( $raw, AbstractZaiEndpoint $endpoint ): void {
		try {
			$ids = ZaiModelListParser::parse_decoded_chat_ids( $raw, $endpoint->plan(), static::REFUSAL_LABEL );
		} catch ( Throwable $e ) {
			return;
		}

		ZaiDiscoveryCache::store_ids(
			static::endpoint_class()::discovery_cache_id( $endpoint->plan(), $endpoint->region() ),
			$ids
		);
	}

	/**
	 * Decides the credential verdict from a 2xx probe response's BODY
	 * (GLM12 #1).
	 *
	 * The status alone is not evidence: z.ai's Anthropic /v1/models route
	 * (intl, cn, and coding bases) answers HTTP 200 for ANY or NO
	 * credential, carrying the rejection in the body — the status-only
	 * rule this replaces blessed garbage keys as VERDICT_VALID for the
	 * 300s STATE_TTL and its unauthenticated 200 cleared region-switch
	 * distrust, while every POST /v1/messages 401'd ("connected but
	 * broken"). Only what the body says counts now:
	 *
	 * - a model list (the data[].id shape BOTH surfaces' discovery
	 *   accepts) — the credential is VALID;
	 * - z.ai's failure envelope with a definitive-rejection code — the
	 *   credential is INVALID (the same evidence a 401/403 status is);
	 * - anything else — INCONCLUSIVE: an unrecognized 2xx body says
	 *   nothing about the credential, so it must neither report connected
	 *   nor persist an unproven invalid verdict (core's key-save
	 *   validation clears keys on false).
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $raw The 2xx probe body's decoded tree (glm13-3: the
	 *                   probe's ONE decode, shared with the seed).
	 * @return bool|null As probe(): true, false, or null (inconclusive).
	 */
	private static function successful_response_verdict( $raw ): ?bool {
		if ( self::probe_body_is_models_list( $raw ) ) {
			// The endpoint served the credential an authenticated model
			// list: valid.
			return true;
		}

		if ( self::probe_body_is_credential_rejection( $raw ) ) {
			// The endpoint answered 2xx but the body rejects the credential
			// itself — the same definitive evidence a 401/403 status is.
			return false;
		}

		return null;
	}

	/**
	 * Reports whether a decoded 2xx body is a model list.
	 *
	 * GLM13 #2: the predicate rides the shared discovery parser's ONE
	 * entry rule (ZaiModelListParser::entry_failure_reason()) — the
	 * verdict's private shape-check copy had already diverged from it
	 * (a vacuous foreach over zero entries accepted an EMPTY data list
	 * the parser rejects, persisting VERDICT_VALID for a body carrying
	 * no authentication proof). What stays OUT of the verdict is exactly
	 * what stays out of the entry rule: the chat filter and the plan
	 * intersection are catalog concerns, not credential ones — a valid
	 * key on a plan whose intersection is empty still authenticated, so
	 * the verdict must not import them.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $raw Decoded response body.
	 * @return bool True when the body is an authenticated-looking model list.
	 */
	private static function probe_body_is_models_list( $raw ): bool {
		return null === ZaiModelListParser::entry_failure_reason( $raw );
	}

	/**
	 * Reports whether a decoded 2xx body is z.ai's failure envelope
	 * rejecting the CREDENTIAL itself.
	 *
	 * The live-captured shape — {"code":401,"msg":"token expired or
	 * incorrect","success":false} riding HTTP 200 — is the failure framing
	 * the z.ai API carries in band on routes that answer 200 regardless of
	 * authentication. Only a body whose code is in the one definitive-
	 * rejection set (GLM10 #8) rejects the CREDENTIAL: other failure codes
	 * (1113 balance/plan standing, ...) reject the account's standing, not
	 * the key, and stay inconclusive exactly like their 429 status twin.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $raw Decoded response body.
	 * @return bool True when the body definitively rejects the credential.
	 */
	private static function probe_body_is_credential_rejection( $raw ): bool {
		return \is_object( $raw )
			&& false === ( $raw->success ?? null )
			&& \is_int( $raw->code ?? null )
			&& self::is_definitive_rejection( $raw->code );
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
