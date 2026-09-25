{{-- The Effects editor popup. One form for every effect type: script-effects.blade.php shows the
     shadow fields for a drop shadow and the amount field for the two blurs, and
     fills them from the layer being edited. --}}
<div class="hb-pop hb-shadow" data-hb-effect>
    <button type="button" class="hb-shadow__head" data-hb-effect-toggle>
        <b data-hb-fx-title>{{ __('heisenberg::editor.effects.drop_shadow') }}</b>
        @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 13])
    </button>
    <div class="hb-shadow__body">
        <div class="hb-shadow__group" data-hb-fx-fields="shadow">
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.color') }}</span>
                <div class="hb-shadow__color">
                    <span class="hb-shadow__sw" data-hb-fx-swatch></span>
                    <input type="text" class="hb-shadow__hex" value="#000000" data-hb-fx-color>
                </div>
                <span class="hb-shadow__box hb-shadow__box--fixed"><input type="text" value="14" data-hb-fx-opacity><span class="p">%</span></span>
            </div>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.blur') }}</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="28" data-hb-fx-blur></span>
            </div>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.offset') }}</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="0" data-hb-fx-x aria-label="X"></span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="8" data-hb-fx-y aria-label="Y"></span>
            </div>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.spread') }}</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="0" data-hb-fx-spread></span>
            </div>
        </div>
        <div class="hb-shadow__group" data-hb-fx-fields="amount" hidden>
            <div class="hb-shadow__row">
                <span class="hb-shadow__lbl">{{ __('heisenberg::editor.effects.amount') }}</span>
                <span class="hb-shadow__box hb-shadow__box--grow"><input type="text" value="0" data-hb-fx-amount><span class="p" data-hb-fx-unit></span></span>
            </div>
            <input type="range" class="hb-shadow__range" min="0" max="100" step="1" value="0" data-hb-fx-amount-range aria-hidden="true" tabindex="-1">
        </div>
    </div>
</div>
@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-shadow__body { display: flex; flex-direction: column; gap: 12px; }
    .hb-shadow__group { display: flex; flex-direction: column; gap: 12px; }
    .hb-shadow__group[hidden] { display: none; }
    .hb-shadow__range { width: 100%; accent-color: var(--hb-accent); }
    .hb-shadow[data-collapsed="true"] .hb-shadow__body { display: none; }
    .hb-shadow__head { border: 0; cursor: pointer; width: 100%; }
    .hb-shadow[data-collapsed="true"] .hb-shadow__head svg { transform: rotate(-90deg); }
</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const boot = () => document.querySelectorAll('[data-hb-effect-toggle]').forEach((head) => {
            if (head.__hbFx) return; head.__hbFx = true;
            head.addEventListener('click', () => {
                const card = head.closest('[data-hb-effect]');
                card.dataset.collapsed = card.dataset.collapsed === 'true' ? 'false' : 'true';
            });
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce
