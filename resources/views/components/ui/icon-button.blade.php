{{-- ui/icon-button — 40x26, cornerRadius 4 (source uses a literal
     4px here, not bound to any $radius-* variable — preserved as-is rather than substituting a token).
     Hover/active use --hb-surface-hover/--hb-surface-active by name-match; the source shows only the
     resting frame, no hover/active swatch. --}}
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
