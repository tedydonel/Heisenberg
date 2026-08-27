@props(['size' => null])
@php
    $hbDimOn = fn (string $key): bool => $size === null || $size === [] || (($size[$key] ?? false) === true);
@endphp
<x-heisenberg::ui.panel-section title="Dimensions">
    @if ($hbDimOn('width') || $hbDimOn('height'))
        <div class="hb-irow">
            @if ($hbDimOn('width'))
                <x-heisenberg::ui.field prefix="W" value="Auto" data-hb-control="size.width" data-hb-control-kind="supports" data-hb-control-type="text" />
            @endif
            @if ($hbDimOn('height'))
                <x-heisenberg::ui.field prefix="H" value="Auto" data-hb-control="size.height" data-hb-control-kind="supports" data-hb-control-type="text" />
            @endif
        </div>
    @endif
        <div class="hb-irow">
        <x-heisenberg::ui.checkbox label="Fill Width" data-hb-control="fillWidth" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
        <x-heisenberg::ui.checkbox label="Fill Height" data-hb-control="fillHeight" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
    </div>
    <div class="hb-irow">
        <x-heisenberg::ui.checkbox label="Hug Width" data-hb-control="hugWidth" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
        <x-heisenberg::ui.checkbox label="Hug Height" data-hb-control="hugHeight" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
    </div>
    <x-heisenberg::ui.checkbox label="Clip Content" data-hb-control="clipContent" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
</x-heisenberg::ui.panel-section>
