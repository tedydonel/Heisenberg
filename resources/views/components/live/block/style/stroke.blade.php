@props(['showPosition' => false, 'vectorControls' => false])
<x-heisenberg::ui.panel-section title="Stroke">
    <x-slot:action>
        <button type="button" class="hb-itrail hb-itrail--bare" aria-label="Add stroke" data-hb-style-add="stroke">
            @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
        </button>
    </x-slot:action>
    <div data-hb-style-layer-list="stroke"></div>
    <template data-hb-style-layer-template="stroke">
        <x-heisenberg::live.block.color-layer color="#000000" opacity="100" />
    </template>
    {{-- Only the + shows until a stroke exists: hbSyncStrokeBody() reveals these controls
         once the stroke list has a layer, and hides them again when the last one is removed. --}}
    <div class="hb-style-stroke__body" data-hb-stroke-body hidden>
        <div class="hb-irow hb-style-stroke__metrics">
            @if ($showPosition)
                {{-- Inside keeps the stroke within the block's set size; Outside adds it around that
                     size (SupportsStyle::strokePositionRule). A CSS border has no "center". --}}
                <div class="hb-icol hb-style-stroke__position">
                    <span class="hb-ilbl">Position</span>
                    <x-heisenberg::ui.select value="border-box" :options="[
                        ['value' => 'border-box', 'label' => 'Inside'],
                        ['value' => 'content-box', 'label' => 'Outside'],
                    ]" data-hb-control="border.position" data-hb-control-kind="supports" data-hb-control-type="select" />
                </div>
            @endif
            <div class="hb-icol hb-style-stroke__weight">
                <span class="hb-ilbl">Weight</span>
                <div class="hb-irow hb-style-stroke__weight-control">
                    <x-heisenberg::ui.field value="1" data-hb-style-all-value="stroke-sides" />
                    <button type="button" class="hb-itrail hb-itrail--expand" aria-label="Expand sides" aria-expanded="false" data-hb-style-expand="stroke-sides">
                        @include('heisenberg::components.ui.icon', ['name' => 'corners-out', 'size' => 18])
                    </button>
                </div>
            </div>
        </div>
        <div id="stroke-sides" class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
            <x-heisenberg::ui.field icon="arrow-line-up" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.top" data-hb-control-kind="supports" data-hb-control-type="text" />
            <x-heisenberg::ui.field icon="arrow-line-left" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.left" data-hb-control-kind="supports" data-hb-control-type="text" />
        </div>
        <div class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
            <x-heisenberg::ui.field icon="arrow-line-down" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.bottom" data-hb-control-kind="supports" data-hb-control-type="text" />
            <x-heisenberg::ui.field icon="arrow-line-right" value="1" data-hb-style-side-value="stroke-sides" data-hb-control="border.width.right" data-hb-control-kind="supports" data-hb-control-type="text" />
        </div>
        <div class="hb-irow hb-irow--top">
            @if ($vectorControls)
                <div class="hb-icol">
                    <span class="hb-ilbl">Join</span>
                    <x-heisenberg::ui.select value="miter" :options="[
                        ['value' => 'miter', 'label' => 'Miter'],
                        ['value' => 'round', 'label' => 'Round'],
                        ['value' => 'bevel', 'label' => 'Bevel'],
                    ]" />
                </div>
            @endif
            @if ($vectorControls)
                <div class="hb-icol">
                    <span class="hb-ilbl">Cap</span>
                    <x-heisenberg::ui.select value="solid" :options="[
                        ['value' => 'solid', 'label' => 'Solid'],
                        ['value' => 'dashed', 'label' => 'Dashed'],
                        ['value' => 'dotted', 'label' => 'Dotted'],
                    ]" />
                </div>
            @endif
        </div>
    </div>
</x-heisenberg::ui.panel-section>
@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-style-stroke__body { display: contents; }
    .hb-style-stroke__body[hidden] { display: none; }
</style>
@endonce
