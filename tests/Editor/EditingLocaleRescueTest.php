<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * End-to-end proof that switching the topbar to fr and editing a block does NOT
 * destroy the home content. The user-visible bug: "when I switch to fr and change the
 * language, both locales show the translated language." This is the save-time rescue
 * that fixes it server-side regardless of which client-side path overwrote the bare
 * attribute (the JS write helper, the inspector, Code view, an AI write_canvas).
 *
 * Scenarios:
 *  - correctly-built payload (bare unchanged, content_fr set): no-op, both locales
 *    render correctly.
 *  - buggy payload where bare got overwritten with fr content (bare differs from
 *    stored, content_fr empty): server restores bare from storage AND folds the
 *    incoming value into content_fr. Both locales render correctly afterwards.
 *  - bare deliberately changed to a NEW home-locale value while editing fr: the
 *    rescue's "bare differs from stored" trigger does NOT fire for translatable
 *    attrs because we only fold when the suffixed slot is empty + bare changed —
 *    and a legitimate home edit with an already-set suffixed slot is left alone
 *    by the suffixed-present early-continue.
 */
class EditingLocaleRescueTest extends TestCase
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
            'autosave' => false,
            'title_en' => 'A Post',
            'title_fr' => '',
            'locale' => 'en',
            'editingLocale' => 'en',
            'blocks' => $blocks,
        ], $overrides);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function updateEnvelope(Post $post, array $blocks, array $overrides = []): array
    {
        return $this->envelope($blocks, array_merge([
            'content_version' => (int) $post->content_version,
        ], $overrides));
    }

    private function makePost(string $titleEn = 'A Post', string $paragraphContent = 'Hello in English.'): Post
    {
        $post = Post::create([
            'title_en' => $titleEn,
            'slug' => 'a-post',
            'status' => 'draft',
            'locale' => 'en',
        ]);

        Block::create([
            'post_id' => $post->id,
            'type' => 'paragraph',
            'content' => [
                'id' => 'b0',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => $paragraphContent],
                'supports' => [],
                'innerBlocks' => [],
            ],
            'order' => 0,
        ]);

        return $post->fresh('blocks');
    }

    public function test_correctly_built_payload_round_trips(): void
    {
        // Client did the right thing: bare=en (unchanged), content_fr=fr (the translation).
        // The rescue is a no-op; both locales render correctly.
        $post = $this->makePost();

        $this->put(
            "/editor/posts/{$post->id}",
            $this->updateEnvelope($post, [[
                'id' => 'b0',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => [
                    'content' => 'Hello in English.',
                    'content_fr' => 'Bonjour en français.',
                ],
                'supports' => [],
                'innerBlocks' => [],
            ]], ['editingLocale' => 'fr'])
        )->assertOk();

        $fresh = $post->fresh('blocks');
        $block = $fresh->blocks->first();
        $this->assertSame('Hello in English.', $block->content['attributes']['content']);
        $this->assertSame('Bonjour en français.', $block->content['attributes']['content_fr']);

        // Render each locale — both show the right language.
        $renderer = app(BlockRenderer::class);
        $blocks = $fresh->blocks->map(fn ($b) => $b->content)->values()->all();
        $this->assertStringContainsString('Hello in English.', $renderer->renderBlocks($blocks, 'en'));
        $this->assertStringContainsString('Bonjour en français.', $renderer->renderBlocks($blocks, 'fr'));
    }

    public function test_buggy_payload_overwriting_bare_is_rescued(): void
    {
        // The user-visible bug: the client sent bare=Bonj..., content_fr empty (a JS
        // path wrote the translation into bare instead of the suffixed slot). The
        // server MUST restore the home content and slot the translation correctly.
        $post = $this->makePost();

        $this->put(
            "/editor/posts/{$post->id}",
            $this->updateEnvelope($post, [[
                'id' => 'b0',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => [
                    'content' => 'Bonjour en français.', // overwritten — this is the damage
                    // 'content_fr' is absent: the client forgot to set the suffixed slot
                ],
                'supports' => [],
                'innerBlocks' => [],
            ]], ['editingLocale' => 'fr'])
        )->assertOk();

        $fresh = $post->fresh('blocks');
        $block = $fresh->blocks->first();

        // Home content restored, translation slotted correctly.
        $this->assertSame('Hello in English.', $block->content['attributes']['content']);
        $this->assertSame('Bonjour en français.', $block->content['attributes']['content_fr']);

        // Render each locale — both show the right language, the user's original report.
        $renderer = app(BlockRenderer::class);
        $blocks = $fresh->blocks->map(fn ($b) => $b->content)->values()->all();
        $this->assertStringContainsString('Hello in English.', $renderer->renderBlocks($blocks, 'en'));
        $this->assertStringContainsString('Bonjour en français.', $renderer->renderBlocks($blocks, 'fr'));
    }

    public function test_home_locale_save_leaves_bare_untouched(): void
    {
        // editingLocale === homeLocale: no rescue runs, the client writes bare directly,
        // the change persists verbatim.
        $post = $this->makePost();

        $this->put(
            "/editor/posts/{$post->id}",
            $this->updateEnvelope($post, [[
                'id' => 'b0',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Updated home text.'],
                'supports' => [],
                'innerBlocks' => [],
            ]], ['editingLocale' => 'en'])
        )->assertOk();

        $block = $post->fresh('blocks')->blocks->first();
        $this->assertSame('Updated home text.', $block->content['attributes']['content']);
    }

    public function test_create_does_not_run_the_rescue(): void
    {
        // No prior tree, no storage to restore from — rescue is a no-op for creates.
        $payload = $this->envelope([[
            'id' => 'b0',
            'name' => 'heisenberg/paragraph',
            'schemaVersion' => '1.0.0',
            'attributes' => [
                'content' => 'Bonjour en français.',
                'content_fr' => 'Bonjour en français.',
            ],
            'supports' => [],
            'innerBlocks' => [],
        ]], [
            'editingLocale' => 'fr',
            'title_en' => 'New',
            'locale' => 'fr',
        ]);
        // Drop the content_version requirement for create.
        unset($payload['content_version']);

        $response = $this->post('/editor/posts', $payload);
        $response->assertStatus(201);

        $post = Post::query()->latest('id')->first();
        $this->assertNotNull($post);
        $block = $post->blocks()->first();
        $this->assertSame('Bonjour en français.', $block->content['attributes']['content']);
    }
}
