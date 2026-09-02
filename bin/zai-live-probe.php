<?php
/**
 * Opt-in live smoke probe for the z.ai connector (Tasks 1.9 / 2.7).
 *
 *   php bin/zai-live-probe.php [--surface=openai|anthropic] [--plan=coding|general] [--region=intl|cn]
 *
 * Reads a real API key at RUNTIME from the environment
 * (ZAI_LIVE_API_KEY or WP_CONNECTORS_TEST_ZAI_API_KEY) or from
 * ~/.config/z.ai/api_key — the key is never written to the repository,
 * fixtures, logs, or output. Only safe facts are printed: endpoint URLs,
 * HTTP statuses, model IDs, the generated text, and timings.
 *
 * Exercises exactly the acceptance path of the selected surface
 * (default openai): availability probe, /models discovery, and one
 * generation through the plugin classes. The anthropic surface resolves
 * the /anthropic endpoints and speaks the Messages protocol; per SPEC §3.2
 * the SAME account key works on both surfaces.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

error_reporting( E_ALL );

$repo = dirname( __DIR__ );

require_once $repo . '/vendor/autoload.php';
require_once $repo . '/tests/harness/wp-stubs.php';
require_once $repo . '/tests/harness/SdkHttpClient.php';
require_once $repo . '/tests/harness/CurlPsr18Client.php';
require_once $repo . '/connectors/zai/src/autoload.php';

/**
 * Resolves the live key from the documented runtime sources only.
 *
 * @return string Empty when no key is available.
 */
function zai_live_probe_key(): string
{
    foreach ( array( 'ZAI_LIVE_API_KEY', 'WP_CONNECTORS_TEST_ZAI_API_KEY' ) as $name ) {
        $value = getenv( $name );
        if ( false !== $value && '' !== $value ) {
            return trim( $value );
        }
    }

    $file = getenv( 'HOME' ) . '/.config/z.ai/api_key';
    if ( is_file( $file ) && is_readable( $file ) ) {
        return trim( (string) file_get_contents( $file ) );
    }

    return '';
}

/**
 * Prints one result line.
 *
 * @param string $label Fact label.
 * @param mixed  $value Fact value (already redaction-safe).
 * @return void
 */
