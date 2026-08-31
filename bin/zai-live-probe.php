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
    fwrite( STDERR, "live-probe: --plan must be coding or general
" );
    exit( 2 );
}
if ( ! in_array( $region, array( 'intl', 'cn' ), true ) ) {
    fwrite( STDERR, "live-probe: --region must be intl or cn
" );
    exit( 2 );
}

$key = zai_live_probe_key();
if ( '' === $key ) {
    fwrite( STDERR, "live-probe: no key found (ZAI_LIVE_API_KEY, WP_CONNECTORS_TEST_ZAI_API_KEY, or ~/.config/z.ai/api_key)\n" );
    exit( 2 );
}

update_option( 'zai_connector_zai_' . ( 'anthropic' === $surface ? 'anthropic_' : '' ) . 'plan', $plan );
update_option( 'zai_connector_zai_' . ( 'anthropic' === $surface ? 'anthropic_' : '' ) . 'region', $region );

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
$start = microtime( true );
$configured = $provider_class::availability()->isConfigured();
zai_live_probe_report( 'availability', $configured ? 'connected' : 'NOT connected' );
zai_live_probe_report( 'availability ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );

// 2. Model discovery through the directory (live models route).
$start = microtime( true );
try {
    $models = $provider_class::modelMetadataDirectory()->listModelMetadata();
    zai_live_probe_report( 'models discovered', count( $models ) );
    zai_live_probe_report( 'model ids', implode( ', ', array_slice( array_map( static function ( $m ) {
        return $m->getId();
    }, $models ), 0, 12 ) ) );
    zai_live_probe_report( 'discovery ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );
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
    zai_live_probe_report( 'usage total tokens', $result->getTokenUsage()->getTotalTokens() );
    zai_live_probe_report( 'generation ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );
} catch ( Exception $e ) {
    zai_live_probe_report( 'generation', 'FAILED: ' . get_class( $e ) . ' ' . $e->getMessage() );
    $exit = 1;
}

zai_live_probe_report( 'result', 0 === $exit ? 'PASS' : 'FAIL' );
exit( $exit );
