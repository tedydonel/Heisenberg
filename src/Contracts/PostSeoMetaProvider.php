<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Models\Post;

/**
 * Supplies SEO/meta fields for a post, decoupling the "seoMeta" post-template
 * capability from any concrete source. (2026-08-11, docs/seo-system.md Wave S1) A native
 * backing model now exists — {@see \Heisenberg\Models\SeoMeta}, the polymorphic `seo_meta`
 * table (docs/BLUEPRINT.md §2.3.11/§2.4) — and {@see \Heisenberg\Adapters\NativeSeoMetaProvider}
 * reading it is the DEFAULT binding at `heisenberg.post_template.seo_meta_provider`, same
 * posture as `comments_provider`/`NativeCommentProvider`. Bind
 * {@see \Heisenberg\Adapters\NullPostSeoMetaProvider} at that key to opt out entirely (always
 * empty), or your own class to integrate an external SEO system (a different table, a hosted
 * service) — the capability's shape never changes, only which class answers it.
 * (docs/post-template-schema.md "SEO/meta emission")
 */
interface PostSeoMetaProvider
{
    /**
     * Meta fields for a post in a locale. Any key may be absent or null; callers must treat
     * every field as optional. The return shape grows additively over time — `title`,
     * `description`, `canonical`, `ogImage`, `robots` are the original four-plus-one; `ogTitle`,
     * `ogDescription`, and `jsonLd` (schema.org JSON-LD, {@see \Heisenberg\Models\SeoMeta::getJsonLd()})
     * were added in Wave S1 as optional extras a caller may ignore.
     *
     * @return array{title?: ?string, description?: ?string, canonical?: ?string, ogImage?: ?string, robots?: ?string, ogTitle?: ?string, ogDescription?: ?string, jsonLd?: array<string, mixed>}
     */
    public function meta(Post $post, string $locale): array;
}
