<?php
/**
 * GLM8 #13 — shared JSON shape predicate tests.
 *
 * The semantics of the one sequential-key rule (json_encode() emits a
 * PHP array as a JSON list only for 0..N-1 keys, the empty array
 * included) and the extraction pin that no former call site hand-rolls
 * it again.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Support\JsonShape;

final class JsonShapeTest extends WpConnectorsTestCase
{
    public function testTheListRuleMatchesTheJsonEncodeOracle()
    {
        // Every shape json_encode() decides, judged by the predicate and
        // the encoder itself: agreement is the contract (GLM8 #13).
        $shapes = array(
            'empty array' => array(),
            'sequential' => array('a', 'b', 'c'),
            'single sequential' => array('only'),
            'string keys' => array('city' => 'Paris', 'unit' => 'C'),
            'mixed keys' => array(0 => 'a', 'name' => 'b'),
            'non-zero start' => array(1 => 'a', 2 => 'b'),
            'gap' => array(0 => 'a', 2 => 'b'),
            'numeric string keys' => array('0' => 'a', '1' => 'b'),
            'descending' => array(2 => 'a', 1 => 'b', 0 => 'c'),
        );

        foreach ($shapes as $label => $value) {
            $encoded = json_encode($value);
            $this->assertIsString($encoded, "[{$label}] The fixture must encode.");

            $expected = '[' === $encoded[0];
            $this->assertSame(
                $expected,
                JsonShape::is_list($value),
                "[{$label}] The predicate must agree with json_encode() (got {$encoded})."
            );
        }
    }

    public function testNoCallSiteHandRollsTheSequentialKeyTest()
    {
        /*
         * GLM8 #13 (extraction pin, the GLM4 #10 pattern): the exact
         * sequential-key predicate existed four times (the model's two
         * list rejections, ToolArgsObjectNess's walk, UsageValidator's
         * oracle fallback). One JsonShape::is_list() serves them.
         *
         * glm31-9 SUPERSEDES the pin's first half (the GLM10 #4
         * lesson, documented here): the shared predicate itself rides
         * the NATIVE array_is_list() now (an engine function on the
         * 8.2 floor; on the former 7.4 floor the SDK's files-autoloaded
         * polyfill provided it — the SDK's own hot paths already call
         * it on every request, so every functional installation
         * provides it either way), and the range-over-
         * count idiom is forbidden EVERYWHERE, JsonShape included: a
         * second hand-maintained copy of platform semantics could
         * silently diverge from the verdict the SDK itself applies to
         * the same value.
         */
        foreach (array(
            'the shared predicate' => __DIR__ . '/../../connectors/zai/src/Support/JsonShape.php',
            'model' => __DIR__ . '/../../connectors/zai/src/Models/ZaiAnthropicTextGenerationModel.php',
            'tool args object-ness' => __DIR__ . '/../../connectors/zai/src/Support/ToolArgsObjectNess.php',
            'usage validator' => __DIR__ . '/../../connectors/zai/src/Support/UsageValidator.php',
        ) as $label => $path) {
            $this->assertSame(
                0,
                preg_match('/\\\\range\( 0, \\\\count\(/', (string) file_get_contents($path)),
                "[{$label}] No hand-rolled range-over-count copy may exist — ride the native predicate."
            );
        }

        foreach (array(
            'model' => __DIR__ . '/../../connectors/zai/src/Models/ZaiAnthropicTextGenerationModel.php',
            'tool args object-ness' => __DIR__ . '/../../connectors/zai/src/Support/ToolArgsObjectNess.php',
            'usage validator' => __DIR__ . '/../../connectors/zai/src/Support/UsageValidator.php',
        ) as $label => $path) {
            $this->assertStringContainsString(
                'JsonShape::is_list(',
                (string) file_get_contents($path),
                "[{$label}] The call site consumes JsonShape::is_list()."
            );
        }
    }

    public function testTheNativeListPredicateIsAvailableWhereverJsonShapeRuns()
    {
        /*
         * glm31-9 canary: JsonShape::is_list() calls the NATIVE
         * array_is_list() — an engine function on the 8.2 floor (the
         * SDK's files-autoloaded polyfill covered the former 7.4
         * floor). The canary pins the harness context loading it (the
         * same context every JsonShape consumer runs in), so an
         * environment missing the function fails HERE with a named
         * cause, never as an undefined-function fatal inside a
         * generation.
         */
        $this->assertTrue(
            \function_exists('array_is_list'),
            'array_is_list() must exist (native on 8.1+, the SDK polyfill below) wherever JsonShape runs.'
        );
        $this->assertTrue(\array_is_list(array(0 => 'a')));
        $this->assertFalse(\array_is_list(array('key' => 'a')));
    }
}
