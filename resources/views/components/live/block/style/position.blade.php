<x-heisenberg::ui.panel-section title="Position">
    <div class="hb-irow">
        <x-heisenberg::ui.field prefix="X" value="0" data-hb-control="position.x" data-hb-control-kind="supports" data-hb-control-type="text" />
        <x-heisenberg::ui.field prefix="Y" value="0" data-hb-control="position.y" data-hb-control-kind="supports" data-hb-control-type="text" />
    </div>
    <div class="hb-irow">
        <x-heisenberg::ui.field prefix="R" value="0" data-hb-control="position.rotation" data-hb-control-kind="supports" data-hb-control-type="text" />
        <div></div>
    </div>
        <x-heisenberg::ui.checkbox class="hb-style-position__absolute" label="Absolute Position"
        data-hb-control="position.mode" data-hb-control-kind="supports" data-hb-control-type="checkbox"
        data-hb-control-on="absolute" data-hb-control-off="" />
</x-heisenberg::ui.panel-section>
