@once

@endonce

@props([
    'on' => false,
    'disabled' => false,
    'name' => null,
])
<label {{ $attributes->merge(['class' => 'hb-toggle' . ($disabled ? ' hb-toggle--disabled' : '')]) }}>
    <input
        type="checkbox"
        role="switch"
        class="hb-toggle__input"
        @if ($name) name="{{ $name }}" @endif
        @if ($on) checked @endif
        @if ($disabled) disabled @endif
    >
    <span class="hb-toggle__track" aria-hidden="true"></span>
    <span class="hb-toggle__knob" aria-hidden="true"></span>
</label>
