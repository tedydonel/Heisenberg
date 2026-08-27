@props(['typography' => []])
@php
    $on = fn (string $key): bool => (bool) ($typography[$key] ?? false);
    $showMetricsRow = $on('lineHeight') || $on('letterSpacing');
@endphp
<x-heisenberg::ui.panel-section title="Typography">
    @if ($on('fontFamily'))
        <div class="hb-irow hb-style-typography__font-row">
            <x-heisenberg::ui.combobox class="hb-style-typography__font" :options="[]" value=""
                placeholder="Default" empty-label="No fonts found" aria-label="Font family"
                data-hb-style-font-family
                data-hb-control="typography.fontFamily" data-hb-control-kind="supports" data-hb-control-type="combobox" />
                        <button type="button" class="hb-itrail hb-itrail--bare hb-varbtn--inline"
                data-hb-style-var-trigger data-hb-style-var-for="typography.fontFamily"
                aria-expanded="false" aria-label="{{ __('heisenberg::editor.inspector.bind_theme_variable') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'selection-all-fill', 'size' => 14])
            </button>
        </div>
    @endif
    @if ($on('fontWeight') || $on('fontSize'))
        <div class="hb-irow hb-style-typography__weight-row">
            @if ($on('fontWeight'))
                <x-heisenberg::ui.select class="hb-style-typography__weight" value="500" :options="[
                    ['value' => '', 'label' => 'Default'],
                    ['value' => '300', 'label' => 'Light'],
                    ['value' => '400', 'label' => 'Regular'],
                    ['value' => '500', 'label' => 'Medium'],
                    ['value' => '600', 'label' => 'Semibold'],
                    ['value' => '700', 'label' => 'Bold'],
                ]" data-hb-control="typography.fontWeight" data-hb-control-kind="supports" data-hb-control-type="select" />
            @endif
            @if ($on('fontSize'))
                <x-heisenberg::ui.field class="hb-style-typography__size" value="12.5" data-hb-control="typography.fontSize" data-hb-control-kind="supports" data-hb-control-type="text" />
            @endif
        </div>
    @endif
    @if ($showMetricsRow)
        <div class="hb-irow hb-irow--top">
            @if ($on('lineHeight'))
                <div class="hb-icol"><span class="hb-ilbl">Line height</span><x-heisenberg::ui.field value="Auto" data-hb-control="typography.lineHeight" data-hb-control-kind="supports" data-hb-control-type="number" /></div>
            @endif
            @if ($on('letterSpacing'))
                <div class="hb-icol"><span class="hb-ilbl">Letter spacing</span><x-heisenberg::ui.field value="0" data-hb-control="typography.letterSpacing" data-hb-control-kind="supports" data-hb-control-type="text" /></div>
            @endif
        </div>
    @endif
    @if ($on('textAlign') || $on('textAlignVertical'))
        <div class="hb-irow hb-irow--top">
            @if ($on('textAlign'))
                <div class="hb-icol">
                    <span class="hb-ilbl">Text horizontal</span>
                    <x-heisenberg::ui.segmented :active-index="null" :items="[
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
                                        <x-heisenberg::ui.segmented :active-index="null" :items="[
                        ['value' => 'start', 'icon' => 'vertical_align_top', 'label' => 'Align text top'],
                        ['value' => 'center', 'icon' => 'vertical_align_center', 'label' => 'Align text middle'],
                        ['value' => 'end', 'icon' => 'vertical_align_bottom', 'label' => 'Align text bottom'],
                    ]" data-hb-control="typography.textAlignVertical" data-hb-control-kind="supports" data-hb-control-type="segmented" />
                </div>
            @endif
        </div>
    @endif
</x-heisenberg::ui.panel-section>
