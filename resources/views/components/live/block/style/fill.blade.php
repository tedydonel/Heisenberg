{{-- live/block/style/fill — Empty until the user intentionally adds a layer. --}}
<x-ui.panel-section title="Fill">
    <x-slot:action>
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Add fill" data-hb-style-add="fill">
            @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
        </button>
    </x-slot:action>
    <div data-hb-style-layer-list="fill"></div>
    <template data-hb-style-layer-template="fill">
        <x-live.block.color-layer color="#000000" opacity="100"
            data-hb-control="color.text" data-hb-control-kind="supports" data-hb-control-type="text" />
    </template>
</x-ui.panel-section>
