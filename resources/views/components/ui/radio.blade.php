{{-- ui/radio — 15x15 circular box, stroke border-strong, 7px accent
     dot. Source shows only the selected instance (dot visible, no enabled:false flag); unselected is
     inferred by hiding the dot, mirroring the sibling Checkbox's enabled/disabled icon pattern.
     Real <input type="radio"> — group multiple with the same `name` to form a radiogroup. --}}
@once
<style>
    .hb-radio { display: inline-flex; align-items: center; gap: var(--hb-space-2, 8px); cursor: pointer; }
    .hb-radio--disabled { opacity: .5; cursor: not-allowed; }
    .hb-radio__input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .hb-radio__box {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 15px;
        height: 15px;
        flex: none;
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border-strong, #C8C8C8);
        border-radius: 999px;
    }
    .hb-radio__dot { width: 7px; height: 7px; border-radius: 999px; background: var(--hb-accent, #000); visibility: hidden; }
    .hb-radio__input:checked ~ .hb-radio__box .hb-radio__dot { visibility: visible; }
    .hb-radio__input:focus-visible ~ .hb-radio__box { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 1px; }
    .hb-radio__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12.5px; color: var(--hb-text-secondary, #5A5A5A); }
</style>
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
