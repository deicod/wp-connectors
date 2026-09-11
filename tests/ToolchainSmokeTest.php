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
        // floor82 (user decision 2026-09-11): the supported floor is 8.2;
        // the runtime may be newer (dev hosts run 8.5), but a suite run on
        // anything older proves nothing about the shipped plugin.
        $this->assertGreaterThanOrEqual(80200, PHP_VERSION_ID);
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
     * floor82 (superseding glm38-1's pin): the curated POST-floor function
     * vocabulary for every tree the phpcs-compat ruleset holds to "must run
     * on 8.2-8.4" (bin, connectors, shared, tests).
     *
     * glm38-1's form banned 8.0+ functions against the former 7.4 floor —
     * fdiv fatals were the demonstrated class, and the locked
     * PHPCompatibility 9.3.5 predates the function and has no sniff for it.
     * With the 8.2 floor every pre-8.2 function is legal (fdiv,
     * str_contains, array_is_list, enum_exists included — the fdiv spelling
     * in the NAN pin stays the NAN constant anyway: clearer than the
     * division spelling, and the zai sibling suite's idiom since GLM2 #4),
     * so the sweep's job flips to the OTHER side of the range: functions
     * introduced ABOVE the floor (8.3/8.4 additions) would fatal every 8.2
     * install while phpcs-compat 9.3.5 — pinned in 2019 — cannot see them
     * either. Same grep-shaped backstop, same sniff-gap class, opposite
     * direction. Comments included: prose naming the call shape gets
     * rewritten, not exempted (the pin flagged its own first docblock).
     *
     * A name joins this list only with a demonstrated unavailable-at-8.2
     * fatal and no polyfill in the tree.
     */
    public function testNoPostFloorFunctionCallsInTheCompatTrees(): void
    {
        $postFloorFunctions = array(
            // PHP 8.3 additions.
            'json_validate',
            'str_increment',
            'str_decrement',
            'stream_context_set_options',
            'mysqli_execute_query',
            'posix_eaccess',
            'posix_sysconf',
            'posix_pathconf',
            'posix_fpathconf',
            // PHP 8.4 additions.
            'array_find',
            'array_find_key',
            'array_any',
            'array_all',
            'mb_trim',
            'mb_ltrim',
            'mb_rtrim',
            'bcdivmod',
        );

        $pattern = '/\b(' . implode('|', $postFloorFunctions) . ')\s*\(/';
        $scanned = 0;

        foreach ($this->compatTreeFiles() as $path) {
            ++$scanned;
            foreach (preg_split('/\R/u', (string) file_get_contents($path)) as $index => $line) {
                if (1 === preg_match($pattern, $line, $matches)) {
                    $this->fail(
                        sprintf(
                            'Function call unavailable on the 8.2 floor: %s() at %s:%d (PHPCompatibility 9.3.5 has no sniff for it — see floor82/glm38-1).',
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
