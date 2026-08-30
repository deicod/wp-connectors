<?php
/**
 * Opt-in live smoke probe for the z.ai connector (Task 1.9).
 *
 *   php bin/zai-live-probe.php [--plan=coding|general] [--region=intl|cn]
 *
 * Reads a real API key at RUNTIME from the environment
 * (ZAI_LIVE_API_KEY or WP_CONNECTORS_TEST_ZAI_API_KEY) or from
 * ~/.config/z.ai/api_key — the key is never written to the repository,
 * fixtures, logs, or output. Only safe facts are printed: endpoint URLs,
 * HTTP statuses, model IDs, the generated text, and timings.
 *
 * Exercises exactly the M1 acceptance path: availability probe, /models
 * discovery, and one chat completion through the plugin classes.
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

$args = getopt( '', array( 'plan::', 'region::' ) );
$plan = isset( $args['plan'] ) ? (string) $args['plan'] : 'coding';
$region = isset( $args['region'] ) ? (string) $args['region'] : 'intl';

$key = zai_live_probe_key();
if ( '' === $key ) {
    fwrite( STDERR, "live-probe: no key found (ZAI_LIVE_API_KEY, WP_CONNECTORS_TEST_ZAI_API_KEY, or ~/.config/z.ai/api_key)\n" );
    exit( 2 );
}

update_option( 'zai_connector_zai_plan', $plan );
update_option( 'zai_connector_zai_region', $region );

use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Plugin;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\HttpTransporter;

zai_live_probe_report( 'date (UTC)', gmdate( 'Y-m-d H:i:s' ) );
zai_live_probe_report( 'plan', $plan );
zai_live_probe_report( 'region', $region );

$endpoint = ZaiEndpoint::for_current_settings();
zai_live_probe_report( 'endpoint base', $endpoint->base_url() );

// A REAL transporter (curl, no redirects) — this script intentionally
// performs live network requests.
$registry = AiClient::defaultRegistry();
$registry->setHttpTransporter( new HttpTransporter( new CurlPsr18Client() ) );

Plugin::register( $registry );
$registry->setProviderRequestAuthentication( 'zai', new ApiKeyRequestAuthentication( $key ) );
update_option( ZaiProviderAvailability::KEY_OPTION, $key );

$exit = 0;

// 1. Availability (authenticated /models probe with persisted verdict).
$start = microtime( true );
$configured = ZaiProvider::availability()->isConfigured();
zai_live_probe_report( 'availability', $configured ? 'connected' : 'NOT connected' );
zai_live_probe_report( 'availability ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );

// 2. Model discovery through the directory (live /models).
$start = microtime( true );
try {
    $models = ZaiProvider::modelMetadataDirectory()->listModelMetadata();
    zai_live_probe_report( 'models discovered', count( $models ) );
    zai_live_probe_report( 'model ids', implode( ', ', array_slice( array_map( static function ( $m ) {
        return $m->getId();
    }, $models ), 0, 12 ) ) );
    zai_live_probe_report( 'discovery ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );
} catch ( Exception $e ) {
    zai_live_probe_report( 'models discovered', 'FAILED: ' . $e->getMessage() );
    $exit = 1;
}

// 3. One chat completion through the plugin model class.
$start = microtime( true );
try {
    $model_id = 'glm-5.3';
    if ( isset( $models ) && ! ZaiProvider::modelMetadataDirectory()->hasModelMetadata( $model_id ) ) {
        $model_id = $models[0]->getId();
    }
    /**
     * The provider only constructs text generation models (ZaiProvider::createModel).
     *
     * @var Deicod\WpConnectors\Zai\Models\ZaiTextGenerationModel $model
     */
    $model = ZaiProvider::model( $model_id );
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
