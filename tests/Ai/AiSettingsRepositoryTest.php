<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Services\AiSettingsRepository;
use Heisenberg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AiSettingsRepository — the operator's providers, models and MCP servers.
 *
 * The structural assertion is that a **provider is a vendor, not a wire
 * format**: several providers can share the `openai` format, each with its own
 * endpoint and models, and a model always names the provider it belongs to.
 *
 * The load-bearing security assertion is that no credential can land in this
 * file. Keys live in the credential store; a provider entry may only name an
 * environment variable.
 */
class AiSettingsRepositoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-ai-settings-' . uniqid('', true) . '.json';
        config(['heisenberg.ai.settings_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function repo(): AiSettingsRepository
    {
        return new AiSettingsRepository($this->path);
    }

    /** @return array<string, mixed> */
    private function twoProviders(): array
    {
        return [
            'providers' => [
                ['id' => 'openai', 'label' => 'OpenAI', 'format' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'key_env' => 'HB_OPENAI'],
                ['id' => 'ollama', 'label' => 'Ollama', 'format' => 'openai', 'base_url' => 'http://localhost:11434/v1'],
            ],
            'models' => [
                ['id' => 'gpt-5', 'label' => 'GPT-5', 'provider' => 'openai', 'enabled' => true, 'effort' => 'high'],
                ['id' => 'llama3.1:70b', 'provider' => 'ollama', 'enabled' => true, 'effort' => 'low'],
            ],
            'active_model' => 'openai:gpt-5',
        ];
    }

    public function test_defaults_are_empty_rather_than_pretending_a_provider_exists(): void
    {
        $settings = $this->repo()->load();

        $this->assertSame([], $settings['providers']);
        $this->assertSame([], $settings['models']);
        $this->assertNull($settings['active_model']);
        $this->assertSame(AiSettingsRepository::TOOLS, $settings['tools']);
    }

    /** Two vendors, one wire format — the case the old design could not express. */
    public function test_several_providers_can_share_one_api_format(): void
    {
        $result = $this->repo()->save($this->twoProviders());
        $this->assertTrue($result['saved'], implode(' / ', $result['errors']));

        $providers = $this->repo()->providers();
        $this->assertCount(2, $providers);
        $this->assertSame(['openai', 'openai'], array_map(fn ($p) => $p->format, $providers));
        $this->assertNotSame($providers[0]->baseUrl, $providers[1]->baseUrl);
    }

    public function test_models_belong_to_providers_and_carry_their_own_effort(): void
    {
        $this->repo()->save($this->twoProviders());

        $models = $this->repo()->models();
        $this->assertSame('openai', $models[0]->provider);
        $this->assertSame('high', $models[0]->effort);
        $this->assertSame('ollama', $models[1]->provider);
        // Effort is per model, not one global setting.
        $this->assertSame('low', $models[1]->effort);
        $this->assertSame('ollama:llama3.1:70b', $models[1]->key());
    }

    public function test_the_same_model_id_can_exist_under_two_providers(): void
    {
        $payload = $this->twoProviders();
        $payload['models'][] = ['id' => 'gpt-5', 'provider' => 'ollama'];

        $result = $this->repo()->save($payload);

        $this->assertTrue($result['saved'], implode(' / ', $result['errors']));
        $this->assertCount(3, $this->repo()->models());
    }

    public function test_a_duplicate_model_under_the_same_provider_is_rejected(): void
    {
        $payload = $this->twoProviders();
        $payload['models'][] = ['id' => 'gpt-5', 'provider' => 'openai'];

        $result = $this->repo()->save($payload);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString('duplicate', implode(' ', $result['errors']));
    }

    public function test_a_model_naming_an_unknown_provider_is_rejected(): void
    {
        $payload = $this->twoProviders();
        $payload['models'][] = ['id' => 'grok-4', 'provider' => 'xai'];

        $result = $this->repo()->save($payload);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString("unknown provider 'xai'", implode(' ', $result['errors']));
    }

    public function test_an_unknown_api_format_is_rejected(): void
    {
        $result = $this->repo()->save([
            'providers' => [['id' => 'weird', 'format' => 'soap', 'base_url' => 'https://x.test']],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString('unknown API format', implode(' ', $result['errors']));
    }

    public function test_the_active_model_resolves_and_falls_back_when_disabled(): void
    {
        $this->repo()->save($this->twoProviders());
        $this->assertSame('gpt-5', $this->repo()->activeModel()->id);

        // Disabling the selected model degrades to another rather than breaking
        // the assistant outright.
        $payload = $this->twoProviders();
        $payload['models'][0]['enabled'] = false;
        $this->repo()->save($payload);

        $this->assertSame('llama3.1:70b', $this->repo()->activeModel()->id);
    }

    public function test_active_model_is_null_when_nothing_is_enabled(): void
    {
        $payload = $this->twoProviders();
        $payload['models'][0]['enabled'] = false;
        $payload['models'][1]['enabled'] = false;
        $this->repo()->save($payload);

        $this->assertNull($this->repo()->activeModel());
    }

    /** A model can legitimately be deleted while selected; that is not an error. */
    public function test_a_dangling_active_model_pointer_is_dropped_silently(): void
    {
        $payload = $this->twoProviders();
        $payload['active_model'] = 'openai:removed-model';

        $result = $this->repo()->save($payload);

        $this->assertTrue($result['saved'], implode(' / ', $result['errors']));
        $this->assertNull($result['settings']['active_model']);
    }

    public function test_a_non_http_provider_base_url_is_rejected(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x/', 'not a url', ''] as $url) {
            $result = $this->repo()->save([
                'providers' => [['id' => 'x', 'format' => 'openai', 'base_url' => $url]],
            ]);

            $this->assertFalse($result['saved'], "expected '{$url}' to be rejected");
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function credentialPayloads(): iterable
    {
        yield 'api_key on a provider' => [['providers' => [
            ['id' => 'openai', 'format' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-nope'],
        ]]];
        yield 'top-level token' => [['token' => 'sk-nope']];
        yield 'secret nested in an mcp server' => [['mcp_servers' => [
            ['id' => 'a', 'url' => 'https://a.test/mcp', 'secret' => 'xoxp-nope'],
        ]]];
    }

    /**
     * Keys go to the credential store. This file must never become one.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('credentialPayloads')]
    public function test_a_credential_shaped_payload_is_refused_outright(array $payload): void
    {
        $result = $this->repo()->save($payload);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString('credential store', implode(' ', $result['errors']));
        $this->assertFileDoesNotExist($this->path);
    }

    public function test_key_env_must_name_a_variable_not_hold_a_key(): void
    {
        $result = $this->repo()->save([
            'providers' => [[
                'id' => 'openai', 'format' => 'openai', 'base_url' => 'https://api.openai.com/v1',
                'key_env' => 'sk-proj-9f3b21c8aa',
            ]],
        ]);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString('environment variable NAME', implode(' ', $result['errors']));
    }

    public function test_a_hand_edited_file_containing_a_credential_is_discarded_on_load(): void
    {
        file_put_contents($this->path, json_encode([
            'providers' => [['id' => 'openai', 'format' => 'openai', 'base_url' => 'https://api.openai.com/v1']],
            'api_key' => 'sk-leaked',
        ]));

        $loaded = $this->repo()->load();

        $this->assertSame([], $loaded['providers'], 'the tainted file should fall back to defaults');
        $this->assertArrayNotHasKey('api_key', $loaded);
    }

    public function test_mcp_servers_still_round_trip_with_their_env_var_name(): void
    {
        $result = $this->repo()->save([
            'mcp_servers' => [[
                'id' => 'linear', 'label' => 'Linear', 'url' => 'https://mcp.linear.app/mcp',
                'auth_env' => 'HEISENBERG_MCP_LINEAR_TOKEN', 'enabled' => true, 'allowed_tools' => ['list_issues'],
            ]],
        ]);

        $this->assertTrue($result['saved'], implode(' / ', $result['errors']));
        $server = $this->repo()->mcpServers()[0];
        $this->assertTrue($server->allows('list_issues'));
        $this->assertFalse($server->allows('delete_everything'));
    }

    public function test_an_unknown_tool_is_rejected(): void
    {
        $result = $this->repo()->save(['tools' => ['fix_grammar', 'launch_missiles']]);

        $this->assertFalse($result['saved']);
        $this->assertStringContainsString('launch_missiles', implode(' ', $result['errors']));
    }
}
