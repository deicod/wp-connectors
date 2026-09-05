<?php
/**
 * SseFrameBuffer frame-splitter tests.
 *
 * Behavior contract (order, exhaustiveness, CR/LF/CRLF tolerance) plus
 * the Codex R15 #4 cursor work: constant-time drains of large frame
 * queues, interleaved feed()/pull() patterns, and reuse of a drained
 * buffer instance.
 *
 * @package wp-connectors
 */

declare( strict_types=1 );

use Deicod\WpConnectors\Zai\Support\SseAggregator;
use Deicod\WpConnectors\Zai\Support\SseFrameBuffer;

final class SseFrameBufferTest extends WpConnectorsTestCase
{
    public function testFramesArePulledInArrivalOrderUntilExhaustion()
    {
        $buffer = new SseFrameBuffer();
        $buffer->feed("event: one\ndata: 1\n\nevent: two\ndata: 2\n\n");
        $buffer->finish();

        $this->assertSame("event: one\ndata: 1", $buffer->pull());
        $this->assertSame("event: two\ndata: 2", $buffer->pull());
        $this->assertNull($buffer->pull(), 'An exhausted queue keeps returning null.');
        $this->assertNull($buffer->pull(), 'Repeated pulls on an empty queue stay null.');
    }

    public function testALargeFrameQueueDrainsInOrder()
    {
        // Codex R15 #4: the drain must stay linear-time (the cursor
        // replaced the reindexing array_shift) — 5000 frames complete
        // quickly and come out in exact order with no skips or repeats.
        $total = 5000;

        $buffer = new SseFrameBuffer();
        $chunk = '';
        for ($i = 0; $i < $total; $i++) {
            $chunk .= 'data: frame-' . $i . "\n\n";
        }
        $buffer->feed($chunk);
        $buffer->finish();

        $start = microtime(true);
        for ($i = 0; $i < $total; $i++) {
            $frame = $buffer->pull();
            $this->assertSame('data: frame-' . $i, $frame, 'Frame ' . $i . ' must arrive in order.');
        }
        $elapsed = microtime(true) - $start;

        $this->assertNull($buffer->pull());
        $this->assertLessThan(5.0, $elapsed, 'A 5000-frame drain completes quickly (linear-time cursor).');
    }

    public function testInterleavedFeedAndPullNeverSkipsOrDuplicates()
    {
        // Cursor discipline: feeding between pulls must not disturb the
        // pending frames or the read position.
        $buffer = new SseFrameBuffer();

        $buffer->feed("data: a\n\ndata: b\n\n");
        $this->assertSame('data: a', $buffer->pull());

        $buffer->feed("data: c\n\ndata: d\n\n");
        $this->assertSame('data: b', $buffer->pull());
        $this->assertSame('data: c', $buffer->pull());

        $buffer->feed("data: e\n\n");
        $this->assertSame('data: d', $buffer->pull());
        $this->assertSame('data: e', $buffer->pull());
        $this->assertNull($buffer->pull());
    }

    public function testADrainedBufferInstanceAcceptsNewFeeds()
    {
        // Compaction must reset BOTH the queue and the cursor: a reused
        // instance keeps working after a full drain.
        $buffer = new SseFrameBuffer();

        $buffer->feed("data: first\n\n");
        $this->assertSame('data: first', $buffer->pull());
        $this->assertNull($buffer->pull(), 'The buffer reports exhaustion.');

        $buffer->feed("data: second\n\n");
        $this->assertSame('data: second', $buffer->pull(), 'A re-fed drained buffer yields the new frame.');
        $this->assertNull($buffer->pull());
    }

    public function testTwentyThousandFramesSplitInOneFeed()
    {
        // Codex R18 #1 volume harness: one feed() of 20k frames splits
        // exactly — count, boundaries, and order all preserved.
        $total = 20000;

        $body = '';
        for ($i = 0; $i < $total; $i++) {
            $body .= 'data: frame-' . $i . "\n\n";
        }

        $buffer = new SseFrameBuffer();
        $buffer->feed($body);
        $buffer->finish();

        $this->assertSame('data: frame-0', $buffer->pull(), 'The first frame is intact.');
        for ($i = 1; $i < $total - 1; $i++) {
            $this->assertSame('data: frame-' . $i, $buffer->pull());
        }
        $this->assertSame('data: frame-' . ($total - 1), $buffer->pull(), 'The last frame is intact.');
        $this->assertNull($buffer->pull());
    }

