{{-- ui/suggestion-row — 30px row, icon color is its own prop
     (source instance uses $editing for a "text" suggestion, but the task calls out iconColor as
     independently settable, so it's exposed as a raw color/token value rather than hardcoded). --}}
@once

@endonce

@props(['icon' => 'text-t', 'iconColor' => 'var(--hb-editing)', 'label' => ''])
<button type="button" {{ $attributes->merge(['class' => 'hb-suggestionrow']) }}>
    <span class="hb-suggestionrow__icon" style="color:{{ $iconColor }};" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 15])
    </span>
    <span class="hb-suggestionrow__label">{{ $label !== '' ? $label : $slot }}</span>
</button>
