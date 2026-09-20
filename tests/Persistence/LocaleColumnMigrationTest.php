<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Proves migration 2026_09_19_000001 (converts `heisenberg_posts.locale` from a DB-level
 * ENUM('en','fr') to a plain `string(8)`) on SQLite — the default test connection
 * (see {@see TestCase::getEnvironmentSetUp()}):
 *
 *  - the column keeps accepting 'en'/'fr' after the migration (RefreshDatabase already
 *    runs it as part of the full migration set, so every other test in this suite is
 *    already implicitly exercising the post-migration schema; this test additionally
 *    exercises the migration's own up()/down() directly);
 *  - existing data survives a rollback and a re-apply;
 *  - the `['locale','slug']` unique index (migration 2026_07_28_000001) still holds;
 *  - the column's default is still 'en';
 *  - down() actually restores DB-level enforcement (not just the type label) and
 *    refuses to run at all when a row holds a locale outside the original pair.
 *
 * MySQL/PostgreSQL are NOT exercised here (see the migration's own docblock for the
 * driver-specific reasoning verified by hand against each grammar's compiled SQL) —
 * this class runs only against the default in-memory SQLite connection.
 */
class LocaleColumnMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    private const MIGRATION_FILE = __DIR__ . '/../../database/migrations/2026_09_19_000001_convert_heisenberg_posts_locale_enum_to_string.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_CONNECTION', 'sqlite') !== 'sqlite') {
            $this->markTestSkipped('This migration test is written against SQLite\'s specific rebuild-based ALTER behaviour; see the migration docblock for the MySQL/Postgres reasoning.');
        }
    }

    private function table(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }

    private function migration(): Migration
    {
        return require self::MIGRATION_FILE;
    }

    public function test_the_column_already_accepts_en_and_fr_after_the_full_migration_set(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create(['title_en' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft']);

        $this->assertSame('en', $en->fresh()->locale);
        $this->assertSame('fr', $fr->fresh()->locale);
    }

    public function test_the_column_default_is_still_en(): void
    {
        $post = Post::create(['title_en' => 'No locale given', 'status' => 'draft']);

        $this->assertSame('en', $post->fresh()->locale);
    }

    public function test_the_locale_slug_unique_index_still_holds(): void
    {
        $table = $this->table();

        DB::table($table)->insert([
            'locale' => 'en', 'title_en' => 'First', 'slug' => 'same-slug',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table($table)->insert([
            'locale' => 'en', 'title_en' => 'Second', 'slug' => 'same-slug',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_column_no_longer_rejects_a_third_locale_at_the_db_level(): void
    {
        // The whole point of the migration: enforcement moved to the application layer
        // (Heisenberg\Support\LocaleConfig / Rule::in() call sites), so the bare column
        // itself is now permissive. This is not an invitation to skip app-level
        // validation on any write path — see the migration's own docblock and this
        // task's inventory of every validated call site.
        $table = $this->table();

        DB::table($table)->insert([
            'locale' => 'de', 'title_en' => 'German test row', 'slug' => 'german-row',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('de', DB::table($table)->where('slug', 'german-row')->value('locale'));

        DB::table($table)->where('slug', 'german-row')->delete();
    }

    public function test_rollback_restores_db_level_enforcement_and_preserves_existing_data(): void
    {
        $table = $this->table();

        $en = Post::create(['title_en' => 'Keep Me EN', 'locale' => 'en', 'slug' => 'keep-me-en', 'status' => 'draft']);
        $fr = Post::create(['title_en' => 'Keep Me FR', 'locale' => 'fr', 'slug' => 'keep-me-fr', 'status' => 'draft']);

        $this->migration()->down();

        // Data survives the SQLite table rebuild triggered by down().
        $this->assertSame('en', DB::table($table)->where('id', $en->id)->value('locale'));
        $this->assertSame('fr', DB::table($table)->where('id', $fr->id)->value('locale'));
        $this->assertSame('keep-me-en', DB::table($table)->where('id', $en->id)->value('slug'));

        // DB-level enforcement is genuinely back, not just the column's type label.
        try {
            DB::table($table)->insert([
                'locale' => 'de', 'title_en' => 'Should be rejected', 'slug' => 'rejected-row',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Expected the restored CHECK constraint to reject an out-of-range locale.');
        } catch (QueryException) {
            // expected
        }

        // The default survives the round trip too.
        $id = DB::table($table)->insertGetId([
            'title_en' => 'Default check', 'slug' => 'default-check',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('en', DB::table($table)->where('id', $id)->value('locale'));

        // Re-applying restores the permissive column, the unique index, and keeps all data.
        $this->migration()->up();

        $this->assertSame('en', DB::table($table)->where('id', $en->id)->value('locale'));
        $this->assertSame('fr', DB::table($table)->where('id', $fr->id)->value('locale'));
        $this->assertSame('en', DB::table($table)->where('id', $id)->value('locale'));

        DB::table($table)->insert([
            'locale' => 'de', 'title_en' => 'Allowed again', 'slug' => 'allowed-again',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('de', DB::table($table)->where('slug', 'allowed-again')->value('locale'));

        $this->expectException(QueryException::class);
        DB::table($table)->insert([
            'locale' => 'en', 'title_en' => 'Duplicate slug', 'slug' => 'keep-me-en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_rollback_refuses_when_a_row_holds_a_locale_outside_the_original_pair(): void
    {
        $table = $this->table();

        DB::table($table)->insert([
            'locale' => 'de', 'title_en' => 'Out of range', 'slug' => 'out-of-range',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/de/');

        $this->migration()->down();
    }
}
