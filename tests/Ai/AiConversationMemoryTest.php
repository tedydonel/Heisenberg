<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Models\AiConversation;
use Heisenberg\Models\Post;
use Heisenberg\Services\AiConversationMemory;
use Heisenberg\Services\AiSettingsRepository;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * The in-app assistant remembers its earlier conversations with an author.
 *
 * Every thread was stored, but each chat only ever saw its own history, so "do you remember our
 * last conversation?" got an honest "no". Each turn now carries a digest of the author's OTHER
 * recent threads ({@see AiConversationMemory}): built server-side, scoped to the author with the
 * same ownership rule as the history list, the current post's threads first.
 */
class AiConversationMemoryTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutCsrfProtection();
        $this->app['env'] = 'local';

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-ai-memory-' . uniqid('', true) . '.json';
        config(['heisenberg.ai.settings_path' => $this->path, 'cache.default' => 'array']);
        putenv('HB_TEST_MEMORY=sk-test');
        $_ENV['HB_TEST_MEMORY'] = 'sk-test';
        $_SERVER['HB_TEST_MEMORY'] = 'sk-test';

        (new AiSettingsRepository($this->path))->save([
            'providers' => [[
                'id' => 'anthropic', 'label' => 'Anthropic', 'format' => 'anthropic',
                'base_url' => 'https://api.anthropic.com', 'key_env' => 'HB_TEST_MEMORY',
            ]],
            'models' => [['id' => 'claude-opus-5', 'provider' => 'anthropic', 'enabled' => true, 'effort' => 'high']],
            'active_model' => 'anthropic:claude-opus-5',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv('HB_TEST_MEMORY');
        unset($_ENV['HB_TEST_MEMORY'], $_SERVER['HB_TEST_MEMORY']);
        parent::tearDown();
    }

    /** @param list<array{0: string, 1: string}> $turns */
    private function conversation(?int $authorId, ?int $postId, string $title, array $turns): AiConversation
    {
        $conversation = AiConversation::create(['author_id' => $authorId, 'post_id' => $postId, 'title' => $title]);
        foreach ($turns as [$role, $content]) {
            $conversation->messages()->create(['role' => $role, 'content' => $content]);
        }

        return $conversation;
    }

    private function digest(?int $authorId, ?int $current = null, ?int $postId = null): string
    {
        return app(AiConversationMemory::class)->digest($authorId, $current, $postId);
    }

    public function test_the_digest_recalls_what_an_earlier_conversation_said(): void
    {
        $this->conversation(null, null, 'Brand voice', [
            ['user', 'My brand voice is playful and terse.'],
            ['assistant', 'Noted: playful and terse.'],
        ]);

        $digest = $this->digest(null);

        $this->assertStringContainsString('Brand voice', $digest);
        $this->assertStringContainsString('Author: My brand voice is playful and terse.', $digest);
        $this->assertStringContainsString('You: Noted: playful and terse.', $digest);
    }

    /** One author is never reminded of another's chats: the history list's own ownership rule. */
    public function test_the_digest_only_ever_covers_the_authors_own_conversations(): void
    {
        $this->conversation(null, null, 'Mine', [['user', 'guest secret']]);
        $this->conversation(7, null, 'Theirs', [['user', 'author seven secret']]);

        $this->assertStringNotContainsString('author seven secret', $this->digest(null));
        $this->assertStringContainsString('author seven secret', $this->digest(7));
        $this->assertStringNotContainsString('guest secret', $this->digest(7));
    }

    public function test_the_current_conversation_is_left_out_and_this_posts_come_first(): void
    {
        $post = Post::create(['title_en' => 'P', 'locale' => 'en']);
        $current = $this->conversation(null, $post->id, 'Current', [['user', 'current thread']]);
        $this->conversation(null, $post->id, 'About this post', [['user', 'older, same post']]);
        $this->conversation(null, null, 'Elsewhere', [['user', 'newer, no post']]);

        $digest = $this->digest(null, $current->id, $post->id);

        $this->assertStringNotContainsString('current thread', $digest);
        $this->assertLessThan(strpos($digest, 'Elsewhere'), strpos($digest, 'About this post'), 'the post being edited comes first');
        $this->assertStringContainsString('(this post)', $digest);
    }

    public function test_long_turns_are_excerpted_and_a_long_thread_keeps_its_opening_and_its_end(): void
    {
        $turns = [['user', 'OPENING ' . str_repeat('word ', 200)]];
        for ($i = 1; $i <= 10; $i++) {
            $turns[] = [$i % 2 ? 'assistant' : 'user', "turn {$i}"];
        }
        $this->conversation(null, null, 'Long', $turns);

        $digest = $this->digest(null);

        $this->assertStringContainsString('OPENING', $digest);
        $this->assertStringNotContainsString(str_repeat('word ', 60), $digest, 'a long turn is excerpted');
        $this->assertStringContainsString('turn 10', $digest);
        $this->assertStringNotContainsString('turn 3', $digest, 'the middle of a long thread is skipped');
    }

    public function test_an_author_with_no_other_conversations_gets_no_digest(): void
    {
        $this->assertSame('', $this->digest(null));
    }

    /**
     * The endpoint puts the digest into the turn the model receives — and ignores any
     * `pastConversations` a client tries to supply.
     */
    public function test_the_assistant_turn_carries_the_memory_and_a_forged_one_is_ignored(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
        ])]);
        $this->conversation(null, null, 'Brand voice', [['user', 'My brand voice is playful and terse.']]);
        $current = $this->conversation(null, null, 'Now', [['user', 'hello']]);

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'Do you remember my brand voice?',
            'conversation_id' => $current->id,
            'context' => ['pastConversations' => 'FORGED MEMORY'],
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $sent = json_encode($request->data()['messages'] ?? []);
            $this->assertStringContainsString('PAST CONVERSATIONS', $sent);
            $this->assertStringContainsString('My brand voice is playful and terse.', $sent);
            $this->assertStringContainsString('never claim you have no memory', $sent);
            $this->assertStringNotContainsString('FORGED MEMORY', $sent);

            return true;
        });
    }
}
