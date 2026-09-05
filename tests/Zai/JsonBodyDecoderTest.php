<?php
/**
 * GLM10 #11 — shared vendor-body decoder tests.
 *
 * Pins the (array|null, stdClass|null) contract both model surfaces
 * consume: the canonical BOM strip, the vendor getData() null
 * normalization for the associative view, and the stdClass-only raw
 * object-ness oracle.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Support\JsonBodyDecoder;

final class JsonBodyDecoderTest extends WpConnectorsTestCase
{
    public function testAnObjectRootDecodesIntoBothViews()
    {
        list($data, $raw) = JsonBodyDecoder::decode('{"content":[{"type":"text","text":"Hi"}]}');

        $this->assertIsArray($data);
        $this->assertSame(array('content' => array(array('type' => 'text', 'text' => 'Hi'))), $data);
        $this->assertInstanceOf(\stdClass::class, $raw, 'The raw view keeps the object-ness oracle.');
        $this->assertSame('Hi', $raw->content[0]->text);
    }

    public function testAGatewayBomPrefixIsStrippedBeforeBothDecodes()
    {
        list($data, $raw) = JsonBodyDecoder::decode("\xEF\xBB\xBF" . '{"a":{"b":1}}');

        $this->assertSame(array('a' => array('b' => 1)), $data, 'The associative view reads the cleaned body.');
        $this->assertInstanceOf(\stdClass::class, $raw, 'The raw view reads the same cleaned body.');
        $this->assertSame(1, $raw->a->b);
    }

    /**
     * @dataProvider provideUndecodableOrNonArrayRoots
     */
    public function testUndecodableAndNonArrayRootsYieldTheNullContract($body, $label)
    {
        /*
         * The vendor getData() contract, one implementation: an empty
         * body, a decode failure, or a scalar root yield (null, null);
         * a LIST root keeps the associative view (vendor getData()
         * returns arrays of either shape) but never the raw one.
         */
        list($data, $raw) = JsonBodyDecoder::decode($body);

        $expectedData = '[1,2]' === $body ? array(1, 2) : null;
        $this->assertSame($expectedData, $data, "{$label}: the associative view follows the vendor contract.");
        $this->assertNull($raw, "{$label}: no object root means no raw view.");
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function provideUndecodableOrNonArrayRoots()
    {
        return array(
            'empty body' => array('', 'empty body'),
            'broken json' => array('{"oops', 'broken json'),
            'scalar string root' => array('"just a string"', 'scalar string root'),
            'scalar int root' => array('123', 'scalar int root'),
            'null root' => array('null', 'null root'),
            'list root' => array('[1,2]', 'list root'),
        );
    }
}
