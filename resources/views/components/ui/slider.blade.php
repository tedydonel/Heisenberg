@once

<script>
    (() => {
        const paint = (input) => {
            const min = Number(input.min || 0);
            const max = Number(input.max || 100);
            const pct = max > min ? ((Number(input.value) - min) / (max - min)) * 100 : 0;
            input.style.setProperty('--hb-slider-pct', pct + '%');
        };
        const boot = () => {
            document.querySelectorAll('[data-hb-slider]').forEach((input) => {
                paint(input);
                if (input.__hbSlider) return;
                input.addEventListener('input', () => paint(input));
                input.__hbSlider = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
        document.addEventListener('hb:block-selected', boot);
        document.addEventListener('hb:block-updated', boot);
    })();
</script>
@endonce

@props([
    'value' => 50,
    'min' => 0,
    'max' => 100,
    'step' => 1,
    'disabled' => false,
])
<span class="hb-slider">
    <input
        type="range"
        data-hb-slider
        min="{{ $min }}"
        max="{{ $max }}"
        step="{{ $step }}"
        value="{{ $value }}"
        @if ($disabled) disabled @endif
        {{ $attributes->merge(['class' => 'hb-slider__input']) }}
    >
</span>
