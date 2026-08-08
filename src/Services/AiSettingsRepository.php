<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Ai\AiModel;
use Heisenberg\Ai\AiProviderProfile;
use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\McpServer;

/**
 * The AI panel's settings: the operator's **providers**, their **models**, which
 * model is in use, the enabled tool cards, and the outbound MCP server list.
 *
 * Stored as one JSON file (config: heisenberg.ai.settings_path, default
 * storage/app/heisenberg/ai.json), the same file-backed pattern as
 * {@see ThemeRepository} and {@see SavedThemeRepository}.
 *
 * The shape is `providers[]` + `models[]` rather than a fixed provider map,
 * because a provider is a vendor and not a wire format: OpenAI, xAI, Gemini,
 * OpenRouter, Groq and a local Ollama all speak the same `openai` format and
 * must be able to coexist, each with its own endpoint, credential and models.
 *
 * **No credential ever lands in this file.** A provider entry carries `key_env`
 * (the NAME of an environment variable) and nothing else; a key typed into the
 * modal goes to the {@see \Heisenberg\Contracts\AiCredentialStore}, which
 * encrypts it in a separate file. {@see self::assertNoCredentials()} rejects any
 * payload containing a key-shaped field outright, and runs on load as well as
 * save so a hand-edited file cannot smuggle one back through the API.
 */
class AiSettingsRepository
{
    /** Tool cards in the panel's Tools tab (live/panel-ai-tools.blade.php). */
    public const TOOLS = [
        'generate_title',
        'write_summary',
        'improve_writing',
        'fix_grammar',
        'change_tone',
        'generate_image',
        'translate',
        'seo_optimize',
    ];

    public const MAX_SERVERS = 20;

    public const MAX_PROVIDERS = 30;

    public const MAX_MODELS = 200;

    /**
     * Field names that must never appear in a settings payload, at any depth.
     * `key_env` and `auth_env` are allowed — they name a variable, they are not
     * one. Anything else key-shaped is either a mistake or an attempt to make
     * this file a secrets store.
     */
    private const CREDENTIAL_KEYS = [
        'token', 'api_key', 'apikey', 'key', 'secret', 'password',
        'authorization', 'auth_token', 'access_token', 'bearer', 'credential',
    ];

    public function __construct(private ?string $path = null)
    {
        $this->path = $path
            ?? config('heisenberg.ai.settings_path')
            ?? storage_path('app/heisenberg/ai.json');
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'providers'   => [],
            'models'      => [],
            'active_model' => null,
            'tools'       => self::TOOLS,
            'mcp_servers' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if (! is_file($this->path)) {
            return $this->defaults();
        }

        $raw = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($raw)) {
            return $this->defaults();
        }

        $checked = $this->validate($raw);

