<?php
/**
 * ONE owner of the live acceptance round trip (glm29-9).
 *
 * bin/zai-live-probe.php and the opt-in PHPUnit smoke skeleton
 * (AbstractZaiSurfaceLiveSmokeTestCase) each hand-maintained the
 * acceptance sequence and had already drifted in BOTH directions: the
 * CLI carried the state-option delete (Codex R13 #5), the probe-miss
 * marker clear (GLM2 #6), the definitive-verdict check (R17b), the
 * discovery-transient clearing and live-vs-fallback evidence (Codex
 * R8 #6), the named discovery cache id (glm29-8), and the
 * preferred-model fallback with its diagnostic (glm19-9) — while the
 * skeleton alone checked the validation state for plaintext. The next
 * rule change would land on one side and the other stay green on stale
 * evidence (glm28-11 had unified only the two PHPUnit twins). This
 * class owns the ordered steps ONCE; the CLI and PHPUnit shells feed
 * it a safe-fact reporter and judge its structured outcome (exit
 * codes / assertions). Reconciled to the stricter side: every CLI
 * hardening above now runs for the PHPUnit consumer too, and the
 * plaintext check runs for the CLI too.
 *
 * Performs REAL network requests (a curl transporter) — the opt-in
 * live consumers only; never loaded or exercised by `composer check`.
 * Never reports the key, only safe facts (URLs, statuses, model ids,
 * the generated text, timings).
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog;
use Deicod\WpConnectors\Zai\Plugin;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\HttpTransporter;

final class ZaiLiveRoundTrip
{
    /**
     * Runs the ordered acceptance steps for one surface and reports the outcome.
     *
     * The sequence: the three option writes, the registry/provider wiring,
     * the endpoint evidence lines, the availability probe (state option
     * and probe-miss marker cleared first, definitive verdict required),
     * live model discovery (transients cleared first, fallback detected),
     * one real generation through the plugin model class (catalog-preferred
     * id with discovered-metadata fallback, empty output rejected), and
     * the state-option plaintext check.
     *
     * @param string   $settings       The surface's settings class (the option
     *                                 names' owner; the availability layer's
     *                                 constants alias them — glm15-23's fixed
     *                                 direction).
     * @param string   $provider       The surface's provider class.
     * @param string   $endpoint_class The surface's endpoint class (the
     *                                 registry's pairing — glm21-10's
     *                                 derivation, supplied by each shell).
     * @param string   $key            The live API key (never reported).
     * @param string   $plan           The validated plan.
     * @param string   $region         The validated region.
     * @param callable $report         function( string $label, mixed $value ): void —
     *                                 receives every safe fact line, in order.
     * @return array{availability: array{connected: bool, definitive: bool}, discovery: array{models: array<object>, live: bool}, generation: array{ok: bool, model_id: string, usage_total_tokens: int|null}, state_option_plaintext: bool} The structured outcome the shells judge.
     */
    public static function run( string $settings, string $provider, string $endpoint_class, string $key, string $plan, string $region, callable $report ): array
    {

        /*
         * glm25-6: the option names and the provider id ride their owner
         * constants — after a rename this sequence writes options the
         * plugin reads and probes the surface it reports as evidence.
         */
        update_option( $settings::OPTION_PLAN, $plan );
        update_option( $settings::OPTION_REGION, $region );
        update_option( $settings::KEY_OPTION, $key );

        // A REAL transporter (curl, no redirects) — live requests only.
        $registry = AiClient::defaultRegistry();
        $registry->setHttpTransporter( new HttpTransporter( new CurlPsr18Client() ) );

        Plugin::register( $registry );
        $registry->setProviderRequestAuthentication( $provider::PROVIDER_ID, new ApiKeyRequestAuthentication( $key ) );

        $endpoint = $endpoint_class::for_current_settings();
        $report( 'endpoint base', $endpoint->base_url() );
        $report( 'models route', $endpoint->models_url() );
        /*
         * glm15-4: the generation-route evidence rides the endpoint layer's
         * one owner (generation_url()) — never a literal that could print a
         * URL the plugin never requests after a vendor or plan route change.
         */
        $report( 'generation route', $endpoint->generation_url() );

        $state_option = $settings::STATE_OPTION;

        /*
         * 1. Availability (authenticated models probe with persisted verdict).
         *
         * Codex R13 #5: the availability verdict is persisted under the
         * selected provider's validation-state option with a five-minute
         * TTL — a repeat probe within that window would report "connected"
         * from the cached verdict without making the documented
         * authenticated request. The state option is a cache of a past
         * check (safe to clear), so it is deleted first: this step must
         * always exercise the live network path.
         *
         * GLM2 #6: the binding-scoped probe-MISS marker (60s) is a cache
         * of a past INCONCLUSIVE check and equally safe to clear — left in
         * place it made this step report the cached inconclusive outcome
         * (and fail) with zero live requests for up to a minute after one
         * transient failure.
         */
        delete_option( $state_option );

        $availability = $provider::availability();
        if ( $availability instanceof AbstractZaiProviderAvailability ) {
            $availability->clear_probe_miss_marker();
        }

        $start      = microtime( true );
        $connected  = $availability->isConfigured();
        $definitive = \is_array( get_option( $state_option ) );

        /*
         * R17b verifier sweep: isConfigured() answers TRUE for an
         * INCONCLUSIVE probe when no stored verdict remains (the delete
         * above removed it) — the credential is merely "not yet
         * disproven", a save-blocking default that must not masquerade as
         * a live acceptance pass. A DEFINITIVE verdict always persists
         * fresh state, so a missing state option after the call means the
         * request itself failed (transport error, 5xx, 429, 404, region
         * distrust): the step reports inconclusive and fails instead of
         * printing connected.
         */
        if ( ! $definitive ) {
            $report( 'availability', 'INCONCLUSIVE (no definitive live verdict)' );
        } else {
            $report( 'availability', $connected ? 'connected' : 'NOT connected' );
        }
        $report( 'availability ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );

        /*
         * 2. Model discovery through the directory (live models route).
         *
         * Codex R8 #6: listModelMetadata() NEVER throws on discovery
         * failure — it silently returns the static fallback, so a count
         * alone (and a PASS) could be reported with no live discovery at
         * all. Successful discovery is cached in a per-endpoint transient
         * while fallbacks never are, so the transient is the fallback-used
         * signal: it is deleted first (a cache — safe to clear) and
         * checked after the call.
         */
        $discovery_transient_ids = $endpoint_class::discovery_transient_ids( $plan, $region );
        foreach ( $discovery_transient_ids as $discovery_transient_id ) {
            delete_transient( $discovery_transient_id );
        }
        /*
         * glm29-8: the EVIDENCE transient is the named
         * discovery_cache_id(), never a positional pick out of the pair —
         * a reorder or a new marker would otherwise read the 60s '_miss'
         * marker (which stores literal true) and report 'live' after a
         * FAILED discovery. The pair list stays for the delete loop only.
         */
        $discovery_cache_id = $endpoint_class::discovery_cache_id( $plan, $region );

        $models = array();
        $live   = false;

        $start = microtime( true );
        try {
            $models = $provider::modelMetadataDirectory()->listModelMetadata();
            $report( 'models discovered', \count( $models ) );
            $report( 'model ids', implode( ', ', array_slice( array_map( static function ( $m ) {
                return $m->getId();
            }, $models ), 0, 12 ) ) );
            $report( 'discovery ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );

            $live = false !== get_transient( $discovery_cache_id );
            /*
             * GLM12 #11: the evidence names the URL this surface's
             * discovery actually requested — models_url() rides the
             * surface's own MODELS_ROUTE, never a hardcoded route.
             */
            $report( 'discovery source', $live ? 'live ' . $endpoint->models_url() : 'DISCOVERY FALLBACK (static catalog — live discovery failed or was malformed)' );
        } catch ( Throwable $e ) {
            // GLM7 #14: Throwable, not Exception — a PHP Error (a TypeError
            // from a strict-types mismatch against a live response shape)
            // reports this step FAILED, never an uncaught fatal.
            $report( 'models discovered', 'FAILED: ' . get_class( $e ) . ' ' . $e->getMessage() );
        }

        /*
         * 3. One generation through the plugin model class (Messages
         * protocol on the anthropic surface; chat completion on the openai
         * surface).
         */
        $generation_ok      = false;
        $generation_model   = '';
        $usage_total_tokens = null;

        $start = microtime( true );
        try {
            /*
             * glm19-9: the preferred id rides the catalog owner —
             * ids_for_plan()'s first entry, not a hardcoded literal (the
             * coding and general plan heads differ, and the next catalog
             * refresh would silently stale-date a shared literal). The
             * fallback to the first DISCOVERED id when live metadata does
             * not carry the preferred id emits a diagnostic: acceptance
             * evidence must name which model fronted the plan.
             */
            $generation_model = ZaiModelCatalog::ids_for_plan( $plan )[0];
            if ( array() !== $models && ! $provider::modelMetadataDirectory()->hasModelMetadata( $generation_model ) ) {
                $fallback_id     = $models[0]->getId();
                $generation_model = $fallback_id;
                $report( 'model fallback', "preferred catalog id absent from discovered metadata — using {$fallback_id}" );
            }
            /**
             * getProviderModel() (not the bare Provider::model()) binds the
             * registry's transporter and auth into the instance; both
             * surfaces' models implement the SDK's TextGenerationModelInterface.
             *
             * @var \Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel|\Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel $model
             */
            $model  = $registry->getProviderModel( $provider::PROVIDER_ID, $generation_model );
            $result = $model->generateTextResult( array(
                new Message( MessageRoleEnum::user(), array( new MessagePart( 'Reply with exactly: wp-connectors live probe ok' ) ) ),
            ) );
            $report( 'model used', $generation_model );
            $report( 'generated text', trim( $result->toText() ) );

            /*
             * Codex R17 review-body finding: a successfully parsed but
             * EMPTY answer is not an acceptance pass — the sentinel prompt
             * has a known non-empty reply, so blank (or whitespace-only)
             * output means the route answered nothing and the step fails
             * like the others instead of reporting PASS over a blank value.
             */
            $generation_ok = '' !== trim( $result->toText() );
            if ( ! $generation_ok ) {
                $report( 'generation', 'FAILED: the model returned empty output for the sentinel prompt' );
            }

            $usage_total_tokens = $result->getTokenUsage()->getTotalTokens();
            $report( 'usage total tokens', $usage_total_tokens );
            $report( 'generation ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );
        } catch ( Throwable $e ) {
            // GLM7 #14: as the discovery step above — Errors report FAILED.
            $report( 'generation', 'FAILED: ' . get_class( $e ) . ' ' . $e->getMessage() );
        }

        /*
         * 4. The key must never appear in any state the plugin persisted
         * (the PHPUnit skeleton's own rule, shared with the CLI now —
         * reconciled to the stricter reading in both directions).
         */
        $stored_state    = get_option( $state_option );
        $state_plaintext = false !== $stored_state
            && false !== strpos( (string) wp_json_encode( $stored_state ), $key );

        return array(
            'availability'           => array(
                'connected'  => $connected,
                'definitive' => $definitive,
            ),
            'discovery'              => array(
                'models' => $models,
                'live'   => $live,
            ),
            'generation'             => array(
                'ok'                 => $generation_ok,
                'model_id'           => $generation_model,
                'usage_total_tokens' => $usage_total_tokens,
            ),
            'state_option_plaintext' => $state_plaintext,
        );
    }
}
