<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Adapters\NullAiProvider;
use Heisenberg\Ai\AiModel;
use Heisenberg\Ai\AiProviderProfile;
use Heisenberg\Contracts\AiCredentialStore;
use Heisenberg\Contracts\AiProvider;

/**
 * Turns a configured {@see AiProviderProfile} into a live {@see AiProvider}
 * adapter, by looking up the adapter class for the profile's **format**.
 *
 * That indirection is the whole point: the two adapters are wire-format
 * implementations, and any number of vendors can share one. Adding xAI or
 * Gemini is a provider row, not a new class.
 *
 * Nothing here ever returns key material. `describe()` reports `has_key` and
 * where the key came from, and the settings API returns only that.
 */
class AiProviderRegistry
{
    public function __construct(
        private AiSettingsRepository $settings,
        private AiCredentialStore $credentials,
    ) {
    }

    /**
     * Every configured provider, described for the settings modal — including
     * the models it owns and whether it is usable right now.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        $models = $this->settings->models();
        $rows = [];

        foreach ($this->settings->providers() as $profile) {
            $own = array_values(array_filter(
                $models,
                static fn (AiModel $m): bool => $m->provider === $profile->id,
            ));

            $rows[] = [
                'id' => $profile->id,
                'label' => $profile->label,
                'format' => $profile->format,
                'base_url' => $profile->baseUrl,
                'key_env' => $profile->keyEnv,
                'icon' => $profile->icon,
                // Booleans only. There is no accessor here that returns a key,
                // and none may be added — this array is serialized to the client.
                'has_key' => $this->credentials->has($profile->id, $profile->keyEnv),
                'key_from_env' => $this->credentials->isFromEnvironment($profile->id, $profile->keyEnv),
                'needs_key' => ! $profile->isLocal(),
                'connected' => $this->isConnected($profile),
                'models' => array_map(static fn (AiModel $m): array => $m->toArray(), $own),
            ];
        }

        return $rows;
    }

    /** Presets an operator can add in one click, minus the ones already added. */
    public function availablePresets(): array
    {
        $existing = array_map(static fn (AiProviderProfile $p): string => $p->id, $this->settings->providers());

        return array_values(array_filter(
            (array) config('heisenberg.ai.provider_presets', []),
            static fn (array $preset): bool => ! in_array($preset['id'] ?? '', $existing, true),
        ));
    }

    /** @return array<string, string> format id → human label */
    public function formats(): array
    {
        $out = [];
        foreach ((array) config('heisenberg.ai.formats', []) as $id => $format) {
            $out[(string) $id] = (string) ($format['label'] ?? $id);
        }

        return $out;
    }

    /**
     * Build the adapter for one provider, or null when its format has no
     * adapter class present. Never throws — a provider whose adapter was
     * removed from a host's install degrades to "not connected".
     *
     * Deliberately NOT memoised. An adapter is a few array entries, and caching
     * one means a key saved earlier in the same request keeps answering with the
     * adapter that was built before it existed — the settings change, the cached
     * instance does not.
     */
    public function make(string $providerId): ?AiProvider
    {
        $profile = $this->settings->provider($providerId);
        if ($profile === null) {
            return null;
        }

        $adapter = (string) config("heisenberg.ai.formats.{$profile->format}.adapter", '');
        if ($adapter === '' || ! class_exists($adapter) || ! is_subclass_of($adapter, AiProvider::class)) {
            return null;
        }

        /** @var AiProvider $instance */
        $instance = app()->make($adapter, ['config' => [
            'id' => $profile->id,
            'label' => $profile->label,
            'base_url' => $profile->baseUrl,
            'key' => $this->credentials->get($profile->id, $profile->keyEnv),
            'local' => $profile->isLocal(),
        ]]);

        return $instance;
    }

    /**
     * The adapter for whichever model is in use, or the null object.
     *
     * Falling back rather than throwing means a half-configured install shows an
     * inert panel instead of 500ing on every keystroke.
     */
    public function active(): AiProvider
    {
        $model = $this->settings->activeModel();

        return ($model !== null ? $this->make($model->provider) : null) ?? new NullAiProvider();
    }

    private function isConnected(AiProviderProfile $profile): bool
    {
        if ($this->make($profile->id) === null) {
            return false;
        }

        return $profile->isLocal() || $this->credentials->has($profile->id, $profile->keyEnv);
    }
}
