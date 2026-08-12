<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GET /heisenberg/posts/{post}/translations (routes/translations.php, `heisenberg.translations.index`,
 * PostTranslationsApiController) — the public, read-only translations API (docs/content-translation.md
 * §7, owner decision 2026-08-11) hosts use to build their own language-switcher UI. Runs against the
 * REAL ConfigRoleGate + PostPolicy, same posture CommentControllerTest takes for the public comments
 * endpoints — this class also depends on PostPolicy::view()'s "a published post is publicly visible"
 * behavior. {@see TranslationRoutesToggleTest} covers the `heisenberg.translations.routes` opt-out.
 */
class PostTranslationsApiTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();
    }

    /** @return array{0: Post, 1: Post} [en (published), fr (draft)], same group, same shared slug. */
    private function groupWithADraftTranslation(): array
    {
        $en = Post::create(['title_en' => 'Hello World', 'locale' => 'en', 'status' => 'published', 'slug' => 'hello-world']);
        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
        ]);

        return [$en, $fr];
    }

    // ── shape + guest visibility ────────────────────────────────────────

    public function test_guest_sees_only_the_published_row_with_the_shared_slug_and_current_flag(): void
    {
        [$en, $fr] = $this->groupWithADraftTranslation();

        $response = $this->getJson("/heisenberg/posts/{$en->id}/translations");

        $response->assertOk();
        $this->assertSame('hello-world', $response->json('slug'));
        $this->assertSame('en', $response->json('default_locale'));

        $rows = $response->json('translations');
        $this->assertCount(1, $rows, 'the draft fr sibling must be invisible to a guest');
        $this->assertSame('en', $rows[0]['locale']);
        $this->assertSame($en->id, $rows[0]['post_id']);
        $this->assertSame('published', $rows[0]['status']);
        $this->assertTrue($rows[0]['current']);

        $this->assertFalse(
            collect($rows)->contains('locale', 'fr'),
            'the fr row must not appear at all for a guest'
        );
    }

    public function test_staff_sees_every_row_including_the_draft(): void
    {
        [$en, $fr] = $this->groupWithADraftTranslation();
        $this->actingAs(new FakeActor(1, 'author'));

        $response = $this->getJson("/heisenberg/posts/{$en->id}/translations");

        $response->assertOk();
        $rows = collect($response->json('translations'))->keyBy('locale');
        $this->assertCount(2, $rows);
        $this->assertSame('published', $rows['en']['status']);
        $this->assertSame('draft', $rows['fr']['status']);
        $this->assertTrue($rows['en']['current']);
        $this->assertFalse($rows['fr']['current']);
    }

    public function test_current_flag_follows_whichever_post_id_was_requested(): void
    {
        [$en, $fr] = $this->groupWithADraftTranslation();
        $this->actingAs(new FakeActor(1, 'author'));

        $response = $this->getJson("/heisenberg/posts/{$fr->id}/translations");

        $response->assertOk();
        $rows = collect($response->json('translations'))->keyBy('locale');
        $this->assertTrue($rows['fr']['current']);
        $this->assertFalse($rows['en']['current']);
    }

    public function test_an_untranslated_post_returns_only_itself(): void
    {
        $solo = Post::create(['title_en' => 'Solo Post', 'locale' => 'en', 'status' => 'published', 'slug' => 'solo-post']);

        $response = $this->getJson("/heisenberg/posts/{$solo->id}/translations");

        $response->assertOk();
        $rows = $response->json('translations');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['current']);
    }

    // ── denial / not-found ────────────────────────────────────────────────

    public function test_an_unknown_post_is_404(): void
    {
        $this->getJson('/heisenberg/posts/999999/translations')->assertStatus(404);
    }

    public function test_a_draft_post_is_entirely_invisible_to_a_guest(): void
    {
        $draft = Post::create(['title_en' => 'A Draft Post', 'locale' => 'en', 'status' => 'draft', 'slug' => 'a-draft-post']);

        $this->getJson("/heisenberg/posts/{$draft->id}/translations")->assertStatus(403);
    }
}
