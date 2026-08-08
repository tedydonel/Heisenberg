<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * One configured provider — OpenAI, Anthropic, xAI, Google Gemini, OpenRouter,
 * Groq, MiniMax, a local Ollama, anything.
 *
 * **A provider is not a wire format.** `format` names the API shape it speaks
 * (`anthropic` or `openai`); almost every vendor on the market speaks the
 * OpenAI `/chat/completions` shape, so the format is a property of the provider
 * rather than an identity. Getting this backwards is what produced the earlier
 * design in which "Anthropic" and "OpenAI-compatible" *were* the two providers
 * and there was nowhere to put a third vendor.
 *
 * A provider owns its endpoint and its credential. Models belong to providers
 * ({@see AiModel}), which is what lets one install mix Claude, GPT, Gemini and a
 * local Llama in the same picker.
 *
 * The API key is never a property of this object. `keyEnv` names an environment
 * variable; a key entered through the UI lives in the credential store keyed by
 * `id`. Both are resolved at call time and neither is ever serialized here.
 */
class AiProviderProfile
{
    public const FORMAT_ANTHROPIC = 'anthropic';
    public const FORMAT_OPENAI = 'openai';

    public const FORMATS = [self::FORMAT_ANTHROPIC, self::FORMAT_OPENAI];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $format,
        public readonly string $baseUrl,
        public readonly ?string $keyEnv = null,
        public readonly ?string $icon = null,
        public readonly bool $builtIn = false,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $format = (string) ($raw['format'] ?? self::FORMAT_OPENAI);

        return new self(
            id: (string) ($raw['id'] ?? ''),
            label: (string) ($raw['label'] ?? $raw['id'] ?? ''),
            format: in_array($format, self::FORMATS, true) ? $format : self::FORMAT_OPENAI,
            baseUrl: rtrim((string) ($raw['base_url'] ?? ''), '/'),
            keyEnv: ($raw['key_env'] ?? null) !== null && $raw['key_env'] !== '' ? (string) $raw['key_env'] : null,
            icon: ($raw['icon'] ?? null) !== null && $raw['icon'] !== '' ? (string) $raw['icon'] : null,
            builtIn: (bool) ($raw['built_in'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'format' => $this->format,
            'base_url' => $this->baseUrl,
            'key_env' => $this->keyEnv,
            'icon' => $this->icon,
            'built_in' => $this->builtIn,
        ];
    }

    /**
     * A local endpoint needs no credential — demanding a fake key to reach
     * 127.0.0.1 would be theatre.
     */
    public function isLocal(): bool
    {
        $host = (string) parse_url($this->baseUrl, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.local');
    }
}
