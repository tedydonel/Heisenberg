<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\MediaResolver;
use Heisenberg\Models\PublicFile;

/**
 * MediaResolver backed by the public media library (PublicFile). Resolves a
 * `/uploads/...` URL back to its record via the reverse lookup (§7 forUrl())
 * and returns the real url/srcset/sizes payload instead of NullMediaResolver's
 * scheme-checked passthrough. Not bound by default (see config('heisenberg.
 * media_resolver')) — a host opts in once it wants the renderer to serve
 * responsive images from the library.
 */
class PublicFileMediaResolver implements MediaResolver
{
    public function resolve(string $url, string $context): array
    {
        $class = $this->modelClass();
        $file = $class::forUrl($url);

        if ($file === null) {
            return ['url' => $url, 'srcset' => null, 'sizes' => null];
        }

        return $file->imagePayload($context);
    }

    /**
     * @return iterable<PublicFile>
     */
    public function browse(int $page = 1, int $perPage = 24): iterable
    {
        $class = $this->modelClass();

        return $class::query()
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get();
    }

    /** @return class-string<PublicFile> */
    private function modelClass(): string
    {
        return (string) config('heisenberg.models.public_file', PublicFile::class);
    }
}
