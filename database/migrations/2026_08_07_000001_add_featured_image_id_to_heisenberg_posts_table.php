<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Featured image for a post (Post tab inspector's "Featured image" disclosure, mirror image of
 * the page-padding/discussion columns added 2026-08-03). Nullable FK to heisenberg_public_files;
 * null means "no featured image" (the inspector's dropzone is shown again). The FK is unconditional
 * rather than the lazy-conditional pattern the `uploaded_by` column uses on heisenberg_public_files
 * because heisenberg_public_files is guaranteed to exist by the time this migration runs — the
 * package's own migrations always load its own blocks first.
 *
 * `featured_image_id` is deliberately NOT in Post::$fillable (same posture as `page_padding_x`/
 * `page_padding_y`/`allow_comments`): PostSettingsController::updateFeaturedImage() is the only path
 * that may write it, mirror of its own docblock.
 *
 * Cascade is `nullOnDelete()` rather than `cascadeOnDelete()`: deleting a featured image must
 * not destroy the post it belonged to — a post with a featured image is half-published content
 * and the post is the more important row. The next migration of the post's `featured_image_id`
 * to null is the right behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->foreignId('featured_image_id')
                ->nullable()
                ->after('allow_comments')
                ->constrained($this->publicFilesTable())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('featured_image_id');
        });
    }

    private function postsTable(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }

    private function publicFilesTable(): string
    {
        return config('heisenberg.tables.public_files', 'heisenberg_public_files');
    }
};
