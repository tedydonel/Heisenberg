{{-- live/block/style/alignment — Alignment section (gated on supports.align): the
     6-way self-align bar (3 horizontal + 3 vertical), a ui/segmented. --}}
<x-ui.panel-section title="Alignment">
    <x-ui.segmented :active-index="5" :items="[
        ['value' => 'left', 'icon' => 'align-left-fill', 'label' => 'Left'],
        ['value' => 'center', 'icon' => 'align-center-horizontal-fill', 'label' => 'Center'],
        ['value' => 'right', 'icon' => 'align-right-fill', 'label' => 'Right'],
        ['value' => 'top', 'icon' => 'align-top-fill', 'label' => 'Top'],
        ['value' => 'middle', 'icon' => 'align-center-vertical-fill', 'label' => 'Middle'],
        ['value' => 'bottom', 'icon' => 'align-bottom-fill', 'label' => 'Bottom'],
    ]" />
</x-ui.panel-section>
