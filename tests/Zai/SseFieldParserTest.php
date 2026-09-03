<?php
/**
 * Shared SSE field-parser tests (GLM7 #18).
 *
 * Behavior contract of the ONE field parser both aggregators consume:
 * comment/empty-line skipping, the GLM5 #8 event-value whitespace rules,
 * multi-line data joining, and the ignored id:/retry:/unknown fields.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Support\AnthropicSseAggregator;
use Deicod\WpConnectors\Zai\Support\SseFieldParser;

final class SseFieldParserTest extends WpConnectorsTestCase
{
    public function testParsesEventAndDataFields()
    {
        $fields = SseFieldParser::parse("event: message_stop\ndata: {\"type\":\"message_stop\"}");

        $this->assertSame('message_stop', $fields['event']);
        $this->assertSame('{"type":"message_stop"}', $fields['data']);
    }

    public function testJoinsMultiLineDataAndStripsLeadingSpacesOnly()
    {
        /*
         * The data: value strips leading SPACES only (the spec's optional
         * space, historically spaces-only on both surfaces — deliberately
         * NOT tabs). A line with leading whitespace before the field name
         * is not a data: field at all.
         */
        $fields = SseFieldParser::parse("data: first\ndata:  second \ndata:\tthird\n data: not-a-field");

        $this->assertNull($fields['event']);
        $this->assertSame("first\nsecond \n\tthird", $fields['data']);
    }

    public function testAnEmptyEventValueCountsAsAbsent()
    {
        // GLM5 #8: a spec-legal empty 'event:' governs nothing — the
        // payload's type member does; an empty name is null, and a
        // tab-separated value trims clean.
        $this->assertNull(SseFieldParser::parse("event:\ndata: {}")['event']);
        $this->assertNull(SseFieldParser::parse("event:   \ndata: {}")['event']);
        $this->assertSame('ping', SseFieldParser::parse("event:\t ping \t\ndata: {}")['event']);
    }

    public function testCommentsEmptyLinesAndUnknownFieldsYieldNoData()
    {
        $this->assertNull(SseFieldParser::parse(': keep-alive comment')['data']);
        $this->assertNull(SseFieldParser::parse('id: 42')['data']);
        $this->assertNull(SseFieldParser::parse('retry: 10000')['data']);
        $this->assertNull(SseFieldParser::parse('future-field: x')['data']);
        $this->assertNull(SseFieldParser::parse('')['data']);
    }

    public function testBothAggregatorsConsumeTheOneSharedParser()
    {
        /*
         * GLM7 #18 (extraction pin, the GLM4 #10 pattern): both
         * aggregators must ride SseFieldParser for their field parsing,
         * and neither may hand-roll the event:/data: line loop again —
         * the copy-paste the extraction removed.
         */
        foreach (array(
            'legacy' => __DIR__ . '/../../connectors/zai/src/Support/SseAggregator.php',
            'anthropic' => __DIR__ . '/../../connectors/zai/src/Support/AnthropicSseAggregator.php',
        ) as $label => $path) {
            $source = (string) file_get_contents($path);

            $this->assertSame(
                1,
                preg_match_all('/SseFieldParser::parse\(/', $source),
                "[{$label}] The aggregator must consume the shared field parser."
            );
            $this->assertSame(
                0,
                preg_match_all('/strpos\( \$line, \'data:\' \)/', $source),
                "[{$label}] The aggregator must not hand-roll the data: line loop."
            );
        }
    }
}
