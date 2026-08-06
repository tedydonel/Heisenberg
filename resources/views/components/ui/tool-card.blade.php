{{-- ui/tool-card — vertical card, padding 12/6, centered 22px icon +
     centered wrapping fs-xs label. --}}
@once
<style>
    .hb-toolcard {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        padding: 12px 6px;
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-sm, 3px);
        cursor: pointer;
        text-align: center;
    }
    .hb-toolcard:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-toolcard__icon { display: inline-flex; width: 22px; height: 22px; color: var(--hb-text-secondary, #5A5A5A); }
    .hb-toolcard__label { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-secondary, #5A5A5A); }
</style>
@endonce

@props(['icon' => 'sparkle', 'label' => ''])
<button type="button" {{ $attributes->merge(['class' => 'hb-toolcard']) }}>
    <span class="hb-toolcard__icon" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 22])
    </span>
    <span class="hb-toolcard__label">{{ $label }}</span>
</button>
