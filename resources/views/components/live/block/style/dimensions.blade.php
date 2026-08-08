{{-- live/block/style/dimensions — Dimensions section. Per-control gating on the contract's
     `supports.size` feature map (same idiom as flex-layout/typography): a contract that
     declares only some of width/height/min/max renders only those fields, so a control can
     never write an undeclared path. (2026-08-07: `column` now declares width/height too — its
     width folds into the flex-basis/max-width composition in column.css.) Absent/empty prop =
     render everything (back-compat). --}}
@props(['size' => null])
@php
    $hbDimOn = fn (string $key): bool => $size === null || $size === [] || (($size[$key] ?? false) === true);
@endphp
<x-ui.panel-section title="Dimensions">
    @if ($hbDimOn('width') || $hbDimOn('height'))
        <div class="hb-irow">
            @if ($hbDimOn('width'))
                <x-ui.field prefix="W" value="Auto" data-hb-control="size.width" data-hb-control-kind="supports" data-hb-control-type="text" />
            @endif
            @if ($hbDimOn('height'))
                <x-ui.field prefix="H" value="Auto" data-hb-control="size.height" data-hb-control-kind="supports" data-hb-control-type="text" />
            @endif
        </div>
    @endif
    {{-- Fill/Hug/Clip are ATTRIBUTES, not supports. They are class-based capabilities
         in SupportsStyle — `hb-size-fill-w` sets width:100%, and a bare custom property cannot
         flip width/display the way a variable-driven declaration can. Classes come from
         style.classNames, whose predicates BlockRenderer::predicateMatches() resolves against
         `attributes` ONLY, never `supports`. So an attribute is the only shape that reaches the
         class; `dropCap` is the existing precedent for a style-ish boolean living there.

         They carry no data-hb-control-on/off, so the checkbox writes a real boolean — classNames
         predicates compare with ===, and the string 'true' would never match. --}}
    <div class="hb-irow">
        <x-ui.checkbox label="Fill Width" data-hb-control="fillWidth" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
        <x-ui.checkbox label="Fill Height" data-hb-control="fillHeight" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
    </div>
    <div class="hb-irow">
        <x-ui.checkbox label="Hug Width" data-hb-control="hugWidth" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
        <x-ui.checkbox label="Hug Height" data-hb-control="hugHeight" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
    </div>
    <x-ui.checkbox label="Clip Content" data-hb-control="clipContent" data-hb-control-kind="attributes" data-hb-control-type="checkbox" />
</x-ui.panel-section>
