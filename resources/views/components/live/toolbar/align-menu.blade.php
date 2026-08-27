<div class="hb-pop hb-alignmenu" data-hb-alignmenu>
    <div class="hb-varmenu__list">
        @foreach ([['left', 'align-left', 'Left'], ['center', 'align-center', 'Center'], ['right', 'align-right', 'Right']] as [$value, $icon, $label])
            <button type="button" class="hb-vmi" data-align-value="{{ $value }}" hidden>
                <span class="hb-vmi__l">
                    <span class="hb-vmi__ic">@include('heisenberg::components.ui.icon', ['name' => $icon, 'size' => 16])</span>
                    <span class="hb-vmi__name">{{ $label }}</span>
                </span>
            </button>
        @endforeach
    </div>
</div>
@once
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const boot = () => document.querySelectorAll('[data-hb-alignmenu]').forEach((menu) => {
            if (menu.__hbAlign) return; menu.__hbAlign = true;
            const options = [...menu.querySelectorAll('[data-align-value]')];

            options.forEach((btn) => btn.addEventListener('click', () => {
                if (btn.hidden) return;
                options.forEach((b) => b.classList.remove('hb-vmi--on'));
                btn.classList.add('hb-vmi--on');
                menu.dispatchEvent(new CustomEvent('alignselect', { bubbles: true, detail: { value: btn.dataset.alignValue } }));
            }));

            document.addEventListener('hb:block-selected', (e) => {
                const contract = e.detail && e.detail.contract;
                const model = e.detail && e.detail.model;
                const allowed = (contract && contract.supports && Array.isArray(contract.supports.align)) ? contract.supports.align : [];
                const current = model && model.supports ? model.supports.align : null;
                options.forEach((btn) => {
                    const value = btn.dataset.alignValue;
                    btn.hidden = allowed.indexOf(value) === -1;
                    btn.classList.toggle('hb-vmi--on', value === current);
                });
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
