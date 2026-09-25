<?php

declare(strict_types=1);

use Heisenberg\Models\TocEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Translatable table-of-contents labels (docs/content-translation.md §0): `label_en`/`label_fr`,
 * the same fixed per-locale columns `title_*`/`excerpt_*` use. An entry's `anchor` and `order`
 * are shared by every locale (the anchor is a DOM id on the one rendered heading); only the
 * label is translated.
 *
 * Every existing label is backfilled into its post's HOME locale column. The bare `label`
 * column stays and keeps mirroring the home-locale label ({@see TocEntry}), so a host that
 * reads `$entry->label` directly still gets what it always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('label_en', 160)->nullable()->after('label');
            $table->string('label_fr', 160)->nullable()->after('label_en');
        });

        $posts = config('heisenberg.tables.posts', 'heisenberg_posts');
        DB::table($this->table())
            ->join($posts, $posts . '.id', '=', $this->table() . '.post_id')
            ->select([$this->table() . '.id', $this->table() . '.label', $posts . '.locale'])
            ->orderBy($this->table() . '.id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $locale = in_array($row->locale, ['en', 'fr'], true) ? $row->locale : 'en';
                    DB::table($this->table())->where('id', $row->id)->update(["label_{$locale}" => $row->label]);
                }
            });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['label_en', 'label_fr']);
        });
    }

    private function table(): string
    {
        return config('heisenberg.tables.toc_entries', 'heisenberg_post_toc_entries');
    }
};
