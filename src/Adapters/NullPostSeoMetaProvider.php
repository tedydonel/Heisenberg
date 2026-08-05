<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\PostSeoMetaProvider;
use Heisenberg\Models\Post;

/**
 * Default SEO/meta adapter: always empty. Unlike this package's other null
 * adapters, there is genuinely nothing truthful this could return — Heisenberg
 * has no `SeoMeta` model yet (see the contract's docblock). A host implements
 * this itself and binds it at `config('heisenberg.post_template.seo_meta_provider')`.
 */
class NullPostSeoMetaProvider implements PostSeoMetaProvider
{
    public function meta(Post $post, string $locale): array
    {
        return [];
    }
}
