@once

@endonce

@props(['colors' => ['#FFFFFF', '#000000', '#0A0A0A'], 'label' => '', 'selected' => false])
<button
    type="button"
    aria-pressed="{{ $selected ? 'true' : 'false' }}"
    {{ $attributes->merge(['class' => 'hb-themepresetcard' . ($selected ? ' hb-themepresetcard--selected' : '')]) }}
>
    <span class="hb-themepresetcard__swatches" aria-hidden="true">
        @foreach (array_slice($colors, 0, 3) as $color)
            <span class="hb-themepresetcard__swatch" style="background:{{ $color }};"></span>
        @endforeach
    </span>
    <span class="hb-themepresetcard__label">{{ $label }}</span>
</button>
