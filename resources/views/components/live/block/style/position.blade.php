{{-- live/block/style/position — Position section. --}}
<x-ui.panel-section title="Position">
    <div class="hb-irow">
        <x-ui.field prefix="X" value="0" data-hb-control="position.x" data-hb-control-kind="supports" data-hb-control-type="text" />
        <x-ui.field prefix="Y" value="0" data-hb-control="position.y" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
    <div class="hb-irow">
        <x-ui.field prefix="R" value="0" data-hb-control="position.rotation" data-hb-control-kind="supports" data-hb-control-type="text" />
        <div></div>
    </div>
    {{-- Writes supports.position.mode, whose sanitizer is `position-mode` (static|relative|
         absolute). Off writes '' rather than 'static' so the variable falls back to
         SupportsStyle's own default instead of pinning an explicit value the user never chose. --}}
    <x-ui.checkbox class="hb-style-position__absolute" label="Absolute Position"
        data-hb-control="position.mode" data-hb-control-kind="supports" data-hb-control-type="checkbox"
        data-hb-control-on="absolute" data-hb-control-off="" />
</x-ui.panel-section>
