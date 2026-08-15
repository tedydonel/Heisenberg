{{-- ui/input — 100x26 default, value w/ optional trailing chevron.
     Distinct from ui/field (compound leading-icon/prefix/unit field) and ui/number-field per the
     source's own separation of these three. Real editable text input.
     Width is a prop (default 100px, matching the source's own instance) — other panels reuse this
     same node at 70px, 80px, and fill_container, per their own descendant overrides. --}}
@once

@endonce

@props([
    'value' => '',
    'placeholder' => '',
    'showChevron' => false,
    'disabled' => false,
    // Fluid by default (see ui/field): the inline width overrode the panel's own layout,
    // which is why a URL field showed ~15 characters inside a much wider section.
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
