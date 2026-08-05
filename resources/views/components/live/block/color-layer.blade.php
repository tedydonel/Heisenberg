{{-- live/block/color-layer — one Fill or Stroke colour layer.

     Two affordances, deliberately distinct (2026-08-05):
       - the SWATCH opens the colour picker (pick a literal colour)
       - the trailing selection-all-fill icon opens the THEME VARIABLE popup (bind to a token)

     Before this the icon opened the colour picker and there was no way to bind a layer to a
     token, so inspector.blade.php's decorator injected a SECOND selection-all-fill trigger
     outside the field — two identical icons doing different things. The decorator skips any
     control that already contains a var trigger, so marking the icon here removes the duplicate
     at its source rather than special-casing this component inside the decorator.

     The var trigger needs no data-hb-style-var-for: it sits INSIDE the element carrying
     data-hb-control, so closest() resolves its target.

     Opacity is a real input, not a static span — layers composite, and a layer's alpha is what
     makes stacking meaningful (see the fill compositing note in style/fill.blade.php). --}}
@props(['color' => '#000000', 'opacity' => '100'])
<div {{ $attributes->merge(['class' => 'hb-colorlayer']) }}>
    <div class="hb-colorlayer__fill">
        <span class="hb-colorlayer__l">
            <button type="button" class="hb-colorlayer__swatch" style="background: {{ $color }};"
                aria-label="{{ __('heisenberg::editor.inspector.pick_colour') }}" aria-expanded="false"
                data-hb-style-color-trigger></button>
            <input type="text" class="hb-colorlayer__hex" value="{{ $color }}">
            <button type="button" class="hb-colorlayer__open" aria-expanded="false"
                aria-label="{{ __('heisenberg::editor.inspector.bind_theme_variable') }}"
                data-hb-style-var-trigger><x-ui.icon name="selection-all-fill" size="14" /></button>
        </span>
        <span class="hb-colorlayer__op"><input type="text" class="hb-colorlayer__opv" value="{{ $opacity }}" aria-label="{{ __('heisenberg::editor.inspector.layer_opacity') }}" data-hb-style-layer-opacity><x-ui.icon name="percent" size="14" /></span>
    </div>
    <button type="button" class="hb-colorlayer__rm" aria-label="Remove" data-hb-style-remove><x-ui.icon name="minus" size="14" /></button>
</div>
