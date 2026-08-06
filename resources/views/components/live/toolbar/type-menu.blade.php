{{-- live/toolbar/type-menu — the block-type switcher dropdown from the toolbar's type pill
    . The only rewrite the runtime API can do safely today is
     a heading's own `level` attribute (1-6) — heisenberg/heading stores it as a single integer
     attribute, not as three separate block types, so "Heading 1/2/3" are level values, not distinct
     blocks. Turning a block into a genuinely different contract (e.g. Paragraph -> List) has no
     supporting runtime API — attribute shapes aren't guaranteed compatible across contracts, and
     insertBlock/removeBlock alone would silently drop the block's content. That's out of scope for
     this pass: a heading shows its H1-H6 levels (from the contract's own `level` enum); any other
     block shows only its own current type, selected and inert, rather than entries (List, Quote,
     Code, ...) that look actionable but would silently do nothing.

     All six level buttons are rendered up front (hidden) so no HTML has to be constructed in JS —
     hb:block-selected only ever toggles `hidden`/`--on`, never builds markup. --}}
@props(['selected' => 'Text'])
<div class="hb-pop hb-typemenu" data-hb-typemenu>
    <div class="hb-typemenu__search"><input type="search" placeholder="Search block type…"></div>
    <div class="hb-typemenu__list">
        <button type="button" class="hb-typemenu__item hb-typemenu__item--on" data-type-current disabled>
            <span class="ck">@include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 14])</span>
            <span class="ic">@include('heisenberg::components.ui.icon', ['name' => 'text-t', 'size' => 18])</span>
            <span class="lb" data-type-current-label>{{ $selected }}</span>
        </button>
        @foreach (range(1, 6) as $lvl)
            <button type="button" class="hb-typemenu__item" data-type-level="{{ $lvl }}" hidden>
                <span class="ck">@include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 14])</span>
                <span class="ic">@include('heisenberg::components.ui.icon', ['name' => 'text-h', 'size' => 18])</span>
                <span class="lb">H{{ $lvl }}</span>
            </button>
        @endforeach
    </div>
</div>
@once
<script>
    (() => {
        const boot = () => document.querySelectorAll('[data-hb-typemenu]').forEach((menu) => {
            if (menu.__hbType) return; menu.__hbType = true;
            const items = [...menu.querySelectorAll('.hb-typemenu__item')];
            const search = menu.querySelector('input[type="search"]');

            // Final visibility is context (which items even apply to the selected block) AND
            // search (does the label match the query) combined — kept as two separate signals
            // (dataset.ctxHidden vs the search term) so neither one clobbers the other's state.
            const updateVisibility = () => {
                const term = search ? search.value.trim().toLowerCase() : '';
                items.forEach((it) => {
                    const ctxOk = it.dataset.ctxHidden !== '1';
                    const label = (it.querySelector('.lb') || {}).textContent || '';
                    it.hidden = !(ctxOk && (!term || label.toLowerCase().indexOf(term) !== -1));
                });
            };

            items.forEach((it) => it.addEventListener('click', () => {
                if (it.disabled) return;
                items.forEach((i) => i.classList.remove('hb-typemenu__item--on'));
                it.classList.add('hb-typemenu__item--on');
                if (it.dataset.typeLevel != null) {
                    menu.dispatchEvent(new CustomEvent('blocktype', { bubbles: true, detail: { level: Number(it.dataset.typeLevel) } }));
                }
            }));
            if (search) search.addEventListener('input', updateVisibility);

            document.addEventListener('hb:block-selected', (e) => {
                const model = e.detail && e.detail.model;
                const contract = e.detail && e.detail.contract;
                const levelDef = contract && contract.attributeDefinitions && contract.attributeDefinitions.level;
                const isLeveled = !!(levelDef && Array.isArray(levelDef.enum));
                const currentBtn = menu.querySelector('[data-type-current]');
                if (currentBtn) {
                    currentBtn.dataset.ctxHidden = isLeveled ? '1' : '0';
                    currentBtn.classList.toggle('hb-typemenu__item--on', !isLeveled);
                    const label = currentBtn.querySelector('[data-type-current-label]');
                    if (label && !isLeveled) label.textContent = (contract && contract.title) || (model && model.name) || 'Block';
                }
                items.forEach((it) => {
                    if (it.dataset.typeLevel == null) return;
                    const lvl = Number(it.dataset.typeLevel);
                    const inEnum = isLeveled && levelDef.enum.indexOf(lvl) !== -1;
                    it.dataset.ctxHidden = inEnum ? '0' : '1';
                    it.classList.toggle('hb-typemenu__item--on', inEnum && !!model && Number(model.attributes && model.attributes.level) === lvl);
                });
                updateVisibility();
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
