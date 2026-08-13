<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * live/pickers/color-picker.blade.php. The first build of this component shipped two
 * click-to-cycle buttons where dropdowns belonged (gradient type could only ever express
 * 2 of the 3 CSS gradient functions, and the colour model was a static <span> reading
 * "RGBA" that nothing could change), and it put the colour editor *inside* the Fill tab,
 * so editing a gradient stop's colour navigated away from the ramp being edited.
 *
 * These assertions pin the corrected structure. The picker is rendered by the inspector's
 * Style panel, so /editor carries it.
 *
 * RefreshDatabase (2026-08-04): /editor now reads the category table on every render (Phase
 * 3.1's Categories combobox seed) — see EditorRendersTest's own note on the same change.
 */
class ColorPickerTest extends TestCase
{
    use RefreshDatabase;


    private function editorHtml(): string
    {
        return $this->get('/editor')->getContent();
    }

    public function test_gradient_type_is_a_select_offering_all_three_css_gradient_functions(): void
    {
        $html = $this->editorHtml();

        // ui/select, not the old two-state toggle button.
        $this->assertStringContainsString('data-cp-gradient-type', $html);
        $this->assertMatchesRegularExpression(
            '/data-hb-select[^>]*data-cp-gradient-type|data-cp-gradient-type[^>]*data-hb-select/',
            $html,
            'The gradient type control must be a ui/select, not a bespoke button',
        );

        foreach (['linear', 'radial', 'conic'] as $type) {
            $this->assertStringContainsString('data-hb-select-option="' . $type . '"', $html);
        }
    }

    public function test_radial_gradients_expose_a_shape_select(): void
    {
        $html = $this->editorHtml();

        // radial-gradient() takes circle|ellipse; the angle field is meaningless for it.
        // Both controls ship, and the script swaps them on type change.
        $this->assertStringContainsString('data-cp-gradient-shape', $html);
        $this->assertStringContainsString('data-hb-select-option="circle"', $html);
        $this->assertStringContainsString('data-hb-select-option="ellipse"', $html);
        $this->assertStringContainsString('if (angleWrap) angleWrap.hidden = isRadial;', $html);

        // Default type is linear, so shape must render hidden — and the attribute has to
        // survive ui/select's $attributes->merge() to get there.
        $this->assertMatchesRegularExpression(
            '/data-cp-gradient-shape[^>]*\shidden|hidden[^>]*\sdata-cp-gradient-shape/',
            $html,
            'The radial shape select must start hidden under the default linear type',
        );
    }

    public function test_fill_tab_colour_model_is_a_select_not_a_static_rgba_label(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-cp-model', $html);
        foreach (['hex', 'rgb', 'rgba', 'hsl', 'hsla', 'hsb'] as $model) {
            $this->assertStringContainsString('data-hb-select-option="' . $model . '"', $html);
        }

        // The dead label the select replaced.
        $this->assertStringNotContainsString('<span class="hb-cp__model"', $html);
    }

    public function test_every_colour_model_has_a_field_set_and_a_parse_branch(): void
    {
        $html = $this->editorHtml();

        // Switching the model rebuilds the input row from MODELS, so a model listed in the
        // dropdown with no entry here would render an empty row.
        foreach (['hex:', 'rgb:', 'rgba:', 'hsl:', 'hsla:', 'hsb:'] as $key) {
            $this->assertStringContainsString($key . ' [{ k:', $html);
        }

        // ...and writing a value back needs the inverse conversion to exist.
        $this->assertStringContainsString('function hslToRgb(', $html);
        $this->assertStringContainsString('function rgbToHsl(', $html);
    }

    public function test_colour_editor_is_shared_by_both_tabs_rather_than_living_in_fill(): void
    {
        $html = $this->editorHtml();

        // One editor, mounted outside both bodies. Gradient mode points it at the selected
        // stop instead of switching the user to the Fill tab.
        $this->assertStringContainsString('data-cp-editor', $html);
        $this->assertStringContainsString('data-cp-body="gradient"', $html);
        $this->assertStringNotContainsString('data-cp-body="fill"', $html);
        $this->assertStringContainsString('function loadSelectedStopIntoEditor()', $html);
    }

    public function test_gradient_stops_are_draggable_and_keyboard_reachable(): void
    {
        $html = $this->editorHtml();

        // Typing a number was the only way to move a stop in the first build.
        $this->assertStringContainsString('data-cp-gradient-stop-handle', $html);
        $this->assertStringContainsString("gradientBar.addEventListener('mousedown'", $html);
        $this->assertStringContainsString("gradientBar.addEventListener('keydown'", $html);
    }

    public function test_gradient_stop_editing_survives_the_resort_that_dragging_causes(): void
    {
        $html = $this->editorHtml();

        // Stops sort by position, which is the value a drag changes — so index-based tracking
        // loses the dragged stop the moment it crosses a neighbour. Identity is a per-stop id.
        $this->assertStringContainsString('function sortStops(keepId)', $html);
        $this->assertStringContainsString('gradient.stops.findIndex((stop) => stop.id === id)', $html);
    }

    public function test_gradient_gains_distribute_and_duplicate_actions(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('data-cp-gradient-distribute', $html);
        $this->assertStringContainsString('data-cp-gradient-duplicate', $html);
        // Pre-existing actions must survive the rework — EditorRendersTest also pins these.
        $this->assertStringContainsString('data-cp-gradient-add', $html);
        $this->assertStringContainsString('data-cp-gradient-reverse', $html);
    }

    public function test_new_stops_sample_the_ramp_instead_of_a_hardcoded_grey(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('function sampleAt(position)', $html);
        $this->assertStringNotContainsString("color: '#808080', opacity: 100 }; }", $html);
    }

    public function test_gradient_css_emits_all_three_functions_with_alpha_aware_stops(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString("'radial-gradient('", $html);
        $this->assertStringContainsString("'conic-gradient(from '", $html);
        $this->assertStringContainsString("'linear-gradient('", $html);
        // Stops are emitted as rgba() rather than hex with an appended alpha pair, so a stop
        // colour that already carries its own alpha can't produce a 10-digit hex.
        $this->assertStringContainsString("'rgba(' + parsed[0]", $html);
    }

    public function test_picker_keeps_the_event_contract_the_inspector_listens_on(): void
    {
        $html = $this->editorHtml();

        // inspector.blade.php binds `colorchange` and `gradientchange` and reads
        // detail.gradientStop / detail.css. Reworking the picker must not break that.
        $this->assertStringContainsString("new CustomEvent('colorchange'", $html);
        $this->assertStringContainsString("new CustomEvent('gradientchange'", $html);
        $this->assertStringContainsString('gradientStop: mode === ', $html);
        $this->assertStringContainsString("document.addEventListener('colorchange'", $html);
        $this->assertStringContainsString("document.addEventListener('gradientchange'", $html);

        // The public API the inspector calls. Asserted member by member rather than as one
        // literal, so adding a method doesn't fail a test about the existing ones.
        foreach (['setHex', 'setGradient', 'setGradientCss', 'getValue', 'setMode'] as $method) {
            $this->assertMatchesRegularExpression('/root\.__hbCp = \{[^}]*\b' . $method . '\b/', $html);
        }
    }

    public function test_picker_chrome_is_localised(): void
    {
        $html = $this->editorHtml();

        // The picker shipped with ~20 hardcoded English strings and zero __() calls, which the
        // Phase 4 i18n pass missed. Notation (HEX/RGB/R/G/B) stays untranslated by design.
        $this->assertStringContainsString('Distribute stops evenly', $html);

        $this->app->setLocale('fr');
        $french = $this->get('/editor')->getContent();

        $this->assertStringContainsString('Répartir les étapes uniformément', $french);
        $this->assertStringNotContainsString('Distribute stops evenly', $french);
        // Colour models are notation and stay identical in both locales.
        $this->assertStringContainsString('>RGBA<', $french);
    }

    /**
     * Bug B (2026-08-13): the gradient UI used to keep its shared flat-colour editor mounted
     * underneath the gradient section itself — a colour picker nested inside the picker that
     * opened it. A gradient stop's swatch now opens a SEPARATE `standalone` instance of this
     * same component (no Fill/Gradient tabs, no gradient section) anchored to that stop, rather
     * than reusing the editor sitting inside the gradient popup.
     */
    /**
     * Reopening a saved gradient used to land on the Fill tab showing black/white: the opener
     * seeded from the row's hex field (which holds the Gradient tab's label, not a colour), and
     * setGradient() ran rgba() stops through parseHex(), which does not read them.
     */
    public function test_the_colour_popup_reseeds_a_saved_gradient_instead_of_falling_back_to_flat(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('setGradientCss', $html);
        $this->assertStringContainsString("layer?.dataset.hbStyleGradient || ''", $html);
        // rgba() stops must be understood, not silently replaced by the #000000 fallback.
        $this->assertStringContainsString('rgba?\\(([^)]*)\\)', $html);
    }

    /**
     * The stop editor stacks on top of the gradient popup that owns it, so clicking back onto
     * that popup — or anywhere on the canvas, which never reaches a Style panel — must dismiss
     * it. Both paths previously left it open indefinitely.
     */
    public function test_the_gradient_stop_popup_closes_on_an_outside_click(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString("root.querySelector('[data-hb-style-popup=\"gradient-stop\"]')", $html);
        $this->assertStringContainsString('stopPopup.hidden = true;', $html);
        // Its own trigger is exempt, or the picker's click handler would open and this would
        // close it within the same event.
        $this->assertStringContainsString("[data-cp-gradient-stop-select]", $html);
        // A canvas click closes every panel's popups despite never reaching a Style root.
        $this->assertStringContainsString("document.querySelectorAll('.hb-blockstyle').forEach((panel) => closeStylePopups(panel));", $html);
    }

    public function test_a_gradient_stop_opens_a_standalone_picker_with_no_nested_gradient_section(): void
    {
        $html = $this->editorHtml();

        // The wiring: a stop's swatch dispatches the event, a document listener opens the
        // standalone popup and hands the write-back callback the stop provided.
        $this->assertStringContainsString("new CustomEvent('gradientstopedit'", $html);
        $this->assertStringContainsString("document.addEventListener('gradientstopedit'", $html);
        $this->assertStringContainsString('showNestedStylePopup(root,', $html);

        // Gradient mode hides the shared editor rather than repurposing it for a stop — a CSS
        // rule, so it lives in the compiled stylesheet, not the page HTML.
        $css = $this->get('/heisenberg-assets/editor.css')->getContent();
        $this->assertStringContainsString('.hb-cp[data-cp-mode="gradient"] .hb-cp__editor', $css);

        // One standalone stop-editor popup per main Fill/Stroke colour popup (style-panel.blade.php
        // mounts the pair together, once per registered block's Style panel) — and its count of
        // gradient-section markup stays pinned to the MAIN pickers only: a standalone instance
        // never renders a copy of the tabs, the ramp, or the stop-row template.
        // Counted WITH the class prefix so the inline script's own querySelector/closest
        // strings for these same popups aren't mistaken for mounted elements. Both popups
        // mount under one @if in style-panel.blade.php, so the pairing is exact; the picker
        // COUNT is deliberately not asserted — pickers are mounted by several other panels
        // too, so any fixed number here just pins an unrelated implementation detail.
        $mainPopups = substr_count($html, 'class="hb-style-popup" data-hb-style-popup="color"');
        $standalonePopups = substr_count($html, 'class="hb-style-popup" data-hb-style-popup="gradient-stop"');
        $this->assertGreaterThan(0, $mainPopups);
        $this->assertSame($mainPopups, $standalonePopups);
        $this->assertSame($standalonePopups, substr_count($html, 'hb-cp--standalone'));

        // The actual invariant: a standalone picker carries no gradient UI of its own, so
        // editing a stop can never re-open the gradient section that owns it.
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $standalone = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " hb-cp--standalone ")]');
        $this->assertGreaterThan(0, $standalone->length, 'no standalone picker was mounted at all');

        foreach ($standalone as $picker) {
            $this->assertSame(0, $xpath->query('.//*[@data-cp-gradient-bar]', $picker)->length);
            $this->assertSame(0, $xpath->query('.//*[@data-cp-gradient-stop-template]', $picker)->length);
            $this->assertSame(0, $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " hb-cp__tabs ")]', $picker)->length);
        }
    }

    public function test_select_labels_land_on_the_combobox_not_the_wrapper(): void
    {
        $html = $this->editorHtml();

        // A bare <div> carrying aria-label has no role, so assistive tech drops the label.
        // ui/select now forwards it to the trigger button.
        $this->assertMatchesRegularExpression(
            '/role="combobox"[^>]*aria-label="Gradient type"/',
            $html,
            "The select's accessible name must be on the combobox trigger",
        );
    }
}
