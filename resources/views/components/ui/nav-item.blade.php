{{-- ui/nav-item — from Pencil Sidebar/NavItem (e6Xx1): 32px row, transparent by default. Active state
     is now sourced for real: the Sidebar (U5wHwo) instances this component 8x, and the "Nav — Components"
     instance overrides fill to $bg-inset with text-primary + label fontWeight 500 — that's the actual
     active treatment, replacing an earlier bg-muted guess made before this instance was inspected. --}}
@once
<style>
    .hb-navitem {
        display: flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        width: 100%;
        height: 32px;
        padding: 0 var(--hb-space-2, 8px);
        border: 0;
        border-radius: var(--hb-radius-sm, 3px);
        background: transparent;
        color: var(--hb-text-secondary, #5A5A5A);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        cursor: pointer;
        text-decoration: none;
    }
    .hb-navitem__icon { display: inline-flex; width: 14px; height: 14px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
    .hb-navitem:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-navitem--active { background: var(--hb-bg-inset, #EEEEEE); color: var(--hb-text-primary, #0A0A0A); font-weight: 500; }
    .hb-navitem--active .hb-navitem__icon { color: var(--hb-text-primary, #0A0A0A); }
    .hb-navitem:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: -2px; }
</style>
@endonce

@props(['icon' => 'circle', 'label' => '', 'active' => false])
<button type="button" aria-current="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => 'hb-navitem' . ($active ? ' hb-navitem--active' : '')]) }}>
    <span class="hb-navitem__icon" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 14])
    </span>
    <span>{{ $label !== '' ? $label : $slot }}</span>
</button>
