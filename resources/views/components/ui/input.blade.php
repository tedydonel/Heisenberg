@once

@endonce

@props([
    'value' => '',
    'placeholder' => '',
    'showChevron' => false,
    'disabled' => false,
    'width' => null,
])
<div {{ $attributes->merge(array_filter(['class' => 'hb-input' . ($disabled ? ' hb-input--disabled' : ''), 'style' => $width ? "width:{$width};" : null])) }}>
    <input
        type="text"
        class="hb-input__value"
        value="{{ $value }}"
        placeholder="{{ $placeholder }}"
        @if ($disabled) disabled @endif
    >
    @if ($showChevron)
        <span class="hb-input__chevron" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 12])
        </span>
    @endif
</div>
