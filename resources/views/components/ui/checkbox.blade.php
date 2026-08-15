{{-- ui/checkbox — 15x15 box, cornerRadius 3, stroke border-strong,
     check icon (11px, accent-fg) present but disabled=hidden in the unchecked reference. The checked
     box fill ($accent) is inferred — not shown separately in source — because an accent-fg (white)
     check glyph needs a filled background to be visible; unchecked stays $bg per source.
     Indeterminate is not in the source at all (only checked/unchecked instances exist); implemented
     as the same filled box with a dash glyph, inferred from the checked treatment.
     Real <input type="checkbox"> under the hood — native change/keyboard/indeterminate behavior. --}}
@once

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
