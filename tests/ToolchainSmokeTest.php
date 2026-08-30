<?php
/**
 * Toolchain smoke test (Task 0.2).
 *
 * Verifies the pinned development dependencies are installed and loadable,
 * in particular the exact wordpress/php-ai-client SDK version the connectors
 * are built against.
 *
 * @package wp-connectors
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ToolchainSmokeTest extends TestCase
{
    public function testPhpVersionIsInSupportedRange(): void
    {
        // Runtime may be newer than the floor (dev hosts run 8.5); plugin code
        // itself must stay 7.4-compatible, enforced by phpcs-compat + php -l.
        $this->assertGreaterThanOrEqual(70400, PHP_VERSION_ID);
    }

    public function testPinnedAiClientSdkIsInstalled(): void
    {
        $this->assertTrue(class_exists(\WordPress\AiClient\AiClient::class));
        $this->assertSame('1.3.1', \WordPress\AiClient\AiClient::VERSION);
    }

    public function testComposerDeclaresExtZipForBuildAndArtifactTests(): void
    {
        // bin/build.php and the artifact tests fatal at `new ZipArchive()`
        // without ext-zip; declaring it in require-dev makes dependency
        // setup fail with an actionable platform error instead of a mid-run
        // fatal (review finding).
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertIsArray($composer['require-dev'] ?? null);
        $this->assertArrayHasKey('ext-zip', $composer['require-dev'], 'require-dev must declare the ext-zip platform package.');
    }

    public function testAiClientRegistryIsUsable(): void
    {
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();
        $this->assertInstanceOf(\WordPress\AiClient\Providers\ProviderRegistry::class, $registry);
        $this->assertSame($registry, \WordPress\AiClient\AiClient::defaultRegistry());
    }
}
