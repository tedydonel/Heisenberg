<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authored table-of-contents entries — a scoped-down first cut of blueprint §2.3.10's
 * `BlogPostTocEntry` (single `label`/`anchor` rather than bilingual `label_en`/`label_fr` and
 * `target`, no `num`; see {@see \Heisenberg\Models\TocEntry}'s own docblock). The editorially-
 * curated counterpart to the `tableOfContents` capability's `source: "headings"` derivation
 * (docs/post-template-schema.md). A post's TOC renders ONLY when it has rows here;
 * PostSettingsController::updateToc() writes them with replace-all semantics (delete then
 * re-insert in submitted order), so `order` is always a dense 0-based index — never a value the
 * client picks directly.
 *
 * Table name comes from `config('heisenberg.tables.toc_entries')` — already reserved for this
 * purpose (see config/heisenberg.php; the key predates this migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->string('label', 160);
            $table->string('anchor', 160);
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->index('post_id');

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
        return config('heisenberg.tables.toc_entries', 'heisenberg_post_toc_entries');
    }
};
