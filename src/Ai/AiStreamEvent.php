<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * One event on the normalised stream.
 *
 * Anthropic and OpenAI-compatible endpoints ship completely different SSE
 * payloads. Both adapters translate into these four types so the editor's panel
 * JS never learns which provider is active — switching provider changes nothing
 * client-side, which is the whole point of the normalisation.
 */
class AiStreamEvent
{
    public const TEXT_DELTA = 'text_delta';
    public const TOOL_USE = 'tool_use';
    public const DONE = 'done';
    public const ERROR = 'error';

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
