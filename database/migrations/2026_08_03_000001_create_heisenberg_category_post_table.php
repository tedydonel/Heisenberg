<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post <-> Category pivot — mirrors `heisenberg_post_tag` (migration 2026_07_28_000005) exactly:
 * composite primary key `(post_id, category_id)`, no surrogate id, both FKs cascade on delete.
 *
 * DEVIATION FROM BLUEPRINT: docs/BLUEPRINT.md §2.3.1/§2.4 (and migration 2026_07_28_000006's own
 * docblock) document a post's category as a single BelongsTo — deliberately, "confirmed by the
 * blueprint, not an oversight." This migration supersedes that: the editor's Categories field is
 * now a Gutenberg-style multi-select checklist, which a single `category_id` column cannot back.
 * See the next migration (2026_08_03_000002) for the `category_id` column's removal + data
 * migration, and Post::categories()/Category::posts() for the reworked relations.
 *
 * Runs after both `heisenberg_posts` (2026_01_01_000001) and `heisenberg_categories`
 * (2026_07_28_000003) in filename order, so both FK targets already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('category_id');

            $table->primary(['post_id', 'category_id']);

            $table->foreign('post_id')
                ->references('id')
                ->on(config('heisenberg.tables.posts', 'heisenberg_posts'))
                ->cascadeOnDelete();

            $table->foreign('category_id')
                ->references('id')
                ->on(config('heisenberg.tables.categories', 'heisenberg_categories'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.category_post', 'heisenberg_category_post');
    }
};
