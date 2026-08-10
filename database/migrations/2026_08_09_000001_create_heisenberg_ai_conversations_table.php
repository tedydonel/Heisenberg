<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI conversations — one row per chat thread in the editor's AI panel, so a
 * conversation survives a page reload and can be reopened later. Before this
 * table the thread was pure client-side DOM: closing the tab was deletion.
 *
 * Scoped to an author and (optionally) a post: the history dialog lists "my
 * conversations about this post". `post_id` is nullable because a chat can
 * start before the post's first save gives it an id; it stays attached to
 * nothing rather than blocking the assistant. Deleting a post hard-deletes its
 * conversations — a chat about a post that no longer exists has no home to be
 * reopened in. (Deliberately NOT part of the deleted_batch_id soft-delete
 * cascade: conversations are workbench state, not content.)
 *
 * The turns themselves live in `ai_messages` (next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('title')->nullable(); // first user prompt, truncated — display only
            $table->timestamps();

            $table->index('post_id');
            $table->index('author_id');
            $table->index('updated_at'); // history dialog lists newest-activity first

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
        return config('heisenberg.tables.ai_conversations', 'heisenberg_ai_conversations');
    }
};
