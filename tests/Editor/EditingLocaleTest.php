<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\Support\AssertsHtmlStructure;
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
 *
 * Structural-assertion note: the client-side write/read/fold logic this file protects lives
 * ENTIRELY in inline JS (block-runtime.blade.php) with nothing server-rendered to key off, which
 * is exactly the case AssertsHtmlStructure's docblock calls out as a legitimate reason to assert
 * on inline script bodies directly (assertInlineScriptContains, whitespace-tolerant) rather than
 * force a data-attribute that would not otherwise exist. This is also the file the review that
 * started this pass singled out: a JS reformatting-only commit (block-runtime's resolveAttrKey)
 * once had to happen purely to keep a literal, whitespace-exact string match here — converting to
 * assertInlineScriptContains's tolerant matching is the actual fix for that. Real markup checks
 * (the language dropdown options, the translation rows, the per-locale title JSON island) now use
 * AssertsHtmlStructure instead of raw substring/regex/manual-substr scoping.
 */
class EditingLocaleTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutCsrfProtection();
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

    /**
     * The control lives on the CANVAS, on the locale badge above the post title — the element
     * that already named the language being edited. It used to sit in the topbar, far from the
     * content it governs and alongside view-level controls like device preview, which invited
     * reading a mode switch as a view filter.
     */
    public function test_the_language_control_sits_on_the_canvas_badge_not_the_topbar(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertElementExists($html, '.hb-page__locale [data-hb-lang-toggle]');
        $this->assertElementExists($html, '.hb-page__locale [data-hb-lang-option][data-locale="fr"]');
        // The badge is inside the control now, so it still names the current locale.
        $this->assertElementExists($html, '.hb-page__locale [data-hb-editing-locale-badge]');

        $this->assertElementMissing($html, '.hb-topbar__langsel');
        $this->assertElementMissing($html, '.hb-topbar__zone [data-hb-lang-toggle]');
    }

    public function test_topbar_language_dropdown_switches_locale_in_place_never_navigates(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // The switch itself — one clickable option per configured locale, wired to the runtime's
        // own editing-locale seam, not a fetch/navigation. Both attributes on the SAME element.
        $this->assertElementExists($html, '[data-hb-lang-option]');
        $this->assertElementExists($html, '[data-hb-lang-option][data-locale="en"]');
        $this->assertElementExists($html, '[data-hb-lang-option][data-locale="fr"]');
        $this->assertInlineScriptContains($html, 'window.hbEditor.setEditingLocale(opt.dataset.locale');

        // The Wave-1-era per-post navigation/creation affordances are gone outright.
        $this->assertElementMissing($html, '[data-hb-lang-open]');
        $this->assertElementMissing($html, '[data-hb-lang-create]');
        $this->assertInlineScriptDoesNotMatch($html, '/postTranslationsUrlTemplate/');
        $this->assertInlineScriptDoesNotMatch($html, '/postEditorUrlTemplate/');

        // Never disabled — switching which locale you are about to author in needs no saved post.
        $this->assertElementMissingAttribute($html, '[data-hb-lang-toggle]', 'disabled');
    }

    public function test_the_create_translation_endpoint_no_longer_exists(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        // The Wave-1-removed PostTranslationController route — nothing claims this path anymore.
        $this->postJson("/editor/posts/{$post->id}/translations", ['locale' => 'fr'])
            ->assertNotFound();
    }

    public function test_editor_show_seeds_the_home_locale_and_per_locale_titles(): void
    {
        $post = Post::create(['title_en' => 'English Title', 'title_fr' => 'French Title', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        // block-runtime.blade.php's window.__hbEditor seed — the "home locale" the bare/suffixed
        // write rule keys off never changes for this document's lifetime. Data literally handed to
        // the runtime's bootstrap script, so an inline-script assertion (not a data attribute) is
        // the right tool — whitespace-tolerant, so reformatting the object literal cannot break it.
        $this->assertInlineScriptContains($html, 'postLocale: ' . json_encode('en'));
        $this->assertInlineScriptContains($html, 'contentLocales: ' . json_encode(['en', 'fr']));

        // topbar's per-locale title cache — both configured locales' OWN raw column values, not
        // just the one currently on screen. Decoded from its own JSON data island rather than
        // hand-escaping the expected JSON and string-matching the whole attribute.
        $titlesByLocale = $this->hbDataJson($html, '[data-hb-title-by-locale]', 'data-hb-title-by-locale');
        $this->assertSame(['en' => 'English Title', 'fr' => 'French Title'], $titlesByLocale);
    }

    public function test_editor_index_seeds_the_config_default_locale_for_a_blank_document(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertInlineScriptContains($html, 'postLocale: ' . json_encode('en'));
        $titlesByLocale = $this->hbDataJson($html, '[data-hb-title-by-locale]', 'data-hb-title-by-locale');
        $this->assertSame(['en' => '', 'fr' => ''], $titlesByLocale);
    }

    public function test_translations_section_renders_plain_locale_rows_before_the_first_save(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-post-translations-field]');
        $this->assertElementExists($html, '[data-hb-translation-row][data-hb-translation-locale="en"]');
        $this->assertElementExists($html, '[data-hb-translation-row][data-hb-translation-locale="fr"]');
        $this->assertElementTextContains(
            $html,
            '[data-hb-post-translations-field]',
            __('heisenberg::editor.inspector.post_translations_needs_save'),
        );

        // No create/open/update affordances anywhere — there is nothing to create.
        $this->assertElementMissing($html, '[data-hb-translation-create]');
        $this->assertElementMissing($html, '[data-hb-translation-open]');
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
        // home-locale bare fallback — 100% complete. Scoped to the ROW's own text via a real DOM
        // query rather than manually slicing the raw HTML up to the next literal '</button>'
        // (fragile the moment the row gains a nested element that also happens to be a button).
        $enRow = '[data-hb-translation-row][data-hb-translation-locale="en"]';
        $this->assertElementTextContains($html, $enRow, __('heisenberg::editor.inspector.post_translations_complete'));

        // fr: title_fr is empty and the block has no content_fr variant — incomplete, and the
        // summary names BOTH gaps (title missing, 0/1 blocks translated).
        $frRow = '[data-hb-translation-row][data-hb-translation-locale="fr"]';
        $this->assertElementTextContains($html, $frRow, __('heisenberg::editor.inspector.post_translations_title_missing'));
        $this->assertElementTextContains($html, $frRow, '0/1');
        $frText = (string) $this->hbText($html, $frRow);
        $this->assertStringNotContainsString(__('heisenberg::editor.inspector.post_translations_complete'), $frText);
    }

    public function test_translations_section_click_switches_the_editing_locale_not_navigation(): void
    {
        $post = Post::create(['title_en' => 'Hello', 'locale' => 'en', 'status' => 'draft']);

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        $this->assertInlineScriptContains($html, 'window.hbEditor.setEditingLocale(row.dataset.hbTranslationLocale)');
        $this->assertElementMissing($html, '[data-hb-translation-open]');
        $this->assertElementMissing($html, '[data-hb-translation-create]');
        $this->assertElementMissing($html, '[data-hb-translation-update]');
    }

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

    // The write logic itself is client-side JS (block-runtime.blade.php) — there is no server
    // decision to exercise via HTTP, so these pin the SOURCE: one resolver, and every translatable
    // write path (inspector controls, canvas rich-text/contenteditable, Code view) routed through
    // it, per this wave's brief ("route them all through one client-side helper rather than
    // duplicating the suffix logic"). All whitespace-tolerant (assertInlineScriptContains), so a
    // pure reformatting of the underlying JS — the exact kind of change that once forced a whole
    // commit purely to satisfy this file's old exact-string match — no longer touches this test.

    public function test_the_home_locale_exemption_is_encoded_in_exactly_one_place(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Translatable + editing a DIFFERENT locale than the post's own → suffixed; every other
        // case (non-translatable, or editing the post's own home locale) → the bare key, which is
        // what LocalizedAttributes::read() falls back to and what every pre-translation post
        // already stores.
        $this->assertInlineScriptContains(
            $html,
            "function resolveAttrKey(name, key) {\n        return (isTranslatableAttr(name, key) && editingLocale !== homeLocale) ? key + '_' + editingLocale : key;\n    }",
        );
    }

    public function test_every_translatable_write_path_routes_through_the_shared_resolver(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Inspector controls (setAttribute — the one write path handleControlEvent uses).
        $this->assertInlineScriptContains($html, 'model.attributes[resolveAttrKey(model.name, key)] = value;');
        // Canvas contenteditable / rich-text commit.
        $this->assertInlineScriptContains($html, "model.attributes[resolveAttrKey(model.name, ce.getAttribute('data-hb-rt'))] = serializedEmailValue(ce);");
        // Code view: a plain `attr="value"` write and the rich-text body write.
        $this->assertInlineScriptContains($html, 'window.hbEditor.resolveAttrKey(model.name, name)');
        $this->assertInlineScriptContains($html, 'window.hbEditor.resolveAttrKey(frame.model.name, rich)');

        // Nothing writes a raw, unresolved key for content that came from the user — the two
        // remaining `model.attributes[...] =` assignments left in block-runtime.blade.php (columns
        // reconciliation, contract preset seeding) are both structural, never translatable text.
    }

    public function test_reads_fall_back_to_the_bare_key_matching_localized_attributes_read(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Mirrors Heisenberg\Support\LocalizedAttributes::read() exactly: `key_<editingLocale>`
        // when the block actually carries that variant, else the bare key.
        $this->assertInlineScriptContains($html, 'function readAttr(model, key)');
        $this->assertInlineScriptContains($html, 'const val = readAttr(model, node.attribute);');
        $this->assertInlineScriptContains($html, 'const raw = readAttr(model, node.attribute);');
        $this->assertInlineScriptContains($html, 'const v = readAttr(model, tok.slice(11));');

        // The inspector re-seeds every control through the SAME helper — a translated block must
        // show that locale's text after a selection change, not the bare/home value.
        $inspectorHtml = $this->get('/editor')->assertOk()->getContent();
        $this->assertInlineScriptContains($inspectorHtml, 'window.hbEditor.readAttr(model, key)');
    }

    // The data-loss bug: switching to French and asking the assistant to translate wiped the
    // English text, because applyCanvasTool's only move was window.hbEditor.replaceDoc() — a
    // whole-document swap that writes BARE keys, oblivious to the editing locale. The fix adds a
    // client-side mirror of McpToolRegistry::foldTranslatedBlocks()/foldNode() (the PHP rule
    // create_translation already enforces for a SAVED post): same position-matched fold, same
    // path-naming scheme, same refusal wording, exposed as window.hbEditor.foldTranslation and
    // wired into a new window.hbEditor.applyCanvasWrite that applyCanvasTool now calls instead of
    // deciding replace/append/fold itself.

    public function test_fold_translation_is_exposed_on_the_runtime_and_reuses_the_shared_resolver(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertInlineScriptContains($html, 'function foldTranslation(blocks)');
        $this->assertInlineScriptContains($html, 'foldTranslation: foldTranslation,');
        $this->assertInlineScriptContains($html, 'applyCanvasWrite: applyCanvasWrite,');
        // Reuses the ONE place the suffix rule lives, rather than re-deriving it.
        $this->assertInlineScriptContains($html, 'storedNode.attributes[resolveAttrKey(storedName, key)] = value;');
        $this->assertInlineScriptContains($html, 'translatableKeys(storedName).forEach');
    }

    public function test_fold_translation_mirrors_the_server_side_mismatch_wording_exactly(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Same path-naming scheme as McpToolRegistry::foldNodes()/foldNode() ("blocks[N]" top
        // level, ">N" per innerBlocks depth) and the SAME two mismatch messages
        // TranslationToolsTest pins server-side ("block count differs", "block name mismatch").
        $this->assertInlineScriptContains($html, "mismatches.push(path + ': block count differs (post has ' + storedNodes.length + ', translated code has ' + translatedNodes.length + ')');");
        $this->assertInlineScriptContains($html, "mismatches.push(path + \": block name mismatch ('\"");
        $this->assertInlineScriptContains($html, "mismatches.push(path + ': innerBlocks count differs (post has ' + storedInner.length + ', translated code has ' + translatedInner.length + ')');");
        $this->assertInlineScriptContains($html, "path + '[' + index + ']'");
        $this->assertInlineScriptContains($html, "path + '>' + index");
    }

    public function test_fold_translation_never_partially_applies_on_a_mismatch(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // A mismatch is collected into `mismatches` and returned as {ok:false, error} BEFORE
        // `doc.blocks` is ever reassigned — the mutation line only runs once the whole tree
        // matched.
        $this->assertInlineScriptContains($html, 'if (mismatches.length) {');
        $this->assertInlineScriptContains($html, 'doc.blocks = folded;');
    }
}
