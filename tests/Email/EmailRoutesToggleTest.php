<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * Pins `config('heisenberg.email.routes')` as the load-time opt-out for the whole routes/email.php
 * file (registerEmailRoutes() in HeisenbergServiceProvider, docs/email-system.md §6.1). Turning the
 * group off removes an email's public address — and must therefore ALSO close the editor's
 * id-scoped preview/export routes, which exist only to redirect into it. If they still rendered,
 * a host that deliberately disabled email serving would be left with a second way in.
 *
 * The config value has to be set BEFORE the service provider boots — flipping it mid-test would
 * have no effect on routes already registered — so this lives in its own class with a
 * getEnvironmentSetUp() override, the same technique CommentRoutesToggleTest uses.
 */
class EmailRoutesToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.email.routes', false);
    }

    private function makeEmail(): Post
    {
        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en', 'status' => 'published']);
        $post->type = 'email';
        $post->save();

        return $post;
    }

    public function test_the_slug_routes_are_absent_when_disabled(): void
    {
        $post = $this->makeEmail();

        $this->get("/emails/{$post->slug}")->assertNotFound();
        $this->get("/emails/{$post->slug}/export?format=html")->assertNotFound();

        $this->assertFalse(
            Route::has('heisenberg.email.show'),
            'the served-email route must not be registered when heisenberg.email.routes is false'
        );
    }

    public function test_the_editors_id_scoped_routes_404_rather_than_rendering_the_email_themselves(): void
    {
        $post = $this->makeEmail();

        $this->get("/editor/{$post->id}/email-preview")->assertNotFound();
        $this->get("/editor/{$post->id}/email-export?format=html")->assertNotFound();
        $this->get("/editor/{$post->id}/email-export?format=eml")->assertNotFound();
    }
}
