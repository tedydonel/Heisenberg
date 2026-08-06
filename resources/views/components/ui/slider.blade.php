{{-- ui/slider — 6px track (bg-inset), 6px accent fill, 16px surface handle
     with drop shadow, handle vertically centered on the track ((16-6)/2 = 5px, matches source's -5 y
     offset). Native <input type="range"> under custom styling — gives real dragging, keyboard steps,
     and change events for free instead of hand-rolled pointer-event code. A tiny script paints the
     fill portion via a CSS custom property since range fill-percentage isn't stylable in pure CSS. --}}
@once
<style>
    .hb-slider { position: relative; display: inline-flex; align-items: center; width: 180px; height: 16px; }
    .hb-slider__input {
        -webkit-appearance: none;
        appearance: none;
        width: 100%;
        height: 6px;
        margin: 0;
        border-radius: var(--hb-radius-sm, 3px);
        background: linear-gradient(
            to right,
            var(--hb-accent, #000) 0%,
            var(--hb-accent, #000) var(--hb-slider-pct, 50%),
            var(--hb-bg-inset, #EEEEEE) var(--hb-slider-pct, 50%),
            var(--hb-bg-inset, #EEEEEE) 100%
        );
        cursor: pointer;
    }
    .hb-slider__input::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 16px;
        height: 16px;
        border-radius: 999px;
        background: var(--hb-surface, #fff);
        border: 1px solid var(--hb-border-strong, #C8C8C8);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .25);
        cursor: grab;
    }
    .hb-slider__input::-moz-range-thumb {
        width: 16px;
        height: 16px;
        border: 1px solid var(--hb-border-strong, #C8C8C8);
        border-radius: 999px;
        background: var(--hb-surface, #fff);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .25);
        cursor: grab;
    }
    .hb-slider__input::-moz-range-track { height: 6px; border-radius: var(--hb-radius-sm, 3px); background: transparent; }
    .hb-slider__input:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 2px; }
    .hb-slider__input:disabled { opacity: .5; cursor: not-allowed; }
</style>
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
                if (input.__hbSlider) return;
                paint(input);
                input.addEventListener('input', () => paint(input));
                input.__hbSlider = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
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
