@once

@endonce

@props(['for' => null])
<label @if ($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'hb-field-label']) }}>{{ $slot }}</label>
