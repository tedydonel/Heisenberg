<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The topbar's house icon used to be a bare `<button>` with no href, no data attribute and no
 * click handler anywhere in topbar/script.blade.php — it looked like a control and did nothing.
 *
 * It now links to the host's public site. The two states below are both deliberate: given a site
 * URL it is a real link, and given none it stays inert rather than linking to the editor's own
 * host, which is an admin subdomain in most installs and not where "home" means to go.
 */
class TopbarHomeButtonTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    private function editorHtml(): string
    {
        return $this->get('/editor')->assertOk()->getContent();
    }

    public function test_it_links_to_the_configured_public_site(): void
    {
        config(['heisenberg.site_url' => 'https://example.com']);

        $html = $this->editorHtml();

        $this->assertElementExists($html, 'a[data-hb-home]');
        $this->assertSame('https://example.com', $this->hbAttr($html, 'a[data-hb-home]', 'href'));
    }

    public function test_a_trailing_slash_does_not_reach_the_markup(): void
    {
        config(['heisenberg.site_url' => 'https://example.com/']);

        $this->assertSame('https://example.com', $this->hbAttr($this->editorHtml(), 'a[data-hb-home]', 'href'));
    }

    public function test_it_falls_back_to_the_framework_url(): void
    {
        config(['heisenberg.site_url' => null, 'app.url' => 'https://example.com']);

        $this->assertSame('https://example.com', $this->hbAttr($this->editorHtml(), 'a[data-hb-home]', 'href'));
    }

    /**
     * Nothing configured — including Laravel's own `http://localhost` default, which is not a
     * public site — leaves the control inert. An href here would be a link to the editor host.
     */
    public function test_with_no_public_site_it_stays_inert(): void
    {
        config(['heisenberg.site_url' => null, 'app.url' => 'http://localhost']);

        $html = $this->editorHtml();

        $this->assertElementMissing($html, 'a[data-hb-home]');
        $this->assertElementExists($html, '.hb-topbar__zone--left button.hb-topbar__btn');
    }
}
