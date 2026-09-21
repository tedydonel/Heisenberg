<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A post's public URL must name the PUBLIC site, not whichever host served the editor.
 *
 * `route()` builds its host from the current request. When Heisenberg is mounted inside an admin
 * or staff dashboard on a subdomain — the common deployment — that produces a URL whose path is
 * right and whose host is wrong, and it lands in the sitemap, the canonical tag and every
 * hreflang alternate. Rebasing onto `heisenberg.site_url` is what stops that.
 *
 * `heisenberg.public.routes` is opt-in and its routes load once at provider boot, so it has to be
 * set in getEnvironmentSetUp() rather than setUp().
 */
class SeoUrlRebaseTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('heisenberg.public.routes', true);
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::create(array_merge(
            ['title_en' => 'A post', 'status' => 'published', 'locale' => 'en', 'slug' => 'hello'],
            $attributes,
        ));
    }

    public function test_the_public_route_is_rebased_onto_the_configured_site(): void
    {
        config(['heisenberg.site_url' => 'https://example.com', 'heisenberg.seo.url_template' => null]);
        $post = $this->makePost(['locale' => 'fr', 'slug' => 'bonjour']);

        $this->assertSame(
            'https://example.com/posts/fr/bonjour',
            $this->app->make(PostUrlResolver::class)->url($post),
        );
    }

    public function test_without_a_site_url_the_route_host_is_left_alone(): void
    {
        config(['heisenberg.site_url' => null, 'app.url' => null, 'heisenberg.seo.url_template' => null]);
        $post = $this->makePost(['slug' => 'hello']);

        $this->assertSame(
            route('heisenberg.public.posts.show', ['locale' => 'en', 'slug' => 'hello']),
            $this->app->make(PostUrlResolver::class)->url($post),
        );
    }

    /** An explicit template is the host's own answer and must not be second-guessed. */
    public function test_an_explicit_template_still_wins_over_the_site_url(): void
    {
        config([
            'heisenberg.site_url' => 'https://example.com',
            'heisenberg.seo.url_template' => 'https://news.example.org/{locale}/{slug}',
        ]);
        $post = $this->makePost(['locale' => 'en', 'slug' => 'hello']);

        $this->assertSame(
            'https://news.example.org/en/hello',
            $this->app->make(PostUrlResolver::class)->url($post),
        );
    }
}
