{{-- ui/tool-card — vertical card, padding 12/6, centered 22px icon +
     centered wrapping fs-xs label. --}}
@once

@endonce

@props(['icon' => 'sparkle', 'label' => ''])
<button type="button" {{ $attributes->merge(['class' => 'hb-toolcard']) }}>
    <span class="hb-toolcard__icon" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 22])
    </span>
    <span class="hb-toolcard__label">{{ $label }}</span>
</button>
