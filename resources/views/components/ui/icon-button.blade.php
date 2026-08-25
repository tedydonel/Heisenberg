@once

@endonce

@props([
    'icon' => '',
    'label' => '',
    'active' => false,
    'disabled' => false,
])
<button
    type="button"
    aria-label="{{ $label }}"
    aria-pressed="{{ $active ? 'true' : 'false' }}"
    @if ($disabled) disabled @endif
    {{ $attributes->merge(['class' => 'hb-iconbtn' . ($active ? ' hb-iconbtn--active' : '')]) }}
>
    @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 16])
</button>