    public function testTheIncrementalFeedingBoundIsDocumentedAtFeed()
    {
        /*
         * glm15-24: the latent incremental-feed cost — each feed()
         * re-normalizes and rescans the entire unconsumed buffer, so
         * chunk-by-chunk feeding of one large frame is quadratic in the
         * TAIL (never the whole stream; consumed frames are split off)
         * — is accepted and documented, not fixed: both production
         * callers feed the complete body in one call, and a persistent
         * normalized/scanned cursor would make the prefix-strip and
         * CR-hold buffer rewrites regression surface in this heavily
         * pinned class. The pin holds the honest statement at the
         * site; re-open only with an incremental consumer or a
         * demonstrated slow path (the ledger entry).
         */
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/connectors/zai/src/Support/SseFrameBuffer.php');

        $this->assertStringContainsString('the honest bound', $source, 'feed() documents its per-call bound.');
        $this->assertStringContainsString('O(k · unconsumed-tail)', $source, 'The stated bound names the unit it is quadratic in.');
        $this->assertStringContainsString('deferred until an incremental consumer exists', $source, 'The deferral condition is stated at the site.');
    }

    public function testEightyThousandFramesFeedAndDrainQuickly()
    {
        /*
         * Codex R18 #1 fails-before perf guard: the offset scan is
         * linear (~12 ms feed + ~12 ms drain for 80k frames on the
         * reference Pi), while the old copy-per-delimiter split took
         * ~3.1 s at the same size. The 2.0 s bound holds ~87x headroom
         * over the post-fix measurement yet sits far below the old
         * quadratic cost, so it stays stable under load while still
         * failing the shape it guards against.
         */
        $total = 80000;

        $body = '';
        for ($i = 0; $i < $total; $i++) {
            $body .= 'data: x' . $i . "\n\n";
        }

        $start = microtime(true);

        $buffer = new SseFrameBuffer();
        $buffer->feed($body);
        $buffer->finish();

        $count = 0;
        while (null !== $buffer->pull()) {
            $count++;
        }

        $elapsed = microtime(true) - $start;

        $this->assertSame($total, $count, 'Every frame is split and drained.');
        $this->assertLessThan(2.0, $elapsed, 'Splitting and draining 80k frames must stay far below the old quadratic cost.');
    }

    /**
     * @dataProvider provideAwkwardChunkBoundaries
     */
    public function testChunkBoundariesSplitIdenticallyToASingleShotFeed($chunks)
    {
        /*
         * Codex R18 #1 (iii): the offset scan must not change
         * chunk-boundary behavior — a stream fed in awkward pieces
         * yields the SAME frames as one single-shot feed, including a
         * boundary inside a CRLF-CRLF delimiter, one between the two
         * blank-line newlines, and a trailing CR held across feeds.
         */
        $whole = implode('', $chunks);

        $single = new SseFrameBuffer();
        $single->feed($whole);
        $single->finish();

        $pieced = new SseFrameBuffer();
        foreach ($chunks as $chunk) {
            $pieced->feed($chunk);
        }
        $pieced->finish();

        $expected = array();
        while (null !== ($frame = $single->pull())) {
            $expected[] = $frame;
        }
        $actual = array();
        while (null !== ($frame = $pieced->pull())) {
            $actual[] = $frame;
        }

        $this->assertSame($expected, $actual, 'Chunked feeding yields the single-shot frames.');
        $this->assertNotSame(array(), $expected, 'The fixture must produce at least one frame.');
    }

    /**
     * @return array<string, list<list<string>>>
     */
    public function provideAwkwardChunkBoundaries()
    {
        return array(
            'boundary inside a CRLF-CRLF delimiter' => array(
                array("data: a\r\n\r", "\ndata: b\r\n\r\n"),
            ),
            'boundary between the two blank-line newlines' => array(
                array("data: a\n", "\ndata: b\n\n"),
            ),
            'trailing CR held across feeds' => array(
                array("data: a\n\ndata: b\r", "\r\n\r\ndata: c\n\n"),
            ),
            'one byte at a time' => array(
                str_split("data: a\n\ndata: b\r\n\r\n"),
            ),
        );
    }

