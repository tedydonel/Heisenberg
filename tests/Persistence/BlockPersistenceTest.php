<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Persistence smoke tests: the posts/blocks schema, JSON content cast,
 * ordering, and cascade delete.
 *
 * Runs on the suite's default in-memory SQLite connection (§4.16 remediation —
 * this used to hard-require a local MySQL server, so these 4 tests errored in
 * any environment without one; the schema here — uuid/enum/json/timestamps/
 * softDeletes + a cascading FK — is fully representable on SQLite via
 * Laravel's grammar). If a host CI opts into MySQL (DB_CONNECTION=mysql, for
 * blueprint Rule 11's fuller future schema — taxonomy, revisions, patterns)
 * but the server isn't reachable, this class skips gracefully instead of
 * erroring.
 */
class BlockPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') === 'mysql' && ! self::mysqlIsReachable()) {
            $this->markTestSkipped(sprintf(
                'DB_CONNECTION=mysql but no server is reachable at %s:%s — the default local '
                . 'run uses SQLite (see TestCase::getEnvironmentSetUp()); this skip only fires '
                . 'when MySQL is explicitly requested via phpunit.xml.',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
            ));

            return;
        }

        parent::setUp();
    }

    /** A raw, app-independent reachability probe — safe to call before the app boots. */
    private static function mysqlIsReachable(): bool
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
                env('DB_DATABASE', 'heisenberg_test'),
            );
            new \PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', ''), [\PDO::ATTR_TIMEOUT => 1]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_post_auto_assigns_uuid_and_slug(): void
    {
        $post = Post::create(['title_en' => 'The Quiet Craft', 'status' => 'draft']);

        $this->assertNotEmpty($post->translation_group_id);
        $this->assertSame('the-quiet-craft', $post->slug);
        $this->assertSame(0, $post->fresh()->content_version);
    }

    public function test_blocks_persist_with_json_content_and_order(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Hi']], 'order' => 1]);
        $post->blocks()->create(['type' => 'heading', 'content' => ['name' => 'heisenberg/heading'], 'order' => 0]);

        $blocks = $post->blocks()->get();

        $this->assertCount(2, $blocks);
        $this->assertSame('heading', $blocks->first()->type); // ordered by `order` (0 first)
        $this->assertSame(
            ['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Hi']],
            $blocks->firstWhere('type', 'paragraph')->content // json array cast round-trips
        );
    }

    public function test_blocks_cascade_when_post_force_deleted(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        $postId = $post->id;

        $post->forceDelete();

        $this->assertDatabaseMissing(config('heisenberg.tables.blocks'), ['post_id' => $postId]);
    }

    public function test_content_version_bumps_atomically(): void
    {
        $post = Post::create(['title_en' => 'V', 'status' => 'draft']);

        $post->bumpContentVersion();
        $post->bumpContentVersion();

        $this->assertSame(2, $post->fresh()->content_version);
    }
}
