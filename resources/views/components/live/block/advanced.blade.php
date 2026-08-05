{{-- live/block/advanced — Block.advanced (wt2Gk). Responsive-visibility toggles
     (driven by the contract's `visibility` attributes) + scroll animation controls. --}}
<div class="hb-blockadvanced">
    <x-ui.panel-section title="Hide based on device screen width" collapsible>
        @foreach ([
            [['Extra small devices', 'hideXs'], ['Small devices', 'hideSm']],
            [['Medium devices', 'hideMd'], ['Large devices', 'hideLg']],
            [['Xl devices', 'hideXl'], ['Xxl devices', 'hideXxl']],
        ] as $pair)
            <div class="hb-irow">
                @foreach ($pair as $field)
                    <label class="hb-tglrow"><span class="hb-tglrow__l">{{ $field[0] }}</span><x-ui.toggle data-hb-control="{{ $field[1] }}" data-hb-control-kind="attributes" data-hb-control-type="toggle" /></label>
                @endforeach
            </div>
        @endforeach
    </x-ui.panel-section>

    <x-ui.panel-section title="Animate on scroll" collapsible>
        <div class="hb-icol">
            <span class="hb-ilbl">Animation type</span>
            <x-ui.select value="fade-up" :options="[
                ['value' => 'fade-up', 'label' => 'Fade Up'],
                ['value' => 'fade-down', 'label' => 'Fade Down'],
                ['value' => 'zoom-in', 'label' => 'Zoom In'],
                ['value' => 'slide-left', 'label' => 'Slide Left'],
            ]" data-hb-control="animate" data-hb-control-kind="attributes" data-hb-control-type="select" />
        </div>
        <div class="hb-icol">
            <div class="hb-irow" style="justify-content:space-between;"><span class="hb-ilbl">Duration</span><span class="hb-tglrow__l" data-hb-range-readout>1000</span></div>
            <x-ui.slider value="50" data-hb-control="animateDuration" data-hb-control-kind="attributes" data-hb-control-type="range" />
        </div>
        <div class="hb-icol">
            <div class="hb-irow" style="justify-content:space-between;"><span class="hb-ilbl">Delay</span><span class="hb-tglrow__l" data-hb-range-readout>100</span></div>
            <x-ui.slider value="10" data-hb-control="animateDelay" data-hb-control-kind="attributes" data-hb-control-type="range" />
        </div>
        <x-ui.button variant="secondary" leading-icon="play">Play animation</x-ui.button>
    </x-ui.panel-section>
</div>
@once
<style>
    .hb-tglrow { display: flex; align-items: center; justify-content: space-between; gap: var(--hb-space-2, 8px); cursor: pointer; }
    .hb-tglrow__l { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12px; color: var(--hb-text-secondary, #5A5A5A); }
</style>
@endonce
