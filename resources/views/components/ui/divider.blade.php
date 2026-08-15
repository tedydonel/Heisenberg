{{-- ui/divider — 1px $border line, 180px default length. Vertical
     orientation isn't in the source (only the horizontal rectangle exists) — inferred by rotating the
     same 1px/border treatment 90°, no new value introduced. --}}
@once

@endonce

@props(['orientation' => 'horizontal'])
@php $orientation = $orientation === 'vertical' ? 'vertical' : 'horizontal'; @endphp
<hr {{ $attributes->merge(['class' => "hb-divider hb-divider--{$orientation}"]) }}>
