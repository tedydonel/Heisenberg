<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Post tab's Translations section (docs/content-translation.md §5, Wave T2a) —
 * live/inspector.blade.php's Blade-rendered rows, seeded from EditorController::show()'s
 * `postTranslations` (TranslationStatusService::statuses()) / index()'s `null` marker.
 */
class PostTranslationsWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same local-dev PostPolicy::view() bypass every other /editor/{post} test relies on
        // (PostSettingsControllerTest, PostPersistenceTest) — these fixtures are draft, unowned
        // posts, which a real (non-bypassed) guest may not view.
        $this->app['env'] = 'local';
    }

    public function test_a_blank_unsaved_document_renders_the_save_first_hint_with_no_rows(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertStringContainsString('data-hb-post-translations-field', $html);
        $this->assertStringContainsString(
            __('heisenberg::editor.inspector.post_translations_needs_save'),
            $html,
        );
        // No rendered ROW markup — the wiring script's own [data-hb-translation-row] selector
        // string is legitimately present regardless (it's emitted once, unconditionally), so the
        // pin checks for an actual opening tag rather than the bare attribute name.
        $this->assertStringNotContainsString('<div class="hb-post-translation-row"', $html);
    }

    public function test_a_saved_untranslated_post_renders_a_missing_row_with_a_create_button(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->getContent();

        $this->assertStringContainsString('data-hb-translation-locale="en"', $html);
        $this->assertStringContainsString('data-hb-translation-status="source"', $html);
        $this->assertStringContainsString('data-hb-translation-locale="fr"', $html);
        $this->assertStringContainsString('data-hb-translation-status="missing"', $html);
        $this->assertStringContainsString('data-hb-translation-create', $html);
        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations_create'), $html);
        $this->assertStringContainsString(__('heisenberg::editor.locales.fr'), $html);
    }

    public function test_every_status_renders_its_own_chip_class_and_the_right_actions(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'published',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $html = $this->get("/editor/{$en->id}")->getContent();

        // Published sibling: chip + Open, no Create/Update.
        $this->assertStringContainsString('hb-post-translation-row__chip--published', $html);
        $this->assertStringContainsString('data-hb-translation-post-id="' . $fr->id . '"', $html);
        $this->assertStringContainsString('data-hb-translation-open', $html);
        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations_status_published'), $html);

        // Source row: a "this post" marker, never an action button.
        $this->assertStringContainsString('hb-post-translation-row__chip--source', $html);
        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations_this_post'), $html);
    }

    public function test_an_outdated_sibling_renders_both_open_and_update_from_source(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id,
        ]);
        $fr->translated_from_version = $en->fresh()->content_version;
        $fr->save();
        $en->bumpContentVersion();

        $html = $this->get("/editor/{$en->id}")->getContent();

        $this->assertStringContainsString('data-hb-translation-status="outdated"', $html);
        $this->assertStringContainsString('hb-post-translation-row__chip--outdated', $html);
        $this->assertStringContainsString('data-hb-translation-open', $html);
        $this->assertStringContainsString('data-hb-translation-update', $html);
        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations_update'), $html);
    }

    public function test_the_translations_url_template_and_editor_url_template_are_seeded(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->getContent();

        // Same __ID__-placeholder convention as postLayoutUrlTemplate/postDiscussionUrlTemplate/…
        // (EditorController::sharedViewData()) — the client substitutes the CURRENT post id at
        // click time, not the server at render time (the template is shared with /editor's blank,
        // id-less document too).
        $this->assertStringContainsString('data-hb-translations-url-template="', $html);
        $this->assertStringContainsString('/editor/posts/__ID__/translations', $html);
        $this->assertStringContainsString('data-hb-editor-url-template="', $html);
        $this->assertStringContainsString('/editor/__ID__', $html);
    }

    public function test_the_translations_disclosure_label_renders(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations'), $html);
    }
}
