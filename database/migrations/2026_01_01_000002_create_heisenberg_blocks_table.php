<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocks table (blueprint §2.4): one row per block, `content` is the JSON payload
 * and the single source of truth for structured content. Cascades on post delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->string('type', 50);
            $table->json('content');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('deleted_batch_id')->nullable();

            $table->index(['post_id', 'order']);
            $table->index('deleted_batch_id');
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
        return config('heisenberg.tables.blocks', 'heisenberg_blocks');
    }
};
