@props(['color' => '#000000', 'opacity' => '100'])
<div {{ $attributes->merge(['class' => 'hb-colorlayer']) }}>
    <div class="hb-colorlayer__fill">
        <span class="hb-colorlayer__l">
            <span class="hb-colorlayer__swatch" style="background: {{ $color }};" role="button" tabindex="0"
                aria-label="{{ __('heisenberg::editor.inspector.pick_colour') }}" data-hb-style-color-trigger></span>
            <input type="text" class="hb-colorlayer__hex" value="{{ $color }}">
            <button type="button" class="hb-colorlayer__open" aria-expanded="false"
                aria-label="{{ __('heisenberg::editor.inspector.bind_theme_variable') }}"
                data-hb-style-var-trigger><x-heisenberg::ui.icon name="selection-all-fill" size="14" /></button>
        </span>
        <span class="hb-colorlayer__op"><span data-hb-style-layer-opacity>{{ $opacity }}</span><x-heisenberg::ui.icon name="percent" size="14" /></span>
    </div>
    <button type="button" class="hb-colorlayer__rm" aria-label="Remove" data-hb-style-remove><x-heisenberg::ui.icon name="minus" size="14" /></button>
</div>
