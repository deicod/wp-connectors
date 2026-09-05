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
         * oracle fallback). One JsonShape::is_list() serves them; the
         * range-over-count idiom may not come back.
         */
        $this->assertSame(
            1,
            preg_match('/\\\\range\( 0, \\\\count\(/', (string) file_get_contents(__DIR__ . '/../../connectors/zai/src/Support/JsonShape.php')),
            'The predicate file owns the one hand-rolled copy.'
        );

        foreach (array(
            'model' => __DIR__ . '/../../connectors/zai/src/Models/ZaiAnthropicTextGenerationModel.php',
            'tool args object-ness' => __DIR__ . '/../../connectors/zai/src/Support/ToolArgsObjectNess.php',
            'usage validator' => __DIR__ . '/../../connectors/zai/src/Support/UsageValidator.php',
        ) as $label => $path) {
            $this->assertSame(
                0,
                preg_match('/\\\\range\( 0, \\\\count\(/', (string) file_get_contents($path)),
                "[{$label}] The call site must ride the shared predicate."
            );
            $this->assertStringContainsString(
                'JsonShape::is_list(',
                (string) file_get_contents($path),
                "[{$label}] The call site consumes JsonShape::is_list()."
            );
        }
    }
}
