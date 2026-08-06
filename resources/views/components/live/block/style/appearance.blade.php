{{-- live/block/style/appearance — opacity + linked corner-radius controls.

     This section straddles two supports groups: opacity writes `appearance.opacity`, the four
     corner fields write `border.radius.*`. style-panel shows the section when EITHER group is
     supported and passes both flags, so each half gates on its own key. Text blocks are exactly
     the case that needs it: they declare `appearance` (opacity) but not `border`,
     so this renders as an opacity-only section. See docs/inspector-composition.md §4.2.

     The "all corners" field and its expand toggle belong to the corner group, not to opacity —
     they are presentation controls over the same four paths, so they go when the corners do,
     otherwise the row would keep a linked-value field driving nothing. --}}
@props(['showOpacity' => true, 'showCorners' => true])
<x-ui.panel-section title="Appearance">
    <div class="hb-irow hb-style-appearance__top">
        @if ($showOpacity)
            <x-ui.field class="hb-style-appearance__field" value="100" unit="%" data-hb-control="appearance.opacity" data-hb-control-kind="supports" data-hb-control-type="number" />
        @endif
        @if ($showCorners)
            <x-ui.field class="hb-style-appearance__field" icon="style-corner-radius-all" value="0" data-hb-style-all-value="appearance-corners" />
            <button type="button" class="hb-itrail hb-itrail--expand" aria-label="Expand corners" aria-expanded="false" data-hb-style-expand="appearance-corners">
                @include('heisenberg::components.ui.icon', ['name' => 'corners-out', 'size' => 18])
            </button>
        @endif
    </div>
    @if ($showCorners)
        <div id="appearance-corners" class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
            <x-ui.field icon="style-corner-radius-top-left" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.topLeft" data-hb-control-kind="supports" data-hb-control-type="text" />
            <x-ui.field icon="style-corner-radius-top-right" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.topRight" data-hb-control-kind="supports" data-hb-control-type="text" />
        </div>
        <div class="hb-irow hb-irow--pad-r" data-hb-style-expandable hidden>
            <x-ui.field icon="style-corner-radius-bottom-left" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.bottomLeft" data-hb-control-kind="supports" data-hb-control-type="text" />
            <x-ui.field icon="style-corner-radius-bottom-right" value="0" data-hb-style-side-value="appearance-corners" data-hb-control="border.radius.bottomRight" data-hb-control-kind="supports" data-hb-control-type="text" />
        </div>
    @endif
</x-ui.panel-section>
