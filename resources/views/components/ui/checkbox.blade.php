{{-- ui/checkbox — from Pencil UI/CheckRow (teCah): 15x15 box, cornerRadius 3, stroke border-strong,
     check icon (11px, accent-fg) present but disabled=hidden in the unchecked reference. The checked
     box fill ($accent) is inferred — not shown separately in source — because an accent-fg (white)
     check glyph needs a filled background to be visible; unchecked stays $bg per source.
     Indeterminate is not in the source at all (only checked/unchecked instances exist); implemented
     as the same filled box with a dash glyph, inferred from the checked treatment.
     Real <input type="checkbox"> under the hood — native change/keyboard/indeterminate behavior. --}}
@once
<style>
    .hb-checkbox { display: inline-flex; align-items: center; gap: var(--hb-space-2, 8px); cursor: pointer; }
    .hb-checkbox--disabled { opacity: .5; cursor: not-allowed; }
    .hb-checkbox__input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .hb-checkbox__box {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 15px;
        height: 15px;
        flex: none;
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border-strong, #C8C8C8);
        border-radius: 3px;
    }
    .hb-checkbox__check { display: inline-flex; width: 11px; height: 11px; color: var(--hb-accent-fg, #fff); visibility: hidden; }
    .hb-checkbox__input:checked ~ .hb-checkbox__box,
    .hb-checkbox__input:indeterminate ~ .hb-checkbox__box {
        background: var(--hb-accent, #000);
        border-color: var(--hb-accent, #000);
    }
    .hb-checkbox__input:checked ~ .hb-checkbox__box .hb-checkbox__check,
    .hb-checkbox__input:indeterminate ~ .hb-checkbox__box .hb-checkbox__check { visibility: visible; }
    .hb-checkbox__input:focus-visible ~ .hb-checkbox__box { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 1px; }
    .hb-checkbox__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12.5px; color: var(--hb-text-secondary, #5A5A5A); }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-checkbox-indeterminate="true"]').forEach((input) => {
                input.indeterminate = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'checked' => false,
    'indeterminate' => false,
    'disabled' => false,
    'label' => '',
    'name' => null,
    'value' => '1',
])
<label {{ $attributes->merge(['class' => 'hb-checkbox' . ($disabled ? ' hb-checkbox--disabled' : '')]) }}>
    <input
        type="checkbox"
        class="hb-checkbox__input"
        @if ($name) name="{{ $name }}" @endif
        value="{{ $value }}"
        @if ($checked) checked @endif
        @if ($disabled) disabled @endif
        @if ($indeterminate) data-hb-checkbox-indeterminate="true" @endif
    >
    <span class="hb-checkbox__box">
        <span class="hb-checkbox__check" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => $indeterminate ? 'minus' : 'check-bold', 'size' => 11])
        </span>
    </span>
    @if ($label !== '')
        <span class="hb-checkbox__label">{{ $label }}</span>
    @endif
    {{ $slot }}
</label>
