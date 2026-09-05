<?php
/**
 * Opt-in live smoke probe for the z.ai connector (Tasks 1.9 / 2.7).
 *
 *   php bin/zai-live-probe.php [--surface openai|anthropic] [--plan coding|general] [--region intl|cn]
 *
 * Every long option accepts both the space-separated and the '='-attached
 * value form (GLM8 #7).
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

use Deicod\WpConnectors\Zai\Availability\AbstractZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiAnthropicProviderAvailability;
use Deicod\WpConnectors\Zai\Availability\ZaiProviderAvailability;
use Deicod\WpConnectors\Zai\Endpoints\ZaiAnthropicEndpoint;
use Deicod\WpConnectors\Zai\Endpoints\ZaiEndpoint;
use Deicod\WpConnectors\Zai\Metadata\ZaiModelCatalog;
use Deicod\WpConnectors\Zai\Plugin;
use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\HttpTransporter;

/**
 * Resolves the live key from the documented runtime sources only.
 *
 * glm15-3: HOME may be UNSET (cron, systemd) — getenv()
 * then returns false, and the previous bare concatenation probed the
 * filesystem ROOT ('/.config/z.ai/api_key'), silently using whatever
 * unrelated readable file lives there as the live API key. The fallback
 * is skipped entirely when no usable HOME exists.
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

    $home = getenv( 'HOME' );
    if ( \is_string( $home ) && '' !== $home ) {
        $file = $home . '/.config/z.ai/api_key';
        if ( is_file( $file ) && is_readable( $file ) ) {
            return trim( (string) file_get_contents( $file ) );
        }
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

/**
 * Returns one long-option value, or the default when absent/malformed.
 *
 * GLM8 #7: getopt() returns an ARRAY for a repeated option — the old
 * (string) cast emitted an Array-to-string notice and handed the
 * whitelist checks the literal 'Array'. A malformed value normalizes to
 * '' so the per-option whitelist rejects it with its own diagnostic.
 *
 * @param array  $args    The getopt() result.
 * @param string $name    Option name (without leading dashes).
 * @param string $default Value used when the option is absent.
 * @return string The option value ('' when present but malformed).
 */
function zai_live_probe_option( array $args, string $name, string $default ): string
{
    if ( ! isset( $args[ $name ] ) ) {
        return $default;
    }

    return \is_string( $args[ $name ] ) ? $args[ $name ] : '';
}

/*
 * GLM8 #7: getopt's OPTIONAL-value '::' declarations (this probe's old
 * form) capture only the '--option=value' syntax — the conventional
 * space-separated '--option value' form returns false for every
 * declared option, which the (string) cast turned into '' and rejected
 * with a diagnostic that blamed the VALUE ('--surface must be openai or
 * anthropic' for exactly that value). The REQUIRED-value ':'
 * declarations below accept BOTH forms. Their one silent gap: a bare
 * '--option' with no value at all drops out of the getopt() result
 * entirely (or swallows the next token as its value), so the missing
 * value is detected against the raw argv — a bare '--option' token
 * whose following token is absent or itself option-led can only ever
 * mean a missing value (none of this probe's values starts with '--').
 */
global $argv;
$zai_probe_argv = $argv;

foreach ( array( 'surface', 'plan', 'region' ) as $zai_probe_option_name ) {
    $zai_probe_position = array_search( '--' . $zai_probe_option_name, $zai_probe_argv, true );
    if ( false === $zai_probe_position ) {
        continue;
    }

    $zai_probe_next = isset( $zai_probe_argv[ $zai_probe_position + 1 ] ) ? (string) $zai_probe_argv[ $zai_probe_position + 1 ] : null;
    if ( null === $zai_probe_next || '--' === substr( $zai_probe_next, 0, 2 ) ) {
        fwrite( STDERR, "live-probe: --{$zai_probe_option_name} requires a value (use --{$zai_probe_option_name} <value> or --{$zai_probe_option_name}=<value>)\n" );
        exit( 2 );
    }
}

$args = getopt( '', array( 'surface:', 'plan:', 'region:' ) );
$surface = zai_live_probe_option( $args, 'surface', 'openai' );
if ( ! in_array( $surface, array( 'openai', 'anthropic' ), true ) ) {
    fwrite( STDERR, "live-probe: --surface must be openai or anthropic\n" );
    exit( 2 );
}

