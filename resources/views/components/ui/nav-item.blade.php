{{-- ui/nav-item — 32px row, transparent by default. Active state
     is now sourced for real: the Sidebar (U5wHwo) instances this component 8x, and the "Nav — Components"
     instance overrides fill to $bg-inset with text-primary + label fontWeight 500 — that's the actual
     active treatment, replacing an earlier bg-muted guess made before this instance was inspected. --}}
@once

@endonce

@props(['icon' => 'circle', 'label' => '', 'active' => false])
<button type="button" aria-current="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => 'hb-navitem' . ($active ? ' hb-navitem--active' : '')]) }}>
    <span class="hb-navitem__icon" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 14])
    </span>
    <span>{{ $label !== '' ? $label : $slot }}</span>
</button>
