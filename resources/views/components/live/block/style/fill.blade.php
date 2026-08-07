{{-- live/block/style/fill — Empty until the user intentionally adds a layer.

     WHICH PATH FILL WRITES depends on the block, exactly as in the design source: a Fill is
     the fill of the thing you selected. For a text block that is its text colour
     (`color.text`); for a CONTAINER (group/columns/column) it is the frame's background
     (`color.background`) — writing text colour there tinted nothing, since a container
     paints no text of its own. style-panel.blade.php picks the path; the layer stack JS
     reads it off `data-hb-layer-path` rather than assuming. --}}
@props(['path' => 'color.text'])
<x-ui.panel-section title="Fill">
    <x-slot:action>
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Add fill" data-hb-style-add="fill">
            @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
        </button>
    </x-slot:action>
    {{-- The list is a STACK: layers paint bottom-up in DOM order, so the newest sits on top.
         inspector.blade.php flattens it with source-over alpha compositing and writes the result
         to supports.color.text (the block's own colour), keeping the raw stack alongside it at
         supports.color.layers so reopening the block restores every layer rather than just the
         flattened colour.

         No data-hb-control on the template: a per-row hook would make each layer overwrite the
         same scalar path, so the last row would win and stacking could never work. The stack
         owns the write. --}}
    <div data-hb-style-layer-list="fill" data-hb-layer-path="{{ $path }}"></div>
    <template data-hb-style-layer-template="fill">
        <x-live.block.color-layer color="#000000" opacity="100" />
    </template>
</x-ui.panel-section>
