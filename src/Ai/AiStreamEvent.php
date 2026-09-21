<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

use Heisenberg\Services\AiToolRunner;

/**
 * One event on the normalised stream.
 *
 * Anthropic and OpenAI-compatible endpoints ship completely different SSE
 * payloads. Both adapters translate into these five types so the editor's panel
 * JS never learns which provider is active — switching provider changes nothing
 * client-side, which is the whole point of the normalisation.
 */
class AiStreamEvent
{
    public const TEXT_DELTA = 'text_delta';

    public const TOOL_USE = 'tool_use';

    public const DONE = 'done';

    public const ERROR = 'error';

    /**
     * A slice of the model's reasoning — Anthropic's `thinking_delta` blocks,
     * or `reasoning_content` / `reasoning` on an OpenAI-compatible chunk.
     * Carried on the same `text` field as {@see self::TEXT_DELTA} so the panel
     * reuses one accumulation code path, but tagged with its own `type` so it
     * can be routed to a separate (collapsible) area rather than the answer
     * body. Deliberately never folded into the transcript replayed to the
     * model or into saved content — see {@see AiToolRunner}.
     */
    public const REASONING = 'reasoning_delta';

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly string $text = '',
        public readonly array $data = [],
    ) {
    }

    public static function textDelta(string $text): self
    {
        return new self(self::TEXT_DELTA, $text);
    }

    /** A slice of the model's reasoning — see {@see self::REASONING}. */
    public static function reasoningDelta(string $text): self
    {
        return new self(self::REASONING, $text);
    }

    /** @param array<string, mixed> $call */
    public static function toolUse(array $call): self
    {
        return new self(self::TOOL_USE, '', $call);
    }

    /** @param array<string, mixed> $meta */
    public static function done(array $meta = []): self
    {
        return new self(self::DONE, '', $meta);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, $message);
    }

    /** The SSE `data:` payload for this event. */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'text' => $this->text,
            'data' => $this->data,
        ], static fn ($value) => $value !== '' && $value !== []);
    }
}
