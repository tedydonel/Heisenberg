<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email system, Wave E1 (docs/email-system.md §3). `type` distinguishes a post row
 * authored as a blog/page document (`'post'`, the existing default) from one authored
 * as an email (`'email'`) — same editor, same block engine, same lifecycle machinery
 * (revisions, autosave, locking, translations), different render target
 * (`EmailRenderer` beside the existing `BlockRenderer`, docs/email-system.md §5).
 *
 * string(16), NOT NULL, default `'post'` (every existing/未-set row is a post, never a
 * silent NULL), indexed — every listing surface that must scope by type (sitemap,
 * `Post::scopePosts()`/`scopeEmails()`, MCP `list_posts`) filters on it.
 *
 * NOT added to `Post::$fillable` — same guarded posture as `status`: PostController's
 * own docblock is explicit that lifecycle-adjacent columns are "never mass-assigned"
 * even when a column technically appears in `$fillable`, and `type` is stricter still
 * (deliberately absent from the array entirely). The only write path is a direct
 * property assignment at the moment a document is created as an email (McpToolRegistry's
 * `create_post` `type` arg today; the editor's "New email" flow in Wave E3) — never a
 * generic mass-assign that could flip an existing post's type as a side effect of an
 * unrelated content edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('type', 16)->default('post')->after('locale');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropIndex([$this->table() . '_type_index']);
            $table->dropColumn('type');
        });
    }

    private function table(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }
};
