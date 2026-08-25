@once

@endonce

@props([
    'icon' => null,
    'prefix' => null,
    'value' => '',
    'unit' => null,
    'chevron' => false,
    'disabled' => false,
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
