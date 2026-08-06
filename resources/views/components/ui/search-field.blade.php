{{-- ui/search-field — 36px inline row, bottom border only (no full
     box), leading 13px search icon. Real editable text input, not a static hint. --}}
@once
<style>
    .hb-searchfield {
        display: flex;
        align-items: center;
        gap: var(--hb-space-2, 8px);
        width: 100%;
        height: 36px;
        padding: 0 10px;
        border: 0;
        border-bottom: 1px solid var(--hb-border, #E4E4E4);
        flex: none;
    }
    .hb-searchfield__icon { display: inline-flex; width: 13px; height: 13px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
    .hb-searchfield__input {
        flex: 1 1 auto;
        min-width: 0;
        border: 0;
        outline: 0;
        background: transparent;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-searchfield__input::placeholder { color: var(--hb-text-muted, #9A9A9A); }
    .hb-searchfield:focus-within { border-bottom-color: var(--hb-border-focus, #000); }
</style>
@endonce

@props(['value' => '', 'placeholder' => 'Search…'])
<div {{ $attributes->merge(['class' => 'hb-searchfield']) }}>
    <span class="hb-searchfield__icon" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => 'magnifying-glass', 'size' => 13])
    </span>
    <input type="search" class="hb-searchfield__input" value="{{ $value }}" placeholder="{{ $placeholder }}">
</div>
