<?php
/**
 * glm16-8 — shared mislabeled-body JSON fallback tests.
 *
 * Pins the shared scaffold's contract both model surfaces consume:
 * the decoder's raw view as the object-root oracle (only a JSON
 * OBJECT body is even attempted), the already-decoded pair handed to
 * the surface's parse callable (glm15-7's one-decode contract), the
 * glm14-2 marker propagation, and the null fallback for every other
 * ResponseException.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use Deicod\WpConnectors\Zai\Support\FixedMessageResponseException;
use Deicod\WpConnectors\Zai\Support\JsonFallbackResult;

final class JsonFallbackResultTest extends WpConnectorsTestCase
{
    public function testAnObjectRootRunsTheSurfaceParseOnTheDecodedPair()
    {
        $seen  = null;
        $stub  = $this->createMock(GenerativeAiResult::class);

        $result = JsonFallbackResult::parse(
            '{"content":[{"type":"text","text":"Hi"}]}',
            function ($data, $raw) use (&$seen, $stub) {
                $seen = array($data, $raw);

                return $stub;
            }
        );

        $this->assertSame($stub, $result, 'The parse callable\'s result passes through.');
        $this->assertIsArray($seen[0], 'The parse callable receives the associative view.');
        $this->assertInstanceOf(\stdClass::class, $seen[1], 'The parse callable receives the raw object-ness oracle — no second decode.');
        $this->assertSame('Hi', $seen[1]->content[0]->text, 'Both views read the ONE shared decode.');
    }

    /**
     * @dataProvider provideNonObjectRoots
     */
    public function testANonObjectRootReturnsNullWithoutRunningTheParse($body)
    {
        $ran = false;

        $result = JsonFallbackResult::parse(
            $body,
            function ($data, $raw) use (&$ran) {
                $ran = true;

                return $this->createMock(GenerativeAiResult::class);
            }
        );

        $this->assertNull($result, 'Only a JSON OBJECT body is even attempted.');
        $this->assertFalse($ran, 'A non-object root must not run the surface parse.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function provideNonObjectRoots(): array
    {
        return array(
            'JSON list' => array('["lost"]'),
            'scalar' => array('"done"'),
            'undecodable' => array('not json'),
            'empty' => array(''),
        );
    }

    public function testThePluginMarkerRejectionPropagates()
    {
        // glm14-2: the plugin's own fixed-message rejections surface even
        // on the fallback path — never swallowed into null.
        $marker = FixedMessageResponseException::fixed('zai_anthropic', 'data', 'The precise rejection.');

        try {
            JsonFallbackResult::parse(
                '{"object":true}',
                function ($data, $raw) use ($marker) {
                    throw $marker;
                }
            );
            $this->fail('The marker exception must propagate.');
        } catch (FixedMessageResponseException $e) {
            $this->assertSame($marker, $e);
        }
    }

    public function testAnyOtherResponseExceptionFallsBackToNull()
    {
        // The GLM8 #5/GLM12 #3 contract: a JSON object that is no valid
        // surface payload returns null so the caller surfaces its
        // stream-typed error.
        $result = JsonFallbackResult::parse(
            '{"object":true}',
            function ($data, $raw) {
                throw ResponseException::fromMissingData('zai_anthropic', 'data');
            }
        );

        $this->assertNull($result);
    }
}
