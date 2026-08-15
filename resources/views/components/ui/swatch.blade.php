{{-- ui/swatch — 28x28, radius-sm, border-strong stroke, fill stack of
     [checkerboard shader, color]. Real CSS checkerboard painted behind the color layer so alpha colors
     (e.g. rgba()/#RRGGBBAA) show the checker through, per the task's explicit requirement. --}}
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
