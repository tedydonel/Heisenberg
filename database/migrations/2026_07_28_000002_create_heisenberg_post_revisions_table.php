<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisions table — a point-in-time snapshot of a post (its block tree, rendered
 * HTML, title/excerpt) so "restore a previous version" is possible. `content_version`
 * (on `posts`) is a bare optimistic-lock counter; it cannot answer "what did this
 * post look like an hour ago", which is what this table is for.
 *
 * Table name comes from `config('heisenberg.tables.revisions')` (already reserved
 * for this purpose — see config/heisenberg.php).
 *
 * Mirrors the wider blueprint's `blog_post_revisions` (docs/BLUEPRINT.md §2.3.5,
 * §2.4): a JSON snapshot of the blocks (`content_blocks`), the rendered HTML and
 * title/excerpt in both locales, an author, and a `revision_type` tag
 * (manual|auto_save|restore). Participates in the SAME deleted_batch_id
 * cascade soft-delete/restore mechanism as `blocks` (see Post::delete()/restore())
 * — both content tables carry the shared batch UUID so a post's revisions are
 * trashed and restored in lockstep with it, not just its blocks.
 *
 * Not wired into any controller/save path here — that belongs to the HTTP layer
 * another agent owns. This migration + the Revision model only make the
 * snapshot/restore primitive available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->json('content_blocks'); // point-in-time snapshot of the block tree
            $table->longText('rendered_html_en')->nullable();
            $table->longText('rendered_html_fr')->nullable();
            $table->string('title_en')->nullable();
            $table->string('title_fr')->nullable();
            $table->text('excerpt_en')->nullable();
            $table->text('excerpt_fr')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('revision_type', 20)->default('manual');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('deleted_batch_id')->nullable();

            $table->index('post_id');
            $table->index('author_id');
            $table->index('deleted_batch_id');

            $table->foreign('post_id')
                ->references('id')
                ->on(config('heisenberg.tables.posts', 'heisenberg_posts'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.revisions', 'heisenberg_post_revisions');
    }
};
