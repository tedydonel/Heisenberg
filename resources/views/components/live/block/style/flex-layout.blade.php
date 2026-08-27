@props(['layout' => null])
@php
    $hbFlexOn = fn (string $key): bool => $layout === null || $layout === [] || (($layout[$key] ?? false) === true);
    $hbFlexAxis = ['start', 'center', 'end'];
@endphp
<x-heisenberg::ui.panel-section title="Flex Layout">
    @if ($hbFlexOn('direction'))
        <x-heisenberg::ui.segmented data-hb-style-flexmode :active-index="null" :items="[
            ['value' => 'wrap', 'icon' => 'squares-four'],
            ['value' => 'column', 'icon' => 'arrow-down'],
            ['value' => 'row', 'icon' => 'arrow-right'],
        ]" />
    @endif
    <div class="hb-irow hb-irow--top" style="gap:14px;">
        @if ($hbFlexOn('justify') || $hbFlexOn('align'))
            <div class="hb-icol" style="flex:none;">
                <span class="hb-ilbl">Alignment</span>
                <div class="hb-agrid" role="group" aria-label="Align content" data-hb-style-alignment-grid>
                    @foreach ($hbFlexAxis as $alignValue)
                        <div class="hb-agrid__row">
                            @foreach ($hbFlexAxis as $justifyValue)
                                <button type="button" class="hb-agrid__dot"
                                    aria-label="justify {{ $justifyValue }}, align {{ $alignValue }}" aria-pressed="false"
                                    data-hb-style-alignment
                                    data-hb-flex-justify="{{ $justifyValue }}"
                                    data-hb-flex-align="{{ $alignValue }}"></button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="hb-icol">
            <span class="hb-ilbl">Gap</span>
            @if ($hbFlexOn('gap'))
                <div class="hb-iradio hb-iradio--on is-active" role="radio" aria-checked="true" tabindex="0" data-hb-style-radio data-hb-flex-spacing="packed">
                    <span class="hb-iradio__dot hb-iradio__dot--on"></span>
                    <x-heisenberg::ui.field icon="arrows-horizontal" value="0" data-hb-control="layout.gap" data-hb-control-kind="supports" data-hb-control-type="text" />
                </div>
            @endif
            @if ($hbFlexOn('justify'))
                <div class="hb-iradio" role="radio" aria-checked="false" tabindex="0" data-hb-style-radio data-hb-flex-spacing="space-between"><span class="hb-iradio__dot"></span><span class="hb-iradio__label">Space Between</span></div>
                <div class="hb-iradio" role="radio" aria-checked="false" tabindex="0" data-hb-style-radio data-hb-flex-spacing="space-around"><span class="hb-iradio__dot"></span><span class="hb-iradio__label">Space Around</span></div>
            @endif
        </div>
    </div>
</x-heisenberg::ui.panel-section>
