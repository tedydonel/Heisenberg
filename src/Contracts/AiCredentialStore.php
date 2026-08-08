<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

/**
 * Where a provider's API key lives.
 *
 * **Write-only by contract.** There is `put()`, `forget()` and `has()`, and a
 * `get()` that only the provider adapters call — but nothing in the HTTP layer
 * may expose it, and the settings API returns `has_key` booleans and nothing
 * else. A store that could be read back through the API would turn a settings
 * endpoint into a credential-exfiltration endpoint.
 *
 * The bundled implementation encrypts at rest with the app key and keeps an
 * environment variable ahead of it, so an operator who prefers `.env` (the
 * safer posture, and the original default) keeps working unchanged while an
 * operator who wants to paste a key into the UI can.
 *
 * A host that runs a real secrets manager binds its own implementation via
 * `config('heisenberg.ai.credential_store')`.
 */
interface AiCredentialStore
{
    /** Is a key available for this provider, from any source? */
    public function has(string $providerId, ?string $envVar = null): bool;

    /**
     * The key, or null. Called by provider adapters at request time; never by
     * anything that renders or returns a response body.
     */
    public function get(string $providerId, ?string $envVar = null): ?string;

    /** Store (or replace) a key. */
    public function put(string $providerId, string $key): void;

    public function forget(string $providerId): void;

    /**
     * Is the available key coming from the environment rather than this store?
     * The UI uses it to explain why a stored key is being ignored.
     */
    public function isFromEnvironment(string $providerId, ?string $envVar = null): bool;
}
