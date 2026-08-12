<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Pins `config('heisenberg.translations.routes')` as the load-time opt-out for the whole
 * routes/translations.php file (registerTranslationRoutes() in HeisenbergServiceProvider) — same
 * technique CommentRoutesToggleTest uses for routes/comments.php: the config value has to be set
 * BEFORE the service provider boots, so flipping it mid-test would have no effect on routes
 * already registered.
 */
class TranslationRoutesToggleTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.translations.routes', false);
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();
    }

    public function test_the_public_translations_route_is_absent_when_disabled(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'published']);

        $this->getJson("/heisenberg/posts/{$post->id}/translations")->assertStatus(404);

        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('heisenberg.translations.index'),
            'the translations index route must not be registered when heisenberg.translations.routes is false'
        );
    }
}
