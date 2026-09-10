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

    /**
     * glm38-1: the curated PHP 8.0+ function floor for every tree the
     * phpcs-compat ruleset holds to "must run on 7.4-8.4" (bin, connectors,
     * shared, tests).
     *
     * The locked PHPCompatibility 9.3.5 predates several of these functions
     * and has no availability sniff for them — the fdiv spelling sailed
     * through the static gate and fataled the NAN guard pin on the declared
     * 7.4 floor instead of exercising it. The sweep is the grep-shaped
     * backstop for exactly the sniff-gap class (comments included: prose
     * naming the call shape gets rewritten, not exempted).
     *
     * Deliberately NOT listed: array_is_list (8.1) — the SDK polyfills it
     * on the 7.4 floor (glm31-9), and the harness canary pins the polyfill
     * loads. A name joins this list only with a demonstrated 7.4 fatal and
     * no polyfill in the tree.
     */
    public function testNoUnsupportedPhp8FunctionCallsInTheCompatTrees(): void
    {
        $floor8Functions = array(
            'fdiv',
            'str_contains',
            'str_starts_with',
            'str_ends_with',
            'preg_last_error_msg',
            'get_debug_type',
            'get_resource_id',
            'enum_exists',
        );

        $pattern = '/\b(' . implode('|', $floor8Functions) . ')\s*\(/';
        $scanned = 0;

        foreach ($this->compatTreeFiles() as $path) {
            ++$scanned;
            foreach (preg_split('/\R/u', (string) file_get_contents($path)) as $index => $line) {
                if (1 === preg_match($pattern, $line, $matches)) {
                    $this->fail(
                        sprintf(
                            'PHP 8.0+ function call on the 7.4 floor: %s() at %s:%d (PHPCompatibility 9.3.5 has no sniff for it — see glm38-1).',
                            $matches[1],
                            $path,
                            $index + 1
                        )
                    );
                }
            }
        }

        // Non-vacuity: the sweep must see the real trees, not an empty root.
        $this->assertGreaterThan(0, $scanned, 'The floor sweep must visit at least one file.');
    }

    /**
     * Every PHP file under the phpcs-compat ruleset's tree set, mirroring
     * its vendor/dist/tools and tests/fixtures/data exclusions.
     *
     * @return string[]
     */
    private function compatTreeFiles(): array
    {
        $files = array();
        $excludedData = realpath(__DIR__ . '/../tests/fixtures/data');

        foreach (array('/../bin', '/../connectors', '/../shared', '/../tests') as $tree) {
            $root = realpath(__DIR__ . $tree);
            if (false === $root) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if ('.php' !== substr($path, -4)) {
                    continue;
                }
                if (1 === preg_match('#(^|/)(vendor|dist|tools)/#', substr($path, strlen($root) + 1))) {
                    continue;
                }
                if (false !== $excludedData && 0 === strpos($path, $excludedData . '/')) {
                    continue;
                }
                $files[] = $path;
            }
        }

        return $files;
    }
}
