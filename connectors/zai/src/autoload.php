<?php
/**
 * PSR-4 autoloader for the Connectors: z.ai plugin.
 *
 * Same pattern as the official provider plugins: no Composer at runtime.
 *
 * @since 0.1.0
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

$deicod_zai_autoloader = static function ( string $class_name ): void {
	$prefix = 'Deicod\\WpConnectors\\Zai\\';
	$len    = strlen( $prefix );

	if ( strncmp( $class_name, $prefix, $len ) !== 0 ) {
		return;
	}

	$relative = substr( $class_name, $len );
	$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_file( $file ) ) {
		require $file;
	}
};

spl_autoload_register( $deicod_zai_autoloader );
