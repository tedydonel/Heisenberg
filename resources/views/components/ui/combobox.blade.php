{{-- ui/combobox — a search-first sibling of ui/select, for option sets too large to scan as a
     static list (e.g. a remote catalog with thousands of entries). Same public API shape as
     ui/select (open/close/select + a `change` event), but:
       - there is no separate trigger + search field — the field that displays the current value IS
         the search field. Focusing it opens the menu and selects its text (so the first keystroke
         replaces it); typing dispatches a bubbling `search` event with the query, and the consumer
         fetches + calls replaceOptions(). Closing without picking anything reverts the field back to
         the last confirmed value — nothing is "half-typed" and left hanging.
       - Enter with no option highlighted commits the RAW TYPED TEXT as the value rather than
         requiring a catalog match — the option set is only ever a fetched-on-demand page, so a value
         genuinely outside it (e.g. a system font like "Georgia" that Google Fonts doesn't carry at
         all) must still be settable. setValue() supports the same for a programmatic set.
       - the option list scrolls via the app's own ui/custom-scrollbar, not a native scrollbar, at a
         fixed (not max-) height, so opening it is never a surprise 1-row sliver.
     Kept as its own component (not a mode on ui/select) so ui/select stays the simple, static-list
     dropdown its other consumers (color-picker's gradient type/shape/model) already rely on. --}}
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
                // A combobox's option set is meant to be replaced at runtime (see replaceOptions
                // below) — captured once, from whatever the first real option renders, so a
                // dynamically-built option's checkmark never has to duplicate the icon system (see
                // EditorIcon's own docblock on redundant icon resolvers, TODO 6.12).
                const checkTemplate = root.querySelector('[data-hb-combobox-option] .hb-combobox__check')?.innerHTML || '';
                let highlighted = -1;
                let currentLabel = input.value; // '' when nothing is selected yet

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
                    // Search from whatever the field currently shows, not a forced-blank slate — the
                    // field never gets visibly cleared just because the menu opened.
                    root.dispatchEvent(new CustomEvent('search', { bubbles: true, detail: { query: input.value } }));
                    // The menu (and its nested ui/custom-scrollbar) just went from display:none to
                    // visible for the first time it's ever had a real size — nudge it to recheck.
                    // Twice: immediately, and once more next frame in case display:none -> flex
                    // hasn't fully settled layout by the time the immediate one reads geometry.
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

                // Sets an arbitrary value directly, independent of whether it matches any
                // currently-loaded option (see the component docblock).
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

                // Replaces the option list wholesale (e.g. a fresh page of search results). `list`
                // is [{value, label}]; empty shows a muted row rather than a blank menu.
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

                // Appends a further page onto the current list (see the `loadmore` event below) —
                // never called with an empty page, so unlike replaceOptions there's no empty-state
                // to render; a stray "no results" row from an earlier empty replaceOptions() call is
                // cleared first since it's not a real option `.hb-combobox__option` selector would match.
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
                // A mousedown on an option (below) preventDefault()s, so the input never actually
                // blurs when picking one — this only ever fires for a genuine focus-loss (Tab,
                // clicking elsewhere), where reverting unconfirmed text is exactly what's wanted.
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
                    event.preventDefault(); // keeps focus on the input — no blur, no revert race
                    select(option);
                });

                document.addEventListener('click', (event) => {
                    if (!root.contains(event.target)) closeAndRevert();
                });

                // Fires a bubbling `loadmore` (query = current field text) once the options list is
                // scrolled near its bottom, so a consumer backed by a paginated remote catalog (e.g.
                // the Fonts picker) can fetch + appendOptions() the next page. Left consumer-managed
                // whether a fetch is already in flight or the last page was short (no more results) —
                // this listener only ever reports "user scrolled near the bottom", nothing else.
                optionsScroll?.addEventListener('scroll', () => {
                    const { scrollTop, scrollHeight, clientHeight } = optionsScroll;
                    if (scrollHeight - (scrollTop + clientHeight) < 48) {
                        root.dispatchEvent(new CustomEvent('loadmore', { bubbles: true, detail: { query: input.value } }));
                    }
                }, { passive: true });

                // Static mode (data-hb-combobox-static): the Blade-rendered options ARE the whole
                // catalog, so the component answers its own `search` events by filtering that
                // captured list — no consumer wiring needed. A query equal to the committed label
                // (the just-focused, untouched field) shows the full list, not a one-row filter.
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
    // true = the rendered options are the whole catalog; the combobox filters them itself.
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
            <x-ui.custom-scrollbar container="[data-hb-combobox-options-scroll]" />
        </div>
    </div>
</div>
