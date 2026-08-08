<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiResponse;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Contracts\AiProvider;

/**
 * The bundled default AI provider: it does nothing, on purpose.
 *
 * Same null-object posture as NullVirusScanner / NullMediaResolver / NullAuditSink.
 * A fresh install has no API key and has agreed to nothing, so the shipped
 * default must never make a network call, never throw, and never leave the AI
 * panel looking broken. Sending a prompt with this provider active returns a
 * plain "no provider configured" message in the response card — the same
 * rendering path a real error takes, so there is no separate empty state to
 * maintain.
 *
 * A host opts in by setting HEISENBERG_AI_PROVIDER and the matching key env var.
 */
class NullAiProvider implements AiProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function id(): string
    {
        return (string) ($this->config['id'] ?? 'null');
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? 'None');
    }

    /**
     * Always false — not "unconfigured pending a key", but "there is nothing to
     * configure". This is what the registry falls back to when no model is in
     * use, so the panel is inert rather than broken.
     */
    public function isConfigured(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function discoverModels(): array
    {
        return [];
    }

    public function supportsTools(): bool
    {
        return false;
    }

    public function complete(AiRequest $request): AiResponse
    {
        return AiResponse::error($this->message());
    }

    /** @return iterable<AiStreamEvent> */
    public function stream(AiRequest $request): iterable
    {
        yield AiStreamEvent::error($this->message());
    }

    private function message(): string
    {
        return __('heisenberg::editor.ai.no_provider');
    }
}
