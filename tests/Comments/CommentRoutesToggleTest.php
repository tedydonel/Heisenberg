<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Comments;

use Heisenberg\Models\Post;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Pins `config('heisenberg.comments.routes')` as the load-time opt-out for the whole
 * routes/comments.php file (registerCommentRoutes() in HeisenbergServiceProvider). The
 * config value has to be set BEFORE the service provider boots — flipping it mid-test
 * would have no effect on routes already registered — so this lives in its own test
 * class with a getEnvironmentSetUp() override, same technique McpServerTest uses for
 * its own boot-time config.
 */
class CommentRoutesToggleTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.comments.routes', false);
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();
    }

    public function test_the_public_and_moderation_routes_are_absent_when_disabled(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'published']);

        $this->getJson("/heisenberg/posts/{$post->id}/comments")->assertStatus(404);
        $this->getJson('/editor/comments/data')->assertStatus(404);

        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('heisenberg.comments.thread'),
            'the thread route must not be registered when heisenberg.comments.routes is false'
        );
    }
}
