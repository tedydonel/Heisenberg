{{-- live/block/style/alignment — the BLOCK's placement within its parent (gated on
     supports.align). Distinct from Typography's Horizontal/Vertical pair, which places the text
     inside the block — see docs/inspector-composition.md §4.1.

     Wired 2026-08-05 (TODO 7.1). Only the three horizontal options render: `supports.align`
     accepts left|center|right|wide|full (BlockContractValidator::ALIGN_VALUES) and
     BlockRenderer::resolveClass() emits `hb-align-<value>` only for those. The design's three
     vertical options (top/middle/bottom) have no model path — vertical self-alignment needs a
     flex parent to align within, and nothing can contain a block yet (innerBlocks is disabled on
     every shipped contract). Rendering them would put three controls on screen that write
     nowhere, which is the defect this phase exists to remove. They return with containers. --}}
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
