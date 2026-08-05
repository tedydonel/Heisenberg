<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media\Fakes;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A minimal real `users` row (Eloquent model, not an in-memory stub) so the
 * "deleting a user nulls uploaded_by" scenario can exercise the actual FK
 * constraint (see MediaLibraryTest::setUp() — it creates a `users` table
 * before running the package migrations so the guarded FK in
 * create_heisenberg_public_files_table gets added).
 */
class FakeUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name', 'email'];
}
