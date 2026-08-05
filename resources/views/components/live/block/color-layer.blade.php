{{-- live/block/color-layer — Fill and Stroke layer row. --}}
@props(['color' => '#000000', 'opacity' => '100'])
<div {{ $attributes->merge(['class' => 'hb-colorlayer']) }}>
    <div class="hb-colorlayer__fill">
        <span class="hb-colorlayer__l">
            <span class="hb-colorlayer__swatch" style="background: {{ $color }};"></span>
            <input type="text" class="hb-colorlayer__hex" value="{{ $color }}">
            <button type="button" class="hb-colorlayer__open" aria-label="Edit colour" aria-expanded="false" data-hb-style-color-trigger><x-ui.icon name="selection-all-fill" size="14" /></button>
        </span>
        <span class="hb-colorlayer__op"><span>{{ $opacity }}</span><x-ui.icon name="percent" size="14" /></span>
    </div>
    <button type="button" class="hb-colorlayer__rm" aria-label="Remove" data-hb-style-remove><x-ui.icon name="minus" size="14" /></button>
</div>
