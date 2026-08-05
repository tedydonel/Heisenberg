<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories table (blueprint §2.3.3/§2.4 `blog_categories`): a self-parenting
 * tree — `parent_id` is a nullable FK back onto this same table, `nullOnDelete`
 * (blueprint: "promotes children to root" is handled at the application layer
 * in CategoryController::destroy() before the row is deleted; the DB-level
 * `nullOnDelete` here is the fallback for any row that reaches deletion any
 * other way). A post has AT MOST ONE category — see the sibling migration
 * `2026_07_28_000006_add_category_id_to_heisenberg_posts_table.php` and
 * `Post::category()` (a BelongsTo, not a pivot). Contrast with tags, which
 * ARE many-to-many (migration 2026_07_28_000005's `heisenberg_post_tag`
 * pivot) — the blueprint confirms this asymmetry is deliberate, not an
 * oversight of the config already reserving a `post_tag` table but no
 * `category_post` table.
 *
 * Bilingual `name_en`/`name_fr` (and `description_en`/`description_fr`) live
 * on a SINGLE row — unlike `Post`, a category is NOT split one-row-per-locale,
 * so there is no `locale` column and no `['locale','slug']` composite here.
 *
 * DEVIATION FROM BLUEPRINT: docs/BLUEPRINT.md §2.4 documents `blog_categories`
 * as "(no SD)" in the AS-BUILT legacy schema, and §2.3.3 likewise has no SD
 * trait. This package's task brief explicitly asked for soft deletes on the
 * new taxonomy tables, and every other Heisenberg content model already
 * shipped (Post, Block, Revision, PublicFile) uses SoftDeletes — keeping
 * Category/Tag as the only hard-delete models would be a surprising
 * inconsistency for an adopter building against this API, and the blueprint
 * carries no `[TARGET]` note insisting the no-SD behavior be preserved. See
 * the Category model's docblock for the corollary this creates.
 *
 * `slug` is a PLAIN unique column — not a `(slug, deleted_at)` composite:
 * both MySQL and SQLite treat every NULL as distinct for uniqueness purposes,
 * so a `(slug, deleted_at)` composite enforces nothing among active rows
 * (deleted_at IS NULL on all of them) — see migration
 * 2026_07_28_000001_add_unique_slug_index_to_heisenberg_posts_table's
 * docblock for the fuller version of this reasoning. Because a category has
 * no locale axis to compose `slug` with the way Post does, a bare
 * `unique('slug')` is both correct AND simplest here: it spans every row —
 * active or soft-deleted — so a trashed category's slug stays reserved until
 * the row is force-deleted. Mirrors Post's `['locale','slug']` intent exactly,
 * minus the locale dimension Post needs and Category doesn't have.
 *
 * SQLite/MySQL 5.7+: a self-referencing FK inside the same `Schema::create`
 * call, a plain unique index, and a single nullable FK — all portable across
 * both drivers with Laravel's schema builder alone, no partial/filtered
 * index and no generated column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name_en');
            $table->string('name_fr')->nullable();
            $table->string('slug')->unique();
            $table->text('description_en')->nullable();
            $table->text('description_fr')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('order');

            $table->foreign('parent_id')
                ->references('id')
                ->on($this->table())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.categories', 'heisenberg_categories');
    }
};
