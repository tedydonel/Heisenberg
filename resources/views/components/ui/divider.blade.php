@once

@endonce

@props(['orientation' => 'horizontal'])
@php $orientation = $orientation === 'vertical' ? 'vertical' : 'horizontal'; @endphp
<hr {{ $attributes->merge(['class' => "hb-divider hb-divider--{$orientation}"]) }}>
