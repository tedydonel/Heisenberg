{{-- ui/field — compound field, 120x26. L-group (optional leading icon +
     optional prefix + value) justified against an R-group (optional unit + optional chevron).
     cornerRadius 4 in the source is a literal, not bound to any $radius-* variable — kept as-is. --}}
@once

@endonce

@props([
    'icon' => null,
    'prefix' => null,
    'value' => '',
    'unit' => null,
    'chevron' => false,
    'disabled' => false,
    // No default width: a fixed inline width beat every stylesheet rule that tries to make
    // a field fill its row (.hb-irow .hb-field { width: 100% }), so fields were pinned at
    // 120px inside panels far wider than that. Pass one only where a fixed size is meant.
    'width' => null,
])
<div {{ $attributes->merge(array_filter(['class' => 'hb-field' . ($disabled ? ' hb-field--disabled' : ''), 'style' => $width ? "width:{$width};" : null])) }}>
    <span class="hb-field__leading">
        @if ($icon)
            <span class="hb-field__icon" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 14])
            </span>
        @endif
        @if ($prefix)
            <span class="hb-field__prefix">{{ $prefix }}</span>
        @endif
        <input type="text" class="hb-field__value" value="{{ $value }}" @if ($disabled) disabled @endif>
    </span>
    @if ($unit || $chevron)
        <span class="hb-field__trailing">
            @if ($unit)
                <span class="hb-field__unit">{{ $unit }}</span>
            @endif
            @if ($chevron)
                <span class="hb-field__chevron" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 11])
                </span>
            @endif
        </span>
    @endif
</div>
