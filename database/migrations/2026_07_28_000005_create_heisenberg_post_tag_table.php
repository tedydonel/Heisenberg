<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post <-> Tag pivot (blueprint §2.4 `blog_post_tag`: "pivot, no timestamps").
 * Composite primary key `(post_id, tag_id)` — no surrogate `id` column,
 * exactly mirroring the blueprint. Both FKs cascade on delete: a
 * force-deleted post or tag takes its pivot rows with it at the DB level.
 *
 * IMPORTANT caveat (same one Post::blocks()/revisions() already document): a
 * plain SoftDeletes `delete()` on Post or Tag is an UPDATE, not a DELETE — it
 * does NOT fire this FK cascade, so a soft-deleted post/tag keeps its pivot
 * rows intact (only `forceDelete()` actually removes them). This is
 * deliberate here, unlike blocks/revisions: Post's cascading soft-delete
 * mechanism (the shared `deleted_batch_id`, see Post::delete()/restore())
 * intentionally does NOT extend to categories/tags — the blueprint calls
 * them "independent taxonomies" a post merely references, not owned content
 * the way blocks/revisions are. A trashed post keeps its tag associations
 * (and its category_id) exactly as they were, ready to reappear unchanged if
 * the post is restored.
 *
 * Runs after both `heisenberg_posts` (2026_01_01_000001) and
 * `heisenberg_tags` (2026_07_28_000004) in filename order, so both FK targets
 * already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');

            $table->primary(['post_id', 'tag_id']);

            $table->foreign('post_id')
                ->references('id')
                ->on(config('heisenberg.tables.posts', 'heisenberg_posts'))
                ->cascadeOnDelete();

            $table->foreign('tag_id')
                ->references('id')
                ->on(config('heisenberg.tables.tags', 'heisenberg_tags'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.post_tag', 'heisenberg_post_tag');
    }
};
