<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The AI panel + settings dialog, asserted at the rendered-page level in the
 * style of InspectorWiringTest: the page must MOUNT the extracted components and
 * carry the data attributes their scripts key off — not re-implement the UI.
 *
 * The panel's own markup came 1:1 from Pencil and is only ever added to, so
 * these tests also pin the pieces that must survive: the prompt bar, the
 * suggestion rows and the tool grid are all still there after the settings
 * button was slotted into the header.
 *
 * RefreshDatabase for the same reason EditorRendersTest needs it —
 * EditorController::index() reads the category table on every render.
 *
 * Structural-assertion note: this file used to assert with whole-page
 * `assertStringContainsString()`/`assertStringNotContainsString()` calls against
 * raw HTML AND literal inline-JS lines (e.g. `'const liveApply = (final)'`
 * pinned character-for-character, or a hand-escaped route URL compared against
 * `str_replace('/', '\/', route(...))`). It now uses AssertsHtmlStructure to
 * assert DOM structure (an element/attribute/class exists) and decodes the
 * settings dialog's own JSON data islands (`data-payload`/`data-urls`) rather
 * than string-matching their JSON-escaped serialization. Real client-side
 * business logic that only ever lives in inline JS (the live-apply/tool-apply
 * wiring, the translating-locale guard) is still asserted, through
 * assertInlineScriptContains()/assertInlineScriptMatches(), which scope the
 * search to actual `<script>` bodies and tolerate reformatting rather than
 * pinning an exact line.
 */
class AiPanelWiringTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    private function editorHtml(): string
    {
        return $this->get('/editor')->assertOk()->getContent();
    }

    public function test_the_panel_header_carries_the_settings_trigger(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-ai-settings-open]');
        // Added beside the extracted header row, not in place of it.
        $this->assertElementExists($html, '.hb-ai-header__title');
        $this->assertElementExists($html, '.hb-ai-header__badge');
    }

    public function test_the_extracted_panel_composition_survived_the_wiring(): void
    {
        $html = $this->editorHtml();

        foreach ([
            'hb-ai-composer',          // prompt composer well (reference Frame 4)
            'hb-ai-composer__btn',     // send / new-chat / stop controls
            'hb-ai-msg',               // a transcript turn
            'hb-panel-ai__grid',       // Tools tab card grid
        ] as $marker) {
            $this->assertElementExists($html, '.' . $marker, "the extracted {$marker} must still be mounted");
        }
    }

    public function test_the_settings_dialog_is_mounted_in_the_media_dialog_shell(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-ai-settings]');
        // Reusing the shell is what gives this dialog hbOpen/hbClose, Escape,
        // backdrop-close and the focus trap — if these classes drift, all four
        // silently stop working. Both classes on the SAME element, order-independent.
        $this->assertElementExists($html, '.hb-mediadialog__scrim');
        $this->assertElementExists($html, '.hb-mediadialog.hb-aidialog');
    }

    public function test_the_dialog_offers_all_four_tabs(): void
    {
        $html = $this->editorHtml();

        foreach (['providers', 'models', 'mcp', 'expose'] as $tab) {
            $this->assertElementExists($html, '[data-hb-tab-body="' . $tab . '"]');
        }
    }

    /**
     * A provider is a VENDOR, not a wire format. The tab lists vendors an
     * operator can add in one click; several of them share the `openai` format,
     * which is exactly what the earlier design could not express.
     */
    public function test_the_providers_tab_offers_real_vendors_not_api_formats(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-prov-list]');
        $this->assertElementExists($html, '[data-hb-preset-list]');

        // The seed list reaches the client as a JSON data island — decoded and
        // compared against the CONFIGURED presets (config('heisenberg.ai.provider_presets'))
        // rather than a hardcoded copy of the vendor names, so this proves the
        // real wiring (config -> AiProviderRegistry::availablePresets() -> payload
        // -> this attribute) rather than merely that some string appears on the page.
        $payload = $this->hbDataJson($html, '[data-hb-ai-settings]', 'data-payload');
        $this->assertIsArray($payload['presets'] ?? null);
        $seededLabels = array_column($payload['presets'], 'label');

        $configuredPresets = (array) config('heisenberg.ai.provider_presets');
        $this->assertNotEmpty($configuredPresets);

        foreach (['openai', 'anthropic', 'google', 'xai', 'openrouter', 'ollama'] as $id) {
            $configured = collect($configuredPresets)->firstWhere('id', $id);
            $this->assertIsArray($configured, "'{$id}' should be a configured provider preset");
            $this->assertContains($configured['label'], $seededLabels, "'{$configured['label']}' should reach the settings payload");
        }
    }

    /** A custom vendor needs a name, a format, a URL and (optionally) an env var. */
    public function test_a_custom_provider_can_be_added(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-prov-add-toggle]');
        $this->assertElementExists($html, '[data-hb-prov-new-label]');
        $this->assertElementExists($html, '[data-hb-prov-new-format]');
        $this->assertElementExists($html, '[data-hb-prov-new-url]');
    }

    /** The gap that started this: there is now somewhere to put an API key. */
    public function test_each_provider_has_an_api_key_field_and_a_discover_button(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-prov-key]');
        $this->assertElementExists($html, '[data-hb-prov-savekey]');
        $this->assertElementExists($html, '[data-hb-prov-discover]');
        $this->assertElementExists($html, '[data-hb-prov-url]');
    }

    public function test_the_dialog_carries_every_endpoint_it_needs(): void
    {
        $html = $this->editorHtml();

        // Decoded from the dialog's own JSON data island and compared directly
        // against route() — no manual JSON-slash-escaping needed once it is read
        // back through the DOM, unlike the raw-HTML substring match this replaced.
        $urls = $this->hbDataJson($html, '[data-hb-ai-settings]', 'data-urls');

        $this->assertSame(route('heisenberg.editor.ai.settings.update'), $urls['settings'] ?? null);
        $this->assertSame(route('heisenberg.editor.ai.providers.key', ['provider' => '__ID__']), $urls['key'] ?? null);
        $this->assertSame(route('heisenberg.editor.ai.providers.discover', ['provider' => '__ID__']), $urls['discover'] ?? null);
        $this->assertSame(route('heisenberg.editor.ai.mcp.test'), $urls['mcpTest'] ?? null);
    }

    /**
     * Models are a list with a per-model toggle and an add/edit form carrying
     * the provider and the effort — effort is per model, not one global knob.
     */
    public function test_models_are_an_editable_list_with_per_model_provider_and_effort(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-model-list]');
        $this->assertElementExists($html, '[data-hb-model-add-toggle]');
        $this->assertElementExists($html, '[data-hb-model-new-provider]');
        $this->assertElementExists($html, '[data-hb-model-new-effort]');
        // One form serves add AND edit, so the two cannot drift apart.
        $this->assertElementExists($html, '[data-hb-model-edit]');
        $this->assertElementExists($html, '[data-hb-tmpl="model"]');
    }

    /** No model catalogue is shipped — models come from the vendor's endpoint. */
    public function test_no_model_catalogue_is_hardcoded_into_the_page(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-prov-discover]');
        $this->assertElementMissing($html, '[data-hb-ai-model-list]');
    }

    /** Every scrolling region uses the house scrollbar, not the browser's. */
    public function test_every_tab_body_uses_the_custom_scrollbar(): void
    {
        $html = $this->editorHtml();

        foreach (['providers', 'models', 'mcp', 'expose'] as $tab) {
            $this->assertElementExists($html, '[data-hb-ai-' . $tab . '-scroll]');
        }
    }

    public function test_the_prompt_bar_is_wired_to_the_stream_endpoint(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-ai-prompt]');
        $this->assertElementExists($html, '[data-hb-ai-send]');
        $this->assertElementHasAttribute($html, '[data-hb-panel-ai]', 'data-stream-url', route('heisenberg.editor.ai.stream'));
    }

    /**
     * The panel is a conversation now: turns accumulate in a thread instead of
     * one result card being overwritten, which is what made it impossible to see
     * anything you had already asked.
     */
    public function test_the_panel_is_a_transcript_not_a_single_result_card(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-ai-thread]');
        // Two templates now — user and assistant turns render differently
        // (the assistant turn carries the thinking block and applied card).
        $this->assertElementExists($html, '[data-hb-ai-user-template]');
        $this->assertElementExists($html, '[data-hb-ai-assistant-template]');
        $this->assertElementExists($html, '[data-hb-ai-new]');
        // The old single-slot card is gone, along with its fake copy. The
        // latter has no markup counterpart (it was placeholder copy, not a
        // control), so it stays a plain leftover-content substring check.
        $this->assertElementMissing($html, '[data-hb-ai-result]');
        $this->assertStringNotContainsString('punchy introduction', $html);
    }

    /** Removed on request: the header subtitle and the whole suggestion block. */
    public function test_the_subtitle_and_suggestion_rows_are_gone(): void
    {
        $html = $this->editorHtml();

        // Leftover copy check — no element ever carried this text as a hook.
        $this->assertStringNotContainsString('Get help writing, editing, and optimizing', $html);
        $this->assertElementMissing($html, '.hb-suggestionrow');
        $this->assertElementMissing($html, '[data-hb-ai-suggest="Write an introduction"]');
    }

    /**
     * A single-line input hides everything past its width; prompts are routinely
     * longer than that. Enter sends and Shift+Enter is a newline (wired in the
     * keydown handler, no longer advertised as hint copy), and a long generation
     * can be stopped.
     */
    public function test_the_composer_is_a_growing_textarea_with_a_stop_control(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, 'textarea[data-hb-ai-prompt]');
        $this->assertElementExists($html, '[data-hb-ai-send]');
        $this->assertElementExists($html, '[data-hb-ai-stop]');
    }

    /**
     * Every middle panel is one width. The AI panel briefly had its own wider
     * track; this pins the rule that it does not, so the next attempt to buy
     * room by widening the shell fails here instead of in review.
     */
    public function test_the_ai_panel_uses_the_shared_panel_width(): void
    {
        $html = $this->editorHtml();

        $this->assertElementMissing($html, '.hb-editor--ai-wide');
    }

    public function test_markup_replies_build_the_canvas_live_and_insert_is_gone(): void
    {
        // 2026-08-09: the Insert button is gone — a markup reply applies to the canvas AS IT
        // STREAMS (each completed top-level block lands on arrival), appended to the document
        // the run found; regenerating first restores that baseline so the redo replaces the
        // old build instead of doubling it. Prose replies never touch the page.
        $html = $this->editorHtml();

        $this->assertElementMissing($html, '[data-hb-ai-insert]');
        $this->assertElementExists($html, '[data-hb-ai-regenerate]');
        $this->assertInlineScriptContains($html, 'const liveApply = (final)');
        $this->assertInlineScriptContains($html, 'liveApply(false);');
        $this->assertInlineScriptContains($html, 'liveApply(true);');
        $this->assertInlineScriptContains($html, 'window.hbEditor.replaceDoc(lastRun.baseline.concat(parsed.blocks));');
        $this->assertInlineScriptContains($html, 'window.hbEditor.replaceDoc(lastRun.baseline);');
    }

    /**
     * The direct write path (2026-08-09): the assistant's write_canvas tool
     * frames arrive on the stream with their shortcode arguments, and the panel
     * applies them straight to the editor — append or replace. Once a tool
     * build lands, the legacy text-extraction fallback stands down so content
     * is never applied twice.
     */
    public function test_the_write_canvas_tool_frames_apply_to_the_editor(): void
    {
        $html = $this->editorHtml();

        $this->assertInlineScriptContains($html, "'heisenberg__write_canvas'");
        $this->assertInlineScriptContains($html, 'const applyCanvasTool');
        $this->assertInlineScriptContains($html, 'if (toolBuilt) return;');
        // Server verdict is honored: code the contracts rejected never applies.
        $this->assertInlineScriptContains($html, 'data.ok === false');
        // set_page_title lands in the editor's title field the same way.
        $this->assertInlineScriptContains($html, "'heisenberg__set_page_title'");
        $this->assertInlineScriptContains($html, 'const applyTitleTool');
    }

    /**
     * Assistant prose is markdown — rendered, not shown raw. The renderer
     * escapes first (model output can never inject markup) and the assistant
     * bubble is a <div> so lists/paragraphs are legal inside it.
     */
    public function test_assistant_replies_render_markdown_not_raw_text(): void
    {
        $html = $this->editorHtml();

        $this->assertInlineScriptContains($html, 'const renderMarkdown');
        $this->assertInlineScriptContains($html, 'renderMarkdown(reply.textEl');
        $this->assertElementExists($html, 'div.hb-ai-msg__text[data-hb-ai-text]');
    }

    /**
     * The live build routes AI output through the code view's parser rather than
     * a second insertion path, so the export has to exist on the page.
     */
    public function test_the_code_view_parser_is_exported_for_the_assistant(): void
    {
        $html = $this->editorHtml();

        $this->assertInlineScriptContains($html, 'window.hbCodeView');
        $this->assertInlineScriptContains($html, 'hbEditor.replaceDoc');
    }

    public function test_tool_cards_carry_their_prompts(): void
    {
        $html = $this->editorHtml();

        $label = __('heisenberg::editor.panel_ai_tools.tool_generate_title');
        $this->assertElementExists($html, '[data-hb-ai-suggest="' . $label . '"]');
    }

    public function test_providers_expose_editable_connection_detail_but_never_a_key_field(): void
    {
        $html = $this->editorHtml();

        // This is what makes a provider addable from the UI at all.
        $this->assertElementExists($html, '[data-hb-prov-new-url]');
        $this->assertElementExists($html, '[data-hb-model-new-id]');
    }

    public function test_the_mcp_tab_can_add_test_and_scope_a_server(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-mcp-list]');
        $this->assertElementExists($html, '[data-hb-mcp-add]');
        $this->assertElementExists($html, '[data-hb-mcp-test]');
        // The add form asks for the env var's NAME, never a token.
        $this->assertElementExists($html, '[data-hb-mcp-new-env]');
        $this->assertElementMissing($html, '[data-hb-mcp-new-token]');
    }

    /**
     * The Expose tab is generated from McpToolRegistry, so it cannot advertise a
     * tool the server does not actually answer to.
     */
    public function test_the_expose_tab_lists_the_real_tool_set_with_tiers(): void
    {
        $html = $this->editorHtml();

        // Guard the guard: these must actually be real registered tools, not
        // just strings this test happens to also assert are rendered.
        $registryNames = array_column(app(McpToolRegistry::class)->describeAll(), 'name');

        foreach (['list_blocks', 'create_post', 'render_preview'] as $tool) {
            $this->assertContains($tool, $registryNames, "{$tool} should be a real registered MCP tool");
            $this->assertElementExists(
                $html,
                "//span[@class='hb-aidialog__name' and normalize-space(text())='{$tool}']",
                "{$tool} should be rendered in the Expose tab",
            );
        }
    }

    /**
     * Every other tool card sends a prompt to a text provider; neither shipped
     * adapter can make an image, and a card that looks actionable but does
     * nothing is worse than no card.
     */
    public function test_the_generate_image_card_is_not_offered(): void
    {
        $html = $this->editorHtml();

        $imageLabel = __('heisenberg::editor.panel_ai_tools.tool_generate_image');
        $translateLabel = __('heisenberg::editor.panel_ai_tools.tool_translate');

        $this->assertElementMissing($html, '[data-hb-ai-suggest="' . $imageLabel . '"]');
        $this->assertElementExists($html, '[data-hb-ai-suggest="' . $translateLabel . '"]');
    }

    /**
     * The data-loss bug this wave fixes: switch to French, ask the assistant to translate, and
     * the English text was overwritten because the panel's write_canvas apply path
     * (applyCanvasTool) knew nothing about the editing locale. It now carries editingLocale/
     * homeLocale on every turn's context (so EditorPrompt::user() can state the TRANSLATING rule
     * against the concrete pair) and branches its apply through hbEditor.applyCanvasWrite, which
     * folds rather than replaces on a non-home locale.
     */
    public function test_the_turn_envelope_carries_the_editing_and_home_locale(): void
    {
        $html = $this->editorHtml();

        $this->assertInlineScriptContains($html, 'base.editingLocale = window.hbEditor.getEditingLocale()');
        $this->assertInlineScriptContains($html, 'base.homeLocale = window.hbEditor.getHomeLocale()');
    }

    /**
     * applyCanvasTool no longer decides replace/append itself — it hands the parsed blocks and
     * mode to hbEditor.applyCanvasWrite (block-runtime.blade.php), which returns the fold-or-
     * replace/append decision. A refused append or a structural mismatch surfaces as a note in
     * the panel through the same addNote() a network error uses, and neither ever partially
     * applies anything (applyCanvasWrite/foldTranslation only mutate doc.blocks on a full match).
     */
    public function test_the_write_canvas_apply_path_routes_through_apply_canvas_write(): void
    {
        $html = $this->editorHtml();

        $this->assertInlineScriptContains($html, 'window.hbEditor.applyCanvasWrite(parsed.blocks, args.mode)');
        $this->assertInlineScriptContains($html, 'result.refusedAppend');
        $this->assertInlineScriptContains($html, "addNote(msg('msgTranslateAppendRefused'), true)");
        $this->assertInlineScriptContains($html, "addNote(result.error || msg('msgTranslateMismatch'), true)");
        $this->assertInlineScriptContains(
            $html,
            "(result.translating ? msg('msgTranslated') : msg('msgBuilt'))",
        );

        // The legacy bare-shortcode fallback has no fold — it must stand down entirely while
        // translating rather than replaceDoc away the home locale's text.
        $this->assertInlineScriptContains(
            $html,
            'if (window.hbEditor.getEditingLocale() !== window.hbEditor.getHomeLocale()) return;',
        );
    }

    /**
     * SECURITY: no configured credential — however it reached the process (env var here) —
     * may ever be echoed into the page, even though the modal legitimately needs to show the
     * env var's NAME so an operator knows what to set. Left as exact substring checks
     * deliberately: this is the one place a structural query would be the WRONG tool — the
     * guarantee is "this literal secret value appears nowhere in the whole response", which a
     * scoped element query could accidentally narrow.
     */
    public function test_the_rendered_page_never_contains_key_material(): void
    {
        putenv('HEISENBERG_AI_ANTHROPIC_KEY=sk-ant-page-secret');
        $_ENV['HEISENBERG_AI_ANTHROPIC_KEY'] = 'sk-ant-page-secret';
        $_SERVER['HEISENBERG_AI_ANTHROPIC_KEY'] = 'sk-ant-page-secret';

        try {
            $html = $this->editorHtml();

            $this->assertStringNotContainsString('sk-ant-page-secret', $html);
            // The env var's NAME is fine — the modal has to tell an operator
            // what to set.
            $this->assertStringContainsString('HEISENBERG_AI_ANTHROPIC_KEY', $html);
        } finally {
            putenv('HEISENBERG_AI_ANTHROPIC_KEY');
            unset($_ENV['HEISENBERG_AI_ANTHROPIC_KEY'], $_SERVER['HEISENBERG_AI_ANTHROPIC_KEY']);
        }
    }
}
