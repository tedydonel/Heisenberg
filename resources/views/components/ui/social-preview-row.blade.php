@props([
    'logo' => 'facebook-logo-bold',
    'label' => '',
    'network' => 'facebook',
    'expanded' => false,
])
<div class="hb-social-preview-group" data-hb-social-group="{{ $network }}" data-expanded="{{ $expanded ? 'true' : 'false' }}">
    <button type="button" class="hb-socialpreviewrow" data-hb-social-toggle="{{ $network }}" aria-expanded="{{ $expanded ? 'true' : 'false' }}" aria-label="{{ $label }}">
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
    <div class="hb-social-card hb-social-card--{{ $network }}" data-hb-social-card="{{ $network }}">
        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            <div class="hb-social-card__media">
                <img class="hb-social-card__img" data-hb-social-preview-img src="" alt="" hidden>
                <div class="hb-social-card__placeholder" data-hb-social-preview-img-ph>
                    @include('heisenberg::components.ui.icon', ['name' => 'image', 'size' => 28])
                </div>
            </div>
            <div class="hb-social-card__body">
                @if ($network === 'linkedin')
                    <h4 class="hb-social-card__title" data-hb-social-preview-title></h4>
                    <span class="hb-social-card__domain" data-hb-social-preview-domain></span>
                @else
                    <span class="hb-social-card__domain" data-hb-social-preview-domain></span>
                    <h4 class="hb-social-card__title" data-hb-social-preview-title></h4>
                    <p class="hb-social-card__desc" data-hb-social-preview-desc></p>
                @endif
            </div>
        @endif
    </div>
</div>