        return $checked['valid'] ? $checked['settings'] : $this->defaults();
    }

    /**
     * @param  array<string, mixed> $settings
     * @return array{saved: bool, errors: list<string>, settings: array<string, mixed>}
     */
    public function save(array $settings): array
    {
        $checked = $this->validate($settings);
        if (! $checked['valid']) {
            return ['saved' => false, 'errors' => $checked['errors'], 'settings' => $this->load()];
        }

        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $this->path,
            json_encode($checked['settings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );

        return ['saved' => true, 'errors' => [], 'settings' => $checked['settings']];
    }

    /** @return list<AiProviderProfile> */
    public function providers(): array
    {
        return array_map(
            static fn (array $row): AiProviderProfile => AiProviderProfile::fromArray($row),
            $this->load()['providers'],
        );
    }

    public function provider(string $id): ?AiProviderProfile
    {
        foreach ($this->providers() as $provider) {
            if ($provider->id === $id) {
                return $provider;
            }
        }

        return null;
    }

    /** @return list<AiModel> */
    public function models(): array
    {
        return array_map(
            static fn (array $row): AiModel => AiModel::fromArray($row),
            $this->load()['models'],
        );
    }

    /**
     * The model the assistant should use: the explicitly selected one when it is
     * still present and enabled, else the first enabled model, else none.
     * Falling back rather than failing means disabling the active model degrades
     * to "use another" instead of "assistant is broken".
     */
    public function activeModel(): ?AiModel
    {
        $settings = $this->load();
        $models = array_map(static fn (array $r): AiModel => AiModel::fromArray($r), $settings['models']);
        $enabled = array_values(array_filter($models, static fn (AiModel $m): bool => $m->enabled));

        foreach ($enabled as $model) {
            if ($model->key() === $settings['active_model']) {
                return $model;
            }
        }

        return $enabled[0] ?? null;
    }

    /** @return list<McpServer> */
    public function mcpServers(): array
    {
        return array_map(
            static fn (array $row): McpServer => McpServer::fromArray($row),
            $this->load()['mcp_servers'],
        );
    }

    /** @return list<string> */
    public function enabledTools(): array
    {
        return $this->load()['tools'];
    }

    /**
     * @param  array<string, mixed> $raw
     * @return array{valid: bool, errors: list<string>, settings: array<string, mixed>}
     */
    public function validate(array $raw): array
    {
        $errors = [];
        $defaults = $this->defaults();

        if (($leaked = $this->assertNoCredentials($raw)) !== null) {
            return [
                'valid'    => false,
                'errors'   => ["'{$leaked}' cannot be stored here — API keys go to the credential store, not the settings file"],
                'settings' => $defaults,
            ];
        }

        $providers = $this->validateProviders((array) ($raw['providers'] ?? []), $errors);
        $models = $this->validateModels((array) ($raw['models'] ?? []), array_column($providers, 'id'), $errors);

        $active = $raw['active_model'] ?? null;
        $active = is_string($active) && $active !== '' ? $active : null;
        if ($active !== null && ! in_array($active, array_map(
            static fn (array $m): string => $m['provider'] . ':' . $m['id'],
            $models,
        ), true)) {
            // Not an error: a model can legitimately be removed while selected.
            $active = null;
        }

        $tools = [];
        foreach ((array) ($raw['tools'] ?? $defaults['tools']) as $tool) {
            $tool = is_string($tool) ? $tool : '';
            if (! in_array($tool, self::TOOLS, true)) {
                $errors[] = "tools: unknown tool '{$tool}'";
                continue;
            }
            $tools[$tool] = true;
        }

        $servers = $this->validateServers((array) ($raw['mcp_servers'] ?? []), $errors);

        return [
            'valid'  => $errors === [],
            'errors' => $errors,
            'settings' => [
                'providers'    => $providers,
                'models'       => $models,
                'active_model' => $active,
                'tools'        => array_keys($tools),
                'mcp_servers'  => $servers,
            ],
        ];
    }

    /**
     * @param  array<int, mixed> $raw
     * @param  list<string>      $errors
     * @return list<array<string, mixed>>
     */
    private function validateProviders(array $raw, array &$errors): array
    {
        $formats = array_keys((array) config('heisenberg.ai.formats', []));
        $out = [];
        $seen = [];

        foreach ($raw as $i => $row) {
            if (! is_array($row)) {
                $errors[] = "providers.{$i}: must be an object";
                continue;
            }
            if (count($out) >= self::MAX_PROVIDERS) {
                $errors[] = 'providers: at most ' . self::MAX_PROVIDERS;
                break;
            }

            $id = (string) ($row['id'] ?? '');
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1) {
                $errors[] = "providers.{$i}: id must be kebab-case (a-z, 0-9, -)";
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = "providers.{$i}: duplicate id '{$id}'";
                continue;
            }

            $format = (string) ($row['format'] ?? '');
            if (! in_array($format, $formats, true)) {
                $errors[] = "providers.{$i} ('{$id}'): unknown API format '{$format}'";
                continue;
            }

            $url = trim((string) ($row['base_url'] ?? ''));
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            // http(s) only, for the same reason the MCP server list enforces it:
            // this URL is fetched server-side.
            if (! in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
                $errors[] = "providers.{$i} ('{$id}'): base_url must be an http(s) URL";
                continue;
            }

            $keyEnv = $row['key_env'] ?? null;
            if ($keyEnv !== null && $keyEnv !== '') {
                $keyEnv = (string) $keyEnv;
                // Shape-check the NAME. A pasted secret almost never looks like
                // an env var name, so it lands here rather than in the file.
                if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $keyEnv) !== 1) {
                    $errors[] = "providers.{$i} ('{$id}'): key_env must be an environment variable NAME (A-Z, 0-9, _), not a key";
                    continue;
                }
            } else {
                $keyEnv = null;
            }

            $icon = (string) ($row['icon'] ?? '');

            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'label' => mb_substr(trim((string) ($row['label'] ?? $id)), 0, 60) ?: $id,
                'format' => $format,
                'base_url' => rtrim($url, '/'),
                'key_env' => $keyEnv,
                'icon' => preg_match('/^[a-z0-9-]{1,40}$/', $icon) === 1 ? $icon : null,
                'built_in' => (bool) ($row['built_in'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed> $raw
     * @param  list<string>      $providerIds
     * @param  list<string>      $errors
     * @return list<array<string, mixed>>
     */
    private function validateModels(array $raw, array $providerIds, array &$errors): array
    {
        $out = [];
        $seen = [];

        foreach ($raw as $i => $row) {
            if (! is_array($row)) {
                $errors[] = "models.{$i}: must be an object";
                continue;
            }
            if (count($out) >= self::MAX_MODELS) {
                $errors[] = 'models: at most ' . self::MAX_MODELS;
                break;
            }

            $id = trim((string) ($row['id'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/\-]{0,99}$/', $id) !== 1) {
                $errors[] = "models.{$i}: '{$id}' is not a valid model id";
                continue;
            }

            $provider = (string) ($row['provider'] ?? '');
            if (! in_array($provider, $providerIds, true)) {
                $errors[] = "models.{$i} ('{$id}'): unknown provider '{$provider}'";
                continue;
            }

            // Same model id may exist under two providers — the pair is the key.
            $key = $provider . ':' . $id;
            if (isset($seen[$key])) {
                $errors[] = "models.{$i}: duplicate '{$key}'";
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'id' => $id,
                'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 60) ?: $id,
                'provider' => $provider,
                'enabled' => (bool) ($row['enabled'] ?? true),
                'effort' => AiRequest::normalizeEffort($row['effort'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed> $raw
     * @param  list<string>      $errors
     * @return list<array<string, mixed>>
     */
    private function validateServers(array $raw, array &$errors): array
    {
        $servers = [];
        $seen = [];

        foreach ($raw as $i => $row) {
            if (! is_array($row)) {
                $errors[] = "mcp_servers.{$i}: must be an object";
                continue;
            }
            if (count($servers) >= self::MAX_SERVERS) {
                $errors[] = 'mcp_servers: at most ' . self::MAX_SERVERS . ' servers';
                break;
            }

            $id = (string) ($row['id'] ?? '');
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1) {
                $errors[] = "mcp_servers.{$i}: id must be kebab-case (a-z, 0-9, -)";
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = "mcp_servers.{$i}: duplicate id '{$id}'";
                continue;
            }

            $url = trim((string) ($row['url'] ?? ''));
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
                $errors[] = "mcp_servers.{$i} ('{$id}'): url must be an http(s) URL";
                continue;
            }

            $authEnv = $row['auth_env'] ?? null;
            if ($authEnv !== null && $authEnv !== '') {
                $authEnv = (string) $authEnv;
                if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $authEnv) !== 1) {
                    $errors[] = "mcp_servers.{$i} ('{$id}'): auth_env must be an environment variable NAME (A-Z, 0-9, _), not a token";
                    continue;
                }
            } else {
                $authEnv = null;
            }

            $allowed = [];
            foreach ((array) ($row['allowed_tools'] ?? []) as $tool) {
                $tool = is_string($tool) ? trim($tool) : '';
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $tool) === 1) {
                    $allowed[$tool] = true;
                }
            }

            $seen[$id] = true;
            $servers[] = [
                'id'            => $id,
                'label'         => mb_substr(trim((string) ($row['label'] ?? $id)), 0, 60),
                'url'           => $url,
                'auth_env'      => $authEnv,
                'enabled'       => (bool) ($row['enabled'] ?? false),
                'allowed_tools' => array_keys($allowed),
            ];
        }

        return $servers;
    }

    /**
     * Depth-first scan for credential-shaped field names.
     *
     * @param array<mixed> $data
     */
    private function assertNoCredentials(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::CREDENTIAL_KEYS, true)) {
                return $key;
            }
            if (is_array($value) && ($found = $this->assertNoCredentials($value)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
