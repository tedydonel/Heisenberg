@props(['path' => 'color.text'])
<x-heisenberg::ui.panel-section title="Fill">
    <x-slot:action>
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Add fill" data-hb-style-add="fill">
            @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
        </button>
    </x-slot:action>
        <div data-hb-style-layer-list="fill" data-hb-layer-path="{{ $path }}"></div>
    <template data-hb-style-layer-template="fill">
        <x-heisenberg::live.block.color-layer color="#000000" opacity="100" />
    </template>
</x-heisenberg::ui.panel-section>
