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
require_once $repo . '/tests/harness/ZaiLiveRoundTrip.php';
require_once $repo . '/connectors/zai/src/autoload.php';

use Deicod\WpConnectors\Zai\Provider\ZaiAnthropicProvider;
use Deicod\WpConnectors\Zai\Provider\ZaiProvider;
use Deicod\WpConnectors\Zai\Settings\AbstractPlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\PlanRegionSettings;
use Deicod\WpConnectors\Zai\Settings\ZaiAnthropicPlanRegionSettings;
use Deicod\WpConnectors\Zai\Support\ZaiSurfaces;

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

/**
 * The SDK-dependent per-surface probe facts, keyed by settings class.
 *
 * glm21-10: the settings/endpoint pairing is DERIVED from the registry
 * (the loop below); this table carries only the columns the SDK-free
 * registry may not hold — the CLI name, the provider class, and the two
 * owner-constant identity facts (GLM11 #5). A declared return type (not
 * a foldable literal): the registry may grow a surface this table has no
 * row for, and the loop's guard must stay reachable — a registry surface
 * without its facts row exits loudly instead of silently probing the
 * wrong surface.
 *
 * glm24-1: the availability-class column is GONE. Its only consumers
 * read KEY_OPTION/STATE_OPTION — constants the availability layer
 * itself aliases from the settings class (glm15-23's fixed alias
 * direction), so the registry row's settings class already carries
 * them. The hand-paired column was the one probe fact no lockstep pin
 * covered: a pairing swap between rows wrote surface A's key option and
 * deleted surface B's validation state while generation ran on A.
 *
 * @return array<string, array{cli: string, provider: class-string, provider_id: string, default_plan: string}>
 */
