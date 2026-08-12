<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance coverage for `GET /editor/posts/{post}/seo/analyze` (routes/editor.php,
 * `heisenberg.editor.seo.analyze`, {@see \Heisenberg\Http\Controllers\SeoAnalysisController}) —
 * response shape, locale validation/default, `o_*` query overrides reaching
 * {@see \Heisenberg\Services\SeoAnalyzer}, and the same PostPolicy::view() gate
 * `CommentControllerTest`/`PreviewSeoTest` already pin (a draft stays invisible to a guest).
 */
class SeoAnalysisEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function publishedPost(array $overrides = []): Post
    {
        return Post::create(array_merge(['title_en' => 'A published post', 'status' => 'published', 'locale' => 'en'], $overrides));
    }

    private function draftPost(array $overrides = []): Post
    {
        return Post::create(array_merge(['title_en' => 'A draft post', 'status' => 'draft', 'locale' => 'en'], $overrides));
    }

    public function test_returns_the_expected_shape(): void
    {
        $post = $this->publishedPost();

        $response = $this->getJson("/editor/posts/{$post->id}/seo/analyze")->assertOk();

        $response->assertJsonStructure([
            'score', 'rating', 'checks' => [
                '*' => ['id', 'group', 'status', 'weight', 'message', 'message_key', 'params'],
            ],
        ]);
        $this->assertIsInt($response->json('score'));
        $this->assertContains($response->json('rating'), ['poor', 'needs-work', 'good', 'excellent']);
    }

    public function test_locale_defaults_to_the_posts_own_locale(): void
    {
        $post = $this->publishedPost(['locale' => 'fr', 'title_fr' => 'Titre français', 'title_en' => '']);
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey(), 'focus_keyphrase_fr' => 'mot-clé']);

        // No ?locale= at all -- resolves to the post's own 'fr' row, so the FR-only keyphrase is
        // seen (an 'en' resolution would instead find nothing and warn keyphrase-set).
        $response = $this->getJson("/editor/posts/{$post->id}/seo/analyze")->assertOk();

        $keyphraseCheck = collect($response->json('checks'))->firstWhere('id', 'keyphrase-set');
        $this->assertSame('pass', $keyphraseCheck['status']);
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $post = $this->publishedPost();

        $this->getJson("/editor/posts/{$post->id}/seo/analyze?locale=de")->assertStatus(422);
    }

    public function test_o_star_overrides_reach_the_analyzer(): void
    {
        $post = $this->publishedPost();
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey(), 'og_image' => '']);

        $withoutOverride = $this->getJson("/editor/posts/{$post->id}/seo/analyze?locale=en")->assertOk();
        $ogCheckBefore = collect($withoutOverride->json('checks'))->firstWhere('id', 'og-image');
        $this->assertSame('warn', $ogCheckBefore['status']);

        $withOverride = $this->getJson(
            "/editor/posts/{$post->id}/seo/analyze?locale=en&o_og_image=" . urlencode('https://cdn.example/hero.jpg')
        )->assertOk();
        $ogCheckAfter = collect($withOverride->json('checks'))->firstWhere('id', 'og-image');
        $this->assertSame('pass', $ogCheckAfter['status']);
    }

    public function test_it_is_forbidden_for_a_guest_on_a_draft(): void
    {
        $post = $this->draftPost();

        $this->getJson("/editor/posts/{$post->id}/seo/analyze")->assertStatus(403);
    }

    public function test_it_404s_for_a_nonexistent_post(): void
    {
        $this->getJson('/editor/posts/999999/seo/analyze')->assertNotFound();
    }
}