/*
 * GLM10 #15: ONE per-surface fact table, chosen after the surface
 * validates. The script previously hand-composed the plan/region option
 * names and selected ~8 per-surface facts through scattered inline
 * ternaries (the plan default, provider id/class, key/state options,
 * endpoint class) although it already rode owner constants elsewhere —
 * an option rename would have stranded the probe writing options
 * nothing reads while it still printed the chosen plan/region as
 * acceptance evidence, misleading evidence for the exact billing-surface
 * risk the plan/region whitelists exist for. Every fact now rides its
 * owner: the settings layer's OPTION_PLAN/OPTION_REGION, the
 * availability layer's KEY_OPTION/STATE_OPTION, the endpoint layer's
 * discovery_transient_ids() — and (GLM11 #5) the provider layer's
 * PROVIDER_ID and the settings layer's DEFAULT_PLAN, the last two
 * hand-composed identity literals: a PROVIDER_ID rename would have
 * left Plugin::register() under the new id while the probe wired
 * setProviderRequestAuthentication()/getProviderModel() to the stale
 * one, failing with a diagnostic that never points at the stale
 * literal.
 */
$zai_probe_surfaces = array(
    'openai' => array(
        'settings'     => PlanRegionSettings::class,
        'endpoint'     => ZaiEndpoint::class,
        'provider'     => ZaiProvider::class,
        'availability' => ZaiProviderAvailability::class,
        'provider_id'  => ZaiProvider::PROVIDER_ID,
        'default_plan' => PlanRegionSettings::DEFAULT_PLAN,
    ),
    'anthropic' => array(
        'settings'     => ZaiAnthropicPlanRegionSettings::class,
        'endpoint'     => ZaiAnthropicEndpoint::class,
        'provider'     => ZaiAnthropicProvider::class,
        'availability' => ZaiAnthropicProviderAvailability::class,
        'provider_id'  => ZaiAnthropicProvider::PROVIDER_ID,
        'default_plan' => ZaiAnthropicPlanRegionSettings::DEFAULT_PLAN,
    ),
);

$surface_facts = $zai_probe_surfaces[ $surface ];

$plan = zai_live_probe_option( $args, 'plan', $surface_facts['default_plan'] );
$region = zai_live_probe_option( $args, 'region', AbstractPlanRegionSettings::DEFAULT_REGION );

/*
 * Codex R7 #2: a typo in --plan/--region was stored and REPORTED while
 * the settings getters silently fell back to defaults before endpoint
 * resolution — the probe printed e.g. `china` while actually hitting
 * intl, making evidence misleading and potentially exercising the wrong
 * billing surface. Validate exactly like --surface: reject before any
 * key lookup or network call.
 *
 * glm15-10: the whitelists ride the declared owner
 * (AbstractPlanRegionSettings::PLANS/REGIONS) — the third hand-copy of
 * the lists after the settings layer and uninstall.php, so a valid new
 * value was rejected here with a misleading diagnostic while the
 * plugin itself served it. The diagnostics compose from the same
 * constants.
 */
if ( ! in_array( $plan, AbstractPlanRegionSettings::PLANS, true ) ) {
    fwrite( STDERR, 'live-probe: --plan must be ' . implode( ' or ', AbstractPlanRegionSettings::PLANS ) . "\n" );
    exit( 2 );
}
if ( ! in_array( $region, AbstractPlanRegionSettings::REGIONS, true ) ) {
    fwrite( STDERR, 'live-probe: --region must be ' . implode( ' or ', AbstractPlanRegionSettings::REGIONS ) . "\n" );
    exit( 2 );
}

$key = zai_live_probe_key();
if ( '' === $key ) {
    fwrite( STDERR, "live-probe: no key found (ZAI_LIVE_API_KEY, WP_CONNECTORS_TEST_ZAI_API_KEY, or ~/.config/z.ai/api_key)\n" );
    exit( 2 );
}

update_option( $surface_facts['settings']::OPTION_PLAN, $plan );
update_option( $surface_facts['settings']::OPTION_REGION, $region );

$provider_id = $surface_facts['provider_id'];
$provider_class = $surface_facts['provider'];
$key_option = $surface_facts['availability']::KEY_OPTION;

zai_live_probe_report( 'date (UTC)', gmdate( 'Y-m-d H:i:s' ) );
zai_live_probe_report( 'surface', $surface );
zai_live_probe_report( 'plan', $plan );
zai_live_probe_report( 'region', $region );