function zai_live_probe_report( string $label, $value ): void
{
    printf( "%-24s %s\n", $label . ':', is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
}

$args = getopt( '', array( 'surface::', 'plan::', 'region::' ) );
$surface = isset( $args['surface'] ) ? (string) $args['surface'] : 'openai';
if ( ! in_array( $surface, array( 'openai', 'anthropic' ), true ) ) {
    fwrite( STDERR, "live-probe: --surface must be openai or anthropic\n" );
    exit( 2 );
}
$plan = isset( $args['plan'] ) ? (string) $args['plan'] : ( 'anthropic' === $surface ? 'general' : 'coding' );
$region = isset( $args['region'] ) ? (string) $args['region'] : 'intl';

/*
 * Codex R7 #2: a typo in --plan/--region was stored and REPORTED while
 * the settings getters silently fell back to defaults before endpoint
 * resolution — the probe printed e.g. `china` while actually hitting
 * intl, making evidence misleading and potentially exercising the wrong
 * billing surface. Validate exactly like --surface: reject before any
 * key lookup or network call.
 */
if ( ! in_array( $plan, array( 'coding', 'general' ), true ) ) {
    fwrite( STDERR, "live-probe: --plan must be coding or general\n" );
    exit( 2 );
}
if ( ! in_array( $region, array( 'intl', 'cn' ), true ) ) {
    fwrite( STDERR, "live-probe: --region must be intl or cn\n" );
    exit( 2 );
}

$key = zai_live_probe_key();
if ( '' === $key ) {
    fwrite( STDERR, "live-probe: no key found (ZAI_LIVE_API_KEY, WP_CONNECTORS_TEST_ZAI_API_KEY, or ~/.config/z.ai/api_key)\n" );
    exit( 2 );
}

update_option( 'zai_connector_zai_' . ( 'anthropic' === $surface ? 'anthropic_' : '' ) . 'plan', $plan );
update_option( 'zai_connector_zai_' . ( 'anthropic' === $surface ? 'anthropic_' : '' ) . 'region', $region );

use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Plugin;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\HttpTransporter;

$provider_id = 'anthropic' === $surface ? 'zai_anthropic' : 'zai';
$provider_class = 'anthropic' === $surface ? ZaiAnthropicProvider::class : ZaiProvider::class;
$key_option = 'anthropic' === $surface ? ZaiAnthropicProviderAvailability::KEY_OPTION : ZaiProviderAvailability::KEY_OPTION;

zai_live_probe_report( 'date (UTC)', gmdate( 'Y-m-d H:i:s' ) );
zai_live_probe_report( 'surface', $surface );
zai_live_probe_report( 'plan', $plan );
zai_live_probe_report( 'region', $region );

$endpoint = 'anthropic' === $surface ? ZaiAnthropicEndpoint::for_current_settings() : ZaiEndpoint::for_current_settings();
zai_live_probe_report( 'endpoint base', $endpoint->base_url() );
zai_live_probe_report( 'models route', $endpoint->models_url() );
zai_live_probe_report( 'messages route', 'anthropic' === $surface ? $endpoint->messages_url() : $endpoint->api_url( 'chat/completions' ) );

// A REAL transporter (curl, no redirects) — this script intentionally
// performs live network requests.
$registry = AiClient::defaultRegistry();
$registry->setHttpTransporter( new HttpTransporter( new CurlPsr18Client() ) );

Plugin::register( $registry );
$registry->setProviderRequestAuthentication( $provider_id, new ApiKeyRequestAuthentication( $key ) );
update_option( $key_option, $key );

$exit = 0;

// 1. Availability (authenticated models probe with persisted verdict).
/*
 * Codex R13 #5: the availability verdict is persisted under the selected
 * provider's validation-state option with a five-minute TTL — a repeat
 * probe within that window would report "connected" from the cached
 * verdict without making the documented authenticated request, even if
 * the route is currently unavailable or the credential was revoked.
 * The state option is a cache of a past check (safe to clear from a
 * probe), so it is deleted first exactly like the discovery transient
 * below: this step must always exercise the live network path.
 *
 * GLM2 #6: the binding-scoped probe-MISS marker (GLM1 #6, 60s) is a
 * cache of a past INCONCLUSIVE check and equally safe to clear — left
 * in place it made this step report the cached inconclusive outcome
 * (and fail) with zero live requests for up to a minute after one
 * transient failure.
 */
$state_option = 'anthropic' === $surface ? ZaiAnthropicProviderAvailability::STATE_OPTION : ZaiProviderAvailability::STATE_OPTION;
delete_option( $state_option );

$availability = $provider_class::availability();
if ( $availability instanceof AbstractZaiProviderAvailability ) {
    $availability->clear_probe_miss_marker();
}

$start = microtime( true );
$configured = $availability->isConfigured();

/*
 * R17b verifier sweep: isConfigured() answers TRUE for an INCONCLUSIVE
 * probe when no stored verdict remains (the delete above removed it) —
 * the credential is merely "not yet disproven", a save-blocking default
 * that must not masquerade as a live acceptance pass. A DEFINITIVE
 * verdict always persists fresh state, so a missing state option after
 * the call means the request itself failed (transport error, 5xx, 429,
 * 404, region distrust): report the step as inconclusive and fail it
 * instead of printing connected.
 */
$definitive_state = get_option( $state_option );
if ( ! is_array( $definitive_state ) ) {
    zai_live_probe_report( 'availability', 'INCONCLUSIVE (no definitive live verdict)' );
    $exit = 1;
} else {
    zai_live_probe_report( 'availability', $configured ? 'connected' : 'NOT connected' );
}

zai_live_probe_report( 'availability ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );

/*
 * Codex R7 #5: availability is a documented acceptance STEP — a false
 * verdict must fail the probe even when a later generation happens to
 * succeed (the two routes can apply different access policy). Do not
 * continue as if the step passed.
 */
if ( ! $configured ) {
    $exit = 1;
}

/*
 * 2. Model discovery through the directory (live models route).
 *
 * Codex R8 #6: listModelMetadata() NEVER throws on discovery failure —
 * it silently returns the static fallback, so the probe used to report a
 * model count (and PASS) with no live discovery at all. Successful
 * discovery is cached in a per-endpoint transient while fallbacks never
 * are, so the transient is the fallback-used signal: it is deleted first
 * (a cache — safe to clear from a probe) and checked after the call.
 */
$discovery_cache_id = ( 'anthropic' === $surface ? 'zai_connector_zai_anthropic_models_' : 'zai_connector_zai_models_' )
    . md5( $endpoint->cache_key() );
delete_transient( $discovery_cache_id );
delete_transient( $discovery_cache_id . '_miss' );

$start = microtime( true );
try {
    $models = $provider_class::modelMetadataDirectory()->listModelMetadata();
    zai_live_probe_report( 'models discovered', count( $models ) );
    zai_live_probe_report( 'model ids', implode( ', ', array_slice( array_map( static function ( $m ) {
        return $m->getId();
    }, $models ), 0, 12 ) ) );
    zai_live_probe_report( 'discovery ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );

    $discovered_live = false !== get_transient( $discovery_cache_id );
    zai_live_probe_report( 'discovery source', $discovered_live ? 'live /v1/models' : 'DISCOVERY FALLBACK (static catalog — live discovery failed or was malformed)' );
    if ( ! $discovered_live ) {
        $exit = 1;
    }
} catch ( Exception $e ) {
    zai_live_probe_report( 'models discovered', 'FAILED: ' . $e->getMessage() );
    $exit = 1;
}

// 3. One generation through the plugin model class (Messages protocol on
// the anthropic surface; chat completion on the openai surface).
$start = microtime( true );
try {
    $model_id = 'glm-5.3';
    if ( isset( $models ) && ! $provider_class::modelMetadataDirectory()->hasModelMetadata( $model_id ) && array() !== $models ) {
        $model_id = $models[0]->getId();
    }
    /**
     * getProviderModel() (not the bare Provider::model()) binds the
     * registry's transporter and auth into the instance; both surfaces'
     * models implement the SDK's TextGenerationModelInterface.
     *
     * @var Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel|Deicod\WpConnectors\Zai\Models\ZaiAnthropicTextGenerationModel $model
     */
    $model = $registry->getProviderModel( $provider_id, $model_id );
    $result = $model->generateTextResult( array(
        new Message( MessageRoleEnum::user(), array( new MessagePart( 'Reply with exactly: wp-connectors live probe ok' ) ) ),
    ) );
    zai_live_probe_report( 'model used', $model_id );
    zai_live_probe_report( 'generated text', trim( $result->toText() ) );

    /*
     * Codex R17 review-body finding: a successfully parsed but EMPTY
     * answer is not an acceptance pass — the sentinel prompt has a
     * known non-empty reply, so blank (or whitespace-only) output means
     * the route answered nothing and the probe must fail like the other
     * acceptance steps instead of reporting PASS over a blank value.
     */
    if ( '' === trim( $result->toText() ) ) {
        zai_live_probe_report( 'generation', 'FAILED: the model returned empty output for the sentinel prompt' );
        $exit = 1;
    }

    zai_live_probe_report( 'usage total tokens', $result->getTokenUsage()->getTotalTokens() );
    zai_live_probe_report( 'generation ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );
} catch ( Exception $e ) {
    zai_live_probe_report( 'generation', 'FAILED: ' . get_class( $e ) . ' ' . $e->getMessage() );
    $exit = 1;
}

zai_live_probe_report( 'result', 0 === $exit ? 'PASS' : 'FAIL' );
exit( $exit );
