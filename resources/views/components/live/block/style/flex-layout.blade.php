{{-- live/block/style/flex-layout — Flex layout controls. --}}
<x-ui.panel-section title="Flex Layout">
    <x-ui.segmented :active-index="0" :items="[
        ['value' => 'wrap', 'icon' => 'squares-four'],
        ['value' => 'column', 'icon' => 'arrow-down'],
        ['value' => 'row', 'icon' => 'arrow-right'],
    ]" />
    <div class="hb-irow hb-irow--top" style="gap:14px;">
        <div class="hb-icol" style="flex:none;">
            <span class="hb-ilbl">Alignment</span>
            <div class="hb-agrid" role="group" aria-label="Align content" data-hb-style-alignment-grid>
                @for ($r = 0; $r < 3; $r++)
                    <div class="hb-agrid__row">
                        @for ($c = 0; $c < 3; $c++)
                            <button type="button" class="hb-agrid__dot{{ $r === 1 && $c === 1 ? ' hb-agrid__dot--on is-active' : '' }}" aria-label="align {{ $r }}-{{ $c }}" aria-pressed="{{ $r === 1 && $c === 1 ? 'true' : 'false' }}" data-hb-style-alignment></button>
                        @endfor
                    </div>
                @endfor
            </div>
        </div>
        <div class="hb-icol">
            <span class="hb-ilbl">Gap</span>
            <div class="hb-iradio hb-iradio--on is-active" role="radio" aria-checked="true" tabindex="0" data-hb-style-radio>
                <span class="hb-iradio__dot hb-iradio__dot--on"></span>
                <x-ui.field icon="arrows-horizontal" value="0" data-hb-control="layout.gap" data-hb-control-kind="supports" data-hb-control-type="text" />
            </div>
            <div class="hb-iradio" role="radio" aria-checked="false" tabindex="0" data-hb-style-radio"><span class="hb-iradio__dot"></span><span class="hb-iradio__label">Space Between</span></div>
            <div class="hb-iradio" role="radio" aria-checked="false" tabindex="0" data-hb-style-radio"><span class="hb-iradio__dot"></span><span class="hb-iradio__label">Space Around</span></div>
        </div>
    </div>
</x-ui.panel-section>
