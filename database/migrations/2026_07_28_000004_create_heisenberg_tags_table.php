<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags table (blueprint §2.3.4/§2.4 `blog_tags`) — flat, no parent, no locale
 * split (bilingual `name_en`/`name_fr` on one row, same as Category). See the
 * categories migration's docblock (2026_07_28_000003) for the full reasoning
 * behind (a) the SoftDeletes deviation from the blueprint's "(no SD)"
 * annotation, and (b) why `slug` is a bare unique column rather than a
 * `(slug, deleted_at)` composite — both apply identically here.
 *
 * Post <-> Tag is many-to-many (migration 2026_07_28_000005's
 * `heisenberg_post_tag` pivot, table name already reserved in
 * config('heisenberg.tables.post_tag')) — contrast with Category, which is a
 * per-post BelongsTo (`heisenberg_posts.category_id`, migration
 * 2026_07_28_000006), not a pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('name_en');
            $table->string('name_fr')->nullable();
            $table->string('slug')->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.tags', 'heisenberg_tags');
    }
};
