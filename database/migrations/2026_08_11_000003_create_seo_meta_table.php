<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO/meta storage (docs/BLUEPRINT.md §2.3.11/§2.4's `SeoMeta` — polymorphic, no host
 * coupling — ported verbatim, plus the extensions the SEO/Social panel already draws:
 * `og_title_en/_fr`, `og_description_en/_fr` (social tab is distinct from the meta tab),
 * `focus_keyphrase_en/_fr` (scoring input, Wave S2b), `in_sitemap` (the panel's "Include in
 * sitemap" toggle — `robots` alone covers index/follow, not sitemap inclusion).
 *
 * `config('heisenberg.tables.seo_meta')` reserves this table name long before this migration
 * existed (docs/seo-system.md Wave S1) — deliberately **unprefixed** `seo_meta`, unlike every
 * other table in this package, matching the blueprint exactly: the same table is meant to
 * serve any morphable entity a host adds (categories, tags, …), not just posts, so it isn't
 * namespaced under `heisenberg_` the way post-specific tables are.
 *
 * `morphs('able')` gives `able_type`/`able_id` with a composite index; a unique index on the
 * same pair on top of that enforces "one SeoMeta row per entity" (`updateOrCreate` on
 * `able_type`/`able_id` is the only way a row is ever created — see {@see \Heisenberg\Models\SeoMeta}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->morphs('able');

            $table->string('meta_title_en', 255)->nullable();
            $table->string('meta_title_fr', 255)->nullable();
            $table->string('meta_description_en', 255)->nullable();
            $table->string('meta_description_fr', 255)->nullable();
            $table->string('og_image', 255)->nullable();
            $table->string('canonical_url', 255)->nullable();
            $table->string('robots', 255)->default('index, follow');
            $table->string('schema_type', 255)->nullable();
            $table->json('schema_data')->nullable();

            $table->string('og_title_en', 255)->nullable();
            $table->string('og_title_fr', 255)->nullable();
            $table->string('og_description_en', 255)->nullable();
            $table->string('og_description_fr', 255)->nullable();
            $table->string('focus_keyphrase_en', 255)->nullable();
            $table->string('focus_keyphrase_fr', 255)->nullable();
            $table->boolean('in_sitemap')->default(true);

            $table->timestamps();

            $table->unique(['able_type', 'able_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.seo_meta', 'seo_meta');
    }
};
