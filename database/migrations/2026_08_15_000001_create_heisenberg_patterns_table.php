<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's saved reusable block compositions ("patterns") — a list of
 * complete block models the toolbar's save-as-block button writes to and the
 * Components panel's Blocks tab reads back as draggable cards. The toolbar
 * gates the save button to containers (group/columns/column, where
 * `innerBlocks.enabled === true`), so every pattern is the saved shape of a
 * container with its subtree — exactly what duplicateBlock clones — and
 * insertion is the same operation re-run on a fresh set of ids.
 *
 * `name` is a short user-typed label (the prompt that opens when the toolbar's
 * save icon is clicked), capped at 120 chars and unique across this install
 * so a pattern can be referenced by name in URLs (the Blocks tab's search
 * keeps it usable without one). `blocks` is the same JSON shape the editor's
 * save payload uses: an array of block models in the form duplicateBlock
 * produces (id, name, attributes, supports, innerBlocks, schemaVersion) — the
 * server treats it as opaque and hands it back verbatim to the client, which
 * inserts each top-level entry through the runtime's normalizeModel so the
 * fresh-id/re-default dance is the same one an in-place duplicate takes.
 *
 * `heisenberg_patterns` was reserved in `config('heisenberg.tables.patterns')`
 * long before this migration existed — this is the delivery that finally
 * fills it in and lets the editor's `heisenberg.editor.patterns.*` routes
 * live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->json('blocks');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.patterns', 'heisenberg_patterns');
    }
};