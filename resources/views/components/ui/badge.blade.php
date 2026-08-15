{{-- ui/badge — pill, padding 3/8, bg-inset/text-secondary. Source only shows
     the neutral instance; "accent" variant is inferred (solid accent/accent-fg, matching how the design
     treats accent elsewhere) since no accent-tinted badge token/instance exists to source from. --}}
@once

@endonce

@props(['label' => '', 'variant' => 'neutral'])
@php $variant = in_array($variant, ['neutral', 'accent'], true) ? $variant : 'neutral'; @endphp
<span {{ $attributes->merge(['class' => "hb-badge hb-badge--{$variant}"]) }}>{{ $label !== '' ? $label : $slot }}</span>
