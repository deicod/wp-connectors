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
}
