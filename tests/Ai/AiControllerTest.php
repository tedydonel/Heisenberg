<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Contracts\AiCredentialStore;
use Heisenberg\Tests\TestCase;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Illuminate\Support\Facades\Http;

/**
 * The AI settings API: providers, models, credentials and model discovery.
 *
 * The assertion that matters most on this surface is that **no response ever
 * contains key material**. A key can be written and it can be reported as
 * present, but there is deliberately no path that reads one back.
 */
class AiControllerTest extends TestCase
{
    private string $path;

    private string $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $unique = uniqid('', true);
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "hb-ai-http-{$unique}.json";
        $this->credentials = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "hb-ai-cred-{$unique}.json";

        config([
            'heisenberg.ai.settings_path' => $this->path,
            'heisenberg.ai.credentials_path' => $this->credentials,
            'cache.default' => 'array',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->credentials);
        putenv('HB_TEST_OPENAI');
        unset($_ENV['HB_TEST_OPENAI'], $_SERVER['HB_TEST_OPENAI']);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function oneProvider(): array
    {
        return [
            'providers' => [[
                'id' => 'openai', 'label' => 'OpenAI', 'format' => 'openai',
                'base_url' => 'https://api.openai.com/v1', 'key_env' => 'HB_TEST_OPENAI',
            ]],
            'models' => [['id' => 'gpt-5', 'provider' => 'openai', 'enabled' => true, 'effort' => 'high']],
            'active_model' => 'openai:gpt-5',
        ];
    }

    private function asAdmin(): self
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        return $this;
    }

    public function test_settings_read_is_open_and_starts_empty(): void
    {
        $this->app['env'] = 'testing';

        $this->getJson('/editor/ai/settings')
            ->assertOk()
            ->assertJsonPath('settings.providers', [])
            ->assertJsonPath('settings.models', [])
            ->assertJsonPath('mcp.server_enabled', false);
    }

    /** Presets are seeds an operator can add in one click, not a fixed list. */
    public function test_known_providers_are_offered_as_presets(): void
    {
        $this->app['env'] = 'testing';

        $ids = array_column($this->getJson('/editor/ai/settings')->json('presets'), 'id');

        $this->assertContains('openai', $ids);
        $this->assertContains('anthropic', $ids);
        // A vendor is a provider, not a format — several share the openai shape.
        $this->assertContains('xai', $ids);
        $this->assertContains('ollama', $ids);
    }

    public function test_both_api_formats_are_offered(): void
    {
        $this->app['env'] = 'testing';

        $formats = $this->getJson('/editor/ai/settings')->json('formats');

        $this->assertArrayHasKey('openai', $formats);
        $this->assertArrayHasKey('anthropic', $formats);
    }

