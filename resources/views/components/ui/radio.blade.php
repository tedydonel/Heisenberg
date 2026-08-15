{{-- ui/radio — 15x15 circular box, stroke border-strong, 7px accent
     dot. Source shows only the selected instance (dot visible, no enabled:false flag); unselected is
     inferred by hiding the dot, mirroring the sibling Checkbox's enabled/disabled icon pattern.
     Real <input type="radio"> — group multiple with the same `name` to form a radiogroup. --}}
@once

@endonce

@props([
    'selected' => false,
    'disabled' => false,
    'label' => '',
    // No shared default: radios that omit `name` must NOT merge into one page-wide
    // group. A real radiogroup always passes an explicit shared name.
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
