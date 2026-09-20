<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer dev autoloader, which provides the pinned
 * wordpress/php-ai-client SDK used by connector tests. The WordPress API
 * test harness (function stubs, option/cron resets, HTTP interception) is
 * loaded lazily by WpConnectorsTestCase so pure unit tests never pay for it.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Test bootstrap: vendor/autoload.php is missing. Run `php tools/composer.phar install` first.\n");
    exit(1);
}
require_once $autoload;

/*
 * Dev-only PSR-4 map for the connector namespaces, so tests can reference
 * plugin classes without depending on plugin main files having been loaded
 * first (each plugin also ships its own runtime autoloader — this one exists
 * purely to make test ordering irrelevant). The namespace suffix uses the
 * ONE shared derivation (acronym casing preserved, e.g. OpenAiOauth) also
 * enforced by bin/check-conventions.php and used by bin/build.php.
 */
require_once dirname( __DIR__ ) . '/bin/lib/plugin-tools.php';

$connectors_dir = dirname( __DIR__ ) . '/connectors';
foreach ( glob( $connectors_dir . '/*/src', GLOB_ONLYDIR ) ?: array() as $src_dir ) {
	$suffix = wp_connectors_namespace_suffix_from_slug( basename( dirname( $src_dir ) ) );
	$prefix = 'Deicod\\WpConnectors\\' . $suffix . '\\';
	spl_autoload_register(
		static function ( string $class ) use ( $prefix, $src_dir ) {
			$len = strlen( $prefix );
			if ( strncmp( $class, $prefix, $len ) !== 0 ) {
				return;
			}
			$file = $src_dir . '/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	);
}

/*
 * Dev-only PSR-4 map for the shared OAuth contracts (Task 3.1): shared/src
 * carries the source namespace Deicod\WpConnectors\Shared, which
 * bin/build.php rewrites into each plugin's private namespace at build
 * time (record 0005). Tests load the source directly; shipped plugins
 * never do.
 */
$shared_src = dirname( __DIR__ ) . '/shared/src';
if ( is_dir( $shared_src ) ) {
	$shared_prefix = 'Deicod\\WpConnectors\\Shared\\';
	spl_autoload_register(
		static function ( string $class ) use ( $shared_prefix, $shared_src ) {
			$len = strlen( $shared_prefix );
			if ( strncmp( $class, $shared_prefix, $len ) !== 0 ) {
				return;
			}
			$file = $shared_src . '/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	);

	/*
	 * The two harness fixtures that IMPLEMENT Shared interfaces ride
	 * the same guard (t31-ocr57-5): DeterministicClock and
	 * InMemoryTokenStorage implement ClockInterface and
	 * TokenStorageInterface from the namespace the autoloader above
	 * maps — resolved at CLASS LOAD, before any test runs — so in
	 * exactly the scenario the guard models (a checkout without
	 * shared/src) the unconditional require fatals ('Interface not
	 * found') instead of degrading. Riding the guard, the
	 * absent-checkout shape stays bootable and PHPUnit reports the
	 * missing fixtures per-test — the guard's own modeled behavior.
	 * The remaining requires below stay unconditional by census:
	 * no other harness file resolves a Shared symbol at load time
	 * (these two are the only Shared-typed files in harness/).
	 * Regression: driven out-of-band this round — a scratch
	 * checkout without shared/src (real vendor, harness, and
	 * plugin-tools staged) fatals at the pre-fix line 79 with the
	 * predicted 'Interface ClockInterface not found' and boots
	 * clean (exit 0) after the fix; the normal path guards true
	 * and requires as before (suite green through the full check).
	 *
	 * The gates name the INTERFACE FILES, not the directory alone
	 * (OCR round 65, t31-ocr65-4): the guard once modeled only the
	 * two states fully-absent and fully-present shared/src, but a
	 * PARTIAL checkout — directory present, one contract file
	 * missing (a sparse checkout, a mid-rebase worktree) —
	 * registered the autoloader and executed the requires anyway:
	 * the fixtures' implements clauses resolve their interfaces at
	 * LOAD time, the autoloader maps them to exactly the missing
	 * files, and the bootstrap FATALED — a whole-suite fatal over
	 * the one checkout shape between the two modeled states,
	 * defeating the guard's purpose (the fixtures' remaining Shared
	 * references — InstantArithmetic, StoredGrant, HeaderMap,
	 * AccessTokenSet — ride method bodies, resolved lazily at call
	 * time, so the implements clauses ARE the load-time closure;
	 * census-verified). Each require gates on its own interface
	 * file now: a partial checkout degrades to the documented
	 * per-test missing-fixture shape for exactly the missing
	 * pieces, never all-or-nothing and never a bootstrap fatal.
	 * Regression: driven END-TO-END and pinned in
	 * FoundationHarnessTest — a scratch checkout whose shared/src
	 * is copied MINUS Clock/ClockInterface.php runs this bootstrap
	 * in a child and boots clean (exit 0, the Clock fixture
	 * skipped, the Token fixture loading through its PRESENT
	 * interface); red at HEAD with the 'Interface ClockInterface
	 * not found' fatal.
	 */
	if ( file_exists( $shared_src . '/Clock/ClockInterface.php' ) ) {
		require_once __DIR__ . '/harness/DeterministicClock.php';
	}
	if ( file_exists( $shared_src . '/Grant/TokenStorageInterface.php' ) ) {
		require_once __DIR__ . '/harness/InMemoryTokenStorage.php';
	}
}

require_once __DIR__ . '/harness/wp-stubs.php';
require_once __DIR__ . '/harness/SdkHttpClient.php';
require_once __DIR__ . '/harness/CurlPsr18Client.php';
require_once __DIR__ . '/harness/WpConnectorsTestCase.php';
require_once __DIR__ . '/harness/FakeSecrets.php';
require_once __DIR__ . '/harness/HttpResponseFactory.php';
require_once __DIR__ . '/harness/SimpleArrayCache.php';
require_once __DIR__ . '/harness/OpaqueAuthentication.php';
require_once __DIR__ . '/harness/CapturingTransporter.php';
require_once __DIR__ . '/harness/ZaiLiveRoundTrip.php';
require_once __DIR__ . '/harness/AbstractZaiSurfaceRequestMappingTestCase.php';
require_once __DIR__ . '/harness/AbstractZaiModelDirectoryTestCase.php';
require_once __DIR__ . '/harness/AbstractZaiSurfaceResponseMappingTestCase.php';
require_once __DIR__ . '/harness/AbstractZaiSurfaceLiveSmokeTestCase.php';
