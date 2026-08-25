@props(['values' => ['left', 'center', 'right']])
@php
    $icons = [
        'left' => ['align-left-fill', 'Align left'],
        'center' => ['align-center-horizontal-fill', 'Align center'],
        'right' => ['align-right-fill', 'Align right'],
        'wide' => ['align-center-horizontal-fill', 'Wide'],
        'full' => ['align-center-horizontal-fill', 'Full width'],
    ];
    $items = [];
    foreach ($values as $value) {
        if (! isset($icons[$value])) {
            continue;
        }
        $items[] = ['value' => $value, 'icon' => $icons[$value][0], 'label' => $icons[$value][1]];
    }
@endphp
<x-ui.panel-section title="Alignment">
    <x-ui.segmented :active-index="null" :items="$items"
        data-hb-control="align" data-hb-control-kind="supports" data-hb-control-type="segmented" />
</x-ui.panel-section>
