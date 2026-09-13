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
}

require_once __DIR__ . '/harness/wp-stubs.php';
require_once __DIR__ . '/harness/SdkHttpClient.php';
require_once __DIR__ . '/harness/CurlPsr18Client.php';
require_once __DIR__ . '/harness/WpConnectorsTestCase.php';
require_once __DIR__ . '/harness/FakeSecrets.php';
require_once __DIR__ . '/harness/DeterministicClock.php';
require_once __DIR__ . '/harness/InMemoryTokenStorage.php';
require_once __DIR__ . '/harness/HttpResponseFactory.php';
require_once __DIR__ . '/harness/SimpleArrayCache.php';
require_once __DIR__ . '/harness/OpaqueAuthentication.php';
require_once __DIR__ . '/harness/CapturingTransporter.php';
require_once __DIR__ . '/harness/ZaiLiveRoundTrip.php';
require_once __DIR__ . '/harness/AbstractZaiSurfaceRequestMappingTestCase.php';
require_once __DIR__ . '/harness/AbstractZaiModelDirectoryTestCase.php';
require_once __DIR__ . '/harness/AbstractZaiSurfaceResponseMappingTestCase.php';
require_once __DIR__ . '/harness/AbstractZaiSurfaceLiveSmokeTestCase.php';
