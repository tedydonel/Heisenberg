@once

@endonce

@props(['color' => '#000000', 'size' => 28, 'selected' => false])
<button
    type="button"
    aria-selected="{{ $selected ? 'true' : 'false' }}"
    {{ $attributes->merge(['class' => 'hb-swatch', 'style' => "width:{$size}px;height:{$size}px;"]) }}
>
    <span class="hb-swatch__color" style="background:{{ $color }};"></span>
</button>