    public function testFinishFlushesTheFinalUnterminatedFrameAfterALargeFeed()
    {
        // Codex R18 #1 (iv): the flush still fires after the offset scan
        // consumed thousands of frames in the same feed.
        $total = 5000;

        $body = '';
        for ($i = 0; $i < $total; $i++) {
            $body .= 'data: frame-' . $i . "\n\n";
        }
        $body .= 'data: final-unterminated';

        $buffer = new SseFrameBuffer();
        $buffer->feed($body);
        $buffer->finish();

        for ($i = 0; $i < $total; $i++) {
            $buffer->pull();
        }
        $this->assertSame('data: final-unterminated', $buffer->pull(), 'The unterminated tail flushes as the last frame.');
        $this->assertNull($buffer->pull());
    }

    public function testMixedLineEndingsStillSplitFrames()
    {
        // Regression guard for the framing the cursor change must not
        // disturb: CR, LF, and CRLF may mix freely.
        $buffer = new SseFrameBuffer();
        $buffer->feed("event: a\r\ndata: 1\r\n\r\nevent: b\rdata: 2\r\r\n");

        $this->assertSame("event: a\ndata: 1", $buffer->pull());
        $this->assertSame("event: b\ndata: 2", $buffer->pull());
        $this->assertNull($buffer->pull());
    }

    public function testALeadingUtf8BomIsStrippedBeforeFraming()
    {
        /*
         * GLM3 #7: a gateway-prepended BOM glued itself to the first
         * frame, where it matched no 'data:'/'event:' prefix — the frame
         * was silently dropped, so a single-event stream aggregated to
         * null. The BOM is stripped at stream start; the first frame
         * comes out byte-identical to a BOM-less stream.
         */
        $bom = "\xEF\xBB\xBF";

        $buffer = new SseFrameBuffer();
        $buffer->feed($bom . "event: a\ndata: 1\n\ndata: 2\n\n");
        $buffer->finish();

        $this->assertSame("event: a\ndata: 1", $buffer->pull(), 'The first frame must survive the BOM intact.');
        $this->assertSame('data: 2', $buffer->pull());
        $this->assertNull($buffer->pull());
    }

    public function testASplitUtf8BomIsHeldUntilItsBytesComplete()
    {
        // GLM3 #7: a BOM split across chunks must not half-strip — the
        // held prefix disambiguates once the next byte arrives.
        $buffer = new SseFrameBuffer();
        $buffer->feed("\xEF");
        $this->assertNull($buffer->pull(), 'A lone BOM first byte holds no frame yet.');

        $buffer->feed("\xBB");
        $this->assertNull($buffer->pull(), 'Two BOM bytes still hold.');

        $buffer->feed("\xBFdata: b\n\n");
        $this->assertSame('data: b', $buffer->pull(), 'The completed BOM strips and the frame survives intact.');
        $this->assertNull($buffer->pull());
    }

    public function testAUtf8BomAfterTheFirstByteIsNotStripped()
    {
        // The strip window is stream start only: a BOM appearing later in
        // the byte stream is ordinary (garbage) content and must survive
        // into the frame untouched.
        $buffer = new SseFrameBuffer();
        $buffer->feed("data: a\n\n");
        $this->assertSame('data: a', $buffer->pull());

        $buffer->feed("data: \xEF\xBB\xBFembedded\n\n");
        $buffer->finish();

        $this->assertSame("data: \xEF\xBB\xBFembedded", $buffer->pull(), 'A mid-stream BOM is frame content, not a marker.');
        $this->assertNull($buffer->pull());
    }

    public function testAnEmptyFirstChunkKeepsTheBomWindowOpen()
    {
        // An empty leading chunk (a transport no-op) must not close the
        // strip window before any byte has arrived.
        $buffer = new SseFrameBuffer();
        $buffer->feed('');
        $buffer->feed("\xEF\xBB\xBFdata: late\n\n");
        $buffer->finish();

        $this->assertSame('data: late', $buffer->pull());
        $this->assertNull($buffer->pull());
    }

