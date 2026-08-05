{{-- live/block/style — complete mounted Block Style composition. --}}
@props(['supports' => [], 'state' => 0])
@php
    $typography = [
        'fontFamily' => true,
        'fontWeight' => true,
        'fontSize' => true,
        'lineHeight' => true,
        'letterSpacing' => true,
    ];
@endphp
<div {{ $attributes->merge(['class' => 'hb-blockstyle']) }}>
    <x-ui.panel-section title="State">
        <x-ui.tabs :active-index="0" :items="[
            ['value' => 'default', 'label' => 'Default'],
            ['value' => 'hover', 'label' => 'Hover'],
            ['value' => 'active', 'label' => 'Active'],
            ['value' => 'focus', 'label' => 'Focus'],
        ]" />
    </x-ui.panel-section>

    <x-live.block.style.alignment />
    <x-live.block.style.typography :typography="$typography" />
    <x-live.block.style.position />
    <x-live.block.style.flex-layout />
    <x-live.block.style.spacing />
    <x-live.block.style.dimensions />
    <x-live.block.style.appearance />
    <x-live.block.style.fill />
    <x-live.block.style.stroke />
    <x-live.block.style.effects />

    <div class="hb-style-popup" data-hb-style-popup="color" hidden>
        <x-live.pickers.color-picker value="#000000" />
    </div>
    {{-- Padding/Margin's own mode-switcher popups are inlined inside live/block/style/spacing.blade.php
         itself now (2026-08-04) — nothing padding/margin-specific is registered from here anymore. --}}
    <div class="hb-style-popup" data-hb-style-popup="effect" hidden>
        <x-live.pickers.effect-editor />
    </div>
</div>
