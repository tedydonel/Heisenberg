<?php

declare(strict_types=1);

namespace Heisenberg\Ai;

/**
 * One outbound MCP server the editor may borrow tools from.
 *
 * `authEnv` is the NAME of an environment variable, never a token. That is the
 * same rule the provider config follows: no credential this package handles is
 * ever written to disk or returned by an endpoint, so a settings file that
 * leaks tells an attacker which env vars exist and nothing else.
 *
 * `allowedTools` is an allow-list checked BEFORE execution, not a hint to the
 * model. A server that advertises a tool outside the list simply cannot have it
 * called, however convincingly its description asks.
 */
class McpServer
{
    /** @param list<string> $allowedTools */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $authEnv = null,
        public readonly bool $enabled = false,
        public readonly array $allowedTools = [],
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: (string) ($raw['id'] ?? ''),
            label: (string) ($raw['label'] ?? ''),
            url: (string) ($raw['url'] ?? ''),
            authEnv: ($raw['auth_env'] ?? null) !== null ? (string) $raw['auth_env'] : null,
            enabled: (bool) ($raw['enabled'] ?? false),
            allowedTools: array_values(array_map('strval', (array) ($raw['allowed_tools'] ?? []))),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'label'         => $this->label,
            'url'           => $this->url,
            'auth_env'      => $this->authEnv,
            'enabled'       => $this->enabled,
            'allowed_tools' => $this->allowedTools,
        ];
    }

    /**
     * The bearer token for this server, read from the environment at call time.
     * Null when no env var is named or the named one is unset — the caller must
     * treat that as "not configured" and send no Authorization header at all,
     * rather than an empty one (which some servers accept as anonymous and
     * others reject confusingly).
     */
    public function token(): ?string
    {
        if ($this->authEnv === null || $this->authEnv === '') {
            return null;
        }

        $value = env($this->authEnv);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && ($this->authEnv === null || $this->token() !== null);
    }

    /** Is `$tool` callable on this server? Allow-list, checked before execution. */
    public function allows(string $tool): bool
    {
        return $this->enabled && in_array($tool, $this->allowedTools, true);
    }
}
