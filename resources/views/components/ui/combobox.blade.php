@once

<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-combobox]').forEach((root) => {
                if (root.__hbCombobox) return;
                const input = root.querySelector('[data-hb-combobox-input]');
                const menu = root.querySelector('[data-hb-combobox-menu]');
                const optionsContainer = root.querySelector('[data-hb-combobox-options]');
                const optionsScroll = root.querySelector('[data-hb-combobox-options-scroll]');
                const options = () => Array.from(root.querySelectorAll('[data-hb-combobox-option]'));
                const checkTemplate = root.querySelector('[data-hb-combobox-option] .hb-combobox__check')?.innerHTML || '';
                let highlighted = -1;
                let currentLabel = input.value;

                const close = () => {
                    root.dataset.open = 'false';
                    input.setAttribute('aria-expanded', 'false');
                    highlighted = -1;
                };
                const revert = () => { input.value = currentLabel; };
                const closeAndRevert = () => { close(); revert(); };

                const open = () => {
                    if (root.classList.contains('hb-combobox--disabled')) return;
                    root.dataset.open = 'true';
                    input.setAttribute('aria-expanded', 'true');
                    highlighted = options().findIndex((o) => o.getAttribute('aria-selected') === 'true');
                    root.dispatchEvent(new CustomEvent('search', { bubbles: true, detail: { query: input.value } }));
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                    requestAnimationFrame(() => document.dispatchEvent(new CustomEvent('hb:refresh')));
                };

                const commit = (value, label) => {
                    currentLabel = label ?? value ?? '';
                    input.value = currentLabel;
                    root.dataset.value = value ?? '';
                };

                const select = (option) => {
                    options().forEach((o) => o.setAttribute('aria-selected', 'false'));
                    option.setAttribute('aria-selected', 'true');
                    commit(option.dataset.hbComboboxOption, option.querySelector('span')?.textContent.trim());
                    close();
                    input.focus();
                    root.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { value: root.dataset.value } }));
                };

                const setValue = (value, label) => {
                    options().forEach((o) => o.setAttribute('aria-selected', o.dataset.hbComboboxOption === value ? 'true' : 'false'));
                    commit(value, label);
                };

                const buildOption = (opt) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.setAttribute('role', 'option');
                    btn.dataset.hbComboboxOption = opt.value;
                    btn.setAttribute('aria-selected', opt.value === root.dataset.value ? 'true' : 'false');
                    btn.className = 'hb-combobox__option';
                    const label = document.createElement('span');
                    label.textContent = opt.label;
                    const check = document.createElement('span');
                    check.className = 'hb-combobox__check';
                    check.setAttribute('aria-hidden', 'true');
                    check.innerHTML = checkTemplate;
                    btn.append(label, check);
                    return btn;
                };

                const replaceOptions = (list) => {
                    if (!optionsContainer) return;
                    highlighted = -1;
                    if (!list.length) {
                        const empty = document.createElement('div');
                        empty.className = 'hb-combobox__options-empty';
                        empty.textContent = optionsContainer.dataset.hbComboboxEmptyLabel || 'No results';
                        optionsContainer.replaceChildren(empty);
                        return;
                    }
                    optionsContainer.replaceChildren(...list.map(buildOption));
                };

                const appendOptions = (list) => {
                    if (!optionsContainer || !list.length) return;
                    const emptyRow = optionsContainer.querySelector('.hb-combobox__options-empty');
                    if (emptyRow) emptyRow.remove();
                    list.forEach((opt) => optionsContainer.append(buildOption(opt)));
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

                const commitFreeText = () => {
                    const typed = input.value.trim();
                    if (!typed) { revert(); close(); return; }
                    options().forEach((o) => o.setAttribute('aria-selected', o.dataset.hbComboboxOption === typed ? 'true' : 'false'));
                    commit(typed, typed);
                    close();
                    root.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { value: root.dataset.value } }));
                };

                input.addEventListener('focus', () => {
                    open();
                    input.select();
                });
                input.addEventListener('blur', closeAndRevert);
                input.addEventListener('input', () => {
                    root.dispatchEvent(new CustomEvent('search', { bubbles: true, detail: { query: input.value } }));
                });
                input.addEventListener('keydown', (event) => {
                    const list = options();
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        if (root.dataset.open !== 'true') return open();
                        setHighlight(Math.min(list.length - 1, highlighted + 1));
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (root.dataset.open !== 'true') return open();
                        setHighlight(Math.max(0, highlighted - 1));
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        if (list[highlighted]) select(list[highlighted]);
                        else commitFreeText();
                    } else if (event.key === 'Escape') {
                        closeAndRevert();
                    }
                });

                menu.addEventListener('mousedown', (event) => {
                    const option = event.target.closest('[data-hb-combobox-option]');
                    if (!option) return;
                    event.preventDefault();
                    select(option);
                });

                document.addEventListener('click', (event) => {
                    if (!root.contains(event.target)) closeAndRevert();
                });

                optionsScroll?.addEventListener('scroll', () => {
                    const { scrollTop, scrollHeight, clientHeight } = optionsScroll;
                    if (scrollHeight - (scrollTop + clientHeight) < 48) {
                        root.dispatchEvent(new CustomEvent('loadmore', { bubbles: true, detail: { query: input.value } }));
                    }
                }, { passive: true });

                if (root.hasAttribute('data-hb-combobox-static')) {
                    const master = options().map((o) => ({
                        value: o.dataset.hbComboboxOption,
                        label: o.querySelector('span')?.textContent.trim() || '',
                    }));
                    root.addEventListener('search', (event) => {
                        const query = String(event.detail?.query || '').trim().toLowerCase();
                        const untouched = query !== '' && query === (currentLabel || '').trim().toLowerCase();
                        replaceOptions(query === '' || untouched
                            ? master
                            : master.filter((o) => o.label.toLowerCase().indexOf(query) !== -1));
                    });
                }

                root.__hbCombobox = { open, close, select, setValue, replaceOptions, appendOptions };
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
    'emptyLabel' => 'No results',
    'disabled' => false,
    'ariaLabel' => null,
    'static' => false,
])
@php
    $selectedOption = collect($options)->firstWhere('value', $value);
    $hasValue = $value !== null && $value !== '';
    $displayLabel = $selectedOption['label'] ?? ($hasValue ? $value : '');
    $listboxId = 'hb-combobox-listbox-' . uniqid();
