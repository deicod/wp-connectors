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
