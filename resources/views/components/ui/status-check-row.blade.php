@once

@endonce

@props(['status' => 'pass', 'text' => ''])
@php
    $hbStatusCheckIcons = [
        'pass' => 'check-circle-fill',
        'warn' => 'warning-circle',
        'fail' => 'x-circle-fill',
        'na'   => 'minus-circle',
    ];
    $status = array_key_exists($status, $hbStatusCheckIcons) ? $status : 'pass';
@endphp
<div {{ $attributes->merge(['class' => 'hb-statuscheckrow']) }}>
    <span class="hb-statuscheckrow__icon hb-statuscheckrow__icon--{{ $status }}" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $hbStatusCheckIcons[$status], 'size' => 14])
    </span>
    <span class="hb-statuscheckrow__text">{{ $text !== '' ? $text : $slot }}</span>
</div>
