<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills every existing post's single `category_id` into the new `heisenberg_category_post`
 * pivot (migration 2026_08_03_000001), then drops `category_id` — completing the single-to-multi
 * category conversion (see that migration's docblock for why). Runs AFTER the pivot table
 * migration in filename order, so its target already exists.
 *
 * down() is schema-only, not a full data-symmetric reversal: it re-adds `category_id` and
 * backfills it from each post's FIRST pivot row (arbitrary if a post ended up with more than one
 * category after this migration ran forward) — a real, if lossy, best-effort restore rather than
 * leaving the column merely present-but-empty. The pivot table itself is left untouched (owned by
 * the other migration's own down()).
 */
return new class extends Migration
{
    public function up(): void
    {
        $posts = DB::table($this->postsTable())->whereNotNull('category_id')->get(['id', 'category_id']);

        foreach ($posts as $post) {
            DB::table($this->pivotTable())->insert([
                'post_id' => $post->id,
                'category_id' => $post->category_id,
            ]);
        }

        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            // dropForeign() only removes the FK constraint, not the separate plain index migration
            // 2026_07_28_000006 also created ($table->index('category_id')) — SQLite's dropColumn()
            // (a full table rebuild under the hood) fails if a leftover index still references the
            // column being dropped.
            $table->dropIndex(['category_id']);
            $table->dropColumn('category_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable()->after('author_id');
            $table->index('category_id');

            $table->foreign('category_id')
                ->references('id')
                ->on($this->categoriesTable())
                ->nullOnDelete();
        });

        $pivots = DB::table($this->pivotTable())->orderBy('post_id')->get(['post_id', 'category_id']);
        $seen = [];

        foreach ($pivots as $pivot) {
            if (isset($seen[$pivot->post_id])) {
                continue; // best-effort: keep only the first category per post
            }
            $seen[$pivot->post_id] = true;
            DB::table($this->postsTable())->where('id', $pivot->post_id)->update(['category_id' => $pivot->category_id]);
        }
    }

    private function postsTable(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }

    private function pivotTable(): string
    {
        return config('heisenberg.tables.category_post', 'heisenberg_category_post');
    }

    private function categoriesTable(): string
    {
        return config('heisenberg.tables.categories', 'heisenberg_categories');
    }
};
