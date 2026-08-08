<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Ai\SseReader;
use Heisenberg\Tests\TestCase;

/**
 * The chunk-boundary cases. A real stream splits wherever the network felt like
 * splitting it, so "a chunk is one frame" is the assumption that quietly works
 * in fixtures and breaks in production.
 */
class SseReaderTest extends TestCase
{
    public function test_a_complete_frame_is_returned_immediately(): void
    {
        $events = (new SseReader())->push("data: {\"a\":1}\n\n");

        $this->assertCount(1, $events);
        $this->assertSame(['a' => 1], SseReader::json($events[0]['data']));
    }

    public function test_a_frame_split_across_chunks_is_buffered_until_complete(): void
    {
        $reader = new SseReader();

        $this->assertSame([], $reader->push('data: {"text":"hel'));
        $this->assertSame([], $reader->push('lo"}'));

        $events = $reader->push("\n\n");
        $this->assertCount(1, $events);
        $this->assertSame(['text' => 'hello'], SseReader::json($events[0]['data']));
    }

    public function test_several_frames_in_one_chunk_all_come_back(): void
    {
        $events = (new SseReader())->push("data: 1\n\ndata: 2\n\ndata: 3\n\n");

        $this->assertSame(['1', '2', '3'], array_column($events, 'data'));
    }

    public function test_the_event_name_is_captured_when_present(): void
    {
        $events = (new SseReader())->push("event: content_block_delta\ndata: {}\n\n");

        $this->assertSame('content_block_delta', $events[0]['event']);
    }

    public function test_a_frame_with_no_event_line_still_parses(): void
    {
        $events = (new SseReader())->push("data: {}\n\n");

        $this->assertNull($events[0]['event']);
    }

    /** A proxy that rewrites line endings must not hide the frame delimiter. */
    public function test_crlf_line_endings_are_normalised(): void
    {
        $events = (new SseReader())->push("data: {\"a\":1}\r\n\r\n");

        $this->assertCount(1, $events);
        $this->assertSame(['a' => 1], SseReader::json($events[0]['data']));
    }

    /** Per the SSE spec, repeated data lines in one frame join with newlines. */
    public function test_multiple_data_lines_in_one_frame_are_joined(): void
    {
        $events = (new SseReader())->push("data: line one\ndata: line two\n\n");

        $this->assertSame("line one\nline two", $events[0]['data']);
    }

    public function test_a_non_json_payload_decodes_to_null_rather_than_throwing(): void
    {
        $this->assertNull(SseReader::json('[DONE]'));
    }

    public function test_a_trailing_partial_frame_is_never_emitted(): void
    {
        $reader = new SseReader();

        $events = $reader->push("data: {\"a\":1}\n\ndata: {\"b\":2");

        $this->assertCount(1, $events);
        $this->assertSame(['a' => 1], SseReader::json($events[0]['data']));
    }
}
