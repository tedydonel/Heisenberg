<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Support\SiteUrl;
use Heisenberg\Tests\TestCase;

/**
 * The public site address is CONFIGURED, never detected.
 *
 * Heisenberg is commonly mounted inside an admin or staff dashboard on its own subdomain, so the
 * request the editor renders under says `admin.example.com` while readers are on `example.com`.
 * Nothing in that request distinguishes the two, which means any host derived from it is a guess
 * that is wrong precisely in the setups that matter. These pin the resolution order and, just as
 * importantly, that an unconfigured install answers "I don't know" instead of inventing a domain.
 */
class SiteUrlTest extends TestCase
{
    public function test_the_heisenberg_key_wins(): void
    {
        config(['heisenberg.site_url' => 'https://example.com', 'app.url' => 'https://admin.example.com']);

        $this->assertSame('https://example.com', SiteUrl::base());
        $this->assertSame('example.com', SiteUrl::host());
    }

    public function test_it_falls_back_to_the_framework_url(): void
    {
        config(['heisenberg.site_url' => null, 'app.url' => 'https://example.com']);

        $this->assertSame('https://example.com', SiteUrl::base());
    }

    public function test_a_trailing_slash_never_survives(): void
    {
        config(['heisenberg.site_url' => 'https://example.com/']);

        $this->assertSame('https://example.com', SiteUrl::base());
    }

    /**
     * Laravel ships `APP_URL=http://localhost`. Treating that as a real public site would put
     * "localhost" in a canonical tag and in the social card preview — worse than no answer.
     */
    public function test_the_framework_localhost_default_is_not_a_public_site(): void
    {
        config(['heisenberg.site_url' => null, 'app.url' => 'http://localhost']);

        $this->assertSame('', SiteUrl::base());
        $this->assertSame('', SiteUrl::host());
    }

    public function test_nothing_configured_yields_nothing(): void
    {
        config(['heisenberg.site_url' => null, 'app.url' => null]);

        $this->assertSame('', SiteUrl::base());
        $this->assertSame('', SiteUrl::host());
    }

    public function test_rebase_moves_the_host_and_keeps_the_path(): void
    {
        config(['heisenberg.site_url' => 'https://example.com']);

        $this->assertSame(
            'https://example.com/posts/fr/bonjour',
            SiteUrl::rebase('https://admin.example.com/posts/fr/bonjour'),
        );
    }

    public function test_rebase_keeps_query_and_fragment(): void
    {
        config(['heisenberg.site_url' => 'https://example.com']);

        $this->assertSame(
            'https://example.com/posts/en/hi?page=2#top',
            SiteUrl::rebase('https://admin.example.com/posts/en/hi?page=2#top'),
        );
    }

    public function test_rebase_is_a_no_op_when_no_site_is_configured(): void
    {
        config(['heisenberg.site_url' => null, 'app.url' => null]);

        $this->assertSame(
            'https://admin.example.com/posts/en/hi',
            SiteUrl::rebase('https://admin.example.com/posts/en/hi'),
        );
    }
}