    public function testStripStreamPrefixComposesTheCanonicalRule()
    {
        // GLM8 #2: ONE canonical prefix composition — [whitespace] BOM
        // [whitespace], stripped only when a BOM is present.
        $bom = "\xEF\xBB\xBF";

        // Whitespace around a BOM is stripped with it, on either side.
        $this->assertSame('data: a', SseFrameBuffer::strip_stream_prefix($bom . 'data: a'));
        $this->assertSame('data: a', SseFrameBuffer::strip_stream_prefix(' ' . $bom . 'data: a'));
        $this->assertSame('data: a', SseFrameBuffer::strip_stream_prefix($bom . ' ' . 'data: a'));
        $this->assertSame('data: a', SseFrameBuffer::strip_stream_prefix(" \r\n\t\x0B\0" . $bom . "\n\r data: a"));

        // No BOM: the body comes back UNCHANGED, leading whitespace and
        // all (the plain-ws prefix is the spec-correct dropped frame).
        $this->assertSame(' data: a', SseFrameBuffer::strip_stream_prefix(' data: a'));
        $this->assertSame("\n\ndata: a", SseFrameBuffer::strip_stream_prefix("\n\ndata: a"));

        // A BOM after the first field byte is content, not a prefix.
        $this->assertSame('data: ' . $bom . 'x', SseFrameBuffer::strip_stream_prefix('data: ' . $bom . 'x'));

        // Degenerate tails: a bare BOM prefixes nothing; a partial BOM
        // at end-of-body is not a prefix decision and stays put.
        $this->assertSame('', SseFrameBuffer::strip_stream_prefix(' ' . $bom));
        $this->assertSame(" \xEFdata: a", SseFrameBuffer::strip_stream_prefix(" \xEFdata: a"));
    }

    public function testWhitespaceAroundALeadingBomIsStrippedLikeTheBomAlone()
    {
        /*
         * GLM8 #2 (the finding's shapes): EventStreamSniff accepted
         * whitespace-then-BOM (and BOM-then-whitespace) bodies while this
         * buffer stripped the BOM at byte 0 only — the first frame then
         * matched no field and was silently dropped, corrupting the
         * aggregated content while the response reported success. The
         * prefix window now strips the full [ws] BOM [ws] run, so the
         * first frame comes out byte-identical to a BOM-less stream.
         */
        $bom = "\xEF\xBB\xBF";

        foreach (array('ws+BOM' => ' ' . $bom, 'BOM+ws' => $bom . ' ') as $label => $prefix) {
            $buffer = new SseFrameBuffer();
            $buffer->feed($prefix . "event: a\ndata: 1\n\ndata: 2\n\n");
            $buffer->finish();

            $this->assertSame("event: a\ndata: 1", $buffer->pull(), "[{$label}] The first frame must survive the prefix intact.");
            $this->assertSame('data: 2', $buffer->pull(), "[{$label}] Later frames are unaffected.");
            $this->assertNull($buffer->pull());
        }
    }

    public function testPlainLeadingWhitespaceStillDropsItsFirstFrame()
    {
        /*
         * GLM8 #2 guard: whitespace WITHOUT a BOM strips nothing — the
         * ws-prefixed first frame is the spec-correct DROPPED frame
         * (unknown field name), byte-identical to master. Only the
         * BOM-adjacent prefix changed.
         */
        $buffer = new SseFrameBuffer();
        $buffer->feed(' data: first' . "\n\n" . 'data: second' . "\n\n");
        $buffer->finish();

        $this->assertSame(' data: first', $buffer->pull(), 'A plain-ws first frame stays a frame.');
        $this->assertSame('data: second', $buffer->pull());
        $this->assertNull($buffer->pull());

        $aggregator = new SseAggregator();
        $aggregator->feed(' data: {"id":"c1","choices":[{"index":0,"delta":{"content":"gone"},"finish_reason":null}]}' . "\n\n");
        $aggregator->feed('data: {"id":"c1","choices":[{"index":0,"delta":{"content":" kept"},"finish_reason":"stop"}]}' . "\n\n");
        $aggregator->finish();

        $this->assertSame(' kept', $aggregator->aggregated()['choices'][0]['message']['content'], 'The ws-prefixed first delta is still dropped, master-identical.');
    }

