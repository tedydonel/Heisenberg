{{-- ui/input — from Pencil UI/Input (Iq6wP): 100x26 default, value w/ optional trailing chevron.
     Distinct from ui/field (compound leading-icon/prefix/unit field) and ui/number-field per the
     source's own separation of these three. Real editable text input.
     Width is a prop (default 100px, matching the source's own instance) — other panels reuse this
     same node at 70px, 80px, and fill_container, per their own descendant overrides. --}}
@once
<style>
    .hb-input {
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--hb-space-2, 8px);
        height: 26px;
        min-width: 0;
        box-sizing: border-box;
        padding: 0 var(--hb-space-2, 8px);
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        transition: border-color .12s ease;
    }
    .hb-input:hover { border-color: var(--hb-border-strong, #C8C8C8); }
    .hb-input:focus-within { border-color: var(--hb-border-focus, #000); }
    .hb-input--disabled { opacity: .5; }
    .hb-input__value {
        flex: 1 1 auto;
        min-width: 0;
        border: 0;
        outline: 0;
        padding: 0;
        background: transparent;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-input__value::placeholder { color: var(--hb-text-muted, #9A9A9A); }
    .hb-input__chevron { display: inline-flex; width: 12px; height: 12px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
</style>
@endonce

@props([
    'value' => '',
    'placeholder' => '',
    'showChevron' => false,
    'disabled' => false,
    'width' => '100px',
])
<div {{ $attributes->merge(['class' => 'hb-input' . ($disabled ? ' hb-input--disabled' : ''), 'style' => "width:{$width};"]) }}>
    <input
        type="text"
        class="hb-input__value"
        value="{{ $value }}"
        placeholder="{{ $placeholder }}"
        @if ($disabled) disabled @endif
    >
    @if ($showChevron)
        <span class="hb-input__chevron" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 12])
        </span>
    @endif
</div>
