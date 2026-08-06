{{-- ui/number-field — 72x26, centered value on an inset fill.
     Real <input type="number"> — native stepping/arrow-key behavior covers "keyboard steps". --}}
@once
<style>
    .hb-numfield {
        display: inline-flex;
        width: 72px;
        height: 26px;
        background: var(--hb-bg-inset, #EEEEEE);
        border: 1px solid transparent;
        border-radius: var(--hb-radius-md, 5px);
        transition: border-color .12s ease;
    }
    .hb-numfield:focus-within { border-color: var(--hb-border-focus, #000); }
    .hb-numfield--disabled { opacity: .5; }
    .hb-numfield__value {
        flex: 1 1 auto;
        width: 100%;
        border: 0;
        outline: 0;
        background: transparent;
        text-align: center;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary, #0A0A0A);
        -moz-appearance: textfield;
    }
    .hb-numfield__value::-webkit-outer-spin-button,
    .hb-numfield__value::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
</style>
@endonce

@props([
    'value' => 0,
    'min' => null,
    'max' => null,
    'step' => 1,
    'disabled' => false,
])
<div {{ $attributes->merge(['class' => 'hb-numfield' . ($disabled ? ' hb-numfield--disabled' : '')]) }}>
    <input
        type="number"
        class="hb-numfield__value"
        value="{{ $value }}"
        @if (! is_null($min)) min="{{ $min }}" @endif
        @if (! is_null($max)) max="{{ $max }}" @endif
        step="{{ $step }}"
        @if ($disabled) disabled @endif
    >
</div>
