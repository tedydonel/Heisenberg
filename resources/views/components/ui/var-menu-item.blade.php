{{-- ui/var-menu-item — from Pencil Var Menu Item (ejrry): 34px row, cornerRadius 6 (literal, no exact
     matching token — radius-md is 5, radius-lg is 8, neither matches, so the literal is kept). Leading
     check icon is present but transparent/hidden until selected. Trailing is a union: the source's
     color-type instance shows a swatch with the value hidden; a number-type var would show the value
     with the swatch hidden — modeled here as one `trailing` prop shaped {type: swatch|value, ...}. --}}
@once
<style>
    .hb-varmenuitem {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        width: 100%;
        height: 34px;
        padding: 0 10px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        cursor: pointer;
        text-align: left;
    }
    .hb-varmenuitem:hover { background: var(--hb-surface-hover, #F7F7F7); }
    .hb-varmenuitem__left { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
    .hb-varmenuitem__check { display: inline-flex; width: 13px; height: 13px; color: var(--hb-text-primary, #0A0A0A); visibility: hidden; flex: none; }
    .hb-varmenuitem--selected .hb-varmenuitem__check { visibility: visible; }
    .hb-varmenuitem__name { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); color: var(--hb-text-secondary, #5A5A5A); }
    .hb-varmenuitem__swatch { width: 16px; height: 16px; border-radius: 4px; border: 1px solid var(--hb-border-strong, #C8C8C8); flex: none; }
    .hb-varmenuitem__value { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); color: var(--hb-text-muted, #9A9A9A); flex: none; }
</style>
@endonce

@props(['name' => '', 'selected' => false, 'trailing' => null])
<button {{ $attributes->merge(['class' => 'hb-varmenuitem' . ($selected ? ' hb-varmenuitem--selected' : '')]) }}>
    <span class="hb-varmenuitem__left">
        <span class="hb-varmenuitem__check" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 13])
        </span>
        <span class="hb-varmenuitem__name">{{ $name }}</span>
    </span>
    @if (($trailing['type'] ?? null) === 'swatch')
        <span class="hb-varmenuitem__swatch" style="background:{{ $trailing['color'] ?? 'transparent' }};" aria-hidden="true"></span>
    @elseif (($trailing['type'] ?? null) === 'value')
        <span class="hb-varmenuitem__value">{{ $trailing['value'] ?? '' }}</span>
    @endif
</button>
