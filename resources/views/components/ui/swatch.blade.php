{{-- ui/swatch — 28x28, radius-sm, border-strong stroke, fill stack of
     [checkerboard shader, color]. Real CSS checkerboard painted behind the color layer so alpha colors
     (e.g. rgba()/#RRGGBBAA) show the checker through, per the task's explicit requirement. --}}
@once
<style>
    .hb-swatch {
        position: relative;
        display: inline-block;
        width: 28px;
        height: 28px;
        border-radius: var(--hb-radius-sm, 3px);
        border: 1px solid var(--hb-border-strong, #C8C8C8);
        overflow: hidden;
        cursor: pointer;
        background-image:
            linear-gradient(45deg, #ccc 25%, transparent 25%),
            linear-gradient(-45deg, #ccc 25%, transparent 25%),
            linear-gradient(45deg, transparent 75%, #ccc 75%),
            linear-gradient(-45deg, transparent 75%, #ccc 75%);
        background-size: 8px 8px;
        background-position: 0 0, 0 4px, 4px -4px, -4px 0;
        background-color: #fff;
    }
    .hb-swatch__color { position: absolute; inset: 0; }
    .hb-swatch[aria-selected="true"] { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 1px; }
</style>
@endonce

@props(['color' => '#000000', 'size' => 28, 'selected' => false])
<button
    type="button"
    aria-selected="{{ $selected ? 'true' : 'false' }}"
    {{ $attributes->merge(['class' => 'hb-swatch', 'style' => "width:{$size}px;height:{$size}px;"]) }}
>
    <span class="hb-swatch__color" style="background:{{ $color }};"></span>
</button>
