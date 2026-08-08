<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * Incremental Server-Sent Events reader.
 *
 * Both provider families stream SSE, and both hand us arbitrary byte chunks that
 * split wherever the network felt like splitting them — a chunk boundary lands
 * mid-JSON far more often than test fixtures suggest. This buffers until a
 * complete `\n\n`-delimited event has arrived, so a decoded payload is never a
 * half-parsed line.
 *
 * Anthropic labels each frame with `event:` and OpenAI-compatible endpoints do
 * not, so both parts are returned and each adapter uses what it needs.
 */
class SseReader
{
    private string $buffer = '';

    /**
     * Feed a raw chunk; get back every event that completed with it.
     *
     * @return list<array{event: ?string, data: string}>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        // Normalise CRLF so a proxy that rewrites line endings doesn't hide the
        // blank-line frame delimiter from us.
        $this->buffer = str_replace("\r\n", "\n", $this->buffer);

        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $frame = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);

            $event = null;
            $data = [];
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    // Per the SSE spec multiple `data:` lines in one frame join
                    // with newlines; OpenAI-compatible endpoints rely on this
                    // for multi-line payloads.
                    $data[] = ltrim(substr($line, 5), ' ');
                }
            }

            if ($data !== []) {
                $events[] = ['event' => $event, 'data' => implode("\n", $data)];
            }
        }

        return $events;
    }

    /** Decode an event payload, or null when it isn't JSON (e.g. `[DONE]`). */
    public static function json(string $data): ?array
    {
        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : null;
    }
}
