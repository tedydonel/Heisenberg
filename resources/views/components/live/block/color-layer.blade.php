{{-- live/block/color-layer — Fill and Stroke layer row.

     Markup is the .pen composition as extracted (see Documents/head-ui.html: swatch, hex text,
     selection-all-fill icon, then the opacity value + percent icon, then the remove minus).
     Only behaviour hooks are added here — an earlier pass rewrote the swatch into a <button> and
     the opacity into an <input>, which was a redesign, not a wiring change. Reverted.

     Two affordances, deliberately distinct:
       - the SWATCH opens the colour picker (pick a literal colour, alpha included)
       - the selection-all-fill icon opens the THEME VARIABLE popup (bind to a token)

     Opacity stays a display span rather than becoming an editable field: the colour picker
     already carries an alpha slider and emits it on colorchange, so the layer's alpha comes from
     there. A second way to type the same number would be redundant. --}}
@props(['color' => '#000000', 'opacity' => '100'])
<div {{ $attributes->merge(['class' => 'hb-colorlayer']) }}>
    <div class="hb-colorlayer__fill">
        <span class="hb-colorlayer__l">
            <span class="hb-colorlayer__swatch" style="background: {{ $color }};" role="button" tabindex="0"
                aria-label="{{ __('heisenberg::editor.inspector.pick_colour') }}" data-hb-style-color-trigger></span>
            <input type="text" class="hb-colorlayer__hex" value="{{ $color }}">
            <button type="button" class="hb-colorlayer__open" aria-expanded="false"
                aria-label="{{ __('heisenberg::editor.inspector.bind_theme_variable') }}"
                data-hb-style-var-trigger><x-ui.icon name="selection-all-fill" size="14" /></button>
        </span>
        <span class="hb-colorlayer__op"><span data-hb-style-layer-opacity>{{ $opacity }}</span><x-ui.icon name="percent" size="14" /></span>
    </div>
    <button type="button" class="hb-colorlayer__rm" aria-label="Remove" data-hb-style-remove><x-ui.icon name="minus" size="14" /></button>
</div>
