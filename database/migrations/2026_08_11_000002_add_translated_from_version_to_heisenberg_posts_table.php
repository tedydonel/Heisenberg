<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content translation, Wave T1 (docs/content-translation.md §2). Split-row translations already
 * exist (same `translation_group_id`, distinct `locale` per row) — what was missing is a way to
 * tell whether a translated sibling has drifted out of date from the row it was translated FROM.
 *
 * `translated_from_version` records the SOURCE row's `content_version` at the moment this row's
 * content was last translated (created or re-translated from the source). Outdated-detection
 * (`Post::isTranslationOutdated()`) is then just: this value is non-null, a sibling exists, and
 * that sibling's current `content_version` has moved past the recorded value — i.e. the source
 * kept changing after we translated it. Null means "never machine/workflow-translated" (hand
 * authored independently, or a pre-existing row from before this feature) — never considered
 * outdated, since there is no recorded translation point to compare against.
 *
 * Nullable, no default, no FK (it is a snapshot of a sibling's counter, not a reference to a row —
 * the sibling itself is found via `translation_group_id` + the other locale, same as `sibling()`).
 * Not added to Post::$fillable — same "server-only counter-adjacent column" posture as
 * `content_version` itself; see Post::$translated_from_version docblock for the write path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->unsignedBigInteger('translated_from_version')->nullable()->after('content_version');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('translated_from_version');
        });
    }

    private function table(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }
};
