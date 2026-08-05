<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public files table (docs/media-library-backend-blueprint.md §4, §11). Metadata
 * only — binaries live on the `uploads` disk, never in this table. The blueprint
 * built this schema in 4 historical steps (create, add variants JSON, back-fill
 * `type` to the file extension, ensure-variants-column guard); this single create
 * migration collapses all of them — `type` is the extension from day one and
 * `variants` ships with the table.
 *
 * `uploaded_by` is always a plain nullable column so uploads work even in a host
 * with no users table yet; the FK itself is added ONLY when the configured users
 * table (config('heisenberg.users_table')) is already migrated, because a package
 * must never hard-assume the host's users table exists at package-migration time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('type', 60);
            $table->string('disk', 32)->default('uploads');
            $table->string('stored_path');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->json('variants')->nullable();
            $table->string('alt_text_en', 255)->nullable();
            $table->string('alt_text_fr', 255)->nullable();
            $table->string('caption_en', 500)->nullable();
            $table->string('caption_fr', 500)->nullable();
            $table->string('credit', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index(['type', 'created_at']);
            $table->index('uploaded_by');
        });

        $usersTable = (string) config('heisenberg.users_table', 'users');

        if (Schema::hasTable($usersTable)) {
            Schema::table($this->table(), function (Blueprint $table) use ($usersTable): void {
                $table->foreign('uploaded_by')
                    ->references('id')
                    ->on($usersTable)
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('heisenberg.tables.public_files', 'heisenberg_public_files');
    }
};
