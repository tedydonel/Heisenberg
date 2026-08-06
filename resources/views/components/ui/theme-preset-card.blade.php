{{-- ui/theme-preset-card — vertical card, 3 stacked 16px round
     swatches + centered label. "selected" isn't in the source (only one instance exists) — inferred as
     a border-focus outline, the same highlight convention used when ui/select is open. --}}
@once
<style>
    .hb-themepresetcard {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: var(--hb-space-2, 8px);
        width: 100%;
        padding: 12px 10px;
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-sm, 3px);
        cursor: pointer;
        text-align: center;
    }
    .hb-themepresetcard--selected { border-color: var(--hb-border-focus, #000); }
    .hb-themepresetcard__swatches { display: flex; gap: 4px; }
    .hb-themepresetcard__swatch { width: 16px; height: 16px; border-radius: 999px; border: 1px solid var(--hb-border, #E4E4E4); }
    .hb-themepresetcard__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-secondary, #5A5A5A); }
</style>
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
