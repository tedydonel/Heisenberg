<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `GET /heisenberg/posts/{post}/translations` (routes/translations.php, `PostTranslationsApiController`)
 * — rebuilt for the single-row translation model (docs/content-translation.md §0/§7): a
 * "translation" is `_<locale>` attribute variants on the SAME row now, so this endpoint reports
 * per-locale COMPLETENESS ({@see \Heisenberg\Services\TranslationStatusService}), not a set of
 * sibling post rows. Shape: `{default_locale, slug, translations: [{locale, complete, current}]}`.
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

    public function test_reports_per_locale_completeness_and_the_shared_slug(): void
    {
        $post = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'slug' => 'hello-world',
            'status' => 'published', 'locale' => 'en',
        ]);

        $response = $this->getJson("/heisenberg/posts/{$post->id}/translations")->assertOk();

        $response->assertJson([
            'default_locale' => 'en',
            'slug' => 'hello-world',
        ]);
        $translations = $response->json('translations');
        $byLocale = collect($translations)->keyBy('locale');

        $this->assertTrue($byLocale['en']['complete']);
        $this->assertTrue($byLocale['en']['current']);
        $this->assertTrue($byLocale['fr']['complete']);
        $this->assertFalse($byLocale['fr']['current']);
    }

    public function test_current_reflects_the_requested_locale_query_param(): void
    {
        $post = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'slug' => 'hello-world',
            'status' => 'published', 'locale' => 'en',
        ]);

        $response = $this->getJson("/heisenberg/posts/{$post->id}/translations?locale=fr")->assertOk();

        $byLocale = collect($response->json('translations'))->keyBy('locale');
        $this->assertFalse($byLocale['en']['current']);
        $this->assertTrue($byLocale['fr']['current']);
    }

    public function test_an_invalid_locale_query_param_falls_back_to_the_posts_own_locale(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'locale' => 'en']);

        $response = $this->getJson("/heisenberg/posts/{$post->id}/translations?locale=xx")->assertOk();

        $byLocale = collect($response->json('translations'))->keyBy('locale');
        $this->assertTrue($byLocale['en']['current']);
    }

    public function test_response_carries_no_urls(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'locale' => 'en']);

        $body = $this->getJson("/heisenberg/posts/{$post->id}/translations")->assertOk()->getContent();

        $this->assertStringNotContainsString('http://', (string) $body);
        $this->assertStringNotContainsString('https://', (string) $body);
    }

    public function test_a_guest_is_forbidden_from_a_draft_post(): void
    {
        $post = Post::create(['title_en' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'locale' => 'en']);

        $this->getJson("/heisenberg/posts/{$post->id}/translations")->assertStatus(403);
    }

    public function test_an_author_tier_actor_can_view_a_draft_post(): void
    {
        $post = Post::create(['title_en' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'locale' => 'en']);
        $this->actingAs(new FakeActor(1, 'author'));

        $this->getJson("/heisenberg/posts/{$post->id}/translations")->assertOk();
    }

    public function test_an_unknown_post_404s(): void
    {
        $this->getJson('/heisenberg/posts/999999/translations')->assertStatus(404);
    }
}
