<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Models\Post;

/**
 * Supplies SEO/meta fields for a post, decoupling the "seoMeta" post-template
 * capability from any concrete source. Unlike every other adapter contract in
 * this package, there is currently **no backing model at all** for this one:
 * `config('heisenberg.tables.seo_meta')` reserves the polymorphic `seo_meta`
 * table (docs/BLUEPRINT.md §2.3.11) but no `SeoMeta` model has been built
 * (planned M3), and `Post` carries no SEO columns of its own. The bundled
 * {@see \Heisenberg\Adapters\NullPostSeoMetaProvider} therefore has nothing
 * truthful to return and always answers empty — a host must implement this
 * contract itself (reading its own columns/table) to emit real meta tags
 * before that native model exists. (docs/post-template-schema.md "SEO/meta emission")
 */
interface PostSeoMetaProvider
{
    /**
     * Meta fields for a post in a locale. Any key may be absent or null;
     * callers must treat every field as optional.
     *
     * @return array{title?: ?string, description?: ?string, canonical?: ?string, ogImage?: ?string, robots?: ?string}
     */
    public function meta(Post $post, string $locale): array;
}
