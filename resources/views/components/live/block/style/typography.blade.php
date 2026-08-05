{{-- live/block/style/typography — Typography controls, gated per control.

     2026-08-05: `$typography` was declared as a prop and never read, and style-panel passed a
     hardcoded all-true map, so every block offered all five fields. That is wrong per contract:
     heading declares lineHeight and paragraph does not, and neither declares letterSpacing — so
     paragraph showed a line-height field with no style variable behind it, and both showed a
     letter-spacing field that writes into the void. style-panel now derives the map from the real
     contract; each field renders only when its own key is supported.

     The Horizontal/Vertical alignment segmenteds carry no data-hb-control at all — they are
     decorative today and are left ungated rather than tied to a key they do not write. See
     docs/inspector-composition.md §4.2. --}}
@props(['typography' => []])
@php
    $on = fn (string $key): bool => (bool) ($typography[$key] ?? false);
    // The two fields in this row gate independently, so the row itself only renders when at
    // least one survives — otherwise a bare flex row would add stray vertical space.
    $showMetricsRow = $on('lineHeight') || $on('letterSpacing');
@endphp
<x-ui.panel-section title="Typography">
    @if ($on('fontFamily'))
        {{-- ui/combobox against the vendored Google Fonts catalog (TODO 7.5), NOT a static list.
             This was a ui/select with five literal families (Default/Rubik/Inter/Georgia/JetBrains
             Mono). The left sidebar's Style tab already does this properly — same component, same
             GET /editor/fonts endpoint, same paged search/loadmore contract — so this reuses that
             wiring rather than reimplementing it. Options start empty and are filled by the
             inspector's own search handler on focus/typing. --}}
        <div class="hb-irow hb-style-typography__font-row">
            <x-ui.combobox class="hb-style-typography__font" :options="[]" value=""
                placeholder="Default" empty-label="No fonts found" aria-label="Font family"
                data-hb-style-font-family
                data-hb-control="typography.fontFamily" data-hb-control-kind="supports" data-hb-control-type="combobox" />
            {{-- TODO 7.7 replaces this with the `selection-all-fill` theme-variable trigger:
                 "since on the typography font combobox does not support input, please the x icon
                 in line with it for that 'selection-all-fill' icon". Left as the clear-font
                 button until the variable-popup wiring lands, so the field stays clearable. --}}
            <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Clear font" data-hb-style-clear-font>
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])
            </button>
        </div>
    @endif
    @if ($on('fontWeight') || $on('fontSize'))
        <div class="hb-irow hb-style-typography__weight-row">
            @if ($on('fontWeight'))
                <x-ui.select class="hb-style-typography__weight" value="500" :options="[
                    ['value' => '', 'label' => 'Default'],
                    ['value' => '300', 'label' => 'Light'],
                    ['value' => '400', 'label' => 'Regular'],
                    ['value' => '500', 'label' => 'Medium'],
                    ['value' => '600', 'label' => 'Semibold'],
                    ['value' => '700', 'label' => 'Bold'],
                ]" data-hb-control="typography.fontWeight" data-hb-control-kind="supports" data-hb-control-type="select" />
            @endif
            @if ($on('fontSize'))
                <x-ui.field class="hb-style-typography__size" value="12.5" data-hb-control="typography.fontSize" data-hb-control-kind="supports" data-hb-control-type="text" />
            @endif
        </div>
    @endif
    @if ($showMetricsRow)
        <div class="hb-irow hb-irow--top">
            @if ($on('lineHeight'))
                <div class="hb-icol"><span class="hb-ilbl">Line height</span><x-ui.field value="Auto" data-hb-control="typography.lineHeight" data-hb-control-kind="supports" data-hb-control-type="number" /></div>
            @endif
            @if ($on('letterSpacing'))
                <div class="hb-icol"><span class="hb-ilbl">Letter spacing</span><x-ui.field value="0" data-hb-control="typography.letterSpacing" data-hb-control-kind="supports" data-hb-control-type="text" /></div>
            @endif
        </div>
    @endif
    {{-- TEXT alignment — distinct from the standalone Alignment section, which places the BLOCK
         within its parent (`supports.align` → `hb-align-*` class). These place the text inside the
         block, via SupportsStyle's --hb-text-align / --hb-text-align-v. Both were decorative
         (no data-hb-control) until TODO 7.4; the labels now say which is which. --}}
    @if ($on('textAlign') || $on('textAlignVertical'))
        <div class="hb-irow hb-irow--top">
            @if ($on('textAlign'))
                <div class="hb-icol">
                    <span class="hb-ilbl">Text horizontal</span>
                    <x-ui.segmented :active-index="null" :items="[
                        ['value' => 'left', 'icon' => 'format_align_left', 'label' => 'Align text left'],
                        ['value' => 'center', 'icon' => 'format_align_center', 'label' => 'Align text center'],
                        ['value' => 'right', 'icon' => 'format_align_right', 'label' => 'Align text right'],
                        ['value' => 'justify', 'icon' => 'format_align_justify', 'label' => 'Justify text'],
                    ]" data-hb-control="typography.textAlign" data-hb-control-kind="supports" data-hb-control-type="segmented" />
                </div>
            @endif
            @if ($on('textAlignVertical'))
                <div class="hb-icol">
                    <span class="hb-ilbl">Text vertical</span>
                    {{-- SupportsStyle emits these through `align-self`, whose sanitizer is
                         `align-3` (start|center|end) — not top/middle/bottom. --}}
                    <x-ui.segmented :active-index="null" :items="[
                        ['value' => 'start', 'icon' => 'vertical_align_top', 'label' => 'Align text top'],
                        ['value' => 'center', 'icon' => 'vertical_align_center', 'label' => 'Align text middle'],
                        ['value' => 'end', 'icon' => 'vertical_align_bottom', 'label' => 'Align text bottom'],
                    ]" data-hb-control="typography.textAlignVertical" data-hb-control-kind="supports" data-hb-control-type="segmented" />
                </div>
            @endif
        </div>
    @endif
</x-ui.panel-section>
