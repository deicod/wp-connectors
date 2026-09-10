<?php
/**
 * Zai_anthropic model metadata directory: custom (non-OpenAI-compat)
 * discovery with a plan-partitioned static GLM fallback and optional
 * cached /v1/models discovery.
 *
 * The class rides the SDK's ROUTE-AGNOSTIC directory base (glm36-6) —
 * its final list/has/get trio and its one abstract (a map of model ID
 * to metadata) impose no route or framing assumption, so the hand-
 * rolled trio's drift risk (a future vendor contract change landing on
 * the zai surface's inherited copy while silently skipping this one)
 * is closed at the same inheritance the sibling already rides. The
 * SDK base's own cache layer (in-memory plus any PSR-16 cache, 24h
 * TTL) is bypassed exactly like the sibling's — hasCache() never
 * serves, setCache() stores nothing, the base key stays
 * endpoint-scoped — so the WordPress transient below remains the
 * single cache. What stays CUSTOM is the Anthropic surface's own
 * discovery flow (discover_model_ids(): the models route, the protocol
 * wrap, the verdict recording) — the part the OpenAI-compat abstract's
 * baked-in route assumptions genuinely excluded, and the reason this
 * class extends the route-agnostic parent directly instead of that
 * subclass like the zai sibling.
 *
 * The NEUTRAL GLM catalog DATA (IDs, capability/option metadata,
 * newest-first sorting, chat-support evidence) is shared with the zai
 * provider's ZaiModelCatalog — data reuse, not adapter coupling: the
 * two protocol adapters never call each other.
 *
 * The fallback is plan-partitioned exactly like the zai provider's (SPEC
 * §3.3): coding subscriptions expose a restricted, coding-suitable model
 * set; the general pay-as-you-go API exposes the full catalog.
 *
 * O1 (Anthropic surface): GET {base}/v1/models exists (existence
 * probe-verified 401-with-dummy-token, SPEC §3.1) but its behavior with a
 * VALID key is UNPROBED, so the tested static fallback is authoritative;
 * discovery is attempted opportunistically (a 2xx with a list of chat IDs
 * wins and is cached), and every failure shape — 401/404/5xx, malformed
 * body, no usable chat IDs, transport — falls back without poisoning the
 * POSITIVE cache: failures are negatively cached for NEGATIVE_TTL (60)
 * seconds only (GLM1 #6), so a later valid key can still discover. The
 * Task 2.7 live probe records the credentialed outcome.
 *
 * The discovery cache is a WordPress transient scoped to the endpoint
 * identity (provider + plan + region, via ZaiAnthropicEndpoint::cache_key()),
 * so a warm cache can never serve another endpoint's catalog after a
 * settings change. Successful discovery is additionally intersected with
 * the ACTIVE plan's catalog before caching (Codex R3 #4): the coding plan
 * advertises only its restricted model set even though the live route
 * returns the full list. The SDK base's cache layer is neutralized (see
 * hasCache()/setCache() below) and the class keeps no in-memory state
 * beyond the per-content map memo (GLM7 #13) — the transient stays the
 * single source of the resolved IDs.
 *
 * @since 0.2.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

namespace Deicod\WpConnectors\Zai\Metadata;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Deicod\WpConnectors\Zai\Authentication\SpeaksAnthropicMessagesProtocol;
use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Support\LoggingHttpTransporter;
use Deicod\WpConnectors\Zai\Support\NeutralizesSdkModelCache;

/**
 * Model metadata directory for zai_anthropic.
 *
 * @since 0.2.0
 */
final class ZaiAnthropicModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {

	// The aliases keep the traits' originals reachable for the wrapping
	// overrides below (traits have no parent:: chain inside the using
	// class itself).
	use WithHttpTransporterTrait {
		setHttpTransporter as trait_set_transporter; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias.
	}
	use WithRequestAuthenticationTrait {
		WithRequestAuthenticationTrait::getRequestAuthentication as trait_get_request_authentication; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- trait method alias.
	}

	/*
	 * glm15-8: the protocol wrap rides the shared trait (the bespoke
	 * getRequestAuthentication() override this class carried), and the
	 * SDK trait's raw getter stays reachable through the alias above
	 * for the one hook below. The insteadof resolves the two traits'
	 * same-named methods in favor of the protocol wrap.
	 */
	use SpeaksAnthropicMessagesProtocol {
		SpeaksAnthropicMessagesProtocol::getRequestAuthentication insteadof WithRequestAuthenticationTrait; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
	}

