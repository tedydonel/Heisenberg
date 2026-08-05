<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\ThemeRepository;
use Heisenberg\Tests\TestCase;
use Heisenberg\Tests\Taxonomy\FakeActor;

/**
 * Acceptance tests for the theme library API (routes/editor.php: GET/POST/DELETE
 * /editor/themes) — SavedThemeController + SavedThemeRepository. Same
 * authorization posture as ThemeController (admins-tier RoleGate, local-dev
 * GuestActor bypass) — mirrors CategoryControllerTest's pattern for exercising
 * both a real authenticated actor and the local-only bypass, but needs neither
 * RefreshDatabase nor the MySQL-reachability skip since nothing here touches
 * the database (SavedThemeRepository is file-backed, like ThemeRepository).
 */
class SavedThemeControllerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        // Isolate the container-resolved SavedThemeRepository from the real
        // storage/app/heisenberg/themes.json — without this, state written by
        // one test would leak into the next (they'd all share the same file).
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-saved-themes-http-' . uniqid('', true) . '.json';
        config(['heisenberg.saved_themes_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function theme(): array
    {
        return (new ThemeRepository())->defaults();
    }

    public function test_index_is_open_and_empty_by_default(): void
    {
        $this->app['env'] = 'testing';

        $this->getJson('/editor/themes')->assertOk()->assertJson(['themes' => []]);
    }

    public function test_a_guest_in_a_non_local_environment_is_denied(): void
    {
        $this->app['env'] = 'testing';

        $this->postJson('/editor/themes', ['name' => 'Mine', 'theme' => $this->theme()])->assertStatus(403);
    }

    public function test_a_guest_in_the_local_environment_is_allowed_via_the_dev_bypass(): void
    {
        $this->app['env'] = 'local';

        $this->postJson('/editor/themes', ['name' => 'Mine', 'theme' => $this->theme()])
            ->assertOk()
            ->assertJson(['saved' => true, 'name' => 'Mine']);
    }

    public function test_an_authors_tier_actor_is_denied(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'employee_l1'));

        $this->postJson('/editor/themes', ['name' => 'Mine', 'theme' => $this->theme()])->assertStatus(403);
    }

    public function test_an_admin_can_save_and_it_appears_in_the_index(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $this->postJson('/editor/themes', ['name' => 'Brand', 'theme' => $this->theme()])
            ->assertOk()
            ->assertJson(['saved' => true, 'name' => 'Brand']);

        $this->getJson('/editor/themes')->assertOk()->assertJsonPath('themes.0.name', 'Brand');
    }

    public function test_store_rejects_a_blank_name(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $this->postJson('/editor/themes', ['name' => '  ', 'theme' => $this->theme()])->assertStatus(422);
    }

    public function test_store_rejects_an_invalid_theme_payload(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $theme = $this->theme();
        $theme['colors'][0]['value'] = 'not-a-color';

        $this->postJson('/editor/themes', ['name' => 'Broken', 'theme' => $theme])->assertStatus(422);
        $this->getJson('/editor/themes')->assertJson(['themes' => []]);
    }

    public function test_saving_under_an_existing_name_overwrites_rather_than_duplicates(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $this->postJson('/editor/themes', ['name' => 'Brand', 'theme' => $this->theme()])->assertOk();
        $this->postJson('/editor/themes', ['name' => 'Brand', 'theme' => $this->theme()])->assertOk();

        $response = $this->getJson('/editor/themes')->assertOk();
        $this->assertCount(1, $response->json('themes'));
    }

    public function test_an_admin_can_delete_a_saved_theme(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $this->postJson('/editor/themes', ['name' => 'Brand', 'theme' => $this->theme()])->assertOk();
        $this->deleteJson('/editor/themes', ['name' => 'Brand'])->assertOk()->assertJson(['themes' => []]);
    }

    public function test_delete_is_denied_for_an_authors_tier_actor(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
        $this->postJson('/editor/themes', ['name' => 'Brand', 'theme' => $this->theme()])->assertOk();

        $this->actingAs(new FakeActor(2, 'employee_l1'));
        $this->deleteJson('/editor/themes', ['name' => 'Brand'])->assertStatus(403);

        $this->assertCount(1, $this->getJson('/editor/themes')->json('themes'));
    }
}
