{{-- ui/social-preview-row — 36px row, cornerRadius radius-md,
     border, logo+label left, chevron right. Logo/label are props so this shell works for Facebook/X/
     LinkedIn alike (the source only instances the Facebook case for this particular reusable node). --}}
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