	// glm37-6: the SDK cache neutralization (getBaseCacheKey/hasCache/
	// setCache) rides the shared Support\NeutralizesSdkModelCache trait —
	// this surface's glm36-6 port was verified once empirically and pinned
	// nowhere, so a neutralization change landing on the zai sibling only
	// drifted silently. One rule composes for both surfaces now, pinned
	// through the shared directory test base.
	use NeutralizesSdkModelCache;

	/*
	 * glm19-10: this class carried four cache alias constants
	 * (CACHE_PREFIX, DISCOVERY_TTL, NEGATIVE_TTL, NEGATIVE_CACHE_SUFFIX)
	 * that no production code read — all cache composition goes through
	 * ZaiDiscoveryCache and the endpoint classes (discovery_transient_
	 * ids()), and only tests referenced the directory aliases. Deleted
	 * rather than kept as drift surface. The real owners:
	 * ZaiDiscoveryCache (TTLs, suffix), ZaiAnthropicPlanRegionSettings
	 * (prefix).
	 */

	/**
	 * Wraps the transporter with the (option-gated) debug logger.
	 *
	 * @since 0.2.0
	 *
	 * @param HttpTransporterInterface $http_transporter Transporter to install.
	 * @return void
	 */
	public function setHttpTransporter( HttpTransporterInterface $http_transporter ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK trait method name.
		$this->trait_set_transporter( LoggingHttpTransporter::wrap( $http_transporter ) );
	}

	/**
	 * The RAW wired authentication — the SDK trait's aliased getter,
	 * unwrapped (glm15-8: the protocol wrap lives once on the
	 * SpeaksAnthropicMessagesProtocol trait).
	 *
	 * @since 0.2.0
	 *
	 * @return RequestAuthenticationInterface
	 */
	protected function raw_request_authentication(): RequestAuthenticationInterface {
		return $this->trait_get_request_authentication();
	}

	/**
	 * The endpoint class whose current-settings identity scopes the SDK
	 * cache key (glm37-6's one parameterized fact for the shared
	 * NeutralizesSdkModelCache trait — the glm36-6 neutralization this
	 * class carried inline, one owner down).
	 *
	 * @since 0.2.0
	 *
	 * @return class-string
	 */
	protected static function discovery_endpoint_class(): string {
		return ZaiAnthropicEndpoint::class;
	}

	/**
	 * Returns the model map for the CURRENT endpoint: cached discovery,
	 * discovery, or the plan-specific static fallback.
	 *
	 * This is the SDK base's one abstract (glm36-6) — the final trio's
	 * list/has/get consults land here through the neutralized cache
	 * funnel (hasCache() never serves, so every consult re-runs this
	 * build; the plugin transient and the content memo below are the
	 * only caches). The plan/region options are read at call time, so a
	 * settings change swaps the cache identity and catalog on the very
	 * next lookup — a warm cache can never serve another endpoint's
	 * models.
	 *
	 * GLM4 #10: the cache orchestration (positive/negative transients,
	 * TTLs, plan fallback) lives once in the shared ZaiDiscoveryCache —
	 * the zai surface's directory runs the identical flow through it, so
	 * a caching-rule change can never land on one surface only.
	 *
	 * GLM7 #13 memoized the map rebuild per transient CONTENT
	 * (originally keyed by the cache id plus a digest of the resolved
	 * IDs — the map is a pure function of the ID list, but every
	 * list/has/get call re-ran the full rebuild plus sort of constant
	 * data, twice or more per AI request). GLM9 #10 moved that memo
	 * into the shared cache (ZaiDiscoveryCache::memoized_map()), where
	 * the zai surface's GLM8 #9 copy had lived beside it as a verbatim
	 * twin; glm26-6 replaced the digest with a strict stored-list
	 * compare (the digest glm26-6 left stored is gone since glm35-5).
	 * The transient is still read on every call, so a settings change,
	 * a cross-process cache write, or a TTL expiry swaps the stored id
	 * list and the next call rebuilds.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, ModelMetadata> Map of model ID to metadata.
	 */
	protected function sendListModelsRequest(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SDK base abstract's name; the per-consult plugin cache owns discovery, not an SDK cache.

		/*
		 * glm26-6: the consult skeleton (endpoint resolve → discovery
		 * cache id → cached_ids → memoized_map) rides the shared
		 * ZaiDiscoveryCache::resolved_map() orchestrator — the
		 * hand-spelled skeleton was this surface's copy of the zai
		 * directory's sendListModelsRequest() flow, the composition-layer
		 * drift GLM4 #10's shared cache left standing. This surface owns
		 * only what genuinely differs: HOW a discovery request is made
		 * and parsed here (discover_model_ids()).
		 */
		return ZaiDiscoveryCache::resolved_map(
			ZaiAnthropicEndpoint::class,
			function ( ZaiAnthropicEndpoint $endpoint ): array {
				return $this->discover_model_ids( $endpoint );
			}
		);
	}

