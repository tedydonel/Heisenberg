{{-- live/block/style/spacing — Padding + Margin, fully self-contained (2026-08-04): the field
     markup, the mode-switcher popups, AND their own layout tweaks all live in this one file —
     nothing about Padding/Margin is split into a separate live/pickers/*.blade.php file or
     registered from style-panel.blade.php anymore (unlike Color/Effect, which stay pickers/*.blade.php
     since those really are shared, independently-reusable popups, not spacing-specific). This
     replaces the earlier split (padding-values-menu.blade.php / margin-values-menu.blade.php,
     registered centrally in style-panel.blade.php) per an explicit "don't embed padding/margin
     in any other file" instruction.

     Split out of flex-layout.blade.php originally (2026-08-03) into its own file, matching every
     sibling style/*.blade.php's one-file-per-concern convention (alignment, typography, position,
     dimensions, appearance, fill, stroke, effects) — Padding had been living inside "Flex Layout"
     for no reason tied to flex itself.

     Margin mirrors Padding's markup/behavior exactly (same mode-switcher: one value for all sides /
     horizontal-vertical / top-right-bottom-left, same popup-menu pattern) — see inspector.blade.php's
     marginSideInputs()/syncMarginControls()/setMarginAxisValue()/setMarginMode() and the dispatch
     blocks alongside their padding equivalents. Reuses the padding icon set (style-padding-*) since
     no margin-specific icon assets exist and the pictograms themselves are direction/concept-
     agnostic between the two.

     MODEL WIRING (2026-08-04) — both groups now write to the block, closing the gap this file's
     earlier revisions carried forward deliberately ("mirror Padding's current behavior exactly").
     Both shipped contracts already declare all eight `supports.spacing.{margin,padding}.{side}`
     paths WITH matching `style.variables`, so the renderer was always ready; only the panel was
     silent. See docs/inspector-composition.md §4.3.

     Only the FOUR-SIDE fields carry data-hb-control — they are the only 1:1 mapping to the model.
     The "one value" and "horizontal/vertical" fields are aggregate VIEWS of those same four
     values (one input addressing two or four paths, which data-hb-control cannot express), so
     they fan out through inspector.blade.php's commitSpacingGroup() instead, which writes the
     whole `spacing.{group}` object in a single setSupport call — one re-render per keystroke
     regardless of the active mode, rather than one per side. --}}
@once
<style>
    {{-- The shared .hb-icol (31-block-inspector.css) only gives a 6px gap, and the "four sides"
         mode's two stacked .hb-irow rows previously sat in a bare, unclassed div with NO gap at
         all between them — both read as too cramped vertically once expanded. Scoped here (not a
         wider .hb-icol change) since that class is reused by every other Style section. --}}
    .hb-spacing-group { gap: 10px; }
</style>
@endonce
{{-- Per-group gating on the contract's `supports.spacing` map (same idiom as
     dimensions/flex-layout): `column` declares only padding, `embed` only margin —
     the undeclared group must not render a section that writes into the void.
     Absent/empty prop = render both (back-compat). --}}
@props(['spacing' => null])
@php
    $hbSpacingModeOptions = ['One value for all sides', 'Horizontal/Vertical', 'Top/Right/Bottom/Left'];
    $hbSpacingOn = fn (string $key): bool => $spacing === null || $spacing === [] || ! empty($spacing[$key]);
@endphp

@if ($hbSpacingOn('padding'))
<x-ui.panel-section title="Padding">
    <div class="hb-icol hb-spacing-group">
        <div class="hb-irow" style="justify-content:space-between;">
            <span class="hb-ilbl">Padding</span>
            <button type="button" class="hb-itrail" aria-label="Padding values" aria-expanded="false" data-hb-style-popup-trigger="padding" style="flex:none;">
                @include('heisenberg::components.ui.icon', ['name' => 'gear-six', 'size' => 15])
            </button>
        </div>
        <div class="hb-irow" data-hb-style-padding-mode="one" hidden>
            <x-ui.field icon="style-padding-all" value="0" data-hb-style-all-value="padding" />
        </div>
        <div class="hb-irow" data-hb-style-padding-mode="two" hidden>
            <x-ui.field icon="style-padding-horizontal" value="0" data-hb-style-padding-axis="horizontal" />
            <x-ui.field icon="style-padding-vertical" value="0" data-hb-style-padding-axis="vertical" />
        </div>
        <div class="hb-icol hb-spacing-group" data-hb-style-padding-mode="four">
            <div class="hb-irow">
                <x-ui.field icon="style-padding-left" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="left" data-hb-control="spacing.padding.left" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-ui.field icon="style-padding-right" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="right" data-hb-control="spacing.padding.right" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
            <div class="hb-irow">
                <x-ui.field icon="style-padding-top" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="top" data-hb-control="spacing.padding.top" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-ui.field icon="style-padding-bottom" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="bottom" data-hb-control="spacing.padding.bottom" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
        </div>
    </div>
</x-ui.panel-section>
@endif

@if ($hbSpacingOn('margin'))
<x-ui.panel-section title="Margin">
    <div class="hb-icol hb-spacing-group">
        <div class="hb-irow" style="justify-content:space-between;">
            <span class="hb-ilbl">Margin</span>
            <button type="button" class="hb-itrail" aria-label="Margin values" aria-expanded="false" data-hb-style-popup-trigger="margin" style="flex:none;">
                @include('heisenberg::components.ui.icon', ['name' => 'gear-six', 'size' => 15])
            </button>
        </div>
        <div class="hb-irow" data-hb-style-margin-mode="one" hidden>
            <x-ui.field icon="style-padding-all" value="0" data-hb-style-all-value="margin" />
        </div>
        <div class="hb-irow" data-hb-style-margin-mode="two" hidden>
            <x-ui.field icon="style-padding-horizontal" value="0" data-hb-style-margin-axis="horizontal" />
            <x-ui.field icon="style-padding-vertical" value="0" data-hb-style-margin-axis="vertical" />
        </div>
        <div class="hb-icol hb-spacing-group" data-hb-style-margin-mode="four">
            <div class="hb-irow">
                <x-ui.field icon="style-padding-left" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="left" data-hb-control="spacing.margin.left" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-ui.field icon="style-padding-right" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="right" data-hb-control="spacing.margin.right" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
            <div class="hb-irow">
                <x-ui.field icon="style-padding-top" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="top" data-hb-control="spacing.margin.top" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-ui.field icon="style-padding-bottom" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="bottom" data-hb-control="spacing.margin.bottom" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
        </div>
    </div>
</x-ui.panel-section>
@endif

{{-- Mode-switcher popups (formerly live/pickers/padding-values-menu.blade.php +
     margin-values-menu.blade.php, registered centrally in style-panel.blade.php) — inlined here so
     nothing about Padding/Margin lives outside this file. Default selection (index 2 = "Top/Right/
     Bottom/Left") matches the "four" mode both panels default to above. --}}
@if ($hbSpacingOn('padding'))
<div class="hb-style-popup" data-hb-style-popup="padding" hidden>
    <div class="hb-pop hb-padmenu" role="radiogroup" aria-label="Padding Values">
        <span class="hb-padmenu__title">Padding Values</span>
        <div class="hb-padmenu__opts">
            @foreach ($hbSpacingModeOptions as $i => $opt)
                <button type="button" class="hb-padmenu__opt{{ $i === 2 ? ' hb-padmenu__opt--on' : '' }}" role="radio" aria-checked="{{ $i === 2 ? 'true' : 'false' }}" data-hb-style-padding-option="{{ $i }}">
                    <x-ui.radio name="padding-mode" :label="$opt" :selected="$i === 2" />
                </button>
            @endforeach
        </div>
    </div>
</div>
@endif

@if ($hbSpacingOn('margin'))
<div class="hb-style-popup" data-hb-style-popup="margin" hidden>
    <div class="hb-pop hb-padmenu" role="radiogroup" aria-label="Margin Values">
        <span class="hb-padmenu__title">Margin Values</span>
        <div class="hb-padmenu__opts">
            @foreach ($hbSpacingModeOptions as $i => $opt)
                <button type="button" class="hb-padmenu__opt{{ $i === 2 ? ' hb-padmenu__opt--on' : '' }}" role="radio" aria-checked="{{ $i === 2 ? 'true' : 'false' }}" data-hb-style-margin-option="{{ $i }}">
                    <x-ui.radio name="margin-mode" :label="$opt" :selected="$i === 2" />
                </button>
            @endforeach
        </div>
    </div>
</div>
@endif
