{{-- ui/status-tag — pill, dot + label, source shows only the success
     instance (success-soft bg, success dot, text-primary label). danger uses the direct token pair
     (danger-subtle/danger); neutral has no soft-bg token of its own, so it's inferred from bg-muted/
     text-muted, following the same soft-bg + dot-color pattern as the sourced success case. --}}
@once

@endonce

@props(['label' => '', 'status' => 'neutral'])
@php $status = in_array($status, ['success', 'danger', 'neutral'], true) ? $status : 'neutral'; @endphp
<span {{ $attributes->merge(['class' => "hb-statustag hb-statustag--{$status}"]) }}>
    <span class="hb-statustag__dot" aria-hidden="true"></span>
    <span>{{ $label !== '' ? $label : $slot }}</span>
</span>
