<?php
/**
 * PSR-4 autoloader for the Example Connector test fixture.
 *
 * Same pattern as the shipped plugins: no Composer at runtime.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare(strict_types=1);

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'Deicod\\WpConnectors\\ExampleConnector\\';
	$len    = strlen( $prefix );

	if ( strncmp( $class, $prefix, $len ) !== 0 ) {
		return;
	}

	$relative = substr( $class, $len );
	$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_file( $file ) ) {
		require $file;
	}
} );
