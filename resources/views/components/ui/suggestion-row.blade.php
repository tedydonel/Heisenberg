@once

@endonce

@props(['icon' => 'text-t', 'iconColor' => 'var(--hb-editing)', 'label' => ''])
<button type="button" {{ $attributes->merge(['class' => 'hb-suggestionrow']) }}>
    <span class="hb-suggestionrow__icon" style="color:{{ $iconColor }};" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 15])
    </span>
    <span class="hb-suggestionrow__label">{{ $label !== '' ? $label : $slot }}</span>
</button>
