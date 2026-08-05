<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `category_id` to the posts table (blueprint §2.4 `blog_posts.category_id`
 * FK -> `blog_categories`, `nullOnDelete`; §2.3.1 "`category()` BelongsTo
 * `BlogCategory`") — a NEW migration rather than editing
 * 2026_01_01_000001_create_heisenberg_posts_table.php, which this task does
 * not touch.
 *
 * A post has AT MOST ONE category (BelongsTo) — deliberately a different
 * shape from tags (BelongsToMany via the `heisenberg_post_tag` pivot,
 * migration 2026_07_28_000005). The asymmetry config('heisenberg.tables')
 * already hints at (a reserved `post_tag` table but no `category_post` table)
 * is confirmed correct by the blueprint, not an oversight — see
 * Category::class and Post::category()/tags() docblocks for the full
 * reasoning.
 *
 * Runs AFTER the categories table migration (2026_07_28_000003) in filename
 * order, so the FK target already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable()->after('author_id');
            $table->index('category_id');

            $table->foreign('category_id')
                ->references('id')
                ->on($this->categoriesTable())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }

    private function postsTable(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }

    private function categoriesTable(): string
    {
        return config('heisenberg.tables.categories', 'heisenberg_categories');
    }
};