    /**
     * @dataProvider providePrefixedBodiesAndChunkSplits
     */
    public function testFeedingTheRawBodyFramesExactlyLikeTheCanonicalPrefixStrip($body, array $chunks)
    {
        /*
         * GLM8 #2 (the alignment pin): whatever the sniff accepts, the
         * framing below must parse identically to the canonical
         * strip_stream_prefix() composition — for EVERY chunk split,
         * because the prefix window holds undecided whitespace and
         * split BOMs across chunk boundaries.
         */
        $expected = new SseFrameBuffer();
        $expected->feed(SseFrameBuffer::strip_stream_prefix($body));
        $expected->finish();
        $frames = array();
        while (null !== ($frame = $expected->pull())) {
            $frames[] = $frame;
        }

        $actual_buffer = new SseFrameBuffer();
        foreach ($chunks as $chunk) {
            $actual_buffer->feed($chunk);
        }
        $actual_buffer->finish();
        $actual = array();
        while (null !== ($frame = $actual_buffer->pull())) {
            $actual[] = $frame;
        }

        $this->assertSame($frames, $actual, 'Feeding the raw body yields the canonical-prefix frames.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function providePrefixedBodiesAndChunkSplits()
    {
        $bom = "\xEF\xBB\xBF";
        $bodies = array(
            'ws then BOM' => ' ' . $bom . "data: a\n\ndata: b\n\n",
            'BOM then ws' => $bom . " data: a\n\ndata: b\r\n\r\n",
            'ws BOM ws (newlines both sides)' => "\r\n " . $bom . "\t\ndata: a\n\ndata: b\n\n",
            'bare BOM' => $bom . "event: a\ndata: 1\n\n",
            'plain body' => "data: a\n\ndata: b\n\n",
            'plain ws only' => " \r\n\ndata: a\n\n",
            'ws+BOM with unterminated tail' => ' ' . $bom . 'data: unterminated',
        );

        $cases = array();
        foreach ($bodies as $body_label => $body) {
            $cases["{$body_label} (single shot)"] = array($body, array($body));
            $cases["{$body_label} (one byte at a time)"] = array($body, str_split($body));
        }

        return $cases;
    }

    public function testBothAggregatorsRideTheOneSharedFrameConsumptionBase()
    {
        /*
         * GLM8 #8 (extraction pin, the GLM4 #10/GLM7 #18 pattern): the
         * frame-consumption protocol — the SseFrameBuffer instance,
         * feed()/finish() driving it, and the pull loop — existed
         * byte-identical in BOTH aggregators with no shared owner.
         * Neither may hand-roll the plumbing again; it lives once, on
         * the shared AbstractSseAggregator base.
         */
        $base = (string) file_get_contents(__DIR__ . '/../../connectors/zai/src/Support/AbstractSseAggregator.php');
        $this->assertSame(
            1,
            preg_match_all('/new SseFrameBuffer\(/', $base),
            'The base owns the one frame-buffer construction.'
        );
        $this->assertSame(
            1,
            preg_match_all('/->pull\(\)/', $base),
            'The base owns the one frame pull loop.'
        );

        foreach (array(
            'legacy' => __DIR__ . '/../../connectors/zai/src/Support/SseAggregator.php',
            'anthropic' => __DIR__ . '/../../connectors/zai/src/Support/AnthropicSseAggregator.php',
        ) as $label => $path) {
            $source = (string) file_get_contents($path);

            $this->assertSame(
                0,
                preg_match_all('/new SseFrameBuffer\(/', $source),
                "[{$label}] The aggregator must not construct its own frame buffer."
            );
            $this->assertSame(
                0,
                preg_match_all('/->pull\(\)/', $source),
                "[{$label}] The aggregator must not hand-roll the pull loop."
            );
            $this->assertSame(
                0,
                preg_match_all('/frame_buffer/', $source),
                "[{$label}] The aggregator must not keep a private buffer handle."
            );
        }
    }
}
