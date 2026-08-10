<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Models\AiConversation;
use Heisenberg\Tests\TestCase;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The chat-history API: list, reopen, append, bulk delete — and above all the
 * ownership rule. Every route is scoped to the requesting author inside the
 * controller; the assertions that matter most here are the ones proving one
 * author can neither read nor delete another's threads.
 */
class AiConversationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function actingAsAuthor(int $id = 1): void
    {
        $this->actingAs(new FakeActor($id, 'author'));
    }

    public function test_a_conversation_is_created_titled_and_listed(): void
    {
        $this->actingAsAuthor();

        $id = $this->postJson('/editor/ai/conversations', [])->assertCreated()->json('id');

        $this->postJson("/editor/ai/conversations/{$id}/messages", [
            'role' => 'user',
            'content' => 'Surprise me with a post about coffee, something beautiful.',
        ])->assertCreated();
        $this->postJson("/editor/ai/conversations/{$id}/messages", [
            'role' => 'assistant',
            'content' => 'Here it is.',
            'meta' => ['applied' => ['Built 3 blocks'], 'thoughtSecs' => 4],
        ])->assertCreated();

        $list = $this->getJson('/editor/ai/conversations')->assertOk()->json('conversations');
        $this->assertCount(1, $list);
        $this->assertSame(2, $list[0]['message_count']);
        // The first user turn names the thread.
        $this->assertStringStartsWith('Surprise me with a post', $list[0]['title']);
    }

    /**
     * Filtering by the current post must not hide unattached threads: a chat
     * begun before the post's first save has post_id null, and hiding those
     * made history look permanently empty the moment a draft got its id.
     */
    public function test_the_post_filter_keeps_unattached_conversations_listed(): void
    {
        $this->actingAsAuthor();

        $mine = \Heisenberg\Models\Post::create(['title_en' => 'Mine', 'status' => 'draft'])->getKey();
        $theirs = \Heisenberg\Models\Post::create(['title_en' => 'Theirs', 'status' => 'draft'])->getKey();

        $unattached = $this->postJson('/editor/ai/conversations', [])->json('id');
        $this->postJson("/editor/ai/conversations/{$unattached}/messages", ['role' => 'user', 'content' => 'before the save']);

        $attached = $this->postJson('/editor/ai/conversations', ['post_id' => $mine])->json('id');
        $this->postJson("/editor/ai/conversations/{$attached}/messages", ['role' => 'user', 'content' => 'on my post', 'post_id' => $mine]);

        $other = $this->postJson('/editor/ai/conversations', ['post_id' => $theirs])->json('id');
        $this->postJson("/editor/ai/conversations/{$other}/messages", ['role' => 'user', 'content' => 'on the other post', 'post_id' => $theirs]);

        $list = $this->getJson('/editor/ai/conversations?post_id=' . $mine)->assertOk()->json('conversations');
        $ids = array_column($list, 'id');

        $this->assertContains($attached, $ids);
        $this->assertContains($unattached, $ids, 'a thread with no post yet must stay visible');
        $this->assertNotContains($other, $ids, "another post's thread must not leak into this filter");
    }

    public function test_show_returns_the_full_transcript_in_order(): void
    {
        $this->actingAsAuthor();

        $id = $this->postJson('/editor/ai/conversations', [])->json('id');
        $this->postJson("/editor/ai/conversations/{$id}/messages", ['role' => 'user', 'content' => 'one']);
        $this->postJson("/editor/ai/conversations/{$id}/messages", ['role' => 'assistant', 'content' => 'two', 'meta' => ['reasoning' => 'hmm']]);

        $data = $this->getJson("/editor/ai/conversations/{$id}")->assertOk()->json();
        $this->assertSame(['user', 'assistant'], array_column($data['messages'], 'role'));
        $this->assertSame('hmm', $data['messages'][1]['meta']['reasoning']);
    }

    public function test_invalid_turns_are_rejected(): void
    {
        $this->actingAsAuthor();
        $id = $this->postJson('/editor/ai/conversations', [])->json('id');

        $this->postJson("/editor/ai/conversations/{$id}/messages", ['role' => 'tool', 'content' => 'x'])->assertStatus(422);
        $this->postJson("/editor/ai/conversations/{$id}/messages", ['role' => 'user', 'content' => '  '])->assertStatus(422);
    }

    public function test_bulk_delete_removes_only_the_requested_threads(): void
    {
        $this->actingAsAuthor();

        $keep = $this->postJson('/editor/ai/conversations', [])->json('id');
        $goA = $this->postJson('/editor/ai/conversations', [])->json('id');
        $goB = $this->postJson('/editor/ai/conversations', [])->json('id');

        $this->deleteJson('/editor/ai/conversations', ['ids' => [$goA, $goB]])
            ->assertOk()->assertJson(['deleted' => 2]);

        $this->assertNotNull(AiConversation::find($keep));
        $this->assertNull(AiConversation::find($goA));
    }

    public function test_ownership_one_author_cannot_see_or_delete_anothers_threads(): void
    {
        $this->actingAsAuthor(1);
        $mine = $this->postJson('/editor/ai/conversations', [])->json('id');
        $this->postJson("/editor/ai/conversations/{$mine}/messages", ['role' => 'user', 'content' => 'private']);

        $this->actingAsAuthor(2);
        $this->assertSame([], $this->getJson('/editor/ai/conversations')->assertOk()->json('conversations'));
        $this->getJson("/editor/ai/conversations/{$mine}")->assertNotFound();
        // Deleting someone else's id reports zero rows, silently — no oracle.
        $this->deleteJson('/editor/ai/conversations', ['ids' => [$mine]])->assertOk()->assertJson(['deleted' => 0]);
        $this->assertNotNull(AiConversation::find($mine));
    }

    public function test_non_authors_are_denied(): void
    {
        $this->actingAs(new FakeActor(9, 'subscriber'));

        $this->getJson('/editor/ai/conversations')->assertForbidden();
        $this->postJson('/editor/ai/conversations', [])->assertForbidden();
    }
}
