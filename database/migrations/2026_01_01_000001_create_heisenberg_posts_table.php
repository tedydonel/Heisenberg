<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posts table (blueprint §2.4). A focused subset of the full magazine schema —
 * the columns the save/render/lifecycle paths need; the remaining presentation
 * columns (eyebrow pills, hero image, byline, counters, …) are added later.
 * author_id is nullable with no FK here; a host wires the users FK via config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->uuid('translation_group_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->enum('locale', ['en', 'fr'])->default('en');
            $table->string('title_en');
            $table->string('title_fr')->nullable();
            $table->string('slug');
            $table->unsignedBigInteger('content_version')->default(0);
            $table->text('excerpt_en')->nullable();
            $table->text('excerpt_fr')->nullable();
            $table->longText('rendered_html_en')->nullable();
            $table->longText('rendered_html_fr')->nullable();
            $table->enum('status', ['draft', 'pending_review', 'published', 'scheduled', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('deleted_batch_id')->nullable();

            $table->index('translation_group_id');
            $table->index('author_id');
            $table->index('status');
            $table->index('slug');
            $table->index('deleted_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }
};
