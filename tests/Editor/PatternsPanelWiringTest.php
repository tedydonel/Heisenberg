<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Http\Controllers\EditorController;
use Heisenberg\Models\Pattern;
use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Saved patterns, end to end through the EDITOR's markup.
 *
 * Every piece of this feature existed — the table, the model, the controller, the routes, the
 * toolbar's save popover, the panel's grid and `hbEditor.insertPattern()` — but the editor view
 * never passed the panel its URLs or its rows, so `data-hb-patterns-index-url` rendered empty:
 * the tab could not list anything and the toolbar's save had nowhere to post. The controller has
 * always built those values ({@see EditorController::sharedViewData()});
 * nothing consumed them. These assertions are on the rendered page for that reason — the wiring
 * is exactly what was missing, so it is exactly what gets pinned.
 */
class PatternsPanelWiringTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    private function editorHtml(): string
    {
        return $this->get('/editor')->getContent();
    }

    public function test_the_panel_receives_the_urls_its_grid_and_the_toolbar_save_need(): void
    {
        $html = $this->editorHtml();

        foreach (['index', 'store', 'destroy'] as $action) {
            $value = $this->hbAttr($html, '[data-hb-panel-cb]', 'data-hb-patterns-' . $action . '-url');
            $this->assertNotNull($value, "the panel carries no {$action} url attribute at all");
            $this->assertNotSame('', trim((string) $value), "the {$action} url renders empty, so that call can never fire");
            $this->assertStringContainsString('/editor/patterns', (string) $value);
        }
    }

    /** A saved pattern is on the page when the editor loads — not only after something changes. */
    public function test_saved_patterns_render_into_the_grid_on_load(): void
    {
        Pattern::create([
            'name' => 'Hero with image',
            'blocks' => [[
                'id' => 'p1',
                'name' => 'heisenberg/group',
                'schemaVersion' => '1.0.0',
                'attributes' => [],
                'supports' => [],
                'innerBlocks' => [],
            ]],
        ]);

        $html = $this->editorHtml();

        $card = '[data-hb-patterns-grid] [data-hb-saved-block][data-hb-pattern-name="Hero with image"]';
        $this->assertElementExists($html, $card);
        $this->assertElementExists($html, '[data-hb-patterns-grid] [data-hb-pattern-delete]');
        $this->assertElementMissing($html, '[data-hb-patterns-empty]');
    }

    public function test_an_install_with_no_patterns_says_how_to_make_one(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-patterns-empty]');
        $this->assertElementTextContains($html, '[data-hb-patterns-empty]', 'Save as pattern');
    }

    /** The tab, the sidebar entry and the toolbar button all call it a pattern now. */
    public function test_the_feature_is_named_patterns_in_the_ui(): void
    {
        $html = $this->editorHtml();

        $this->assertElementExists($html, '[data-hb-toolbar] [data-tb-action="save"][aria-label="Save as pattern"]');
        $this->assertStringContainsString('>Patterns</button>', $html);
        $this->assertStringContainsString('<span>Patterns</span>', $html);
    }

    /**
     * REGRESSION. The grid is rendered twice — by Blade on load, and by refreshBlocksTab() after
     * a pattern is saved or deleted — and the two had drifted: the JS built a bare wrapper with a
     * `hb-tcard__label` span (a class that exists nowhere) and no tool-card at all, so every card
     * lost its icon and framing the moment the list refreshed. The JS now clones the ONE card
     * shape this template holds, which is the only way the two stay identical.
     */
    public function test_the_rebuilt_card_clones_the_same_markup_blade_renders(): void
    {
        $html = $this->editorHtml();

        $template = 'template[data-hb-pattern-card-template]';
        $this->assertElementExists($html, $template);
        // The template holds a real tool-card (icon + label) and the delete control.
        $this->assertElementExists($html, $template . ' .hb-panel-cb__card .hb-toolcard .hb-toolcard__label');
        $this->assertElementExists($html, $template . ' [data-hb-pattern-delete]');

        $this->assertInlineScriptContains($html, "root.querySelector('[data-hb-pattern-card-template]')");
        $this->assertInlineScriptContains($html, "card.querySelector('.hb-toolcard__label')");
        // The markup the drift produced must not come back.
        $this->assertStringNotContainsString('hb-tcard__label', $html);
    }

    /**
     * REGRESSION. `closeAll` lives in one toolbar's boot() closure; the top-level
     * openSaveBlockDialog() called it by name, threw a ReferenceError, and the save popover never
     * opened — the feature's only entry point.
     */
    public function test_the_save_dialog_is_handed_the_close_callback_it_cannot_see(): void
    {
        $html = $this->editorHtml();

        $this->assertInlineScriptContains($html, 'function openSaveBlockDialog(tb, ctx, closeAll)');
        $this->assertInlineScriptContains($html, 'openSaveBlockDialog(tb, ctx, closeAll);');
        $this->assertInlineScriptContains($html, "if (typeof closeAll === 'function') closeAll();");
    }
}