@endphp
<div
    data-hb-combobox
    data-open="false"
    data-value="{{ $value }}"
    @if ($static) data-hb-combobox-static @endif
    {{ $attributes->merge(['class' => 'hb-combobox' . ($disabled ? ' hb-combobox--disabled' : '')]) }}
>
    <div class="hb-combobox__field">
        <input
            type="text"
            role="combobox"
            aria-haspopup="listbox"
            aria-expanded="false"
            aria-autocomplete="list"
            aria-controls="{{ $listboxId }}"
            @if ($ariaLabel) aria-label="{{ $ariaLabel }}" @endif
            data-hb-combobox-input
            value="{{ $displayLabel }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            spellcheck="false"
            @if ($disabled) disabled @endif
            class="hb-combobox__input"
        >
        <span class="hb-combobox__caret" aria-hidden="true">
            @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 14])
        </span>
    </div>
    <div id="{{ $listboxId }}" role="listbox" data-hb-combobox-menu class="hb-combobox__menu">
        <div class="hb-combobox__options-wrap" data-hb-combobox-options-wrap>
            <div class="hb-combobox__options-scroll" data-hb-combobox-options-scroll>
                <div data-hb-combobox-options data-hb-combobox-empty-label="{{ $emptyLabel }}" class="hb-combobox__options">
                    @foreach ($options as $option)
                        <button
                            type="button"
                            role="option"
                            data-hb-combobox-option="{{ $option['value'] }}"
                            aria-selected="{{ ($option['value'] ?? null) === $value ? 'true' : 'false' }}"
                            class="hb-combobox__option"
                        >
                            <span>{{ $option['label'] }}</span>
                            <span class="hb-combobox__check" aria-hidden="true">
                                @include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 14])
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-combobox-options-scroll]" />
        </div>
    </div>
</div>
