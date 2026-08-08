<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * A provider-agnostic completion request.
 *
 * What is NOT on this object is as deliberate as what is: there is no
 * `temperature`, `top_p`, `top_k` or `budget_tokens` field, and none may be
 * added. Current Anthropic models reject all four with a 400 — thinking depth
 * and spend are controlled by {@see self::$effort} instead, which the Anthropic
 * adapter sends as `output_config.effort` and the OpenAI-compatible adapter
 * maps onto whatever its endpoint understands (or drops).
 *
 * `tools` holds MCP-derived tool definitions in a neutral shape
 * (`{name, description, input_schema}`); each adapter renders them into its own
 * wire format. Empty in Phase 1 — the tool loop lands with the MCP client.
 */
class AiRequest
{
    /** Accepted `effort` levels, cheapest first. */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public const DEFAULT_EFFORT = 'high';

    /**
     * @param list<AiMessage>                                                          $messages
     * @param list<array{name: string, description?: string, input_schema?: array}>    $tools
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $system = null,
        public readonly ?string $model = null,
        public readonly string $effort = self::DEFAULT_EFFORT,
        public readonly int $maxTokens = 16000,
        public readonly array $tools = [],
    ) {
    }

    /**
     * Normalise an effort string, falling back to the default rather than
     * throwing — an unknown level from stored settings must degrade, not 500.
     */
    public static function normalizeEffort(mixed $effort): string
    {
        $effort = is_string($effort) ? strtolower(trim($effort)) : '';

        return in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
    }

    public function withModel(?string $model): self
    {
        return new self($this->messages, $this->system, $model, $this->effort, $this->maxTokens, $this->tools);
    }

    /** @param list<array{name: string, description?: string, input_schema?: array}> $tools */
    public function withTools(array $tools): self
    {
        return new self($this->messages, $this->system, $this->model, $this->effort, $this->maxTokens, $tools);
    }

    /** @param list<AiMessage> $messages */
    public function withMessages(array $messages): self
    {
        return new self($messages, $this->system, $this->model, $this->effort, $this->maxTokens, $this->tools);
    }

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }
}
