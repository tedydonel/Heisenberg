<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * One model, belonging to one provider.
 *
 * Effort lives here rather than as a single global setting because it is a
 * per-model property in practice: a small local model has no use for `xhigh`,
 * and a reasoning model is wasted at `low`. Whichever model is in use brings its
 * own effort with it.
 *
 * `enabled` is the toggle in the Models tab — a model that is off stays in the
 * list (so it can be turned back on without re-typing its id) but is never
 * offered to the assistant.
 */
class AiModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $provider,
        public readonly bool $enabled = true,
        public readonly string $effort = AiRequest::DEFAULT_EFFORT,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $id = (string) ($raw['id'] ?? '');

        return new self(
            id: $id,
            label: (string) ($raw['label'] ?? '') !== '' ? (string) $raw['label'] : $id,
            provider: (string) ($raw['provider'] ?? ''),
            enabled: (bool) ($raw['enabled'] ?? true),
            effort: AiRequest::normalizeEffort($raw['effort'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'provider' => $this->provider,
            'enabled' => $this->enabled,
            'effort' => $this->effort,
        ];
    }

    /** Stable identity across providers — two vendors may serve the same model id. */
    public function key(): string
    {
        return $this->provider . ':' . $this->id;
    }
}
