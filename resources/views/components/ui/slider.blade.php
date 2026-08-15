{{-- ui/slider — 6px track (bg-inset), 6px accent fill, 16px surface handle
     with drop shadow, handle vertically centered on the track ((16-6)/2 = 5px, matches source's -5 y
     offset). Native <input type="range"> under custom styling — gives real dragging, keyboard steps,
     and change events for free instead of hand-rolled pointer-event code. A tiny script paints the
     fill portion via a CSS custom property since range fill-percentage isn't stylable in pure CSS. --}}
@once

<script>
    (() => {
        const paint = (input) => {
            const min = Number(input.min || 0);
            const max = Number(input.max || 100);
            const pct = max > min ? ((Number(input.value) - min) / (max - min)) * 100 : 0;
            input.style.setProperty('--hb-slider-pct', pct + '%');
        };
        // Repaint EVERY slider, wired or not. The fill is a painted custom property, so a
        // value set programmatically (the inspector syncing a selected block, a re-render,
        // a restored revision) leaves it showing the previous block's position — the
        // "doesn't render right after a refresh" symptom. Wiring stays once-only.
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
        // A selection or a model write repaints too — neither dispatches hb:refresh, and both
        // change what the slider should be showing.
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
