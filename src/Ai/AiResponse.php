<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * A completed, provider-agnostic response.
 *
 * Errors are a VALUE here, not an exception. Three of the failure modes this
 * surface has to handle are ordinary outcomes rather than faults — no provider
 * configured, the model declined (Anthropic returns HTTP 200 with
 * `stop_reason: "refusal"`), and the endpoint was unreachable — and the AI panel
 * has to render all three the same way: as a message in the response card. An
 * adapter that threw for those would just push try/catch into every caller.
 */
class AiResponse
{
    /**
     * @param list<array{id: string, name: string, arguments: array}> $toolCalls
     * @param array<string, int|string>                               $usage
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
        public readonly ?string $stopReason = null,
        public readonly ?string $model = null,
        public readonly array $usage = [],
        public readonly ?string $error = null,
    ) {
    }

    public static function error(string $message): self
    {
        return new self(error: $message);
    }

    /**
     * The model declined the request. Anthropic reports this as a successful
     * HTTP 200 carrying `stop_reason: "refusal"` — never as an error status —
     * so adapters must check the stop reason BEFORE reading content, and land
     * here rather than surfacing an empty response.
     */
    public static function refusal(string $message, ?string $model = null): self
    {
        return new self(stopReason: 'refusal', model: $model, error: $message);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text'       => $this->text,
            'toolCalls'  => $this->toolCalls,
            'stopReason' => $this->stopReason,
            'model'      => $this->model,
            'usage'      => $this->usage,
            'error'      => $this->error,
        ];
    }
}
