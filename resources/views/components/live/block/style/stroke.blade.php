{{-- live/block/style/stroke — Empty until the user intentionally adds a layer.

     WIRING (2026-08-07, when the container contracts declared `border`): every control here
     writes a real path — the colour stack composites into `supports.border.color`, Weight
     writes the four `supports.border.width.<side>` paths (the "all" field is an aggregate
     VIEW that fans out to them, exactly like Padding's), and Cap writes
     `supports.border.style` (its solid/dashed/dotted options ARE the CSS border styles).

     `vectorControls` gates Position (inside/center/outside) and Join (miter/round/bevel):
     both are vector-editor concepts with NO CSS border equivalent, so they would write
     nowhere. Kept in place rather than deleted — the design owns them and they render the
     day the engine can express them — the same treatment Alignment gives its vertical
     options. --}}
@props(['vectorControls' => false])
<x-ui.panel-section title="Stroke">
    <x-slot:action>
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Add stroke" data-hb-style-add="stroke">
            @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
        </button>
    </x-slot:action>
    {{-- Same stack semantics as Fill (see style/fill.blade.php) — composited into
         supports.border.color, with the raw stack kept at supports.border.layers. --}}
    <div data-hb-style-layer-list="stroke"></div>
    <template data-hb-style-layer-template="stroke">
        <x-live.block.color-layer color="#000000" opacity="100" />
    </template>
    <div class="hb-irow hb-style-stroke__metrics">
        @if ($vectorControls)
            <div class="hb-icol hb-style-stroke__position">
                <span class="hb-ilbl">Position</span>
                <x-ui.select value="inside" :options="[
                    ['value' => 'inside', 'label' => 'Inside'],
                    ['value' => 'center', 'label' => 'Center'],
                    ['value' => 'outside', 'label' => 'Outside'],
                ]" />
            </div>
        @endif
        <div class="hb-icol hb-style-stroke__weight">
            <span class="hb-ilbl">Weight</span>
            <div class="hb-irow hb-style-stroke__weight-control">
                {{-- No data-hb-control: `border.width` is a SIDE MAP, so a scalar write here
                     would replace it. This is an aggregate view over the four side fields
                     below (Padding's exact pattern) and commits through their own hooks. --}}
                <x-ui.field value="1" data-hb-style-all-value="stroke-sides" />
                <button type="button" class="hb-itrail hb-itrail--expand" aria-label="Expand sides" aria-expanded="false" data-hb-style-expand="stroke-sides">
                    @include('heisenberg::components.ui.icon', ['name' => 'corners-out', 'size' => 18])
                </button>
            </div>
        </div>
    </div>
    <div id="stroke-sides" class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
        <x-ui.field icon="arrow-line-up" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.top" data-hb-control-kind="supports" data-hb-control-type="text" />
        <x-ui.field icon="arrow-line-left" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.left" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
    <div class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
        <x-ui.field icon="arrow-line-down" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.bottom" data-hb-control-kind="supports" data-hb-control-type="text" />
        <x-ui.field icon="arrow-line-right" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.right" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
    <div class="hb-irow hb-irow--top">
        @if ($vectorControls)
            <div class="hb-icol">
                <span class="hb-ilbl">Join</span>
                <x-ui.select value="miter" :options="[
                    ['value' => 'miter', 'label' => 'Miter'],
                    ['value' => 'round', 'label' => 'Round'],
                    ['value' => 'bevel', 'label' => 'Bevel'],
                ]" />
            </div>
        @endif
        @if ($vectorControls)
            <div class="hb-icol">
                <span class="hb-ilbl">Cap</span>
                <x-ui.select value="solid" :options="[
                    ['value' => 'solid', 'label' => 'Solid'],
                    ['value' => 'dashed', 'label' => 'Dashed'],
                    ['value' => 'dotted', 'label' => 'Dotted'],
                ]" />
            </div>
        @endif
    </div>
</x-ui.panel-section>