	/**
	 * The availability layer this directory's credential gate and verdict
	 * recorders consult (glm26-8).
	 *
	 * The concrete availability class was hard-instantiated inline in
	 * discover_model_ids() — the pairing drift glm24-1 removed from the
	 * live probe, then unpinned at the directory layer: a copy-paste edit
	 * that misses the buried 'new' wires this surface's refusals and
	 * verdicts onto ANOTHER provider's availability (another surface's
	 * STATE_OPTION store and region-pending flag). One hook states the
	 * pairing once — the models' credential_gate_availability() shape
	 * (glm14-6) — and the lockstep test pins it to the registry row.
	 *
	 * glm37-4 (the honest contract, glm15-24's pattern): the hook returns
	 * a FRESH instance carrying no transporter and no wired
	 * authentication — the refusal reads and verdict WRITES this
	 * directory's consult sites (the discovery gate and the
	 * definitive-rejection recorders) exist for are option/transient
	 * state, never network. The probing instance is the one the registry
	 * wires. An isConfigured() consult on this un-wired instance answers
	 * SILENTLY, not loudly — inconclusive → configured-pending TRUE on
	 * zero network evidence (FALSE under region-switch distrust,
	 * glm21-2), plus a planted 60s probe-miss marker either way (see
	 * the SafeGenerationBoundary hook's full statement). Gate state
	 * only; never probe through it.
	 *
	 * @since 0.2.0
	 *
	 * @return AbstractZaiProviderAvailability
	 */
	protected function availability(): AbstractZaiProviderAvailability {
		return new ZaiAnthropicProviderAvailability();
	}

