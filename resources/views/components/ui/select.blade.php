@once

<script nonce="{{ heisenberg_csp_nonce() }}">
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
