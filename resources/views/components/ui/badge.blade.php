@once

@endonce

@props(['label' => '', 'variant' => 'neutral'])
@php $variant = in_array($variant, ['neutral', 'accent'], true) ? $variant : 'neutral'; @endphp
<span {{ $attributes->merge(['class' => "hb-badge hb-badge--{$variant}"]) }}>{{ $label !== '' ? $label : $slot }}</span>
