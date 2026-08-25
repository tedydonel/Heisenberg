@once

@endonce

@props([
    'selected' => false,
    'disabled' => false,
    'label' => '',
    'name' => null,
    'value' => '1',
])
@php $name ??= 'hb-radio-' . uniqid(); @endphp
<label {{ $attributes->merge(['class' => 'hb-radio' . ($disabled ? ' hb-radio--disabled' : '')]) }}>
    <input
        type="radio"
        class="hb-radio__input"
        name="{{ $name }}"
        value="{{ $value }}"
        @if ($selected) checked @endif
        @if ($disabled) disabled @endif
    >
    <span class="hb-radio__box"><span class="hb-radio__dot"></span></span>
    @if ($label !== '')
        <span class="hb-radio__label">{{ $label }}</span>
    @endif
    {{ $slot }}
</label>
