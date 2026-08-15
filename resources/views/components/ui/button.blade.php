{{-- ui/button —
     Three variants collapsed into one component (variant prop) per shared geometry: gap 6, radius-md,
     fs-sm/500 label, optional 14px leading icon (hidden unless leadingIcon is set).
     Hover/disabled are inferred CSS states — the source shows one static frame per variant, no hover swatch.
     Height is a fixed 28px (explicit request, not from the source's padding-derived ~32px) so every
     button using this component is the same height everywhere it's used. --}}
@once

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
