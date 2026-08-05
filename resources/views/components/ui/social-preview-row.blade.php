{{-- ui/social-preview-row — from Pencil Social Preview Row (JbljK): 36px row, cornerRadius radius-md,
     border, logo+label left, chevron right. Logo/label are props so this shell works for Facebook/X/
     LinkedIn alike (the source only instances the Facebook case for this particular reusable node). --}}
@once
<style>
    .hb-socialpreviewrow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        height: 36px;
        padding: 0 var(--hb-space-3, 12px);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        background: transparent;
        cursor: pointer;
        text-align: left;
    }
    .hb-socialpreviewrow__left { display: inline-flex; align-items: center; gap: var(--hb-space-2, 8px); }
    .hb-socialpreviewrow__logo { display: inline-flex; width: 16px; height: 16px; color: var(--hb-text-secondary, #5A5A5A); flex: none; }
    .hb-socialpreviewrow__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; color: var(--hb-text-primary, #0A0A0A); }
    .hb-socialpreviewrow__chevron { display: inline-flex; width: 13px; height: 13px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
</style>
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
