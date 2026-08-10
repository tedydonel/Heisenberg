<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI messages — the turns of an `ai_conversations` thread, in order. Only the
 * turns a human would recognise as the conversation are stored (`user` and
 * `assistant`): the tool-call rounds inside one assistant turn are transport,
 * not transcript, and replaying them to the model would pin it to tool results
 * that have since gone stale. Continuing a conversation resends these turns as
 * real message history, so the model actually remembers it.
 *
 * `meta` carries per-turn extras the transcript UI rebuilds from (reasoning
 * text, tools used, blocks-built count) — display state, never model input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('role', 16); // user | assistant
            $table->longText('content');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('conversation_id');

            $table->foreign('conversation_id')
                ->references('id')
                ->on(config('heisenberg.tables.ai_conversations', 'heisenberg_ai_conversations'))
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.ai_messages', 'heisenberg_ai_messages');
    }
};
