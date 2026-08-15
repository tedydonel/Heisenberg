{{-- ui/number-field — 72x26, centered value on an inset fill.
     Real <input type="number"> — native stepping/arrow-key behavior covers "keyboard steps". --}}
@once

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
