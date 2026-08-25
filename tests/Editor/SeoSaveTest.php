<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for the SEO/Social panel's save path (docs/seo-system.md §3, Wave S2a):
 * SavePostRequest's `seo` validation + PostController::applySeo()/seoPayload(). Same local-dev
 * authorization bypass pattern as PostPersistenceTest (which this file mirrors the envelope()/
 * block() fixture helpers of, self-contained per this file tree's own convention).
 */
class SeoSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function registry(): BlockRegistryService
    {
        return app(BlockRegistryService::class);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function envelope(array $blocks, array $overrides = []): array
    {
        return array_merge([
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'computedStyles' => '',
            'autosave' => false,
            'blocks' => $blocks,
            'title_en' => 'A Test Post',
            'locale' => 'en',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function block(string $name, array $attributes = [], array $overrides = []): array
    {
        return array_merge([
            'id' => 'b1',
            'name' => $name,
            'schemaVersion' => '1.0.0',
            'attributes' => $attributes,
            'supports' => [],
            'innerBlocks' => [],
        ], $overrides);
    }

    private function seoRowFor(Post $post): ?SeoMeta
    {
        return SeoMeta::query()
            ->where('able_type', $post->getMorphClass())
            ->where('able_id', $post->getKey())
            ->first();
    }

    public function test_seo_riding_the_first_save_creates_a_seo_meta_row_on_the_own_locale_columns(): void
    {
        $response = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Seo Post',
                'seo' => [
                    'meta_title' => 'A great title',
                    'meta_description' => 'A great description',
                    'focus_keyphrase' => 'sourdough starter',
                ],
            ],
        ));

        $response->assertCreated();
        $postId = $response->json('post.id');
        $post = Post::find($postId);

        $row = $this->seoRowFor($post);
        $this->assertNotNull($row);
        $this->assertSame('A great title', $row->meta_title_en);
        $this->assertSame('A great description', $row->meta_description_en);
        $this->assertSame('sourdough starter', $row->focus_keyphrase_en);
        $this->assertNull($row->meta_title_fr);
        // Untouched keys keep SeoMeta's own column defaults.
        $this->assertSame('index, follow', $row->robots);
        $this->assertTrue((bool) $row->in_sitemap);
    }

    public function test_a_french_post_writes_the_fr_columns_not_en(): void
    {
        $response = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => null,
                'title_fr' => 'Article Test',
                'locale' => 'fr',
                'seo' => ['meta_title' => 'Un excellent titre'],
            ],
        ));

        $response->assertCreated();
        $post = Post::find($response->json('post.id'));

        $row = $this->seoRowFor($post);
        $this->assertNotNull($row);
        $this->assertSame('Un excellent titre', $row->meta_title_fr);
        $this->assertNull($row->meta_title_en);
    }

    public function test_robots_booleans_compose_into_the_robots_column(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Robots Test'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Robots Test',
                'content_version' => $version,
                'seo' => ['robots_index' => false, 'robots_follow' => true],
            ],
        ));

        $response->assertOk();
        $row = $this->seoRowFor(Post::find($postId));
        $this->assertSame('noindex, follow', $row->robots);
        $this->assertSame(['robots_index' => false, 'robots_follow' => true], [
            'robots_index' => $response->json('post.seo.robots_index'),
            'robots_follow' => $response->json('post.seo.robots_follow'),
        ]);
    }

    public function test_setting_only_one_robots_flag_preserves_the_other(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Robots Partial', 'seo' => ['robots_index' => false, 'robots_follow' => false]],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');
        $this->assertSame('noindex, nofollow', $this->seoRowFor(Post::find($postId))->robots);

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Robots Partial', 'content_version' => $version, 'seo' => ['robots_follow' => true]],
        ));

        $response->assertOk();
        // robots_index (untouched this save) stays noindex; robots_follow flips to follow.
        $this->assertSame('noindex, follow', $this->seoRowFor(Post::find($postId))->robots);
    }

    public function test_a_second_save_updates_the_same_row_and_never_duplicates_it(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Update Test', 'seo' => ['meta_title' => 'First title', 'meta_description' => 'First description']],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Update Test', 'content_version' => $version, 'seo' => ['meta_title' => 'Second title']],
        ));

        $response->assertOk();
        $this->assertSame(1, SeoMeta::query()->count());
        $row = $this->seoRowFor(Post::find($postId));
        $this->assertSame('Second title', $row->meta_title_en);
        // meta_description wasn't sent on the second save — it must survive untouched.
        $this->assertSame('First description', $row->meta_description_en);
    }

    public function test_og_image_canonical_and_in_sitemap_round_trip(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Round Trip Test',
                'seo' => [
                    'og_image' => 'https://example.com/social.jpg',
                    'canonical_url' => 'https://example.com/round-trip-test',
                    'in_sitemap' => false,
                ],
            ],
        ));

        $store->assertCreated();
        $row = $this->seoRowFor(Post::find($store->json('post.id')));
        $this->assertSame('https://example.com/social.jpg', $row->og_image);
        $this->assertSame('https://example.com/round-trip-test', $row->canonical_url);
        $this->assertFalse((bool) $row->in_sitemap);

        $this->assertSame('https://example.com/social.jpg', $store->json('post.seo.og_image'));
        $this->assertSame('https://example.com/round-trip-test', $store->json('post.seo.canonical_url'));
        $this->assertFalse($store->json('post.seo.in_sitemap'));
    }

    public function test_an_empty_canonical_url_is_accepted_and_clears_the_column(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Empty Canonical', 'seo' => ['canonical_url' => 'https://example.com/x']],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Empty Canonical', 'content_version' => $version, 'seo' => ['canonical_url' => '']],
        ));

        $response->assertOk();
        $this->assertNull($this->seoRowFor(Post::find($postId))->canonical_url);
    }

    public function test_a_non_url_canonical_is_rejected_with_422(): void
    {
        $response = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Bad Canonical', 'seo' => ['canonical_url' => 'not-a-url']],
        ));

        $response->assertStatus(422);
        $this->assertArrayHasKey('seo.canonical_url', $response->json('errors'));
        $this->assertSame(0, Post::count());
    }

    public function test_autosave_ignores_the_seo_payload(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Autosave Test'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Autosave Test', 'content_version' => $version, 'autosave' => true, 'seo' => ['meta_title' => 'Should not save']],
        ));

        $response->assertOk();
        $this->assertNull($this->seoRowFor(Post::find($postId)));
    }

    public function test_the_save_response_echoes_default_seo_state_when_nothing_was_sent(): void
    {
        $response = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'No Seo Sent'],
        ));

        $response->assertCreated();
        $this->assertSame('', $response->json('post.seo.meta_title'));
        $this->assertTrue($response->json('post.seo.robots_index'));
        $this->assertTrue($response->json('post.seo.robots_follow'));
        $this->assertTrue($response->json('post.seo.in_sitemap'));
        $this->assertSame(0, SeoMeta::query()->count());
    }
}
