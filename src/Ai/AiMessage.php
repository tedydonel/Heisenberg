<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * One turn in a conversation, provider-agnostic.
 *
 * Deliberately small: the two provider families disagree about almost
 * everything in their wire formats, so the shared shape carries only what both
 * can express and each adapter renders it into its own.
 *
 * The system prompt is NOT a message here — it lives on {@see AiRequest::$system}
 * precisely because Anthropic makes it a top-level field while
 * OpenAI-compatible endpoints make it a message.
 *
 * Three roles, because a real tool round-trip needs all three:
 *
 * - `user` / `assistant` — ordinary text turns.
 * - an **assistant turn carrying `toolCalls`** — the model asking for tools.
 * - a **`tool` turn** — one result, matched back by `toolUseId`.
 *
 * Feeding tool output back as plain user text would be simpler, and wrong: the
 * model would not recognise it as the answer to its own call, and would often
 * ask again.
 */
class AiMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    /**
     * @param list<array{id: string, name: string, arguments: array}> $toolCalls assistant turns only
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly ?string $toolUseId = null,
        public readonly ?string $name = null,
        public readonly bool $isError = false,
    ) {
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }

    /** The model's own turn, replayed so its tool calls can be answered. */
    public static function toolRequest(string $content, array $toolCalls): self
    {
        return new self(self::ROLE_ASSISTANT, $content, $toolCalls);
    }

    /** One tool's output, matched to the call by `$toolUseId`. */
    public static function toolResult(string $toolUseId, string $name, string $content, bool $isError = false): self
    {
        return new self(self::ROLE_TOOL, $content, [], $toolUseId, $name, $isError);
    }

    public function isToolResult(): bool
    {
        return $this->role === self::ROLE_TOOL;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /** @return array{role: string, content: string} */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
