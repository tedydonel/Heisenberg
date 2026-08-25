@once

@endonce

@props(['label' => 'Tab', 'active' => false])
<button type="button" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => 'hb-tabitem']) }}>
    {{ $label }}
</button>
