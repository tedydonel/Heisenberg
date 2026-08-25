@once

@endonce

@props(['logo' => 'facebook-logo-bold', 'label' => ''])
<button type="button" {{ $attributes->merge(['class' => 'hb-socialpreviewrow']) }}>
    <span class="hb-socialpreviewrow__left">
        <span class="hb-socialpreviewrow__logo" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => $logo, 'size' => 16])
        </span>
        <span class="hb-socialpreviewrow__label">{{ $label }}</span>
    </span>
    <span class="hb-socialpreviewrow__chevron" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => 'caret-right', 'size' => 13])
    </span>
</button>
