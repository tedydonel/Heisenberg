<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiResponse;

/**
 * One **API format** — not one vendor.
 *
 * There are two implementations (Anthropic Messages, OpenAI Chat Completions)
 * and any number of providers speaking them, because nearly every vendor on the
 * market exposes the OpenAI shape. {@see \Heisenberg\Services\AiProviderRegistry}
 * picks the adapter from a provider's `format` and constructs it with that
 * provider's endpoint and resolved credential.
 *
 * Adapters receive an `array $config` constructor parameter:
 * `{id, label, base_url, key, local}`. The key is already resolved (environment
 * first, then the credential store) — an adapter never reads config or env
 * itself, so there is exactly one place that decides where a credential comes
 * from.
 *
 * Two rules every adapter must honour:
 *
 * 1. **Never surface a credential.** Not in a response, not in an error message.
 *    An upstream 401 body can quote the key it rejected, so error text is ours,
 *    never theirs.
 * 2. **Never throw for an ordinary outcome.** A missing key, a refusal, an
 *    unreachable endpoint and a timeout are all {@see AiResponse::error()} —
 *    the panel renders them in the response card.
 */
interface AiProvider
{
    /** The provider id this adapter was built for. */
    public function id(): string;

    public function label(): string;

    /** Is this provider usable right now — endpoint set, and a key if one is needed? */
    public function isConfigured(): bool;

    /**
     * Ask the endpoint what it serves, via its own `/models` route.
     *
     * Models are DISCOVERED, not hardcoded: a vendor's catalogue changes without
     * asking us, and a shipped list is wrong the week after it ships. A provider
     * that cannot answer returns an empty list and the operator adds ids by hand.
     *
     * @return list<string> model ids
     */
    public function discoverModels(): array;

    /** Can this provider run the MCP tool loop? */
    public function supportsTools(): bool;

    public function complete(AiRequest $request): AiResponse;

    /**
     * The same completion as a normalised event stream.
     *
     * @return iterable<\Heisenberg\Ai\AiStreamEvent>
     */
    public function stream(AiRequest $request): iterable;
}
