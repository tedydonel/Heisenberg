<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wave E3 of docs/email-system.md — the email authoring experience in the editor: creating/
 * opening an email document, the filtered Components/quick-insert palette, the chrome hidden for
 * emails (SEO/Social, Discussion, TOC, Featured image), Translations staying, the canvas's
 * email-width hint, and `type`'s create-only posture on save. Not a script-execution test (no JS
 * engine here) — pins the SERVER-RENDERED contract, same posture as the other *WiringTest classes.
 */
class EmailEditorWiringTest extends TestCase
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

    // ── creating an email document ──────────────────────────────────────────

    public function test_a_plain_editor_load_defaults_to_a_post_document(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringNotContainsString('hb-canvas--email', $html);
        // Every registered block is offered — including one the email surface excludes.
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/embed"', $html);
    }

    public function test_editor_type_email_seeds_a_blank_email_document(): void
    {
        $html = $this->get('/editor?type=email')->assertOk()->getContent();

        $this->assertStringContainsString('hb-canvas--email', $html);
    }

    public function test_an_existing_email_posts_editor_page_carries_the_email_canvas_class(): void
    {
        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        $this->assertStringContainsString('hb-canvas--email', $html);
    }

    public function test_an_existing_plain_posts_editor_page_never_carries_the_email_canvas_class(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en']);

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('hb-canvas--email', $html);
    }

    // ── palette filtering (server-side, docs/email-system.md §4) ───────────

    public function test_the_email_documents_palette_only_lists_email_surface_blocks(): void
    {
        $html = $this->get('/editor?type=email')->getContent();

        // Email-safe (10 of 12 shipped contracts).
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/heading"', $html);
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/paragraph"', $html);
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/image"', $html);
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/button"', $html);

        // Excluded (§4: webfont/SVG dependency, revisit later).
        $this->assertStringNotContainsString('data-hb-insert-block="heisenberg/embed"', $html);
        $this->assertStringNotContainsString('data-hb-insert-block="heisenberg/icon"', $html);

        // The quick-inserter reads the SAME filtered seed — no separate client-side filtering.
        $this->assertStringContainsString('data-hb-qi-block="heisenberg/heading"', $html);
        $this->assertStringNotContainsString('data-hb-qi-block="heisenberg/embed"', $html);
    }

    // ── chrome hidden for emails (server-side, not rendered) ────────────────

    public function test_email_document_hides_seo_social_discussion_toc_and_featured_image(): void
    {
        $html = $this->get('/editor?type=email')->getContent();

        // The SEO/Social panel and its two sidebar nav entries are not rendered at all. Matched
        // on the ROOT TAG (`<div data-hb-panel-seo`), not the bare attribute name — sidebar.
        // blade.php's own (unconditional) nav-switcher script references the bracketed CSS
        // selector `[data-hb-panel-seo]` regardless of whether the panel itself renders.
        $this->assertStringNotContainsString('<div data-hb-panel-seo', $html);
        $this->assertStringNotContainsString('data-hb-nav="seo:0"', $html);
        $this->assertStringNotContainsString('data-hb-nav="seo:1"', $html);

        // Discussion, Table of contents, Featured image — none rendered. Matched on the ROOT
        // TAG'S OWN two-attribute combo, not the bare data-attribute alone: inspector.blade.php's
        // own (unconditional) boot scripts reference the bracketed selectors
        // ([data-hb-featured-field], [data-hb-post-discussion-field], [data-hb-post-toc-field])
        // regardless of whether the section itself renders, and "Featured image" as plain text
        // also appears in the (unconditional) AI tools catalog and a media-picker JS fallback.
        $this->assertStringNotContainsString('data-hb-disclosure-body data-hb-featured-field', $html);
        $this->assertStringNotContainsString('data-hb-disclosure-body data-hb-post-discussion-field', $html);
        $this->assertStringNotContainsString('data-hb-disclosure-body data-hb-post-toc-field', $html);
    }

    public function test_email_document_keeps_translations_and_the_summary(): void
    {
        $html = $this->get('/editor?type=email')->getContent();

        $this->assertStringContainsString('data-hb-post-translations-field', $html);
        $this->assertStringContainsString('data-hb-post-status', $html);
        $this->assertStringContainsString('data-hb-post-popup-trigger="slug"', $html);
    }

    public function test_a_plain_post_document_still_renders_the_full_chrome(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertStringContainsString('data-hb-panel-seo', $html);
        $this->assertStringContainsString('data-hb-nav="seo:0"', $html);
        $this->assertStringContainsString('data-hb-post-discussion-field', $html);
        $this->assertStringContainsString('data-hb-post-toc-field', $html);
        $this->assertStringContainsString('data-hb-featured-field', $html);
    }

    // ── `type` is create-only ────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $blocks */
    private function envelope(array $blocks, array $overrides = []): array
    {
        return array_merge([
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'computedStyles' => '',
            'autosave' => false,
            'blocks' => $blocks,
            'title_en' => 'A Test Email',
            'locale' => 'en',
        ], $overrides);
    }

    public function test_creating_a_post_with_type_email_stamps_the_row(): void
    {
        $response = $this->postJson('/editor/posts', $this->envelope([], ['type' => 'email']));

        $response->assertCreated();
        $postId = $response->json('post.id');
        $this->assertSame('email', Post::find($postId)->type);
    }

    public function test_omitting_type_on_create_defaults_to_post(): void
    {
        $response = $this->postJson('/editor/posts', $this->envelope([]));

        $response->assertCreated();
        $postId = $response->json('post.id');
        $this->assertSame('post', Post::find($postId)->type);
    }

    public function test_type_is_ignored_on_update_a_document_never_changes_type(): void
    {
        $create = $this->postJson('/editor/posts', $this->envelope([], ['type' => 'email']));
        $postId = $create->json('post.id');
        $version = $create->json('post.content_version');

        $update = $this->putJson("/editor/posts/{$postId}", $this->envelope([], [
            'type' => 'post',
            'content_version' => $version,
        ]));

        $update->assertOk();
        $this->assertSame('email', Post::find($postId)->type, 'an existing document must never change type via the generic save path');
    }
}
