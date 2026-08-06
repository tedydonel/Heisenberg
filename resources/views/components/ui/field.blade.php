{{-- ui/field — compound field, 120x26. L-group (optional leading icon +
     optional prefix + value) justified against an R-group (optional unit + optional chevron).
     cornerRadius 4 in the source is a literal, not bound to any $radius-* variable — kept as-is. --}}
@once
<style>
    .hb-field {
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--hb-space-2, 8px);
        height: 26px;
        min-width: 0;
        box-sizing: border-box;
        padding: 0 8px;
        background: var(--hb-bg-inset, #EEEEEE);
        border: 1px solid transparent;
        border-radius: 4px;
        transition: border-color .12s ease;
    }
    .hb-field:focus-within { border-color: var(--hb-border-focus, #000); }
    .hb-field--disabled { opacity: .5; }
    .hb-field__leading { display: inline-flex; align-items: center; gap: 6px; min-width: 0; }
    .hb-field__icon { display: inline-flex; width: 14px; height: 14px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
    .hb-field__prefix { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 12px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
    .hb-field__value {
        flex: 1 1 auto;
        min-width: 0;
        border: 0;
        outline: 0;
        padding: 0;
        background: transparent;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 12px;
        color: var(--hb-text-primary, #0A0A0A);
    }
    .hb-field__trailing { display: inline-flex; align-items: center; gap: 3px; flex: none; }
    .hb-field__unit { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: var(--hb-text-muted, #9A9A9A); }
    .hb-field__chevron { display: inline-flex; width: 11px; height: 11px; color: var(--hb-text-muted, #9A9A9A); cursor: pointer; }
</style>
@endonce

@props([
    'icon' => null,
    'prefix' => null,
    'value' => '',
    'unit' => null,
    'chevron' => false,
    'disabled' => false,
    'width' => '120px',
])
<div {{ $attributes->merge(['class' => 'hb-field' . ($disabled ? ' hb-field--disabled' : ''), 'style' => "width:{$width};"]) }}>
    <span class="hb-field__leading">
        @if ($icon)
            <span class="hb-field__icon" aria-hidden="true">
                @include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 14])
            </span>
        @endif
        @if ($prefix)
            <span class="hb-field__prefix">{{ $prefix }}</span>
        @endif
        <input type="text" class="hb-field__value" value="{{ $value }}" @if ($disabled) disabled @endif>
    </span>
    @if ($unit || $chevron)
        <span class="hb-field__trailing">
            @if ($unit)
                <span class="hb-field__unit">{{ $unit }}</span>
            @endif
            @if ($chevron)
                <span class="hb-field__chevron" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 11])
                </span>
            @endif
        </span>
    @endif
</div>
