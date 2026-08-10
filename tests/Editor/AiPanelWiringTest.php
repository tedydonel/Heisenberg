<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

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
 */
class AiPanelWiringTest extends TestCase
{
    use RefreshDatabase;

    private function editorHtml(): string
    {
        return $this->get('/editor')->assertOk()->getContent();
    }

    public function test_the_panel_header_carries_the_settings_trigger(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-ai-settings-open', $html);
        // Added beside the extracted header row, not in place of it.
        $this->assertStringContainsString('hb-ai-header__title', $html);
        $this->assertStringContainsString('hb-ai-header__badge', $html);
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
            $this->assertStringContainsString($marker, $html, "the extracted {$marker} must still be mounted");
        }
    }

    public function test_the_settings_dialog_is_mounted_in_the_media_dialog_shell(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-ai-settings', $html);
        // Reusing the shell is what gives this dialog hbOpen/hbClose, Escape,
        // backdrop-close and the focus trap — if these classes drift, all four
        // silently stop working.
        $this->assertStringContainsString('hb-mediadialog__scrim', $html);
        $this->assertStringContainsString('hb-mediadialog hb-aidialog', $html);
    }

    public function test_the_dialog_offers_all_four_tabs(): void
    {
        $html = $this->editorHtml();

        foreach (['providers', 'models', 'mcp', 'expose'] as $tab) {
            $this->assertStringContainsString('data-hb-tab-body="' . $tab . '"', $html);
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

        $this->assertStringContainsString('data-hb-prov-list', $html);
        $this->assertStringContainsString('data-hb-preset-list', $html);
        // The seed list reaches the client as JSON, so the vendor names appear.
        foreach (['OpenAI', 'Anthropic', 'xAI', 'Google Gemini', 'OpenRouter', 'Ollama'] as $vendor) {
            $this->assertStringContainsString($vendor, $html);
        }
    }

    /** A custom vendor needs a name, a format, a URL and (optionally) an env var. */
    public function test_a_custom_provider_can_be_added(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-prov-add-toggle', $html);
        $this->assertStringContainsString('data-hb-prov-new-label', $html);
        $this->assertStringContainsString('data-hb-prov-new-format', $html);
        $this->assertStringContainsString('data-hb-prov-new-url', $html);
    }

    /** The gap that started this: there is now somewhere to put an API key. */
    public function test_each_provider_has_an_api_key_field_and_a_discover_button(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-prov-key', $html);
        $this->assertStringContainsString('data-hb-prov-savekey', $html);
        $this->assertStringContainsString('data-hb-prov-discover', $html);
        $this->assertStringContainsString('data-hb-prov-url', $html);
    }

    public function test_the_dialog_carries_every_endpoint_it_needs(): void
    {
        $html = $this->editorHtml();

        foreach ([
            route('heisenberg.editor.ai.settings.update'),
            route('heisenberg.editor.ai.providers.key', ['provider' => '__ID__']),
            route('heisenberg.editor.ai.providers.discover', ['provider' => '__ID__']),
            route('heisenberg.editor.ai.mcp.test'),
        ] as $url) {
            $this->assertStringContainsString(str_replace('/', '\/', $url), $html);
        }
    }

    /**
     * Models are a list with a per-model toggle and an add/edit form carrying
     * the provider and the effort — effort is per model, not one global knob.
     */
    public function test_models_are_an_editable_list_with_per_model_provider_and_effort(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-model-list', $html);
        $this->assertStringContainsString('data-hb-model-add-toggle', $html);
        $this->assertStringContainsString('data-hb-model-new-provider', $html);
        $this->assertStringContainsString('data-hb-model-new-effort', $html);
        // One form serves add AND edit, so the two cannot drift apart.
        $this->assertStringContainsString('data-hb-model-edit', $html);
        $this->assertStringContainsString('data-hb-tmpl="model"', $html);
    }

    /** No model catalogue is shipped — models come from the vendor's endpoint. */
    public function test_no_model_catalogue_is_hardcoded_into_the_page(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-prov-discover', $html);
        $this->assertStringNotContainsString('data-hb-ai-model-list', $html);
    }

    /** Every scrolling region uses the house scrollbar, not the browser's. */
    public function test_every_tab_body_uses_the_custom_scrollbar(): void
    {
        $html = $this->editorHtml();

        foreach (['providers', 'models', 'mcp', 'expose'] as $tab) {
            $this->assertStringContainsString('[data-hb-ai-' . $tab . '-scroll]', $html);
        }
    }

    public function test_the_prompt_bar_is_wired_to_the_stream_endpoint(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-ai-prompt', $html);
        $this->assertStringContainsString('data-hb-ai-send', $html);
        $this->assertStringContainsString('data-stream-url="' . route('heisenberg.editor.ai.stream') . '"', $html);
    }

    /**
     * The panel is a conversation now: turns accumulate in a thread instead of
     * one result card being overwritten, which is what made it impossible to see
     * anything you had already asked.
     */
    public function test_the_panel_is_a_transcript_not_a_single_result_card(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-ai-thread', $html);
        // Two templates now — user and assistant turns render differently
        // (the assistant turn carries the thinking block and applied card).
        $this->assertStringContainsString('data-hb-ai-user-template', $html);
        $this->assertStringContainsString('data-hb-ai-assistant-template', $html);
        $this->assertStringContainsString('data-hb-ai-new', $html);
        // The old single-slot card is gone, along with its fake copy.
        $this->assertStringNotContainsString('data-hb-ai-result', $html);
        $this->assertStringNotContainsString('punchy introduction', $html);
    }

    /** Removed on request: the header subtitle and the whole suggestion block. */
    public function test_the_subtitle_and_suggestion_rows_are_gone(): void
    {
        $html = $this->editorHtml();

        $this->assertStringNotContainsString('Get help writing, editing, and optimizing', $html);
        $this->assertStringNotContainsString('hb-suggestionrow', $html);
        $this->assertStringNotContainsString('data-hb-ai-suggest="Write an introduction"', $html);
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

        $this->assertMatchesRegularExpression('/<textarea[^>]*data-hb-ai-prompt/', $html);
        $this->assertStringContainsString('data-hb-ai-send', $html);
        $this->assertStringContainsString('data-hb-ai-stop', $html);
    }

    /**
     * Every middle panel is one width. The AI panel briefly had its own wider
     * track; this pins the rule that it does not, so the next attempt to buy
     * room by widening the shell fails here instead of in review.
     */
    public function test_the_ai_panel_uses_the_shared_panel_width(): void
    {
        $html = $this->editorHtml();

        $this->assertStringNotContainsString('hb-editor--ai-wide', $html);
    }

    public function test_markup_replies_build_the_canvas_live_and_insert_is_gone(): void
    {
        // 2026-08-09: the Insert button is gone — a markup reply applies to the canvas AS IT
        // STREAMS (each completed top-level block lands on arrival), appended to the document
        // the run found; regenerating first restores that baseline so the redo replaces the
        // old build instead of doubling it. Prose replies never touch the page.
        $html = $this->editorHtml();

        $this->assertStringNotContainsString('data-hb-ai-insert', $html);
        $this->assertStringContainsString('data-hb-ai-regenerate', $html);
        $this->assertStringContainsString('const liveApply = (final)', $html);
        $this->assertStringContainsString('liveApply(false);', $html);
        $this->assertStringContainsString('liveApply(true);', $html);
        $this->assertStringContainsString('window.hbEditor.replaceDoc(lastRun.baseline.concat(parsed.blocks));', $html);
        $this->assertStringContainsString('window.hbEditor.replaceDoc(lastRun.baseline);', $html);
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

        $this->assertStringContainsString("'heisenberg__write_canvas'", $html);
        $this->assertStringContainsString('const applyCanvasTool', $html);
        $this->assertStringContainsString('if (toolBuilt) return;', $html);
        // Server verdict is honored: code the contracts rejected never applies.
        $this->assertStringContainsString('data.ok === false', $html);
        // set_page_title lands in the editor's title field the same way.
        $this->assertStringContainsString("'heisenberg__set_page_title'", $html);
        $this->assertStringContainsString('const applyTitleTool', $html);
    }

    /**
     * Assistant prose is markdown — rendered, not shown raw. The renderer
     * escapes first (model output can never inject markup) and the assistant
     * bubble is a <div> so lists/paragraphs are legal inside it.
     */
    public function test_assistant_replies_render_markdown_not_raw_text(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('const renderMarkdown', $html);
        $this->assertStringContainsString('renderMarkdown(reply.textEl', $html);
        $this->assertMatchesRegularExpression('/<div class="hb-ai-msg__text" data-hb-ai-text>/', $html);
    }

    /**
     * The live build routes AI output through the code view's parser rather than
     * a second insertion path, so the export has to exist on the page.
     */
    public function test_the_code_view_parser_is_exported_for_the_assistant(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('window.hbCodeView', $html);
        $this->assertStringContainsString('hbEditor.replaceDoc', $html);
    }

    public function test_tool_cards_carry_their_prompts(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-ai-suggest="Generate Title"', $html);
    }

    public function test_providers_expose_editable_connection_detail_but_never_a_key_field(): void
    {
        $html = $this->editorHtml();

        // This is what makes a provider addable from the UI at all.
        $this->assertStringContainsString('data-hb-prov-new-url', $html);
        $this->assertStringContainsString('data-hb-model-new-id', $html);
    }

    public function test_the_mcp_tab_can_add_test_and_scope_a_server(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-hb-mcp-list', $html);
        $this->assertStringContainsString('data-hb-mcp-add', $html);
        $this->assertStringContainsString('data-hb-mcp-test', $html);
        // The add form asks for the env var's NAME, never a token.
        $this->assertStringContainsString('data-hb-mcp-new-env', $html);
        $this->assertStringNotContainsString('data-hb-mcp-new-token', $html);
    }

    /**
     * The Expose tab is generated from McpToolRegistry, so it cannot advertise a
     * tool the server does not actually answer to.
     */
    public function test_the_expose_tab_lists_the_real_tool_set_with_tiers(): void
    {
        $html = $this->editorHtml();

        foreach (['list_blocks', 'create_post', 'render_preview'] as $tool) {
            $this->assertStringContainsString($tool, $html);
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

        $this->assertStringNotContainsString('data-hb-ai-suggest="Generate Image"', $html);
        $this->assertStringContainsString('data-hb-ai-suggest="Translate"', $html);
    }

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
