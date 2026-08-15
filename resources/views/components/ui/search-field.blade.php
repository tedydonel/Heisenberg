{{-- ui/search-field — 36px inline row, bottom border only (no full
     box), leading 13px search icon. Real editable text input, not a static hint.

     Opt-in filtering: pass `data-hb-filter="<container selector>"` and
     `data-hb-filter-item="<item selector>"` and typing hides every matching item
     inside the container whose text doesn't contain the query (case-insensitive).
     Items are live-queried per keystroke, so lists rebuilt later still filter. --}}
@once

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
