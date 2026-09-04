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
 * route answers 200 for any or no credential) reports not-connected;
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
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;
use Deicod\WpConnectors\Zai\Support\SseFrameBuffer;

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
	use WithRequestAuthenticationTrait;

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
			// timezone change alters no input to this subtraction, and
			// state_is_fresh() bounds the elapsed below at zero (GLM10 #2).
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
	 * core resolution order applies: env var, then constant (both rungs
	 * delegated to the SDK-free settings layer's shared
	 * env_constant_ladder(), GLM7 #17), then the database option.
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

		$settings_class = static::settings_class();

		foreach ( $settings_class::env_constant_ladder() as $source => $value ) {
			return array(
				'key'    => $value,
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
	 * @param string $provider_label Provider name for the message (the consuming surface's REFUSAL_LABEL).
	 * @param string $reason         Refusal reason from the gate.
	 * @return string The fixed, safe message.
	 */
	public static function refusal_message( string $provider_label, string $reason ): string {
		return 'region_pending' === $reason
			? sprintf( 'The %s provider refuses generation: the active environment credential is pending revalidation after a region switch.', $provider_label )
			: sprintf( 'The %s provider refuses generation: the active credential was rejected for the selected endpoint.', $provider_label );
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
		try {
			$authentication = $authentication_reader();
		} catch ( RuntimeException $e ) {
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
		try {
			$authentication = $authentication_reader();
		} catch ( RuntimeException $e ) {
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
			if ( $this->region_switch_pending( $effective['key'] ) ) {
				delete_option( static::REGION_PENDING_OPTION );
			}
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
	 *                                        resolves the effective key instead).
	 * @param string|null $endpoint_cache_key The request-time endpoint identity
	 *                                        (GLM10 #1), or null for the current
	 *                                        settings.
	 * @return void
	 */
	public function record_rejection_for_status( int $status, callable $authentication_reader, ?string $endpoint_cache_key = null ): void {
		if ( ! self::is_definitive_rejection( $status ) ) {
			return;
		}

		try {
			$wired = $authentication_reader();
		} catch ( RuntimeException $unwired ) {
			$wired = null;
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
	 *                   or a 2xx body that rejects the credential), or null
	 *                   when the probe was inconclusive (transport error,
	 *                   3xx, 429, other 4xx, 5xx, or a 2xx body that says
	 *                   nothing definitive) — which says nothing about the
	 *                   credential and must not block key saving.
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
			return self::successful_response_verdict( $response );
		}

		if ( self::is_definitive_rejection( $status ) ) {
			// The endpoint answered and rejected the credential itself.
			return false;
		}

		// 3xx, 429, other 4xx, 5xx: inconclusive for the credential.
		return null;
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
	 * @param Response $response The 2xx probe response.
	 * @return bool|null As probe(): true, false, or null (inconclusive).
	 */
	private static function successful_response_verdict( Response $response ): ?bool {
		// One BOM-safe object-view decode (the GLM10 #3 rule the discovery
		// parser already rides): a gateway-prepended UTF-8 BOM must degrade
		// nothing here either.
		$raw = json_decode( SseFrameBuffer::strip_stream_prefix( (string) $response->getBody() ) );

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
	 * This is deliberately the MINIMAL entry rule of the shared discovery
	 * parser (ZaiModelListParser): a data member that is a JSON list whose
	 * entries each carry a non-empty string id — and nothing more. The
	 * chat filter, the has_more rejection, and the plan intersection are
	 * CATALOG concerns, not credential ones: a valid key on a plan whose
	 * intersection is empty, or a paginated list, still authenticated, so
	 * the verdict must not import them.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $raw Decoded response body.
	 * @return bool True when the body is an authenticated-looking model list.
	 */
	private static function probe_body_is_models_list( $raw ): bool {
		if ( ! \is_object( $raw ) || ! isset( $raw->data ) || ! \is_array( $raw->data ) ) {
			return false;
		}

		foreach ( $raw->data as $entry ) {
			if ( ! \is_object( $entry ) || ! isset( $entry->id ) || ! \is_string( $entry->id ) || '' === $entry->id ) {
				return false;
			}
		}

		return true;
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
