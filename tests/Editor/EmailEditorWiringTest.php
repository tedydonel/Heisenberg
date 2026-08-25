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

    public function test_a_plain_editor_load_defaults_to_a_post_document(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringNotContainsString('hb-canvas--email', $html);
        // Every registered block is offered — including one the email surface excludes.
        $this->assertStringContainsString('data-hb-insert-block="heisenberg/embed"', $html);
    }

    public function test_editor_type_email_seeds_a_blank_email_document(): void
    {
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertStringContainsString('hb-canvas--email', $html);
    }

    public function test_an_existing_email_posts_editor_page_carries_the_email_canvas_class(): void
    {
        $post = $this->makeEmail();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertStringContainsString('hb-canvas--email', $html);
    }

    // ── one authoring URL per document type (docs/email-system.md §6.2) ──────

    private function makeEmail(): Post
    {
        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        return $post;
    }

    public function test_the_old_type_query_form_redirects_to_the_email_editors_own_address(): void
    {
        $this->get('/editor?type=email')->assertRedirect('/editor/email');
    }

    public function test_an_email_opened_on_the_post_surface_redirects_to_the_email_surface(): void
    {
        $post = $this->makeEmail();

        $this->get("/editor/{$post->id}")->assertRedirect("/editor/email/{$post->id}");
    }

    public function test_a_plain_post_opened_on_the_email_surface_redirects_back(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en']);

        $this->get("/editor/email/{$post->id}")->assertRedirect("/editor/{$post->id}");
    }

    /**
     * Authorization runs BEFORE either surface decides where to send the request, so the redirect
     * can never confirm that an id exists to someone who may not read it.
     */
    public function test_the_redirect_never_leaks_a_draft_to_an_unauthorized_visitor(): void
    {
        $this->app['env'] = 'production'; // the LocalDevRoleGate bypass is local-only
        $post = $this->makeEmail();

        $this->get("/editor/{$post->id}")->assertForbidden();
        $this->get("/editor/email/{$post->id}")->assertForbidden();
    }

    /** A new email's first save must rewrite the URL to the email surface, not the post one. */
    public function test_the_topbar_adopts_the_email_url_after_a_new_emails_first_save(): void
    {
        $emailHtml = $this->get('/editor/email')->assertOk()->getContent();
        $postHtml = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-editor-url-template="http://localhost/editor/email/__ID__"', $emailHtml);
        $this->assertStringContainsString('data-hb-editor-url-template="http://localhost/editor/__ID__"', $postHtml);
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
        $html = $this->get('/editor/email')->getContent();

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

    public function test_email_document_hides_seo_social_discussion_toc_and_featured_image(): void
    {
        $html = $this->get('/editor/email')->getContent();

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
        $html = $this->get('/editor/email')->getContent();

        $this->assertStringContainsString('data-hb-post-translations-field', $html);
        $this->assertStringContainsString('data-hb-post-status', $html);
        $this->assertStringContainsString('data-hb-post-popup-trigger="slug"', $html);
    }

    /**
     * Blog furniture an email has no use for: taxonomy organizes a listing it never appears in,
     * the page-padding sliders move the .hb-page sheet an email is not rendered into, and
     * "stick to the top of the blog" is that listing again.
     */
    public function test_email_document_hides_taxonomy_page_layout_and_the_blog_pin(): void
    {
        $html = $this->get('/editor/email')->getContent();

        $this->assertStringNotContainsString('data-hb-disclosure-body data-hb-post-taxonomy-field', $html);
        $this->assertStringNotContainsString('data-hb-disclosure-body data-hb-post-layout-field', $html);
        $this->assertStringNotContainsString('name="post-stick-top"', $html);
    }

    /** The Summary's slug row names what the slug actually IS here: the email's serving address. */
    public function test_the_summary_slug_row_reads_as_the_emails_serving_address(): void
    {
        $post = $this->makeEmail();
        $post->slug = 'august-letter';
        $post->save();

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();
        $postHtml = $this->get('/editor')->getContent();

        $this->assertStringContainsString('/emails/august-letter', $html);
        $this->assertStringContainsString(__('heisenberg::editor.inspector.summary_email_address'), $html);
        $this->assertStringNotContainsString(__('heisenberg::editor.inspector.summary_email_address'), $postHtml);
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
