<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Support\DashboardUrl;
use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The topbar's house icon is NAVIGATION — where the person editing came from — which is not the
 * same question as `site_url`, the public address readers see. A platform hands its admins and
 * its staff different dashboards, and each expects that button to return them to their own;
 * pointing everyone at the public homepage returned them to nobody's dashboard.
 *
 * `heisenberg.dashboard_url` is a string when everyone shares one, or a map keyed by the host's
 * own role names. Unset, everything below falls back to the previous behaviour exactly.
 */
class DashboardUrlTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    private function editorHtml(): string
    {
        return $this->get('/editor')->assertOk()->getContent();
    }

    public function test_a_single_url_sends_everyone_to_the_same_dashboard(): void
    {
        config(['heisenberg.dashboard_url' => 'https://example.com/admin/']);

        $this->assertSame('https://example.com/admin', DashboardUrl::forUser());
        $this->assertSame('https://example.com/admin', $this->hbAttr($this->editorHtml(), 'a[data-hb-home]', 'href'));
    }

    public function test_a_role_map_returns_each_person_to_their_own_dashboard(): void
    {
        config(['heisenberg.dashboard_url' => [
            'admin' => 'https://example.com/admin',
            'editor' => 'https://example.com/staff',
            'default' => 'https://example.com/account',
        ]]);

        $this->assertSame('https://example.com/admin', DashboardUrl::forUser(new FakeActor(1, 'admin')));
        $this->assertSame('https://example.com/staff', DashboardUrl::forUser(new FakeActor(2, 'editor')));
        // A role with no entry of its own falls to `default`.
        $this->assertSame('https://example.com/account', DashboardUrl::forUser(new FakeActor(3, 'author')));
    }

    /** Config order decides for someone holding several roles, not the order their record lists. */
    public function test_the_most_privileged_entry_wins_for_a_multi_role_user(): void
    {
        config(['heisenberg.dashboard_url' => [
            'admin' => 'https://example.com/admin',
            'editor' => 'https://example.com/staff',
        ]]);

        // Two roles, listed editor-first — the shape ConfigRoleGate reads from a Spatie-style
        // user. The admin entry still wins, because the MAP's order is the authority.
        $both = new class implements Authenticatable
        {
            /** @return string[] */
            public function getRoleNames(): array
            {
                return ['editor', 'admin'];
            }

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return 4;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return '';
            }

            public function setRememberToken($value)
            {
            }

            public function getRememberTokenName()
            {
                return '';
            }
        };

        $this->assertSame('https://example.com/admin', DashboardUrl::forUser($both));
    }

    /** No map entry and no `default` falls through to the public site, as before. */
    public function test_it_falls_back_to_the_public_site(): void
    {
        config([
            'heisenberg.dashboard_url' => ['admin' => 'https://example.com/admin'],
            'heisenberg.site_url' => 'https://example.com',
        ]);

        $this->assertSame('https://example.com', DashboardUrl::forUser(new FakeActor(5, 'author')));

        config(['heisenberg.dashboard_url' => null]);
        $this->assertSame('https://example.com', DashboardUrl::forUser());
    }

    /** Nothing configured anywhere keeps the button inert rather than linking to the editor host. */
    public function test_with_nothing_configured_the_button_stays_inert(): void
    {
        config([
            'heisenberg.dashboard_url' => null,
            'heisenberg.site_url' => null,
            'app.url' => 'http://localhost',
        ]);

        $this->assertSame('', DashboardUrl::forUser());
        $html = $this->editorHtml();
        $this->assertElementMissing($html, 'a[data-hb-home]');
        $this->assertElementExists($html, '.hb-topbar__zone--left button.hb-topbar__btn');
    }

    /** The dashboard link must not leak into SEO: that surface still answers from site_url. */
    public function test_it_does_not_change_the_seo_domain(): void
    {
        config([
            'heisenberg.dashboard_url' => 'https://admin.example.com/dashboard',
            'heisenberg.site_url' => 'https://readers.example.com',
        ]);

        $html = $this->editorHtml();

        $this->assertSame('https://admin.example.com/dashboard', $this->hbAttr($html, 'a[data-hb-home]', 'href'));
        $this->assertStringContainsString('readers.example.com', $html);
        $this->assertStringNotContainsString('admin.example.com/dashboard/', $html);
    }
}
