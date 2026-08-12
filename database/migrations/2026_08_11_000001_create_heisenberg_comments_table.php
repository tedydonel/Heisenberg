<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Native comments storage (docs/BLUEPRINT.md §2.3.6's `BlogComment`, scoped down for this
 * first cut — see {@see \Heisenberg\Models\Comment}'s own docblock for what was deliberately
 * left out). `heisenberg_comments` was reserved by `config('heisenberg.tables.comments')`
 * long before this migration existed; this is the delivery that finally fills it in and
 * flips `PostCommentProvider`'s default binding from the null adapter to
 * {@see \Heisenberg\Adapters\NativeCommentProvider}.
 *
 * `author_id` is a plain, unindexed-by-FK unsignedBigInteger — same "host-owned users table
 * a package must not hard-assume" posture as `heisenberg_public_files.uploaded_by`, except
 * here there is deliberately no conditional FK add-on even when the host's users table
 * exists: a comment must survive a deleted user account (guest or registered), so nulling
 * or cascading the FK is never the right call — the column is looked up, never joined.
 *
 * `parent_id` self-references THIS table (nested replies) and cascades on delete: removing
 * a comment removes its whole reply subtree rather than orphaning replies with a dangling
 * parent_id. `status` starts life as a plain `string` (not a DB-level enum/CHECK) so the
 * `Comment::STATUSES` list can grow without a migration — same reasoning as `Post::status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 120);
            $table->string('author_email', 190)->nullable();
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->index('parent_id');
            $table->index('author_id');
            $table->index(['post_id', 'status', 'created_at']);

            $table->foreign('post_id')
                ->references('id')
                ->on(config('heisenberg.tables.posts', 'heisenberg_posts'))
                ->cascadeOnDelete();

            $table->foreign('parent_id')
                ->references('id')
                ->on($this->table())
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.comments', 'heisenberg_comments');
    }
};
