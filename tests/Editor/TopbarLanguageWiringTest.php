<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The topbar's language dropdown (docs/content-translation.md §5) — the owner's primary
 * view/switch/create-translations switch, a visual sibling of the device dropdown
 * (live/topbar.blade.php). It reads the SAME `postTranslations` seed
 * (TranslationStatusService::statuses()) and the same `postTranslationsUrlTemplate`/
 * `postEditorUrlTemplate` templates as the inspector's own Translations section
 * (see PostTranslationsWiringTest) — no second payload, no second endpoint.
 */
class TopbarLanguageWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same local-dev PostPolicy::view() bypass every other /editor/{post} test relies on.
        $this->app['env'] = 'local';
    }

    public function test_a_blank_unsaved_document_renders_a_disabled_trigger_with_the_default_locale(): void
    {
        $html = $this->get('/editor')->getContent();

        $this->assertStringContainsString('data-hb-lang-toggle', $html);
        $this->assertStringContainsString('hb-topbar__lang-label', $html);
        $this->assertStringContainsString(__('heisenberg::editor.locales.en'), $html);

        // The trigger button itself carries `disabled` — no menu at all for a document with no
        // source post yet to translate FROM (same posture as the inspector's "needs save" hint).
        $this->assertMatchesRegularExpression(
            '/data-hb-lang-toggle\s[^>]*disabled/s',
            $html,
        );
        // The CSS block (@once, rendered on every page regardless of state) legitimately
        // mentions the class name in its selectors, so the pin checks for an actual opening
        // tag rather than the bare class string — same posture PostTranslationsWiringTest
        // uses for its own row markup.
        $this->assertStringNotContainsString('<div class="hb-topbar__langsel-menu"', $html);
    }

    public function test_a_saved_untranslated_post_marks_the_current_locale_and_offers_a_translate_affordance(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->getContent();

        // The current (source) locale renders as a checked, non-interactive row.
        $this->assertStringContainsString('hb-topbar__langsel-opt is-on', $html);
        $this->assertStringContainsString(__('heisenberg::editor.locales.en'), $html);

        // The missing sibling carries the create affordance and its own endpoint template.
        $this->assertStringContainsString('data-hb-lang-create', $html);
        $this->assertStringContainsString('data-locale="fr"', $html);
        $this->assertStringContainsString(
            __('heisenberg::editor.topbar.lang_translate_to', ['locale' => __('heisenberg::editor.locales.fr')]),
            $html,
        );
        $this->assertStringContainsString('data-hb-translations-url-template="', $html);
        $this->assertStringContainsString('/editor/posts/__ID__/translations', $html);
        $this->assertStringContainsString('data-hb-editor-url-template="', $html);
        $this->assertStringContainsString('/editor/__ID__', $html);

        // The trigger is enabled and not the disabled/no-menu blank-document markup.
        $this->assertDoesNotMatchRegularExpression(
            '/data-hb-lang-toggle\s[^>]*disabled/s',
            $html,
        );
    }

    public function test_an_existing_sibling_renders_its_locale_name_status_hint_and_open_affordance(): void
    {
        $en = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'locale' => 'fr', 'status' => 'published',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $html = $this->get("/editor/{$en->id}")->getContent();

        $this->assertStringContainsString('data-hb-lang-open', $html);
        $this->assertStringContainsString('data-post-id="' . $fr->id . '"', $html);
        $this->assertStringContainsString(__('heisenberg::editor.locales.fr'), $html);
        $this->assertStringContainsString(
            __('heisenberg::editor.inspector.post_translations_status_published'),
            $html,
        );
    }

    public function test_an_outdated_sibling_still_uses_the_open_affordance_not_create(): void
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

        $this->assertStringContainsString('data-hb-lang-open', $html);
        $this->assertStringContainsString('data-post-id="' . $fr->id . '"', $html);
        $this->assertStringContainsString(
            __('heisenberg::editor.inspector.post_translations_status_outdated'),
            $html,
        );
    }

    public function test_the_dropdown_sits_next_to_the_device_dropdown_as_a_visual_sibling(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->getContent();

        // Both dropdowns render in the topbar's right-hand cluster, same shell shape
        // (.hb-topbar__*sel wrapper, a toggle button, and a listbox menu).
        $this->assertStringContainsString('hb-topbar__langsel', $html);
        $this->assertStringContainsString('hb-topbar__devsel', $html);
        $this->assertStringContainsString('data-hb-lang-toggle', $html);
        $this->assertStringContainsString('data-hb-device-toggle', $html);
    }
}
