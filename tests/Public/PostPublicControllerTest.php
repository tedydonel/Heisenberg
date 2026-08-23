<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Public;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The bundled opt-in public show route (routes/public.php, POST /posts/{locale}/{slug}).
 * Mirrors the editor preview's render path through BlockRenderer + SEO head — covers
 * the served / not-served matrix, locale resolution, and the `?locale=` override.
 */
class PostPublicControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `heisenberg.public.routes` is opt-in (default false) and routes are loaded once at
     * provider boot — so flipping it in setUp() is too late. Set it in
     * getEnvironmentSetUp() so the routes file is actually loaded before the request runs.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('heisenberg.public.routes', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Local-dev auth bypass so a plain unauthenticated GET (the visitor's posture)
        // can exercise the controller. Same pattern PreviewSeoTest / EditingLocaleTest use.
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function makePost(array $attrs = []): Post
    {
        // `type` is deliberately NOT in Post::$fillable — it must be assigned directly after
        // create (Post::booted()'s docblock + PostController::save()'s create-only `type`
        // handling). Default the row to 'post' via direct assignment so scopePosts() matches.
        $post = Post::create(array_merge([
            'title_en' => 'A Great Post About Widgets',
            'slug' => 'widgets',
            'status' => 'published',
            'locale' => 'en',
        ], $attrs));

        if (! isset($attrs['type'])) {
            $post->type = 'post';
            $post->save();
        }

        return $post;
    }

    private function addBlock(Post $post, int $order, string $name, array $attributes): Block
    {
        return Block::create([
            'post_id' => $post->id,
            'type' => str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name,
            'content' => [
                'id' => 'b' . $order,
                'name' => $name,
                'schemaVersion' => '1.0.0',
                'attributes' => $attributes,
                'supports' => [],
                'innerBlocks' => [],
            ],
            'order' => $order,
        ]);
    }

    public function test_published_post_renders_at_its_slug_for_its_locale(): void
    {
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/paragraph', ['content' => 'Hello world.']);

        $response = $this->get('/posts/en/widgets');

        $response->assertOk();
        $response->assertSee('Hello world.', false);
        $response->assertSee('A Great Post About Widgets');
    }

    public function test_draft_post_returns_404(): void
    {
        $this->makePost(['status' => 'draft']);

        $this->get('/posts/en/widgets')->assertNotFound();
    }

    public function test_scheduled_post_returns_404(): void
    {
        $this->makePost(['status' => 'scheduled']);

        $this->get('/posts/en/widgets')->assertNotFound();
    }

    public function test_archived_post_returns_404(): void
    {
        $this->makePost(['status' => 'archived']);

        $this->get('/posts/en/widgets')->assertNotFound();
    }

    public function test_email_document_returns_404_at_the_public_blog_url(): void
    {
        // Emails have their own dedicated surface (routes/email.php at /emails/{slug}) — the
        // scopePosts() filter in the controller must keep them off the blog URL. `type` must
        // be set via direct property (not $fillable) per Post::booted().
        $post = $this->makePost(['slug' => 'campaign-1']);
        $post->type = 'email';
        $post->save();

        $this->get('/posts/en/campaign-1')->assertNotFound();
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->makePost();

        $this->get('/posts/en/nope')->assertNotFound();
    }

    public function test_unknown_locale_returns_404(): void
    {
        $this->makePost();

        // 'de' is not in the default configured locales (en, fr).
        $this->get('/posts/de/widgets')->assertNotFound();
    }

    public function test_malformed_slug_returns_404(): void
    {
        $this->makePost();

        // Uppercase / spaces / punctuation are not valid slug shapes — the route's `where`
        // constraint rejects them outright before the controller runs.
        $this->get('/posts/en/Bad_Slug!')->assertNotFound();
    }

    public function test_query_locale_override_renders_the_target_locale_title(): void
    {
        $post = $this->makePost([
            'title_en' => 'Hello',
            'title_fr' => 'Bonjour',
            'slug' => 'hello',
            'locale' => 'en',
        ]);

        $response = $this->get('/posts/en/hello?locale=fr');

        $response->assertOk();
        $response->assertSee('Bonjour', false);
    }

    public function test_seo_meta_is_rendered_into_the_head_when_present(): void
    {
        $post = $this->makePost();
        SeoMeta::create([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->id,
            'meta_title_en' => 'Custom SEO Title',
            'meta_description_en' => 'Custom SEO description for the page.',
        ]);

        $response = $this->get('/posts/en/widgets');

        $response->assertOk();
        $response->assertSee('Custom SEO Title', false);
        $response->assertSee('Custom SEO description for the page.', false);
    }

    public function test_trashed_post_returns_404(): void
    {
        $post = $this->makePost();
        $post->delete(); // soft-delete

        $this->get('/posts/en/widgets')->assertNotFound();
    }
}
