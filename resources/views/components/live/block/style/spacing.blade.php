@once
<style nonce="{{ heisenberg_csp_nonce() }}">
        .hb-spacing-group { gap: 10px; }
</style>
@endonce
@props(['spacing' => null])
@php
    $hbSpacingModeOptions = ['One value for all sides', 'Horizontal/Vertical', 'Top/Right/Bottom/Left'];
    $hbSpacingOn = fn (string $key): bool => $spacing === null || $spacing === [] || ! empty($spacing[$key]);
@endphp

@if ($hbSpacingOn('padding'))
<x-heisenberg::ui.panel-section title="Padding">
    <div class="hb-icol hb-spacing-group">
        <div class="hb-irow" style="justify-content:space-between;">
            <span class="hb-ilbl">Padding</span>
            <button type="button" class="hb-itrail" aria-label="Padding values" aria-expanded="false" data-hb-style-popup-trigger="padding" style="flex:none;">
                @include('heisenberg::components.ui.icon', ['name' => 'gear-six', 'size' => 15])
            </button>
        </div>
        <div class="hb-irow" data-hb-style-padding-mode="one" hidden>
            <x-heisenberg::ui.field icon="style-padding-all" value="0" data-hb-style-all-value="padding" />
        </div>
        <div class="hb-irow" data-hb-style-padding-mode="two" hidden>
            <x-heisenberg::ui.field icon="style-padding-horizontal" value="0" data-hb-style-padding-axis="horizontal" />
            <x-heisenberg::ui.field icon="style-padding-vertical" value="0" data-hb-style-padding-axis="vertical" />
        </div>
        <div class="hb-icol hb-spacing-group" data-hb-style-padding-mode="four">
            <div class="hb-irow">
                <x-heisenberg::ui.field icon="style-padding-left" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="left" data-hb-control="spacing.padding.left" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-heisenberg::ui.field icon="style-padding-right" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="right" data-hb-control="spacing.padding.right" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
            <div class="hb-irow">
                <x-heisenberg::ui.field icon="style-padding-top" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="top" data-hb-control="spacing.padding.top" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-heisenberg::ui.field icon="style-padding-bottom" value="0" data-hb-style-side-value="padding" data-hb-style-padding-side="bottom" data-hb-control="spacing.padding.bottom" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
        </div>
    </div>
</x-heisenberg::ui.panel-section>
@endif

@if ($hbSpacingOn('margin'))
<x-heisenberg::ui.panel-section title="Margin">
    <div class="hb-icol hb-spacing-group">
        <div class="hb-irow" style="justify-content:space-between;">
            <span class="hb-ilbl">Margin</span>
            <button type="button" class="hb-itrail" aria-label="Margin values" aria-expanded="false" data-hb-style-popup-trigger="margin" style="flex:none;">
                @include('heisenberg::components.ui.icon', ['name' => 'gear-six', 'size' => 15])
            </button>
        </div>
        <div class="hb-irow" data-hb-style-margin-mode="one" hidden>
            <x-heisenberg::ui.field icon="style-padding-all" value="0" data-hb-style-all-value="margin" />
        </div>
        <div class="hb-irow" data-hb-style-margin-mode="two" hidden>
            <x-heisenberg::ui.field icon="style-padding-horizontal" value="0" data-hb-style-margin-axis="horizontal" />
            <x-heisenberg::ui.field icon="style-padding-vertical" value="0" data-hb-style-margin-axis="vertical" />
        </div>
        <div class="hb-icol hb-spacing-group" data-hb-style-margin-mode="four">
            <div class="hb-irow">
                <x-heisenberg::ui.field icon="style-padding-left" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="left" data-hb-control="spacing.margin.left" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-heisenberg::ui.field icon="style-padding-right" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="right" data-hb-control="spacing.margin.right" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
            <div class="hb-irow">
                <x-heisenberg::ui.field icon="style-padding-top" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="top" data-hb-control="spacing.margin.top" data-hb-control-kind="supports" data-hb-control-type="text" />
                <x-heisenberg::ui.field icon="style-padding-bottom" value="0" data-hb-style-side-value="margin" data-hb-style-margin-side="bottom" data-hb-control="spacing.margin.bottom" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
        </div>
    </div>
</x-heisenberg::ui.panel-section>
@endif

@if ($hbSpacingOn('padding'))
<div class="hb-style-popup" data-hb-style-popup="padding" hidden>
    <div class="hb-pop hb-padmenu" role="radiogroup" aria-label="Padding Values">
        <span class="hb-padmenu__title">Padding Values</span>
        <div class="hb-padmenu__opts">
            @foreach ($hbSpacingModeOptions as $i => $opt)
                <button type="button" class="hb-padmenu__opt{{ $i === 2 ? ' hb-padmenu__opt--on' : '' }}" role="radio" aria-checked="{{ $i === 2 ? 'true' : 'false' }}" data-hb-style-padding-option="{{ $i }}">
                    <x-heisenberg::ui.radio name="padding-mode" :label="$opt" :selected="$i === 2" />
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
                    <x-heisenberg::ui.radio name="margin-mode" :label="$opt" :selected="$i === 2" />
                </button>
            @endforeach
        </div>
    </div>
</div>
@endif
