{{-- ui/search-field — 36px inline row, bottom border only (no full
     box), leading 13px search icon. Real editable text input, not a static hint.

     Opt-in filtering: pass `data-hb-filter="<container selector>"` and
     `data-hb-filter-item="<item selector>"` and typing hides every matching item
     inside the container whose text doesn't contain the query (case-insensitive).
     Items are live-queried per keystroke, so lists rebuilt later still filter. --}}
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
    /* !important because filtered items usually carry their own display rule
       (grid/flex cards), which would beat the UA's [hidden] styling. */
    .hb-filter-hidden { display: none !important; }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('.hb-searchfield[data-hb-filter]').forEach((field) => {
                if (field.__hbFilter) return;
                field.__hbFilter = true;
                const input = field.querySelector('.hb-searchfield__input');
                if (!input) return;
                const apply = () => {
                    const container = document.querySelector(field.getAttribute('data-hb-filter') || '');
                    const itemSelector = field.getAttribute('data-hb-filter-item') || '';
                    if (!container || !itemSelector) return;
                    const query = input.value.trim().toLowerCase();
                    container.querySelectorAll(itemSelector).forEach((item) => {
                        const match = query === '' || (item.textContent || '').toLowerCase().indexOf(query) !== -1;
                        item.classList.toggle('hb-filter-hidden', !match);
                    });
                };
                input.addEventListener('input', apply);
                input.addEventListener('search', apply); // the native clear (x) button
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props(['value' => '', 'placeholder' => 'Search…'])
<div {{ $attributes->merge(['class' => 'hb-searchfield']) }}>
    <span class="hb-searchfield__icon" aria-hidden="true">
        @include('heisenberg::components.ui.icon', ['name' => 'magnifying-glass', 'size' => 13])
    </span>
    <input type="search" class="hb-searchfield__input" value="{{ $value }}" placeholder="{{ $placeholder }}">
</div>
