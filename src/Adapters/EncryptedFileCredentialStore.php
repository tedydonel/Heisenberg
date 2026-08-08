<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\AiCredentialStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * API keys encrypted at rest with the application key, in their own file —
 * deliberately NOT the settings JSON, so a settings file that is copied,
 * committed or shared carries no secrets.
 *
 * Resolution order is **environment first**. That keeps the original,
 * safer-by-default posture intact: an operator who sets
 * `HEISENBERG_AI_OPENAI_KEY` gets exactly the behaviour they had before, and a
 * key pasted into the UI cannot silently shadow one deployed through config
 * management. The UI reports which source won.
 *
 * Two limits worth stating plainly rather than implying otherwise:
 *
 * - Encryption is only as good as `APP_KEY`. Anyone who can read both this file
 *   and the app key can read the keys; this protects against a leaked backup or
 *   a stray `cat`, not against a compromised host.
 * - A host with a real secrets manager should bind its own
 *   {@see AiCredentialStore} instead.
 */
class EncryptedFileCredentialStore implements AiCredentialStore
{
    public function __construct(private ?string $path = null)
    {
        $this->path = $path
            ?? config('heisenberg.ai.credentials_path')
            ?? storage_path('app/heisenberg/ai-credentials.json');
    }

    public function has(string $providerId, ?string $envVar = null): bool
    {
        return $this->get($providerId, $envVar) !== null;
    }

    public function get(string $providerId, ?string $envVar = null): ?string
    {
        if ($envVar !== null && $envVar !== '') {
            $value = env($envVar);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $stored = $this->all()[$providerId] ?? null;
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (DecryptException) {
            // A rotated APP_KEY makes every stored key undecryptable. Report it
            // as absent rather than throwing on every request — the operator
            // re-enters the key, which is the only real remedy anyway.
            return null;
        }

        return $plain !== '' ? $plain : null;
    }

    public function isFromEnvironment(string $providerId, ?string $envVar = null): bool
    {
        if ($envVar === null || $envVar === '') {
            return false;
        }

        $value = env($envVar);

        return is_string($value) && $value !== '';
    }

    public function put(string $providerId, string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            $this->forget($providerId);

            return;
        }

        $all = $this->all();
        $all[$providerId] = Crypt::encryptString($key);
        $this->persist($all);
    }

    public function forget(string $providerId): void
    {
        $all = $this->all();
        unset($all[$providerId]);
        $this->persist($all);
    }

    /** @return array<string, string> */
    private function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($this->path), true);

        return is_array($raw) ? array_filter($raw, 'is_string') : [];
    }

    /** @param array<string, string> $all */
    private function persist(array $all): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->path, json_encode($all, JSON_PRETTY_PRINT), LOCK_EX);
        // Best-effort: keep the file out of a group-readable umask. Ignored on
        // Windows, where chmod is a no-op.
        @chmod($this->path, 0600);
    }
}
