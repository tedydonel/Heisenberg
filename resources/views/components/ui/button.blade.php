{{-- ui/button — from Pencil: Button / Primary (s5A5OK), Button / Secondary (BU00m), Button / Ghost (mB12t).
     Three variants collapsed into one component (variant prop) per shared geometry: gap 6, radius-md,
     fs-sm/500 label, optional 14px leading icon (hidden unless leadingIcon is set).
     Hover/disabled are inferred CSS states — the source shows one static frame per variant, no hover swatch.
     Height is a fixed 28px (explicit request, not from the source's padding-derived ~32px) so every
     button using this component is the same height everywhere it's used. --}}
@once
<style>
    .hb-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: var(--hb-space-2, 8px);
        height: 28px;
        padding: 0 14px;
        border: 1px solid transparent;
        border-radius: var(--hb-radius-md, 5px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        font-weight: 500;
        line-height: 1;
        cursor: pointer;
        white-space: nowrap;
        user-select: none;
        transition: background-color .12s ease, border-color .12s ease, color .12s ease;
    }
    .hb-btn:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 2px; }
    .hb-btn:disabled { opacity: .5; cursor: not-allowed; }
    .hb-btn__icon { display: inline-flex; width: 14px; height: 14px; }

    .hb-btn--primary {
        background: var(--hb-accent, #000);
        color: var(--hb-accent-fg, #fff);
    }
    .hb-btn--primary:hover:not(:disabled) { background: var(--hb-accent-hover, #1a1a1a); }

    .hb-btn--secondary {
        background: var(--hb-bg, #fff);
        color: var(--hb-text-primary, #0a0a0a);
        border-color: var(--hb-border, #e4e4e4);
    }
    .hb-btn--secondary:hover:not(:disabled) { background: var(--hb-surface-hover, #f7f7f7); }

    .hb-btn--ghost {
        background: transparent;
        color: var(--hb-text-secondary, #5a5a5a);
    }
    .hb-btn--ghost:hover:not(:disabled) { background: var(--hb-surface-hover, #f7f7f7); }
</style>
@endonce

@props([
    'variant' => 'primary',
    'type' => 'button',
    'leadingIcon' => null,
    'disabled' => false,
])
@php
    $variants = ['primary' => 'hb-btn--primary', 'secondary' => 'hb-btn--secondary', 'ghost' => 'hb-btn--ghost'];
    $variant = array_key_exists($variant, $variants) ? $variant : 'primary';
@endphp
<button
    type="{{ $type }}"
    @if ($disabled) disabled @endif
    {{ $attributes->merge(['class' => "hb-btn {$variants[$variant]}"]) }}
>
    @if ($leadingIcon)
        <span class="hb-btn__icon" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => $leadingIcon, 'size' => 14])
        </span>
    @endif
    {{ $slot }}
</button>
