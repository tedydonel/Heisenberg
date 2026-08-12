<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\PostSeoMetaProvider;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;

/**
 * Default SEO/meta adapter (2026-08-11, docs/seo-system.md Wave S1), reading
 * {@see \Heisenberg\Models\SeoMeta} (`config('heisenberg.tables.seo_meta')`, polymorphic).
 * Bound at `heisenberg.post_template.seo_meta_provider` in place of
 * {@see NullPostSeoMetaProvider} — see that class and `PostSeoMetaProvider`'s docblock for the
 * "bind your own to opt out" story.
 *
 * Looks the row up by `able_type`/`able_id` directly (`SeoMeta::query()->where(...)`) rather
 * than through a `Post::seoMeta()` relation — that `MorphOne` doesn't exist yet (Wave S2a,
 * parallel-wave discipline: this adapter must not touch `Post`). `able_type` is resolved via
 * `$post->getMorphClass()`, the same value Eloquent itself would write into that column on
 * save (respecting a host's morph map, if one is ever registered — today there is none, so
 * this is the post's FQCN).
 */
class NativeSeoMetaProvider implements PostSeoMetaProvider
{
    public function meta(Post $post, string $locale): array
    {
        $row = SeoMeta::query()
            ->where('able_type', $post->getMorphClass())
            ->where('able_id', $post->getKey())
            ->first();

        if ($row === null) {
            return [];
        }

        return [
            'title' => $row->metaTitle($locale),
            'description' => $row->metaDescription($locale),
            'canonical' => is_string($row->canonical_url) && $row->canonical_url !== '' ? $row->canonical_url : null,
            'ogImage' => is_string($row->og_image) && $row->og_image !== '' ? $row->og_image : null,
            'robots' => is_string($row->robots) && $row->robots !== '' ? $row->robots : 'index, follow',
            'ogTitle' => $row->ogTitle($locale),
            'ogDescription' => $row->ogDescription($locale),
            'jsonLd' => $row->getJsonLd($locale),
        ];
    }
}
