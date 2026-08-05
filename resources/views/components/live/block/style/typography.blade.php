{{-- live/block/style/typography — Typography controls. --}}
@props(['typography' => []])
<x-ui.panel-section title="Typography">
    <div class="hb-irow hb-style-typography__font-row">
        <x-ui.select class="hb-style-typography__font" value="Rubik" :options="[
            ['value' => '', 'label' => 'Default'],
            ['value' => 'Rubik', 'label' => 'Rubik'],
            ['value' => 'Inter', 'label' => 'Inter'],
            ['value' => 'Georgia', 'label' => 'Georgia'],
            ['value' => 'JetBrains Mono', 'label' => 'JetBrains Mono'],
        ]" data-hb-control="typography.fontFamily" data-hb-control-kind="supports" data-hb-control-type="select" />
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Clear font" data-hb-style-clear-font>
            @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 14])
        </button>
    </div>
    <div class="hb-irow hb-style-typography__weight-row">
        <x-ui.select class="hb-style-typography__weight" value="500" :options="[
            ['value' => '', 'label' => 'Default'],
            ['value' => '300', 'label' => 'Light'],
            ['value' => '400', 'label' => 'Regular'],
            ['value' => '500', 'label' => 'Medium'],
            ['value' => '600', 'label' => 'Semibold'],
            ['value' => '700', 'label' => 'Bold'],
        ]" data-hb-control="typography.fontWeight" data-hb-control-kind="supports" data-hb-control-type="select" />
        <x-ui.field class="hb-style-typography__size" value="12.5" data-hb-control="typography.fontSize" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
    <div class="hb-irow hb-irow--top">
        <div class="hb-icol"><span class="hb-ilbl">Line height</span><x-ui.field value="Auto" data-hb-control="typography.lineHeight" data-hb-control-kind="supports" data-hb-control-type="number" /></div>
        <div class="hb-icol"><span class="hb-ilbl">Letter spacing</span><x-ui.field value="0" data-hb-control="typography.letterSpacing" data-hb-control-kind="supports" data-hb-control-type="text" /></div>
    </div>
    <div class="hb-irow hb-irow--top">
        <div class="hb-icol">
            <span class="hb-ilbl">Horizontal</span>
            <x-ui.segmented :active-index="2" :items="[
                ['value' => 'left', 'icon' => 'format_align_left'],
                ['value' => 'center', 'icon' => 'format_align_center'],
                ['value' => 'right', 'icon' => 'format_align_right'],
                ['value' => 'justify', 'icon' => 'format_align_justify'],
            ]" />
        </div>
        <div class="hb-icol">
            <span class="hb-ilbl">Vertical</span>
            <x-ui.segmented :active-index="null" :items="[
                ['value' => 'top', 'icon' => 'vertical_align_top'],
                ['value' => 'middle', 'icon' => 'vertical_align_center'],
                ['value' => 'bottom', 'icon' => 'vertical_align_bottom'],
            ]" />
        </div>
    </div>
</x-ui.panel-section>