function zai_live_probe_sdk_facts(): array
{
    return array(
        PlanRegionSettings::class          => array(
            'cli'          => 'openai',
            'provider'     => ZaiProvider::class,
            'provider_id'  => ZaiProvider::PROVIDER_ID,
            'default_plan' => PlanRegionSettings::DEFAULT_PLAN,
        ),
        ZaiAnthropicPlanRegionSettings::class => array(
            'cli'          => 'anthropic',
            'provider'     => ZaiAnthropicProvider::class,
            'provider_id'  => ZaiAnthropicProvider::PROVIDER_ID,
            'default_plan' => ZaiAnthropicPlanRegionSettings::DEFAULT_PLAN,
        ),
    );
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
/*
 * glm23-3 (review round 23, finding 3): register_argc_argv=0 (a valid
 * php.ini setting — the CLI SAPI defaults it on, a hardened ini or a
 * -d flag turns it off) leaves $argv UNDEFINED and getopt() returning
 * false, so the pre-scan's strict array_search() fataled with a
 * TypeError before any diagnostic — violating this file's own GLM7 #14
 * rule that even Errors must surface as named FAILED steps. An absent
 * argv means no arguments to scan: the empty-array normalization walks
 * the usage path (every option at its default, stopping at the key
 * lookup with its named diagnostic).
 */
global $argv;
$zai_probe_argv = isset( $argv ) && \is_array( $argv ) ? $argv : array();

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
if ( false === $args ) {
	// glm23-3: the same register_argc_argv=0 shape — getopt() reads the
	// argv that is not there. No options parsed; the defaults below.
	$args = array();
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
 * owner: the settings layer's OPTION_PLAN/OPTION_REGION (and, since
 * glm24-1, KEY_OPTION/STATE_OPTION — the availability layer's constants
 * alias them), the endpoint layer's
 * discovery_transient_ids() — and (GLM11 #5) the provider layer's
 * PROVIDER_ID and the settings layer's DEFAULT_PLAN, the last two
 * hand-composed identity literals: a PROVIDER_ID rename would have
 * left Plugin::register() under the new id while the probe wired
 * setProviderRequestAuthentication()/getProviderModel() to the stale
 * one, failing with a diagnostic that never points at the stale
 * literal.
 *
 * glm21-10: the settings/endpoint PAIRING is DERIVED from the one
 * cross-file owner registry — zai_live_probe_sdk_facts() holds only the
 * SDK-dependent columns the SDK-free registry may not carry (glm20-4's
 * split), keyed by the registry row's settings class. A pairing swap in
 * the registry reaches the probe with the same edit, and a third
 * surface without its facts row fails loudly below instead of silently
 * writing surface A's plan/region options while wiring surface B's
 * provider — the misleading-evidence class the file's own Codex R7 #2
 * comment warns about. The CLI whitelist and the default surface derive
 * from the built map's keys (registration order: the first registry row
 * is the default, as 'openai' was).
 */
$zai_probe_sdk_facts = zai_live_probe_sdk_facts();

$zai_probe_surfaces = array();
foreach ( ZaiSurfaces::SURFACES as $zai_probe_row ) {
    $zai_probe_facts = $zai_probe_sdk_facts[ $zai_probe_row['settings'] ] ?? null;

    if ( null === $zai_probe_facts ) {
        fwrite( STDERR, 'live-probe: no SDK facts for surface ' . $zai_probe_row['settings'] . " (add its row to zai_live_probe_sdk_facts() in bin/zai-live-probe.php)\n" );
        exit( 3 );
    }

    /*
     * glm29-9: the map mirrors every per-surface fact (the pins hold the
     * bracket spellings); the shared round-trip runner consumes settings/
     * provider/endpoint and re-derives PROVIDER_ID at wiring time.
     */
    $zai_probe_surfaces[ $zai_probe_facts['cli'] ] = array(
        'settings'     => $zai_probe_row['settings'],
        'endpoint'     => $zai_probe_row['endpoint'],
        'provider'     => $zai_probe_facts['provider'],
        'provider_id'  => $zai_probe_facts['provider_id'],
        'default_plan' => $zai_probe_facts['default_plan'],
    );
}

$surface = zai_live_probe_option( $args, 'surface', (string) array_key_first( $zai_probe_surfaces ) );
if ( ! isset( $zai_probe_surfaces[ $surface ] ) ) {
    fwrite( STDERR, 'live-probe: --surface must be ' . implode( ' or ', array_keys( $zai_probe_surfaces ) ) . "\n" );
    exit( 2 );
}

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

zai_live_probe_report( 'date (UTC)', gmdate( 'Y-m-d H:i:s' ) );
zai_live_probe_report( 'surface', $surface );
zai_live_probe_report( 'plan', $plan );
zai_live_probe_report( 'region', $region );

/*
 * glm29-9: the ordered acceptance steps — the option writes, the
 * registry/provider wiring, the endpoint evidence lines, the
 * availability probe (Codex R13 #5's state delete, GLM2 #6's miss-marker
 * clear, R17b's definitive-verdict rule), live discovery (Codex R8 #6's
 * transient clearing and fallback signal, GLM12 #11's evidence URL,
 * glm29-8's named cache id), one generation (glm19-9's preferred id and
 * fallback diagnostic, Codex R17's empty-output rule), and the
 * state-plaintext check — ride the ONE shared runner
 * (tests/harness/ZaiLiveRoundTrip.php) the opt-in PHPUnit smoke tests
 * also ride. This CLI shell judges the structured outcome through exit
 * codes; the report lines and their order are byte-identical to the
 * pre-glm29-9 probe output.
 */
$outcome = ZaiLiveRoundTrip::run(
    $surface_facts['settings'],
    $surface_facts['provider'],
    $surface_facts['endpoint'],
    $key,
    $plan,
    $region,
    'zai_live_probe_report'
);

$exit = 0;

/*
 * Codex R7 #5: availability is a documented acceptance STEP — a false
 * (or inconclusive) verdict must fail the probe even when a later
 * generation happens to succeed (the two routes can apply different
 * access policy).
 */
if ( ! $outcome['availability']['definitive'] || ! $outcome['availability']['connected'] ) {
    $exit = 1;
}

// Codex R8 #6: fallback discovery is a failed step, never a model count.
if ( ! $outcome['discovery']['live'] ) {
    $exit = 1;
}

// Codex R17: empty generation output is a failed step, never a pass.
if ( ! $outcome['generation']['ok'] ) {
    $exit = 1;
}

// glm29-9: the state-plaintext rule (the PHPUnit skeleton's own check,
// shared with the CLI now — reconciled to the stricter side).
if ( $outcome['state_option_plaintext'] ) {
    zai_live_probe_report( 'state option', 'FAILED: the validation state carries the key in plaintext' );
    $exit = 1;
}

zai_live_probe_report( 'result', 0 === $exit ? 'PASS' : 'FAIL' );
exit( $exit );
