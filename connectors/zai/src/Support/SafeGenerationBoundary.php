<?php
/**
 * Shared WP-facing generation boundary for both z.ai model surfaces
 * (round 9 cleanup, GLM9 #11).
 *
 * Generate_text() (the typed-WP_Error direct-use boundary) and
 * setHttpTransporter()'s debug-logging wrap were byte-identical between
 * the two model classes, and the credential-gate wrapper sequence
 * differed only in WHICH availability class it consulted and WHICH
 * authentication getter supplied the credential — the surfaces extend
 * DIFFERENT SDK parents (AbstractOpenAiCompatibleTextGenerationModel vs
 * AbstractApiBasedModel), so no common base class is possible. The
 * shared behavior lives in this sibling trait of ThrowsSafeHttpErrors
 * (the same mechanism that single-sourced throwIfNotSuccessful), with
 * two hooks each surface owns: the availability instance whose gate is
 * consulted, and the authentication getter that supplies the exact
 * credential about to authenticate (the surfaces' deliberate wiring
 * difference — see GLM3 #9).
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Support;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;

/**
 * Typed WP_Error boundary, logging transporter wrap, and credential gate.
 *
 * @since 0.2.0
 */
trait SafeGenerationBoundary {

	/**
	 * The cache_key() of the endpoint the CURRENT in-flight request was
	 * built against — captured at request-build time through
	 * capture_generation_endpoint() (glm13-6), read by
	 * record_generation_route_rejection().
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	protected $generation_endpoint_cache_key = null;

	/**
	 * The availability instance whose credential gate generation consults.
	 *
	 * Each surface returns its own availability class — the gate state is
	 * per-provider, and one provider's verdict must never gate the other.
	 *
	 * @since 0.2.0
	 *
	 * @return AbstractZaiProviderAvailability
	 */
	abstract protected function credential_gate_availability(): AbstractZaiProviderAvailability;

	/**
	 * The authentication instance the credential gate judges: the exact
	 * credential this model would authenticate with.
	 *
	 * The two surfaces' deliberate wiring difference (GLM3 #9): the zai
	 * surface reads its own getter; the zai_anthropic surface reads the
	 * RAW parent getter, so a foreign-wiring failure surfaces as the 500
	 * binding error rather than a 400 option-rejection. May throw the
	 * SDK's unwired RuntimeException — the gate wrapper treats that as
	 * "not the gate's concern" and skips (an unwired model is a caller
	 * misuse with its own earlier failure).
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	abstract protected function gate_authentication(): RequestAuthenticationInterface;

	/**
	 * Records the definitive invalid verdict a credential-rejecting
	 * GENERATION response represents (glm13-6 — see
	 * ThrowsSafeHttpErrors::throwIfNotSuccessful(), whose abstract hook
	 * this satisfies).
	 *
	 * GLM14-6: one mechanic on the shared boundary, riding the two hooks
	 * above — the credential the gate itself judges is the credential
	 * whose rejection records, and the availability instance whose gate
	 * generation consults is the one that persists the verdict. The
	 * surfaces' copies were verbatim duplicates that had already begun
	 * drifting textually (the zai_anthropic recorder inlined
	 * parent::getRequestAuthentication() where its gate_authentication()
	 * hook returns exactly that); a future change to which getter a
	 * surface's gate judges must not leave the gate and the recorder
	 * disagreeing about which credential flew.
	 *
	 * @since 0.2.0
	 *
	 * @param int $status The definitive-rejection status the route answered.
	 * @return void
	 */
	protected function record_generation_route_rejection( int $status ): void {
		$this->credential_gate_availability()->record_rejection_for_status(
			$status,
			function () {
				return $this->gate_authentication();
			},
			$this->generation_endpoint_cache_key
		);
	}

	/**
	 * Captures the endpoint identity of the CURRENT in-flight request
	 * (glm13-6, the directories' GLM10 #1 discipline) — the cache_key()
	 * a credential-rejecting answer's verdict is recorded against, so
	 * the binding always names the endpoint the request actually hit,
	 * never the one the settings resolve to by the time the response
	 * lands. Each surface's request-build site re-captures before every
	 * send that can follow it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $cache_key The request-time endpoint's cache_key().
	 * @return void
	 */
	protected function capture_generation_endpoint( string $cache_key ): void {
		$this->generation_endpoint_cache_key = $cache_key;
	}

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * The idempotent wrap rule (install the logger on whatever
	 * transporter arrives, never double-wrap) rides
	 * LoggingHttpTransporter::wrap().
	 *
	 * @since 0.2.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		parent::setHttpTransporter( LoggingHttpTransporter::wrap( $http_transporter ) );
	}

	/**
	 * WP-facing generation boundary for DIRECT model use: typed WP_Error.
	 *
	 * NOT part of the core prompt flow: wp_ai_client_prompt() dispatches to
	 * generateTextResult() and converts exceptions itself (fixed core
	 * codes, messages verbatim — no filter), so this wrapper is never
	 * called there. It exists for code that holds the model directly —
	 * obtained via ProviderRegistry::getProviderModel(), the only factory
	 * that binds the HTTP transporter and request auth — and wants the
	 * plugin's typed, redacted zai_* codes (SPEC §6.2) instead of SDK
	 * exceptions: through the core builder callers get core codes with
	 * the same safe messages and correct HTTP statuses either way.
	 *
	 * @since 0.2.0
	 *
	 * @param array $prompt Prompt messages (list of Message).
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult|\WP_Error Result on success; typed, redacted WP_Error on any failure.
	 */
	public function generate_text( array $prompt ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP-flavored direct-use generation boundary; see trait docblock.
		try {
			return $this->generateTextResult( $prompt );
		} catch ( \Throwable $e ) {
			return ErrorMapper::to_wp_error( $e );
		}
	}

	/**
	 * Refuses generation for credentials the availability layer distrusts.
	 *
	 * GLM1 #1 (zai) / Codex R19 #3 (zai_anthropic): an env/constant
	 * credential that survives a region switch is region-pending (or
	 * carries a definitive invalid verdict) and must not be reused
	 * against the other region — the generation paths authenticated
	 * unconditionally, disclosing the old-region key to the newly
	 * selected endpoint while the connector reported disconnected.
	 * GLM4 #9 moved the gate predicate and messages to the availability
	 * layer; GLM5 #17 absorbed the wrapper sequence (the unwired-model
	 * skip, the predicate call, the message build, the throw) into the
	 * one refuse_generation() helper every credential consumer consults
	 * — this trait's remaining contribution is the wiring, through the
	 * two hooks above (GLM9 #11: the sequence was copy-pasted between
	 * the surfaces with only those hook bodies differing).
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 * @throws \WordPress\AiClient\Common\Exception\InvalidArgumentException When the credential is region-pending
	 *                                  or carries a fresh invalid verdict
	 *                                  for the selected endpoint.
	 */
	private function refuse_refused_credentials(): void {
		$this->credential_gate_availability()->refuse_generation(
			function () {
				return $this->gate_authentication();
			}
		);
	}
}
