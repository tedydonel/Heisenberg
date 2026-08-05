<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing storage for two Post-tab inspector sections that were previously inert fixture rows
 * (see inspector.blade.php's own docblock): Page layout (`page_padding_x`/`page_padding_y`, px,
 * the whole page's horizontal/vertical padding — see resources/css/editor/34-canvas.css's
 * `--hb-page-padding-x`/`--hb-page-padding-y` custom properties) and Discussion
 * (`allow_comments`). All three are nullable: null means "use the built-in default" (56/56px,
 * comments allowed) — see EditorController::sharedViewData()/show() for where that default is
 * applied — rather than a NOT NULL column forcing every existing row to be backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->unsignedSmallInteger('page_padding_x')->nullable()->after('scheduled_at');
            $table->unsignedSmallInteger('page_padding_y')->nullable()->after('page_padding_x');
            $table->boolean('allow_comments')->nullable()->after('page_padding_y');
        });
    }

    public function down(): void
    {
        Schema::table($this->postsTable(), function (Blueprint $table): void {
            $table->dropColumn(['page_padding_x', 'page_padding_y', 'allow_comments']);
        });
    }

    private function postsTable(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }
};
