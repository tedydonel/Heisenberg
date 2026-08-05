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

        // The public API the inspector calls, plus the setters added for the save path.
        $this->assertStringContainsString('root.__hbCp = { setHex, setGradient, getValue, setMode }', $html);
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
