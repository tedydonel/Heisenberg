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
