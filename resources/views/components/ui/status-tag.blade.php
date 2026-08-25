@once

@endonce

@props(['label' => '', 'status' => 'neutral'])
@php $status = in_array($status, ['success', 'danger', 'neutral'], true) ? $status : 'neutral'; @endphp
<span {{ $attributes->merge(['class' => "hb-statustag hb-statustag--{$status}"]) }}>
    <span class="hb-statustag__dot" aria-hidden="true"></span>
    <span>{{ $label !== '' ? $label : $slot }}</span>
</span>
