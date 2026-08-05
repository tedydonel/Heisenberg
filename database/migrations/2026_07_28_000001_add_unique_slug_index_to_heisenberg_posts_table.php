<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the slug-collision gap: `slug` shipped as a plain non-unique index
 * (2026_01_01_000001) while `Post::booted()` derived it from the title with
 * NO collision detection at all — two posts could silently share a slug.
 *
 * Column choice: `['locale', 'slug']`, not a bare `slug` unique and not the
 * `(slug, deleted_at)` pattern used elsewhere in the wider blueprint lineage
 * (docs/BLUEPRINT.md §2.4 `blog_posts`):
 *
 *  - `locale` is part of the identity here (§2.3.1: a post's `locale` enum
 *    plus `translation_group_id` model the EN/FR pair as two distinct rows,
 *    not one row with two fields). An EN and FR post are different documents
 *    that may legitimately reuse the same slug text under their own locale
 *    namespace, so scoping the constraint by locale is correct, not merely
 *    permissive.
 *  - Deliberately NOT `(slug, deleted_at)`. That composite relies on
 *    "deleted_at" being NULL for every active row and NOT NULL for trashed
 *    ones, but both MySQL and SQLite treat every NULL as DISTINCT for
 *    uniqueness purposes — so a `(slug, deleted_at)` unique index enforces
 *    NOTHING among active rows (deleted_at IS NULL on all of them); it only
 *    ever fires if two trashed rows share the exact same deleted_at instant.
 *    That defeats the point of the constraint the brief asks for. Using
 *    `['locale', 'slug']` alone (no deleted_at) enforces uniqueness across
 *    ALL rows for that locale, active or soft-deleted — a trashed post keeps
 *    its slug reserved. That is intentional: the post can still be restored
 *    (see Post::restore()), and letting a new post grab a trashed post's
 *    slug in the meantime would turn a later restore into a collision. A
 *    slug becomes reusable again only once the row is actually gone
 *    (forceDelete()). Post::uniqueSlug() queries `withTrashed()` accordingly
 *    when picking a numeric-suffix fallback.
 *
 * SQLite/MySQL 5.7+: a plain composite unique index — no partial/filtered
 * index, no generated column — so it is fully portable across both drivers
 * with Laravel's schema builder alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->unique(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropUnique(['locale', 'slug']);
        });
    }

    private function table(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }
};