	/**
	 * Discovers chat-capable model IDs from the endpoint's /v1/models route.
	 *
	 * Both common list shapes carry data[].id entries, so the parser accepts
	 * the Anthropic shape (data + display_name/created_at) and the OpenAI
	 * shape (data + object/created/owned_by) alike. Any malformed shape, a
	 * non-2xx status, or a list with no usable chat IDs throws, which
	 * sendListModelsRequest() turns into the plan fallback — a definitive 401/403
	 * additionally records the invalid verdict through the availability
	 * layer before throwing (GLM7 #12), exactly as the probe would.
	 *
	 * @since 0.2.0
	 *
	 * @param ZaiAnthropicEndpoint $endpoint The endpoint to discover from.
	 * @return list<string> Chat-capable model IDs, unsorted.
	 * @throws ResponseException On any non-usable discovery response.
	 */
	private function discover_model_ids( ZaiAnthropicEndpoint $endpoint ): array {
		/*
		 * glm21-14: the auth-reader closure (which credential the
		 * rejecting request flew with) was spelled three times and the
		 * fixed five-line rejection throw twice inside this one method;
		 * one reader local and one rejection helper serve all five
		 * sites, so a change to the credential a rejecting request is
		 * judged by, or to the rejection wording/channel, lands at
		 * every site or none — never three-and-two places to miss one.
		 */
		$availability = $this->availability();
		$auth_reader  = function () {
			/*
			 * glm26-4 (glm16-1 alignment): the reader answers WHICH
			 * credential the rejecting request flew with, and judges the
			 * RAW wired instance — like the probe and both models, not
			 * through the protocol wrap. The wrap reader held only by
			 * call-ordering accident: a foreign wiring became wrap()'s
			 * RuntimeException inside record_rejection_via_reader()'s
			 * unwired catch, resolving the ladder/database credential —
			 * the exact glm14-5 cross-credential poisoning the ledger
			 * forbids (a later refactor authenticating through the
			 * reader, or a tolerant wrap, would have fired it). Today
			 * the flight at the wrap funnel below still throws first,
			 * so behavior is byte-identical; the discipline is
			 * structural now. The FLIGHT credential keeps riding the
			 * getRequestAuthentication() funnel (glm16-1: the one
			 * protocol-wrap funnel every request authenticates through).
			 */
			return $this->raw_request_authentication();
		};

		/*
		 * R20 (inline 3907008518): an env/constant credential that survives
		 * an intl/cn switch is region-pending (or carries a definitive
		 * invalid verdict) and must not be reused against the other region —
		 * the generation path already refuses it (R19), but enumeration
		 * still authenticated with it here, disclosing the old-region key to
		 * the newly selected endpoint. The SAME availability gate is
		 * consulted (reused, not duplicated — GLM4 #9: the shared
		 * predicate; GLM5 #17: the shared refuse_discovery() wrapper the
		 * other credential consumers also use): while refused, the
		 * authenticated request never happens and discovery degrades to the
		 * static plan fallback via the resolved-map orchestrator's catch — never fatal,
		 * cached at most as the 60s negative marker (GLM1 #6), so a later
		 * definitive verdict can discover again.
		 */
		$availability->refuse_discovery( $auth_reader );

		$request = new Request( HttpMethodEnum::GET(), $endpoint->models_url() );
		$request = $this->getRequestAuthentication()->authenticateRequest( $request );

		$response = $this->getHttpTransporter()->send( $request );

		$status = $response->getStatusCode();

		if ( ZaiAnthropicProviderAvailability::is_definitive_rejection( $status ) ) {
			/*
			 * GLM7 #12: the models route ANSWERED and rejected the
			 * credential itself — the same definitive evidence the
			 * availability probe persists an invalid verdict for. The
			 * verdict is recorded through the probe's own persist path
			 * (same binding, marker dropped, region distrust resolved) so
			 * isConfigured() and the refusal gates see it immediately,
			 * instead of the previous misattributed 'Missing the "data"
			 * key' error converting into a silent 60s '_miss' marker plus
			 * static-plan fallback with no persisted verdict. The thrown
			 * error names the auth rejection — distinguishable from a
			 * malformed body in the live probe's discovery report — and
			 * the shared cache's catch still keeps discovery never-fatal.
			 *
			 * GLM10 #8: the status set and the recording ride the
			 * availability base's one helper — the block this directory
			 * and the zai twin hand-copied (and once landed one side
			 * only, GLM7 #12/glm9-5) is gone.
			 */
			$availability->record_rejection_for_status(
				$status,
				$auth_reader,
				$endpoint->cache_key()
			);

			$this->reject_discovered_credential();
		}

		if ( ! $response->isSuccessful() ) {
			throw ResponseException::fromMissingData( ZaiAnthropicProviderAvailability::REFUSAL_LABEL, 'data' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design; the label is the class-owned constant (GLM10 #9).
		}

		/*
		 * glm18-4: this route's live-attested rejection shape is HTTP 200
		 * CARRYING the failure envelope ({"success":false,"code":401,...}
		 * — the glm12-1 envelope the probe judges definitive INVALID), so
		 * a successful status alone proves nothing about the credential.
		 * The ONE decode below serves both the verdict and the parse
		 * (glm13-3's discipline): when the body IS the envelope, the
		 * invalid verdict records through the same guarded persist path
		 * the 401/403 branch rides — previously nothing recorded, the
		 * misattributed missing-data rejection converted into the silent
		 * 60s '_miss' marker plus fallback, and a stale VALID verdict
		 * kept isConfigured() answering true while every window's first
		 * generation learned of the revocation only through its own
		 * doomed POST 401.
		 */
		$raw = ZaiModelListParser::decode_models_body( $response );

		if ( $availability->record_rejection_body_verdict( $raw, $auth_reader, $endpoint->cache_key() ) ) {
			$this->reject_discovered_credential();
		}

		/*
		 * GLM1 #11: the list parsing (shape checks, has_more rejection,
		 * chat filter, plan intersection) is SHARED with the zai surface's
		 * directory via ZaiModelListParser — the two copies had already
		 * drifted twice (the has_more rejection and the plan intersection
		 * existed only here). GLM10 #9 (verifier round): this surface
		 * names itself in the parser's rejections. glm18-4: the decoded
		 * tree rides the parser's split entry (the one decode above).
		 */
		return ZaiModelListParser::parse_decoded_chat_ids( $raw, $endpoint->plan(), ZaiAnthropicProviderAvailability::REFUSAL_LABEL );
	}

	/**
	 * Throws the fixed rejection for a credential the discovery route
	 * definitively rejected (glm21-14).
	 *
	 * The 401/403 branch and the 200-envelope branch (glm18-4) threw the
	 * byte-identical five-line ResponseException twice; one helper owns
	 * it, so the wording and the channel (fromInvalidData under this
	 * surface's REFUSAL_LABEL, distinguishable from a malformed body in
	 * the live probe's discovery report) are stated once.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 * @throws ResponseException Always — the credential rejection.
	 */
	private function reject_discovered_credential(): void {
		throw ResponseException::fromInvalidData(
			ZaiAnthropicProviderAvailability::REFUSAL_LABEL, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed message by design; the label is the class-owned constant (GLM10 #9).
			'data',
			'Discovery failed: the credential was rejected for this endpoint.'
		);
	}
}
