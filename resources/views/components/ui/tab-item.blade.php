{{-- ui/tab-item — standalone bordered chip tab (bg, border, radius-sm,
     padding 5/12, fs-xs). Distinct from the pill segments inside ui/tabs — the source defines this as
     its own bordered-chip look, not reused as-is inside the Tabs track, so it's kept as a separate atom
     per the task's rule to not force unrelated-looking things into one component. --}}
@once
<style>
    .hb-tabitem {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 5px 12px;
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-sm, 3px);
        color: var(--hb-text-secondary, #5A5A5A);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-xs, 11px);
        cursor: pointer;
    }
    .hb-tabitem[aria-selected="true"] { color: var(--hb-accent, #000); border-color: var(--hb-border-strong, #C8C8C8); }
    .hb-tabitem:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 1px; }
</style>
@endonce

@props(['label' => 'Tab', 'active' => false])
<button type="button" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => 'hb-tabitem']) }}>
    {{ $label }}
</button>
