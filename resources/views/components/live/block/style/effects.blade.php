{{-- live/block/style/effects — Effects stack and editor trigger. --}}
<x-ui.panel-section title="Effects">
    <x-slot:action>
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Add effect" data-hb-style-add="effects">
            @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
        </button>
    </x-slot:action>
    <div data-hb-style-layer-list="effects">
        <div class="hb-effectrow">
            <div class="hb-fxlayer">
                <button type="button" class="hb-fxlayer__ic" aria-label="Edit effect" aria-expanded="false" data-hb-style-effect-trigger><x-ui.icon name="pencil-simple" size="14" /></button>
                <span class="hb-fxlayer__name">Drop Shadow</span>
                <button type="button" class="hb-fxlayer__ic" aria-label="Toggle visibility" aria-pressed="true" data-hb-style-effect-visibility><x-ui.icon name="eye" size="14" /></button>
            </div>
            <button type="button" class="hb-fxlayer__rm" aria-label="Remove" data-hb-style-remove><x-ui.icon name="minus" size="14" /></button>
        </div>
    </div>
    <template data-hb-style-layer-template="effects">
        <div class="hb-effectrow">
            <div class="hb-fxlayer">
                <button type="button" class="hb-fxlayer__ic" aria-label="Edit effect" aria-expanded="false" data-hb-style-effect-trigger><x-ui.icon name="pencil-simple" size="14" /></button>
                <span class="hb-fxlayer__name">Drop Shadow</span>
                <button type="button" class="hb-fxlayer__ic" aria-label="Toggle visibility" aria-pressed="true" data-hb-style-effect-visibility><x-ui.icon name="eye" size="14" /></button>
            </div>
            <button type="button" class="hb-fxlayer__rm" aria-label="Remove" data-hb-style-remove><x-ui.icon name="minus" size="14" /></button>
        </div>
    </template>
</x-ui.panel-section>
