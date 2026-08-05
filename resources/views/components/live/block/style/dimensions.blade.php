{{-- live/block/style/dimensions — Dimensions section. --}}
<x-ui.panel-section title="Dimensions">
    <div class="hb-irow">
        <x-ui.field prefix="W" value="Auto" data-hb-control="size.width" data-hb-control-kind="supports" data-hb-control-type="text" />
        <x-ui.field prefix="H" value="Auto" data-hb-control="size.height" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
    <div class="hb-irow">
        <x-ui.checkbox label="Fill Width" />
        <x-ui.checkbox label="Fill Height" />
    </div>
    <div class="hb-irow">
        <x-ui.checkbox label="Hug Width" />
        <x-ui.checkbox label="Hug Height" />
    </div>
    <x-ui.checkbox label="Clip Content" />
</x-ui.panel-section>