$endpoint = $surface_facts['endpoint']::for_current_settings();
zai_live_probe_report( 'endpoint base', $endpoint->base_url() );
zai_live_probe_report( 'models route', $endpoint->models_url() );
/*
 * glm15-4: the generation-route evidence rides the endpoint layer's one
 * owner (generation_url()) — the previous instanceof ternary plus the
 * inline chat-completion route literal could print a URL the plugin never
 * requests after any vendor or plan route change (the Anthropic surface
 * already varies /messages vs /v1/messages by plan).
 */
zai_live_probe_report( 'generation route', $endpoint->generation_url() );

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
$state_option = $surface_facts['availability']::STATE_OPTION;
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
// GLM8 #11: the discovery transient ids come from the endpoint layer's
// one owner — no private prefix/md5/'_miss' composition here anymore.
$discovery_transient_ids = $endpoint::discovery_transient_ids( $plan, $region );
foreach ( $discovery_transient_ids as $discovery_transient_id ) {
    delete_transient( $discovery_transient_id );
}
$discovery_cache_id = $discovery_transient_ids[0];

$start = microtime( true );
try {
    $models = $provider_class::modelMetadataDirectory()->listModelMetadata();
    zai_live_probe_report( 'models discovered', count( $models ) );
    zai_live_probe_report( 'model ids', implode( ', ', array_slice( array_map( static function ( $m ) {
        return $m->getId();
    }, $models ), 0, 12 ) ) );
    zai_live_probe_report( 'discovery ms', (int) ( ( microtime( true ) - $start ) * 1000 ) );

    $discovered_live = false !== get_transient( $discovery_cache_id );
    /*
     * GLM12 #11: the evidence names the URL this surface's discovery
     * actually requested — $endpoint->models_url() rides the surface's
     * MODELS_ROUTE ('v1/models' on anthropic, 'models' on openai), where
     * the previously hardcoded Anthropic route misreported the openai
     * surface's {base}/models request to anyone reconciling the probe
     * output against transport logs or the endpoint matrix.
     */
    zai_live_probe_report( 'discovery source', $discovered_live ? 'live ' . $endpoint->models_url() : 'DISCOVERY FALLBACK (static catalog — live discovery failed or was malformed)' );
    if ( ! $discovered_live ) {
        $exit = 1;
    }
} catch ( Throwable $e ) {
    // GLM7 #14: Throwable, not Exception — a PHP Error (a TypeError from
    // a strict-types mismatch in the SDK/DTO layer against a live
    // response shape) must report this step FAILED like any other
    // failure, not crash the probe with an uncaught fatal and a stack
    // trace outside the safe-facts contract.
    zai_live_probe_report( 'models discovered', 'FAILED: ' . get_class( $e ) . ' ' . $e->getMessage() );
    $exit = 1;
}

// 3. One generation through the plugin model class (Messages protocol on
// the anthropic surface; chat completion on the openai surface).
$start = microtime( true );
try {
    /*
     * glm19-9: the preferred id rides the catalog owner the probe's own
     * header declares every fact rides — ids_for_plan()'s first entry,
     * not a hardcoded literal (the coding and general plan heads differ,
     * and the next catalog refresh would silently stale-date a shared
     * literal). The fallback to the first DISCOVERED id when live
     * metadata does not carry the preferred id now emits a diagnostic:
     * acceptance evidence must name which model fronted the plan.
     */
    $model_id = ZaiModelCatalog::ids_for_plan( $plan )[0];
    if ( isset( $models ) && array() !== $models && ! $provider_class::modelMetadataDirectory()->hasModelMetadata( $model_id ) ) {
        $fallback_id   = $models[0]->getId();
        $model_id      = $fallback_id;
        zai_live_probe_report( 'model fallback', "preferred catalog id absent from discovered metadata — using {$fallback_id}" );
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
} catch ( Throwable $e ) {
    // GLM7 #14: as the discovery step above — Errors report FAILED, never
    // an uncaught fatal.
    zai_live_probe_report( 'generation', 'FAILED: ' . get_class( $e ) . ' ' . $e->getMessage() );
    $exit = 1;
}

zai_live_probe_report( 'result', 0 === $exit ? 'PASS' : 'FAIL' );
exit( $exit );