    public function test_an_admin_can_add_a_provider_and_its_models(): void
    {
        $this->asAdmin();

        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])
            ->assertOk()
            ->assertJson(['saved' => true])
            ->assertJsonPath('active', 'openai:gpt-5');

        $this->getJson('/editor/ai/settings')
            ->assertJsonPath('settings.providers.0.id', 'openai')
            ->assertJsonPath('settings.models.0.id', 'gpt-5');
    }

    public function test_a_guest_in_a_non_local_environment_cannot_change_the_configuration(): void
    {
        $this->app['env'] = 'testing';

        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertStatus(403);
    }

    // ── credentials ─────────────────────────────────────────────────────────

    public function test_a_key_can_be_written_and_reported_but_never_read_back(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertOk();

        $response = $this->putJson('/editor/ai/providers/openai/key', ['key' => 'sk-super-secret-value'])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $providers = collect($response->json('providers'))->keyBy('id');
        $this->assertTrue($providers['openai']['has_key']);
        $this->assertTrue($providers['openai']['connected']);

        // The whole point: present, but not readable.
        $this->assertStringNotContainsString('sk-super-secret-value', $response->getContent());
        $this->assertStringNotContainsString(
            'sk-super-secret-value',
            $this->getJson('/editor/ai/settings')->getContent(),
        );
    }

    public function test_a_stored_key_is_encrypted_at_rest(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertOk();
        $this->putJson('/editor/ai/providers/openai/key', ['key' => 'sk-super-secret-value'])->assertOk();

        $this->assertFileExists($this->credentials);
        $this->assertStringNotContainsString('sk-super-secret-value', file_get_contents($this->credentials));
        // ...and still usable by the adapters.
        $this->assertSame('sk-super-secret-value', app(AiCredentialStore::class)->get('openai', null));
    }

    /** An empty key is how the UI clears one. */
    public function test_an_empty_key_clears_the_stored_credential(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertOk();
        $this->putJson('/editor/ai/providers/openai/key', ['key' => 'sk-a'])->assertOk();

        $response = $this->putJson('/editor/ai/providers/openai/key', ['key' => '  '])->assertOk();

        $this->assertFalse(collect($response->json('providers'))->keyBy('id')['openai']['has_key']);
    }

    /**
     * The environment stays the safer, higher-priority source: a key pasted into
     * the UI must not silently shadow one deployed through config management.
     */
    public function test_an_environment_variable_outranks_a_stored_key(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertOk();
        $this->putJson('/editor/ai/providers/openai/key', ['key' => 'sk-from-ui'])->assertOk();

        putenv('HB_TEST_OPENAI=sk-from-env');
        $_ENV['HB_TEST_OPENAI'] = 'sk-from-env';
        $_SERVER['HB_TEST_OPENAI'] = 'sk-from-env';

        $this->assertSame('sk-from-env', app(AiCredentialStore::class)->get('openai', 'HB_TEST_OPENAI'));

        $providers = collect($this->getJson('/editor/ai/settings')->json('providers'))->keyBy('id');
        $this->assertTrue($providers['openai']['key_from_env']);
    }

    public function test_writing_a_key_requires_the_admins_tier(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'employee_l1'));

        $this->putJson('/editor/ai/providers/openai/key', ['key' => 'sk-nope'])->assertStatus(403);
        $this->assertFileDoesNotExist($this->credentials);
    }

    public function test_a_key_for_an_unknown_provider_is_refused(): void
    {
        $this->asAdmin();

        $this->putJson('/editor/ai/providers/ghost/key', ['key' => 'sk-nope'])->assertStatus(404);
    }

    /**
     * Regression: adding a custom vendor and immediately setting its key used to
     * answer "Unknown provider 'minimax'", because the key endpoint resolves the
     * provider from the SAVED settings while the modal had only added it to
     * client-side state. The dialog now persists on add — this proves the
     * server half of that contract holds for a provider it has never seen in
     * config (no preset, no env var).
     */
    public function test_a_custom_vendor_can_be_saved_then_immediately_given_a_key(): void
    {
        $this->asAdmin();

        $this->putJson('/editor/ai/settings', ['settings' => [
            'providers' => [[
                'id' => 'minimax', 'label' => 'MiniMax', 'format' => 'openai',
                'base_url' => 'https://api.minimax.io/v1',
            ]],
        ]])->assertOk()->assertJson(['saved' => true]);

        $response = $this->putJson('/editor/ai/providers/minimax/key', ['key' => 'sk-minimax-live'])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $providers = collect($response->json('providers'))->keyBy('id');
        $this->assertTrue($providers['minimax']['has_key']);
        $this->assertTrue($providers['minimax']['connected']);
        $this->assertStringNotContainsString('sk-minimax-live', $response->getContent());
    }

    /** And discovery works against it too, with no preset or env var involved. */
    public function test_a_custom_vendor_can_discover_its_own_models(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => [
            'providers' => [[
                'id' => 'minimax', 'label' => 'MiniMax', 'format' => 'openai',
                'base_url' => 'https://api.minimax.io/v1',
            ]],
        ]])->assertOk();
        $this->putJson('/editor/ai/providers/minimax/key', ['key' => 'sk-minimax-live'])->assertOk();

        Http::fake(['*' => Http::response(['data' => [['id' => 'MiniMax-M2']]])]);

        $this->postJson('/editor/ai/providers/minimax/discover')
            ->assertOk()
            ->assertJsonPath('models', ['MiniMax-M2']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.minimax.io/v1/models');
    }

    // ── discovery ───────────────────────────────────────────────────────────

    /**
     * Models come from the vendor's own endpoint. A catalogue shipped in this
     * package would be wrong the week a model launches.
     */
    public function test_models_are_discovered_from_the_providers_own_endpoint(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertOk();
        $this->putJson('/editor/ai/providers/openai/key', ['key' => 'sk-a'])->assertOk();

        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'gpt-5'], ['id' => 'gpt-5-mini'], ['id' => 'o4'],
        ]])]);

        $this->postJson('/editor/ai/providers/openai/discover')
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('models', ['gpt-5', 'gpt-5-mini', 'o4']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/models'));
    }

    public function test_discovery_without_a_key_explains_itself(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertOk();
        Http::fake();

        $this->postJson('/editor/ai/providers/openai/discover')->assertStatus(422)->assertJson(['ok' => false]);
        Http::assertNothingSent();
    }

    /** Some gateways simply do not implement /models; that is not an error. */
    public function test_a_provider_that_lists_nothing_reports_empty_not_failed(): void
    {
        $this->asAdmin();
        $this->putJson('/editor/ai/settings', ['settings' => $this->oneProvider()])->assertOk();
        $this->putJson('/editor/ai/providers/openai/key', ['key' => 'sk-a'])->assertOk();

        Http::fake(['*' => Http::response(['data' => []])]);

        $this->postJson('/editor/ai/providers/openai/discover')
            ->assertOk()
            ->assertJson(['ok' => true, 'empty' => true]);
    }

    public function test_discovery_requires_the_admins_tier(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'employee_l1'));

        $this->postJson('/editor/ai/providers/openai/discover')->assertStatus(403);
    }
}
