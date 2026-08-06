{{-- ui/icon-button — 40x26, cornerRadius 4 (source uses a literal
     4px here, not bound to any $radius-* variable — preserved as-is rather than substituting a token).
     Hover/active use --hb-surface-hover/--hb-surface-active by name-match; the source shows only the
     resting frame, no hover/active swatch. --}}
@once
<style>
    .hb-iconbtn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 26px;
        padding: 0;
        background: var(--hb-bg-muted, #F4F4F4);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: 4px;
        color: var(--hb-text-secondary, #5A5A5A);
        cursor: pointer;
        transition: background-color .12s ease, color .12s ease;
    }
    .hb-iconbtn:hover:not(:disabled) {
        background: var(--hb-surface-hover, #F7F7F7);
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-iconbtn--active {
        background: var(--hb-surface-active, #F0F0F0);
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-iconbtn:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 1px; }
    .hb-iconbtn:disabled { opacity: .5; cursor: not-allowed; }
</style>
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
