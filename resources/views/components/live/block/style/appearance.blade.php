{{-- live/block/style/appearance — opacity + linked corner-radius controls.

     This section straddles two supports groups: opacity writes `appearance.opacity`, the four
     corner fields write `border.radius.*`. style-panel shows the section when EITHER group is
     supported and passes $showOpacity for the narrower one, so a contract that declares border
     radius (as both shipped contracts do) but not `appearance` (as neither does) gets the corners
     without an opacity field writing into the void. See docs/inspector-composition.md §4.2. --}}
@props(['showOpacity' => true])
<x-ui.panel-section title="Appearance">
    <div class="hb-irow hb-style-appearance__top">
        @if ($showOpacity)
            <x-ui.field class="hb-style-appearance__field" value="100" unit="%" data-hb-control="appearance.opacity" data-hb-control-kind="supports" data-hb-control-type="number" />
        @endif
        <x-ui.field class="hb-style-appearance__field" icon="style-corner-radius-all" value="0" data-hb-style-all-value="appearance-corners" />
        <button type="button" class="hb-itrail hb-itrail--expand" aria-label="Expand corners" aria-expanded="false" data-hb-style-expand="appearance-corners">
            @include('heisenberg::components.ui.icon', ['name' => 'corners-out', 'size' => 18])
        </button>
    </div>
    <div id="appearance-corners" class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
        <x-ui.field icon="style-corner-radius-top-left" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.topLeft" data-hb-control-kind="supports" data-hb-control-type="text" />
        <x-ui.field icon="style-corner-radius-top-right" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.topRight" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
    <div class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
        <x-ui.field icon="style-corner-radius-bottom-left" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.bottomLeft" data-hb-control-kind="supports" data-hb-control-type="text" />
        <x-ui.field icon="style-corner-radius-bottom-right" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.bottomRight" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
</x-ui.panel-section>
