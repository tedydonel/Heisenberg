{{-- ui/select — one component, open state driven by data-open. Menu options: selected =
     bg-muted + text-primary + trailing check icon, unselected = transparent + text-secondary,
     no icon. Open trigger swaps caret-down -> caret-up (text-secondary, not text-muted)
     and border -> border-focus. No Alpine on this page yet (see icon/tab notes) — vanilla JS,
     same self-contained @once <script> pattern as ui/custom-scrollbar. --}}
@once
<style>
    .hb-select { position: relative; display: inline-flex; width: 150px; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-select__trigger {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--hb-space-2, 8px);
        width: 100%;
        height: 30px;
        padding: 0 10px;
        background: var(--hb-bg, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        color: var(--hb-text-primary, #0A0A0A);
        font-size: var(--hb-fs-sm, 12px);
        cursor: pointer;
    }
    .hb-select[data-open="true"] .hb-select__trigger { border-color: var(--hb-border-focus, #000); }
    .hb-select__value--placeholder { color: var(--hb-text-muted, #9A9A9A); }
    .hb-select__caret { display: inline-flex; width: 14px; height: 14px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
    .hb-select[data-open="true"] .hb-select__caret { color: var(--hb-text-secondary, #5A5A5A); }
    .hb-select--disabled .hb-select__trigger { opacity: .5; cursor: not-allowed; }
    .hb-select__menu {
        position: absolute;
        top: calc(100% + var(--hb-space-1, 4px));
        left: 0;
        right: 0;
        z-index: 20;
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: var(--hb-space-1, 4px);
        background: var(--hb-surface, #fff);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-md, 5px);
        box-shadow: 3px 4px 4px rgba(0, 0, 0, .1);
    }
    .hb-select[data-open="false"] .hb-select__menu { display: none; }
    .hb-select__option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        padding: 6px 8px;
        border: 0;
        border-radius: var(--hb-radius-sm, 3px);
        background: transparent;
        color: var(--hb-text-secondary, #5A5A5A);
        font-size: var(--hb-fs-sm, 12px);
        font-family: inherit;
        text-align: left;
        cursor: pointer;
    }
    .hb-select__option:hover,
    .hb-select__option[data-highlighted="true"] { background: var(--hb-bg-muted, #F4F4F4); }
    .hb-select__option[aria-selected="true"] { background: var(--hb-bg-muted, #F4F4F4); color: var(--hb-text-primary, #0A0A0A); }
    .hb-select__check { display: inline-flex; width: 14px; height: 14px; visibility: hidden; }
    .hb-select__option[aria-selected="true"] .hb-select__check { visibility: visible; }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-select]').forEach((root) => {
                if (root.__hbSelect) return;
                const trigger = root.querySelector('[data-hb-select-trigger]');
                const menu = root.querySelector('[data-hb-select-menu]');
                const valueEl = root.querySelector('[data-hb-select-value]');
                const caretEl = root.querySelector('[data-hb-select-caret]');
                const options = () => Array.from(root.querySelectorAll('[data-hb-select-option]'));
                let highlighted = -1;

                const close = () => {
                    root.dataset.open = 'false';
                    trigger.setAttribute('aria-expanded', 'false');
                    highlighted = -1;
                };
                const open = () => {
                    if (root.classList.contains('hb-select--disabled')) return;
                    root.dataset.open = 'true';
                    trigger.setAttribute('aria-expanded', 'true');
                    highlighted = options().findIndex((o) => o.getAttribute('aria-selected') === 'true');
                };
                const toggle = () => (root.dataset.open === 'true' ? close() : open());

                const select = (option) => {
                    options().forEach((o) => o.setAttribute('aria-selected', 'false'));
                    option.setAttribute('aria-selected', 'true');
                    valueEl.textContent = option.textContent.trim();
                    valueEl.classList.remove('hb-select__value--placeholder');
                    root.dataset.value = option.dataset.hbSelectOption;
                    close();
                    trigger.focus();
                    root.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { value: root.dataset.value } }));
                };

                const setHighlight = (index) => {
                    const list = options();
                    list.forEach((o) => o.removeAttribute('data-highlighted'));
                    if (list[index]) {
                        list[index].dataset.highlighted = 'true';
                        list[index].scrollIntoView({ block: 'nearest' });
                    }
                    highlighted = index;
                };

                trigger.addEventListener('click', toggle);
                trigger.addEventListener('keydown', (event) => {
                    const list = options();
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        if (root.dataset.open !== 'true') return open();
                        setHighlight(Math.min(list.length - 1, highlighted + 1));
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (root.dataset.open !== 'true') return open();
                        setHighlight(Math.max(0, highlighted - 1));
                    } else if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        if (root.dataset.open !== 'true') return open();
                        if (list[highlighted]) select(list[highlighted]);
                    } else if (event.key === 'Escape') {
                        close();
                    }
                });

                options().forEach((option) => option.addEventListener('click', () => select(option)));
                document.addEventListener('click', (event) => {
                    if (!root.contains(event.target)) close();
                });

                root.__hbSelect = { open, close, select };
            });
        };

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'options' => [],
    'value' => null,
    'placeholder' => 'Select option',
    'disabled' => false,
    // Lands on the trigger, not the wrapper: a bare <div> carrying aria-label has no role, so
    // assistive tech drops the label entirely. The combobox button is the labellable element.
    'ariaLabel' => null,
])
@php
    $selectedOption = collect($options)->firstWhere('value', $value);
@endphp
<div
    data-hb-select
    data-open="false"
    data-value="{{ $value }}"
    {{ $attributes->merge(['class' => 'hb-select' . ($disabled ? ' hb-select--disabled' : '')]) }}
>
    <button
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded="false"
        @if ($ariaLabel) aria-label="{{ $ariaLabel }}" @endif
        data-hb-select-trigger
        @if ($disabled) disabled @endif
        class="hb-select__trigger"
    >
        <span data-hb-select-value class="{{ $selectedOption ? '' : 'hb-select__value--placeholder' }}">{{ $selectedOption['label'] ?? $placeholder }}</span>
        <span data-hb-select-caret class="hb-select__caret" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 14])
        </span>
    </button>
    <div role="listbox" data-hb-select-menu class="hb-select__menu">
        @foreach ($options as $option)
            <button
                type="button"
                role="option"
                data-hb-select-option="{{ $option['value'] }}"
                aria-selected="{{ ($option['value'] ?? null) === $value ? 'true' : 'false' }}"
                class="hb-select__option"
            >
                <span>{{ $option['label'] }}</span>
                <span class="hb-select__check" aria-hidden="true">
                    @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 14])
                </span>
            </button>
        @endforeach
    </div>
</div>
