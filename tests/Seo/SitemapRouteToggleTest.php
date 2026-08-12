<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * Pins `config('heisenberg.seo.sitemap')` as the load-time opt-out for routes/seo.php
 * (`HeisenbergServiceProvider::registerSeoRoutes()`) — same technique
 * `CommentRoutesToggleTest` uses for routes/comments.php: the flag has to be set BEFORE the
 * service provider boots, so this lives in its own class with a getEnvironmentSetUp() override.
 */
class SitemapRouteToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.seo.sitemap', false);
    }

    public function test_the_sitemap_route_is_absent_when_disabled(): void
    {
        $this->get('/sitemap.xml')->assertStatus(404);

        $this->assertFalse(
            Route::has('heisenberg.seo.sitemap'),
            'the sitemap route must not be registered when heisenberg.seo.sitemap is false'
        );
    }
}
