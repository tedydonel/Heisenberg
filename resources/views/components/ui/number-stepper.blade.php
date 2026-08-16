{{-- ui/number-stepper — three-cell row of (–)(field)(+) at the same 26px height as ui/input /
     ui/number-field. Two icon-buttons flank a real <input type="number">; the native input
     gives keyboard stepping (arrow up/down, scrubbing) for free, the buttons handle the
     pointer path. The buttons emit a regular 'change' event on the input via the
     dispatchEvent in the script below, which means the existing data-hb-control sync path
     (handleControlEvent etc.) fires without a separate handler.

     min/max honored both by the input attributes (browser arrow-key clamping) and by the
     buttons (clamped at the script boundary so a user mashing – at 0 with min=1 stops at 1,
     not -1). --}}
@once

@endonce
@props([
    'value' => 0,
    'min' => null,
    'max' => null,
    'step' => 1,
    'disabled' => false,
])
<div {{ $attributes->merge(['class' => 'hb-numstepper' . ($disabled ? ' hb-numstepper--disabled' : '')]) }}>
    <button
        type="button"
        class="hb-numstepper__btn"
        data-hb-numstepper-dec
        aria-label="{{ __('heisenberg::editor.common.decrement', [], 'heisenberg') }}"
        @if ($disabled) disabled @endif
    >
        @include('heisenberg::components.ui.icon', ['name' => 'minus', 'size' => 14])
    </button>
    <input
        type="number"
        class="hb-numstepper__value"
        value="{{ $value }}"
        @if (! is_null($min)) min="{{ $min }}" @endif
        @if (! is_null($max)) max="{{ $max }}" @endif
        step="{{ $step }}"
        @if ($disabled) disabled @endif
    >
    <button
        type="button"
        class="hb-numstepper__btn"
        data-hb-numstepper-inc
        aria-label="{{ __('heisenberg::editor.common.increment', [], 'heisenberg') }}"
        @if ($disabled) disabled @endif
    >
        @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 14])
    </button>
</div>
<script>
    (() => {
        const STEP_BY = (key) => {
            // The input could be inside a wrapper that captures the click — the closest input
            // is the only authoritative target for the value read/write. Buttons live as siblings,
            // not as labelable controls.
            document.querySelectorAll('[data-hb-numstepper-' + key + ']').forEach((btn) => {
                if (btn.__hbNumStepperBound) return;
                btn.__hbNumStepperBound = true;
                btn.addEventListener('click', () => {
                    if (btn.disabled) return;
                    const root = btn.parentElement;
                    if (!root) return;
                    const input = root.querySelector('.hb-numstepper__value');
                    if (!input || input.disabled) return;
                    const step = Number(input.step || 1) * (key === 'inc' ? 1 : -1);
                    const min = input.min !== '' ? Number(input.min) : null;
                    const max = input.max !== '' ? Number(input.max) : null;
                    let next = Number(input.value || 0) + step;
                    if (min !== null && next < min) next = min;
                    if (max !== null && next > max) next = max;
                    input.value = String(next);
                    // Native input fires 'input' on user typing and 'change' on commit. Button
                    // clicks bypass both — re-dispatch so any data-hb-control listener picks it up.
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
        };
        STEP_BY('inc');
        STEP_BY('dec');
    })();
</script>