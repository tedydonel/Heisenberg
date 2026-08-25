@once

@endonce

@props(['icon' => 'circle', 'label' => '', 'active' => false])
<button type="button" aria-current="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => 'hb-navitem' . ($active ? ' hb-navitem--active' : '')]) }}>
    <span class="hb-navitem__icon" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 14])
    </span>
    <span>{{ $label !== '' ? $label : $slot }}</span>
</button>
