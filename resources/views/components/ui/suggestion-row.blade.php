{{-- ui/suggestion-row — 30px row, icon color is its own prop
     (source instance uses $editing for a "text" suggestion, but the task calls out iconColor as
     independently settable, so it's exposed as a raw color/token value rather than hardcoded). --}}
@once
<style>
    .hb-suggestionrow {
        display: flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        width: 100%;
        height: 30px;
        border: 0;
        background: transparent;
        text-align: left;
        cursor: pointer;
    }
    .hb-suggestionrow__icon { display: inline-flex; width: 15px; height: 15px; flex: none; }
    .hb-suggestionrow__label {
        flex: 1 1 auto;
        min-width: 0;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-secondary, #5A5A5A);
    }
    .hb-suggestionrow:hover { background: var(--hb-surface-hover, #F7F7F7); }
</style>
@endonce

@props(['icon' => 'text-t', 'iconColor' => 'var(--hb-editing, #3D68F5)', 'label' => ''])
<button type="button" {{ $attributes->merge(['class' => 'hb-suggestionrow']) }}>
    <span class="hb-suggestionrow__icon" style="color:{{ $iconColor }};" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 15])
    </span>
    <span class="hb-suggestionrow__label">{{ $label !== '' ? $label : $slot }}</span>
</button>
