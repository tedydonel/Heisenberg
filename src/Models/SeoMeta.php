<?php

declare(strict_types=1);

namespace Heisenberg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic SEO/meta storage (docs/BLUEPRINT.md §2.3.11/§2.4's `SeoMeta`, ported verbatim
 * plus the extensions the SEO/Social panel already draws — see the migration's own docblock
 * for the full column rationale). Table `config('heisenberg.tables.seo_meta')`, deliberately
 * unprefixed `seo_meta` — the same row shape is meant to serve any morphable entity a host
 * adds later (categories, tags, …), not just posts.
 *
 * `$fillable` (not `$guarded = []`) is the right posture HERE, unlike {@see Post}'s
 * guarded-columns stance: a `Post` is saved through many different code paths with many
 * different partial payloads (autosave, block saves, status transitions, …), so guarding
 * columns and hand-picking what each path may touch is the safer default. A `SeoMeta` row has
 * exactly one write path — {@see \Heisenberg\Adapters\NativeSeoMetaProvider} /
 * `PostController::applySeo()` (Wave S2a) `updateOrCreate`-ing from a request already
 * validated against an explicit field whitelist — so there is nothing a blanket `$fillable`
 * list exposes that the validator didn't already allow through.
 *
 * Locale accessors (`metaTitle()`, `metaDescription()`, `ogTitle()`, `ogDescription()`,
 * `focusKeyphrase()`) take the {@see PublicFile::getAlt()} fallback posture: own-locale value
 * first, the other locale's value when the requested one is empty, null when both are empty.
 */
class SeoMeta extends Model
{
    protected $fillable = [
        'able_type', 'able_id',
        'meta_title_en', 'meta_title_fr',
        'meta_description_en', 'meta_description_fr',
        'og_image', 'canonical_url', 'robots', 'schema_type', 'schema_data',
        'og_title_en', 'og_title_fr',
        'og_description_en', 'og_description_fr',
        'focus_keyphrase_en', 'focus_keyphrase_fr',
        'in_sitemap',
    ];

    protected $casts = [
        'schema_data' => 'array',
        'in_sitemap' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('heisenberg.tables.seo_meta', 'seo_meta');
    }

    public function able(): MorphTo
    {
        return $this->morphTo();
    }

    public function metaTitle(string $locale): ?string
    {
        return $this->localized('meta_title', $locale);
    }

    public function metaDescription(string $locale): ?string
    {
        return $this->localized('meta_description', $locale);
    }

    public function ogTitle(string $locale): ?string
    {
        return $this->localized('og_title', $locale);
    }

    public function ogDescription(string $locale): ?string
    {
        return $this->localized('og_description', $locale);
    }

    public function focusKeyphrase(string $locale): ?string
    {
        return $this->localized('focus_keyphrase', $locale);
    }

    /**
     * Own-locale value first, the other locale's value as a fallback, null when both columns
     * are empty — {@see PublicFile::getAlt()}'s posture, generalized over a column prefix.
     */
    private function localized(string $column, string $locale): ?string
    {
        $locale = strtolower($locale) === 'fr' ? 'fr' : 'en';
        $other = $locale === 'fr' ? 'en' : 'fr';

        $primary = $this->getAttribute("{$column}_{$locale}");
        $fallback = $this->getAttribute("{$column}_{$other}");

        $primary = is_string($primary) ? trim($primary) : '';
        $fallback = is_string($fallback) ? trim($fallback) : '';

        if ($primary !== '') {
            return $primary;
        }

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * Schema.org JSON-LD for this entity (blueprint §11.1). Computed defaults
     * (`@type`/`headline`/`description`/`image`/`url`) are merged UNDER `schema_data` — stored
     * keys always win, so a host that hand-authors richer structured data (e.g. `author`,
     * `datePublished`) never has it clobbered by this method's own defaults. Returns `[]` when
     * there is nothing meaningful to emit (no title/description, no image, and no stored
     * `schema_data`) rather than a bare `{"@context": ...}` stub.
     *
     * @return array<string, mixed>
     */
    public function getJsonLd(string $locale, ?string $url = null): array
    {
        $headline = $this->metaTitle($locale);
        $description = $this->metaDescription($locale);
        $image = is_string($this->og_image) && $this->og_image !== '' ? $this->og_image : null;
        $schemaData = is_array($this->schema_data) ? $this->schema_data : [];

        if ($headline === null && $description === null && $image === null && $schemaData === []) {
            return [];
        }

        $defaults = ['@context' => 'https://schema.org'];
        $defaults['@type'] = is_string($this->schema_type) && $this->schema_type !== '' ? $this->schema_type : 'Article';

        if ($headline !== null) {
            $defaults['headline'] = $headline;
        }
        if ($description !== null) {
            $defaults['description'] = $description;
        }
        if ($image !== null) {
            $defaults['image'] = $image;
        }
        if ($url !== null && $url !== '') {
            $defaults['url'] = $url;
        }

        return array_merge($defaults, $schemaData);
    }
}
