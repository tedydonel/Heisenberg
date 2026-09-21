<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Services\AiSettingsRepository;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The composer's model picker has to take effect on the very next message.
 *
 * Every AI call carries a `provider:id` model key from the picker, but the endpoints
 * used to hand the work to the registry's ACTIVE adapter no matter what that key said.
 * Picking a model belonging to another provider therefore changed only the id in the
 * payload: the request still went to whichever provider was marked "in use" in AI
 * settings, which either rejected the unknown id or quietly answered with its own
 * default. From the author's seat the picker looked purely cosmetic — the only way to
 * really switch was the settings modal plus a page reload.
 *
 * These pin the routing: the adapter is chosen from the SAME model the request
 * resolves to, so the picked model's provider is the one that gets called.
 */
class AiModelRoutingTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutCsrfProtection();

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-ai-routing-' . uniqid('', true) . '.json';
        config([
            'heisenberg.ai.settings_path' => $this->path,
            'cache.default' => 'array',
        ]);

        foreach (['HB_ROUTE_ANTHROPIC' => 'sk-ant-routing', 'HB_ROUTE_OPENAI' => 'sk-oai-routing'] as $env => $value) {
            putenv("{$env}={$value}");
            $_ENV[$env] = $value;
            $_SERVER[$env] = $value;
        }

        // Two providers, two enabled models. Anthropic is the one "in use"; the OpenAI
        // model is reachable only by picking it in the composer.
        (new AiSettingsRepository($this->path))->save([
            'providers' => [
                [
                    'id' => 'anthropic', 'label' => 'Anthropic', 'format' => 'anthropic',
                    'base_url' => 'https://api.anthropic.com', 'key_env' => 'HB_ROUTE_ANTHROPIC',
                ],
                [
                    'id' => 'openai', 'label' => 'OpenAI', 'format' => 'openai',
                    'base_url' => 'https://api.openai.com/v1', 'key_env' => 'HB_ROUTE_OPENAI',
                ],
            ],
            'models' => [
                ['id' => 'claude-opus-5', 'provider' => 'anthropic', 'enabled' => true],
                ['id' => 'gpt-5', 'provider' => 'openai', 'enabled' => true],
            ],
            'active_model' => 'anthropic:claude-opus-5',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        foreach (['HB_ROUTE_ANTHROPIC', 'HB_ROUTE_OPENAI'] as $env) {
            putenv($env);
            unset($_ENV[$env], $_SERVER[$env]);
        }
        parent::tearDown();
    }

    private function fakeBothVendors(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-opus-5',
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'from anthropic']],
            ]),
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-5',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'from openai'], 'finish_reason' => 'stop']],
            ]),
        ]);
    }

    /** The host of every outbound call the endpoint made. */
    private function calledHosts(): array
    {
        $hosts = [];
        foreach (Http::recorded() as [$request]) {
            $hosts[] = parse_url($request->url(), PHP_URL_HOST);
        }

        return array_values(array_unique($hosts));
    }

    public function test_picking_a_model_from_another_provider_calls_that_provider(): void
    {
        $this->actingAs(new FakeActor(1, 'author'));
        $this->fakeBothVendors();

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'write a heading',
            'model' => 'openai:gpt-5',
        ])->assertOk();

        $this->assertSame(
            ['api.openai.com'],
            $this->calledHosts(),
            'the picked model belongs to openai, so anthropic must not be called at all',
        );
    }

    public function test_sending_no_model_still_uses_the_active_one(): void
    {
        $this->actingAs(new FakeActor(1, 'author'));
        $this->fakeBothVendors();

        $this->postJson('/editor/ai/complete', ['prompt' => 'write a heading'])->assertOk();

        $this->assertSame(['api.anthropic.com'], $this->calledHosts());
    }

    public function test_an_unknown_model_key_falls_back_to_the_active_provider(): void
    {
        $this->actingAs(new FakeActor(1, 'author'));
        $this->fakeBothVendors();

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'write a heading',
            'model' => 'nowhere:not-a-model',
        ])->assertOk();

        $this->assertSame(['api.anthropic.com'], $this->calledHosts());
    }

    public function test_a_disabled_model_does_not_route_to_its_provider(): void
    {
        (new AiSettingsRepository($this->path))->save([
            'providers' => [
                [
                    'id' => 'anthropic', 'label' => 'Anthropic', 'format' => 'anthropic',
                    'base_url' => 'https://api.anthropic.com', 'key_env' => 'HB_ROUTE_ANTHROPIC',
                ],
                [
                    'id' => 'openai', 'label' => 'OpenAI', 'format' => 'openai',
                    'base_url' => 'https://api.openai.com/v1', 'key_env' => 'HB_ROUTE_OPENAI',
                ],
            ],
            'models' => [
                ['id' => 'claude-opus-5', 'provider' => 'anthropic', 'enabled' => true],
                ['id' => 'gpt-5', 'provider' => 'openai', 'enabled' => false],
            ],
            'active_model' => 'anthropic:claude-opus-5',
        ]);

        $this->actingAs(new FakeActor(1, 'author'));
        $this->fakeBothVendors();

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'write a heading',
            'model' => 'openai:gpt-5',
        ])->assertOk();

        $this->assertSame(
            ['api.anthropic.com'],
            $this->calledHosts(),
            'a model the operator switched off must not become reachable through the picker',
        );
    }
}
