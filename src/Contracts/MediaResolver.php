<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

/**
 * Resolves an image URL to a renderable payload, decoupling the renderer from
 * the host's media library (GTC's `App\Models\PublicFile`). (Blueprint §4.11, §13.2)
 */
interface MediaResolver
{
    /**
     * Resolve a URL for a render context (e.g. "hero", "inline", "gallery").
     *
     * @return array{url: string, srcset: ?string, sizes: ?string}
     */
    public function resolve(string $url, string $context): array;

    /**
     * Optional: paginated media for the editor picker. The default adapter
     * returns an empty iterable.
     *
     * @return iterable<mixed>
     */
    public function browse(int $page = 1, int $perPage = 24): iterable;
}
