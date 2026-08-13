<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wave 2 of the single-row translation model (docs/content-translation.md §0): the editor edits
 * every language of ONE post in place, instead of navigating to a sibling row (Wave 1, since
 * removed). Covers the seam this wave rebuilds:
 *  - the topbar's editing-locale dropdown is an in-place switch, never a `postId` navigation;
 *  - the Post tab's Translations section renders TranslationStatusService's completeness rows
 *    (and plain locale rows before the first save) instead of the old create/open/update chips;
 *  - EditorController seeds the editing-locale/home-locale/per-locale-title data the client needs;
 *  - a save made while editing a non-home locale lands on `title_<locale>`/`content_<locale>`,
 *    leaving the post's own (home-locale) bare values untouched — the rule LocalizedAttributes::
 *    write() and block-runtime.blade.php's resolveAttrKey() both encode.
 *
 * Same local-dev authorization bypass posture as PostPersistenceTest (a plain, unauthenticated
 * `testbench serve`/test session can still exercise the save endpoint).
 */
class EditingLocaleTest extends TestCase
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
            'blocks' => $blocks,
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

    // ── the topbar dropdown is a locale switch, never navigation ───────────

    public function test_topbar_language_dropdown_switches_locale_in_place_never_navigates(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // The switch itself — one clickable option per configured locale, wired to the runtime's
        // own editing-locale seam, not a fetch/navigation.
        $this->assertStringContainsString('data-hb-lang-option', $html);
        $this->assertStringContainsString('data-hb-lang-option data-locale="en"', $html);
        $this->assertStringContainsString('data-hb-lang-option data-locale="fr"', $html);
        $this->assertStringContainsString('window.hbEditor.setEditingLocale(opt.dataset.locale', $html);

        // The Wave-1-era per-post navigation/creation affordances are gone outright.
        $this->assertStringNotContainsString('data-hb-lang-open', $html);
        $this->assertStringNotContainsString('data-hb-lang-create', $html);
        $this->assertStringNotContainsString('postTranslationsUrlTemplate', $html);
        $this->assertStringNotContainsString('postEditorUrlTemplate', $html);

        // Never disabled — switching which locale you are about to author in needs no saved post.
        $langButton = substr($html, (int) strpos($html, 'data-hb-lang-toggle'));
        $langButton = substr($langButton, 0, (int) strpos($langButton, '>') + 1);
        $this->assertStringNotContainsString('disabled', $langButton);
    }

    public function test_the_create_translation_endpoint_no_longer_exists(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        // The Wave-1-removed PostTranslationController route — nothing claims this path anymore.
        $this->postJson("/editor/posts/{$post->id}/translations", ['locale' => 'fr'])
            ->assertNotFound();
    }

    // ── the editing-locale seed ─────────────────────────────────────────────

    public function test_editor_show_seeds_the_home_locale_and_per_locale_titles(): void
    {
        $post = Post::create(['title_en' => 'English Title', 'title_fr' => 'French Title', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        // block-runtime.blade.php's window.__hbEditor seed — the "home locale" the bare/suffixed
        // write rule keys off never changes for this document's lifetime.
        $this->assertStringContainsString('postLocale: ' . json_encode('en'), $html);
        $this->assertStringContainsString('contentLocales: ' . json_encode(['en', 'fr']), $html);

        // topbar's per-locale title cache — both configured locales' OWN raw column values, not
        // just the one currently on screen.
        $this->assertStringContainsString(
            'data-hb-title-by-locale="' . e(json_encode(['en' => 'English Title', 'fr' => 'French Title'])) . '"',
            $html,
        );
    }

    public function test_editor_index_seeds_the_config_default_locale_for_a_blank_document(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringContainsString('postLocale: ' . json_encode('en'), $html);
        $this->assertStringContainsString(
            'data-hb-title-by-locale="' . e(json_encode(['en' => '', 'fr' => ''])) . '"',
            $html,
        );
    }

    // ── Translations section → completeness ─────────────────────────────────

    public function test_translations_section_renders_plain_locale_rows_before_the_first_save(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-post-translations-field', $html);
        $this->assertStringContainsString('data-hb-translation-row data-hb-translation-locale="en"', $html);
        $this->assertStringContainsString('data-hb-translation-row data-hb-translation-locale="fr"', $html);
        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations_needs_save'), $html);

        // No create/open/update affordances anywhere — there is nothing to create.
        $this->assertStringNotContainsString('data-hb-translation-create', $html);
        $this->assertStringNotContainsString('data-hb-translation-open', $html);
    }

    public function test_translations_section_renders_completeness_rows_for_a_saved_post(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'title_fr' => null, 'locale' => 'en', 'status' => 'draft']);
        $post->blocks()->create([
            'type' => 'heading',
            'order' => 0,
            'content' => [
                'name' => 'heisenberg/heading',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Hi', 'level' => 2],
                'supports' => [],
                'innerBlocks' => [],
            ],
        ]);

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        // en: title set, the block's only translatable attribute (content) resolves via the
        // home-locale bare fallback — 100% complete.
        $enRow = substr($html, (int) strpos($html, 'data-hb-translation-locale="en"'));
        $enRow = substr($enRow, 0, (int) strpos($enRow, '</button>'));
        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations_complete'), $enRow);

        // fr: title_fr is empty and the block has no content_fr variant — incomplete, and the
        // summary names BOTH gaps (title missing, 0/1 blocks translated).
        $frRow = substr($html, (int) strpos($html, 'data-hb-translation-locale="fr"'));
        $frRow = substr($frRow, 0, (int) strpos($frRow, '</button>'));
        $this->assertStringContainsString(__('heisenberg::editor.inspector.post_translations_title_missing'), $frRow);
        $this->assertStringContainsString('0/1', $frRow);
        $this->assertStringNotContainsString(__('heisenberg::editor.inspector.post_translations_complete'), $frRow);
    }

    public function test_translations_section_click_switches_the_editing_locale_not_navigation(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        $this->assertStringContainsString('window.hbEditor.setEditingLocale(row.dataset.hbTranslationLocale)', $html);
        $this->assertStringNotContainsString('data-hb-translation-open', $html);
        $this->assertStringNotContainsString('data-hb-translation-create', $html);
        $this->assertStringNotContainsString('data-hb-translation-update', $html);
    }

    // ── save while editing a non-home locale ────────────────────────────────

    public function test_saving_while_editing_a_non_home_locale_writes_suffixed_variants_leaving_english_untouched(): void
    {
        // First save: authored in the post's own (home) locale, en — the bare keys every
        // pre-translation post already stores.
        $created = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/heading', ['content' => 'English Heading', 'level' => 2])],
            ['title_en' => 'English Title', 'locale' => 'en'],
        ))->assertCreated()->json();

        $postId = $created['post']['id'];
        $version = $created['post']['content_version'];

        // Second save: the SAME document, now switched to editing fr — block-runtime's
        // resolveAttrKey() lands the write on `content_fr` (home locale stays en, so the bare
        // `content` key is left exactly as the client's in-memory model already had it), and
        // topbar's hbTitleSaveExtra() resends both title_en (untouched, still cached) and the
        // newly-edited title_fr.
        $updated = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/heading', ['content' => 'English Heading', 'content_fr' => 'French Heading', 'level' => 2])],
            ['title_en' => 'English Title', 'title_fr' => 'French Title', 'locale' => 'en', 'content_version' => $version],
        ))->assertOk()->json();

        $this->assertSame('English Title', $updated['post']['title_en']);
        $this->assertSame('French Title', $updated['post']['title_fr']);

        $post = Post::with('blocks')->findOrFail($postId);
        $attributes = $post->blocks->first()->content['attributes'];
        $this->assertSame('English Heading', $attributes['content'], 'the home locale\'s bare content must survive untouched');
        $this->assertSame('French Heading', $attributes['content_fr']);
    }

    // ── one shared client-side write helper, with the home-locale exemption ──
    // The write logic itself is client-side JS (block-runtime.blade.php) — there is no server
    // decision to exercise via HTTP, so these pin the SOURCE: one resolver, and every translatable
    // write path (inspector controls, canvas rich-text/contenteditable, Code view) routed through
    // it, per this wave's brief ("route them all through one client-side helper rather than
    // duplicating the suffix logic").

    public function test_the_home_locale_exemption_is_encoded_in_exactly_one_place(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Translatable + editing a DIFFERENT locale than the post's own → suffixed; every other
        // case (non-translatable, or editing the post's own home locale) → the bare key, which is
        // what LocalizedAttributes::read() falls back to and what every pre-translation post
        // already stores.
        $this->assertStringContainsString(
            "function resolveAttrKey(name, key) {\n        return (isTranslatableAttr(name, key) && editingLocale !== homeLocale) ? key + '_' + editingLocale : key;\n    }",
            $html,
        );
    }

    public function test_every_translatable_write_path_routes_through_the_shared_resolver(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Inspector controls (setAttribute — the one write path handleControlEvent uses).
        $this->assertStringContainsString('model.attributes[resolveAttrKey(model.name, key)] = value;', $html);
        // Canvas contenteditable / rich-text commit.
        $this->assertStringContainsString("model.attributes[resolveAttrKey(model.name, ce.getAttribute('data-hb-rt'))] = ce.innerHTML;", $html);
        // Code view: a plain `attr="value"` write and the rich-text body write.
        $this->assertStringContainsString('window.hbEditor.resolveAttrKey(model.name, name)', $html);
        $this->assertStringContainsString('window.hbEditor.resolveAttrKey(frame.model.name, rich)', $html);

        // Nothing writes a raw, unresolved key for content that came from the user — the two
        // remaining `model.attributes[...] =` assignments left in block-runtime.blade.php (columns
        // reconciliation, contract preset seeding) are both structural, never translatable text.
    }

    public function test_reads_fall_back_to_the_bare_key_matching_localized_attributes_read(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Mirrors Heisenberg\Support\LocalizedAttributes::read() exactly: `key_<editingLocale>`
        // when the block actually carries that variant, else the bare key.
        $this->assertStringContainsString('function readAttr(model, key)', $html);
        $this->assertStringContainsString("const val = readAttr(model, node.attribute);", $html);
        $this->assertStringContainsString("const raw = readAttr(model, node.attribute);", $html);
        $this->assertStringContainsString("const v = readAttr(model, tok.slice(11));", $html);

        // The inspector re-seeds every control through the SAME helper — a translated block must
        // show that locale's text after a selection change, not the bare/home value.
        $inspectorHtml = $this->get('/editor')->assertOk()->getContent();
        $this->assertStringContainsString('window.hbEditor.readAttr(model, key)', $inspectorHtml);
    }
}
