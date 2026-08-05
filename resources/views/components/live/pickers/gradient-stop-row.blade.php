{{-- live/pickers/gradient-stop-row — from Pencil Gradient Stop Row (u1QGS1): select handle +
     swatch, hex, opacity %, position %, remove.

     Static/prop-driven reference rendering for the component gallery. The live picker does NOT
     mount this file — it clones the equivalent markup from its own <template> so it can key rows
     by stop id and update them in place. Both share the .hb-gsr styles in 32-pickers.css, so this
     file's structure has to track that template; if you restyle one, restyle both. --}}
@props(['position' => '0', 'color' => '#000000', 'hex' => '#000000', 'opacity' => '100', 'active' => false])
<div class="hb-gsr{{ $active ? ' is-active' : '' }}">
    <button type="button" class="hb-gsr__sel" aria-label="{{ __('heisenberg::editor.color_picker.aria_select_stop') }}">
        <span class="hb-gsr__sw" style="background: {{ $color }};"></span>
    </button>
    <span class="hb-gsr__hexbox">
        <input type="text" class="hb-gsr__hex" spellcheck="false" value="{{ $hex }}" aria-label="{{ __('heisenberg::editor.color_picker.aria_stop_hex') }}">
    </span>
    <span class="hb-gsr__op">
        <input type="text" inputmode="decimal" value="{{ $opacity }}" aria-label="{{ __('heisenberg::editor.color_picker.aria_stop_opacity') }}"><span>%</span>
    </span>
    <span class="hb-gsr__op hb-gsr__op--pos">
        <input type="text" inputmode="decimal" value="{{ $position }}" aria-label="{{ __('heisenberg::editor.color_picker.aria_stop_position') }}"><span>%</span>
    </span>
    <button type="button" class="hb-gsr__rm" aria-label="{{ __('heisenberg::editor.color_picker.aria_remove_stop') }}">
        @include('heisenberg::components.ui.icon', ['name' => 'minus', 'size' => 16])
    </button>
</div>
